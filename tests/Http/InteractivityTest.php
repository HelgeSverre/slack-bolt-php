<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BlockKit\Option;
use Thunder\Bolt\BlockKit\OptionGroup;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Http\Receiver;
use Thunder\Bolt\Http\Response;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\Responses\Acknowledgement;
use Thunder\Bolt\Responses\ActionResponse;
use Thunder\Bolt\Responses\CommandResponse;
use Thunder\Bolt\Responses\OptionsResponse;
use Thunder\Bolt\Responses\ShortcutResponse;
use Thunder\Bolt\Responses\ViewResponse;
use Thunder\Bolt\Routing\InteractionConstraints;
use Thunder\Bolt\Routing\Regex;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class InteractivityTest extends TestCase
{
    private const string SECRET = 'local-interactivity-signing-secret';

    #[DataProvider('acknowledgements')]
    public function test_signed_form_payloads_return_the_family_specific_wire_response(
        string $method,
        string|Regex|InteractionConstraints $selector,
        array $body,
        Acknowledgement $acknowledgement,
        array $expected,
    ): void {
        [$app, $receiver] = $this->application();
        $app->{$method}($selector, static fn() => $acknowledgement);

        $response = $this->post($receiver, $body);

        self::assertSame(200, $response->status);
        if ($expected === []) {
            self::assertSame('', $response->body);
            self::assertStringStartsWith('text/plain', $response->headers['Content-Type']);
        } else {
            self::assertStringStartsWith('application/json', $response->headers['Content-Type']);
            self::assertSame($expected, json_decode($response->body, true, flags: JSON_THROW_ON_ERROR));
        }
    }

    public static function acknowledgements(): iterable
    {
        $action = self::fixture('block-actions-first-action');
        $suggestion = self::fixture('block-suggestion');
        $view = self::fixture('view-submission-response-urls');
        $option = Option::text('Ada', 'U1');
        $modal = ['type' => 'modal', 'title' => ['type' => 'plain_text', 'text' => 'Edit'], 'blocks' => []];
        $legacyMessage = [
            'text' => 'Approved',
            'attachments' => [['text' => 'Request 42', 'color' => 'good']],
            'response_type' => 'in_channel',
            'replace_original' => true,
        ];

        yield 'block action empty' => ['action', 'approve_order', $action, ActionResponse::empty(), []];
        yield 'legacy action message' => [
            'action',
            new InteractionConstraints(callbackId: 'legacy_action'),
            [
                'type' => 'interactive_message',
                'callback_id' => 'legacy_action',
                'actions' => [['name' => 'approve', 'value' => '42']],
            ],
            ActionResponse::message('Approved'),
            ['text' => 'Approved'],
        ];
        yield 'legacy full message response' => [
            'action',
            new InteractionConstraints(callbackId: 'legacy_action'),
            [
                'type' => 'interactive_message',
                'callback_id' => 'legacy_action',
                'actions' => [['name' => 'approve', 'value' => '42']],
            ],
            ActionResponse::fromArray($legacyMessage),
            $legacyMessage,
        ];
        yield 'legacy dialog validation' => [
            'action',
            new InteractionConstraints(callbackId: 'expense_report'),
            self::fixture('legacy-dialog-submission'),
            ActionResponse::errors(['email' => 'Invalid email']),
            ['errors' => [['name' => 'email', 'error' => 'Invalid email']]],
        ];
        yield 'global shortcut' => [
            'shortcut',
            'open_ticket',
            ['type' => 'shortcut', 'callback_id' => 'open_ticket', 'trigger_id' => 'trigger'],
            ShortcutResponse::empty(),
            [],
        ];
        yield 'message shortcut' => [
            'shortcut',
            'open_ticket',
            ['type' => 'message_action', 'callback_id' => 'open_ticket', 'channel' => ['id' => 'C1']],
            ShortcutResponse::empty(),
            [],
        ];
        yield 'regex shortcut' => [
            'shortcut',
            Regex::of('/^open_(?<kind>ticket)$/'),
            ['type' => 'shortcut', 'callback_id' => 'open_ticket'],
            ShortcutResponse::empty(),
            [],
        ];
        yield 'block options' => [
            'options',
            $suggestion['action_id'],
            $suggestion,
            OptionsResponse::options($option),
            ['options' => [['text' => ['type' => 'plain_text', 'text' => 'Ada'], 'value' => 'U1']]],
        ];
        yield 'block option groups' => [
            'options',
            $suggestion['action_id'],
            $suggestion,
            OptionsResponse::groups(OptionGroup::make('People', $option)),
            [
                'option_groups' => [[
                    'label' => ['type' => 'plain_text', 'text' => 'People'],
                    'options' => [['text' => ['type' => 'plain_text', 'text' => 'Ada'], 'value' => 'U1']],
                ]],
            ],
        ];
        yield 'empty options is valid JSON' => [
            'options',
            $suggestion['action_id'],
            $suggestion,
            OptionsResponse::empty(),
            ['options' => []],
        ];
        yield 'regex options' => [
            'options',
            Regex::of('/^(?<action>.+)$/'),
            $suggestion,
            OptionsResponse::empty(),
            ['options' => []],
        ];
        yield 'legacy dialog typed options' => [
            'options',
            InteractionConstraints::options(callbackId: 'expense_report'),
            self::fixture('legacy-dialog-suggestion'),
            OptionsResponse::options($option),
            ['options' => [['label' => 'Ada', 'value' => 'U1']]],
        ];
        yield 'legacy dialog groups' => [
            'options',
            InteractionConstraints::options(callbackId: 'expense_report'),
            self::fixture('legacy-dialog-suggestion'),
            OptionsResponse::groups(OptionGroup::make('People', $option)),
            ['option_groups' => [['label' => 'People', 'options' => [['label' => 'Ada', 'value' => 'U1']]]]],
        ];
        yield 'legacy message options' => [
            'options',
            InteractionConstraints::options(callbackId: 'legacy_picker_callback'),
            self::fixture('legacy-interactive-message-suggestion'),
            OptionsResponse::options($option),
            ['options' => [['text' => 'Ada', 'value' => 'U1']]],
        ];
        yield 'view empty' => ['view', 'ticket_form', $view, ViewResponse::empty(), []];
        yield 'regex view submission' => [
            'view',
            Regex::of('/^ticket_(?<kind>form)$/'),
            $view,
            ViewResponse::empty(),
            [],
        ];
        yield 'view validation' => [
            'view',
            'ticket_form',
            $view,
            ViewResponse::errors(['summary' => 'Required']),
            ['response_action' => 'errors', 'errors' => ['summary' => 'Required']],
        ];
        yield 'view update' => [
            'view',
            'ticket_form',
            $view,
            ViewResponse::update($modal),
            ['response_action' => 'update', 'view' => $modal],
        ];
        yield 'view push' => [
            'view',
            'ticket_form',
            $view,
            ViewResponse::push($modal),
            ['response_action' => 'push', 'view' => $modal],
        ];
        yield 'view clear' => ['view', 'ticket_form', $view, ViewResponse::clear(), ['response_action' => 'clear']];
        yield 'view close' => [
            'view',
            InteractionConstraints::view('ticket_form', 'view_closed'),
            self::fixture('view-closed'),
            ViewResponse::empty(),
            [],
        ];
    }

    public function test_first_action_and_all_constraints_determine_matching_with_separate_regex_captures(): void
    {
        [$app, $receiver] = $this->application();
        $captured = null;
        $app->action(
            'ignore_me_for_bolt_matching',
            static fn() => throw new \RuntimeException('Second action must not be dispatched'),
        );
        $app->action(
            new InteractionConstraints(
                actionId: Regex::of('/^(?<verb>approve)_order$/'),
                blockId: Regex::of('/^(?<kind>orders)$/'),
                callbackId: 'modal_callback',
                type: 'block_actions',
            ),
            static function (Action $action, Delivery $delivery) use (&$captured): ActionResponse {
                $captured = [$action->raw, $delivery->constraintMatches, $delivery->matches];

                return ActionResponse::empty();
            },
        );
        $body = self::fixture('block-actions-first-action');
        $body['view'] = ['callback_id' => 'modal_callback'];

        self::assertSame(200, $this->post($receiver, $body)->status);
        self::assertSame($body, $captured[0]);
        self::assertSame('approve', $captured[1]['actionId']['verb']);
        self::assertSame('orders', $captured[1]['blockId']['kind']);
        self::assertSame('approve', $captured[2]['verb']);
        $body['view']['callback_id'] = 'different';
        self::assertSame(500, $this->post($receiver, $body)->status);
    }

    public function test_view_shorthand_does_not_receive_close_but_explicit_untyped_constraints_receive_both(): void
    {
        [$app, $receiver] = $this->application();
        $calls = 0;
        $app->view('ticket_form', static function () use (&$calls): ViewResponse {
            $calls++;

            return ViewResponse::empty();
        });
        self::assertSame(500, $this->post($receiver, self::fixture('view-closed'))->status);
        self::assertSame(0, $calls);
        [$app, $receiver] = $this->application();
        $app->view(new InteractionConstraints(callbackId: 'ticket_form'), static fn() => ViewResponse::empty());
        self::assertSame(200, $this->post($receiver, self::fixture('view-closed'))->status);
        self::assertSame(200, $this->post($receiver, self::fixture('view-submission-response-urls'))->status);
    }

    #[DataProvider('invalidAcknowledgements')]
    public function test_wrong_response_family_or_body_returns_failure(
        string $method,
        string|InteractionConstraints $selector,
        array $body,
        Acknowledgement $acknowledgement,
    ): void {
        [$app, $receiver, $http] = $this->application();
        $app->{$method}($selector, static fn() => $acknowledgement);
        $response = $this->post($receiver, $body);
        self::assertSame(500, $response->status);
        self::assertSame('Slack delivery could not be processed.', $response->body);
        self::assertSame([], $http->requests);
    }

    public static function invalidAcknowledgements(): iterable
    {
        yield 'command response on action' => [
            'action',
            'approve_order',
            self::fixture('block-actions-first-action'),
            CommandResponse::empty(),
        ];
        yield 'block action cannot send initial message' => [
            'action',
            'approve_order',
            self::fixture('block-actions-first-action'),
            ActionResponse::message('Wrong destination'),
        ];
        yield 'view close cannot validate' => [
            'view',
            InteractionConstraints::view('ticket_form', 'view_closed'),
            self::fixture('view-closed'),
            ViewResponse::errors(['field' => 'Error']),
        ];
        yield 'options response on shortcut' => [
            'shortcut',
            'open',
            ['type' => 'shortcut', 'callback_id' => 'open'],
            OptionsResponse::empty(),
        ];
        yield 'block options cannot use legacy labels' => [
            'options',
            'users',
            ['type' => 'block_suggestion', 'action_id' => 'users', 'block_id' => 'people', 'value' => 'a'],
            OptionsResponse::fromArray(['options' => [['label' => 'Ada', 'value' => 'U1']]]),
        ];
    }

    public function test_missing_and_multiple_acknowledgements_do_not_become_successful_empty_responses(): void
    {
        [$app, $receiver] = $this->application();
        $app->action('approve_order', static fn() => null);
        self::assertSame(500, $this->post($receiver, self::fixture('block-actions-first-action'))->status);
        [$app, $receiver] = $this->application();
        $calls = 0;
        foreach ([1, 2] as $ignored) {
            $app->action('approve_order', static function () use (&$calls): ActionResponse {
                $calls++;

                return ActionResponse::empty();
            });
        }
        self::assertSame(500, $this->post($receiver, self::fixture('block-actions-first-action'))->status);
        self::assertSame(2, $calls);
    }

    public function test_malformed_signed_action_is_bad_request_and_invalid_signature_never_dispatches(): void
    {
        [$app, $receiver] = $this->application();
        $called = false;
        $app->action(new InteractionConstraints(), static function () use (&$called): ActionResponse {
            $called = true;

            return ActionResponse::empty();
        });
        self::assertSame(400, $this->post($receiver, ['type' => 'block_actions', 'actions' => []])->status);
        self::assertSame(401, $receiver->handle('payload=malformed', [])->status);
        self::assertFalse($called);
    }

    public function test_modal_state_and_numeric_error_block_ids_survive_http_serialization(): void
    {
        [$app, $receiver] = $this->application();
        $app->view('ticket_form', static function (View $view): ViewResponse {
            self::assertSame('Broken printer', $view->value('summary', 'title'));

            return ViewResponse::errors(['0' => 'Required']);
        });
        $response = $this->post($receiver, self::fixture('view-submission-response-urls'));
        self::assertSame(200, $response->status);
        self::assertSame('{"response_action":"errors","errors":{"0":"Required"}}', $response->body);
    }

    /** @return array{App,Receiver,RecordingHttpClient} */
    private function application(): array
    {
        $config = new BoltConfig('test-bot-token', self::SECRET);
        $http = new RecordingHttpClient();
        $app = new App(new GenericContainer(), new Client($http, $config->token), $config);

        return [$app, new Receiver($app, $config), $http];
    }

    private function post(Receiver $receiver, array $payload): Response
    {
        $body = http_build_query(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        $timestamp = (string) time();

        return $receiver->handle(
            $body,
            [
                'X-Slack-Request-Timestamp' => $timestamp,
                'X-Slack-Signature' => 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", self::SECRET),
            ],
            'application/x-www-form-urlencoded; charset=utf-8',
        );
    }

    private static function fixture(string $name): array
    {
        return json_decode(
            file_get_contents(dirname(__DIR__) . '/Fixtures/interactions/' . $name . '.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
