<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Testing;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Exceptions\MissingReplyContext;
use Thunder\Bolt\Http\Response;
use Thunder\Bolt\Invocation\Interaction;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\Options;
use Thunder\Bolt\Payloads\Shortcut;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Responses\ActionResponse;
use Thunder\Bolt\Responses\ViewResponse;
use Thunder\Bolt\Testing\AssertionFailed;
use Thunder\Bolt\Testing\BoltTest;
use Thunder\Bolt\Testing\InteractionFixture;
use Thunder\Bolt\Testing\SlackCall;
use Thunder\Bolt\Testing\TestResult;
use Thunder\Bolt\WebApi\Client;

final class InteractionFixtureTest extends TestCase
{
    public function test_action_fixture_reaches_real_receiver_with_targeted_context(): void
    {
        $received = null;
        $bolt = BoltTest::make();
        $bolt->app->action('approve', static function (Action $action, Reply $reply) use (&$received): ActionResponse {
            $received = $action;
            $reply->inThread('Approved.');

            return ActionResponse::empty();
        });

        $bolt
            ->interaction(
                InteractionFixture::action('approve', 'approval')
                    ->fromUser('U_ADA')
                    ->forTeam('T_ACME')
                    ->inChannel('C_DEPLOYS', '171.000002', '171.000001')
                    ->value('approved'),
            )
            ->assertAcknowledged()
            ->assertThreadReply('Approved.')
            ->assertApiCalledWith('chat.postMessage', [
                'channel' => 'C_DEPLOYS',
                'thread_ts' => '171.000001',
                'text' => 'Approved.',
            ]);

        self::assertInstanceOf(Action::class, $received);
        self::assertSame('approve', $received->actionId);
        self::assertSame('approval', $received->blockId);
        self::assertSame('approved', $received->action['value']);
        self::assertSame('U_ADA', $received->userId);
        self::assertSame('C_DEPLOYS', $received->channelId);
    }

    public function test_view_submission_fixture_reaches_real_receiver_with_simple_state_helpers(): void
    {
        $received = null;
        $bolt = BoltTest::make();
        $bolt->app->view('deployment_form', static function (View $view) use (&$received): ViewResponse {
            $received = $view;

            return ViewResponse::errors(['reason' => 'Explain the change.']);
        });

        $bolt->interaction(
            InteractionFixture::viewSubmission('deployment_form')
                ->fromUser('U_ADA')
                ->forTeam('T_ACME')
                ->view('V_DEPLOYMENT')
                ->state('reason', 'reason', 'Routine maintenance.'),
        )->assertAcknowledged([
            'response_action' => 'errors',
            'errors' => ['reason' => 'Explain the change.'],
        ]);

        self::assertInstanceOf(View::class, $received);
        self::assertSame('V_DEPLOYMENT', $received->viewId);
        self::assertSame('U_ADA', $received->userId);
        self::assertSame('Routine maintenance.', $received->value('reason', 'reason'));
    }

    public function test_modal_action_drops_message_reply_context_and_channel_targeting_restores_it(): void
    {
        $modal = InteractionFixture::action('open_modal')->view('V_MODAL')->toArray();
        self::assertSame(['type' => 'view', 'view_id' => 'V_MODAL'], $modal['container']);
        self::assertSame(['id' => 'V_MODAL', 'type' => 'modal'], $modal['view']);
        self::assertArrayNotHasKey('channel', $modal);
        self::assertArrayNotHasKey('message', $modal);
        self::assertArrayNotHasKey('thread_ts', $modal);
        self::assertArrayNotHasKey('response_url', $modal);

        $bolt = BoltTest::make();
        $bolt->app->action('open_modal', static function (Reply $reply): ActionResponse {
            $reply->inChannel('This action belongs to a modal.');

            return ActionResponse::empty();
        });
        $result = $bolt->send($modal, form: true)->assertStatus(500);
        self::assertInstanceOf(MissingReplyContext::class, $result->error);

        $message = InteractionFixture::action('open_modal')
            ->view('V_MODAL')
            ->inChannel('C_APPROVALS', '172.000002', '172.000001')
            ->toArray();
        self::assertSame('message', $message['container']['type']);
        self::assertSame('C_APPROVALS', $message['channel']['id']);
        self::assertArrayNotHasKey('view', $message);
        self::assertSame('https://example.test/response', $message['response_url']);
    }

    public function test_factories_seed_valid_shapes_and_with_preserves_raw_overrides_immutably(): void
    {
        $action = InteractionFixture::action('original', 'original_block');
        $changed = $action->with(['team' => ['domain' => 'example']])->with(['custom' => ['enabled' => false]]);

        self::assertSame('T_TEST', $changed->toArray()['team']['id']);
        self::assertSame('example', $changed->toArray()['team']['domain']);
        self::assertSame('original', $action->toArray()['actions'][0]['action_id']);
        self::assertSame(false, $changed->toArray()['custom']['enabled']);

        $emptyActions = $action->with(['actions' => []]);
        $replacedActions = $action->with(['actions' => [['action_id' => 'other']]]);
        self::assertSame([], $emptyActions->toArray()['actions']);
        self::assertSame([['action_id' => 'other']], $replacedActions->toArray()['actions']);

        self::assertSame('shortcut', InteractionFixture::shortcut('open_modal')->toArray()['type']);
        $options = InteractionFixture::options('assignee')->value('ad')->toArray();
        self::assertSame('block_suggestion', $options['type']);
        self::assertSame('ad', $options['value']);
        self::assertArrayNotHasKey('actions', $options);
        self::assertSame(
            ['reason' => ['reason' => ['type' => 'plain_text_input', 'value' => 'Because.']]],
            InteractionFixture::viewSubmission('reason_form')
                ->state('reason', 'reason', 'Because.')
                ->toArray()['view']['state']['values'],
        );
        self::assertSame(
            ['replacement' => ['field' => ['type' => 'plain_text_input', 'value' => 'New.']]],
            InteractionFixture::viewSubmission('reason_form')
                ->state('old', 'field', 'Old.')
                ->values(['replacement' => ['field' => ['type' => 'plain_text_input', 'value' => 'New.']]])
                ->toArray()['view']['state']['values'],
        );
        self::assertSame(
            [
                0 => [
                    'first' => ['type' => 'plain_text_input', 'value' => 'A'],
                    'second' => ['type' => 'plain_text_input', 'value' => 'B'],
                ],
                'other' => ['third' => ['type' => 'plain_text_input', 'value' => 'C']],
            ],
            InteractionFixture::viewSubmission('numeric_form')
                ->state('0', 'first', 'A')
                ->state('0', 'second', 'B')
                ->state('other', 'third', 'C')
                ->toArray()['view']['state']['values'],
        );
    }

    public function test_value_and_view_reject_incompatible_delivery_kinds(): void
    {
        foreach ([
            static fn() => InteractionFixture::shortcut('open_modal')->value('not-applicable'),
            static fn() => InteractionFixture::shortcut('open_modal')->view('V_MODAL'),
            static fn() => InteractionFixture::options('assignee')->view('V_MODAL'),
        ] as $operation) {
            try {
                $operation();
                self::fail('This fixture kind should require the raw with() escape hatch.');
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_numeric_looking_state_ids_survive_chained_state_helpers_through_the_receiver(): void
    {
        $received = null;
        $bolt = BoltTest::make();
        $bolt->app->view('numeric_form', static function (View $view) use (&$received): ViewResponse {
            $received = $view;

            return ViewResponse::empty();
        });

        $bolt->interaction(
            InteractionFixture::viewSubmission('numeric_form')
                ->state('0', 'first', 'A')
                ->state('0', 'second', 'B')
                ->state('other', 'third', 'C'),
        )->assertAcknowledged();

        self::assertInstanceOf(View::class, $received);
        self::assertSame('A', $received->value('0', 'first'));
        self::assertSame('B', $received->value('0', 'second'));
        self::assertSame('C', $received->value('other', 'third'));
    }

    public function test_all_named_factories_are_accepted_by_the_interaction_reader(): void
    {
        self::assertInstanceOf(Action::class, Interaction::fromArray(InteractionFixture::action('approve')->toArray()));
        self::assertInstanceOf(
            Shortcut::class,
            Interaction::fromArray(InteractionFixture::shortcut('open_modal')->toArray()),
        );
        self::assertInstanceOf(
            Options::class,
            Interaction::fromArray(InteractionFixture::options('assignee', 'ad')->toArray()),
        );
        self::assertInstanceOf(
            View::class,
            Interaction::fromArray(InteractionFixture::viewSubmission('reason_form')->toArray()),
        );
    }

    public function test_structured_api_assertions_are_strict_nested_and_scoped_to_one_delivery(): void
    {
        $bolt = BoltTest::make();
        $bolt->app->event('app_mention', static function (Client $client): void {
            $client
                ->chat()
                ->postMessage([
                    'channel' => 'C_TEST',
                    'text' => 'Report.',
                    'blocks' => [['type' => 'section']],
                    'metadata' => ['nullable' => null, 'enabled' => false, 'count' => 0],
                ]);
        });

        $first = $bolt->mention('first')->assertApiCalledWith(
            'chat.postMessage',
            [
                'metadata' => ['nullable' => null, 'enabled' => false, 'count' => 0],
                'blocks' => [['type' => 'section']],
            ],
            times: 1,
        );
        $second = $bolt->mention('second')->assertApiCalledWith('chat.postMessage', ['text' => 'Report.']);

        self::assertCount(1, $first->calls());
        self::assertCount(1, $second->calls());
    }

    public function test_structured_api_assertion_reports_missing_and_strict_type_mismatches(): void
    {
        $result = new TestResult(
            new Response(200),
            [new SlackCall('chat.postMessage', ['metadata' => ['enabled' => false, 'nullable' => null]])],
            [],
            null,
            null,
            null,
        );

        try {
            $result->assertApiCalledWith('chat.postMessage', ['metadata' => ['missing' => null]]);
            self::fail('A missing null field must not match an explicit null expectation.');
        } catch (AssertionFailed $error) {
            self::assertStringContainsString('$.metadata.missing was missing', $error->getMessage());
        }

        try {
            $result->assertApiCalledWith('chat.postMessage', ['metadata' => ['enabled' => 0]]);
            self::fail('False must not match integer zero.');
        } catch (AssertionFailed $error) {
            self::assertStringContainsString('expected int(0) but received false', $error->getMessage());
        }

        try {
            new TestResult(
                new Response(200),
                [new SlackCall('chat.postMessage', ['blocks' => [['type' => 'section']]])],
                [],
                null,
                null,
                null,
            )->assertApiCalledWith('chat.postMessage', ['blocks' => [1 => ['type' => 'section']]]);
            self::fail('List expectations must retain their original numeric positions.');
        } catch (AssertionFailed $error) {
            self::assertStringContainsString('$.blocks[1] was missing', $error->getMessage());
        }
    }

    public function test_empty_expected_arrays_mean_exactly_empty_at_every_level(): void
    {
        $empty = new TestResult(
            Response::json(['options' => []]),
            [new SlackCall('chat.postMessage', [])],
            [],
            null,
            null,
            null,
        );
        $empty->assertAcknowledged(['options' => []])->assertApiCalledWith('chat.postMessage', []);

        $nonEmpty = new TestResult(
            Response::json(['options' => [['value' => 'ada']]]),
            [new SlackCall('chat.postMessage', ['blocks' => [['type' => 'section']]])],
            [],
            null,
            null,
            null,
        );
        try {
            $nonEmpty->assertAcknowledged(['options' => []]);
            self::fail('A non-empty acknowledgement list must not match [].');
        } catch (AssertionFailed $error) {
            self::assertStringContainsString('$.options expected an empty array', $error->getMessage());
        }
        try {
            $nonEmpty->assertApiCalledWith('chat.postMessage', []);
            self::fail('A non-empty call argument object must not match [].');
        } catch (AssertionFailed $error) {
            self::assertStringContainsString('$ expected an empty array', $error->getMessage());
        }
    }

    public function test_structured_api_assertion_redacts_sensitive_mismatch_values(): void
    {
        $result = new TestResult(
            new Response(200),
            [new SlackCall('auth.test', ['token' => 'xoxb-secret-value'])],
            [],
            null,
            null,
            null,
        );

        try {
            $result->assertApiCalledWith('auth.test', ['token' => 'wrong']);
            self::fail('Expected a sensitive-value mismatch.');
        } catch (AssertionFailed $error) {
            self::assertStringContainsString('[redacted]', $error->getMessage());
            self::assertStringNotContainsString('xoxb-secret-value', $error->getMessage());
        }
    }
}
