<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Command\BotCommand;
use App\BotFactory;
use App\ObservedDiscord;
use App\StateStore;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ReflectionClass;
use RuntimeException;
use PDO;
use PDOStatement;
use React\EventLoop\LoopInterface;
use Throwable;

class BotCommandDummy extends BotCommand
{
    public bool $shouldThrow = false;
    public ?BotFactory $factory = null;
    protected function registerOnly(): bool
    {
        return false;
    }
    protected function factory(): BotFactory
    {
        return $this->factory;
    }
    protected function start(BotFactory $factory): void
    {
        if ($this->shouldThrow) {
            throw new RuntimeException('Failed');
        }
    }
    public function publicExecute(InputInterface $input, OutputInterface $output): int
    {
        return $this->execute($input, $output);
    }
}

class BotCommandRunDummy extends BotCommand
{
    public ?BotFactory $factory = null;
    protected function registerOnly(): bool
    {
        return false;
    }
    protected function factory(): BotFactory
    {
        return $this->factory;
    }
    public function publicExecute(InputInterface $input, OutputInterface $output): int
    {
        return $this->execute($input, $output);
    }
}

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(\App\Command\BotCommand::class)]
class BotCommandTest extends TestCase
{
    private ?string $originalDbUrl = null;

    protected function setUp(): void
    {
        $this->originalDbUrl = getenv('DATABASE_URL') ?: null;
    }

    protected function tearDown(): void
    {
        if ($this->originalDbUrl !== null) {
            putenv('DATABASE_URL=' . $this->originalDbUrl);
        } else {
            putenv('DATABASE_URL');
        }
    }

    /**
     * @return array{0: BotFactory, 1: StateStore, 2: ObservedDiscord}
     */
    private function createMockFactoryWithMockPdo(\PHPUnit\Framework\MockObject\MockObject $mockPdo): array
    {
        putenv('DISCORD_BOT_TOKEN=test-token');
        putenv('DISCORD_GUILD_ID=123456789012345678');
        putenv('DATABASE_URL=postgres://user:***@localhost/postgres');
        putenv('STATE_LOCK_KEY=' . uniqid());

        $storeRef = new ReflectionClass(StateStore::class);
        $mockStoreInstance = $storeRef->newInstanceWithoutConstructor();

        $dbProp = $storeRef->getProperty('db');
        $dbProp->setValue($mockStoreInstance, $mockPdo);

        $factoryRef = new ReflectionClass(BotFactory::class);
        $factory = $factoryRef->newInstanceWithoutConstructor();

        $storeProp = $factoryRef->getProperty('store');
        $storeProp->setValue($factory, $mockStoreInstance);

        $guildIdProp = $factoryRef->getProperty('guildId');
        $guildIdProp->setValue($factory, '123456789012345678');

        $tokenProp = $factoryRef->getProperty('token');
        $tokenProp->setValue($factory, 'test-token');

        return [$factory, $mockStoreInstance, null];
    }

    public function testExecuteClosesStoreOnException(): void
    {
        $mockPdo = $this->createMock(PDO::class);
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);
        $mockPdo->expects($this->once())
            ->method('query')
            ->with($this->stringContains('pg_advisory_unlock_all'))
            ->willReturn($this->createMock(PDOStatement::class));

        $mockDiscord = $this->createMock(ObservedDiscord::class);
        $mockDiscord->method('on');
        $mockDiscord->method('getLoop')->willReturn($this->createMock(LoopInterface::class));

        [$factory] = $this->createMockFactoryWithMockPdo($mockPdo);

        $factoryRef = new ReflectionClass($factory);
        $factoryRef->getProperty('discord')->setValue($factory, $mockDiscord);

        $logger = new TestLogger();
        $command = new BotCommandDummy($logger);
        $command->factory = $factory;
        $command->shouldThrow = true;

        $result = $command->publicExecute($this->createMock(InputInterface::class), $this->createMock(OutputInterface::class));

        $this->assertSame(1, $result);
        $this->assertNotNull($logger->lastError);
        $this->assertStringContainsString('Failed', $logger->lastError);
        $this->assertInstanceOf(RuntimeException::class, $logger->lastException);
    }

    public function testExecuteCallsDiscordRun(): void
    {
        $mockDiscord = $this->createMock(ObservedDiscord::class);
        $mockDiscord->expects($this->once())->method('run');
        $mockDiscord->method('on');
        $mockDiscord->method('getLoop')->willReturn($this->createMock(LoopInterface::class));

        $mockPdo = $this->createMock(PDO::class);
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetchColumn')->willReturn(null);

        [$factory, $realStore, $unused] = $this->createMockFactoryWithMockPdo($mockPdo);

        $factoryRef = new ReflectionClass($factory);
        $factoryRef->getProperty('discord')->setValue($factory, $mockDiscord);

        $logger = new NullLogger();
        $command = new BotCommandRunDummy($logger);
        $command->factory = $factory;

        $result = $command->publicExecute($this->createMock(InputInterface::class), $this->createMock(OutputInterface::class));

        $this->assertSame(0, $result);
    }
}

class TestLogger extends AbstractLogger
{
    public ?string $lastError = null;
    public ?Throwable $lastException = null;
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lastError = (string) $message;
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $this->lastException = $context['exception'];
        }
    }
}
