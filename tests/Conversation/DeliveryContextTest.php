<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Conversation;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\Authorization\AuthorizationSource;
use Thunder\Bolt\Conversation\DeliveryContext;

final class DeliveryContextTest extends TestCase
{
    public function test_mention_root_uses_the_event_timestamp_as_a_thread_root(): void
    {
        $context = $this->context([
            'type' => 'event_callback',
            'team_id' => 'T1',
            'event' => ['type' => 'app_mention', 'channel' => 'C1', 'ts' => '1700000000.000001'],
        ]);

        self::assertNotNull($context->conversation);
        self::assertNotNull($context->thread);
        self::assertSame('C1', $context->conversation->conversationId);
        self::assertSame('T1', $context->conversation->teamId);
        self::assertNull($context->conversation->threadTs);
        self::assertSame('1700000000.000001', $context->thread->threadTs);
    }

    public function test_event_wrapper_without_an_outer_type_still_uses_the_inner_event(): void
    {
        $body = [
            'team_id' => 'T1',
            'event' => ['type' => 'app_mention', 'channel' => 'C1', 'ts' => '1700000000.000001'],
        ];
        $context = $this->context($body);

        self::assertNotNull($context->thread);
        self::assertSame('1700000000.000001', $context->thread->threadTs);
    }

    public function test_nested_message_lifecycle_events_use_their_explicit_message_root(): void
    {
        $changed = $this->context([
            'type' => 'event_callback',
            'team_id' => 'T1',
            'event' => [
                'type' => 'message',
                'subtype' => 'message_changed',
                'channel' => 'C1',
                'message' => ['ts' => '2.000002', 'thread_ts' => '1.000001'],
            ],
        ]);
        $replied = $this->context([
            'type' => 'event_callback',
            'team_id' => 'T1',
            'event' => [
                'type' => 'message',
                'subtype' => 'message_replied',
                'channel' => 'C1',
                'message' => ['ts' => '3.000003'],
            ],
        ]);
        $deleted = $this->context([
            'type' => 'event_callback',
            'team_id' => 'T1',
            'event' => [
                'type' => 'message',
                'subtype' => 'message_deleted',
                'channel' => 'C1',
                'previous_message' => ['ts' => '5.000005', 'thread_ts' => '4.000004'],
            ],
        ]);

        self::assertSame('1.000001', $changed->thread?->threadTs);
        self::assertSame('3.000003', $replied->thread?->threadTs);
        self::assertSame('4.000004', $deleted->thread?->threadTs);
    }

    public function test_message_interactions_can_use_a_message_or_container_root_after_source_identifies_a_conversation(): void
    {
        $action = $this->context([
            'type' => 'block_actions',
            'team' => ['id' => 'T1'],
            'channel' => ['id' => 'C1'],
            'message' => ['ts' => '2.000002', 'thread_ts' => '1.000001'],
        ]);
        $shortcut = $this->context([
            'type' => 'message_action',
            'team' => ['id' => 'T1'],
            'channel' => ['id' => 'C1'],
            'container' => ['message_ts' => '6.000006'],
        ]);

        self::assertSame('1.000001', $action->thread?->threadTs);
        self::assertSame('6.000006', $shortcut->thread?->threadTs);
    }

    public function test_reactions_and_non_message_interactions_do_not_invent_context(): void
    {
        $reaction = $this->context([
            'type' => 'event_callback',
            'team_id' => 'T1',
            'event' => ['type' => 'reaction_added', 'item' => ['channel' => 'C1', 'ts' => '2.000002']],
        ]);
        $threadedReaction = $this->context([
            'type' => 'event_callback',
            'team_id' => 'T1',
            'event' => [
                'type' => 'reaction_added',
                'item' => ['channel' => 'C1', 'ts' => '2.000002'],
                'thread_ts' => '1.000001',
            ],
        ]);
        $globalShortcut = $this->context([
            'type' => 'shortcut',
            'team' => ['id' => 'T1'],
            'channel_id' => 'C-stray',
            'channel' => ['id' => 'C-stray'],
            'container' => ['channel_id' => 'C1', 'message_ts' => '2.000002'],
        ]);
        $view = $this->context([
            'type' => 'view_submission',
            'team' => ['id' => 'T1'],
            'channel_id' => 'C-stray',
            'channel' => ['id' => 'C-stray'],
            'view' => ['private_metadata' => 'C1', 'response_urls' => [['channel_id' => 'C1']]],
        ]);

        self::assertSame('C1', $reaction->conversation?->conversationId);
        self::assertNull($reaction->thread);
        self::assertSame('1.000001', $threadedReaction->thread?->threadTs);
        self::assertNull($globalShortcut->conversation);
        self::assertNull($globalShortcut->thread);
        self::assertNull($view->conversation);
        self::assertNull($view->thread);
        self::assertNull(AuthorizationSource::fromArray([
            'type' => 'shortcut',
            'team' => ['id' => 'T1'],
            'channel_id' => 'C-stray',
            'channel' => ['id' => 'C-stray'],
        ])->conversationId);
        self::assertNull(AuthorizationSource::fromArray([
            'type' => 'view_submission',
            'team' => ['id' => 'T1'],
            'channel_id' => 'C-stray',
            'channel' => ['id' => 'C-stray'],
        ])->conversationId);
    }

    public function test_grid_keys_partition_by_enterprise_without_splitting_contextual_workspaces(): void
    {
        $body = [
            'type' => 'event_callback',
            'team_id' => 'T-source',
            'enterprise_id' => 'E-source',
            'event' => ['type' => 'app_mention', 'channel' => 'C1', 'ts' => '1.000001'],
        ];
        $source = AuthorizationSource::fromArray($body);
        $context = DeliveryContext::fromArray(
            $body,
            $source,
            new Authorization(teamId: 'T-authorized', enterpriseId: 'E-authorized', isEnterpriseInstall: true),
        );
        $sameEnterprise = DeliveryContext::fromArray(
            $body,
            $source,
            new Authorization(teamId: 'T-another-context', enterpriseId: 'E-authorized', isEnterpriseInstall: true),
        );
        $otherEnterprise = DeliveryContext::fromArray(
            $body,
            $source,
            new Authorization(teamId: 'T-another-context', enterpriseId: 'E-other', isEnterpriseInstall: true),
        );

        self::assertNotNull($context->conversation);
        self::assertNotNull($context->thread);
        self::assertNull($context->conversation->teamId);
        self::assertSame('E-authorized', $context->conversation->enterpriseId);
        self::assertTrue($context->conversation->isEnterpriseInstall);
        self::assertSame('1.000001', $context->thread->threadTs);
        self::assertSame($context->conversation->key(), $sameEnterprise->conversation?->key());
        self::assertSame($context->thread->key(), $sameEnterprise->thread?->key());
        self::assertNotSame($context->conversation->key(), $otherEnterprise->conversation?->key());
        self::assertNotSame($context->thread->key(), $otherEnterprise->thread?->key());
    }

    public function test_authorization_source_supplies_the_tenant_when_authorization_has_no_identity(): void
    {
        $body = [
            'type' => 'event_callback',
            'team_id' => 'T-connect',
            'enterprise_id' => 'E-grid',
            'event' => ['type' => 'app_mention', 'channel' => 'C1', 'ts' => '1.000001'],
        ];
        $source = AuthorizationSource::fromArray($body);
        $context = DeliveryContext::fromArray($body, $source, new Authorization());

        self::assertNotNull($context->conversation);
        self::assertSame('T-connect', $context->conversation->teamId);
        self::assertSame('E-grid', $context->conversation->enterpriseId);
        self::assertFalse($context->conversation->isEnterpriseInstall);
    }

    /** @param array<string, mixed> $body */
    private function context(array $body): DeliveryContext
    {
        return DeliveryContext::fromArray(
            $body,
            AuthorizationSource::fromArray($body),
            new Authorization(teamId: 'T1'),
        );
    }
}
