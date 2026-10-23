<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Authorization;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\Authorization\AuthorizationException;
use Thunder\Bolt\Authorization\AuthorizationSource;
use Thunder\Bolt\Authorization\InstallationAuthorizer;
use Thunder\Bolt\Authorization\StaticAuthorizer;
use Thunder\Bolt\OAuth\Installation;
use Thunder\Bolt\OAuth\InstallationQuery;
use Thunder\Bolt\OAuth\Stores\MemoryInstallationStore;

final class AuthorizerTest extends TestCase
{
    public function test_event_authorization_selects_the_authorized_tenant_and_only_the_matching_user_grant(): void
    {
        $store = new MemoryInstallationStore();
        $store->save(Installation::fromOAuthResponse([
            'team' => ['id' => 'T-authorized'],
            'access_token' => 'xoxb-bot',
            'bot_user_id' => 'Ubot',
            'authed_user' => ['id' => 'U-installer', 'access_token' => 'xoxp-installer'],
        ], 1_000));
        $source = AuthorizationSource::fromArray([
            'team_id' => 'T-outer',
            'enterprise_id' => 'E-outer',
            'authorizations' => [[
                'team_id' => 'T-authorized',
                'enterprise_id' => null,
                'is_enterprise_install' => false,
            ]],
            'event' => ['user' => 'U-other', 'channel' => 'C1'],
        ]);

        $authorization = new InstallationAuthorizer($store)->authorize($source);

        self::assertSame('T-authorized', $authorization->teamId);
        self::assertNull($authorization->enterpriseId);
        self::assertSame('xoxb-bot', $authorization->botToken);
        self::assertNull($authorization->userToken);
        self::assertSame('xoxb-bot', $authorization->token());
    }

    public function test_source_uses_a_view_app_installed_team_and_static_authorization_defaults_are_convenient(): void
    {
        $source = AuthorizationSource::fromArray([
            'type' => 'view_submission',
            'team' => ['id' => 'T-connect', 'enterprise_id' => 'E1'],
            'user' => ['id' => 'U1', 'team_id' => 'T-user'],
            'view' => ['app_installed_team_id' => 'T-app'],
        ]);
        $authorization = new StaticAuthorizer(new Authorization(botToken: 'xoxb-static', botUserId: 'Ubot'))->authorize(
            $source,
        );

        self::assertSame('T-app', $source->teamId);
        self::assertSame('xoxb-static', $authorization->token());
    }

    public function test_command_source_accepts_only_the_explicit_true_enterprise_flag(): void
    {
        $source = AuthorizationSource::fromArray([
            'command' => '/deploy',
            'enterprise_id' => 'E1',
            'user_id' => 'U1',
            'is_enterprise_install' => 'true',
        ]);

        self::assertTrue($source->isEnterpriseInstall);
        self::assertFalse(AuthorizationSource::fromArray([
            'command' => '/deploy',
            'team_id' => 'T1',
            'is_enterprise_install' => 'yes',
        ])->isEnterpriseInstall);
    }

    public function test_event_item_channel_has_the_same_precedence_as_bolt_source_extraction(): void
    {
        $source = AuthorizationSource::fromArray([
            'team_id' => 'T1',
            'event' => ['channel' => 'C-channel', 'channel_id' => 'C-explicit', 'item' => ['channel' => 'C-item']],
        ]);

        self::assertSame('C-item', $source->conversationId);
    }

    public function test_org_wide_view_keeps_the_context_team_but_queries_by_enterprise(): void
    {
        $source = AuthorizationSource::fromArray([
            'type' => 'view_submission',
            'is_enterprise_install' => true,
            'enterprise' => ['id' => 'E1'],
            'team' => ['id' => 'T-connect'],
            'view' => ['app_installed_team_id' => 'T-app'],
        ]);

        self::assertSame('T-app', $source->teamId);
        self::assertNull($source->query()->teamId);
        self::assertSame('E1', $source->query()->enterpriseId);
        self::assertTrue($source->query()->isEnterpriseInstall);
    }

    public function test_workspace_delete_prevents_authorization_for_its_former_users(): void
    {
        $store = new MemoryInstallationStore();
        $store->save(Installation::fromOAuthResponse([
            'team' => ['id' => 'T1'],
            'access_token' => 'xoxb-bot',
            'authed_user' => ['id' => 'U1', 'access_token' => 'xoxp-user'],
        ], 1_000));
        $store->delete(new InstallationQuery(teamId: 'T1'));

        $this->expectException(AuthorizationException::class);
        new InstallationAuthorizer($store)->authorize(AuthorizationSource::fromArray([
            'command' => '/deploy',
            'team_id' => 'T1',
            'user_id' => 'U1',
        ]));
    }
}
