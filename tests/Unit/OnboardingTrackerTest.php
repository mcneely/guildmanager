<?php

declare(strict_types=1);

namespace Tests\Unit;

use Module\Onboarding\OnboardingTracker;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(OnboardingTracker::class)]
final class OnboardingTrackerTest extends GuildTestCase
{
    public function testTrackerBaselinesATransitionOnceAndResetsOnLeave(): void
    {
        $tracker = new OnboardingTracker();
        self::assertFalse($tracker->updated('u1', false));
        self::assertFalse($tracker->updated('u1', false));
        self::assertFalse($tracker->baselineOf('u1'));
        self::assertFalse($tracker->added('u2', true));
        self::assertFalse($tracker->updated('u2', true));
        self::assertTrue($tracker->updated('u2', false));
        self::assertFalse($tracker->updated('u2', false));
        $tracker->added('u4', true);
        self::assertTrue($tracker->updated('u4', false));
        $tracker->removed('u4');
        self::assertNull($tracker->baselineOf('u4'));
        self::assertFalse($tracker->updated('u4', false));
        self::assertNull($tracker->baselineOf('missing'));
    }

    public function testAddedAndBaseline(): void
    {
        $tracker = new OnboardingTracker();
        $tracker->added('u1', true);
        self::assertTrue($tracker->baselineOf('u1'));
        $tracker->added('u1', false);
        self::assertFalse($tracker->baselineOf('u1'));
    }

    public function testUpdatedReturnsTrueOnlyOnFirstTransition(): void
    {
        $tracker = new OnboardingTracker();

        // No baseline
        self::assertFalse($tracker->updated('u1', false));

        // Set baseline
        $tracker->added('u1', true);

        // Transition true -> false
        self::assertTrue($tracker->updated('u1', false));

        // Subsequent updates (already false)
        self::assertFalse($tracker->updated('u1', false));

        // Transition false -> true
        $tracker->added('u1', false);
        self::assertFalse($tracker->updated('u1', true));
    }
}
