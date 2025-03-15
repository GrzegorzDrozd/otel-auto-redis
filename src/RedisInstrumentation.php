<?php

/** @noinspection PhpMultipleClassDeclarationsInspection */
/** @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Redis;

use Redis;
use RedisCluster;

class RedisInstrumentation extends AbstractInstrumentation
{
    public const NAME = 'redis';

    public static function register(): void
    {
        $instrumentation = self::getCachedInstrumentation();
        $redisTracker = new RedisTracker();

        self::installHook($instrumentation, Redis::class, '__construct', $redisTracker, startTrackingConnection: true);
        self::installHook($instrumentation, Redis::class, 'connect', $redisTracker, startTrackingConnection: true);
        self::installHook($instrumentation, Redis::class, 'pconnect', $redisTracker, startTrackingConnection: true);
        self::installHook($instrumentation, Redis::class, 'reset', $redisTracker);

        self::installHook($instrumentation, RedisCluster::class, '__construct', $redisTracker, startTrackingConnection: true);

        $commands = static::getCommandsToInstrument();
        $commands[] = 'sAddArray';

        foreach ($commands as $method) {
            self::installHook($instrumentation, RedisCluster::class, $method, $redisTracker, trackParameters: true);
            self::installHook($instrumentation, Redis::class, $method, $redisTracker, trackParameters: true);
        }
    }
}
