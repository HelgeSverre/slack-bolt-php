<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\BlockKit;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BlockKit\Context;
use Thunder\Bolt\BlockKit\Divider;
use Thunder\Bolt\BlockKit\ExternalSelect;
use Thunder\Bolt\BlockKit\Header;
use Thunder\Bolt\BlockKit\Image;
use Thunder\Bolt\BlockKit\ImageElement;
use Thunder\Bolt\BlockKit\Input;
use Thunder\Bolt\BlockKit\Option;
use Thunder\Bolt\BlockKit\PlainTextInput;
use Thunder\Bolt\BlockKit\RawBlock;
use Thunder\Bolt\BlockKit\RawElement;
use Thunder\Bolt\BlockKit\RichText;
use Thunder\Bolt\BlockKit\StaticSelect;
use Thunder\Bolt\BlockKit\Text;

final class BuilderTest extends TestCase
{
    public function test_composes_common_message_and_modal_blocks_immutably(): void
    {
        $input = PlainTextInput::make('title')->multiline()->placeholder('Describe');
        $block = Input::make('Title', $input)->id('summary')->optional();
        self::assertSame('input', $block->toArray()['type']);
        self::assertSame('plain_text_input', $block->toArray()['element']['type']);
        self::assertSame(
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => 'Request']],
            Header::make('Request')->toArray(),
        );
        self::assertSame(['type' => 'divider'], Divider::make()->toArray());
        self::assertSame(
            'context',
            Context::make(
                Text::markdown('*Updated*'),
                ImageElement::url('https://example.test/a.png', 'A'),
            )->toArray()['type'],
        );
        self::assertSame(
            'image',
            Image::make(ImageElement::url('https://example.test/a.png', 'A'))->title('Image')->toArray()['type'],
        );
    }

    public function test_selects_and_extension_points_keep_the_slack_wire_shape(): void
    {
        $select = StaticSelect::make('priority', Option::text('High', 'high'))
            ->placeholder('Choose')
            ->initial(Option::text('High', 'high'));
        self::assertSame('static_select', $select->toArray()['type']);
        self::assertSame('external_select', ExternalSelect::make('owner')->minQueryLength(2)->toArray()['type']);
        self::assertSame(
            'rich_text',
            RichText::fromElements([[
                'type' => 'rich_text_section',
                'elements' => [['type' => 'text', 'text' => 'Hi']],
            ]])->toArray()['type'],
        );
        self::assertSame(
            'video',
            RawBlock::fromArray([
                'type' => 'video',
                'title' => ['type' => 'plain_text', 'text' => 'Demo'],
            ])->toArray()['type'],
        );
        self::assertSame(
            'datepicker',
            RawElement::fromArray(['type' => 'datepicker', 'action_id' => 'date'])->toArray()['type'],
        );
    }
}
