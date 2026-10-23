<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Messages;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BlockKit\Actions;
use Thunder\Bolt\BlockKit\Button;
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\Messages\Attachment;
use Thunder\Bolt\Messages\AttachmentField;
use Thunder\Bolt\Messages\AttachmentMarkdown;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\Messages\MessageMetadata;
use Thunder\Bolt\Messages\Parse;
use Thunder\Bolt\WebApi\Requests\ChatPostMessage;

final class MessageTest extends TestCase
{
    public function test_serializes_plain_and_rich_messages_with_explicit_fallback(): void
    {
        self::assertSame(['text' => 'Hello'], Message::text('Hello')->toArray());
        $button = Button::make('Approve', 'approve')->value('ticket-42')->primary();
        $message = Message::blocks(Section::markdown('*Ready*'), Actions::make($button))->fallbackText(
            'Ready. Approve ticket 42.',
        );
        self::assertSame(
            [
                'text' => 'Ready. Approve ticket 42.',
                'blocks' => [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*Ready*']],
                    [
                        'type' => 'actions',
                        'elements' => [[
                            'type' => 'button',
                            'text' => ['type' => 'plain_text', 'text' => 'Approve'],
                            'action_id' => 'approve',
                            'value' => 'ticket-42',
                            'style' => 'primary',
                        ]],
                    ],
                ],
            ],
            json_decode(json_encode($message, JSON_THROW_ON_ERROR), true),
        );
    }

    public function test_message_and_button_chaining_preserves_reusable_originals(): void
    {
        $original = Message::blocks(Section::text('Original'));
        $modified = $original
            ->fallbackText('Accessible')
            ->inThread('1700000000.000001')
            ->addBlock(Section::text('Another'));
        self::assertArrayNotHasKey('text', $original->toArray());
        self::assertArrayNotHasKey('thread_ts', $original->toArray());
        self::assertCount(1, $original->toArray()['blocks']);
        self::assertCount(2, $modified->toArray()['blocks']);
        $button = Button::make('Delete', 'delete');
        $danger = $button->danger();
        self::assertArrayNotHasKey('style', $button->toArray());
        self::assertSame('danger', $danger->toArray()['style']);
    }

    public function test_replace_and_append_have_distinct_behavior(): void
    {
        $original = Message::blocks(Section::text('A'), Section::text('B'));
        self::assertCount(1, $original->withBlocks(Section::text('C'))->toArray()['blocks']);
        self::assertCount(3, $original->addBlock(Section::text('C'))->toArray()['blocks']);
    }

    public function test_limits_count_unicode_characters_instead_of_bytes(): void
    {
        self::assertSame(str_repeat('é', 3000), Section::text(str_repeat('é', 3000))->toArray()['text']['text']);
        $this->expectException(\InvalidArgumentException::class);
        Section::text(str_repeat('é', 3001));
    }

    public function test_rejects_empty_message_blocks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Message::blocks();
    }

    public function test_rejects_fallback_text_on_a_plain_message(): void
    {
        $this->expectException(\LogicException::class);
        (void) Message::text('Visible')->fallbackText('Other');
    }

    public function test_rejects_a_section_inside_an_actions_block(): void
    {
        $this->expectException(\TypeError::class);
        Actions::make(Section::text('Not an element'));
    }

    public function test_serializes_message_delivery_options_without_an_array_escape_hatch(): void
    {
        $message = Message::text('Review <#C123> by <@U123>')
            ->inThread('1700000000.000001')
            ->replyBroadcast()
            ->metadata('review.completed', ['review_id' => 42, 'assignees' => [['id' => 'U123']]])
            ->unfurlLinks(false)
            ->unfurlMedia(false)
            ->parse(Parse::None)
            ->linkNames()
            ->mrkdwn(false)
            ->username('Review bot')
            ->iconEmoji(':white_check_mark:');

        self::assertSame(
            [
                'text' => 'Review <#C123> by <@U123>',
                'thread_ts' => '1700000000.000001',
                'reply_broadcast' => true,
                'metadata' => [
                    'event_type' => 'review.completed',
                    'event_payload' => ['review_id' => 42, 'assignees' => [['id' => 'U123']]],
                ],
                'unfurl_links' => false,
                'unfurl_media' => false,
                'parse' => 'none',
                'link_names' => true,
                'mrkdwn' => false,
                'username' => 'Review bot',
                'icon_emoji' => ':white_check_mark:',
                'as_user' => false,
            ],
            $message->toArray(),
        );
    }

    public function test_serializes_markdown_messages_and_legacy_attachments_with_native_builders(): void
    {
        self::assertSame(
            ['markdown_text' => '**A streamed-looking message**'],
            Message::markdown('**A streamed-looking message**')->toArray(),
        );

        $attachment = Attachment::text('A deployment is ready.')
            ->fallbackText('Deployment ready')
            ->color('good')
            ->pretext('Release')
            ->author('Thunder', 'https://thunder.example.test', 'https://thunder.example.test/icon.png')
            ->authorSubname('Automation')
            ->title('Release 42', 'https://thunder.example.test/releases/42')
            ->fields(
                AttachmentField::make('Environment', 'Production')->short(),
                AttachmentField::make('Commit', 'abc123')->short(),
            )
            ->image('https://thunder.example.test/release.png')
            ->footer('Thunder CI', 'https://thunder.example.test/footer.png')
            ->timestamp(1700000000)
            ->markdownIn(AttachmentMarkdown::Pretext, AttachmentMarkdown::Text, AttachmentMarkdown::Fields)
            ->callbackId('release-42')
            ->appUnfurl('https://thunder.example.test/releases/42')
            ->app('A123')
            ->bot('B123');

        self::assertSame(
            [
                'attachments' => [[
                    'fallback' => 'Deployment ready',
                    'color' => 'good',
                    'pretext' => 'Release',
                    'author_name' => 'Thunder',
                    'author_link' => 'https://thunder.example.test',
                    'author_icon' => 'https://thunder.example.test/icon.png',
                    'author_subname' => 'Automation',
                    'title' => 'Release 42',
                    'title_link' => 'https://thunder.example.test/releases/42',
                    'text' => 'A deployment is ready.',
                    'fields' => [
                        ['title' => 'Environment', 'value' => 'Production', 'short' => true],
                        ['title' => 'Commit', 'value' => 'abc123', 'short' => true],
                    ],
                    'image_url' => 'https://thunder.example.test/release.png',
                    'footer' => 'Thunder CI',
                    'footer_icon' => 'https://thunder.example.test/footer.png',
                    'ts' => '1700000000',
                    'mrkdwn_in' => ['pretext', 'text', 'fields'],
                    'callback_id' => 'release-42',
                    'app_unfurl_url' => 'https://thunder.example.test/releases/42',
                    'is_app_unfurl' => true,
                    'app_id' => 'A123',
                    'bot_id' => 'B123',
                ]],
            ],
            Message::attachments($attachment)->toArray(),
        );
    }

    public function test_attachment_image_and_thumbnail_are_mutually_exclusive_and_messages_are_immutable(): void
    {
        $image = Attachment::text('Release')->image('https://thunder.example.test/image.png');
        $thumbnail = $image->thumbnail('https://thunder.example.test/thumb.png');

        self::assertArrayHasKey('image_url', $image->toArray());
        self::assertArrayNotHasKey('thumb_url', $image->toArray());
        self::assertArrayNotHasKey('image_url', $thumbnail->toArray());
        self::assertSame('https://thunder.example.test/thumb.png', $thumbnail->toArray()['thumb_url']);
    }

    public function test_chat_post_message_keeps_all_message_fields_when_it_adds_its_destination(): void
    {
        $message = Message::attachments(Attachment::fallback('Release ready')->color('good'))
            ->metadata('release.ready', ['release' => ['id' => '42']])
            ->unfurlLinks(false)
            ->replyBroadcast();

        self::assertSame(
            [
                'channel' => 'C123',
                'attachments' => [['fallback' => 'Release ready', 'color' => 'good']],
                'reply_broadcast' => true,
                'metadata' => ['event_type' => 'release.ready', 'event_payload' => ['release' => ['id' => '42']]],
                'unfurl_links' => false,
                'thread_ts' => '1700000000.000001',
            ],
            new ChatPostMessage('C123', $message, '1700000000.000001')->toArray(),
        );
    }

    public function test_metadata_rejects_values_outside_slacks_event_metadata_shape(): void
    {
        self::assertSame(
            ['event_type' => 'review.updated', 'event_payload' => ['review' => ['id' => '42', 'approved' => true]]],
            MessageMetadata::event('review.updated', ['review' => ['id' => '42', 'approved' => true]])->toArray(),
        );

        $this->expectException(\InvalidArgumentException::class);
        MessageMetadata::event('review.updated', ['review' => ['nested' => ['not' => 'allowed']]]);
    }

    public function test_authorship_fields_follow_slacks_exclusive_modes(): void
    {
        $message = Message::text('Hello')->iconEmoji(':wave:')->iconUrl('https://thunder.example.test/icon.png');
        self::assertSame(
            ['text' => 'Hello', 'icon_url' => 'https://thunder.example.test/icon.png', 'as_user' => false],
            $message->toArray(),
        );

        $this->expectException(\LogicException::class);
        (void) Message::text('Hello')->username('Thunder')->asUser();
    }
}
