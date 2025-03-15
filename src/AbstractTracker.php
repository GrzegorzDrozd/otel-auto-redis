<?php

/** @noinspection PhpMultipleClassDeclarationsInspection */
/** @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Redis;

use Credis_Client;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\Context\ContextInterface;
use Predis\ClientInterface;
use Redis;
use RedisCluster;
use WeakMap;
use WeakReference;

abstract class AbstractTracker
{
    protected WeakMap $attributes;
    protected WeakMap $connections;
    protected WeakMap $contexts;

    abstract protected function trackConnectionAttributes(Redis|RedisCluster|ClientInterface|Credis_Client $redis, string $command = null, array $arguments = []): array;

    public function __construct()
    {
        $this->attributes = new WeakMap();
        $this->connections = new WeakMap();
        $this->contexts = new WeakMap();
    }

    public function getAttributesForConnection(Redis|RedisCluster|ClientInterface|Credis_Client $redis): array
    {
        return $this->attributes[$redis] ?? [];
    }

    public function getContextForConnection(Redis|RedisCluster|ClientInterface|Credis_Client $redis): ?SpanContextInterface
    {
        return ($this->connections[$redis] ?? null)?->get();
    }

    public function setContextForConnection(Redis|RedisCluster|ClientInterface|Credis_Client $redis, SpanContextInterface $context): void
    {
        $this->connections[$redis] = WeakReference::create($context);
    }

    /**
     * When using multiple redis connections in the same process, this method can be used to determine which connection is being used.
     *
     * @internal this should be called ONLY from the hook
     */
    public function getConnectionNumber(Redis|RedisCluster|ClientInterface|Credis_Client $redis): int
    {
        $i = 0;
        foreach ($this->connections as $connection => $context) {
            if ($connection === $redis) {
                return $i;
            }
            $i++;
        }

        // This is not a mistake. If we did not match the connection, it means that there are at least $i connections
        // in the array. In post-hook, we will store connection under this index.
        return $i;
    }

    /**
     * Store context in between hooks so that we can create "nested" spans for pipelines and transactions.
     *
     * Nested pipelines and transactions are not supported, so there is no need to use stacks or queues.
     */
    public function storeContext(Redis|RedisCluster|ClientInterface|Credis_Client $redis, ContextInterface $context): void
    {
        $this->contexts[$redis] = WeakReference::create($context);
    }

    /**
     * Return stored context
     */
    public function getContext(Redis|RedisCluster|ClientInterface|Credis_Client $redis): ?ContextInterface
    {
        return ($this->contexts[$redis] ?? null)?->get();
    }

    public function unsetContext(Redis|RedisCluster|ClientInterface|Credis_Client $redis): void
    {
        unset($this->contexts[$redis]);
    }
}
