<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Console;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Console\ProjectDoctor;
use Thunder\Bolt\Console\ProjectSetup;
use Thunder\Bolt\Jobs\JobConfig;
use Thunder\Bolt\OAuth\OAuthConfig;
use Thunder\Bolt\SocketMode\Config;

final class ProjectDoctorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bolt-doctor-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        mkdir($this->root . '/public', 0700);
    }

    protected function tearDown(): void
    {
        foreach (['file', 'public/store', 'alias'] as $file)
            if (is_file($this->root . '/' . $file) || is_link($this->root . '/' . $file))
                unlink($this->root . '/' . $file);
        rmdir($this->root . '/public');
        rmdir($this->root);
    }

    public function test_socket_setup_does_not_require_the_http_signing_secret_or_create_storage(): void
    {
        $config = new BoltConfig(token: 'private-bot-token', socketMode: new Config('xapp-private-token'));
        $report = new ProjectDoctor($config, new ProjectSetup('socket'), $this->root)->report();
        self::assertTrue($report['ready']);
        self::assertTrue($report['socketReady']);
        self::assertFalse($report['httpReady']);
        self::assertArrayNotHasKey('SLACK_SIGNING_SECRET', $report['configuration']);
        self::assertFalse($report['networkChecked']);
        self::assertFalse($report['workerProcessChecked']);
        self::assertFalse($report['installationVerified']);
        self::assertStringNotContainsString('private-bot-token', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('xapp-private-token', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertDirectoryDoesNotExist($this->root . '/.thunder-bolt');
    }

    public function test_http_readiness_rejects_example_credentials(): void
    {
        $report = new ProjectDoctor(
            new BoltConfig(token: 'xoxb-replace-me', signingSecret: 'replace-me'),
            new ProjectSetup(),
            $this->root,
        )->report();
        self::assertFalse($report['ready']);
        self::assertSame('placeholder', $report['configuration']['SLACK_BOT_TOKEN']);
        self::assertSame('placeholder', $report['configuration']['SLACK_SIGNING_SECRET']);
        self::assertStringContainsString('SLACK_BOT_TOKEN', implode(' ', $report['nextSteps']));
    }

    public function test_incomplete_oauth_stays_oauth_instead_of_silently_becoming_a_token_setup(): void
    {
        $report = new ProjectDoctor(
            new BoltConfig(token: 'unrelated', signingSecret: 'signing'),
            new ProjectSetup('http', 'oauth'),
            $this->root,
        )->report(['SLACK_CLIENT_ID' => '123', 'SLACK_CLIENT_SECRET' => '']);
        self::assertSame('oauth', $report['authorization']);
        self::assertFalse($report['ready']);
        self::assertSame('configured', $report['configuration']['SLACK_CLIENT_ID']);
        self::assertSame('missing', $report['configuration']['SLACK_CLIENT_SECRET']);
        self::assertArrayNotHasKey('SLACK_BOT_TOKEN', $report['configuration']);
    }

    public function test_oauth_configuration_readiness_is_distinct_from_installation_verification(): void
    {
        $config = new BoltConfig(
            signingSecret: 'signing',
            oauth: new OAuthConfig('123', 'private-oauth-secret', 'https://app.test/slack/oauth_redirect'),
        );
        $report = new ProjectDoctor($config, new ProjectSetup('http', 'oauth'), $this->root)->report();
        self::assertTrue($report['ready']);
        self::assertFalse($report['installationVerified']);
        self::assertStringNotContainsString('private-oauth-secret', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('/slack/install', implode(' ', $report['nextSteps']));
    }

    public function test_exposed_or_wrong_kind_storage_is_not_marked_ready(): void
    {
        file_put_contents($this->root . '/file', 'not a directory');
        $report = new ProjectDoctor(
            new BoltConfig(
                token: 'token',
                signingSecret: 'signing',
                installationStorePath: 'public/oauth',
                oauth: new OAuthConfig('123', 'secret', 'https://app.test/callback'),
            ),
            new ProjectSetup('http', 'oauth'),
            $this->root,
        )->report();
        self::assertFalse($report['ready']);
        self::assertTrue($report['storage']['oauth']['public']);
        $report = new ProjectDoctor(
            new BoltConfig(token: 'token', signingSecret: 'signing', conversationStorePath: 'file'),
            new ProjectSetup(),
            $this->root,
        )->report();
        self::assertFalse($report['ready']);
        self::assertFalse($report['storage']['context']['writable']);
    }

    public function test_worker_storage_failure_does_not_claim_the_http_delivery_is_unconfigured(): void
    {
        $doctor = new ProjectDoctor(
            new BoltConfig(token: 'token', signingSecret: 'signing'),
            new ProjectSetup(),
            $this->root,
            new JobConfig('public/queue.sqlite'),
        );
        self::assertTrue($doctor->report()['ready']);
        self::assertFalse($doctor->report()['workerReady']);
        self::assertFalse($doctor->report(workers: true)['ready']);
        self::assertTrue($doctor->report(workers: true)['httpReady']);
        self::assertNotEmpty($doctor->report(workers: true)['failures']);
    }

    public function test_unresolved_parent_segments_cannot_hide_a_public_storage_path(): void
    {
        $config = new BoltConfig(
            token: 'token',
            signingSecret: 'signing',
            conversationStorePath: 'private/../public/context',
        );
        $report = new ProjectDoctor($config, new ProjectSetup(), $this->root)->report();
        self::assertFalse($report['ready']);
        self::assertTrue($report['storage']['context']['public']);
    }

    public function test_public_symlink_to_private_storage_remains_exposed(): void
    {
        if (PHP_OS_FAMILY === 'Windows')
            self::markTestSkipped('Symlinks require elevated privileges on Windows.');
        symlink($this->root, $this->root . '/public/store');
        $report = new ProjectDoctor(
            new BoltConfig(token: 'token', signingSecret: 'signing', conversationStorePath: 'public/store'),
            new ProjectSetup(),
            $this->root,
        )->report();
        self::assertFalse($report['ready']);
        self::assertTrue($report['storage']['context']['public']);
    }

    public function test_redacted_credentials_are_placeholders(): void
    {
        $report = new ProjectDoctor(
            new BoltConfig(token: '<redacted>', signingSecret: '[redacted]'),
            new ProjectSetup(),
            $this->root,
        )->report();
        self::assertFalse($report['ready']);
        self::assertSame('placeholder', $report['configuration']['SLACK_BOT_TOKEN']);
        self::assertSame('placeholder', $report['configuration']['SLACK_SIGNING_SECRET']);
    }

    public function test_setup_and_runtime_authorization_mismatches_are_actionable(): void
    {
        $report = new ProjectDoctor(
            new BoltConfig(signingSecret: 'signing'),
            new ProjectSetup('http', 'oauth'),
            $this->root,
        )->report([
            'SLACK_CLIENT_ID' => '123',
            'SLACK_CLIENT_SECRET' => 'secret',
            'SLACK_OAUTH_REDIRECT_URI' => 'https://app.test/callback',
        ]);
        self::assertFalse($report['ready']);
        self::assertStringContainsString('BoltConfig has no OAuthConfig', implode(' ', $report['failures']));
    }
}
