<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Listeners\ApproveRequest;
use App\Listeners\GreetOnMention;
use App\Listeners\OpenRequestModal;
use App\Listeners\SearchAssignees;
use App\Listeners\SubmitRequest;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\Payloads\Options;
use Thunder\Bolt\Payloads\Shortcut;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

require_once dirname(__DIR__, 2) . '/examples/hello-bolt/app/Listeners/GreetOnMention.php';
require_once dirname(__DIR__, 2) . '/examples/hello-bolt/app/Listeners/ApproveRequest.php';
require_once dirname(__DIR__, 2) . '/examples/hello-bolt/app/Listeners/OpenRequestModal.php';
require_once dirname(__DIR__, 2) . '/examples/hello-bolt/app/Listeners/SearchAssignees.php';
require_once dirname(__DIR__, 2) . '/examples/hello-bolt/app/Listeners/SubmitRequest.php';

final class HelloBoltInteractivityTest extends TestCase
{
    public function test_the_example_connects_a_message_action_modal_options_and_submission_without_network_io(): void
    {
        $transport = new RecordingHttpClient();
        $client = new Client($transport, 'xoxb-example-token');

        $mention = AppMention::fromArray([
            'user' => 'U_EXAMPLE_USER',
            'channel' => 'C_EXAMPLE_CHANNEL',
            'text' => '<@B_EXAMPLE_BOT> hello',
            'ts' => '1700000000.000001',
        ]);
        new GreetOnMention()->__invoke(
            $mention,
            new Reply($client, $mention->channelId, $mention->ts, $mention->userId),
        );

        self::assertSame('chat.postMessage', basename($transport->requests[0]->getUri()->getPath()));
        self::assertSame(
            [
                'channel' => 'C_EXAMPLE_CHANNEL',
                'text' => 'Hello <@U_EXAMPLE_USER>! Ready to review this request?',
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => 'Hello <@U_EXAMPLE_USER>! Ready to review this request?',
                        ],
                    ],
                    [
                        'type' => 'actions',
                        'elements' => [[
                            'type' => 'button',
                            'text' => ['type' => 'plain_text', 'text' => 'Approve'],
                            'action_id' => 'approve_request',
                            'style' => 'primary',
                        ]],
                    ],
                ],
                'thread_ts' => '1700000000.000001',
            ],
            $transport->sentPayload(),
        );

        $action = Action::fromArray([
            'type' => 'block_actions',
            'user' => ['id' => 'U_EXAMPLE_USER'],
            'channel' => ['id' => 'C_EXAMPLE_CHANNEL'],
            'response_url' => 'https://hooks.slack.com/actions/T_EXAMPLE/B_EXAMPLE/EXAMPLE',
            'actions' => [['action_id' => 'approve_request']],
        ]);
        $acknowledgement = new ApproveRequest()->__invoke(
            $action,
            new Reply($client, $action->channelId, null, $action->userId, $action->responseUrl),
        );

        self::assertSame([], $acknowledgement->toArray());
        self::assertSame(
            'https://hooks.slack.com/actions/T_EXAMPLE/B_EXAMPLE/EXAMPLE',
            (string) $transport->requests[1]->getUri(),
        );
        self::assertSame(
            ['text' => 'Approved. The request can move forward.', 'replace_original' => true],
            $transport->sentPayload(1),
        );

        $shortcut = Shortcut::fromArray([
            'type' => 'shortcut',
            'callback_id' => 'open_request_modal',
            'trigger_id' => 'trigger-example',
            'user' => ['id' => 'U_EXAMPLE_USER'],
        ]);
        $shortcutAcknowledgement = new OpenRequestModal($client)->__invoke($shortcut);

        self::assertSame([], $shortcutAcknowledgement->toArray());
        self::assertSame('views.open', basename($transport->requests[2]->getUri()->getPath()));
        self::assertSame('submit_request', $transport->sentPayload(2)['view']['callback_id']);
        self::assertSame('external_select', $transport->sentPayload(2)['view']['blocks'][1]['element']['type']);
        self::assertSame('search_assignees', $transport->sentPayload(2)['view']['blocks'][1]['element']['action_id']);

        $options = Options::fromArray([
            'type' => 'block_suggestion',
            'action_id' => 'search_assignees',
            'block_id' => 'assignee',
            'value' => 'ada',
            'view' => ['callback_id' => 'submit_request'],
        ]);
        self::assertSame(
            [
                'options' => [
                    ['text' => ['type' => 'plain_text', 'text' => 'Ada Lovelace'], 'value' => 'U_ADA_LOVELACE'],
                ],
            ],
            new SearchAssignees()
                ->__invoke($options)
                ->toArray(),
        );

        $emptySummary = View::fromArray([
            'type' => 'view_submission',
            'view' => [
                'id' => 'V_EXAMPLE',
                'callback_id' => 'submit_request',
                'state' => ['values' => ['summary' => ['title' => ['type' => 'plain_text_input', 'value' => '  ']]]],
            ],
        ]);
        $invalidSubmission = new SubmitRequest()
            ->__invoke($emptySummary)
            ->toArray();
        self::assertSame('errors', $invalidSubmission['response_action']);
        self::assertEquals((object) ['summary' => 'Please describe the request.'], $invalidSubmission['errors']);

        $validSummary = View::fromArray([
            'type' => 'view_submission',
            'view' => [
                'id' => 'V_EXAMPLE',
                'callback_id' => 'submit_request',
                'state' => [
                    'values' => [
                        'summary' => ['title' => ['type' => 'plain_text_input', 'value' => 'Review the incident']],
                    ],
                ],
            ],
        ]);
        self::assertSame(
            [],
            new SubmitRequest()
                ->__invoke($validSummary)
                ->toArray(),
        );
    }

    public function test_the_example_refuses_to_approve_without_a_response_url(): void
    {
        $action = Action::fromArray(['type' => 'block_actions', 'actions' => [['action_id' => 'approve_request']]]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires Slack to provide a response_url');

        new ApproveRequest()->__invoke(
            $action,
            new Reply(new Client(new RecordingHttpClient(), 'xoxb-example-token'), null, null, null),
        );
    }
}
