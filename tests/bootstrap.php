<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Support/clock-functions.php';

// Parallel runners (Infection) must not share the per-database advisory lock.
putenv('STATE_LOCK_KEY=' . getmypid());

if (!is_string(getenv('DATABASE_URL')) || getenv('DATABASE_URL') === '') {
    $fromDocker = shell_exec('docker exec guildManager printenv DATABASE_URL 2>/dev/null');
    if (is_string($fromDocker) && str_contains($fromDocker, '://')) {
        $url = trim($fromDocker);
        putenv('DATABASE_URL=' . $url);
        $_ENV['DATABASE_URL'] = $url;
        $_SERVER['DATABASE_URL'] = $url;
    }
}
