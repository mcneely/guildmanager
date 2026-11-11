<?php

declare(strict_types=1);

namespace Module\VoiceWait;

use App\BotContext;
use App\GuildModule;
use React\Promise\PromiseInterface;

/**
 * Voice wait module: automatically server-mutes eligible new voice-channel
 * arrivals for five minutes, with a private status button.
 *
 * The engine (VoiceWaitBot) owns Gateway voice tracking, lease policy, and
 * the guarded mute transport. The host owns interaction dispatch and
 * command registration, which this module feeds it.
 */
final readonly class VoiceWaitModule implements GuildModule
{
    private VoiceWaitBot $bot;

    public function __construct(
        private BotContext $context,
        private MuteTransport $mutations,
        private bool $registerOnly = false,
    ) {
        $this->bot = new VoiceWaitBot($context, $mutations, $registerOnly);
    }

    public function name(): string
    {
        return 'voice_wait';
    }

    public function attach(BotContext $context): void
    {
        $this->bot->attach();
    }

    /** @return array<array-key, mixed> */
    public function commands(): array
    {
        $commands = json_decode(
            (string) file_get_contents(__DIR__ . '/commands.json'),
            true,
            // commands.json nests a handful of levels. 511 and 513 decode it the same.
            /** @infection-ignore-all */
            512,
            JSON_THROW_ON_ERROR,
        );
        /** @var array<array-key, mixed> $commands */
        return $commands;
    }

    /**
     * @param array<array-key, mixed> $interaction
     * @return PromiseInterface<mixed>|null
     */
    public function interact(array $interaction): ?PromiseInterface
    {
        return $this->bot->interact($interaction);
    }
}
