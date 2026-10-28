<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Middleware\MessageFilter;
use Thunder\Bolt\Payloads\Events\Message;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class MessageFilterTest extends TestCase
{
    public static function mentions(): iterable
    {
        foreach ([
            ['<@U-bot> hello',                         true],
            [" \t<@U-bot|Thunder>hello",               true],
            ["\u{FEFF}\u{00A0}\u{2003}<@U-bot> hello", true],
            ['<@U-bot>',                               true],
            ['<@U-bot|>',                              false],
            ['hello <@U-bot>',                         false],
            ['<@U-other> <@U-bot>',                    false],
            ['<!here> <@U-bot>',                       false],
            ['<#U-bot>',                               false],
            ['<U-bot>',                                false],
            ['<https://example.test|U-bot>',           false],
            ['<@U-bot-extra>',                         false],
            ["\u{0085}<@U-bot>",                       false],
            ['',                                       false],
        ] as $index => $case) {
            yield 'mention ' . $index => $case;
        }
    }

    #[DataProvider('mentions')]
    public function test_direct_mentions_follow_bolt_parsing_and_preserve_the_message(
        string $text,
        bool $expected,
    ): void {
        $app = $this->app();
        $seen = [];
        $app->message('', MessageFilter::directMention(), static function (Message $message) use (&$seen): void {
            $seen[] = $message->text;
        });
        $app->dispatch(self::body($text));
        self::assertSame($expected ? [$text] : [], $seen);
    }

    public function test_filters_compose_and_stop_only_their_listener(): void
    {
        $app = $this->app();
        $seen = [];
        $app->message('', MessageFilter::subtype('file_share'), MessageFilter::directMention(), static function () use (
            &$seen,
        ): void {
            $seen[] = 'filtered';
        });
        $app->event('message', static function () use (&$seen): void {
            $seen[] = 'ordinary';
        });
        $app->dispatch(self::body('<@U-bot> hello'));
        $body = self::body('<@U-bot> shared');
        $body['event']['subtype'] = 'file_share';
        $app->dispatch($body);
        self::assertSame(['ordinary', 'filtered', 'ordinary'], $seen);
    }

    public function test_subtype_matches_raw_changed_events_and_rejects_non_messages(): void
    {
        $app = $this->app();
        $seen = 0;
        $app->use(MessageFilter::subtype('message_changed'));
        $app->event('message', static function () use (&$seen): void {
            $seen++;
        });
        $app->event('reaction_added', static function (): never {
            self::fail('Non-message passed filter.');
        });
        $body = self::body('');
        $body['event'] = [
            'type' => 'message',
            'subtype' => 'message_changed',
            'channel' => 'C1',
            'message' => ['text' => 'updated', 'user' => 'U1'],
        ];
        $app->dispatch($body);
        $body['event'] = [
            'type' => 'reaction_added',
            'user' => 'U1',
            'reaction' => 'eyes',
            'event_ts' => '1.000002',
            'item' => ['type' => 'message', 'channel' => 'C1', 'ts' => '1.000001'],
        ];
        $app->dispatch($body);
        self::assertSame(1, $seen);
    }

    public function test_mention_identity_is_resolved_per_installation_without_network_lookup(): void
    {
        $http = new RecordingHttpClient();
        $app = $this->app($http)->authorizeUsing(static fn($source) => new Authorization(
            botToken: 'fixture',
            botUserId: $source->teamId === 'T1' ? 'U-bot' : 'U-other',
            teamId: $source->teamId,
        ));
        $seen = 0;
        $app->message('', MessageFilter::directMention(), static function () use (&$seen): void {
            $seen++;
        });
        $app->dispatch(self::body('<@U-bot> hello'));
        $other = self::body('<@U-bot> hello');
        $other['team_id'] = 'T2';
        $app->dispatch($other);
        self::assertSame(1, $seen);
        self::assertSame([], $http->requests);
    }

    public function test_missing_bot_identity_is_a_clear_error_not_a_failed_match(): void
    {
        $app = new App(
            new GenericContainer(),
            new Client(new RecordingHttpClient(), 'fixture'),
            new BoltConfig(token: 'fixture'),
        );
        $app->message('', MessageFilter::directMention(), static fn() => null);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('bot user ID');
        $app->dispatch(self::body('<@U-bot> hello'));
    }

    private function app(?RecordingHttpClient $http = null): App
    {
        return new App(
            new GenericContainer(),
            new Client($http ?? new RecordingHttpClient(), 'fixture'),
            new BoltConfig(token: 'fixture', botUserId: 'U-bot'),
        );
    }

    private static function body(string $text): array
    {
        return [
            'type' => 'event_callback',
            'team_id' => 'T1',
            'event' => ['type' => 'message', 'user' => 'U1', 'channel' => 'C1', 'text' => $text, 'ts' => '1.000001'],
        ];
    }
}
