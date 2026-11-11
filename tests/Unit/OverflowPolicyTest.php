<?php

declare(strict_types=1);

namespace Tests\Unit;

use Module\Overflow\OverflowPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(OverflowPolicy::class)]
final class OverflowPolicyTest extends TestCase
{
    public function testNamingTruncationAndAnUnreadableName(): void
    {
        self::assertSame('--- Raid Overflow', OverflowPolicy::overflowName('Raid'));
        $named = OverflowPolicy::overflowName(str_repeat('A', 100));
        self::assertSame(100, strlen($named));
        self::assertStringEndsWith(' Overflow', $named);
        $cut = OverflowPolicy::overflowName(str_repeat('é', 95));
        self::assertSame(100, preg_match_all('/./us', $cut));
        self::assertStringEndsWith(' Overflow', $cut);
        self::assertSame('Overflow', OverflowPolicy::overflowName("\xFF"));
        self::assertSame('Overflow', OverflowPolicy::overflowName('Raid', 'no placeholder'));
        self::assertSame('Overflow', OverflowPolicy::overflowName('Raid', '<channel>x<channel>'));
        self::assertSame('Overflow', OverflowPolicy::overflowName('Raid', str_repeat('P', 90) . '<channel>' . str_repeat('S', 20)));
        $cutEmpty = new ReflectionMethod(OverflowPolicy::class, 'cut');
        self::assertSame('', $cutEmpty->invoke(null, 'Raid', 0));
        self::assertSame('', $cutEmpty->invoke(null, 'Raid', -1));
        self::assertSame('R', new ReflectionMethod(OverflowPolicy::class, 'cut')->invoke(null, 'Raid', 1));
        $length = new ReflectionMethod(OverflowPolicy::class, 'length');
        self::assertSame(0, $length->invoke(null, "\xFF"));
        self::assertSame(4, $length->invoke(null, 'Raid'));
        self::assertSame('--- ' . str_repeat('C', 87) . ' Overflow', OverflowPolicy::overflowName(str_repeat('C', 87)));
        self::assertSame('9', OverflowPolicy::nextWaiting([9], ['9' => 'ov'], 'ov'));
        self::assertSame([['id' => '7', 'type' => 3, 'allow' => '0', 'deny' => '4']], OverflowPolicy::overwrites([
            'permission_overwrites' => [['id' => '7', 'type' => '3', 'deny' => 4]],
        ]));
        self::assertSame(-1, OverflowPolicy::idCmp('2', '10'));
        self::assertSame(1, OverflowPolicy::idCmp('01', '1'));
        $byId = [];
        foreach (OverflowPolicy::positionUpdates([
            ['id' => '20', 'position' => 1],
            ['id' => '10', 'position' => 2],
        ], '20', '99') as $update) {
            $byId[$update['id']] = $update['position'];
        }
        self::assertSame(3, $byId['10']);
        self::assertSame(1, $byId['99']);
        $byInt = [];
        foreach (OverflowPolicy::positionUpdates([
            ['id' => 20, 'position' => 1],
            ['id' => 10, 'position' => 2],
        ], '20', '99') as $update) {
            $byInt[$update['id']] = $update['position'];
        }
        self::assertSame(3, $byInt['10']);
        self::assertSame(1, $byInt['99']);
    }

    public function testFullSeatsCreateAndDelete(): void
    {
        self::assertTrue(OverflowPolicy::isFull(4, 4));
        self::assertFalse(OverflowPolicy::isFull(3, 4));
        self::assertFalse(OverflowPolicy::isFull(10, 0));
        self::assertFalse(OverflowPolicy::isFull(1, -1));
        self::assertSame(2, OverflowPolicy::freeSlots(2, 4));
        self::assertSame(0, OverflowPolicy::freeSlots(4, 4));
        self::assertSame(0, OverflowPolicy::freeSlots(1, 0));
        self::assertSame(1, OverflowPolicy::freeSlots(0, 1));
        self::assertSame(0, OverflowPolicy::freeSlots(9, 4));
        self::assertTrue(OverflowPolicy::shouldCreate(4, 4, false, false));
        self::assertFalse(OverflowPolicy::shouldCreate(4, 4, true, false));
        self::assertFalse(OverflowPolicy::shouldCreate(4, 4, false, true));
        self::assertFalse(OverflowPolicy::shouldCreate(3, 4, false, false));
        self::assertFalse(OverflowPolicy::shouldCreate(4, 0, false, false));
        self::assertTrue(OverflowPolicy::shouldDelete(true, 3, 4, 0, false));
        self::assertFalse(OverflowPolicy::shouldDelete(true, 4, 4, 0, false));
        self::assertFalse(OverflowPolicy::shouldDelete(true, 2, 4, 1, false));
        self::assertFalse(OverflowPolicy::shouldDelete(true, 2, 4, 0, true));
        self::assertFalse(OverflowPolicy::shouldDelete(false, 0, 4, 0, false));
        self::assertTrue(OverflowPolicy::shouldDelete(true, 0, 0, 0, false));
    }

    public function testWaitingOrderAdoptionAndPlacement(): void
    {
        self::assertSame('b', OverflowPolicy::nextWaiting(['a', 'b'], ['b' => 'ov'], 'ov'));
        self::assertNull(OverflowPolicy::nextWaiting(['a'], ['a' => 'elsewhere'], 'ov'));
        self::assertNull(OverflowPolicy::nextWaiting([], [], 'ov'));
        $channels = [
            ['id' => '10', 'type' => 2, 'name' => 'Raid Overflow', 'parent_id' => '9'],
            ['id' => '11', 'type' => 0, 'name' => 'Raid Overflow', 'parent_id' => '9'],
            ['id' => '12', 'type' => 2, 'name' => 'Raid Overflow', 'parent_id' => null],
            ['type' => 2, 'parent_id' => '9'],
            ['id' => '14', 'type' => 2, 'name' => 'Other', 'parent_id' => ''],
        ];
        self::assertSame('10', OverflowPolicy::matchPending($channels, 'Raid Overflow', '9'));
        self::assertSame('10', OverflowPolicy::matchPending([
            ['id' => '1', 'type' => 0, 'name' => 'Raid Overflow', 'parent_id' => '9'],
            ['id' => '10', 'type' => 2, 'name' => 'Raid Overflow', 'parent_id' => '9'],
        ], 'Raid Overflow', '9'));
        self::assertSame('10', OverflowPolicy::matchPending([
            ['id' => ['bad'], 'type' => 2, 'name' => 'Raid Overflow', 'parent_id' => '9'],
            ['id' => '10', 'type' => 2, 'name' => 'Raid Overflow', 'parent_id' => '9'],
        ], 'Raid Overflow', '9'));
        self::assertSame('10', OverflowPolicy::matchPending([
            ['type' => 2, 'parent_id' => '9', 'name' => ['nope'], 'id' => '1'],
            ['type' => 2, 'parent_id' => '9', 'name' => 'Raid Overflow', 'id' => '10'],
        ], 'Raid Overflow', '9'));
        self::assertSame('8', OverflowPolicy::matchPending([
            ['id' => 8, 'type' => 2, 'name' => 10, 'parent_id' => 9],
        ], '10', 9));
        self::assertNull(OverflowPolicy::matchPending([
            ['type' => 2, 'parent_id' => '9', 'name' => ['nope'], 'id' => '1'],
            ['type' => 2, 'parent_id' => '9', 'name' => 'Raid Overflow', 'id' => ['nope']],
        ], 'Raid Overflow', '9'));
        self::assertSame('12', OverflowPolicy::matchPending($channels, 'Raid Overflow', null));
        self::assertSame('12', OverflowPolicy::matchPending($channels, 'Raid Overflow', ''));
        $channels[] = ['id' => '13', 'type' => 2, 'name' => 'Raid Overflow', 'parent_id' => '9'];
        self::assertNull(OverflowPolicy::matchPending($channels, 'Raid Overflow', '9'));

        $siblings = [
            ['id' => '100', 'position' => 0],
            ['id' => '200', 'position' => 1],
            ['id' => '300', 'position' => 1],
            ['id' => '400', 'position' => 5],
        ];
        $byId = [];
        foreach (OverflowPolicy::positionUpdates($siblings, '200', '999') as $update) {
            $byId[$update['id']] = $update['position'];
        }
        self::assertArrayNotHasKey('100', $byId);
        self::assertArrayNotHasKey('200', $byId);
        self::assertSame(1, $byId['999']);
        self::assertSame(2, $byId['300']);
        self::assertSame(6, $byId['400']);
        $placed = $siblings;
        $placed[] = ['id' => '999', 'position' => 1];
        $placed[2]['position'] = 2;
        $placed[3]['position'] = 6;
        self::assertSame([], OverflowPolicy::positionUpdates($placed, '200', '999'));
        self::assertSame([], OverflowPolicy::positionUpdates($siblings, 'missing', '999'));
        $misplaced = [
            ['id' => '100', 'position' => 0],
            ['id' => '999', 'position' => 1],
            ['id' => '200', 'position' => 2],
        ];
        $shifted = [];
        foreach (OverflowPolicy::positionUpdates($misplaced, '200', '999') as $update) {
            $shifted[$update['id']] = $update['position'];
        }
        self::assertSame(2, $shifted['999']);
        self::assertArrayNotHasKey('200', $shifted);
        self::assertSame(1, OverflowPolicy::idCmp('10', '2'));
        self::assertSame(0, OverflowPolicy::idCmp('10', '10'));
        self::assertSame(-1, OverflowPolicy::idCmp('10', '11'));
    }

    public function testOverwritesStringifyAndSkipABlankId(): void
    {
        self::assertSame([
            ['id' => '5', 'type' => 0, 'allow' => '1024', 'deny' => '0'],
            ['id' => '6', 'type' => 0, 'allow' => '0', 'deny' => '0'],
        ], OverflowPolicy::overwrites([
            'permission_overwrites' => [
                ['id' => 5, 'type' => 0, 'allow' => 1024, 'deny' => 0],
                ['id' => '', 'type' => 0, 'allow' => 1, 'deny' => 1],
                ['id' => '6'],
            ],
        ]));
        self::assertSame([], OverflowPolicy::overwrites([]));
    }
}
