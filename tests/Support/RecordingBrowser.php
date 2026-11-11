<?php

declare(strict_types=1);

namespace Tests\Support;

use React\Http\Browser;
use React\Promise\PromiseInterface;
use RuntimeException;

use function React\Promise\reject;

/** Records Browser configuration and the one request the default sender builds. */
final class RecordingBrowser extends Browser
{
    /** @var array<string, mixed> */
    public array $options = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $calls = [];

    public function withTimeout($timeout): static
    {
        $this->options['timeout'] = $timeout;

        return $this;
    }

    public function withFollowRedirects($followRedirects): static
    {
        $this->options['followRedirects'] = $followRedirects;

        return $this;
    }

    public function withRejectErrorResponse($obeySuccessCode): static
    {
        $this->options['rejectErrorResponse'] = $obeySuccessCode;

        return $this;
    }

    public function withResponseBuffer($maximumSize): static
    {
        $this->options['responseBuffer'] = $maximumSize;

        return $this;
    }

    public function patch($url, array $headers = [], $body = ''): PromiseInterface
    {
        $this->calls[] = [
            'method' => 'patch',
            'url' => (string) $url,
            'headers' => $headers,
            'body' => (string) $body,
        ];

        return reject(new RuntimeException('offline'));
    }
}
