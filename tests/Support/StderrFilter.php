<?php

declare(strict_types=1);

namespace Tests\Support;

use php_user_filter;

/** Captures STDERR so tests can assert log lines without printing them. */
final class StderrFilter extends php_user_filter
{
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            Harness::appendStderr($bucket->data);
            $consumed += $bucket->datalen;
        }

        return PSFS_PASS_ON;
    }
}
