<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Redis;

use OpenTelemetry\SDK\Common\Configuration\Configuration;
use Predis\Client;
use Predis\Pipeline\Pipeline;

class PredisInstrumentation extends AbstractInstrumentation
{
    public const NAME = 'predis';

    public static function register(): void
    {
        $instrumentation = self::getCachedInstrumentation();
        $redisTracker = new PredisTracker(
            self::isConnectionTrackingInAggregatedConnectionEnabled()
        );

        self::installHook(
            $instrumentation,
            Client::class,
            '__construct',
            $redisTracker,
            startTrackingConnection: true,
        );

        self::installHook(
            $instrumentation,
            Client::class,
            '__call',
            $redisTracker,
            trackParameters: true,
            filterCommands: true,
        );
        self::installHook(
            $instrumentation,
            Client::class,
            'pipeline',
            $redisTracker,
            trackParameters: true,
        );

        self::installHook(
            $instrumentation,
            Pipeline::class,
            '__call',
            $redisTracker,
            trackParameters: true,
            filterCommands: true,
        );
        foreach (['execute', 'flushPipeline', 'executeCommand'] as $method) {
            self::installHook(
                $instrumentation,
                Pipeline::class,
                $method,
                $redisTracker,
                trackParameters: true,
            );
        }
    }

    protected static function isConnectionTrackingInAggregatedConnectionEnabled(): bool
    {
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration')) {
            return Configuration::getBoolean('OTEL_PHP_INSTRUMENTATION_PREDIS_TRACK_AGGREGATED_CONNECTIONS', false);
        }

        return get_cfg_var('otel.instrumentation.predis.track_aggregated_connections') === 'true';
    }
}
