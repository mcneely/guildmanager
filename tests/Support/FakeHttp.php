<?php

declare(strict_types=1);

namespace Tests\Support;

use Discord\Http\Http;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

use function React\Promise\reject;
use function React\Promise\resolve;

/** Scripted Discord HTTP client. Unmatched calls reject so a test cannot hang. */
final class FakeHttp extends Http
{
    /** @var list<array{method: string, url: string, body: mixed}> */
    public array $calls = [];

    /** @var array<string, list<mixed>> */
    public array $queues = [];

    /** @var list<array{method: string, contains: string, result: mixed}> */
    private array $queueItems = [];

    public function __construct(LoopInterface $loop)
    {
        parent::__construct('Bot test', $loop, new NullLogger(), null);
    }

    public function queue(string $method, string $contains, mixed $result): void
    {
        $key = strtoupper($method) . ' ' . $contains;
        $this->queues[$key][] = $result;
        $this->queueItems[] = [
            'method' => strtoupper($method),
            'contains' => $contains,
            'result' => $result,
        ];
    }

    public function get($url, $content = null, array $headers = []): PromiseInterface
    {
        return $this->dispatch('GET', $url, $content);
    }

    public function post($url, $content = null, array $headers = []): PromiseInterface
    {
        return $this->dispatch('POST', $url, $content);
    }

    public function put($url, $content = null, array $headers = []): PromiseInterface
    {
        return $this->dispatch('PUT', $url, $content);
    }

    public function patch($url, $content = null, array $headers = []): PromiseInterface
    {
        return $this->dispatch('PATCH', $url, $content);
    }

    public function delete($url, $content = null, array $headers = []): PromiseInterface
    {
        return $this->dispatch('DELETE', $url, $content);
    }

    private function dispatch(string $method, mixed $url, mixed $body): PromiseInterface
    {
        $path = (string) $url;
        $this->calls[] = ['method' => $method, 'url' => $path, 'body' => $body];

        $bestIndex = null;
        $maxLen = -1;

        foreach ($this->queueItems as $index => $item) {
            if (strcasecmp($item['method'], $method) === 0 && str_contains($path, $item['contains'])) {
                $len = strlen($item['contains']);
                if ($len > $maxLen) {
                    $maxLen = $len;
                    $bestIndex = $index;
                }
            }
        }

        if ($bestIndex !== null) {
            $result = $this->queueItems[$bestIndex]['result'];
            unset($this->queueItems[$bestIndex]);
            // Re-index to maintain list integrity for iteration
            $this->queueItems = array_values($this->queueItems);
            return $this->settle($result, $path, $body);
        }

        return reject(new RuntimeException('Unscripted ' . $method . ' ' . $path));
    }

    private function settle(mixed $result, string $path, mixed $body): PromiseInterface
    {
        if ($result instanceof Throwable) {
            return reject($result);
        }
        if (is_callable($result)) {
            $result = $result($path, $body);
            if ($result instanceof PromiseInterface) {
                return $result;
            }
            if ($result instanceof Throwable) {
                return reject($result);
            }
        }

        return resolve($result);
    }
}
