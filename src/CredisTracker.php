<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Redis;

use Credis_Client;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\TraceAttributeValues;
use Predis\ClientInterface;
use Redis;
use RedisCluster;

class CredisTracker extends AbstractTracker
{
    public function trackConnectionAttributes(Redis|RedisCluster|ClientInterface|Credis_Client $redis, string $command = null, array $arguments = []): array
    {
        $attributes = $this->getAttributesForConnection($redis);
        $attributes[TraceAttributes::DB_SYSTEM_NAME] ??= TraceAttributeValues::DB_SYSTEM_REDIS;

        if (!$redis->isConnected()) {
            return $attributes;
        }

        $attributes[TraceAttributes::SERVER_ADDRESS] ??= $redis->getHost();
        $attributes[TraceAttributes::SERVER_PORT] ??= $redis->getPort();
        $attributes[TraceAttributes::DB_NAMESPACE] = $redis->getSelectedDb();

        return $this->attributes[$redis] = $attributes;
    }
}
