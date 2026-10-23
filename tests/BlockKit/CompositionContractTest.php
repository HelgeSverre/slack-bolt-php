<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\BlockKit;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BlockKit\Image;
use Thunder\Bolt\BlockKit\ImageElement;
use Thunder\Bolt\BlockKit\Option;
use Thunder\Bolt\BlockKit\Picker;
use Thunder\Bolt\BlockKit\PlainTextInput;
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\BlockKit\SlackFile;
use Thunder\Bolt\BlockKit\StaticSelect;
use Thunder\Bolt\BlockKit\Text;

final class CompositionContractTest extends TestCase
{
    public function test_slack_file_images_have_one_source_and_unicode_plain_titles(): void
    {
        $image = Image::make(ImageElement::file(SlackFile::id('F123'), 'Chart'))->title('Résumé')->id('chart');
        self::assertSame(['id' => 'F123'], $image->toArray()['slack_file']);
        self::assertArrayNotHasKey('image_url', $image->toArray());
        self::assertSame('plain_text', $image->toArray()['title']['type']);
        $this->expectException(\InvalidArgumentException::class);
        (void) $image->title(Text::markdown('*Chart*'));
    }

    public function test_markdown_options_are_for_radio_and_checkboxes(): void
    {
        $option = Option::markdown('*Approve*', 'approve');
        self::assertSame('mrkdwn', Picker::radio('choice', $option)->toArray()['options'][0]['text']['type']);
        $this->expectException(\InvalidArgumentException::class);
        StaticSelect::make('choice', $option);
    }

    public function test_option_values_follow_the_pinned_75_character_limit(): void
    {
        self::assertSame(str_repeat('é', 75), Option::text('Label', str_repeat('é', 75))->toArray()['value']);
        $this->expectException(\InvalidArgumentException::class);
        Option::text('Label', str_repeat('é', 76));
    }

    public function test_section_accessories_reject_input_only_controls_and_preserve_expand_false(): void
    {
        $section = Section::text('Text')->expand(false);
        self::assertFalse($section->toArray()['expand']);
        $this->expectException(\InvalidArgumentException::class);
        (void) $section->accessory(PlainTextInput::make('input'));
    }

    public function test_section_fields_apply_the_2000_character_limit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Section::fields(Text::plain(str_repeat('a', 2001)))->toArray();
    }
}
