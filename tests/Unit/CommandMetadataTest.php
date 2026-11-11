<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Command\BotCommand;
use App\Command\RegisterCommandsCommand;
use App\Command\RunBotCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(RunBotCommand::class)]
#[CoversClass(RegisterCommandsCommand::class)]
#[CoversClass(BotCommand::class)]
final class CommandMetadataTest extends GuildTestCase
{
    public function testCommandNamesAndRegisterOnlyFlags(): void
    {
        $app = new Application();
        $run = new RunBotCommand();
        $register = new RegisterCommandsCommand();
        $app->addCommands([$run, $register]);
        self::assertSame('run', $run->getName());
        self::assertSame('register', $register->getName());
        self::assertStringContainsString('voice-wait', (string) $run->getDescription());
        self::assertStringContainsString('Upsert', (string) $register->getDescription());
        self::assertStringContainsString('DATABASE_URL', $run->getHelp());
        self::assertStringContainsString('state database', $register->getHelp());
        self::assertFalse(new ReflectionMethod(RunBotCommand::class, 'registerOnly')->invoke($run));
        self::assertTrue(new ReflectionMethod(RegisterCommandsCommand::class, 'registerOnly')->invoke($register));
    }

    public function testExecuteReportsAMissingEnvironmentAndDoesNotStartTheLoop(): void
    {
        putenv('DISCORD_BOT_TOKEN');
        putenv('DISCORD_GUILD_ID');
        $logger = new class extends AbstractLogger {
            public string $seen = '';

            public array $context = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->seen .= (string) $message;
                $this->context = $context;
            }
        };
        $status = new RunBotCommand($logger)->run(new ArrayInput([]), new BufferedOutput());
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('DISCORD_BOT_TOKEN', $logger->seen);
        self::assertInstanceOf(\Throwable::class, $logger->context['exception'] ?? null);

        $loggerProperty = new \ReflectionProperty(\App\BotFactory::class, 'logger');
        $loggerProperty->setValue(null, new \Psr\Log\NullLogger());
        $probe = new class ($logger) extends BotCommand {
            protected function registerOnly(): bool
            {
                return true;
            }

            public function probe(): void
            {
                $this->factory();
            }
        };
        try {
            $probe->probe();
            self::fail('factory did not reject a missing token');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('DISCORD_BOT_TOKEN', $error->getMessage());
        }
        self::assertSame($logger, $loggerProperty->getValue(null));
    }
}
