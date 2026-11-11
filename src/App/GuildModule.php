<?php

declare(strict_types=1);

namespace App;

use React\Promise\PromiseInterface;

/**
 * Contract implemented by every guild feature module.
 *
 * The host (Bot) owns the Gateway connection, the single interaction
 * dispatch loop, and command registration. A module registers its own
 * Gateway/loop handlers in attach() and claims interactions in interact()
 * by returning a promise (null means "this interaction is not mine").
 */
interface GuildModule
{
    /** Stable module identifier, e.g. "voice_wait". */
    public function name(): string;

    /** Register this module's Gateway and event-loop handlers. */
    public function attach(BotContext $context): void;

    /**
     * Guild command definitions to register (used by --register).
     *
     * @return array<array-key, mixed>
     */
    public function commands(): array;

    /**
     * Handle one normalized INTERACTION_CREATE payload.
     *
     * @param array<array-key, mixed> $interaction
     * @return PromiseInterface<mixed>|null The handling promise, or null if the
     *         module does not own this interaction (host keeps dispatching).
     */
    public function interact(array $interaction): ?PromiseInterface;
}
