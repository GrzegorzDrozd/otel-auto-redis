<?php

declare(strict_types=1);

namespace Unit;

use OpenTelemetry\Contrib\Instrumentation\Redis\CommandGroupEnum;
use OpenTelemetry\Contrib\Instrumentation\Redis\RedisInstrumentation;
use PHPUnit\Framework\TestCase;
use function Symfony\Component\String\u;

class CommandListTest extends TestCase
{
    public function tearDown(): void
    {
        $ref = new \ReflectionClass(RedisInstrumentation::class);
        $ref->getProperty('cachedCommands')->setValue([]);
    }

    public function testGetRedisCommandsReturnsAllCommands(): void
    {
        $commands = RedisInstrumentation::getRedisCommands();

        $this->assertIsArray($commands);
        $this->assertContains('bgrewriteaof', $commands);
        $this->assertContains('zAdd', $commands);
    }

    public function testGetCommandsToInstrumentReturnsAllCommandsWhenNoConfig(): void
    {
        $commands = $this->getCommands();

        $expectedCommands = RedisInstrumentation::getRedisCommands();
        // because of phpunit.xml.dist - only way for testing reids instrumentation registration
        unset($expectedCommands[array_search('mget', $expectedCommands)]);

        $this->assertEquals(
            $expectedCommands,
            $commands
        );
    }

    public function testGetCommandsToInstrumentFiltersByGroup(): void
    {
        putenv('OTEL_PHP_INSTRUMENTATION_REDIS_REDIS_FUNCTIONS=' . CommandGroupEnum::READ_ONLY->value);

        $commands = $this->getCommands();

        $this->assertContains('get', $commands);
        $this->assertNotContains('set', $commands);
    }

    public function testGetCommandsToInstrumentExcludesCommands(): void
    {
        putenv('OTEL_PHP_INSTRUMENTATION_REDIS_REDIS_FUNCTIONS=get,-set');

        $commands = $this->getCommands();

        $this->assertContains('get', $commands);
        $this->assertNotContains('set', $commands);
        $this->assertNotContains('zAdd', $commands);
    }

    public function testGetCommandsToInstrumentWithAllButOneExclude(): void
    {
        putenv('OTEL_PHP_INSTRUMENTATION_REDIS_REDIS_FUNCTIONS=@all,-set');

        $commands = $this->getCommands();

        $this->assertContains('get', $commands);
        $this->assertNotContains('set', $commands);
        $this->assertContains('zAdd', $commands);
    }

    public function testGetCommandsToInstrumentWithAllButWholeGroupExclude(): void
    {
        putenv('OTEL_PHP_INSTRUMENTATION_REDIS_REDIS_FUNCTIONS=@all,-@readonly');

        $commands = $this->getCommands();

        $this->assertNotContains('get', $commands);
        $this->assertContains('set', $commands);
        $this->assertContains('zAdd', $commands);
    }

    public function testGetCommandsToInstrumentHandlesInvalidConfiguration(): void
    {
        $invalidConfiguration = 'invalid-group';
        putenv('OTEL_PHP_INSTRUMENTATION_REDIS_REDIS_FUNCTIONS=' . $invalidConfiguration);

        $commands = $this->getCommands();

        $this->assertIsArray($commands);
        $this->assertEmpty($commands);
    }

    public function testGetRedisCommandsWithSpecificGroup(): void
    {
        $commands = RedisInstrumentation::getRedisCommands(CommandGroupEnum::ADMIN);

        $this->assertIsArray($commands);
        $this->assertContains('bgrewriteaof', $commands);
        $this->assertContains('bgSave', $commands);
        $this->assertNotContains('zAdd', $commands);
    }

    public function testGetRedisCommandsWithMultipleGroups(): void
    {
        $groups = [CommandGroupEnum::ADMIN, CommandGroupEnum::WRITE];
        $commands = RedisInstrumentation::getRedisCommands($groups);

        $this->assertIsArray($commands);
        $this->assertContains('bgrewriteaof', $commands);
        $this->assertContains('zAdd', $commands);
        $this->assertNotContains('hget', $commands);
    }

    public function testGetRedisCommandsWithInvalidGroup(): void
    {
        $commands = RedisInstrumentation::getRedisCommands(['invalid']);

        $this->assertIsArray($commands);
        $this->assertEmpty($commands);
    }

    public function testGetRedisCommandsWithNullGroup(): void
    {
        $commands = RedisInstrumentation::getRedisCommands(null);

        $this->assertIsArray($commands);
        $this->assertContains('bgrewriteaof', $commands);
        $this->assertContains('zAdd', $commands);
    }

    /**
     * @throws \ReflectionException
     * @return mixed
     */
    public function getCommands(): mixed
    {
        $instrumentation = new RedisInstrumentation();
        $ref = new \ReflectionClass(RedisInstrumentation::class);
        $method = $ref->getMethod('getCommandsToInstrument');
        $method->setAccessible(true);

        return $method->invoke($instrumentation);
    }
}
