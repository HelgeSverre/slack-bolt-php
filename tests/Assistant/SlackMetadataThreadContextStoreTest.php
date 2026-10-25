<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Assistant;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Assistant\Stores\SlackMetadataThreadContextStore;
use Thunder\Bolt\Assistant\ThreadContext;
use Thunder\Bolt\Assistant\ThreadIdentity;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class SlackMetadataThreadContextStoreTest extends TestCase
{
    public function test_paginated_context_update_and_fresh_read_keep_the_trusted_identity_and_message_content(): void
    {
        $first = $this->page([['user' => 'U-other', 'ts' => '100.001', 'text' => 'Foreign root']], 'opaque+/= cursor');
        $message = [
            'user' => 'U-bot',
            'ts' => '100.002',
            'thread_ts' => '100.001',
            'text' => 'Keep this text',
            'blocks' => [['type' => 'section', 'text' => ['type' => 'plain_text', 'text' => 'Keep this block']]],
            'metadata' => ['event_type' => 'assistant_thread_context', 'event_payload' => ['case' => 'old']],
        ];
        $last = $this->page([$message]);
        $updated = [
            ...$message,
            'metadata' => ['event_type' => 'assistant_thread_context', 'event_payload' => ['case' => 'new']],
        ];
        $http = new RecordingHttpClient([
            $first,
            $last,
            $first,
            $last,
            new Response(200, [], '{"ok":true}'),
            $first,
            $this->page([$updated]),
        ]);
        $client = new Client($http, 'secret');
        $identity = $this->identity();
        $store = new SlackMetadataThreadContextStore();

        self::assertSame('old', $store->get($identity, $client)?->toArray()['case']);
        $store->save($identity, new ThreadContext(extra: ['case' => 'new']), $client);
        self::assertSame(
            'new',
            new SlackMetadataThreadContextStore()->get($identity, $client->withToken('secret'))?->toArray()['case'],
        );
        self::assertCount(7, $http->requests);
        foreach ([0, 1, 2, 3, 5, 6] as $index) {
            $request = $http->requests[$index];
            self::assertSame('GET', $request->getMethod());
            self::assertSame('Bearer secret', $request->getHeaderLine('Authorization'));
            self::assertSame('D1', $http->sentQuery($index)['channel']);
            self::assertSame('100.001', $http->sentQuery($index)['ts']);
            self::assertSame('true', $http->sentQuery($index)['include_all_metadata']);
            self::assertSame('true', $http->sentQuery($index)['inclusive']);
            self::assertSame('100.001', $http->sentQuery($index)['oldest']);
        }
        self::assertSame('opaque+/= cursor', $http->sentQuery(1)['cursor']);
        self::assertSame('POST', $http->requests[4]->getMethod());
        self::assertSame(
            [
                'channel' => 'D1',
                'ts' => '100.002',
                'text' => 'Keep this text',
                'metadata' => ['event_type' => 'assistant_thread_context', 'event_payload' => ['case' => 'new']],
                'blocks' => $message['blocks'],
            ],
            $http->sentPayload(4),
        );
    }

    public function test_repeated_cursor_is_rejected_without_an_unbounded_read_loop(): void
    {
        $page = $this->page([], 'same');
        $http = new RecordingHttpClient([$page, $page]);
        try {
            new SlackMetadataThreadContextStore()->get($this->identity(), new Client($http, 'secret'));
            self::fail('Expected a cursor cycle.');
        } catch (\UnexpectedValueException) {
            self::assertCount(2, $http->requests);
        }
    }

    public function test_unrelated_metadata_is_never_overwritten(): void
    {
        $http = new RecordingHttpClient([$this->page([[
            'user' => 'U-bot',
            'ts' => '100.002',
            'text' => 'Keep this',
            'metadata' => ['event_type' => 'another_feature', 'event_payload' => ['reference' => 'original']],
        ]])]);
        try {
            new SlackMetadataThreadContextStore()->save(
                $this->identity(),
                new ThreadContext(),
                new Client($http, 'secret'),
            );
            self::fail('Expected unrelated metadata protection.');
        } catch (\LogicException) {
            self::assertCount(1, $http->requests);
            self::assertSame('GET', $http->requests[0]->getMethod());
        }
    }

    private function identity(): ThreadIdentity
    {
        return new ThreadIdentity(new ConversationKey('D1', teamId: 'T1', threadTs: '100.001'), 'U1', 'U-bot');
    }

    private function page(array $messages, string $cursor = ''): Response
    {
        return new Response(200, [], json_encode([
            'ok' => true,
            'messages' => $messages,
            'response_metadata' => ['next_cursor' => $cursor],
        ], JSON_THROW_ON_ERROR));
    }
}
