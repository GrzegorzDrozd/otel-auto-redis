<?php

declare(strict_types=1);

namespace Integration;

use ArrayObject;
use Credis_Client;
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

class CredisInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    /** @var ArrayObject<int, ImmutableSpan> */
    private ArrayObject $storage;

    private Credis_Client&MockObject $credisMock;

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
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    protected function getClientMock(array $methods = []): Credis_Client&MockObject
    {
        //        $mock = $this->getMockBuilder(\Redis::class)
        //            ->disableProxyingToOriginalMethods()
        //            ->onlyMethods([
        ////                'ping',
        ////                'set',
        //                ...$methods,
        //            ])
        //            ->getMock();

        $credisMock = $this->getMockBuilder(Credis_Client::class)
//            ->enableProxyingToOriginalMethods()
            ->disableProxyingToOriginalMethods()
            ->onlyMethods(['forceStandalone','connect', 'write_command','read_reply',...$methods])
            ->addMethods(['pipeline', 'discard', 'mget'])
            ->getMock();

        $credisMock->expects($this->atLeastOnce())->method('connect')->willReturn(true);
        $credisMock->forceStandalone();

        $ref = new \ReflectionObject($credisMock);
        $property = $ref->getProperty('standalone');
        $property->setAccessible(true);
        $property->setValue($credisMock, true);

        return $credisMock;
    }

    public function test_credis(): void
    {
        $client = $this->getClientMock();

        $this->assertCount(1, $this->storage);
        $client->ping();
        $client->set('foo', 'bar');

        $this->assertCount(3, $this->storage);
        $this->assertSame('credis(0) __construct', $this->storage->offsetGet(0)->getName());
        $this->assertSame('credis(0) ping', $this->storage->offsetGet(1)->getName());
        $this->assertSame('credis(0) set', $this->storage->offsetGet(2)->getName());

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

        $this->assertCount(2, $this->storage);
        $client1->ping();

        $this->assertCount(3, $this->storage);
        $client2->ping();

        $this->assertCount(4, $this->storage);
        $this->assertSame('credis __construct', $this->storage->offsetGet(0)->getName());
        $this->assertSame('credis __construct', $this->storage->offsetGet(1)->getName());

        $this->assertSame('credis ping', $this->storage->offsetGet(2)->getName());
        $this->assertSame('credis ping', $this->storage->offsetGet(3)->getName());
    }

    public function test_connection_numbering_enabled(): void
    {
        putenv('OTEL_PHP_INSTRUMENTATION_REDIS_MARK_SPANS_WITH_CONNECTION_NUMBER=true');

        $client1 = $this->getClientMock();
        $client2 = $this->getClientMock();

        $pingOnConnectionOne = 2;
        $this->assertCount($pingOnConnectionOne, $this->storage);
        $client1->ping();

        $pingOnConnectionTwo = 3;
        $this->assertCount($pingOnConnectionTwo, $this->storage);
        $client2->ping();

        $this->assertCount(4, $this->storage);
        $this->assertSame('credis(0) __construct', $this->storage->offsetGet(0)->getName());
        $this->assertSame('credis(1) __construct', $this->storage->offsetGet(1)->getName());
        $this->assertSame('credis(0) ping', $this->storage->offsetGet(2)->getName());
        $this->assertSame('credis(1) ping', $this->storage->offsetGet(3)->getName());

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
        $client = $this->getClientMock();

        $this->assertCount(1, $this->storage);
        $client->ping();
        
        $p = $client->multi();
        $p->set('foo', 'bar');
        $p->set('baz', 'gaz');
        $p->discard();
        $client->ping();

        foreach (['credis(0) __construct', 'credis(0) ping', 'credis(0) multi', 'credis(0) set', 'credis(0) set', 'credis(0) discard', 'credis(0) ping'] as $i => $name) {
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
     * @runInSeparateProcess true
     */
    public function test_filtered_commands(): void
    {
        $client = $this->getClientMock();

        $this->assertCount(1, $this->storage);
        $client->ping();
        $client->set('foo', 'bar');
        $client->mget(['foo']);

        foreach ($this->storage as $span) {
            $this->assertNotSame('credis(0) mget', $span->getName());
            $this->assertStringStartsWith('credis(0)', $span->getName());
        }
    }

    public function test_exception_tracking(): void
    {
        $client = $this->getClientMock(['__call']);

        $client->connect();
        $this->assertCount(1, $this->storage);

        $exceptionMessage = 'test';
        $client->expects($this->once())->method('__call')->willThrowException(new \Exception($exceptionMessage));

        try {
            $client->ping();
        } catch (\Exception $e) {
        }

        $spanWithException = $this->storage->offsetGet(1);
        $this->assertSame(StatusCode::STATUS_ERROR, $spanWithException->getStatus()->getCode());
        $this->assertSame($exceptionMessage, $spanWithException->getStatus()->getDescription());
        $this->assertSame('Exception', $spanWithException->getEvents()[0]->getAttributes()->get('exception.type'));
        $this->assertSame($exceptionMessage, $spanWithException->getEvents()[0]->getAttributes()->get('exception.message'));
    }

    public function test_connection_parameters_tracking(): void
    {
        $client1 = $this->getClientMock(['getHost', 'getPort', 'getSelectedDb', 'isConnected']);
        $client2 = $this->getClientMock(['getHost', 'getPort', 'getSelectedDb', 'isConnected']);

        $client1->expects($this->exactly(3))->method('isConnected')->willReturn(true);


        $client1->expects($this->exactly(1))->method('getHost')->willReturn('localhost');
        $client1->expects($this->exactly(1))->method('getPort')->willReturn(6379);
        $client1->expects($this->exactly(3))->method('getSelectedDb')->willReturn(0);


        $client2->expects($this->exactly(3))->method('isConnected')->willReturn(true);

        $client2->expects($this->exactly(1))->method('getHost')->willReturn('127.0.0.1');
        $client2->expects($this->exactly(1))->method('getPort')->willReturn(16379);
        $client2->expects($this->exactly(3))->method('getSelectedDb')->willReturn(11);

        $client1->ping();
        $client1->set('foo', 'bar');
        $client1->get('foo');

        $client2->ping();
        $client2->set('foo', 'bar');
        $client2->get('foo');

        $this->assertSame('localhost', $this->storage->offsetGet(3)->getAttributes()->get(TraceAttributes::SERVER_ADDRESS));
        $this->assertSame(6379, $this->storage->offsetGet(3)->getAttributes()->get(TraceAttributes::SERVER_PORT));
        $this->assertSame(0, $this->storage->offsetGet(3)->getAttributes()->get(TraceAttributes::DB_NAMESPACE));
        
        $this->assertSame('127.0.0.1', $this->storage->offsetGet(5)->getAttributes()->get(TraceAttributes::SERVER_ADDRESS));
        $this->assertSame(16379, $this->storage->offsetGet(5)->getAttributes()->get(TraceAttributes::SERVER_PORT));
        $this->assertSame(11, $this->storage->offsetGet(5)->getAttributes()->get(TraceAttributes::DB_NAMESPACE));
    }
}
