<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\BlockKit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BlockKit\Option;
use Thunder\Bolt\BlockKit\Picker;

final class PickerFamiliesTest extends TestCase
{
    #[DataProvider('pickerTypes')]
    public function test_each_picker_factory_serializes_its_pinned_discriminator(
        string $expected,
        \Closure $factory,
    ): void {
        self::assertSame($expected, $factory()->toArray()['type']);
    }

    /** @return iterable<string, array{string, \Closure(): \Thunder\Bolt\BlockKit\Picker}> */
    public static function pickerTypes(): iterable
    {
        yield 'multi users' => [
            'multi_users_select',
            static fn() => \Thunder\Bolt\BlockKit\Picker::multiUsers('users'),
        ];
        yield 'multi channels' => [
            'multi_channels_select',
            static fn() => \Thunder\Bolt\BlockKit\Picker::multiChannels('channels'),
        ];
        yield 'multi conversations' => [
            'multi_conversations_select',
            static fn() => \Thunder\Bolt\BlockKit\Picker::multiConversations('conversations'),
        ];
        yield 'multi static' => [
            'multi_static_select',
            static fn() => \Thunder\Bolt\BlockKit\Picker::multiStatic('static', Option::text('One', '1')),
        ];
        yield 'multi external' => [
            'multi_external_select',
            static fn() => \Thunder\Bolt\BlockKit\Picker::multiExternal('external'),
        ];
        yield 'time' => ['timepicker', static fn() => \Thunder\Bolt\BlockKit\Picker::time('time')];
        yield 'datetime' => ['datetimepicker', static fn() => \Thunder\Bolt\BlockKit\Picker::dateTime('datetime')];
        yield 'radio' => [
            'radio_buttons',
            static fn() => \Thunder\Bolt\BlockKit\Picker::radio('radio', Option::text('One', '1')),
        ];
        yield 'overflow' => [
            'overflow',
            static fn() => \Thunder\Bolt\BlockKit\Picker::overflow('overflow', Option::text('One', '1')),
        ];
        yield 'url' => ['url_text_input', static fn() => \Thunder\Bolt\BlockKit\Picker::url('url')];
        yield 'number' => ['number_input', static fn() => \Thunder\Bolt\BlockKit\Picker::number('number')];
        yield 'file' => ['file_input', static fn() => \Thunder\Bolt\BlockKit\Picker::file('file')];
        yield 'rich text' => ['rich_text_input', static fn() => \Thunder\Bolt\BlockKit\Picker::richText('rich')];
    }

    public function test_picker_factories_cover_native_selector_and_input_families(): void
    {
        self::assertSame('users_select', \Thunder\Bolt\BlockKit\Picker::users('owner')->toArray()['type']);
        self::assertSame('channels_select', \Thunder\Bolt\BlockKit\Picker::channels('channel')->toArray()['type']);
        self::assertSame(
            'datepicker',
            \Thunder\Bolt\BlockKit\Picker::date('date')->initial('2026-10-23')->toArray()['type'],
        );
        self::assertSame(
            'checkboxes',
            \Thunder\Bolt\BlockKit\Picker::checkboxes('flags', Option::text('One', '1'))->toArray()['type'],
        );
        self::assertSame('email_text_input', \Thunder\Bolt\BlockKit\Picker::email('email')->toArray()['type']);
    }
}
