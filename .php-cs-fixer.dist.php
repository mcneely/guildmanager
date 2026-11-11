<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRules([
        '@PER-CS' => true,
        'declare_strict_types' => true,
    ])
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/var/php-cs-fixer.cache')
    ->setFinder(
        (new Finder())
            ->in([
                __DIR__.'/bin',
                __DIR__.'/src',
                __DIR__.'/tests'
            ])
    );
