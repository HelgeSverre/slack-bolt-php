<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\OAuth;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\OAuth\Installation;
use Thunder\Bolt\OAuth\InstallationQuery;
use Thunder\Bolt\OAuth\Stores\FileInstallationStore;
use Thunder\Bolt\OAuth\Stores\MemoryInstallationStore;

final class InstallationStoreTest extends TestCase
{
    public function test_oauth_response_keeps_independent_bot_and_user_grants(): void
    {
        $installation = Installation::fromOAuthResponse([
            'team' => ['id' => 'T1'],
            'access_token' => 'xoxb-bot',
            'refresh_token' => 'bot-refresh',
            'expires_in' => 600,
            'scope' => 'chat:write,commands',
            'bot_id' => 'B1',
            'bot_user_id' => 'Ubot',
            'authed_user' => [
                'id' => 'U1',
                'access_token' => 'xoxp-user',
                'refresh_token' => 'user-refresh',
                'expires_in' => 300,
                'scope' => 'search:read',
            ],
        ], 1_000);

        self::assertSame('xoxb-bot', $installation->botToken);
        self::assertSame(1_600, $installation->botTokenExpiresAt);
        self::assertSame(['chat:write', 'commands'], $installation->botScopes);
        self::assertSame('xoxp-user', $installation->userToken);
        self::assertSame(1_300, $installation->userTokenExpiresAt);
        self::assertSame(['search:read'], $installation->userScopes);
    }

    public function test_memory_store_separates_the_current_workspace_bot_from_user_credentials(): void
    {
        $store = new MemoryInstallationStore();
        $installation = $this->installation();
        $store->save($installation);
        $workspace = new InstallationQuery(teamId: 'T1');

        self::assertSame('xoxb-bot', $store->fetch($workspace)->botToken);
        self::assertSame('xoxp-user', $store->fetch($workspace->forUser('U1'))->userToken);
        self::assertNull($store->fetch($workspace->forUser('U2')));
    }

    public function test_file_store_uses_hashed_names_private_records_and_atomic_update(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'thunder-bolt-' . bin2hex(random_bytes(8));
        try {
            $store = new FileInstallationStore($directory);
            $query = new InstallationQuery(teamId: 'T1');
            $store->save($this->installation());
            $updated = $store->update($query->forBot(), static fn(Installation $record): Installation => $record->withBotOAuthResponse(
                [
                    'access_token' => 'xoxb-new',
                    'refresh_token' => 'bot-refresh-new',
                    'expires_in' => 600,
                ],
                2_000,
            ));

            self::assertSame('xoxb-new', $updated->botToken);
            self::assertSame('xoxb-new', $store->fetch($query)->botToken);
            $files = glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
            self::assertCount(2, $files);
            foreach ($files as $file) {
                self::assertMatchesRegularExpression('/\/[a-f0-9]{64}-[a-f0-9]{64}\.json$/', $file);
                self::assertSame(0600, fileperms($file) & 0777);
            }
            $store->delete($query);
            self::assertNull($store->fetch($query));
            self::assertNull($store->fetch($query->forUser('U1')));
        } finally {
            if (is_dir($directory)) {
                foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        }
    }

    public function test_workspace_delete_removes_its_bot_and_all_user_credentials(): void
    {
        $store = new MemoryInstallationStore();
        $store->save($this->installation());
        $workspace = new InstallationQuery(teamId: 'T1');

        $store->delete($workspace);

        self::assertNull($store->fetch($workspace));
        self::assertNull($store->fetch($workspace->forUser('U1')));
    }

    private function installation(): Installation
    {
        return Installation::fromOAuthResponse([
            'team' => ['id' => 'T1'],
            'access_token' => 'xoxb-bot',
            'refresh_token' => 'bot-refresh',
            'expires_in' => 600,
            'bot_user_id' => 'Ubot',
            'authed_user' => [
                'id' => 'U1',
                'access_token' => 'xoxp-user',
                'refresh_token' => 'user-refresh',
                'expires_in' => 300,
            ],
        ], 1_000);
    }
}
