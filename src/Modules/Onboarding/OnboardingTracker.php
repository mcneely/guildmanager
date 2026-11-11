<?php

declare(strict_types=1);

namespace Module\Onboarding;

/**
 * Tracks each member's onboarding screening state (the `pending` flag) so
 * the module can detect the exact moment a member moves out of pending.
 *
 * Baselines are in-memory and established on the first event observed for
 * a member (a join, or an update seen before any join was seen). Only a
 * live pending -> not-pending transition is reported; a member who moves
 * out of pending while the guildmanager is offline is not granted retroactively.
 */
final class OnboardingTracker
{
    /** @var array<string, bool|null> user id => last observed pending state; null = not yet observed. */
    private array $pending = [];

    /**
     * Record a member's join with their initial screening state. A fresh
     * join is never itself a "moved out of pending" transition.
     */
    public function added(string $uid, bool $pending): bool
    {
        $this->pending[$uid] = $pending;
        return false;
    }

    /**
     * Record a member update.
     *
     * @return bool true exactly once per membership: on the first observed
     *         move out of pending (baseline pending, update not pending).
     */
    public function updated(string $uid, bool $pending): bool
    {
        $baseline = $this->pending[$uid] ?? null;
        $this->pending[$uid] = $pending;
        return $baseline === true && $pending === false;
    }

    /** Drop a member's baseline (they left the guild). */
    public function removed(string $uid): void
    {
        unset($this->pending[$uid]);
    }

    /** @return bool|null the last observed pending state, or null if unobserved. */
    public function baselineOf(string $uid): ?bool
    {
        return $this->pending[$uid] ?? null;
    }
}
