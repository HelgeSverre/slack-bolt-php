<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Payloads;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\Options;
use Thunder\Bolt\Payloads\Shortcut;
use Thunder\Bolt\Payloads\View;

final class InteractionPayloadTest extends TestCase
{
    public function test_action_uses_only_the_first_action_and_view_callback(): void
    {
        $payload = Action::fromArray([
            'type' => 'block_actions',
            'user' => ['id' => 'U1'],
            'channel' => ['id' => 'C1'],
            'container' => ['message_ts' => '100.001'],
            'view' => ['callback_id' => 'modal_callback'],
            'callback_id' => 'legacy_callback',
            'actions' => [
                ['action_id' => 'first', 'block_id' => 'block', 'value' => 'one'],
                ['action_id' => 'second', 'block_id' => 'other'],
            ],
        ]);

        self::assertSame('first', $payload->actionId);
        self::assertSame('block', $payload->blockId);
        self::assertSame('modal_callback', $payload->callbackId);
        self::assertSame('U1', $payload->userId);
        self::assertSame('C1', $payload->channelId);
        self::assertSame('100.001', $payload->messageTs);
        self::assertSame(['action_id' => 'first', 'block_id' => 'block', 'value' => 'one'], $payload->action);
    }

    public function test_global_shortcut_does_not_invent_message_context(): void
    {
        $payload = Shortcut::fromArray([
            'type' => 'shortcut',
            'callback_id' => 'open_modal',
            'trigger_id' => 'trigger',
            'user' => ['id' => 'U1'],
        ]);

        self::assertSame('open_modal', $payload->callbackId);
        self::assertSame('U1', $payload->userId);
        self::assertNull($payload->channelId);
        self::assertNull($payload->messageTs);
        self::assertNull($payload->responseUrl);
    }

    public function test_options_prefers_the_callback_from_its_embedded_view(): void
    {
        $payload = Options::fromArray([
            'type' => 'block_suggestion',
            'action_id' => 'assignee',
            'block_id' => 'assignment',
            'callback_id' => 'legacy',
            'view' => ['callback_id' => 'modal'],
            'value' => 'ali',
            'user' => ['id' => 'U1'],
        ]);

        self::assertSame('modal', $payload->callbackId);
        self::assertSame('ali', $payload->value);
        self::assertSame('U1', $payload->userId);
        self::assertNull($payload->channelId);
    }

    public function test_a_present_view_does_not_fall_back_to_a_top_level_callback(): void
    {
        $action = Action::fromArray([
            'type' => 'block_actions',
            'view' => ['id' => 'V1'],
            'callback_id' => 'legacy',
            'actions' => [['action_id' => 'approve']],
        ]);
        $options = Options::fromArray([
            'type' => 'block_suggestion',
            'view' => ['id' => 'V1'],
            'callback_id' => 'legacy',
            'value' => 'a',
        ]);

        self::assertNull($action->callbackId);
        self::assertNull($options->callbackId);
    }

    public function test_view_exposes_text_values_and_non_text_state_cells(): void
    {
        $payload = View::fromArray([
            'type' => 'view_submission',
            'user' => ['id' => 'U1'],
            'view' => [
                'id' => 'V1',
                'callback_id' => 'ticket',
                'state' => [
                    'values' => [
                        'title' => ['input' => ['type' => 'plain_text_input', 'value' => 'Hello']],
                        'assignee' => ['select' => ['type' => 'static_select', 'selected_option' => ['value' => 'U2']]],
                    ],
                ],
            ],
        ]);

        self::assertSame('V1', $payload->viewId);
        self::assertSame('ticket', $payload->callbackId);
        self::assertSame('Hello', $payload->value('title', 'input'));
        self::assertSame(
            ['type' => 'static_select', 'selected_option' => ['value' => 'U2']],
            $payload->value('assignee', 'select'),
        );
        self::assertNull($payload->value('missing', 'input'));
    }
}
