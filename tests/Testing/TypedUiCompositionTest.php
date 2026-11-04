<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Testing;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BlockKit\RichText;
use Thunder\Bolt\BlockKit\RichTextMessageMention;
use Thunder\Bolt\BlockKit\RichTextSection;
use Thunder\Bolt\BlockKit\RichTextTag;
use Thunder\Bolt\BlockKit\RichTextWorkObjectMention;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\Shortcut;
use Thunder\Bolt\Responses\ActionResponse;
use Thunder\Bolt\Responses\ShortcutResponse;
use Thunder\Bolt\Testing\BoltTest;
use Thunder\Bolt\Testing\InteractionFixture;
use Thunder\Bolt\Views\Modal;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\Requests\ChatPostEphemeral;
use Thunder\Bolt\WebApi\Requests\ChatUpdate;
use Thunder\Bolt\WebApi\Requests\ViewRequest;

final class TypedUiCompositionTest extends TestCase
{
    public function test_shortcut_fixture_opens_a_native_modal_with_rich_text_references(): void
    {
        $bolt = BoltTest::make();
        $bolt->app->shortcut('show_references', static function (Shortcut $shortcut, Client $client): ShortcutResponse {
            $references = RichText::make(RichTextSection::make(
                RichTextTag::make('In progress')->blue(),
                RichTextMessageMention::for('C_TEST', '1700000000.000001'),
                RichTextWorkObjectMention::for(
                    'entity-42',
                    'A_TEST',
                    'Deployment',
                    'https://example.test/deployments/42',
                ),
            ));
            $client
                ->views()
                ->open(ViewRequest::open(
                    $shortcut->triggerId ?? throw new \LogicException('Missing trigger.'),
                    Modal::make('References', $references),
                ));
            return ShortcutResponse::empty();
        });

        $bolt
            ->interaction(InteractionFixture::shortcut('show_references'))
            ->assertAcknowledged()
            ->assertApiCalledWith(
                'views.open',
                [
                    'trigger_id' => 'TRIGGER_TEST',
                    'view' => [
                        'type' => 'modal',
                        'blocks' => [[
                            'type' => 'rich_text',
                            'elements' => [[
                                'elements' => [
                                    ['type' => 'tag', 'text' => 'In progress', 'color' => 'blue'],
                                    ['type' => 'message_mention', 'channel_id' => 'C_TEST'],
                                    ['type' => 'work_object_mention', 'entity_id' => 'entity-42', 'app_id' => 'A_TEST'],
                                ],
                            ]],
                        ]],
                    ],
                ],
                times: 1,
            );
    }

    public function test_action_fixture_drives_native_update_and_private_reply_requests(): void
    {
        $bolt = BoltTest::make();
        $bolt->app->action('approve', static function (Action $action, Client $client): ActionResponse {
            $channel = $action->channelId ?? throw new \LogicException('Missing channel.');
            $timestamp = $action->messageTs ?? throw new \LogicException('Missing message.');
            $user = $action->userId ?? throw new \LogicException('Missing user.');
            $client
                ->chat()
                ->update(new ChatUpdate($channel, $timestamp, Message::text('Approved.')->inThread('999.000001')));
            $client
                ->chat()
                ->postEphemeral(new ChatPostEphemeral($channel, $user, 'Saved for you.', threadTs: $action->threadTs));
            return ActionResponse::empty();
        });

        $result = $bolt
            ->interaction(
                InteractionFixture::action('approve')
                    ->fromUser('U_ADA')
                    ->inChannel('C_RELEASE', '171.000002', '171.000001'),
            )
            ->assertApiCalledWith(
                'chat.update',
                ['channel' => 'C_RELEASE', 'ts' => '171.000002', 'text' => 'Approved.'],
                times: 1,
            )
            ->assertApiCalledWith(
                'chat.postEphemeral',
                ['channel' => 'C_RELEASE', 'user' => 'U_ADA', 'thread_ts' => '171.000001'],
                times: 1,
            );
        self::assertArrayNotHasKey('thread_ts', $result->calls()[0]->arguments);
    }
}
