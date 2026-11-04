<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\BlockKit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BlockKit\Actions;
use Thunder\Bolt\BlockKit\Blocks;
use Thunder\Bolt\BlockKit\Button;
use Thunder\Bolt\BlockKit\Chart;
use Thunder\Bolt\BlockKit\ChartAxis;
use Thunder\Bolt\BlockKit\ChartPoint;
use Thunder\Bolt\BlockKit\ChartSeries;
use Thunder\Bolt\BlockKit\Container;
use Thunder\Bolt\BlockKit\DataTable;
use Thunder\Bolt\BlockKit\DataTableCell;
use Thunder\Bolt\BlockKit\DataTableRow;
use Thunder\Bolt\BlockKit\DataVisualization;
use Thunder\Bolt\BlockKit\Divider;
use Thunder\Bolt\BlockKit\ImageElement;
use Thunder\Bolt\BlockKit\Input;
use Thunder\Bolt\BlockKit\Layout;
use Thunder\Bolt\BlockKit\LayoutItem;
use Thunder\Bolt\BlockKit\LayoutRow;
use Thunder\Bolt\BlockKit\Markdown;
use Thunder\Bolt\BlockKit\PlainTextInput;
use Thunder\Bolt\BlockKit\RawBlock;
use Thunder\Bolt\BlockKit\RichText;
use Thunder\Bolt\BlockKit\RichTextSection;
use Thunder\Bolt\BlockKit\RichTextText;
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\BlockKit\Text;
use Thunder\Bolt\Messages\Attachment;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\Views\Home;
use Thunder\Bolt\Views\Modal;

final class ModernBlocksTest extends TestCase
{
    public function test_container_preserves_plain_and_rich_titles_optional_false_flags_and_immutability(): void
    {
        $base = Container::make('Release', Section::text('Ready'));
        $rich = self::rich('Deployment');
        $container = $base
            ->richTextTitle($rich)
            ->subtitle(Text::markdown('*Summary*'))
            ->icon(ImageElement::url('https://example.test/icon.png', 'Release icon'))
            ->width('full')
            ->id('release')
            ->collapsible(false)
            ->collapsed(false)
            ->headerDivider(false);

        self::assertSame(['type', 'title', 'child_blocks'], array_keys($base->toArray()));
        self::assertSame(
            [
                'type' => 'container',
                'title' => Text::plain('Release')->toArray(),
                'rich_text_title' => $rich->toArray(),
                'child_blocks' => [Section::text('Ready')->toArray()],
                'subtitle' => Text::markdown('*Summary*')->toArray(),
                'icon' => ImageElement::url('https://example.test/icon.png', 'Release icon')->toArray(),
                'block_id' => 'release',
                'width' => 'full',
                'is_collapsible' => false,
                'default_collapsed' => false,
                'has_header_divider' => false,
            ],
            $container->toArray(),
        );
        self::assertArrayNotHasKey('title', Container::make($rich)->toArray());
        self::assertSame($rich->toArray(), Container::make($rich)->toArray()['rich_text_title']);
        self::assertCount(10, Container::make('Ten', ...array_fill(0, 10, Divider::make()))->toArray()['child_blocks']);
    }

    public function test_layout_rows_widths_fallbacks_and_nested_containers_serialize_to_slack_shape(): void
    {
        $item = LayoutItem::make(Container::make('Summary', Section::text('Ready')));
        $layout = Layout::make('Deployment', LayoutRow::make($item->width('wide')->horizontalAlignment('end')))
            ->labelVisible(false)
            ->gridLines('rows')
            ->fallback(Section::text('Ready'))
            ->id('deployment');
        self::assertArrayNotHasKey('width', $item->toArray());
        self::assertSame(
            [
                'type' => 'layout',
                'label' => 'Deployment',
                'rows' => [[
                    'items' => [[
                        'block' => Container::make('Summary', Section::text('Ready'))->toArray(),
                        'width' => 'wide',
                        'horizontal_alignment' => 'end',
                    ]],
                ]],
                'is_label_visible' => false,
                'grid_lines' => 'rows',
                'fallback' => [Section::text('Ready')->toArray()],
                'block_id' => 'deployment',
            ],
            $layout->toArray(),
        );
        self::assertSame([], $layout->fallback()->toArray()['fallback']);
        self::assertCount(5, Layout::make('Five', ...array_fill(0, 5, self::row()))->toArray()['rows']);
        self::assertCount(
            3,
            LayoutRow::make(...array_fill(0, 3, LayoutItem::make(Divider::make())))->toArray()['items'],
        );
        self::assertSame('container', Container::make('Outer', Layout::make('Inner', self::row()))->toArray()['type']);
    }

    public function test_data_table_typed_cells_and_pagination_keep_zero_and_numeric_values(): void
    {
        $button = Button::make('Open', 'open')->value('task-1');
        $header = DataTableRow::make(
            DataTableCell::text('Name'),
            DataTableCell::text('Count'),
            DataTableCell::text('Action'),
        );
        $row = DataTableRow::make(
            DataTableCell::richText(self::rich('Task')),
            DataTableCell::number(0),
            DataTableCell::action($button, DataTableCell::text('Open')),
        );
        $base = DataTable::make('Tasks', $header, $row);
        $table = $base->pageSize(100)->rowHeaderColumn(0)->id('tasks');
        self::assertArrayNotHasKey('page_size', $base->toArray());
        self::assertSame(
            [
                'type' => 'data_table',
                'caption' => 'Tasks',
                'rows' => [
                    $header->toArray(),
                    [
                        self::rich('Task')->toArray(),
                        ['type' => 'raw_number', 'value' => 0, 'text' => '0'],
                        [
                            'type' => 'action_cell',
                            'element' => $button->toArray(),
                            'fallback' => ['type' => 'raw_text', 'text' => 'Open'],
                        ],
                    ],
                ],
                'page_size' => 100,
                'row_header_column_index' => 0,
                'block_id' => 'tasks',
            ],
            $table->toArray(),
        );
        self::assertSame(
            ['type' => 'raw_number', 'value' => -2.5, 'text' => '-2.5 ms'],
            DataTableCell::number(-2.5, '-2.5 ms')->toArray(),
        );
        self::assertCount(
            201,
            DataTable::make(
                'Maximum',
                self::dataRow('Header'),
                ...array_fill(0, 200, self::dataRow('Value')),
            )->toArray()['rows'],
        );
    }

    public function test_charts_preserve_category_order_and_support_all_four_variants(): void
    {
        $axis = ChartAxis::categories('Tue', 'Mon');
        $series = ChartSeries::make('Requests', ChartPoint::make('Mon', -1), ChartPoint::make('Tue', 0));
        foreach (['bar', 'area', 'line'] as $type) {
            $chart = Chart::$type($axis->xLabel('Day')->yLabel('Requests'), $series);
            self::assertSame(
                [
                    'type' => $type,
                    'series' => [[
                        'name' => 'Requests',
                        'data' => [['label' => 'Mon', 'value' => -1], ['label' => 'Tue', 'value' => 0]],
                    ]],
                    'axis_config' => ['categories' => ['Tue', 'Mon'], 'x_label' => 'Day', 'y_label' => 'Requests'],
                ],
                $chart->toArray(),
            );
        }
        self::assertSame(['categories' => ['Tue', 'Mon']], $axis->toArray());
        $pie = DataVisualization::make('Traffic', Chart::pie(ChartPoint::make('API', 75), ChartPoint::make('Web', 25)));
        self::assertSame(
            [
                'type' => 'data_visualization',
                'title' => 'Traffic',
                'chart' => [
                    'type' => 'pie',
                    'segments' => [['label' => 'API', 'value' => 75], ['label' => 'Web', 'value' => 25]],
                ],
                'block_id' => 'traffic',
            ],
            $pie->id('traffic')->toArray(),
        );
        self::assertArrayNotHasKey('block_id', $pie->toArray());
    }

    public function test_native_blocks_work_in_messages_home_and_streaming_and_have_convenience_factories(): void
    {
        $chart = Chart::pie(ChartPoint::make('API', 1));
        $container = Blocks::container('Summary', Section::text('Ready'));
        $layout = Blocks::layout('Summary', self::row());
        $table = Blocks::dataTable('Tasks', self::dataRow('Name'), self::dataRow('Task'));
        $visualization = Blocks::dataVisualization('Requests', $chart);
        $blocks = [$container, $layout, $table, $visualization];
        $expected = array_map(static fn($block) => $block->toArray(), $blocks);
        self::assertSame($expected, Message::blocks(...$blocks)->toArray()['blocks']);
        self::assertSame($expected, Home::make(...$blocks)->toArray()['blocks']);
        self::assertSame($expected, \Thunder\Bolt\Assistant\Streaming\Blocks::make(...$blocks)->toArray()['blocks']);
        self::assertSame(json_encode($expected, JSON_THROW_ON_ERROR), json_encode($blocks, JSON_THROW_ON_ERROR));
    }

    public function test_nested_input_still_requires_a_modal_submit_label(): void
    {
        $layout = Layout::make(
            'Form',
            LayoutRow::make(LayoutItem::make(Input::make('Reason', PlainTextInput::make('reason')))),
        );
        self::assertSame('modal', Modal::make('Form', $layout)->submit('Save')->toArray()['type']);
        $this->expectException(\LogicException::class);
        Modal::make('Form', $layout)->toArray();
    }

    public function test_chart_limits_accept_twelve_series_with_twenty_points_and_unicode_labels(): void
    {
        $labels = array_map(strval(...), range(1, 20));
        $points = array_map(static fn(string $label) => ChartPoint::make($label, -1), $labels);
        $series = array_map(static fn(int $i) => ChartSeries::make((string) $i, ...$points), range(1, 12));
        $chart = Chart::bar(ChartAxis::categories(...$labels), ...$series);
        self::assertCount(12, $chart->toArray()['series']);
        self::assertCount(20, $chart->toArray()['series'][0]['data']);
        self::assertSame(str_repeat('é', 20), ChartPoint::make(str_repeat('é', 20), 0)->label);
        self::assertSame(str_repeat('é', 50), DataVisualization::make(str_repeat('é', 50), $chart)->toArray()['title']);
        self::assertCount(
            12,
            Chart::pie(...array_map(
                static fn(int $i) => ChartPoint::make((string) $i, 1),
                range(1, 12),
            ))->toArray()['segments'],
        );
    }

    public function test_raw_extensions_remain_available_and_action_cell_budget_counts_its_button_label(): void
    {
        $raw = RawBlock::fromArray(['type' => 'future_block', 'extension' => false]);
        self::assertSame([$raw->toArray()], Message::blocks($raw)->toArray()['blocks']);
        $action = DataTableCell::action(Button::make('Open', 'open'), DataTableCell::text(str_repeat('x', 20000)));
        $table = DataTable::make('Tasks', self::dataRow('Action'), DataTableRow::make($action));
        self::assertSame('action_cell', Message::blocks($table)->toArray()['blocks'][0]['rows'][1][0]['type']);
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_are_rejected(\Closure $build): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $build();
    }

    public static function invalidValues(): iterable
    {
        yield 'container title' => [static fn() => Container::make(Text::markdown('No'))];
        yield 'container title length' => [static fn() => Container::make(str_repeat('é', 151))];
        yield 'container child count' => [
            static fn() => Container::make('Too many', ...array_fill(0, 11, Divider::make())),
        ];
        yield 'container child type' => [static fn() => Container::make('No', Markdown::make('No'))];
        yield 'container width' => [static fn() => Container::make('No')->width('huge')];
        yield 'layout label' => [static fn() => Layout::make('', self::row())];
        yield 'layout rows empty' => [static fn() => Layout::make('No')];
        yield 'layout rows too many' => [static fn() => Layout::make('No', ...array_fill(0, 6, self::row()))];
        yield 'layout items empty' => [static fn() => LayoutRow::make()];
        yield 'layout items too many' => [
            static fn() => LayoutRow::make(...array_fill(0, 4, LayoutItem::make(Divider::make()))),
        ];
        yield 'layout item width' => [static fn() => LayoutItem::make(Divider::make())->width('full')];
        yield 'layout item alignment' => [
            static fn() => LayoutItem::make(Divider::make())->horizontalAlignment('left'),
        ];
        yield 'layout markdown child' => [static fn() => LayoutItem::make(Markdown::make('No'))];
        yield 'layout fallback' => [
            static fn() => Layout::make('No', self::row())->fallback(Actions::make(Button::make('No', 'no'))),
        ];
        yield 'layout grid lines' => [static fn() => Layout::make('No', self::row())->gridLines('columns')];
        yield 'nesting' => [
            static fn() => Container::make('No', Layout::make(
                'No',
                LayoutRow::make(LayoutItem::make(Container::make('Third'))),
            )),
        ];
        yield 'chart row width half' => [
            static fn() => LayoutRow::make(LayoutItem::make(self::visualization()), LayoutItem::make(Divider::make())),
        ];
        yield 'large blocks together' => [
            static fn() => LayoutRow::make(
                LayoutItem::make(self::visualization())->width('wide'),
                LayoutItem::make(self::table())->width('narrow'),
            ),
        ];
        yield 'table missing data' => [static fn() => DataTable::make('No', self::dataRow('Header'))];
        yield 'table caption' => [static fn() => DataTable::make('', self::dataRow('Header'), self::dataRow('Value'))];
        yield 'table too many rows' => [
            static fn() => DataTable::make(
                'No',
                self::dataRow('Header'),
                ...array_fill(0, 201, self::dataRow('Value')),
            ),
        ];
        yield 'table not rectangular' => [
            static fn() => DataTable::make(
                'No',
                self::dataRow('Header'),
                DataTableRow::make(DataTableCell::text('A'), DataTableCell::text('B')),
            ),
        ];
        yield 'table rich header' => [
            static fn() => DataTable::make(
                'No',
                DataTableRow::make(DataTableCell::richText(self::rich('Header'))),
                self::dataRow('Value'),
            ),
        ];
        yield 'table page size' => [static fn() => self::table()->pageSize(0)];
        yield 'table row header index' => [static fn() => self::table()->rowHeaderColumn(1)];
        yield 'table columns empty' => [static fn() => DataTableRow::make()];
        yield 'table columns too many' => [
            static fn() => DataTableRow::make(...array_fill(0, 21, DataTableCell::text('No'))),
        ];
        yield 'table text empty' => [static fn() => DataTableCell::text('')];
        yield 'table nonfinite number' => [static fn() => DataTableCell::number(INF)];
        yield 'table action fallback' => [
            static fn() => DataTableCell::action(Button::make('No', 'no'), DataTableCell::richText(self::rich('No'))),
        ];
        yield 'table characters' => [
            static fn() => DataTable::make('No', self::dataRow('H'), self::dataRow(str_repeat('é', 20000))),
        ];
        yield 'pie no segments' => [static fn() => Chart::pie()];
        yield 'pie too many segments' => [static fn() => Chart::pie(...array_fill(0, 13, ChartPoint::make('API', 1)))];
        yield 'pie nonpositive segment' => [static fn() => Chart::pie(ChartPoint::make('API', 0))];
        yield 'chart nonfinite' => [static fn() => ChartPoint::make('API', NAN)];
        yield 'chart label length' => [static fn() => ChartPoint::make(str_repeat('é', 21), 0)];
        yield 'axis duplicate categories' => [static fn() => ChartAxis::categories('Mon', 'Mon')];
        yield 'axis no categories' => [static fn() => ChartAxis::categories()];
        yield 'axis too many categories' => [
            static fn() => ChartAxis::categories(...array_map(strval(...), range(1, 21))),
        ];
        yield 'axis title length' => [static fn() => ChartAxis::categories('Mon')->xLabel(str_repeat('x', 51))];
        yield 'series empty' => [static fn() => ChartSeries::make('API')];
        yield 'series duplicate points' => [
            static fn() => ChartSeries::make('API', ChartPoint::make('Mon', 1), ChartPoint::make('Mon', 2)),
        ];
        yield 'chart duplicate series' => [
            static fn() => Chart::line(ChartAxis::categories('Mon'), self::series(), self::series()),
        ];
        yield 'chart missing category' => [
            static fn() => Chart::bar(ChartAxis::categories('Mon', 'Tue'), self::series()),
        ];
        yield 'chart wrong category' => [static fn() => Chart::area(ChartAxis::categories('Tue'), self::series())];
        yield 'chart no series' => [static fn() => Chart::line(ChartAxis::categories('Mon'))];
        yield 'chart title length' => [
            static fn() => DataVisualization::make(str_repeat('x', 51), Chart::pie(ChartPoint::make('API', 1))),
        ];
        yield 'block id empty' => [static fn() => self::visualization()->id('')];
        yield 'message charts' => [
            static fn() => Message::blocks(self::visualization(), self::visualization(), self::visualization()),
        ];
        yield 'message nested charts' => [
            static fn() => Message::blocks(...array_fill(
                0,
                3,
                Layout::make('Chart', LayoutRow::make(LayoutItem::make(self::visualization()))),
            )),
        ];
        yield 'message aggregate table characters' => [
            static fn() => Message::blocks(self::largeTable(), self::largeTable()),
        ];
        yield 'attachment aggregate table characters' => [
            static fn() => Message::blocks(self::largeTable())
                ->withAttachments(Attachment::blocks(self::largeTable()))
                ->toArray(),
        ];
        yield 'attachment aggregate charts' => [
            static fn() => Message::attachments(
                Attachment::blocks(self::visualization()),
                Attachment::blocks(self::visualization()),
                Attachment::blocks(self::visualization()),
            )->toArray(),
        ];
        yield 'streaming chart limits' => [
            static fn() => \Thunder\Bolt\Assistant\Streaming\Blocks::make(
                self::visualization(),
                self::visualization(),
                self::visualization(),
            ),
        ];
        yield 'modal unsupported chart' => [static fn() => Modal::make('No', self::visualization())->toArray()];
        yield 'modal nested unsupported container' => [
            static fn() => Modal::make('No', Layout::make(
                'No',
                LayoutRow::make(LayoutItem::make(Container::make('No'))),
            ))->toArray(),
        ];
        yield 'chart too many series' => [
            static fn() => Chart::line(
                ChartAxis::categories('Mon'),
                ...array_map(
                    static fn(int $i) => ChartSeries::make((string) $i, ChartPoint::make('Mon', 1)),
                    range(1, 13),
                ),
            ),
        ];
        yield 'series too many points' => [
            static fn() => ChartSeries::make('API', ...array_map(
                static fn(int $i) => ChartPoint::make((string) $i, 1),
                range(1, 21),
            )),
        ];
        yield 'series name length' => [
            static fn() => ChartSeries::make(str_repeat('x', 21), ChartPoint::make('Mon', 1)),
        ];
        yield 'message nested block budget' => [
            static fn() => Message::blocks(...array_fill(0, 26, Container::make('Summary', Divider::make()))),
        ];
        yield 'home nested block budget' => [
            static fn() => Home::make(...array_fill(0, 51, Container::make('Summary', Divider::make())))->toArray(),
        ];
    }

    public function test_chart_row_can_use_more_than_half_the_width_and_table_budget_counts_unicode_characters(): void
    {
        $row = LayoutRow::make(
            LayoutItem::make(self::visualization())->width('wide'),
            LayoutItem::make(Divider::make())->width('narrow'),
        );
        self::assertCount(2, $row->toArray()['items']);
        self::assertSame(
            'data_table',
            DataTable::make('At limit', self::dataRow('H'), self::dataRow(str_repeat('é', 19999)))->toArray()['type'],
        );
        self::assertSame('raw_text', self::largeTable()->toArray()['rows'][0][0]['type']);
    }

    private static function rich(string $text): RichText
    {
        return RichText::make(RichTextSection::make(RichTextText::make($text)));
    }

    private static function row(): LayoutRow
    {
        return LayoutRow::make(LayoutItem::make(Divider::make()));
    }

    private static function dataRow(string $text): DataTableRow
    {
        return DataTableRow::make(DataTableCell::text($text));
    }

    private static function table(): DataTable
    {
        return DataTable::make('Tasks', self::dataRow('Name'), self::dataRow('Task'));
    }

    private static function largeTable(): DataTable
    {
        return DataTable::make('Large', self::dataRow('H'), self::dataRow(str_repeat('x', 10000)));
    }

    private static function visualization(): DataVisualization
    {
        return DataVisualization::make('Requests', Chart::pie(ChartPoint::make('API', 1)));
    }

    private static function series(): ChartSeries
    {
        return ChartSeries::make('API', ChartPoint::make('Mon', 1));
    }
}
