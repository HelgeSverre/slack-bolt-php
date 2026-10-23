<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\BlockKit;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BlockKit\Confirm;
use Thunder\Bolt\BlockKit\FeedbackButtons;
use Thunder\Bolt\BlockKit\IconButton;
use Thunder\Bolt\BlockKit\RichText;
use Thunder\Bolt\BlockKit\RichTextBroadcastMention;
use Thunder\Bolt\BlockKit\RichTextChannelMention;
use Thunder\Bolt\BlockKit\RichTextColor;
use Thunder\Bolt\BlockKit\RichTextDate;
use Thunder\Bolt\BlockKit\RichTextEmoji;
use Thunder\Bolt\BlockKit\RichTextLink;
use Thunder\Bolt\BlockKit\RichTextList;
use Thunder\Bolt\BlockKit\RichTextPreformatted;
use Thunder\Bolt\BlockKit\RichTextQuote;
use Thunder\Bolt\BlockKit\RichTextSection;
use Thunder\Bolt\BlockKit\RichTextTeamMention;
use Thunder\Bolt\BlockKit\RichTextText;
use Thunder\Bolt\BlockKit\RichTextUsergroupMention;
use Thunder\Bolt\BlockKit\RichTextUserMention;
use Thunder\Bolt\BlockKit\URLSourceElement;
use Thunder\Bolt\BlockKit\WorkflowButton;

final class SpecialistElementsTest extends TestCase
{
    public function test_feedback_buttons_emit_two_accessible_plain_text_buttons(): void
    {
        $buttons = FeedbackButtons::make('Helpful', 'yes', 'Not helpful', 'no', 'feedback')
            ->positiveAccessibilityLabel('This helped')
            ->negativeAccessibilityLabel('This did not help');

        self::assertSame(
            [
                'type' => 'feedback_buttons',
                'action_id' => 'feedback',
                'positive_button' => [
                    'text' => ['type' => 'plain_text', 'text' => 'Helpful'],
                    'value' => 'yes',
                    'accessibility_label' => 'This helped',
                ],
                'negative_button' => [
                    'text' => ['type' => 'plain_text', 'text' => 'Not helpful'],
                    'value' => 'no',
                    'accessibility_label' => 'This did not help',
                ],
            ],
            $buttons->toArray(),
        );
    }

    public function test_icon_and_workflow_buttons_keep_all_specialist_fields(): void
    {
        $confirm = Confirm::make('Continue?', 'This launches a workflow.');

        self::assertSame(
            [
                'type' => 'icon_button',
                'icon' => 'trash',
                'text' => ['type' => 'plain_text', 'text' => 'Delete'],
                'action_id' => 'delete',
                'accessibility_label' => 'Delete this item',
                'value' => 'item-1',
                'visible_to_user_ids' => ['U1', 'U2'],
                'confirm' => $confirm->toArray(),
            ],
            IconButton::make('trash', 'Delete', 'delete')
                ->accessibilityLabel('Delete this item')
                ->value('item-1')
                ->visibleTo('U1', 'U2')
                ->confirm($confirm)
                ->toArray(),
        );

        self::assertSame(
            [
                'type' => 'workflow_button',
                'text' => ['type' => 'plain_text', 'text' => 'Start request'],
                'workflow' => [
                    'trigger' => [
                        'url' => 'https://slack.com/trigger/T123',
                        'customizable_input_parameters' => [['name' => 'requester', 'value' => 'U1']],
                    ],
                ],
                'style' => 'primary',
                'accessibility_label' => 'Start a request workflow',
                'confirm' => $confirm->toArray(),
            ],
            WorkflowButton::make('Start request', 'https://slack.com/trigger/T123')
                ->input('requester', 'U1')
                ->primary()
                ->accessibilityLabel('Start a request workflow')
                ->confirm($confirm)
                ->toArray(),
        );
    }

    public function test_url_source_is_a_dedicated_task_card_composition_object(): void
    {
        self::assertSame(
            [
                'type' => 'url',
                'url' => 'https://docs.slack.dev/reference',
                'text' => 'Slack reference',
            ],
            URLSourceElement::make('https://docs.slack.dev/reference', 'Slack reference')->toArray(),
        );
    }

    public function test_typed_rich_text_leaves_and_containers_preserve_slack_wire_shapes(): void
    {
        $section = RichTextSection::make(
            RichTextText::make('Hello')->bold()->italic(),
            RichTextLink::to('https://example.test', 'Example')->underline(),
            RichTextEmoji::named('wave')->unicode('1f44b'),
            RichTextBroadcastMention::here(),
            RichTextChannelMention::for('C123'),
            RichTextUserMention::for('U123'),
            RichTextUsergroupMention::for('S123'),
            RichTextTeamMention::for('T123'),
            RichTextColor::hex('#0f5cff'),
            RichTextDate::at(1_729_679_200, '{date_short}')->fallback('October 23')->code(),
        );

        $block = RichText::make(
            $section,
            RichTextList::ordered($section)->indent(2)->border(),
            RichTextQuote::make(RichTextText::make('Quoted'))->border(false),
            RichTextPreformatted::make(RichTextText::make('php '), RichTextLink::to('https://php.net', 'PHP')),
        )->id('typed-rich-text');

        self::assertSame('rich_text', $block->toArray()['type']);
        self::assertSame('typed-rich-text', $block->toArray()['block_id']);
        self::assertSame('rich_text_section', $block->toArray()['elements'][0]['type']);
        self::assertSame(['bold' => true, 'italic' => true], $block->toArray()['elements'][0]['elements'][0]['style']);
        self::assertSame('link', $block->toArray()['elements'][0]['elements'][1]['type']);
        self::assertSame('emoji', $block->toArray()['elements'][0]['elements'][2]['type']);
        self::assertSame('broadcast', $block->toArray()['elements'][0]['elements'][3]['type']);
        self::assertSame('channel', $block->toArray()['elements'][0]['elements'][4]['type']);
        self::assertSame('user', $block->toArray()['elements'][0]['elements'][5]['type']);
        self::assertSame('usergroup', $block->toArray()['elements'][0]['elements'][6]['type']);
        self::assertSame('team', $block->toArray()['elements'][0]['elements'][7]['type']);
        self::assertSame('color', $block->toArray()['elements'][0]['elements'][8]['type']);
        self::assertSame('date', $block->toArray()['elements'][0]['elements'][9]['type']);
        self::assertSame('rich_text_list', $block->toArray()['elements'][1]['type']);
        self::assertSame('ordered', $block->toArray()['elements'][1]['style']);
        self::assertSame(1, $block->toArray()['elements'][1]['border']);
        self::assertSame('rich_text_quote', $block->toArray()['elements'][2]['type']);
        self::assertSame(0, $block->toArray()['elements'][2]['border']);
        self::assertSame('rich_text_preformatted', $block->toArray()['elements'][3]['type']);
    }

    public function test_specialist_builders_reject_missing_required_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeedbackButtons::make('', 'Helpful', 'yes', 'No', 'no');
    }
}
