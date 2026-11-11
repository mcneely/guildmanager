<?php

declare(strict_types=1);

namespace App;

use Discord\WebSockets\Intents;
use Module\Onboarding\OnboardingModule;
use Module\Overflow\MoveTransport;
use Module\Overflow\OverflowModule;
use Module\VoiceWait\MuteTransport;
use Module\VoiceWait\VoiceWaitModule;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Monolog\Logger;

/**
 * Builds the Gateway client and wires the host to its Modules from the process
 * environment. Every configuration problem becomes a RuntimeException with
 * an actionable message, so the console commands can surface it verbatim.
 */
final class BotFactory
{
    private static ?LoggerInterface $logger = null;
    private function __construct(
        private readonly ObservedDiscord $discord,
        private readonly StateStore $store,
        private readonly string $guildId,
        private readonly string $token,
    ) {}

    public static function setLogger(LoggerInterface $logger): void
    {
        static::$logger = $logger;
    }
    private static function logger(): LoggerInterface
    {
        return static::$logger ?? new NullLogger();
    }
    public static function fromEnvironment(): self
    {
        $token = getenv('DISCORD_BOT_TOKEN') ?: '';
        $guildId = getenv('DISCORD_GUILD_ID') ?: '';
        if ($token === '' || !preg_match('/^\d{17,20}$/', $guildId)) {
            throw new RuntimeException('Set DISCORD_BOT_TOKEN and a valid DISCORD_GUILD_ID in your environment. Never paste the token into chat.');
        }
        // Prevent default DEBUG output from printing raw packets, interaction tokens, etc.
        // Built after the state store: the Discord client's constructor schedules
        // async work on the ReactPHP loop, and the store's lock is the first
        // failure we want to surface without starting that loop.
        $store = new StateStore();
        $discord = new ObservedDiscord([
            'token' => $token,
            'intents' => Intents::GUILDS | Intents::GUILD_VOICE_STATES | Intents::GUILD_MEMBERS,
            'loadAllMembers' => false,
            'storeMessages' => false,
            'disableVoiceClient' => true,
            'logger' => self::logger(),
        ]);
        return new self($discord, $store, $guildId, $token);
    }

    public function discord(): ObservedDiscord
    {
        return $this->discord;
    }

    public function store(): StateStore
    {
        return $this->store;
    }

    /**
     * Wire the host and both Modules. $registerOnly builds the
     * register-and-exit variant of every component.
     */
    public function build(bool $registerOnly): Bot
    {
        $logger = self::logger();
        $context = new BotContext($this->discord, $this->store, $this->guildId, $logger);
        $modules = [
            new VoiceWaitModule($context, new MuteTransport($this->discord->getLoop(), $this->token), $registerOnly),
            new OnboardingModule($context, getenv('ONBOARDING_ROLE_NAME') ?: 'Verified', null, $registerOnly),
            new OverflowModule($context, new MoveTransport($this->discord->getLoop(), $this->token), $registerOnly),
        ];
        return new Bot($context, $modules, $registerOnly);
    }
}
