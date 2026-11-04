<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

/** Discoverable factories for Slack's specialist block families. */
final class Blocks
{
    public static function container(string|Text|RichText $title, Block ...$children): Container
    {
        return Container::make($title, ...$children);
    }

    public static function layout(string $label, LayoutRow ...$rows): Layout
    {
        return Layout::make($label, ...$rows);
    }

    public static function dataTable(string $caption, DataTableRow $header, DataTableRow ...$rows): DataTable
    {
        return DataTable::make($caption, $header, ...$rows);
    }

    public static function dataVisualization(string $title, Chart $chart): DataVisualization
    {
        return DataVisualization::make($title, $chart);
    }

    public static function alert(string|Text $text, string $level = 'default'): Alert
    {
        return Alert::make($text, $level);
    }

    public static function card(): Card
    {
        return Card::make();
    }

    public static function carousel(Card ...$cards): Carousel
    {
        return Carousel::make(...$cards);
    }

    public static function contextActions(FeedbackButtons|IconButton ...$elements): ContextActions
    {
        return ContextActions::make(...$elements);
    }

    public static function file(string $externalId, string $source = 'remote'): File
    {
        return File::make($externalId, $source);
    }

    public static function markdown(string $text): Markdown
    {
        return Markdown::make($text);
    }

    public static function plan(string $title, TaskCard ...$tasks): Plan
    {
        return Plan::make($title, ...$tasks);
    }

    public static function table(TableRow ...$rows): Table
    {
        return Table::make(...$rows);
    }

    public static function taskCard(string $taskId, string $title, string $status): TaskCard
    {
        return TaskCard::make($taskId, $title, $status);
    }

    public static function video(string|Text $title, string $videoUrl, string $thumbnailUrl, string $altText): Video
    {
        return Video::make($title, $videoUrl, $thumbnailUrl, $altText);
    }
}
