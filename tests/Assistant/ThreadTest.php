<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Assistant;

use GuzzleHttp\Psr7\Response as HttpResponse;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Assistant\Stores\SlackMetadataThreadContextStore;
use Thunder\Bolt\Assistant\Stores\StoredThreadContextStore;
use Thunder\Bolt\Assistant\SuggestedPrompt;
use Thunder\Bolt\Assistant\Thread;
use Thunder\Bolt\Assistant\ThreadContext;
use Thunder\Bolt\Assistant\ThreadIdentity;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Conversation\Stores\MemoryConversationStore;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class ThreadTest extends TestCase
{
    public function test_explicit_context_save_updates_the_current_delivery_snapshot(): void
    {
        $http = new RecordingHttpClient();
        $thread = $this->thread($http, new ThreadContext('C-old'));
        $thread->saveContext(new ThreadContext('C-new'));
        self::assertSame('C-new', $thread->context()?->channelId);
        $thread->say('Updated');
        self::assertSame('C-new', $http->sentPayload()['metadata']['event_payload']['channel_id']);
    }

    public function test_empty_focus_context_uses_a_json_object_in_message_metadata(): void
    {
        $http = new RecordingHttpClient();
        $this->thread($http, new ThreadContext())->say('Ready');
        self::assertStringContainsString('"event_payload":{}', (string) $http->requests[0]->getBody());
    }

    public function test_thread_pins_messages_and_assistant_controls_to_its_root(): void
    {
        $http = new RecordingHttpClient();
        $thread = $this->thread($http, new ThreadContext('C1', 'T1', extra: ['workspace' => 'demo']));

        $thread->say('Ready');
        $thread->setStatus('Reading', ['Fetching context']);
        $thread->clearStatus();
        $thread->setTitle('Release review');
        $thread->setSuggestedPrompts([new SuggestedPrompt('Summarize', 'Summarize the release')], 'Try one');

        self::assertSame('chat.postMessage', basename($http->requests[0]->getUri()->getPath()));
        self::assertSame('C1', $http->sentPayload(0)['channel']);
        self::assertSame('100.001', $http->sentPayload(0)['thread_ts']);
        self::assertSame('assistant_thread_context', $http->sentPayload(0)['metadata']['event_type']);
        self::assertSame('demo', $http->sentPayload(0)['metadata']['event_payload']['workspace']);
        self::assertSame('assistant.threads.setStatus', basename($http->requests[1]->getUri()->getPath()));
        self::assertSame(['Fetching context'], $http->sentPayload(1)['loading_messages']);
        self::assertSame('', $http->sentPayload(2)['status']);
        self::assertSame('Release review', $http->sentPayload(3)['title']);
        self::assertSame('Try one', $http->sentPayload(4)['title']);
        self::assertSame(
            ['title' => 'Summarize', 'message' => 'Summarize the release'],
            $http->sentPayload(4)['prompts'][0],
        );
    }

    public function test_thread_does_not_replace_existing_message_metadata(): void
    {
        $http = new RecordingHttpClient();
        $thread = $this->thread($http, new ThreadContext('C1', 'T1', extra: ['workspace' => 'demo']));

        $thread->say(Message::text('Ready')->metadata('custom', ['request' => 'R1']));

        self::assertSame('custom', $http->sentPayload(0)['metadata']['event_type']);
        self::assertSame('R1', $http->sentPayload(0)['metadata']['event_payload']['request']);
        self::assertArrayNotHasKey('workspace', $http->sentPayload(0)['metadata']['event_payload']);
    }

    public function test_context_can_be_saved_from_the_event_or_explicitly_loaded_later(): void
    {
        $http = new RecordingHttpClient();
        $store = new StoredThreadContextStore(new MemoryConversationStore());
        $identity = $this->identity();
        $event = new ThreadContext('C1', 'T1', extra: ['record' => '42']);
        $thread = new Thread(new Client($http, 'xoxb-test'), $identity, $store, $event);

        $thread->saveContext();
        $fresh = new Thread(new Client($http, 'xoxb-test'), $identity, $store);

        self::assertSame('42', $fresh->context()?->toArray()['record']);
        $this->expectException(\LogicException::class);
        new Thread(new Client($http, 'xoxb-test'), $this->identity(), $store)->saveContext();
    }

    public function test_slack_metadata_store_only_reads_and_updates_our_initial_bot_reply(): void
    {
        $reply = new HttpResponse(200, ['Content-Type' => 'application/json'], json_encode([
            'ok' => true,
            'messages' => [
                [
                    'ts' => '100.001',
                    'user' => 'U-other',
                    'text' => 'Foreign',
                    'metadata' => [
                        'event_type' => 'assistant_thread_context',
                        'event_payload' => ['record' => 'foreign'],
                    ],
                ],
                [
                    'ts' => '100.002',
                    'user' => 'U-bot',
                    'text' => 'Root',
                    'blocks' => [['type' => 'section']],
                    'metadata' => ['event_type' => 'assistant_thread_context', 'event_payload' => ['record' => 'ours']],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $http = new RecordingHttpClient([$reply, $reply]);
        $store = new SlackMetadataThreadContextStore();
        $identity = new ThreadIdentity(new ConversationKey('C1', 'T1', threadTs: '100.001'), 'U1', 'U-bot');
        $client = new Client($http, 'xoxb-test');

        self::assertSame('ours', $store->get($identity, $client)?->toArray()['record']);
        $store->save($identity, new ThreadContext('C1', 'T1', extra: ['record' => 'updated']), $client);

        self::assertSame('Root', $http->sentPayload(2)['text']);
        self::assertSame('100.002', $http->sentPayload(2)['ts']);
        self::assertSame([['type' => 'section']], $http->sentPayload(2)['blocks']);
        self::assertSame('updated', $http->sentPayload(2)['metadata']['event_payload']['record']);
    }

    private function thread(RecordingHttpClient $http, ?ThreadContext $context = null): Thread
    {
        return new Thread(
            new Client($http, 'xoxb-test'),
            $this->identity(),
            new StoredThreadContextStore(new MemoryConversationStore()),
            $context,
        );
    }

    private function identity(): ThreadIdentity
    {
        return new ThreadIdentity(new ConversationKey('C1', 'T1', threadTs: '100.001'), 'U1', 'U-bot');
    }
}
