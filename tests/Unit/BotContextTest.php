<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\BotContext;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\Support\Harness;

#[CoversClass(BotContext::class)]
final class BotContextTest extends GuildTestCase
{
    public function testIdentityEndpointsAndForbiddenMemberWrites(): void
    {
        $harness = $this->harness();
        $harness->context->setIdentity(['user' => ['id' => Harness::BOT]]);
        self::assertSame(Harness::BOT, $harness->context->botId);
        self::assertSame(Harness::BOT, $harness->context->applicationId);
        $harness->context->setIdentity(['user' => ['id' => Harness::BOT], 'application' => ['id' => Harness::APP]]);
        self::assertSame(Harness::APP, $harness->context->applicationId);

        $guild = $harness->guildId;
        $routes = [
            'guilds/' . $guild,
            'guilds/' . $guild . '/roles',
            'guilds/' . $guild . '/channels',
            'guilds/' . $guild . '/members/' . Harness::USER,
            'guilds/' . $guild . '/voice-states/' . Harness::USER,
            'channels/' . Harness::CHANNEL,
            'channels/' . Harness::CHANNEL . '/messages',
            'applications/' . Harness::APP . '/guilds/' . $guild . '/commands',
            'interactions/1/token/callback',
            'webhooks/' . Harness::APP . '/token/messages/@original',
        ];
        foreach ($routes as $route) {
            $endpoint = BotContext::endpoint($route);
            self::assertStringContainsString(explode('/', $route)[0], (string) $endpoint);
        }
        $this->expectException(InvalidArgumentException::class);
        BotContext::endpoint('guilds/' . $guild . '/unknown');
    }

    public function testApiNormalizesRepliesAndRefusesMemberPatches(): void
    {
        $harness = $this->harness();
        $guild = $harness->guildId;
        $harness->http->queue('GET', 'guilds/' . $guild, ['id' => $guild, 'name' => 'Home']);
        $body = null;
        $harness->context->api('get', 'guilds/' . $guild)->then(function (array $data) use (&$body): void {
            $body = $data;
        });
        $harness->flush();
        self::assertSame(['id' => $guild, 'name' => 'Home'], $body);

        $harness->http->queue('POST', 'channels/', ['id' => '1']);
        $posted = null;
        $harness->context->api('post', 'channels/' . Harness::CHANNEL . '/messages', ['content' => 'hi'])
            ->then(function (array $data) use (&$posted): void {
                $posted = $data;
            });
        $harness->flush();
        self::assertSame(['id' => '1'], $posted);
        self::assertSame(['content' => 'hi'], $harness->http->calls[1]['body']);

        self::assertSame([], BotContext::normalize(null));
        self::assertSame([], BotContext::normalize(''));
        self::assertSame(['a' => 1], BotContext::normalize((object) ['a' => 1]));
        try {
            BotContext::normalize('plain');
            self::fail('a scalar body must not normalize to an array');
        } catch (\UnexpectedValueException) {
        }

        try {
            $harness->context->api('patch', 'guilds/' . $guild . '/members/' . Harness::USER, ['mute' => true]);
            self::fail('member patch was accepted');
        } catch (LogicException $error) {
            self::assertStringContainsString('explicit transport', $error->getMessage());
        }
    }

    public function testLoggingAndInteractionHelpers(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $interaction = [
            'id' => '999999999999999999',
            'token' => 'offline-token',
            'application_id' => Harness::APP,
        ];
        $harness->http->queue('POST', 'interactions/', []);
        $harness->http->queue('POST', 'interactions/', []);
        $harness->http->queue('PATCH', 'webhooks/', []);
        $harness->context->reply($interaction, 'hello', [['type' => 1]]);
        $harness->context->defer($interaction);
        $harness->context->editReply($interaction, 'updated');
        $harness->flush();
        $harness->context->log('ready');
        $harness->context->logError('failed', new RuntimeException('secret', 12));
        $stderr = Harness::stderr();
        self::assertStringContainsString('ready', $stderr);
        self::assertStringContainsString('RuntimeException', $stderr);
        self::assertStringContainsString('code 12', $stderr);
        self::assertStringNotContainsString('secret', $stderr);
        self::assertCount(3, $harness->http->calls);
        self::assertSame(4, $harness->http->calls[0]['body']['type']);
        self::assertSame('hello', $harness->http->calls[0]['body']['data']['content']);
        self::assertSame(64, $harness->http->calls[0]['body']['data']['flags']);
        self::assertSame([], $harness->http->calls[0]['body']['data']['allowed_mentions']['parse']);
        self::assertSame([['type' => 1]], $harness->http->calls[0]['body']['data']['components']);
        self::assertSame(5, $harness->http->calls[1]['body']['type']);
        self::assertSame(64, $harness->http->calls[1]['body']['data']['flags']);
        self::assertSame('updated', $harness->http->calls[2]['body']['content']);
        self::assertSame([], $harness->http->calls[2]['body']['allowed_mentions']['parse']);
    }

    public function testIdentityCastsRouteAnchorsAndDecodeDepth(): void
    {
        $harness = $this->harness();
        $harness->context->setIdentity(['user' => ['id' => 123], 'application' => ['id' => 456]]);
        self::assertSame('123', $harness->context->botId);
        self::assertSame('456', $harness->context->applicationId);
        $harness->context->setIdentity(['user' => 'nope', 'application' => ['id' => ['nested']]]);
        self::assertSame('', $harness->context->botId);
        self::assertSame('', $harness->context->applicationId);

        $harness->context->setIdentity(['user' => ['id' => ['array_id' => 1]]]);
        self::assertSame('', $harness->context->botId);
        $harness->context->setIdentity(['user' => ['id' => (object) ['id' => 1]]]);
        self::assertSame('', $harness->context->botId);

        $guild = $harness->guildId;
        $endpoint = (string) BotContext::endpoint('guilds/' . $guild);
        self::assertSame('guilds/' . $guild, $endpoint);
        self::assertStringNotContainsString('guilds/guilds/', $endpoint);

        foreach ([
            'x/guilds/' . $guild . '/members/' . Harness::USER,
            'guilds/' . $guild . '/members/' . Harness::USER . '/extra',
        ] as $route) {
            try {
                $harness->context->api('patch', $route, ['mute' => true]);
            } catch (LogicException $error) {
                self::assertStringNotContainsString('explicit transport', $error->getMessage(), $route);
            } catch (\Throwable) {
            }
        }

        $deep = [];
        $cursor = &$deep;
        for ($i = 0; $i < 510; ++$i) {
            $cursor['n'] = [];
            $cursor = &$cursor['n'];
        }
        $cursor['n'] = 'leaf';
        unset($cursor);
        self::assertSame('leaf', $this->leaf(BotContext::normalize($deep)));

        $tooDeep = [];
        $cursor = &$tooDeep;
        for ($i = 0; $i < 511; ++$i) {
            $cursor['n'] = [];
            $cursor = &$cursor['n'];
        }
        $cursor['n'] = 'leaf';
        unset($cursor);
        try {
            BotContext::normalize($tooDeep);
            self::fail('a value past the decode depth must be rejected');
        } catch (\JsonException) {
        }
    }

    private function leaf(array $value): string
    {
        $cursor = $value;
        while (isset($cursor['n']) && is_array($cursor['n'])) {
            $cursor = $cursor['n'];
        }

        return is_string($cursor['n'] ?? null) ? $cursor['n'] : '';
    }
}
