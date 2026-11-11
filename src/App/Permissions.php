<?php

declare(strict_types=1);

namespace App;

final class Permissions
{
    public const ADMIN = 1 << 3;
    public const MANAGE_CHANNELS = 1 << 4;
    public const MANAGE_GUILD = 1 << 5;
    public const VIEW = 1 << 10;
    public const SEND = 1 << 11;
    public const CONNECT = 1 << 20;
    public const MUTE = 1 << 22;
    public const MOVE_MEMBERS = 1 << 24;
    public const MANAGE_ROLES = 1 << 28;

    public static function has(int $permissions, int $required): bool
    {
        return ($permissions & self::ADMIN) !== 0 || ($permissions & $required) === $required;
    }

    /**
     * @param array{id: int|string, owner_id: int|string} $guild
     * @param list<array{id: int|string, permissions: int|string}> $roles
     * @param array{user?: array{id?: int|string}, roles?: list<int|string>} $member
     */
    public static function base(array $guild, array $roles, array $member): int
    {
        $uid = (string) ($member['user']['id'] ?? '');
        if ($uid === (string) $guild['owner_id']) {
            return PHP_INT_MAX;
        }
        $ids = array_map(strval(...), $member['roles'] ?? []);
        $permissions = 0;
        foreach ($roles as $role) {
            if ((string) $role['id'] === (string) $guild['id']
                || in_array((string) $role['id'], $ids, true)) {
                $permissions |= (int) $role['permissions'];
            }
        }
        if (($permissions & self::ADMIN) !== 0) {
            return PHP_INT_MAX;
        }
        return $permissions;
    }

    /**
     * 64-bit PHP only; IDs stay strings. Discord's documented overwrite order.
     *
     * @param array{id: int|string, owner_id: int|string} $guild
     * @param list<array{id: int|string, permissions: int|string}> $roles
     * @param array{user?: array{id?: int|string}, roles?: list<int|string>} $member
     * @param array{permission_overwrites?: list<array{id: int|string, type: int|string, allow: int|string, deny: int|string}>} $channel
     */
    public static function effective(array $guild, array $roles, array $member, array $channel): int
    {
        $permissions = self::base($guild, $roles, $member);
        if (($permissions & self::ADMIN) !== 0) {
            return PHP_INT_MAX;
        }
        $uid = (string) ($member['user']['id'] ?? '');
        $ids = array_map(strval(...), $member['roles'] ?? []);
        $overwrites = $channel['permission_overwrites'] ?? [];

        // 1. Guild-wide overwrites
        foreach ($overwrites as $overwrite) {
            if ((string) $overwrite['id'] === (string) $guild['id']) {
                // PHP 8 bitwise operators numeric-cast the string, so the explicit cast does not change the bits.
                /** @infection-ignore-all */
                $guildAllow = (int) $overwrite['allow'];
                $permissions = ($permissions & ~(int) $overwrite['deny']) | $guildAllow;
            }
        }

        // 2. Role-based overwrites
        $allow = $deny = 0;
        foreach ($overwrites as $overwrite) {
            if ((int) $overwrite['type'] === 0 && in_array((string) $overwrite['id'], $ids, true)) {
                // PHP 8 bitwise operators numeric-cast the string, so the explicit cast does not change the bits.
                /** @infection-ignore-all */
                $roleAllow = (int) $overwrite['allow'];
                /** @infection-ignore-all */
                $roleDeny = (int) $overwrite['deny'];
                $allow |= $roleAllow;
                $deny |= $roleDeny;
            }
        }
        $permissions = ($permissions & ~$deny) | $allow;

        // 3. Member-specific overwrites
        foreach ($overwrites as $overwrite) {
            if ((int) $overwrite['type'] === 1 && (string) $overwrite['id'] === $uid) {
                $permissions = ($permissions & ~(int) $overwrite['deny']) | (int) $overwrite['allow'];
            }
        }

        return $permissions;
    }
}
