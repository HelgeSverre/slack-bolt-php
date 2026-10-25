<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\WebApi;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Generated\WebApi\Models\Requests\ConversationsRepliesRequest;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\MultipartFile;
use Thunder\Bolt\WebApi\RetryPolicy;
use Thunder\Bolt\WebApi\SlackApiException;

final class ConversationReadTest extends TestCase
{
    public static function methods(): iterable
    {
        foreach (['history', 'info', 'list', 'members', 'replies'] as $leaf) {
            yield $leaf => ['conversations.' . $leaf];
        }
    }

    #[DataProvider('methods')]
    public function test_audited_reads_use_get_with_an_empty_body_and_header_credentials(string $method): void
    {
        $http = new RecordingHttpClient();
        $client = new Client($http, 'default-secret', 'https://proxy.example.test/slack/');
        $arguments = [
            'channel' => 'C+A&B',
            'ts' => '1792861144.701709',
            'inclusive' => true,
            'include_all_metadata' => false,
            'limit' => 0,
            'cursor' => 'next+/=& cursor',
            'unused' => null,
            'token' => 'per-call-secret',
        ];
        $client->call($method, $arguments);
        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('proxy.example.test', $request->getUri()->getHost());
        self::assertSame('/slack/' . $method, $request->getUri()->getPath());
        self::assertSame('', (string) $request->getBody());
        self::assertFalse($request->hasHeader('Content-Type'));
        self::assertSame('Bearer per-call-secret', $request->getHeaderLine('Authorization'));
        self::assertSame(
            [
                'channel' => 'C+A&B',
                'ts' => '1792861144.701709',
                'inclusive' => 'true',
                'include_all_metadata' => 'false',
                'limit' => '0',
                'cursor' => 'next+/=& cursor',
            ],
            $http->sentQuery(),
        );
        self::assertStringNotContainsString('secret', (string) $request->getUri());
        self::assertStringContainsString('cursor=next%2B%2F%3D%26%20cursor', $request->getUri()->getQuery());
        self::assertSame('per-call-secret', $arguments['token']);

        $client->call($method);
        self::assertSame('Bearer default-secret', $http->requests[1]->getHeaderLine('Authorization'));
        self::assertSame('', $http->requests[1]->getUri()->getQuery());
    }

    public function test_generated_typed_requests_share_the_same_get_transport(): void
    {
        $http = new RecordingHttpClient();
        new Client($http, 'secret')
            ->conversations()
            ->replies(
                new ConversationsRepliesRequest(
                    'D1',
                    '1792861144.701709',
                    includeAllMetadata: true,
                    inclusive: false,
                    limit: 1,
                ),
            );
        self::assertSame('GET', $http->requests[0]->getMethod());
        self::assertSame('1792861144.701709', $http->sentQuery()['ts']);
        self::assertSame('true', $http->sentQuery()['include_all_metadata']);
        self::assertSame('false', $http->sentQuery()['inclusive']);
    }

    public function test_empty_filtered_pages_still_follow_the_opaque_cursor_and_keep_filters(): void
    {
        $http = new RecordingHttpClient([
            new Response(200, [], '{"ok":true,"channels":[],"response_metadata":{"next_cursor":"opaque+/= cursor"}}'),
            new Response(200, [], '{"ok":true,"channels":[{"id":"C1"}],"response_metadata":{"next_cursor":""}}'),
        ]);
        $pages = new Client($http, 'secret')->paginate('conversations.list', [
            'types' => 'public_channel,private_channel',
            'exclude_archived' => false,
            'limit' => 1,
        ]);
        self::assertSame([], $http->requests);
        self::assertCount(2, iterator_to_array($pages));
        self::assertSame(
            [
                'types' => 'public_channel,private_channel',
                'exclude_archived' => 'false',
                'limit' => '1',
                'cursor' => 'opaque+/= cursor',
            ],
            $http->sentQuery(1),
        );
    }

    public function test_repeated_pagination_cursor_fails_instead_of_looping(): void
    {
        $response = new Response(200, [], '{"ok":true,"messages":[],"response_metadata":{"next_cursor":"same"}}');
        $http = new RecordingHttpClient([$response, $response]);
        try {
            iterator_to_array(new Client($http, 'secret')->paginate('conversations.replies', [
                'channel' => 'D1',
                'ts' => '1.0',
            ]));
            self::fail('Expected a cursor cycle.');
        } catch (SlackApiException $exception) {
            self::assertSame('repeated_pagination_cursor', $exception->error);
            self::assertCount(2, $http->requests);
        }
    }

    public function test_retry_preserves_get_query_and_does_not_move_the_token_into_the_url(): void
    {
        $http = new RecordingHttpClient([new Response(503), new Response(200, [], '{"ok":true}')]);
        new Client($http, 'secret')
            ->retrying(RetryPolicy::transientErrors(jitter: false), static function (): void {})
            ->conversations()
            ->history(['channel' => 'D1', 'oldest' => '1.000001', 'inclusive' => false]);
        self::assertCount(2, $http->requests);
        self::assertSame('GET', $http->requests[1]->getMethod());
        self::assertSame((string) $http->requests[0]->getUri(), (string) $http->requests[1]->getUri());
        self::assertStringNotContainsString('secret', (string) $http->requests[1]->getUri());
        self::assertSame('', (string) $http->requests[1]->getBody());
    }

    public function test_a_resumed_paginator_rejects_its_starting_cursor_before_repeating_the_request(): void
    {
        $http = new RecordingHttpClient([new Response(
            200,
            [],
            '{"ok":true,"channels":[],"response_metadata":{"next_cursor":"resume"}}',
        )]);
        try {
            iterator_to_array(new Client($http, 'secret')->paginate('conversations.list', ['cursor' => 'resume']));
            self::fail('Expected the resumed cursor cycle to stop.');
        } catch (SlackApiException $exception) {
            self::assertSame('repeated_pagination_cursor', $exception->error);
            self::assertCount(1, $http->requests);
        }
    }

    public static function invalidValues(): iterable
    {
        yield 'array' => [['nested']];
        yield 'object' => [new \stdClass()];
        yield 'non-finite number' => [INF];
        yield 'file' => [MultipartFile::bytes('data', 'probe.txt')];
    }

    #[DataProvider('invalidValues')]
    public function test_non_scalar_read_arguments_are_rejected_before_sending(mixed $value): void
    {
        $http = new RecordingHttpClient();
        try {
            new Client($http, 'secret')
                ->conversations()
                ->info(['channel' => $value]);
            self::fail('Expected unsupported query data to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $http->requests);
        }
    }

    public function test_write_methods_and_unknown_methods_keep_json_post_semantics(): void
    {
        foreach (['chat.postMessage', 'views.open', 'conversations.open', 'conversations.futureRead'] as $method) {
            $http = new RecordingHttpClient();
            new Client($http, 'secret')->call($method, ['nested' => ['enabled' => true]]);
            self::assertSame('POST', $http->requests[0]->getMethod());
            self::assertSame('application/json; charset=utf-8', $http->requests[0]->getHeaderLine('Content-Type'));
            self::assertSame(['nested' => ['enabled' => true]], $http->sentPayload());
        }
    }
}
