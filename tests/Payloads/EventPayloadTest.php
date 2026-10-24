<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Payloads;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Exceptions\InvalidPayload;
use Thunder\Bolt\Payloads\Events\AgentSessionStopped;
use Thunder\Bolt\Payloads\Events\AgentSessionTitleChanged;
use Thunder\Bolt\Payloads\Events\AppUninstalled;
use Thunder\Bolt\Payloads\Events\FileShared;
use Thunder\Bolt\Payloads\Events\LinkShared;
use Thunder\Bolt\Payloads\Events\MemberJoinedChannel;
use Thunder\Bolt\Payloads\Events\MemberLeftChannel;
use Thunder\Bolt\Payloads\Events\ReactionAdded;
use Thunder\Bolt\Payloads\Events\ReactionRemoved;
use Thunder\Bolt\Payloads\Events\TokensRevoked;

final class EventPayloadTest extends TestCase
{
    public function test_reaction_payloads_keep_the_item_and_optional_item_user(): void
    {
        $raw = [
            'type' => 'reaction_added',
            'user' => 'U-reactor',
            'reaction' => 'thumbsup',
            'item' => ['type' => 'message', 'channel' => 'C1', 'ts' => '1.000001'],
            'event_ts' => '1.000002',
            'future_field' => ['preserved' => true],
        ];

        $added = ReactionAdded::fromArray($raw);
        $removed = ReactionRemoved::fromArray([...$raw, 'type' => 'reaction_removed', 'item_user' => 'U-author']);

        self::assertSame('thumbsup', $added->reaction);
        self::assertNull($added->itemUserId);
        self::assertNull($added->channelId);
        self::assertNull($added->ts);
        self::assertSame($raw, $added->raw);
        self::assertSame('U-author', $removed->itemUserId);
        self::assertSame('reaction_removed', $removed->type);
    }

    public function test_channel_membership_payloads_model_optional_inviter_and_enterprise(): void
    {
        $joined = MemberJoinedChannel::fromArray([
            'type' => 'member_joined_channel',
            'user' => 'U-member',
            'channel' => 'C1',
            'channel_type' => 'C',
            'team' => 'T1',
            'inviter' => 'U-inviter',
            'enterprise' => 'E1',
        ]);
        $left = MemberLeftChannel::fromArray([
            'type' => 'member_left_channel',
            'user' => 'U-member',
            'channel' => 'C1',
            'channel_type' => 'C',
            'team' => 'T1',
        ]);

        self::assertSame('U-inviter', $joined->inviterId);
        self::assertSame('E1', $joined->enterpriseId);
        self::assertNull($left->inviterId);
        self::assertNull($left->enterpriseId);
        self::assertNull($left->eventTs);
        self::assertNull($left->ts);
    }

    public function test_link_and_file_shared_payloads_distinguish_their_documented_identifiers(): void
    {
        $link = LinkShared::fromArray([
            'type' => 'link_shared',
            'channel' => 'COMPOSER',
            'user' => 'U1',
            'message_ts' => 'composer-id',
            'links' => [['domain' => 'example.test', 'url' => 'https://example.test/work/1']],
            'source' => 'composer',
            'user_locale' => 'en-GB',
        ]);
        $file = FileShared::fromArray([
            'type' => 'file_shared',
            'channel_id' => 'C1',
            'file_id' => 'F1',
            'user_id' => 'U1',
            'file' => ['id' => 'F1', 'name' => 'report.pdf'],
            'event_ts' => '2.000001',
        ]);

        self::assertSame('composer-id', $link->messageTs);
        self::assertSame('composer', $link->source);
        self::assertSame('en-GB', $link->userLocale);
        self::assertNull($link->ts);
        self::assertNull($link->threadTs);
        self::assertSame('F1', $file->fileId);
        self::assertSame('U1', $file->userId);
        self::assertSame('C1', $file->channelId);
        self::assertNull($file->ts);
        self::assertSame('report.pdf', $file->file['name']);
    }

    public function test_lifecycle_and_agent_session_payloads_keep_optional_fields_optional(): void
    {
        $uninstalled = AppUninstalled::fromArray(['type' => 'app_uninstalled', 'future' => 'kept']);
        $revoked = TokensRevoked::fromArray([
            'type' => 'tokens_revoked',
            'tokens' => ['oauth' => ['U1'], 'bot' => []],
        ]);
        $stopped = AgentSessionStopped::fromArray([
            'type' => 'agent_session_stopped',
            'channel' => 'C1',
            'user' => 'U1',
            'event_ts' => '3.000001',
            'streaming_message_ts' => [],
        ]);
        $renamed = AgentSessionTitleChanged::fromArray([
            'type' => 'agent_session_title_changed',
            'channel' => 'C1',
            'user' => 'U1',
            'event_ts' => '3.000002',
            'title' => 'A better title',
        ]);

        self::assertSame('kept', $uninstalled->raw['future']);
        self::assertSame(['U1'], $revoked->oauthUserIds);
        self::assertSame([], $revoked->botUserIds);
        self::assertSame([], $stopped->streamingMessageTs);
        self::assertNull($stopped->ts);
        self::assertNull($stopped->threadTs);
        self::assertSame('A better title', $renamed->title);
        self::assertNull($renamed->previousTitle);
        self::assertNull($renamed->teamId);
    }

    #[DataProvider('invalidPayloads')]
    public function test_documented_required_fields_are_checked(string $payloadClass, array $body): void
    {
        $this->expectException(InvalidPayload::class);
        $payloadClass::fromArray($body);
    }

    public static function invalidPayloads(): iterable
    {
        yield 'reaction requires an item reference' => [
            ReactionAdded::class,
            ['type' => 'reaction_added', 'user' => 'U1', 'reaction' => 'wave', 'event_ts' => '1.0'],
        ];
        yield 'membership requires a team' => [
            MemberJoinedChannel::class,
            ['type' => 'member_joined_channel', 'user' => 'U1', 'channel' => 'C1', 'channel_type' => 'C'],
        ];
        yield 'link shared requires links' => [
            LinkShared::class,
            ['type' => 'link_shared', 'channel' => 'C1', 'user' => 'U1', 'message_ts' => '1.0', 'links' => []],
        ];
        yield 'file shared requires a nested file ID' => [
            FileShared::class,
            [
                'type' => 'file_shared',
                'channel_id' => 'C1',
                'file_id' => 'F1',
                'user_id' => 'U1',
                'file' => [],
                'event_ts' => '1.0',
            ],
        ];
        yield 'tokens revoked requires ID lists' => [
            TokensRevoked::class,
            ['type' => 'tokens_revoked', 'tokens' => ['oauth' => 'U1']],
        ];
        yield 'stop event requires a streaming timestamp list' => [
            AgentSessionStopped::class,
            ['type' => 'agent_session_stopped', 'channel' => 'C1', 'user' => 'U1', 'event_ts' => '1.0'],
        ];
        yield 'title event requires a title' => [
            AgentSessionTitleChanged::class,
            ['type' => 'agent_session_title_changed', 'channel' => 'C1', 'user' => 'U1', 'event_ts' => '1.0'],
        ];
    }
}
