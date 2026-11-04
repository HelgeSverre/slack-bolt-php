<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\BlockKit;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BlockKit\Actions;
use Thunder\Bolt\BlockKit\Button;
use Thunder\Bolt\BlockKit\Confirm;
use Thunder\Bolt\BlockKit\ExternalSelect;
use Thunder\Bolt\BlockKit\Input;
use Thunder\Bolt\BlockKit\Option;
use Thunder\Bolt\BlockKit\OptionGroup;
use Thunder\Bolt\BlockKit\Picker;
use Thunder\Bolt\BlockKit\PlainTextInput;
use Thunder\Bolt\BlockKit\RawElement;
use Thunder\Bolt\BlockKit\RichText;
use Thunder\Bolt\BlockKit\StaticSelect;
use Thunder\Bolt\BlockKit\Text;

final class ControlsTest extends TestCase
{
    public function test_button_actions_and_input_serialize_all_common_control_fields(): void
    {
        $confirm = Confirm::make('Remove?', 'This cannot be undone.', 'Remove', 'Keep')->danger();
        self::assertSame('primary', Confirm::make('Continue?', 'Continue?')->primary()->toArray()['style']);
        self::assertSame(
            [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Remove'],
                'action_id' => 'remove',
                'value' => '42',
                'style' => 'danger',
                'url' => 'https://example.test/remove/42',
                'confirm' => $confirm->toArray(),
                'accessibility_label' => 'Remove request 42',
            ],
            Button::make('Remove', 'remove')
                ->value('42')
                ->danger()
                ->url('https://example.test/remove/42')
                ->confirm($confirm)
                ->accessibilityLabel('Remove request 42')
                ->toArray(),
        );

        self::assertSame(
            'actions-id',
            Actions::make(Button::make('Go', 'go'))->id('actions-id')->toArray()['block_id'],
        );
        self::assertSame(
            [
                'type' => 'input',
                'block_id' => 'details',
                'label' => ['type' => 'plain_text', 'text' => 'Details', 'emoji' => true],
                'element' => ['type' => 'plain_text_input', 'action_id' => 'details'],
                'hint' => ['type' => 'plain_text', 'text' => 'Visible only to your team', 'emoji' => true],
                'optional' => true,
                'dispatch_action' => true,
            ],
            Input::make('Details', PlainTextInput::make('details'))
                ->id('details')
                ->hint('Visible only to your team')
                ->optional()
                ->dispatchAction()
                ->toArray(),
        );
    }

    public function test_static_and_external_selects_keep_their_distinct_slack_shapes(): void
    {
        $one = Option::text('One', '1');
        $two = Option::text('Two', '2');
        $confirm = Confirm::make('Continue?', 'Choose a value.');

        self::assertSame(
            [
                'type' => 'static_select',
                'action_id' => 'priority',
                'option_groups' => [OptionGroup::make('Choices', $one, $two)->toArray()],
                'placeholder' => ['type' => 'plain_text', 'text' => 'Choose', 'emoji' => true],
                'initial_option' => $one->toArray(),
                'confirm' => $confirm->toArray(),
                'focus_on_load' => true,
            ],
            StaticSelect::groups('priority', OptionGroup::make('Choices', $one, $two))
                ->placeholder('Choose')
                ->initial($one)
                ->confirm($confirm)
                ->focusOnLoad()
                ->toArray(),
        );

        self::assertSame(
            [
                'type' => 'external_select',
                'action_id' => 'owner',
                'placeholder' => ['type' => 'plain_text', 'text' => 'Find a person', 'emoji' => true],
                'min_query_length' => 2,
                'initial_option' => $one->toArray(),
                'confirm' => $confirm->toArray(),
                'focus_on_load' => true,
            ],
            ExternalSelect::make('owner')
                ->placeholder('Find a person')
                ->minQueryLength(2)
                ->initial($one)
                ->confirm($confirm)
                ->focusOnLoad()
                ->toArray(),
        );
    }

    public function test_picker_serializes_selectors_dates_files_and_input_families(): void
    {
        $confirm = Confirm::make('Continue?', 'Continue with the selected value?');
        self::assertSame(
            [
                'type' => 'multi_conversations_select',
                'action_id' => 'rooms',
                'placeholder' => ['type' => 'plain_text', 'text' => 'Choose rooms', 'emoji' => true],
                'confirm' => $confirm->toArray(),
                'focus_on_load' => true,
                'initial_conversations' => ['C1', 'C2'],
                'max_selected_items' => 2,
                'default_to_current_conversation' => true,
                'filter' => [
                    'include' => ['public', 'private'],
                    'exclude_external_shared_channels' => true,
                    'exclude_bot_users' => true,
                ],
            ],
            Picker::multiConversations('rooms')
                ->placeholder('Choose rooms')
                ->confirm($confirm)
                ->focusOnLoad()
                ->initialItems('C1', 'C2')
                ->maxItems(2)
                ->defaultToCurrentConversation()
                ->conversationTypes('public', 'private')
                ->excludeExternalSharedChannels()
                ->excludeBotUsers()
                ->toArray(),
        );

        self::assertSame('2026-10-23', Picker::date('due')->initial('2026-10-23')->toArray()['initial_date']);
        self::assertSame(
            '09:15',
            Picker::time('time')->initial('09:15')->timezone('Europe/Oslo')->toArray()['initial_time'],
        );
        self::assertSame(
            1_793_491_200,
            Picker::dateTime('when')->initial(1_793_491_200)->toArray()['initial_date_time'],
        );
        self::assertSame(
            [
                'type' => 'file_input',
                'action_id' => 'attachments',
                'filetypes' => ['pdf', 'png'],
                'max_files' => 3,
            ],
            Picker::file('attachments')->fileTypes('pdf', 'png', 'pdf')->maxFiles(3)->toArray(),
        );
        self::assertSame(
            [
                'type' => 'number_input',
                'action_id' => 'amount',
                'is_decimal_allowed' => true,
                'min_value' => '-1.5',
                'max_value' => '12.75',
                'dispatch_action_config' => ['trigger_actions_on' => ['on_character_entered']],
            ],
            Picker::number('amount')
                ->allowDecimals()
                ->range(-1.5, '12.75')
                ->dispatchOn('on_character_entered')
                ->toArray(),
        );

        $rich = RichText::fromElements([
            'type' => 'rich_text_section',
            'elements' => [['type' => 'text', 'text' => 'Hi']],
        ]);
        self::assertSame(
            [
                'type' => 'rich_text_input',
                'action_id' => 'body',
                'initial_value' => $rich->toArray(),
                'min_lines' => 2,
                'max_lines' => 8,
            ],
            Picker::richText('body')->initialRichText($rich)->visibleLines(2, 8)->toArray(),
        );
    }

    public function test_controls_reject_invalid_values_and_unsupported_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (void) Button::make('Go', 'go')->url('/relative');
    }

    public function test_controls_reject_non_plain_input_labels(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Input::make(Text::markdown('*No*'), PlainTextInput::make('field'));
    }

    public function test_actions_only_accepts_controls_allowed_on_slack_action_blocks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Actions::make(RawElement::fromArray(['type' => 'file_input', 'action_id' => 'upload']));
    }
}
