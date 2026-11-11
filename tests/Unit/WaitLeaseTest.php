<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Module\VoiceWait\WaitLease;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WaitLease::class)]
final class WaitLeaseTest extends TestCase
{
    public function testPreexistingRestrictionIsNeverClaimed(): void
    {
        self::assertNull(WaitLease::begin('g', 'u', 'c', 's', 'server_mute', 1000, true, ['mute' => true], ['mute' => true]));
    }

    public function testApplyExpiryAndStaleRelease(): void
    {
        $lease = $this->lease();
        self::assertNull($lease->prepareRelease(1400, $this->observation(), true));
        self::assertTrue($lease->applied(1, $this->observation()));
        self::assertNull($lease->prepareRelease(1299, $this->observation(), true));
        $release = $lease->prepareRelease(1300, $this->observation(), true);
        self::assertIsArray($release);
        self::assertSame(['mute' => false], $release['restore']);
        self::assertSame(2, $release['generation']);
        self::assertNull($lease->prepareRelease(1301, $this->observation(), true));
        self::assertFalse($lease->released(1, true));
        self::assertTrue($lease->released($release['generation'], true));
        self::assertSame('complete', $lease->snapshot()['state']);
        self::assertNull($lease->snapshot()['reason']);
    }

    public function testRecoveredLeaseRequiresReviewAndKeepsTheDeadline(): void
    {
        $lease = $this->lease();
        $lease->applied(1, $this->observation());
        $recovered = WaitLease::recover($lease->snapshot());
        self::assertSame(1300, $recovered->snapshot()['expires_at']);
        self::assertSame('review', $recovered->snapshot()['state']);
        self::assertNull($recovered->prepareRelease(1500, $this->observation(), true));
    }

    public function testGatewayGapDisconnectedMemberAndRejoinBlockRelease(): void
    {
        $lease = $this->lease();
        $lease->applied(1, $this->observation());
        self::assertNull($lease->prepareRelease(1300, $this->observation(), false));
        self::assertSame('review', $lease->snapshot()['state']);

        $lease = $this->lease();
        $lease->applied(1, $this->observation());
        $left = $this->observation();
        $left['channel_id'] = null;
        self::assertNull($lease->prepareRelease(1300, $left, true));
        self::assertSame('review', $lease->snapshot()['state']);

        $lease = $this->lease();
        $lease->applied(1, $this->observation());
        $rejoined = $this->observation();
        $rejoined['session_id'] = 'new-session';
        self::assertNull($lease->prepareRelease(1300, $rejoined, true));
    }

    public function testMoveAllowsPromptCleanupInTheSameSession(): void
    {
        $lease = $this->lease();
        $lease->applied(1, $this->observation());
        $moved = $this->observation();
        $moved['channel_id'] = 'other';
        self::assertNotNull($lease->prepareRelease(1005, $moved, true, true));
    }

    public function testModeratorOverrideAndHoldRejectLateCallbacks(): void
    {
        $lease = $this->lease();
        $lease->applied(1, $this->observation());
        $lease->externalRemovalObserved();
        self::assertSame('cancelled', $lease->snapshot()['state']);
        self::assertNull($lease->prepareRelease(1300, $this->observation(), true));
        self::assertFalse($lease->applied(1, $this->observation()));

        $lease = $this->lease();
        $lease->hold('Moderator requested hold.');
        self::assertSame(2, $lease->generation());
        self::assertFalse($lease->applied(1, $this->observation()));
        self::assertSame('review', $lease->snapshot()['state']);
    }

    public function testUnverifiedReleaseRetainsTheReviewRecord(): void
    {
        $lease = $this->lease();
        $lease->applied(1, $this->observation());
        $release = $lease->prepareRelease(1300, $this->observation(), true);
        self::assertIsArray($release);
        self::assertFalse($lease->released($release['generation'], false));
        self::assertSame('review', $lease->snapshot()['state']);
    }

    public function testSpeakGateAcceptsTheExpectedOverwriteAndRefusesAChangedOne(): void
    {
        $original = ['exists' => true, 'allow' => '2097152', 'deny' => '0'];
        $expected = ['exists' => true, 'allow' => '0', 'deny' => '2097152'];
        $lease = WaitLease::begin('g', 'u', 'c', 's', 'speak_gate', 1000, false, $original, $expected);
        self::assertInstanceOf(WaitLease::class, $lease);
        $gate = $this->observation();
        $gate['restriction'] = $expected;
        self::assertTrue($lease->applied(1, $gate));
        $gate['restriction']['deny'] = '2097153';
        self::assertNull($lease->prepareRelease(1300, $gate, true));
        self::assertSame('review', $lease->snapshot()['state']);
    }

    public function testSpeakGateReleaseDoesNotRequireTheMemberToStillBeConnected(): void
    {
        $expected = ['allow' => '0', 'deny' => '1'];
        $lease = WaitLease::begin('g', 'u', 'c', 's', 'speak_gate', 1000, false, ['allow' => '1'], $expected);
        self::assertInstanceOf(WaitLease::class, $lease);
        $gate = $this->observation();
        $gate['restriction'] = $expected;
        self::assertTrue($lease->applied(1, $gate));
        $gate['channel_id'] = null;
        $release = $lease->prepareRelease(1300, $gate, true);
        self::assertIsArray($release);
        self::assertSame('speak_gate', $release['mode']);
        self::assertSame(['allow' => '1'], $release['restore']);
    }

    public function testBeginRejectsAnUnknownModeAndABlankIdentity(): void
    {
        try {
            WaitLease::begin('', 'u', 'c', 's', 'server_mute', 1, false, [], []);
            self::fail('blank guild was accepted');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->expectException(InvalidArgumentException::class);
        WaitLease::begin('g', 'u', 'c', 's', 'deaf', 1, false, [], []);
    }

    public function testBeginRejectsAMissingSession(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WaitLease::begin('g', 'u', 'c', '', 'server_mute', 1, false, [], []);
    }

    public function testRecoverRejectsAnIncompleteOrUnsupportedSnapshot(): void
    {
        $saved = $this->lease()->snapshot();
        unset($saved['expected']);
        try {
            WaitLease::recover($saved);
            self::fail('incomplete snapshot was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Incomplete saved lease.', $error->getMessage());
        }
        $saved = $this->lease()->snapshot();
        unset($saved['version']);
        try {
            WaitLease::recover($saved);
            self::fail('snapshot without version was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Incomplete saved lease.', $error->getMessage());
        }
        foreach ([
            'started_at' => '1',
            'expires_at' => '1',
            'guild_id' => 1,
            'user_id' => 1,
            'channel_id' => 1,
            'session_id' => 1,
            'mode' => 1,
            'state' => 1,
            'original' => 'nope',
            'expected' => 'nope',
            'reason' => 1,
        ] as $field => $bad) {
            $saved = $this->lease()->snapshot();
            $saved[$field] = $bad;
            try {
                WaitLease::recover($saved);
                self::fail($field . ' was accepted');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Unsupported saved lease.', $error->getMessage(), $field);
            }
        }
        $saved = $this->lease()->snapshot();
        $saved['version'] = 2;
        $this->expectException(InvalidArgumentException::class);
        WaitLease::recover($saved);
    }

    public function testRecoverRejectsANonIntegerGenerationAndSkipsHoldForAFinishedLease(): void
    {
        $saved = $this->lease()->snapshot();
        $saved['generation'] = '1';
        try {
            WaitLease::recover($saved);
            self::fail('string generation was accepted');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $lease = $this->lease();
        $lease->applied(1, $this->observation());
        $release = $lease->prepareRelease(1300, $this->observation(), true);
        self::assertIsArray($release);
        self::assertTrue($lease->released($release['generation'], true));
        $recovered = WaitLease::recover($lease->snapshot());
        self::assertSame('complete', $recovered->snapshot()['state']);
        self::assertSame(2, $recovered->generation());
    }

    public function testAppliedAndReleasedRejectAMismatchedOperation(): void
    {
        $lease = $this->lease();
        self::assertFalse($lease->applied(2, $this->observation()));
        $changed = $this->observation();
        $changed['channel_id'] = 'other';
        self::assertFalse($lease->applied(1, $changed));
        self::assertSame('review', $lease->snapshot()['state']);
        $otherUser = $this->lease();
        $mismatch = $this->observation();
        $mismatch['user_id'] = 'someone-else';
        self::assertFalse($otherUser->applied(1, $mismatch));
        self::assertSame('review', $otherUser->snapshot()['state']);
        self::assertSame(1, WaitLease::recover($this->lease()->snapshot())->snapshot()['version']);
        self::assertFalse($lease->released(99, true));
    }

    public function testReleaseHoldsOnAnIdentityMismatch(): void
    {
        $lease = $this->lease();
        $lease->applied(1, $this->observation());
        $other = $this->observation();
        $other['user_id'] = 'someone-else';
        self::assertNull($lease->prepareRelease(1300, $other, true));
        self::assertSame('Observation identity mismatch.', $lease->snapshot()['reason']);
    }

    public function testHoldAndExternalRemovalAreNoOpsAfterTheLeaseIsFinished(): void
    {
        $lease = $this->lease();
        $lease->applied(1, $this->observation());
        $release = $lease->prepareRelease(1300, $this->observation(), true);
        self::assertIsArray($release);
        self::assertTrue($lease->released($release['generation'], true));
        $generation = $lease->generation();
        $lease->hold('too late');
        $lease->externalRemovalObserved();
        self::assertSame('complete', $lease->snapshot()['state']);
        self::assertSame($generation, $lease->generation());

        $cancelled = $this->lease();
        $cancelled->externalRemovalObserved();
        $generation = $cancelled->generation();
        $cancelled->hold('too late');
        $cancelled->externalRemovalObserved();
        self::assertSame('cancelled', $cancelled->snapshot()['state']);
        self::assertSame($generation, $cancelled->generation());
    }

    public function testStatusCoversEveryStateIncludingAnElapsedActiveWait(): void
    {
        $lease = $this->lease();
        self::assertSame('Your five-minute wait is being applied.', $lease->status(1000));
        $lease->applied(1, $this->observation());
        self::assertSame('Your wait ends <t:1300:R> (<t:1300:T>).', $lease->status(1200));
        self::assertSame('Your wait has elapsed. Release is still being checked.', $lease->status(1300));
        $release = $lease->prepareRelease(1300, $this->observation(), true);
        self::assertIsArray($release);
        self::assertSame('Your wait has elapsed. Release is being confirmed.', $lease->status(1300));
        self::assertTrue($lease->released($release['generation'], true));
        self::assertSame('The guildmanager\'s wait restriction has been removed. Your own mute or other server restrictions may still apply.', $lease->status(1300));

        $cancelled = $this->lease();
        $cancelled->externalRemovalObserved();
        self::assertSame(2, $cancelled->generation());
        self::assertSame('The automatic wait was overridden. Check your current voice state or ask a moderator.', $cancelled->status(1000));

        $held = $this->lease();
        $held->hold('review');
        self::assertSame('A moderator needs to review this wait before automatic release.', $held->status(1000));
    }

    private function lease(): WaitLease
    {
        $lease = WaitLease::begin('g', 'u', 'c', 's', 'server_mute', 1000, false, ['mute' => false], ['mute' => true]);
        self::assertInstanceOf(WaitLease::class, $lease);

        return $lease;
    }

    /** @return array<string, mixed> */
    private function observation(): array
    {
        return [
            'guild_id' => 'g',
            'user_id' => 'u',
            'channel_id' => 'c',
            'session_id' => 's',
            'restriction' => ['mute' => true],
        ];
    }
}
