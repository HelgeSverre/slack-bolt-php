<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Jobs\JobConfig;
use Thunder\Bolt\SocketMode\Config as SocketConfig;

/** Local configuration checks only: never contacts Slack, opens stores, or starts workers. */
final readonly class ProjectDoctor
{
    public function __construct(
        private BoltConfig $config,
        private ProjectSetup $setup,
        private string $root,
        private JobConfig $jobs = new JobConfig(),
    ) {}

    /**
     * @param array<string, string> $oauthValues Values are consumed only to report redacted presence.
     * @return array{ok: bool, ready: bool, php: string, transport: string, authorization: string, httpReady: bool, socketReady: bool, workerReady: bool, contextStore: string, socketTokenConfigured: bool, socketAuthorizationConfigured: bool, configuration: array<string, string>, storage: array<string, array{writable: bool, public: bool}>, failures: list<string>, warnings: list<string>, nextSteps: list<string>, networkChecked: bool, installationVerified: bool, workerProcessChecked: bool}
     */
    public function report(array $oauthValues = [], bool $workers = false): array
    {
        $failures = [];
        $warnings = [];
        foreach (['json', 'mbstring', 'openssl'] as $extension) {
            if (!extension_loaded($extension))
                $failures[] = "Install the PHP {$extension} extension.";
        }
        $oauth = $this->setup->authorization === 'oauth';
        $configuredOAuth = $this->config->oauth;
        $authorization = $oauth
            ? [
                'SLACK_CLIENT_ID' => $configuredOAuth?->clientId ?? $oauthValues['SLACK_CLIENT_ID'] ?? '',
                'SLACK_CLIENT_SECRET' => $configuredOAuth?->clientSecret ?? $oauthValues['SLACK_CLIENT_SECRET'] ?? '',
                'SLACK_OAUTH_REDIRECT_URI' =>
                    $configuredOAuth?->redirectUri ?? $oauthValues['SLACK_OAUTH_REDIRECT_URI'] ?? '',
            ] : ['SLACK_BOT_TOKEN' => $this->config->token];
        $socket = $this->config->socketMode ?? new SocketConfig();
        $configuration = array_map(self::presence(...), $authorization);
        $http = $configuration + ['SLACK_SIGNING_SECRET' => self::presence($this->config->signingSecret)];
        $socketValues = $configuration + ['SLACK_APP_TOKEN' => self::presence($socket->appToken)];
        if (
            $oauth
            && $configuredOAuth === null
            && !array_any($configuration, static fn(string $status): bool => $status !== 'configured')
        ) {
            $failures[] = 'OAuth values are present, but BoltConfig has no OAuthConfig. Enable OAuth in config/.';
        }
        if (!$oauth && $configuredOAuth !== null) {
            $failures[] = 'BoltConfig enables OAuth. Use doctor --auth=oauth or update config/setup.config.php to match.';
        }
        $configuration = $this->setup->transport === 'socket' ? $socketValues : $http;
        $authReady =
            !array_any(
                array_map(self::presence(...), $authorization),
                static fn(string $status): bool => $status !== 'configured',
            )
            && (!$oauth || $configuredOAuth !== null);
        $storage = [];
        if ($oauth) {
            $storage['oauth'] = $this->storage($this->config->installationStorePath ?? '.thunder-bolt/oauth', true);
            if (!$storage['oauth']['writable'] || $storage['oauth']['public'])
                $failures[] = 'Use a writable OAuth store outside public/ (SLACK_INSTALLATION_STORE_PATH).';
        }
        if (!in_array($this->config->conversationStoreDriver, ['memory', 'disabled'], true)) {
            $sqlite = $this->config->conversationStoreDriver === 'sqlite';
            $storage['context'] = $this->storage(
                $this->config->conversationStorePath
                ?? ($sqlite ? '.thunder-bolt/conversations.sqlite' : '.thunder-bolt/conversations'),
                !$sqlite,
            );
            if ($sqlite && !extension_loaded('pdo_sqlite'))
                $failures[] = 'The SQLite context adapter requires the PHP pdo_sqlite extension.';
            if (!$storage['context']['writable'] || $storage['context']['public'])
                $failures[] = 'Use writable context storage outside public/ (SLACK_CONTEXT_STORE_PATH).';
        }
        $storage['jobs'] = $this->storage($this->jobs->path ?? '.thunder-bolt/jobs/queue.sqlite', false);
        $jobProblems = [];
        if (!extension_loaded('pdo_sqlite'))
            $jobProblems[] = 'Durable jobs require the PHP pdo_sqlite extension.';
        if (!$storage['jobs']['writable'] || $storage['jobs']['public'])
            $jobProblems[] = 'Use a writable SQLite job path outside public/ (SLACK_JOB_STORE_PATH).';
        if (!function_exists('pcntl_signal'))
            $warnings[] = 'PCNTL is unavailable; process signals are platform-dependent. Use your process supervisor to stop workers.';
        $workerReady = $failures === [] && $authReady && $jobProblems === [];
        $httpReady = $failures === [] && $authReady && self::configured($http, 'SLACK_SIGNING_SECRET');
        $socketReady = $failures === [] && $authReady && self::configured($socketValues, 'SLACK_APP_TOKEN');
        if ($workers)
            $failures = [...$failures, ...$jobProblems];
        else
            $warnings = [...$warnings, ...$jobProblems];
        $ready = ($this->setup->transport === 'socket' ? $socketReady : $httpReady) && (!$workers || $workerReady);
        $steps = [];
        foreach ($configuration as $name => $status) {
            if ($status !== 'configured')
                $steps[] = "Set {$name} in .env; replace any example value.";
        }
        if ($oauth)
            $steps[] = 'Configure the HTTPS callback in Slack and open /slack/install. Local readiness does not verify a stored installation.';
        elseif ($authReady)
            $steps[] = 'Run php bolt auth:verify to verify the static token and identity with Slack.';
        if ($ready)
            $steps[] = $this->setup->startCommand();
        if ($workerReady)
            $steps[] = 'Run php bolt jobs:work in a separate process when dispatching durable jobs.';
        return [
            'ok' => $failures === [],
            'ready' => $ready,
            'php' => PHP_VERSION,
            'transport' => $this->setup->transport,
            'authorization' => $oauth ? 'oauth' : 'static-token',
            'httpReady' => $httpReady,
            'socketReady' => $socketReady,
            'workerReady' => $workerReady,
            'contextStore' => $this->config->conversationStoreDriver,
            'socketTokenConfigured' => self::configured($socketValues, 'SLACK_APP_TOKEN'),
            'socketAuthorizationConfigured' => $authReady,
            'configuration' => $configuration,
            'storage' => $storage,
            'failures' => $failures,
            'warnings' => $warnings,
            'nextSteps' => $steps,
            'networkChecked' => false,
            'installationVerified' => false,
            'workerProcessChecked' => false,
        ];
    }

    /** @param array<string, string> $statuses Public classifications, never credential values. */
    private static function configured(array $statuses, string $key): bool
    {
        return ($statuses[$key] ?? null) === 'configured';
    }

    private static function presence(string $value): string
    {
        if (trim($value) === '')
            return 'missing';
        return in_array(
            $value,
            [
                'replace-me',
                'xoxb-replace-me',
                'xapp-replace-me',
                'xoxb-your-bot-token',
                'xapp-your-app-token',
                '<redacted>',
                '[redacted]',
                'https://example.test/slack/oauth_redirect',
            ],
            true,
        )
            ? 'placeholder'
            : 'configured';
    }

    /** @return array{writable: bool, public: bool} */
    private function storage(string $path, bool $directory): array
    {
        $absolute = str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
        $path = $absolute ? $path : $this->root . '/' . $path;
        $probe = $path;
        $suffix = [];
        while (!file_exists($probe) && dirname($probe) !== $probe) {
            array_unshift($suffix, basename($probe));
            $probe = dirname($probe);
        }
        $resolved =
            str_replace('\\', '/', realpath($probe) ?: $probe) . ($suffix === [] ? '' : '/' . implode('/', $suffix));
        $public = self::normalize($this->root . '/public');
        $resolvedPublic = self::normalize(realpath($this->root . '/public') ?: $this->root . '/public');
        $exposed = false;
        foreach ([self::normalize($path), self::normalize($resolved)] as $candidate) {
            foreach ([$public, $resolvedPublic] as $webRoot) {
                if ($candidate === $webRoot || str_starts_with($candidate, rtrim($webRoot, '/') . '/'))
                    $exposed = true;
            }
        }
        $wrongKind = file_exists($path) && ($directory ? !is_dir($path) : !is_file($path));
        return [
            'writable' =>
                !$wrongKind
                    && is_writable($probe)
                    && ($probe === $path || is_dir($probe))
                    && ($directory || !is_file($path) || is_writable(dirname($path))),
            'public' => $exposed,
        ];
    }

    private static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (PHP_OS_FAMILY === 'Windows')
            $path = strtolower($path);
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                if ($parts !== [] && !str_ends_with(end($parts), ':'))
                    array_pop($parts);
            } elseif ($part !== '' && $part !== '.')
                $parts[] = $part;
        }
        return (str_starts_with($path, '//') ? '//' : (str_starts_with($path, '/') ? '/' : '')) . implode('/', $parts);
    }
}
