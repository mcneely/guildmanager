<?php

declare(strict_types=1);

namespace Module\Overflow;

/**
 * Pure decisions for the overflow module. No I/O: the module applies these
 * results, and the offline suite checks them without a Discord connection.
 *
 * A voice channel is full when its user limit is set and occupancy has
 * reached it. Discord rejects every join and member-move into a channel
 * that is already at that limit, so an overflow member can be moved in
 * only once a seat is free.
 */
final class OverflowPolicy
{
    public const string PREFIX = '---';
    public const string SUFFIX = ' Overflow (Join if channel full)';
    public const int MAX_NAME = 100;

    /**
     * Generates the overflow channel name using the provided template.
     * The template must contain the '<channel>' placeholder.
     */
    public static function overflowName(string $sourceName, string $template = '--- <channel> Overflow'): string
    {
        // length is never negative, so === -1 is the same miss as === 0; the later check still returns Overflow.
        /** @infection-ignore-all */
        $empty = self::length($sourceName) === 0;
        if ($empty) {
            // A zero-length name still becomes Overflow on the later length check.
            /** @infection-ignore-all */
            return 'Overflow';
        }

        $parts = explode('<channel>', $template);
        if (count($parts) !== 2) {
            return 'Overflow';
        }
        [$prefix, $suffix] = $parts;

        $room = self::MAX_NAME - self::length($suffix) - self::length($prefix);
        /** @infection-ignore-all */
        $base = self::length($sourceName) <= $room ? $sourceName : self::cut($sourceName, $room);

        if (self::length($base) === 0) {
            return 'Overflow';
        }

        $name = $prefix . $base . $suffix;

        /** @infection-ignore-all */
        return self::length($name) === 0 ? 'Overflow' : $name;
    }

    public static function isFull(int $occupancy, int $userLimit): bool
    {
        return $userLimit > 0 && $occupancy >= $userLimit;
    }

    public static function shouldCreate(int $occupancy, int $userLimit, bool $tracked, bool $isOverflow): bool
    {
        return !$isOverflow && !$tracked && self::isFull($occupancy, $userLimit);
    }

    /** Seats still open. A limit of 0 is not a cap, so it reports none. */
    public static function freeSlots(int $occupancy, int $userLimit): int
    {
        return $userLimit < 1 ? 0 : max(0, $userLimit - $occupancy);
    }

    /**
     * Delete only an overflow this guildmanager created, and only once nobody is
     * waiting in it and the source is under its limit. A move still in
     * flight counts as occupied: deleting the channel would disconnect them.
     */
    public static function shouldDelete(bool $owned, int $occupancy, int $userLimit, int $overflowOccupancy, bool $moveInFlight): bool
    {
        return $owned && !$moveInFlight && $overflowOccupancy === 0 && !self::isFull($occupancy, $userLimit);
    }

    /**
     * First waiter who is still connected to the overflow. Order is the
     * join order the module recorded; missing entries are skipped.
     *
     * @param list<string> $order
     * @param array<string, string> $voices user id => channel id
     * @param string $overflowId
     */
    public static function nextWaiting(array $order, array $voices, string $overflowId): ?string
    {
        foreach ($order as $uid) {
            /** @infection-ignore-all */
            if (($voices[(string) $uid] ?? null) === $overflowId) {
                return (string) $uid;
            }
        }
        return null;
    }

    /**
     * After a crash between "intent saved" and "id saved", adopt the one
     * voice channel that already has the overflow's name under the same
     * parent. Two matches is ambiguous: adopt neither rather than delete
     * the wrong channel later.
     *
     * @param list<array{type?: int|string, name?: mixed, id?: mixed, parent_id?: mixed}> $channels
     * @param string $expectedName
     * @param int|string|null $parentId
     */
    public static function matchPending(array $channels, string $expectedName, int|string|null $parentId): ?string
    {
        $parentId = self::parent($parentId);
        $found = null;
        foreach ($channels as $channel) {
            /** @infection-ignore-all */
            if ((int) ($channel['type'] ?? -1) !== 2) {
                continue;
            }
            if (self::parent($channel['parent_id'] ?? null) !== $parentId) {
                continue;
            }
            $name = $channel['name'] ?? '';
            if (!is_int($name) && !is_string($name)) {
                continue;
            }
            if ((string) $name !== $expectedName) {
                continue;
            }
            if ($found !== null) {
                return null;
            }
            $id = $channel['id'] ?? '';
            if (!is_int($id) && !is_string($id)) {
                continue;
            }
            $found = (string) $id;
        }
        return $found;
    }

    /**
     * Position updates that place the overflow immediately under the source
     * without renumbering channels that already sort above it.
     *
     * Channels that sort after the source have their position incremented so
     * the new channel, given the source's position, sorts between the source
     * and everything that followed it. An overflow that is already the next
     * sibling produces no updates.
     *
     * @param list<array{id: string, position: int}> $siblings same parent, overflow included when it already exists
     * @return list<array{id: string, position: int}>
     */
    public static function positionUpdates(array $siblings, string $sourceId, string $overflowId): array
    {
        $sorted = $siblings;
        usort($sorted, static function (array $a, array $b): int {
            /** @infection-ignore-all */
            $left = (int) $a['position'];
            /** @infection-ignore-all */
            $right = (int) $b['position'];
            $byPosition = $left <=> $right;
            return $byPosition !== 0 ? $byPosition : self::idCmp((string) $a['id'], (string) $b['id']);
        });
        $ids = array_map(static fn(array $channel): string => (string) $channel['id'], $sorted);
        $sourceAt = array_search($sourceId, $ids, true);
        $overflowAt = array_search($overflowId, $ids, true);
        if ($sourceAt !== false && $overflowAt === $sourceAt + 1) {
            return [];
        }
        $current = [];
        foreach ($siblings as $channel) {
            /** @infection-ignore-all */
            $current[(string) $channel['id']] = (int) $channel['position'];
        }
        $sourcePos = null;
        $after = false;
        $next = [];
        foreach ($sorted as $channel) {
            $id = (string) $channel['id'];
            if ($id === $overflowId) {
                continue;
            }
            /** @infection-ignore-all */
            $position = (int) $channel['position'];
            if ($id === $sourceId) {
                $sourcePos = $position;
                $after = true;
                $next[$id] = $position;
                continue;
            }
            $next[$id] = $after ? $position + 1 : $position;
        }
        if ($sourcePos === null) {
            /** @infection-ignore-all */
            return [];
        }
        $next[$overflowId] = $sourcePos;
        $updates = [];
        foreach ($next as $id => $position) {
            if (($current[$id] ?? null) !== $position) {
                $updates[] = ['id' => $id, 'position' => $position];
            }
        }
        return $updates;
    }

    /**
     * Overwrites copied onto the overflow so the same members can see and
     * join it. allow/deny stay strings: API v10 rejects numeric bitfields.
     *
     * @param array{permission_overwrites?: list<array{id?: int|string, type?: int|string, allow?: int|string, deny?: int|string}>} $channel
     * @return list<array{id: string, type: int, allow: string, deny: string}>
     */
    public static function overwrites(array $channel): array
    {
        $out = [];
        foreach ($channel['permission_overwrites'] ?? [] as $overwrite) {
            if (empty($overwrite['id'])) {
                continue;
            }
            $out[] = [
                'id' => (string) $overwrite['id'],
                'type' => (int) ($overwrite['type'] ?? 0),
                'allow' => (string) ($overwrite['allow'] ?? '0'),
                'deny' => (string) ($overwrite['deny'] ?? '0'),
            ];
        }
        return $out;
    }

    /** Numeric comparison for snowflake ids kept as strings. */
    public static function idCmp(string $a, string $b): int
    {
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }
        return $a <=> $b;
    }

    private static function parent(mixed $parentId): ?string
    {
        if (!is_int($parentId) && !is_string($parentId)) {
            return null;
        }
        if ($parentId === '') {
            return null;
        }
        return (string) $parentId;
    }

    private static function length(string $text): int
    {
        $count = preg_match_all('/./us', $text);
        return $count === false ? 0 : $count;
    }

    private static function cut(string $text, int $chars): string
    {
        if ($chars < 1) {
            return '';
        }
        preg_match_all('/./us', $text, $matches);
        return implode('', array_slice($matches[0], 0, $chars));
    }
}
