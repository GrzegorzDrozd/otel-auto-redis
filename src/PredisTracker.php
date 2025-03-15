<?php

/** @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Redis;

use Credis_Client;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\TraceAttributeValues;
use Predis\ClientInterface;
use Predis\Connection\AggregateConnectionInterface;
use Predis\NotSupportedException;
use Redis;
use RedisCluster;

class PredisTracker extends AbstractTracker
{
    public function __construct(
        protected bool $trackConnectionInClusterMode = false,
    ) {
        parent::__construct();
    }

    public function trackConnectionAttributes(Redis|RedisCluster|ClientInterface|Credis_Client $redis, string $command = null, array $arguments = []): array
    {
        $attributes = $this->getAttributesForConnection($redis);
        $attributes[TraceAttributes::DB_SYSTEM_NAME] ??= TraceAttributeValues::DB_SYSTEM_REDIS;

        if (!$redis->isConnected()) {
            return $this->attributes[$redis] = $attributes;
        }

        $connection = $redis->getConnection();
        if ($connection instanceof AggregateConnectionInterface) {
            if (!$this->trackConnectionInClusterMode) {
                return $this->attributes[$redis] = $attributes;
            }
            $redisCommand = $redis->createCommand($command, $arguments);

            try {
                $connection = $connection->getConnectionByCommand($redisCommand);
                $parameters = $connection->getParameters();

                // cluster commands that are fetching data from multiple nodes are not supported
            } catch (NotSupportedException) {
                return $this->attributes[$redis] = $attributes;
            }

            // set values every time command is executed because it can be executed by different connection
            if (!empty($parameters->path)) {
                $attributes[TraceAttributes::SERVER_ADDRESS] = $parameters->path;
            } else {
                $attributes[TraceAttributes::SERVER_ADDRESS] = $parameters->host;
                $attributes[TraceAttributes::SERVER_PORT] = $parameters->port;
            }
            $attributes[TraceAttributes::DB_NAMESPACE] = $parameters->database;

            return $attributes;
        }

        // other cases we will set parameters once
        $parameters = $connection->getParameters();
        if (!empty($parameters->path)) {
            $attributes[TraceAttributes::SERVER_ADDRESS] ??= $parameters->path;
        } else {
            $attributes[TraceAttributes::SERVER_ADDRESS] ??= $parameters->host;
            $attributes[TraceAttributes::SERVER_PORT] ??= $parameters->port;
        }
        $attributes[TraceAttributes::DB_NAMESPACE] = $parameters->database ?? 0;

        return $this->attributes[$redis] = $attributes;
    }
}
