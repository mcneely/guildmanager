<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\BotContext;
use App\BotFactory;
use App\ObservedDiscord;
use App\Command\BotCommand;
use App\Command\RunBotCommand;
use App\StateStore;
use Module\Onboarding\OnboardingModule;
use Discord\WebSockets\Intents;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use ReflectionObject;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\Database;
use Tests\Support\Harness;

#[CoversClass(BotFactory::class)]
#[CoversClass(BotCommand::class)]
final class BotFactoryTest extends GuildTestCase
{
    protected function setUp(): void
    {
        putenv('STATE_LOCK_KEY=' . getmypid());
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('DISCORD_BOT_TOKEN');
        putenv('DISCORD_GUILD_ID');
        putenv('ONBOARDING_ROLE_NAME');
        putenv('STATE_LOCK_KEY');

        // Clean up state database to prevent lease leaks between tests
        try {
            $pdo = \Tests\Support\Database::side();
            $pdo->exec('DELETE FROM state');
        } catch (\Throwable) {
            // Ignore if database isn't available
        }

        parent::tearDown();
    }

    public function testFromEnvironmentRejectsAMissingTokenOrGuild(): void
    {
        putenv('DISCORD_BOT_TOKEN');
        putenv('DISCORD_GUILD_ID=111111111111111111');
        try {
            BotFactory::fromEnvironment();
            self::fail('missing token was accepted');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('DISCORD_BOT_TOKEN', $error->getMessage());
        }
        putenv('DISCORD_BOT_TOKEN=offline-token');
        putenv('DISCORD_GUILD_ID=nope');
        $this->expectException(RuntimeException::class);
        BotFactory::fromEnvironment();
    }

    public function testBuildWiresEveryModuleAndTheCommandStopsTheLoop(): void
    {
        putenv('DISCORD_BOT_TOKEN=offline-token');
        putenv('DISCORD_GUILD_ID=' . Harness::GUILD);
        putenv('DATABASE_URL=' . Database::url());
        putenv('ONBOARDING_ROLE_NAME=Members');
        $factory = BotFactory::fromEnvironment();
        self::assertNotNull($factory->discord());
        $running = $factory->build(false);
        $registering = $factory->build(true);
        self::assertNotSame($running, $registering);
        unset($factory, $running, $registering);
        gc_collect_cycles();

        $command = new class extends RunBotCommand {
            public int $ran = 0;

            public ?BotFactory $ready = null;

            public int $built = 0;

            protected function factory(): BotFactory
            {
                ++$this->built;
                putenv('DISCORD_BOT_TOKEN=offline-token');
                putenv('DISCORD_GUILD_ID=' . Harness::GUILD);
                putenv('DATABASE_URL=' . Database::url());
                $loop = new \Tests\Support\TestLoop();
                $loop->futureTick(static function () use ($loop): void {
                    $loop->stop();
                });
                $silent = new \Tests\Support\SilentDiscord([
                    'token' => 'offline-token',
                    'loop' => $loop,
                    'intents' => 0,
                    'loadAllMembers' => false,
                    'storeMessages' => false,
                    'disableVoiceClient' => true,
                    'logger' => new \Psr\Log\NullLogger(),
                ]);
                $props = new ReflectionClass(BotFactory::class);
                $factory = $props->newInstanceWithoutConstructor();
                $props->getProperty('discord')->setValue($factory, $silent);
                $props->getProperty('store')->setValue($factory, new StateStore(Database::url()));
                $props->getProperty('guildId')->setValue($factory, Harness::GUILD);
                $props->getProperty('token')->setValue($factory, 'offline-token');
                $this->ready = $factory;

                return $factory;
            }

            protected function start(BotFactory $factory): void
            {
                ++$this->ran;
                $factory->discord()->getLoop()->stop();
            }
        };
        $tester = new CommandTester($command);
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(1, $command->ran);
        self::assertSame(1, $command->built);
        self::assertNotEmpty($command->ready->discord()->listeners('raw'));
        $reopened = new StateStore(Database::url());
        unset($reopened);
        unset($tester, $command);
        gc_collect_cycles();

        $loop = new \Tests\Support\TestLoop();
        $silent = new \Tests\Support\SilentDiscord([
            'token' => 'offline-token',
            'loop' => $loop,
            'intents' => 0,
            'loadAllMembers' => false,
            'storeMessages' => false,
            'disableVoiceClient' => true,
            'logger' => new \Psr\Log\NullLogger(),
        ]);
        $factory = (new ReflectionClass(BotFactory::class))->newInstanceWithoutConstructor();
        new ReflectionProperty(BotFactory::class, 'discord')->setValue($factory, $silent);
        $loop->futureTick(static function () use ($loop): void {
            $loop->stop();
        });
        $run = new ReflectionMethod(BotCommand::class, 'start');
        $run->invoke(new RunBotCommand(), $factory);
        unset($factory, $silent);
        gc_collect_cycles();
    }

    public function testSetLoggerAssignsStaticProperty(): void
    {
        $logger = new \Psr\Log\NullLogger();
        BotFactory::setLogger($logger);

        $prop = new ReflectionProperty(BotFactory::class, 'logger');
        self::assertSame($logger, $prop->getValue());

        // Reset to avoid leaking into other tests
        $prop->setValue(null, null);
    }

    public function testLoggerUsesSetLogger(): void
    {
        $logger = new \Psr\Log\NullLogger();
        BotFactory::setLogger($logger);

        putenv('DISCORD_BOT_TOKEN=token');
        putenv('DISCORD_GUILD_ID=123456789012345678');
        putenv('DATABASE_URL=' . Database::url());
        $factory = BotFactory::fromEnvironment();

        $discordProp = new ReflectionProperty(BotFactory::class, 'discord');
        $discord = $discordProp->getValue($factory);

        $discordPropLog = new ReflectionProperty(ObservedDiscord::class, 'logger');
        self::assertSame($logger, $discordPropLog->getValue($discord));

        // Reset
        BotFactory::setLogger(new \Psr\Log\NullLogger());
    }

    public function testFromEnvironmentRejectsInvalidGuildIdFormat(): void
    {
        putenv('DISCORD_BOT_TOKEN=token');

        $invalidIds = [
            '123',                      // Too short
            '123456789012345678901',     // Too long
            '123456789012345678a',      // Contains letters
            ' 123456789012345678',      // Leading space
            '123456789012345678 ',      // Trailing space
        ];

        foreach ($invalidIds as $id) {
            putenv('DISCORD_GUILD_ID=' . $id);
            try {
                BotFactory::fromEnvironment();
                self::fail('expected RuntimeException for guild ID: ' . $id);
            } catch (RuntimeException $error) {
                self::assertStringContainsString('DISCORD_GUILD_ID', $error->getMessage());
            }
        }
    }

    public function testBotConfiguration(): void
    {
        putenv('DISCORD_BOT_TOKEN=test-token');
        putenv('DISCORD_GUILD_ID=123456789012345678');
        putenv('ONBOARDING_ROLE_NAME=Members');

        $factory = BotFactory::fromEnvironment();
        $bot = $factory->build(false);

        $ref = new ReflectionClass($bot);
        $contextProp = $ref->getProperty('context');
        $context = $contextProp->getValue($bot);

        $this->assertInstanceOf(BotContext::class, $context);
        $this->assertInstanceOf(ObservedDiscord::class, $context->discord);
        $this->assertInstanceOf(StateStore::class, $context->store);

        $discordRef = new ReflectionObject($context->discord);
        $optionsProp = $discordRef->getProperty('options');
        $options = $optionsProp->getValue($context->discord);

        self::assertSame(Intents::GUILDS | Intents::GUILD_VOICE_STATES | Intents::GUILD_MEMBERS, $options['intents']);
        self::assertFalse($options['loadAllMembers']);
        self::assertFalse($options['storeMessages']);
        self::assertTrue($options['disableVoiceClient']);

        $factory->store()->close();
    }

    public function testBuildWiresExpectedModules(): void
    {
        putenv('DISCORD_BOT_TOKEN=test-token');
        putenv('DISCORD_GUILD_ID=123456789012345678');
        putenv('DATABASE_URL=' . Database::url());
        putenv('ONBOARDING_ROLE_NAME=Members');

        $factory = BotFactory::fromEnvironment();
        $bot = $factory->build(false);

        $ref = new ReflectionClass($bot);
        $modulesProp = $ref->getProperty('modules');

        $modules = $modulesProp->getValue($bot);

        $moduleNames = array_map(fn($m) => $m->name(), $modules);

        self::assertContains('onboarding', $moduleNames);
        self::assertContains('voice_wait', $moduleNames);
        self::assertContains('overflow', $moduleNames);

        $onboardingModule = null;
        foreach ($modules as $module) {
            if ($module instanceof OnboardingModule) {
                $onboardingModule = $module;
                break;
            }
        }
        $this->assertNotNull($onboardingModule);

        $onboardingRef = new ReflectionClass($onboardingModule);
        $roleNameProp = $onboardingRef->getProperty('roleName');

        self::assertSame('Members', $roleNameProp->getValue($onboardingModule));

        // Test default
        putenv('ONBOARDING_ROLE_NAME');
        $factory->store()->close();

        $factory2 = BotFactory::fromEnvironment();
        $bot2 = $factory2->build(false);
        $modules2 = $modulesProp->getValue($bot2);
        $onboardingModule2 = null;
        foreach ($modules2 as $module) {
            if ($module instanceof OnboardingModule) {
                $onboardingModule2 = $module;
                break;
            }
        }
        $this->assertNotNull($onboardingModule2);
        $roleNameProp2 = $onboardingRef->getProperty('roleName');

        self::assertSame('Verified', $roleNameProp2->getValue($onboardingModule2));
        $factory2->store()->close();
    }
}
