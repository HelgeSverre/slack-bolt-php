<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Testing;

use PHPUnit\Framework\TestCase;
use Tempest\CommandBus\CommandHandler;
use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Attributes\SlashCommand;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Jobs\Job;
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Responses\CommandResponse;
use Thunder\Bolt\Testing\AssertionFailed;
use Thunder\Bolt\Testing\BoltTest;
use Thunder\Bolt\Testing\SlackCall;
use Thunder\Bolt\WebApi\Client;

final class BoltTestTest extends TestCase
{
    public function test_message_fixtures_use_message_routing_and_keep_the_supplied_thread(): void
    {
        $bolt = BoltTest::make();
        $bolt->app->message('hello', static function (Reply $reply): void {
            $reply->inThread('Matched.');
        });
        $bolt->message('hello', threadTs: '1.000001')->assertThreadReply('Matched.');
    }

    public function test_action_reply_assertions_use_nested_user_channel_and_original_thread_fields(): void
    {
        $bolt = BoltTest::make();
        $bolt->app->action('approve', static function (Reply $reply): \Thunder\Bolt\Responses\ActionResponse {
            $reply->inThread('Thread reply.');
            $reply->ephemeral('Private reply.');
            return \Thunder\Bolt\Responses\ActionResponse::empty();
        });
        $bolt
            ->interaction([
                'type' => 'block_actions',
                'team' => ['id' => 'T1'],
                'user' => ['id' => 'U1'],
                'channel' => ['id' => 'C1'],
                'message' => ['ts' => '2.0', 'thread_ts' => '1.0'],
                'actions' => [['action_id' => 'approve', 'block_id' => 'approvals', 'type' => 'button']],
            ])
            ->assertThreadReply('Thread reply.')
            ->assertEphemeralReply('Private reply.');
    }

    public function test_captured_client_cannot_be_replaced_by_a_potentially_live_client_binding(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $bolt = BoltTest::make();
        $bolt->bind(new Client($bolt->slack, 'another-token'));
    }

    public function test_no_messages_assertion_includes_edits_to_existing_messages(): void
    {
        $bolt = BoltTest::make();
        $bolt->app->event('app_mention', static function (Client $client): void {
            $client->chat()->update(['channel' => 'C1', 'ts' => '1.0', 'text' => 'Changed.']);
        });
        $this->expectException(AssertionFailed::class);
        $bolt->mention('hello')->assertNoMessagesSent();
    }

    public function test_real_handlers_receive_typed_deliveries_and_use_a_fake_constructor_client(): void
    {
        $bolt = BoltTest::make()->listeners(TestGreeting::class);
        $result = $bolt
            ->mention('hello', user: 'U123', channel: 'C123', threadTs: '1.000009')
            ->assertAcknowledged()
            ->assertThreadReply('Hello U123.')
            ->assertNoJobsQueued();
        self::assertCount(1, $result->calls());
        self::assertSame('1.000009', $result->calls()[0]->arguments['thread_ts']);
    }

    public function test_results_do_not_include_prior_calls_and_harnesses_are_isolated(): void
    {
        $bolt = BoltTest::make()->listeners(TestGreeting::class);
        $first = $bolt->mention('hello');
        $second = $bolt->mention('again');
        self::assertCount(1, $first->calls());
        self::assertCount(1, $second->calls());
        self::assertCount(2, $bolt->slack->calls());
        BoltTest::make()->mention('nothing')->assertNoApiCalls();
        $this->expectException(AssertionFailed::class);
        $second->assertNoMessagesSent();
    }

    public function test_command_assertions_cover_the_returned_acknowledgement_not_a_posted_message(): void
    {
        BoltTest::make()
            ->listeners(TestPing::class)
            ->command('/ping')
            ->assertAcknowledged(['text' => 'Pong.', 'response_type' => 'ephemeral'])
            ->assertNoMessagesSent();
    }

    public function test_failed_acknowledgement_assertions_do_not_pass_when_fields_are_missing(): void
    {
        $this->expectException(AssertionFailed::class);
        BoltTest::make()->listeners(TestPing::class)->command('/ping')->assertAcknowledged(['text' => 'Wrong.']);
    }

    public function test_missing_acknowledgement_fields_produce_an_assertion_failure_for_an_empty_body(): void
    {
        $this->expectException(AssertionFailed::class);
        BoltTest::make()->mention('no listener')->assertAcknowledged(['text' => 'Missing.']);
    }

    public function test_real_job_acceptance_deduplicates_and_does_not_execute_a_job_handler(): void
    {
        $bolt = BoltTest::make(jobs: true)->listeners(TestQueue::class)->jobHandlers(TestQueuedHandler::class);
        $data = ['user' => 'U1', 'channel' => 'C1', 'text' => 'work', 'ts' => '1.0'];
        $result = $bolt
            ->event('app_mention', $data, eventId: 'Ev-deduplicate')
            ->assertJobQueued(TestQueued::class, static fn($job): bool => $job->payload['text'] === 'work')
            ->assertNoMessagesSent();
        self::assertCount(1, $result->jobs());
        $bolt->event('app_mention', $data, eventId: 'Ev-deduplicate')->assertNoJobsQueued();
        $bolt->event(
            'app_mention',
            $data,
            team: 'T_OTHER',
            eventId: 'Ev-deduplicate',
        )->assertJobQueued(TestQueued::class);
    }

    public function test_no_job_assertion_fails_when_an_execution_was_accepted(): void
    {
        $this->expectException(AssertionFailed::class);
        BoltTest::make(jobs: true)
            ->listeners(TestQueue::class)
            ->jobHandlers(TestQueuedHandler::class)
            ->mention('work')
            ->assertNoJobsQueued();
    }

    public function test_unknown_api_calls_are_blocked_and_original_failures_remain_available(): void
    {
        $bolt = BoltTest::make();
        $bolt->app->event('app_mention', static function (Client $client): void {
            $client->users()->info(['user' => 'U123']);
        });
        try {
            $bolt->mention('hello');
            self::fail('An unstubbed read should not silently succeed.');
        } catch (AssertionFailed $error) {
            self::assertNotNull($error->getPrevious());
        }
        $bolt->slack->respondsWith('users.info', ['ok' => true, 'user' => ['id' => 'U123']]);
        $bolt->mention('hello')->assertApiCalled('users.info', times: 1);
    }

    public function test_api_error_stubs_are_not_silently_replaced_with_success(): void
    {
        $bolt = BoltTest::make()->listeners(TestGreeting::class);
        $bolt->slack->respondsWith('chat.postMessage', ['ok' => false, 'error' => 'channel_not_found']);
        $this->expectException(AssertionFailed::class);
        $bolt->mention('hello');
    }

    public function test_send_allows_negative_receiver_tests(): void
    {
        BoltTest::make()->send(['type' => 'unsupported'])->assertStatus(501);
        BoltTest::make()
            ->send(['type' => 'event_callback', 'event' => ['type' => 'app_home_opened']])
            ->assertStatus(400);
    }

    public function test_response_url_interactions_are_recorded_without_network_calls(): void
    {
        $bolt = BoltTest::make();
        $bolt->app->action('approve', static function (Reply $reply): \Thunder\Bolt\Responses\ActionResponse {
            $reply->replaceOriginal('Approved.');
            return \Thunder\Bolt\Responses\ActionResponse::empty();
        });
        $bolt->interaction([
            'type' => 'block_actions',
            'team' => ['id' => 'T1'],
            'user' => ['id' => 'U1'],
            'channel' => ['id' => 'C1'],
            'response_url' => 'https://example.test/response',
            'actions' => [['action_id' => 'approve', 'block_id' => 'approvals', 'type' => 'button']],
        ])->assertAcknowledged()->assertApiCalled(
            'response_url',
            static fn(SlackCall $call): bool => ($call->arguments['replace_original'] ?? false) === true,
        );
    }

    public function test_user_services_can_be_bound_without_booting_environment_configuration(): void
    {
        $bolt = BoltTest::make()->bind(new TestLabel('bound'))->listeners(TestBoundGreeting::class);
        $bolt->mention('hello')->assertThreadReply('bound');
    }
}

final readonly class TestGreeting
{
    public function __construct(
        private Client $client,
    ) {}

    #[Event('app_mention')]
    public function __invoke(AppMention $event): void
    {
        $this->client
            ->chat()
            ->postMessage([
                'channel' => $event->channelId,
                'thread_ts' => $event->threadTs ?? $event->ts,
                'text' => "Hello {$event->userId}.",
            ]);
    }
}

final readonly class TestPing
{
    #[SlashCommand('/ping')]
    public function __invoke(): CommandResponse
    {
        return CommandResponse::ephemeral('Pong.');
    }
}

final readonly class TestQueued implements Job
{
    public function __construct(
        public string $text,
    ) {}
}

final readonly class TestQueue
{
    #[Event('app_mention')]
    public function __invoke(AppMention $event, Jobs $jobs): void
    {
        $jobs->dispatch(new TestQueued($event->text));
    }
}

final readonly class TestQueuedHandler
{
    #[CommandHandler]
    public function handle(TestQueued $job): void
    {
        throw new \LogicException('Intake assertions must never execute this handler.');
    }
}

final readonly class TestLabel
{
    public function __construct(
        public string $text,
    ) {}
}

final readonly class TestBoundGreeting
{
    public function __construct(
        private TestLabel $label,
    ) {}

    #[Event('app_mention')]
    public function __invoke(Reply $reply): void
    {
        $reply->inThread($this->label->text);
    }
}
