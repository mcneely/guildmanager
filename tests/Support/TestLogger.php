<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Log\AbstractLogger;
use Throwable;

final class TestLogger extends AbstractLogger
{
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $e = $context['exception'];
            $message .= ' (' . $e::class . ', code ' . (string) $e->getCode() . ')';
        }

        fwrite(STDERR, gmdate('c') . ' ' . $message . "\n");
    }
}
