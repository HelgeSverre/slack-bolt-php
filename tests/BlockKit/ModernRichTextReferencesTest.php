<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\BlockKit;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BlockKit\RichText;
use Thunder\Bolt\BlockKit\RichTextMessageMention;
use Thunder\Bolt\BlockKit\RichTextSection;
use Thunder\Bolt\BlockKit\RichTextTag;
use Thunder\Bolt\BlockKit\RichTextTagColor;
use Thunder\Bolt\BlockKit\RichTextWorkObjectMention;

final class ModernRichTextReferencesTest extends TestCase
{
    public function test_modern_references_compose_as_native_rich_text_leaves(): void
    {
        $block = RichText::make(RichTextSection::make(
            RichTextTag::make('In progress')->blue()->bold()->highlight()->unlink(),
            RichTextMessageMention::for('C123', '1720710212.123456')
                ->author('U123')
                ->thread('1720710000.000001')
                ->text('Original request')
                ->url('https://example.test/messages/1')
                ->clientHighlight()
                ->underline(),
            RichTextWorkObjectMention::for('E123', 'A123', 'Deploy release', 'https://work.example.test/deploy/42')
                ->icon('https://work.example.test/icon.png')
                ->fullSizePreview(false)
                ->product('Thunder Work')
                ->italic()
                ->strike(),
        ))->id('references');

        self::assertSame(
            [
                'type' => 'rich_text',
                'elements' => [[
                    'type' => 'rich_text_section',
                    'elements' => [
                        [
                            'type' => 'tag',
                            'text' => 'In progress',
                            'color' => 'blue',
                            'style' => ['bold' => true, 'highlight' => true, 'unlink' => true],
                        ],
                        [
                            'type' => 'message_mention',
                            'channel_id' => 'C123',
                            'message_ts' => '1720710212.123456',
                            'author_id' => 'U123',
                            'thread_ts' => '1720710000.000001',
                            'text' => 'Original request',
                            'url' => 'https://example.test/messages/1',
                            'style' => ['underline' => true, 'client_highlight' => true],
                        ],
                        [
                            'type' => 'work_object_mention',
                            'entity_id' => 'E123',
                            'app_id' => 'A123',
                            'text' => 'Deploy release',
                            'url' => 'https://work.example.test/deploy/42',
                            'icon_url' => 'https://work.example.test/icon.png',
                            'full_size_preview_enabled' => false,
                            'product_name' => 'Thunder Work',
                            'style' => ['italic' => true, 'strike' => true],
                        ],
                    ],
                ]],
                'block_id' => 'references',
            ],
            $block->toArray(),
        );
        self::assertSame($block->toArray(), $block->jsonSerialize());
    }

    public function test_tag_color_helpers_are_immutable_and_cover_the_native_color_enum(): void
    {
        $tag = RichTextTag::make('Ready');
        $blue = $tag->blue();

        self::assertSame(['type' => 'tag', 'text' => 'Ready'], $tag->toArray());
        self::assertSame(['type' => 'tag', 'text' => 'Ready', 'color' => 'blue'], $blue->toArray());
        self::assertSame(
            ['type' => 'tag', 'text' => 'Ready', 'color' => 'red'],
            $tag->color(RichTextTagColor::Red)->toArray(),
        );
    }

    public function test_modern_reference_leaves_keep_json_serialization_in_lockstep(): void
    {
        $leaves = [
            RichTextTag::make('Ready')->green(),
            RichTextMessageMention::for('C123', '1720710212.123456'),
            RichTextWorkObjectMention::for('E123', 'A123', 'Deploy', 'https://work.example.test/42'),
        ];

        foreach ($leaves as $leaf) {
            self::assertSame($leaf->toArray(), $leaf->jsonSerialize());
        }
    }

    public function test_modern_reference_leaves_reject_missing_required_values_and_invalid_urls(): void
    {
        $invalid = [
            static fn() => RichTextTag::make(''),
            static fn() => RichTextMessageMention::for('', '1720710212.123456'),
            static fn() => RichTextMessageMention::for('C123', ''),
            static fn() => RichTextMessageMention::for('C123', '1720710212.123456')->url('not a URL'),
            static fn() => RichTextWorkObjectMention::for('', 'A123', 'Deploy', 'https://work.example.test/42'),
            static fn() => RichTextWorkObjectMention::for('E123', 'A123', '', 'https://work.example.test/42'),
            static fn() => RichTextWorkObjectMention::for('E123', 'A123', 'Deploy', 'relative/path'),
            static fn() => RichTextWorkObjectMention::for(
                'E123',
                'A123',
                'Deploy',
                'https://work.example.test/42',
            )->icon('not a URL'),
        ];

        foreach ($invalid as $build) {
            try {
                $build();
                self::fail('Expected an invalid modern rich-text reference to be rejected.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_modern_reference_leaves_reject_the_unsupported_code_style(): void
    {
        $this->expectException(\LogicException::class);

        (void) RichTextTag::make('Ready')->code();
    }
}
