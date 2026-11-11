<?php

declare(strict_types=1);

namespace Module\VoiceWait;

use InvalidArgumentException;

/**
 * Transport-independent policy. This class makes no Discord calls.
 *
 * Persist snapshot() atomically BEFORE executing any returned operation.
 * Recovered leases deliberately require review. A shared Discord boolean
 * cannot provide a guarantee against an indistinguishable concurrent edit.
 *
 * @phpstan-type LeaseRecord array{
 *     version: int,
 *     guild_id: string,
 *     user_id: string,
 *     channel_id: string,
 *     session_id: string,
 *     mode: string,
 *     started_at: int,
 *     expires_at: int,
 *     state: string,
 *     generation: int,
 *     original: array<array-key, mixed>,
 *     expected: array<array-key, mixed>,
 *     reason: string|null
 * }
 */
final class WaitLease
{
    /** @param LeaseRecord $record */
    private function __construct(private array $record) {}

    /**
     * @param array<array-key, mixed> $original
     * @param array<array-key, mixed> $expected
     */
    public static function begin(
        string $guildId,
        string $userId,
        string $channelId,
        string $sessionId,
        string $mode,
        int $now,
        bool $alreadyRestricted,
        array $original,
        array $expected,
    ): ?self {
        if (!in_array($mode, ['server_mute', 'speak_gate'], true)) {
            throw new InvalidArgumentException('Unsupported restriction mode.');
        }
        if ($alreadyRestricted) {
            return null; // Never claim or later release a preexisting restriction.
        }
        foreach ([$guildId, $userId, $channelId, $sessionId] as $id) {
            if ($id === '') {
                throw new InvalidArgumentException('Missing identity or session.');
            }
        }

        return new self([
            'version' => 1,
            'guild_id' => $guildId,
            'user_id' => $userId,
            'channel_id' => $channelId,
            'session_id' => $sessionId,
            'mode' => $mode,
            'started_at' => $now,
            'expires_at' => $now + 300,
            'state' => 'applying',
            'generation' => 1,
            'original' => $original,
            'expected' => $expected,
            'reason' => null,
        ]);
    }

    /** @param array<array-key, mixed> $saved */
    public static function recover(array $saved): self
    {
        $required = [
            'version', 'guild_id', 'user_id', 'channel_id', 'session_id',
            'mode', 'started_at', 'expires_at', 'state', 'generation',
            'original', 'expected',
        ];
        foreach ($required as $field) {
            if (!array_key_exists($field, $saved)) {
                throw new InvalidArgumentException('Incomplete saved lease.');
            }
        }
        $lease = new self(self::record($saved));
        if (in_array($saved['state'], ['applying', 'active', 'releasing'], true)) {
            $lease->hold('Recovered after restart; a moderator must review ownership.');
        }
        return $lease;
    }

    /** The caller must persist this generation before issuing the apply request. */
    public function generation(): int
    {
        return $this->record['generation'];
    }

    /** Called only after a successful apply response AND a fresh matching state. */
    /** @param array<array-key, mixed> $observation */
    public function applied(int $generation, array $observation): bool
    {
        if (!$this->matchesOperation($generation, 'applying')) {
            return false;
        }
        if (!$this->sameIdentityAndSession($observation)
            || ($observation['channel_id'] ?? null) !== $this->record['channel_id']
            || !$this->sameRestriction($observation)) {
            $this->hold('The state changed before apply was verified.');
            return false;
        }
        $this->record['state'] = 'active';
        return true;
    }

    /**
     * Evaluate a FRESH observation at expiry or before cleanup after a move.
     * The adapter must also serialize Gateway/REST processing and check its
     * connection epoch immediately before issuing the actual REST request.
     *
     * @param array<array-key, mixed> $observation
     *
     * @return array{
     *     generation: int,
     *     guild_id: string,
     *     user_id: string,
     *     channel_id: string,
     *     mode: string,
     *     restore: array<array-key, mixed>
     * }|null
     */
    public function prepareRelease(
        int $now,
        array $observation,
        bool $gatewayContinuous,
        bool $leftSelectedChannel = false,
    ): ?array {
        if ($this->record['state'] !== 'active') {
            return null;
        }
        if (!$gatewayContinuous) {
            $this->hold('Gateway observation was interrupted.');
            return null;
        }
        if ($now < $this->record['expires_at'] && !$leftSelectedChannel) {
            return null;
        }
        if (($observation['guild_id'] ?? null) !== $this->record['guild_id']
            || ($observation['user_id'] ?? null) !== $this->record['user_id']) {
            $this->hold('Observation identity mismatch.');
            return null;
        }
        if ($this->record['mode'] === 'server_mute') {
            if (!$this->sameIdentityAndSession($observation)
                || empty($observation['channel_id'])) {
                $this->hold('Member disconnected or changed session; review before unmuting.');
                return null;
            }
        }
        if (!$this->sameRestriction($observation)) {
            $this->hold('Restriction differs from the guildmanager-owned snapshot.');
            return null;
        }

        $this->record['state'] = 'releasing';
        ++$this->record['generation'];
        return [
            'generation' => $this->record['generation'],
            'guild_id' => $this->record['guild_id'],
            'user_id' => $this->record['user_id'],
            'channel_id' => $this->record['channel_id'],
            'mode' => $this->record['mode'],
            'restore' => $this->record['original'],
        ];
    }

    /** Call only after a successful release response and matching verification. */
    public function released(int $generation, bool $restorationVerified): bool
    {
        if (!$this->matchesOperation($generation, 'releasing')) {
            return false;
        }
        if (!$restorationVerified) {
            $this->hold('Release response could not be verified.');
            return false;
        }
        $this->record['state'] = 'complete';
        $this->record['reason'] = null;
        return true;
    }

    public function hold(string $reason): void
    {
        if (in_array($this->record['state'], ['complete', 'cancelled'], true)) {
            return;
        }
        $this->record['state'] = 'review';
        $this->record['reason'] = $reason;
        ++$this->record['generation']; // Invalidates every outstanding callback.
    }

    /** External removal is an override; do not reapply during this lease. */
    public function externalRemovalObserved(): void
    {
        if (in_array($this->record['state'], ['complete', 'cancelled'], true)) {
            return;
        }
        $this->record['state'] = 'cancelled';
        $this->record['reason'] = 'Restriction removed outside the automatic release.';
        ++$this->record['generation'];
    }

    public function status(int $now): string
    {
        return match ($this->record['state']) {
            'applying' => 'Your five-minute wait is being applied.',
            'active' => $now < $this->record['expires_at']
                ? 'Your wait ends <t:' . $this->record['expires_at'] . ':R> '
                  . '(<t:' . $this->record['expires_at'] . ':T>).'
                : 'Your wait has elapsed. Release is still being checked.',
            'releasing' => 'Your wait has elapsed. Release is being confirmed.',
            'complete' => 'The guildmanager\'s wait restriction has been removed. '
                . 'Your own mute or other server restrictions may still apply.',
            'cancelled' => 'The automatic wait was overridden. '
                . 'Check your current voice state or ask a moderator.',
            default => 'A moderator needs to review this wait before automatic release.',
        };
    }

    /** @return LeaseRecord */
    public function snapshot(): array
    {
        return $this->record;
    }

    private function matchesOperation(int $generation, string $state): bool
    {
        return $generation === $this->record['generation']
            && $this->record['state'] === $state;
    }

    /** @param array<array-key, mixed> $observation */
    private function sameIdentityAndSession(array $observation): bool
    {
        return ($observation['guild_id'] ?? null) === $this->record['guild_id']
            && ($observation['user_id'] ?? null) === $this->record['user_id']
            && ($observation['session_id'] ?? null) === $this->record['session_id'];
    }

    /** @param array<array-key, mixed> $observation */
    private function sameRestriction(array $observation): bool
    {
        // Adapters must canonicalize bitsets as decimal strings and key order.
        // speak_gate must observe the ORIGINAL selected channel's overwrite.
        return ($observation['restriction'] ?? null) === $this->record['expected'];
    }

    /**
     * @param array<array-key, mixed> $saved
     *
     * @return LeaseRecord
     */
    private static function record(array $saved): array
    {
        $reason = $saved['reason'] ?? null;
        if (!is_int($saved['version'] ?? null) || $saved['version'] !== 1
            || !is_int($saved['generation'] ?? null)
            || !is_int($saved['started_at'] ?? null)
            || !is_int($saved['expires_at'] ?? null)
            || !is_string($saved['guild_id'] ?? null)
            || !is_string($saved['user_id'] ?? null)
            || !is_string($saved['channel_id'] ?? null)
            || !is_string($saved['session_id'] ?? null)
            || !is_string($saved['mode'] ?? null)
            || !is_string($saved['state'] ?? null)
            || !is_array($saved['original'] ?? null)
            || !is_array($saved['expected'] ?? null)
            || (!is_string($reason) && $reason !== null)) {
            throw new InvalidArgumentException('Unsupported saved lease.');
        }

        return [
            'version' => $saved['version'],
            'guild_id' => $saved['guild_id'],
            'user_id' => $saved['user_id'],
            'channel_id' => $saved['channel_id'],
            'session_id' => $saved['session_id'],
            'mode' => $saved['mode'],
            'started_at' => $saved['started_at'],
            'expires_at' => $saved['expires_at'],
            'state' => $saved['state'],
            'generation' => $saved['generation'],
            'original' => $saved['original'],
            'expected' => $saved['expected'],
            'reason' => $reason,
        ];
    }
}
