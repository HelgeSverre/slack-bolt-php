<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Payloads;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\Values\FormState;
use Thunder\Bolt\Payloads\Values\InputValue;
use Thunder\Bolt\Payloads\Values\UploadedFile;
use Thunder\Bolt\Payloads\View;

final class FormStateTest extends TestCase
{
    #[DataProvider('controls')]
    public function test_actions_and_views_share_the_same_typed_reader(
        array $cell,
        string $method,
        mixed $expected,
    ): void {
        $values = ['block' => ['input' => $cell]];
        $action = Action::fromArray([
            'type' => 'block_actions',
            'actions' => [$cell + ['action_id' => 'input']],
            'state' => ['values' => $values],
        ]);
        $view = View::fromArray([
            'type' => 'view_submission',
            'view' => ['callback_id' => 'form', 'state' => ['values' => $values]],
        ]);
        foreach ([
            $action->input(),
            $action->form()->require('block', 'input'),
            $view->form()->require('block', 'input'),
        ] as $input) {
            self::assertSame($cell['type'], $input->type);
            self::assertSame($expected, $input->{$method}());
        }
        self::assertSame($cell, $view->state('block', 'input'));
        self::assertSame(
            array_key_exists('value', $cell) && (is_string($cell['value']) || $cell['value'] === null)
                ? $cell['value']
                : $cell,
            $view->value('block', 'input'),
        );
        self::assertSame($cell + ['action_id' => 'input'], $action->action);
    }

    public static function controls(): iterable
    {
        foreach (['plain_text_input', 'email_text_input', 'url_text_input', 'number_input', 'button'] as $type) {
            yield $type => [['type' => $type, 'value' => '0'], 'text', '0'];
        }
        yield 'empty text' => [['type' => 'plain_text_input', 'value' => ''], 'text', ''];
        yield 'cleared text' => [['type' => 'plain_text_input', 'value' => null], 'text', null];
        foreach (['static_select', 'external_select', 'overflow', 'radio_buttons'] as $type) {
            yield $type => [
                [
                    'type' => $type,
                    'selected_option' => ['value' => 'choice', 'text' => ['type' => 'plain_text', 'text' => 'Choice']],
                ],
                'selectedValue',
                'choice',
            ];
        }
        yield 'deselected radio' => [['type' => 'radio_buttons', 'selected_option' => null], 'selectedValue', null];
        foreach ([
            'users_select' => 'user',
            'channels_select' => 'channel',
            'conversations_select' => 'conversation',
        ] as $type => $field) {
            yield $type => [['type' => $type, 'selected_' . $field => 'ID1'], 'selectedValue', 'ID1'];
        }
        foreach (['multi_static_select', 'multi_external_select', 'checkboxes', 'select'] as $type) {
            yield $type => [
                ['type' => $type, 'selected_options' => [['value' => 'a'], ['value' => 'b']]],
                'selectedValues',
                ['a', 'b'],
            ];
        }
        foreach ([
            'multi_users_select' => 'users',
            'multi_channels_select' => 'channels',
            'multi_conversations_select' => 'conversations',
        ] as $type => $field) {
            yield $type => [
                ['type' => $type, 'selected_' . $field => ['ID1', 'ID2']],
                'selectedValues',
                ['ID1', 'ID2'],
            ];
        }
        yield 'empty checkboxes' => [['type' => 'checkboxes', 'selected_options' => []], 'selectedValues', []];
        yield 'date' => [['type' => 'datepicker', 'selected_date' => '2024-02-29'], 'date', '2024-02-29'];
        yield 'invalid date' => [['type' => 'datepicker', 'selected_date' => '2025-02-29'], 'date', null];
        yield 'cleared date' => [['type' => 'datepicker', 'selected_date' => null], 'date', null];
        yield 'time' => [['type' => 'timepicker', 'selected_time' => '23:59'], 'time', '23:59'];
        yield 'invalid time' => [['type' => 'timepicker', 'selected_time' => '24:00'], 'time', null];
        yield 'datetime' => [
            ['type' => 'datetimepicker', 'selected_date_time' => 1_700_000_000],
            'timestamp',
            1_700_000_000,
        ];
        yield 'rich text' => [
            [
                'type' => 'rich_text_input',
                'rich_text_value' => [
                    'type' => 'rich_text',
                    'elements' => [['type' => 'future_section', 'new' => true]],
                ],
            ],
            'richText',
            ['type' => 'rich_text', 'elements' => [['type' => 'future_section', 'new' => true]]],
        ];
    }

    public function test_files_have_typed_metadata_and_keep_unknown_fields(): void
    {
        $file = [
            'id' => 'F123',
            'name' => 'report.txt',
            'title' => 'Report',
            'mimetype' => 'text/plain',
            'size' => 42,
            'url_private' => 'https://files.slack.com/private',
            'future' => ['x' => true],
        ];
        $cell = ['type' => 'file_input', 'files' => [$file]];
        $input = View::fromArray([
            'view' => ['state' => ['values' => ['upload' => ['file' => $cell]]]],
        ])->form()->require('upload', 'file');
        $upload = $input->files()[0];
        self::assertInstanceOf(UploadedFile::class, $upload);
        self::assertSame('F123', $upload->id);
        self::assertSame('report.txt', $upload->name);
        self::assertSame('Report', $upload->title);
        self::assertSame('text/plain', $upload->mimeType);
        self::assertSame(42, $upload->size);
        self::assertSame('https://files.slack.com/private', $upload->privateUrl);
        self::assertSame($file, $upload->raw);
        self::assertSame($cell, $input->raw);
        self::assertNull(UploadedFile::fromArray(['id' => 'F1'])->name);
        $this->expectException(\InvalidArgumentException::class);
        UploadedFile::fromArray([]);
    }

    public function test_unknown_controls_missing_cells_and_cleared_values_remain_distinct(): void
    {
        $raw = ['type' => 'future_control', 'new_value' => ['x' => 42]];
        $form = FormState::fromArray([
            'block' => ['future' => $raw, 'cleared' => ['type' => 'plain_text_input', 'value' => null]],
        ]);
        self::assertNull($form->get('absent', 'input'));
        self::assertNull($form->get('block', 'absent'));
        self::assertSame($raw, $form->require('block', 'future')->raw);
        self::assertSame('future_control', $form->require('block', 'future')->type);
        self::assertNull($form->require('block', 'future')->text());
        self::assertNull($form->require('block', 'cleared')->text());
        self::assertArrayHasKey('value', $form->require('block', 'cleared')->raw);
        $this->expectException(\OutOfBoundsException::class);
        $form->require('block', 'missing');
    }

    public function test_action_state_prefers_top_level_state_and_preserves_first_action_matching(): void
    {
        $first = ['type' => 'button', 'action_id' => 'first', 'value' => 'one'];
        $action = Action::fromArray([
            'type' => 'block_actions',
            'actions' => [$first, ['type' => 'button', 'action_id' => 'second', 'value' => 'two']],
            'state' => ['values' => []],
            'view' => ['state' => ['values' => ['x' => ['y' => $first]]]],
        ]);
        self::assertSame('first', $action->actionId);
        self::assertSame('one', $action->input()->text());
        self::assertSame($first, $action->action);
        self::assertNull($action->form()->get('x', 'y'));
        $fallback = Action::fromArray(['view' => ['state' => ['values' => ['x' => ['y' => $first]]]]]);
        self::assertSame('one', $fallback->form()->require('x', 'y')->text());
    }

    public function test_malformed_cells_are_safe_to_read_without_coercing_types(): void
    {
        $form = new FormState(['bad' => 'string', 'block' => ['bad' => false]]);
        self::assertNull($form->get('bad', 'input'));
        self::assertNull($form->get('block', 'bad'));
        $bad = new InputValue([
            'type' => false,
            'value' => 42,
            'selected_date' => [],
            'selected_time' => false,
            'selected_date_time' => '123',
            'rich_text_value' => 'string',
            'files' => [['id' => null], ['id' => 'F1', 'size' => '42'], 'string'],
        ]);
        self::assertNull($bad->type);
        foreach (['text', 'selectedValue', 'date', 'time', 'timestamp', 'richText'] as $method) {
            self::assertNull($bad->{$method}());
        }
        self::assertSame([], $bad->selectedValues());
        self::assertCount(1, $bad->files());
        self::assertNull($bad->files()[0]->size);
        self::assertSame(
            ['valid'],
            new InputValue([
                'type' => 'checkboxes',
                'selected_options' => [['value' => false], ['value' => 'valid']],
            ])->selectedValues(),
        );
        self::assertSame(
            [],
            new InputValue(['type' => 'multi_users_select', 'selected_users' => ['bad' => 'U1']])->selectedValues(),
        );
    }
}
