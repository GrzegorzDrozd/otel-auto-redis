<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Redis;

use Credis_Client;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use Predis\Client;
use Predis\ClientInterface;
use Predis\Pipeline\Pipeline;
use Redis;
use RedisCluster;
use Throwable;

abstract class AbstractInstrumentation
{
    public const NAME = 'unknown';

    protected static array $cachedCommands = [];

    /**
     * @return CachedInstrumentation
     */
    protected static function getCachedInstrumentation(): CachedInstrumentation
    {
        return new CachedInstrumentation(
            'io.opentelemetry.contrib.redis.' . RedisInstrumentation::NAME,
            null,
            Version::VERSION_1_30_0->url()
        );
    }

    protected static function makeBuilder(
        CachedInstrumentation $instrumentation,
        string $name,
        string $function,
        string $class,
        ?string $filename,
        ?int $lineno
    ): SpanBuilderInterface {
        /** @psalm-suppress ArgumentTypeCoercion */
        return $instrumentation->tracer()
            ->spanBuilder($name)
            ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
            ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
            ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
            ->setSpanKind(SpanKind::KIND_CLIENT)
        ;
    }

    protected static function installHook(
        CachedInstrumentation $instrumentation,
        string $redisClass,
        string $redisCommand,
        AbstractTracker $tracker,
        array $extraAttributes = [],
        bool $startTrackingConnection = false,
        bool $trackParameters = false,
        bool $filterCommands = false,
        array $nonCommandMethods = []
    ): void {
        hook(
            $redisClass,
            $redisCommand,
            pre: function (
                Redis|RedisCluster|ClientInterface|Credis_Client|Pipeline $redis,
                ?array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation, $tracker, $trackParameters, $filterCommands, $nonCommandMethods) {

                $isCall = $function === '__call';
                if ($isCall) {
                    $function = $params[0];
                    $params = $params[1];
                }

                if ($filterCommands === true) {
                    $commands = static::getCommandsToInstrument();
                    if (!in_array($function, $commands, true) && !in_array($function, $nonCommandMethods, true)) {
                        return;
                    }
                }

                if ($redis instanceof Pipeline) {
                    $redis = $redis->getClient();
                }

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = AbstractInstrumentation::makeBuilder(
                    $instrumentation,
                    self::getSpanNamePrefix($redis, $function, $tracker->getConnectionNumber($redis)),
                    $function,
                    $class,
                    $filename,
                    $lineno
                );

                if ($connectionSpanContext = $tracker->getContextForConnection($redis)) {
                    $builder->addLink($connectionSpanContext);
                }

                if ($parent = $tracker->getContext($redis)) {
                    $builder->setParent($parent);
                }

                if (
                    $trackParameters &&
                    ($preparedParameters = self::prepareDbOperationParams($params, $isCall)) &&
                    !empty($preparedParameters)
                ) {
                    $builder->setAttribute(TraceAttributes::DB_OPERATION_PARAMETER, $preparedParameters);
                }

                $builder->setAttribute(TraceAttributes::DB_OPERATION_NAME, $function);

                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                Context::storage()->attach($currentContext = $span->storeInContext($parent));

                if ($function === 'multi' || $function === 'pipeline' || $function === 'transaction') {
                    $tracker->storeContext($redis, $currentContext);
                }

            },
            post: static function (
                Redis|RedisCluster|ClientInterface|Credis_Client|Pipeline $redis,
                ?array $params,
                mixed $return,
                ?Throwable $exception,
                string $class,
                string $function,
            ) use ($tracker, $filterCommands, $nonCommandMethods, $extraAttributes, $startTrackingConnection) {

                $isCall = $function === '__call';
                if ($isCall) {
                    $function = $params[0];
                    $params = $params[1];
                }

                if ($filterCommands === true) {
                    $commands = static::getCommandsToInstrument();
                    if (!in_array($function, $commands, true) && !in_array($function, $nonCommandMethods, true)) {
                        return;
                    }
                }

                if ($redis instanceof Pipeline) {
                    $redis = $redis->getClient();
                }

                if ($function === 'exec' || $function === 'discard' || $function === 'execute') {
                    $tracker->unsetContext($redis);
                }
                
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $span = Span::fromContext($scope->context());

                $attributes = $tracker->trackConnectionAttributes($redis, $function, $params);
                $span->setAttributes($attributes + $extraAttributes);

                if ($startTrackingConnection) {
                    $tracker->setContextForConnection($redis, $span->getContext());
                }

                AbstractInstrumentation::end($exception);
            }
        );
    }

    protected static function end(?Throwable $exception, array $attributes = []): void
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $scope->detach();
        $span = Span::fromContext($scope->context());
        if ($exception) {
            $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }
        $span->setAttributes($attributes);

        $span->end();
    }

    protected static function isMarkingConnectionEnabled(): bool
    {
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration')) {
            return Configuration::getBoolean('OTEL_PHP_INSTRUMENTATION_REDIS_MARK_SPANS_WITH_CONNECTION_NUMBER', true);
        }

        return get_cfg_var('otel.instrumentation.redis.mark_spans_with_connection_number') === 'true';
    }

    protected static function getSpanNamePrefix(Redis|RedisCluster|ClientInterface|Credis_Client|Pipeline $redis, $function, $connectionNumber): string
    {
        if ($redis instanceof Redis || $redis instanceof RedisCluster) {
            $className = 'redis';
        } elseif ($redis instanceof Client || $redis instanceof Pipeline) {
            $className = 'predis';
        } elseif ($redis instanceof Credis_Client) {
            $className = 'credis';
        } else {
            $className = 'unknown';
        }

        if (self::isMarkingConnectionEnabled()) {
            return sprintf('%s(%s) %s', $className, $connectionNumber, $function);
        }

        return sprintf('%s %s', $className, $function);
    }

    protected static function prepareDbOperationParams(?array $params = [], bool $isCall = false): array
    {
        $ret = [];
        foreach ($params ?? [] as $key => $value) {
            if (is_string($value)) {
                $ret[$key] = substr($value, 0, 100);
            } elseif (is_array($value)) {
                $ret[$key] = join(',', self::prepareDbOperationParams($value, $isCall));
            } else {
                $ret[$key] = (string) $value;
            }
        }

        return $ret;
    }

    /**
     * Determines and returns a list of Redis commands to be instrumented based on configuration settings.
     *
     * The method first checks for cached results and uses them if available.
     * If no cached value is present, it retrieves the relevant configuration,
     * processes the commands or command groups specified for inclusion and exclusion, and computes the final list of commands.
     *
     * Configuration can be set using environment variables or PHP configuration:
     *
     *  Environment variable format:
     *  OTEL_PHP_INSTRUMENTATION_REDIS_{NAME}_FUNCTIONS
     *
     *  PHP configuration format:
     *  otel.instrumentation.redis.{name}.functions
     *
     * Where {name} is either: redis (for extension, phpredis), predis, or credis.
     *
     * The value of the configuration can be a single command, a group of commands, or a combination of both.
     * Using prefix '-' before a command or group name will exclude it from the final list.
     *
     * Examples:
     *  OTEL_PHP_INSTRUMENTATION_REDIS_REDIS_FUNCTIONS=@all,-@readonly
     *  OTEL_PHP_INSTRUMENTATION_REDIS_REDIS_FUNCTIONS=@write
     *  OTEL_PHP_INSTRUMENTATION_REDIS_REDIS_FUNCTIONS=get,mget
     *
     * @return array An array of command names to be instrumented, ensuring excluded commands and groups are removed.
     */
    protected static function getCommandsToInstrument(): array
    {
        // This method is called only from ::register(), before any user code runs. Because of that, we can cache this value
        if (self::$cachedCommands) {
            return self::$cachedCommands;
        }

        /**
         * @noinspection RedundantSuppression
         * @noinspection ClassConstantCanBeUsedInspection
         */
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration')) {
            $values = Configuration::getList('OTEL_PHP_INSTRUMENTATION_REDIS_' . strtoupper(static::NAME) . '_FUNCTIONS', []);
        } else {
            $configValue = get_cfg_var('otel.instrumentation.redis.' . self::NAME . '.functions');
            $values = match (true) {
                is_array($configValue) => $configValue,
                is_string($configValue) => [$configValue],
                default => [],
            };
        }

        $allCommands = self::getRedisCommands();
        if ($values === [] || $values === [CommandGroupEnum::ALL]) {
            return $allCommands;
        }

        $commands = [];
        $commandsToDelete = [];

        foreach ($values as $value) {
            // adding specific command
            if (in_array($value, $allCommands, true)) {
                $commands[] = $value;
            
                // adding whole the group
            } elseif ($group = CommandGroupEnum::tryFrom($value)) {
                $commands = [...$commands, ...self::getRedisCommands($group)];

                // excluding a group
            } elseif (str_starts_with($value, '-') && $group = CommandGroupEnum::tryFrom(substr($value, 1))) {
                $commandsToDelete = [...$commandsToDelete, ...self::getRedisCommands($group)];

                // excluding a specific command
            } elseif (str_starts_with($value, '-') && in_array($adjustedCommandName = substr($value, 1), $allCommands, true)) {
                $commandsToDelete[] = $adjustedCommandName;

            }
        }

        $commands = array_diff($commands, $commandsToDelete);

        return self::$cachedCommands = array_unique($commands);
    }

    public static function getRedisCommands(array|CommandGroupEnum $group = null): array
    {
        //<editor-fold desc="List of redis commands" defaultstate="collapsed">
        $commands =  [
            'bgrewriteaof' => CommandGroupEnum::ADMIN,
            'bgSave' => CommandGroupEnum::ADMIN,
            'debug' => CommandGroupEnum::ADMIN,
            'failover' => CommandGroupEnum::ADMIN,
            'monitor' => CommandGroupEnum::ADMIN,
            'pfselftest' => CommandGroupEnum::ADMIN,
            'psync' => CommandGroupEnum::ADMIN,
            'replconf' => CommandGroupEnum::ADMIN,
            'replicaof' => CommandGroupEnum::ADMIN,
            'save' => CommandGroupEnum::ADMIN,
            'shutdown' => CommandGroupEnum::ADMIN,
            'slaveof' => CommandGroupEnum::ADMIN,
            'sync' => CommandGroupEnum::ADMIN,
            'wait' => CommandGroupEnum::BLOCKING,
            'waitaof' => CommandGroupEnum::BLOCKING,
            'acl' => CommandGroupEnum::OTHER,
            'asking' => CommandGroupEnum::OTHER,
            'auth' => CommandGroupEnum::OTHER,
            'client' => CommandGroupEnum::OTHER,
            'cluster' => CommandGroupEnum::OTHER,
            'command' => CommandGroupEnum::OTHER,
            'config' => CommandGroupEnum::OTHER,
            'discard' => CommandGroupEnum::OTHER,
            'echo' => CommandGroupEnum::OTHER,
            'eval' => CommandGroupEnum::OTHER,
            'evalsha' => CommandGroupEnum::OTHER,
            'exec' => CommandGroupEnum::OTHER,
            'fcall' => CommandGroupEnum::OTHER,
            'function' => CommandGroupEnum::OTHER,
            'hello' => CommandGroupEnum::OTHER,
            'info' => CommandGroupEnum::OTHER,
            'lastSave' => CommandGroupEnum::OTHER,
            'latency' => CommandGroupEnum::OTHER,
            'memory' => CommandGroupEnum::OTHER,
            'module' => CommandGroupEnum::OTHER,
            'multi' => CommandGroupEnum::OTHER,
            'object' => CommandGroupEnum::OTHER,
            'ping' => CommandGroupEnum::OTHER,
            'pubsub' => CommandGroupEnum::OTHER,
            'quit' => CommandGroupEnum::OTHER,
            'readonly' => CommandGroupEnum::OTHER,
            'readwrite' => CommandGroupEnum::OTHER,
            'reset' => CommandGroupEnum::OTHER,
            'role' => CommandGroupEnum::OTHER,
            'script' => CommandGroupEnum::OTHER,
            'select' => CommandGroupEnum::OTHER,
            'slowlog' => CommandGroupEnum::OTHER,
            'time' => CommandGroupEnum::OTHER,
            'unwatch' => CommandGroupEnum::OTHER,
            'watch' => CommandGroupEnum::OTHER,
            'xgroup' => CommandGroupEnum::OTHER,
            'xinfo' => CommandGroupEnum::OTHER,
            'psubscribe' => CommandGroupEnum::PUBSUB,
            'publish' => CommandGroupEnum::PUBSUB,
            'punsubscribe' => CommandGroupEnum::PUBSUB,
            'spublish' => CommandGroupEnum::PUBSUB,
            'ssubscribe' => CommandGroupEnum::PUBSUB,
            'subscribe' => CommandGroupEnum::PUBSUB,
            'sunsubscribe' => CommandGroupEnum::PUBSUB,
            'unsubscribe' => CommandGroupEnum::PUBSUB,
            'bitcount' => CommandGroupEnum::READ_ONLY,
            'bitfield_ro' => CommandGroupEnum::READ_ONLY,
            'bitpos' => CommandGroupEnum::READ_ONLY,
            'dbSize' => CommandGroupEnum::READ_ONLY,
            'dump' => CommandGroupEnum::READ_ONLY,
            'eval_ro' => CommandGroupEnum::READ_ONLY,
            'evalsha_ro' => CommandGroupEnum::READ_ONLY,
            'exists' => CommandGroupEnum::READ_ONLY,
            'expiretime' => CommandGroupEnum::READ_ONLY,
            'fcall_ro' => CommandGroupEnum::READ_ONLY,
            'geodist' => CommandGroupEnum::READ_ONLY,
            'geohash' => CommandGroupEnum::READ_ONLY,
            'geopos' => CommandGroupEnum::READ_ONLY,
            'georadius_ro' => CommandGroupEnum::READ_ONLY,
            'georadiusbymember_ro' => CommandGroupEnum::READ_ONLY,
            'geosearch' => CommandGroupEnum::READ_ONLY,
            'get' => CommandGroupEnum::READ_ONLY,
            'getBit' => CommandGroupEnum::READ_ONLY,
            'getRange' => CommandGroupEnum::READ_ONLY,
            'hExists' => CommandGroupEnum::READ_ONLY,
            'hexpiretime' => CommandGroupEnum::READ_ONLY,
            'hGet' => CommandGroupEnum::READ_ONLY,
            'hGetAll' => CommandGroupEnum::READ_ONLY,
            'hKeys' => CommandGroupEnum::READ_ONLY,
            'hLen' => CommandGroupEnum::READ_ONLY,
            'hMget' => CommandGroupEnum::READ_ONLY,
            'hpexpiretime' => CommandGroupEnum::READ_ONLY,
            'hpttl' => CommandGroupEnum::READ_ONLY,
            'hRandField' => CommandGroupEnum::READ_ONLY,
            'hscan' => CommandGroupEnum::READ_ONLY,
            'hStrLen' => CommandGroupEnum::READ_ONLY,
            'httl' => CommandGroupEnum::READ_ONLY,
            'hVals' => CommandGroupEnum::READ_ONLY,
            'keys' => CommandGroupEnum::READ_ONLY,
            'lcs' => CommandGroupEnum::READ_ONLY,
            'lindex' => CommandGroupEnum::READ_ONLY,
            'lLen' => CommandGroupEnum::READ_ONLY,
            'lolwut' => CommandGroupEnum::READ_ONLY,
            'lPos' => CommandGroupEnum::READ_ONLY,
            'lrange' => CommandGroupEnum::READ_ONLY,
            'mget' => CommandGroupEnum::READ_ONLY,
            'pexpiretime' => CommandGroupEnum::READ_ONLY,
            'pfcount' => CommandGroupEnum::READ_ONLY,
            'pttl' => CommandGroupEnum::READ_ONLY,
            'randomKey' => CommandGroupEnum::READ_ONLY,
            'scan' => CommandGroupEnum::READ_ONLY,
            'scard' => CommandGroupEnum::READ_ONLY,
            'sDiff' => CommandGroupEnum::READ_ONLY,
            'sInter' => CommandGroupEnum::READ_ONLY,
            'sintercard' => CommandGroupEnum::READ_ONLY,
            'sismember' => CommandGroupEnum::READ_ONLY,
            'sMembers' => CommandGroupEnum::READ_ONLY,
            'sMisMember' => CommandGroupEnum::READ_ONLY,
            'sort_ro' => CommandGroupEnum::READ_ONLY,
            'sRandMember' => CommandGroupEnum::READ_ONLY,
            'sscan' => CommandGroupEnum::READ_ONLY,
            'strlen' => CommandGroupEnum::READ_ONLY,
            'substr' => CommandGroupEnum::READ_ONLY,
            'sUnion' => CommandGroupEnum::READ_ONLY,
            'touch' => CommandGroupEnum::READ_ONLY,
            'ttl' => CommandGroupEnum::READ_ONLY,
            'type' => CommandGroupEnum::READ_ONLY,
            'xlen' => CommandGroupEnum::READ_ONLY,
            'xpending' => CommandGroupEnum::READ_ONLY,
            'xrange' => CommandGroupEnum::READ_ONLY,
            'xread' => CommandGroupEnum::READ_ONLY,
            'xrevrange' => CommandGroupEnum::READ_ONLY,
            'zCard' => CommandGroupEnum::READ_ONLY,
            'zCount' => CommandGroupEnum::READ_ONLY,
            'zdiff' => CommandGroupEnum::READ_ONLY,
            'zinter' => CommandGroupEnum::READ_ONLY,
            'zintercard' => CommandGroupEnum::READ_ONLY,
            'zLexCount' => CommandGroupEnum::READ_ONLY,
            'zMscore' => CommandGroupEnum::READ_ONLY,
            'zRandMember' => CommandGroupEnum::READ_ONLY,
            'zRange' => CommandGroupEnum::READ_ONLY,
            'zRangeByLex' => CommandGroupEnum::READ_ONLY,
            'zRangeByScore' => CommandGroupEnum::READ_ONLY,
            'zRank' => CommandGroupEnum::READ_ONLY,
            'zRevRange' => CommandGroupEnum::READ_ONLY,
            'zRevRangeByLex' => CommandGroupEnum::READ_ONLY,
            'zRevRangeByScore' => CommandGroupEnum::READ_ONLY,
            'zRevRank' => CommandGroupEnum::READ_ONLY,
            'zscan' => CommandGroupEnum::READ_ONLY,
            'zScore' => CommandGroupEnum::READ_ONLY,
            'zunion' => CommandGroupEnum::READ_ONLY,
            'append' => CommandGroupEnum::WRITE,
            'bitfield' => CommandGroupEnum::WRITE,
            'bitop' => CommandGroupEnum::WRITE,
            'blmove' => CommandGroupEnum::WRITE,
            'blmpop' => CommandGroupEnum::WRITE,
            'blPop' => CommandGroupEnum::WRITE,
            'brPop' => CommandGroupEnum::WRITE,
            'brpoplpush' => CommandGroupEnum::WRITE,
            'bzmpop' => CommandGroupEnum::WRITE,
            'bzPopMax' => CommandGroupEnum::WRITE,
            'bzPopMin' => CommandGroupEnum::WRITE,
            'copy' => CommandGroupEnum::WRITE,
            'decr' => CommandGroupEnum::WRITE,
            'decrBy' => CommandGroupEnum::WRITE,
            'del' => CommandGroupEnum::WRITE,
            'expire' => CommandGroupEnum::WRITE,
            'expireAt' => CommandGroupEnum::WRITE,
            'flushAll' => CommandGroupEnum::WRITE,
            'flushDB' => CommandGroupEnum::WRITE,
            'geoadd' => CommandGroupEnum::WRITE,
            'georadius' => CommandGroupEnum::WRITE,
            'georadiusbymember' => CommandGroupEnum::WRITE,
            'geosearchstore' => CommandGroupEnum::WRITE,
            'getDel' => CommandGroupEnum::WRITE,
            'getEx' => CommandGroupEnum::WRITE,
            'getset' => CommandGroupEnum::WRITE,
            'hDel' => CommandGroupEnum::WRITE,
            'hexpire' => CommandGroupEnum::WRITE,
            'hexpireat' => CommandGroupEnum::WRITE,
            'hIncrBy' => CommandGroupEnum::WRITE,
            'hIncrByFloat' => CommandGroupEnum::WRITE,
            'hMset' => CommandGroupEnum::WRITE,
            'hpersist' => CommandGroupEnum::WRITE,
            'hpexpire' => CommandGroupEnum::WRITE,
            'hpexpireat' => CommandGroupEnum::WRITE,
            'hSet' => CommandGroupEnum::WRITE,
            'hSetNx' => CommandGroupEnum::WRITE,
            'incr' => CommandGroupEnum::WRITE,
            'incrBy' => CommandGroupEnum::WRITE,
            'incrByFloat' => CommandGroupEnum::WRITE,
            'lInsert' => CommandGroupEnum::WRITE,
            'lMove' => CommandGroupEnum::WRITE,
            'lmpop' => CommandGroupEnum::WRITE,
            'lPop' => CommandGroupEnum::WRITE,
            'lPush' => CommandGroupEnum::WRITE,
            'lPushx' => CommandGroupEnum::WRITE,
            'lrem' => CommandGroupEnum::WRITE,
            'lSet' => CommandGroupEnum::WRITE,
            'ltrim' => CommandGroupEnum::WRITE,
            'migrate' => CommandGroupEnum::WRITE,
            'move' => CommandGroupEnum::WRITE,
            'mset' => CommandGroupEnum::WRITE,
            'msetnx' => CommandGroupEnum::WRITE,
            'persist' => CommandGroupEnum::WRITE,
            'pexpire' => CommandGroupEnum::WRITE,
            'pexpireAt' => CommandGroupEnum::WRITE,
            'pfadd' => CommandGroupEnum::WRITE,
            'pfdebug' => CommandGroupEnum::WRITE,
            'pfmerge' => CommandGroupEnum::WRITE,
            'psetex' => CommandGroupEnum::WRITE,
            'rename' => CommandGroupEnum::WRITE,
            'renameNx' => CommandGroupEnum::WRITE,
            'restore' => CommandGroupEnum::WRITE,
            'restore-asking' => CommandGroupEnum::WRITE,
            'rPop' => CommandGroupEnum::WRITE,
            'rpoplpush' => CommandGroupEnum::WRITE,
            'rPush' => CommandGroupEnum::WRITE,
            'rPushx' => CommandGroupEnum::WRITE,
            'sAdd' => CommandGroupEnum::WRITE,
            'sDiffStore' => CommandGroupEnum::WRITE,
            'set' => CommandGroupEnum::WRITE,
            'setBit' => CommandGroupEnum::WRITE,
            'setex' => CommandGroupEnum::WRITE,
            'setnx' => CommandGroupEnum::WRITE,
            'setRange' => CommandGroupEnum::WRITE,
            'sInterStore' => CommandGroupEnum::WRITE,
            'sMove' => CommandGroupEnum::WRITE,
            'sort' => CommandGroupEnum::WRITE,
            'sPop' => CommandGroupEnum::WRITE,
            'srem' => CommandGroupEnum::WRITE,
            'sUnionStore' => CommandGroupEnum::WRITE,
            'swapdb' => CommandGroupEnum::WRITE,
            'unlink' => CommandGroupEnum::WRITE,
            'xack' => CommandGroupEnum::WRITE,
            'xadd' => CommandGroupEnum::WRITE,
            'xautoclaim' => CommandGroupEnum::WRITE,
            'xclaim' => CommandGroupEnum::WRITE,
            'xdel' => CommandGroupEnum::WRITE,
            'xreadgroup' => CommandGroupEnum::WRITE,
            'xsetid' => CommandGroupEnum::WRITE,
            'xtrim' => CommandGroupEnum::WRITE,
            'zAdd' => CommandGroupEnum::WRITE,
            'zdiffstore' => CommandGroupEnum::WRITE,
            'zIncrBy' => CommandGroupEnum::WRITE,
            'zinterstore' => CommandGroupEnum::WRITE,
            'zmpop' => CommandGroupEnum::WRITE,
            'zPopMax' => CommandGroupEnum::WRITE,
            'zPopMin' => CommandGroupEnum::WRITE,
            'zrangestore' => CommandGroupEnum::WRITE,
            'zRem' => CommandGroupEnum::WRITE,
            'zRemRangeByLex' => CommandGroupEnum::WRITE,
            'zRemRangeByRank' => CommandGroupEnum::WRITE,
            'zRemRangeByScore' => CommandGroupEnum::WRITE,
            'zunionstore' => CommandGroupEnum::WRITE,
        ];
        //</editor-fold>

        if (null === $group || CommandGroupEnum::ALL === $group || [] === $group || [CommandGroupEnum::ALL] === $group) {
            return array_keys($commands);
        }

        if (!is_array($group)) {
            $group = [$group];
        }

        if (in_array(CommandGroupEnum::ALL, $group, true)) {
            return array_keys($commands);
        }

        // make sure array contains only enum values
        $group = array_filter($group, static fn ($v) => $v instanceof CommandGroupEnum);

        $ret = [];
        foreach ($commands as $command => $commandGroup) {
            if (in_array($commandGroup, $group, true)) {
                $ret[] = $command;
            }
        }

        return $ret;
    }
}
