<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Responses;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BlockKit\Option;
use Thunder\Bolt\BlockKit\OptionGroup;
use Thunder\Bolt\Responses\ActionResponse;
use Thunder\Bolt\Responses\OptionsResponse;
use Thunder\Bolt\Responses\ShortcutResponse;
use Thunder\Bolt\Responses\ViewResponse;

final class InteractionResponseTest extends TestCase
{
    public function test_action_responses_serialize_empty_message_and_legacy_errors(): void
    {
        self::assertSame([], ActionResponse::empty()->toArray());
        self::assertSame(['text' => 'Updated'], ActionResponse::message('Updated')->toArray());
        self::assertSame(
            [
                'errors' => [
                    ['name' => 'email', 'error' => 'Invalid address'],
                    ['name' => 'owner', 'error' => 'Required'],
                ],
            ],
            ActionResponse::errors(['email' => 'Invalid address', 'owner' => 'Required'])->toArray(),
        );
    }

    public function test_action_response_preserves_advanced_legacy_interactive_message_fields(): void
    {
        $body = [
            'response_type' => 'in_channel',
            'text' => 'Deployment finished',
            'attachments' => [['color' => 'good', 'text' => 'api']],
            'replace_original' => true,
            'delete_original' => false,
        ];

        self::assertSame($body, ActionResponse::fromArray($body)->toArray());
    }

    public function test_options_support_modern_options_groups_and_legacy_escape_hatch(): void
    {
        $alice = Option::text('Alice', 'U1')->description('Platform team');
        $group = OptionGroup::make('People', $alice);

        self::assertSame(
            [
                'options' => [[
                    'text' => ['type' => 'plain_text', 'text' => 'Alice'],
                    'value' => 'U1',
                    'description' => ['type' => 'plain_text', 'text' => 'Platform team'],
                ]],
            ],
            OptionsResponse::options($alice)->toArray(),
        );
        self::assertSame(
            [
                'option_groups' => [[
                    'label' => ['type' => 'plain_text', 'text' => 'People'],
                    'options' => [[
                        'text' => ['type' => 'plain_text', 'text' => 'Alice'],
                        'value' => 'U1',
                        'description' => ['type' => 'plain_text', 'text' => 'Platform team'],
                    ]],
                ]],
            ],
            OptionsResponse::groups($group)->toArray(),
        );
        self::assertSame(
            ['options' => [['label' => 'Legacy', 'value' => 'legacy']]],
            OptionsResponse::fromArray([
                'options' => [['label' => 'Legacy', 'value' => 'legacy']],
            ])->toArray(),
        );
        self::assertSame(
            ['options' => [['text' => 'Interactive legacy', 'value' => 'legacy-text']]],
            OptionsResponse::fromArray([
                'options' => [['text' => 'Interactive legacy', 'value' => 'legacy-text']],
            ])->toArray(),
        );
        self::assertSame(['options' => []], OptionsResponse::empty()->toArray());
    }

    public function test_options_reject_mixed_or_oversized_families(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OptionsResponse::fromArray(['options' => [], 'option_groups' => []]);
    }

    public function test_options_reject_more_than_one_hundred_choices(): void
    {
        $option = Option::text('Choice', 'choice');

        $this->expectException(\InvalidArgumentException::class);
        OptionsResponse::options(...array_fill(0, 101, $option));
    }

    public function test_view_and_shortcut_acknowledgements_use_slack_response_shapes(): void
    {
        self::assertSame([], ShortcutResponse::empty()->toArray());
        self::assertEquals(
            ['response_action' => 'errors', 'errors' => (object) ['title' => 'Required']],
            ViewResponse::errors(['title' => 'Required'])->toArray(),
        );
        self::assertSame('{"response_action":"errors","errors":{"0":"Required"}}', json_encode(
            ViewResponse::errors(['0' => 'Required'])->toArray(),
            JSON_THROW_ON_ERROR,
        ));
        self::assertSame(
            ['response_action' => 'update', 'view' => ['type' => 'modal', 'blocks' => []]],
            ViewResponse::update(['type' => 'modal', 'blocks' => []])->toArray(),
        );
        self::assertSame(
            ['response_action' => 'push', 'view' => ['type' => 'modal', 'blocks' => []]],
            ViewResponse::push(['type' => 'modal', 'blocks' => []])->toArray(),
        );
        self::assertSame(['response_action' => 'clear'], ViewResponse::clear()->toArray());
    }
}
