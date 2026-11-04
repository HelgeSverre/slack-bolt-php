<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\BlockKit;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BlockKit\Alert;
use Thunder\Bolt\BlockKit\Blocks;
use Thunder\Bolt\BlockKit\Card;
use Thunder\Bolt\BlockKit\Carousel;
use Thunder\Bolt\BlockKit\ContextActions;
use Thunder\Bolt\BlockKit\FeedbackButtons;
use Thunder\Bolt\BlockKit\File;
use Thunder\Bolt\BlockKit\IconButton;
use Thunder\Bolt\BlockKit\Markdown;
use Thunder\Bolt\BlockKit\Plan;
use Thunder\Bolt\BlockKit\RichText;
use Thunder\Bolt\BlockKit\Table;
use Thunder\Bolt\BlockKit\TableCell;
use Thunder\Bolt\BlockKit\TableColumn;
use Thunder\Bolt\BlockKit\TableRow;
use Thunder\Bolt\BlockKit\TaskCard;
use Thunder\Bolt\BlockKit\URLSourceElement;
use Thunder\Bolt\BlockKit\Video;

final class SpecialistBlocksTest extends TestCase
{
    public function test_video_requires_and_serializes_the_pinned_fields(): void
    {
        $video = Video::make('Demo', 'https://example.test/demo', 'https://example.test/thumb.png', 'Demo video')
            ->titleUrl('https://example.test/watch')
            ->author('Thunder')
            ->provider('Example', 'https://example.test/icon.png')
            ->description('A short demo.');

        self::assertSame(
            [
                'type' => 'video',
                'video_url' => 'https://example.test/demo',
                'thumbnail_url' => 'https://example.test/thumb.png',
                'alt_text' => 'Demo video',
                'title' => ['type' => 'plain_text', 'text' => 'Demo', 'emoji' => true],
                'title_url' => 'https://example.test/watch',
                'author_name' => 'Thunder',
                'provider_name' => 'Example',
                'provider_icon_url' => 'https://example.test/icon.png',
                'description' => ['type' => 'plain_text', 'text' => 'A short demo.', 'emoji' => true],
            ],
            $video->toArray(),
        );
    }

    public function test_file_markdown_and_alert_use_native_required_values(): void
    {
        self::assertSame(
            ['type' => 'file', 'external_id' => 'F123', 'source' => 'remote'],
            File::remote('F123')->toArray(),
        );
        self::assertSame(['type' => 'markdown', 'text' => '# A response'], Markdown::make('# A response')->toArray());
        self::assertSame(
            [
                'type' => 'alert',
                'text' => ['type' => 'plain_text', 'text' => 'Watch out', 'emoji' => true],
                'level' => 'warning',
            ],
            Alert::make('Watch out')->warning()->toArray(),
        );
    }

    public function test_table_uses_typed_cells_rows_and_column_settings(): void
    {
        $table = Table::make(
            TableRow::make(TableCell::text('Name'), TableCell::text('Status')),
            TableRow::make(
                TableCell::text('Deploy'),
                TableCell::richText(RichText::fromElements([
                    'type' => 'rich_text_section',
                    'elements' => [['type' => 'text', 'text' => 'Complete']],
                ])),
            ),
        )->columns(TableColumn::make('left', true), TableColumn::make('right'));

        self::assertSame('table', $table->toArray()['type']);
        self::assertSame(['type' => 'raw_text', 'text' => 'Name'], $table->toArray()['rows'][0][0]);
        self::assertSame('rich_text', $table->toArray()['rows'][1][1]['type']);
        self::assertSame(
            [
                ['align' => 'left', 'is_wrapped' => true],
                ['align' => 'right'],
            ],
            $table->toArray()['column_settings'],
        );
    }

    public function test_card_carousel_and_context_actions_are_typed_and_immutable(): void
    {
        $card = Card::make()->title('*Build*')->body('Passed');
        self::assertSame('card', $card->toArray()['type']);
        self::assertSame('mrkdwn', $card->toArray()['title']['type']);
        self::assertSame('carousel', Carousel::make($card)->toArray()['type']);

        $actions = ContextActions::make(
            FeedbackButtons::make('Useful', 'yes', 'Not useful', 'no', 'feedback'),
            IconButton::make('trash', 'Delete', 'delete')->accessibilityLabel('Delete item'),
        );
        self::assertSame('context_actions', $actions->toArray()['type']);
        self::assertSame('feedback_buttons', $actions->toArray()['elements'][0]['type']);
        self::assertSame('icon_button', $actions->toArray()['elements'][1]['type']);
    }

    public function test_task_cards_plans_and_discoverable_factories_preserve_the_wire_shape(): void
    {
        $details = RichText::fromElements([
            'type' => 'rich_text_section',
            'elements' => [['type' => 'text', 'text' => 'Working']],
        ]);
        $task = TaskCard::make('task-1', 'Implement blocks', 'in_progress')
            ->details($details)
            ->sources(URLSourceElement::make('https://docs.slack.dev', 'Slack docs'));
        $plan = Plan::make('Release', $task);

        self::assertSame('task_card', $task->toArray()['type']);
        self::assertSame('url', $task->toArray()['sources'][0]['type']);
        self::assertSame($task->toArray(), $plan->toArray()['tasks'][0]);
        self::assertInstanceOf(Video::class, Blocks::video(
            'Demo',
            'https://example.test/video',
            'https://example.test/thumb',
            'Demo',
        ));
        self::assertInstanceOf(Plan::class, Blocks::plan('Release', $task));
        self::assertInstanceOf(TaskCard::class, Blocks::taskCard('task-2', 'Ship', 'pending'));
    }

    public function test_specialist_factories_reject_invalid_pinned_constraints(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Video::make('Demo', 'http://example.test/video', 'https://example.test/thumb', 'Demo');
    }
}
