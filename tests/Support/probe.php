<?php

declare(strict_types=1);

namespace Tests\Support;

require_once '/App/vendor/autoload.php';
require_once '/App/tests/Support/Database.php';
echo 'URL: ' . Database::url() . PHP_EOL;
echo 'Side: ' . Database::side() . PHP_EOL;
