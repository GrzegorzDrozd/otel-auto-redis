<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Redis;

use Credis_Client;

class CredisInstrumentation extends AbstractInstrumentation
{
    public const NAME = 'credis';

    public static function register(): void
    {
        $instrumentation = self::getCachedInstrumentation();
        $redisTracker = new CredisTracker();

        self::installHook(
            $instrumentation,
            Credis_Client::class,
            '__construct',
            $redisTracker,
            startTrackingConnection: true,
        );

        $commands = static::getCommandsToInstrument();
        // remove actual functions that are proxied to a __call method to avoid 2 spans
        $commands = array_diff($commands, ['ping', 'select', 'auth', 'punsubscribe', 'scan', 'hscan', 'sscan', 'zscan', 'psubscribe', 'unsubscribe', 'subscribe']);
        foreach ($commands as $command) {
            self::installHook(
                $instrumentation,
                Credis_Client::class,
                $command,
                $redisTracker,
                trackParameters: true,
                
            );
        }

        self::installHook(
            $instrumentation,
            Credis_Client::class,
            '__call',
            $redisTracker,
            trackParameters: true,
            filterCommands: true,
            nonCommandMethods: ['pipeline']
        );
    }
}
