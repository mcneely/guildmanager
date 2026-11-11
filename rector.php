<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php83\Rector\Class_\ReadOnlyAnonymousClassRector;
use Rector\Php85\Rector\ArrayDimFetch\ArrayFirstLastRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/bin',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets(
        php85: true,
    )
    ->withComposerBased(symfony: true)
    ->withSkip([
        ArrayFirstLastRector::class,
    ])
    ->withCache(__DIR__ . '/var/rector-cache');
