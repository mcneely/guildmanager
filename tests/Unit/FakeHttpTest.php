<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeHttp;
use Tests\Support\TestLoop;

#[CoversNothing]
final class FakeHttpTest extends TestCase
{
    public function testQueueAndDispatch(): void
    {
        $loop = new TestLoop();
        $http = new FakeHttp($loop);

        $url = 'guilds/123';
        $data = ['id' => 123];

        $http->queue('GET', $url, $data);

        $promise = $http->get($url);

        $resolved = false;
        $promise->then(function ($result) use (&$resolved, $data) {
            if ($result === $data) {
                $resolved = true;
            }
        });

        $loop->run();

        $this->assertTrue($resolved, 'The promise should have resolved with the queued data.');
    }

    public function testUnscriptedReturnsError(): void
    {
        $loop = new TestLoop();
        $http = new FakeHttp($loop);

        $promise = $http->get('unknown/url');

        $errorCaught = false;
        $promise->then(null, function (\Throwable $e) use (&$errorCaught) {
            if ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'Unscripted GET unknown/url')) {
                $errorCaught = true;
            }
        });

        $loop->run();

        $this->assertTrue($errorCaught, 'An unscripted request should result in a RuntimeException.');
    }
}
