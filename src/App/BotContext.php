<?php

declare(strict_types=1);

namespace App;

use Discord\Http\Endpoint;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LogLevel;
use React\Promise\PromiseInterface;
use Throwable;
use Psr\Log\LoggerInterface;

/**
 * Shared services available to every module: the Gateway client, durable
 * state, guild/guildmanager identity, rate-limit-aware HTTP, and logging.
 *
 * api() must never perform member PATCHes: writes that change a member's
 * state go through the owning module's own explicit transport, so the
 * caller's invariants (guards, verification, no blind retries) stay visible
 * at the call site instead of hiding in a generic helper.
 */
final class BotContext
{
    public string $botId = '';
    public string $applicationId = '';

    public function __construct(
        public ObservedDiscord $discord,
        public StateStore $store,
        public string $guildId,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Host calls this when it observes a READY packet.
     *
     * @param array<array-key, mixed> $data
     */
    public function setIdentity(array $data): void
    {
        $user = $data['user'] ?? null;
        $application = $data['application'] ?? null;
        $userId = is_array($user) ? ($user['id'] ?? '') : '';
        $this->botId = is_int($userId) || is_string($userId) ? (string) $userId : '';
        $applicationId = is_array($application) ? ($application['id'] ?? $this->botId) : $this->botId;
        $this->applicationId = is_int($applicationId) || is_string($applicationId) ? (string) $applicationId : $this->botId;
    }

    /**
     * Rate-limit-aware request. Resolves to the decoded JSON body as an array.
     *
     * @param array<array-key, mixed>|null $body
     * @return PromiseInterface<array<array-key, mixed>>
     * @throws LogicException Member PATCHes are forbidden here by design.
     */
    public function api(string $method, string $route, ?array $body = null): PromiseInterface
    {
        if ($method === 'patch' && preg_match('~^guilds/\d+/members/\d+$~', $route)) {
            throw new LogicException('Member writes must go through a module\'s explicit transport.');
        }
        $http = $this->discord->getHttpClient();
        $endpoint = self::endpoint($route);
        /** @var PromiseInterface<mixed> $promise */
        $promise = $body === null ? $http->{$method}($endpoint) : $http->{$method}($endpoint, $body);
        return $promise->then(static fn(mixed $data): array => self::normalize($data));
    }

    public static function endpoint(string $route): Endpoint
    {
        // Bind variables so DiscordPHP uses the proper rate-limit buckets.
        $routes = [
            '~^guilds/(\d+)$~' => 'guilds/:guild_id',
            '~^guilds/(\d+)/roles$~' => 'guilds/:guild_id/roles',
            '~^guilds/(\d+)/channels$~' => 'guilds/:guild_id/channels',
            '~^guilds/(\d+)/members/(\d+)$~' => 'guilds/:guild_id/members/:user_id',
            '~^guilds/(\d+)/voice-states/(\d+)$~' => 'guilds/:guild_id/voice-states/:user_id',
            '~^channels/(\d+)$~' => 'channels/:channel_id',
            '~^channels/(\d+)/messages$~' => 'channels/:channel_id/messages',
            '~^applications/(\d+)/guilds/(\d+)/commands$~' => 'applications/:application_id/guilds/:guild_id/commands',
            '~^interactions/(\d+)/([^/]+)/callback$~' => 'interactions/:interaction_id/:interaction_token/callback',
            '~^webhooks/(\d+)/([^/]+)/messages/@original$~' => 'webhooks/:webhook_id/:webhook_token/messages/@original',
        ];
        foreach ($routes as $pattern => $template) {
            if (preg_match($pattern, $route, $matches)) {
                array_shift($matches);
                return Endpoint::bind($template, ...$matches);
            }
        }
        throw new InvalidArgumentException('Unsupported API route.');
    }

    /** @return array<array-key, mixed> */
    public static function normalize(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('API body was not a JSON object or array.');
        }
        return $decoded;
    }

    public function log(string $text): void
    {
        $this->logger->log(LogLevel::INFO, $text);
    }

    public function logError(string $message, Throwable $error): void
    {
        /** @infection-ignore-all */
        $this->logger->error($message, ['exception' => $error]);
    }

    /**
     * Ephemeral immediate reply to an interaction.
     *
     * @param array{id: int|string, token: string} $interaction
     * @param list<array<string, mixed>> $components
     * @return PromiseInterface<array<array-key, mixed>>
     */
    public function reply(array $interaction, string $text, array $components = []): PromiseInterface
    {
        return $this->api('post', 'interactions/' . $interaction['id'] . '/' . $interaction['token'] . '/callback', [
            'type' => 4, 'data' => [
                'content' => $text, 'flags' => 64,
                'allowed_mentions' => ['parse' => []],
                'components' => $components,
            ],
        ]);
    }

    /**
     * Ephemeral defer (acknowledgement) for an interaction.
     *
     * @param array{id: int|string, token: string} $interaction
     * @return PromiseInterface<array<array-key, mixed>>
     */
    public function defer(array $interaction): PromiseInterface
    {
        return $this->api('post', 'interactions/' . $interaction['id'] . '/' . $interaction['token'] . '/callback', [
            'type' => 5, 'data' => ['flags' => 64],
        ]);
    }

    /**
     * Edit the interaction's original ephemeral message.
     *
     * @param array{application_id: int|string, token: string} $interaction
     * @param list<array<string, mixed>> $components
     * @return PromiseInterface<array<array-key, mixed>>
     */
    public function editReply(array $interaction, string $text, array $components = []): PromiseInterface
    {
        return $this->api('patch', 'webhooks/' . $interaction['application_id'] . '/' . $interaction['token'] . '/messages/@original', [
            'content' => $text, 'allowed_mentions' => ['parse' => []],
            'components' => $components,
        ]);
    }
}
