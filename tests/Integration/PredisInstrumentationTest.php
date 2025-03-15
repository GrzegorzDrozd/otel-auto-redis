<?php

declare(strict_types=1);

namespace Integration;

use ArrayObject;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Pipeline\Pipeline;

class PredisInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<int, ImmutableSpan> */
    private ArrayObject $storage;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
            ->activate();

        putenv('OTEL_PHP_INSTRUMENTATION_PREDIS_TRACK_AGGREGATED_CONNECTIONS=true');
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    protected function getClientMock(array $methods = []): Client&MockObject
    {
        return $this->getMockBuilder(Client::class)
            ->disableProxyingToOriginalMethods()
            ->onlyMethods([
                '__construct',
                '__call',
                'isConnected',
                ...$methods,
            ])
            ->getMock();
    }

    public function test_predis(): void
    {
        $client = $this->getClientMock();

        $client->expects($this->atLeastOnce())->method('isConnected')->willReturn(true);

        $this->assertCount(1, $this->storage);
        $client->__call('ping', []);
        $client->__call('set', ['foo', 'bar']);

        $this->assertCount(3, $this->storage);
        $this->assertSame('predis(0) __construct', $this->storage->offsetGet(0)->getName());
        $this->assertSame('predis(0) ping', $this->storage->offsetGet(1)->getName());
        $this->assertSame('predis(0) set', $this->storage->offsetGet(2)->getName());

        $actualParams = $this->storage->offsetGet(2)->getAttributes()->get(TraceAttributes::DB_OPERATION_PARAMETER);
        $this->assertSame('foo', $actualParams[0]);
        $this->assertSame('bar', $actualParams[1]);

        $spanIdOfFirstConnection = $this->storage->offsetGet(0)->getSpanId();
        $spanIdOfOperation = $this->storage->offsetGet(2)->getLinks()[0]->getSpanContext()->getSpanId();
        $this->assertSame($spanIdOfFirstConnection, $spanIdOfOperation);
    }

    public function test_connection_numbering_disabled(): void
    {
        putenv('OTEL_PHP_INSTRUMENTATION_REDIS_MARK_SPANS_WITH_CONNECTION_NUMBER=false');

        $client1 = $this->getClientMock();
        $client2 = $this->getClientMock();

        $client1->expects($this->atLeastOnce())->method('isConnected')->willReturn(true);
        $client2->expects($this->atLeastOnce())->method('isConnected')->willReturn(true);

        $this->assertCount(2, $this->storage);
        $client1->__call('ping', []);

        $this->assertCount(3, $this->storage);
        $client2->__call('ping', []);

        $this->assertCount(4, $this->storage);
        $this->assertSame('predis __construct', $this->storage->offsetGet(0)->getName());
        $this->assertSame('predis __construct', $this->storage->offsetGet(1)->getName());
        $this->assertSame('predis ping', $this->storage->offsetGet(2)->getName());
        $this->assertSame('predis ping', $this->storage->offsetGet(3)->getName());
    }

    public function test_connection_numbering_enabled(): void
    {
        putenv('OTEL_PHP_INSTRUMENTATION_REDIS_MARK_SPANS_WITH_CONNECTION_NUMBER=true');

        $client1 = $this->getClientMock();
        $client2 = $this->getClientMock();

        $client1->expects($this->once())->method('isConnected')->willReturn(true);
        $client2->expects($this->once())->method('isConnected')->willReturn(true);

        $pingOnConnectionOne = 2;
        $this->assertCount($pingOnConnectionOne, $this->storage);
        $client1->__call('ping', []);

        $pingOnConnectionTwo = 3;
        $this->assertCount($pingOnConnectionTwo, $this->storage);
        $client2->__call('ping', []);

        $this->assertCount(4, $this->storage);
        $this->assertSame('predis(0) __construct', $this->storage->offsetGet(0)->getName());
        $this->assertSame('predis(1) __construct', $this->storage->offsetGet(1)->getName());

        $this->assertSame('predis(0) ping', $this->storage->offsetGet($pingOnConnectionOne)->getName());
        $this->assertSame('predis(1) ping', $this->storage->offsetGet($pingOnConnectionTwo)->getName());

        $spanIdOfFirstConnection = $this->storage->offsetGet(0)->getSpanId();
        $spanIdOfSecondConnection = $this->storage->offsetGet(1)->getSpanId();
        $this->assertNotSame($spanIdOfFirstConnection, $spanIdOfSecondConnection);

        $spanIdOfOperationOnFirstConnection = $this->storage->offsetGet($pingOnConnectionOne)->getLinks()[0]->getSpanContext()->getSpanId();
        $spanIdOfOperationOnSecondConnection = $this->storage->offsetGet($pingOnConnectionTwo)->getLinks()[0]->getSpanContext()->getSpanId();
        $this->assertSame($spanIdOfFirstConnection, $spanIdOfOperationOnFirstConnection);
        $this->assertSame($spanIdOfSecondConnection, $spanIdOfOperationOnSecondConnection);
        $this->assertNotSame($spanIdOfOperationOnFirstConnection, $spanIdOfOperationOnSecondConnection);
    }

    public function test_pipelines(): void
    {
        $client = $this->getClientMock(['pipeline']);

        $client->expects($this->atLeastOnce())->method('isConnected')->willReturn(true);
        $client->expects($this->once())->method('pipeline')->willReturn(new Pipeline($client));

        $this->assertCount(1, $this->storage);
        $client->__call('ping', []);
        $p = $client->pipeline();
        $p->set('foo', 'bar');
        $p->set('baz', 'gaz');
        $p->discard();
        $client->__call('ping', []);

        foreach (['predis(0) __construct', 'predis(0) ping', 'predis(0) pipeline', 'predis(0) set', 'predis(0) set', 'predis(0) discard', 'predis(0) ping'] as $i => $name) {
            $this->assertSame($name, $this->storage->offsetGet($i)->getName());
        }
        $pipelineSpanId = $this->storage->offsetGet(2)->getSpanId();
        $firstSetSpanParentId = $this->storage->offsetGet(3)->getParentSpanId();
        $secondSetSpanParentId = $this->storage->offsetGet(4)->getParentSpanId();
        $discardSpanParentId = $this->storage->offsetGet(5)->getParentSpanId();
        $this->assertSame($pipelineSpanId, $firstSetSpanParentId);
        $this->assertSame($pipelineSpanId, $secondSetSpanParentId);
        $this->assertSame($pipelineSpanId, $discardSpanParentId);
    }

    /**
     * @runInSeparateProcess 
     */
    public function test_filtered_commands(): void
    {
        $client = $this->getClientMock();

        $client->expects($this->atLeastOnce())->method('isConnected')->willReturn(true);

        $this->assertCount(1, $this->storage);
        $client->__call('ping', []);
        $client->__call('mget', [['foo', 'bar']]);
        $client->__call('get', ['foo']);

        foreach ($this->storage as $span) {
            $this->assertNotSame('predis(0) set', $span->getName());
            $this->assertStringStartsWith('predis(0)', $span->getName());
        }
    }

    public function test_exception_tracking(): void
    {
        $client = $this->getClientMock(['pipeline']);

        $client->expects($this->atLeastOnce())->method('isConnected')->willReturn(true);

        $this->assertCount(1, $this->storage);
        $client->__call('ping', []);
        $client->__call('set', ['foo', 'bar']);
        $client->__call('get', ['foo']);

        $exceptionMessage = 'test';
        $client->expects($this->once())->method('pipeline')->willThrowException(new \Exception($exceptionMessage));
        try {
            $client->pipeline();
        } catch (\Exception $e) {
        }
        $spanWithException = $this->storage->offsetGet(4);
        $this->assertSame(StatusCode::STATUS_ERROR, $spanWithException->getStatus()->getCode());
        $this->assertSame($exceptionMessage, $spanWithException->getStatus()->getDescription());
        $this->assertSame('Exception', $spanWithException->getEvents()[0]->getAttributes()->get('exception.type'));
        $this->assertSame($exceptionMessage, $spanWithException->getEvents()[0]->getAttributes()->get('exception.message'));


    }
}
