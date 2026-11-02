<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

use RuntimeException;

final readonly class ProjectScaffold
{
    public function __construct(
        private string $packageRoot,
    ) {}

    public function create(string $directory, ?ProjectSetup $setup = null): string
    {
        $setup ??= new ProjectSetup();
        $project = $this->resolveNewProjectPath($directory);
        $projectName = basename($project);
        if (!mkdir($project, 0775, true) && !is_dir($project)) {
            throw new RuntimeException("Unable to create {$project}.");
        }
        foreach (['app/Listeners', 'config', 'public'] as $path) {
            $this->makeDirectory("{$project}/{$path}");
        }
        $this->writeJson("{$project}/composer.json", [
            'name' => 'acme/' . $this->slug($projectName),
            'description' => 'A Slack application built with Thunder Bolt.',
            'type' => 'project',
            'license' => 'proprietary',
            'require' => ['php' => '^8.5', 'thunder/bolt' => '0.2.x-dev'],
            'repositories' => [[
                'type' => 'path',
                'url' => $this->packageRoot,
                'options' => ['symlink' => true, 'versions' => ['thunder/bolt' => '0.2.x-dev']],
            ]],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ]);
        $this->write(
            "{$project}/.env.example",
            "SLACK_BOT_TOKEN=xoxb-replace-me\nSLACK_SIGNING_SECRET=replace-me\nSLACK_BOT_USER_ID=\nSLACK_BOT_ID=\nSLACK_IGNORE_SELF=true\nSLACK_ATTACH_FUNCTION_TOKEN=true\n\n# Optional Socket Mode. An app-level xapp token is required only for `bolt socket:serve`.\nSLACK_APP_TOKEN=\n\n# Optional multi-workspace OAuth. Set all three to enable.\nSLACK_CLIENT_ID=\nSLACK_CLIENT_SECRET=\nSLACK_OAUTH_REDIRECT_URI=\nSLACK_BOT_SCOPES=app_mentions:read,chat:write\nSLACK_USER_SCOPES=\n# Store outside public/. Default: .thunder-bolt/oauth\nSLACK_INSTALLATION_STORE_PATH=\n\n# Context: file (default), sqlite, memory, or disabled.\nSLACK_CONTEXT_STORE=file\n# File directory or SQLite database path; default storage stays outside public/.\nSLACK_CONTEXT_STORE_PATH=\n\n# Durable named jobs; default .thunder-bolt/jobs/queue.sqlite. Requires pdo_sqlite.\nSLACK_JOB_STORE_PATH=\n",
        );
        $this->write("{$project}/.gitignore", "/vendor/\n/.env\n/.thunder-bolt/\n");
        $this->write(
            "{$project}/slack-manifest.yaml",
            "_metadata:\n  major_version: 1\ndisplay_information:\n  name: Thunder Bolt App\nfeatures:\n  bot_user:\n    display_name: Thunder Bolt\noauth_config:\n  scopes:\n    bot:\n      - app_mentions:read\n      - chat:write\nsettings:\n  # Set true before running `bolt socket:serve`; HTTP remains the default transport.\n  socket_mode_enabled: false\n  event_subscriptions:\n    request_url: https://example.test/slack/events\n    bot_events:\n      - app_mention\n",
        );
        $environment = file_get_contents("{$project}/.env.example");
        if ($environment === false) {
            throw new RuntimeException('Unable to read the generated environment template.');
        }
        if ($setup->authorization === 'oauth') {
            $environment = str_replace('SLACK_BOT_TOKEN=xoxb-replace-me', 'SLACK_BOT_TOKEN=', $environment);
        }
        if ($setup->transport === 'socket') {
            $environment = str_replace('SLACK_APP_TOKEN=', 'SLACK_APP_TOKEN=xapp-replace-me', $environment);
            $manifest = file_get_contents("{$project}/slack-manifest.yaml");
            if ($manifest === false) {
                throw new RuntimeException('Unable to read the generated Slack manifest.');
            }
            $manifest = str_replace('socket_mode_enabled: false', 'socket_mode_enabled: true', $manifest);
            $manifest = str_replace("    request_url: https://example.test/slack/events\n", '', $manifest);
            $this->write("{$project}/slack-manifest.yaml", $manifest);
        }
        $this->write("{$project}/.env.example", $environment);
        $this->write(
            "{$project}/config/setup.config.php",
            "<?php\n\ndeclare(strict_types=1);\n\nuse Thunder\\Bolt\\Console\\ProjectSetup;\n\nreturn new ProjectSetup(transport: "
            . var_export($setup->transport, true)
            . ', authorization: '
            . var_export($setup->authorization, true)
            . ");\n",
        );
        $this->write("{$project}/README.md", $this->projectReadme($setup));
        $this->copyStub('public-index.php.stub', "{$project}/public/index.php");
        $this->copyStub('mention-listener.php.stub', "{$project}/app/Listeners/GreetOnMention.php");
        $this->write(
            "{$project}/bolt",
            "#!/usr/bin/env php\n<?php\n\ndeclare(strict_types=1);\n\nrequire __DIR__ . '/vendor/autoload.php';\nrequire __DIR__ . '/vendor/thunder/bolt/bin/bolt';\n",
        );
        chmod("{$project}/bolt", 0755);

        return $project;
    }

    private function projectReadme(ProjectSetup $setup): string
    {
        $authorization = $setup->authorization === 'oauth'
            ? 'Set SLACK_CLIENT_ID, SLACK_CLIENT_SECRET and an HTTPS SLACK_OAUTH_REDIRECT_URI. Start php bolt serve for the installation/callback routes (also with Socket Mode), expose them through HTTPS, configure the same callback URL in Slack, then open /slack/install to create an installation.'
            : 'Set SLACK_BOT_TOKEN from your installed Slack app. Run php bolt auth:verify to verify its identity.';
        $transport = $setup->transport === 'socket'
            ? 'Set SLACK_APP_TOKEN to an app-level token with connections:write. The generated manifest enables Socket Mode. OAuth still needs an HTTPS installation/callback route.'
            : "Set SLACK_SIGNING_SECRET. Expose your local HTTP server through trusted HTTPS, then configure Slack's Events API URL as /slack/events.";
        return "# Your Slack app\n\nThis app uses {$setup->transport} delivery and {$setup->authorization} authorization.\n\n1. Run composer install.\n2. Copy .env.example to .env and fill in your app credentials.\n3. {$authorization}\n4. {$transport}\n5. Run php bolt doctor, then {$setup->startCommand()}.\n\nNever commit .env. The framework checkout must remain available while using the local Composer path repository.\n\n## Background work\n\nUse php bolt make:job MyJob to scaffold an explicit named job. Run php bolt jobs:work in a separate process when dispatching durable jobs; SQLite requires pdo_sqlite. Configuration readiness does not prove a worker is running.\n\n## Useful commands\n\n- php bolt doctor --format=json\n- php bolt status --format=json --require=jobs\n- php bolt listener:list\n- php bolt make:listener MyListener --event=app_mention\n- php bolt make:job MyJob\n\nThe onboarding defaults live in config/setup.config.php. For an HTTP readiness check alongside Socket Mode, use php bolt doctor --transport=http.\n";
    }

    public function listener(string $root, string $name, string $kind, string $selector, ?string $type = null): string
    {
        $class = $this->normalizeClassName($name);
        $directory = rtrim($root, DIRECTORY_SEPARATOR) . '/app/Listeners';
        $this->makeDirectory($directory);
        $target = "{$directory}/{$class}.php";
        if (file_exists($target)) {
            throw new RuntimeException("Refusing to overwrite existing listener {$target}.");
        }
        if ($kind === 'slash-command') {
            $this->write($target, strtr($this->stub('slash-command-listener.php.stub'), [
                '{{ class }}' => $class,
                '{{ selector }}' => var_export($selector, true),
            ]));

            return $target;
        }
        if (in_array($kind, ['action', 'shortcut', 'options', 'view'], true)) {
            $this->write($target, strtr($this->stub("{$kind}-listener.php.stub"), [
                '{{ class }}' => $class,
                '{{ selector }}' => var_export($selector, true),
                '{{ selectorArgument }}' => $this->interactionSelectorArgument($kind, $selector, $type),
                '{{ typeArgument }}' => $type === null ? '' : ', type: ' . var_export($type, true),
            ]));

            return $target;
        }

        if ($kind === 'function') {
            $this->write($target, strtr($this->stub('function-listener.php.stub'), [
                '{{ class }}' => $class,
                '{{ selector }}' => var_export($selector, true),
            ]));
            return $target;
        }

        [$attribute, $payloadUse, $payloadClass] = match ($kind) {
            'event' => $this->eventPayload($selector),
            'message' => ['MessageAttribute', 'Events\\Message as SlackMessage', 'SlackMessage'],
            default => throw new RuntimeException("Unknown listener kind {$kind}."),
        };
        $stub = $kind === 'event' && !in_array($selector, ['app_mention', 'message'], true)
            ? 'event-listener.php.stub'
            : 'listener.php.stub';
        $contents = strtr($this->stub($stub), [
            '{{ attribute }}' => $attribute,
            '{{ attributeUse }}' => $kind === 'message' ? 'Message as MessageAttribute' : $attribute,
            '{{ payloadUse }}' => $payloadUse,
            '{{ payloadClass }}' => $payloadClass,
            '{{ selector }}' => var_export($selector, true),
            '{{ class }}' => $class,
        ]);
        $this->write($target, $contents);

        return $target;
    }

    /** @return array{job: string, handler: string} */
    public function job(string $root, string $name): array
    {
        $class = $this->normalizeClassName($name);
        $directory = rtrim($root, DIRECTORY_SEPARATOR) . '/app/Jobs';
        $job = "{$directory}/{$class}.php";
        $handler = "{$directory}/{$class}Handler.php";
        foreach ([$job, $handler] as $target) {
            if (file_exists($target)) {
                throw new RuntimeException("Refusing to overwrite existing job scaffold {$target}.");
            }
        }
        $this->makeDirectory($directory);
        $this->write($job, strtr($this->stub('job.php.stub'), ['{{ class }}' => $class]));
        $this->write($handler, strtr($this->stub('job-handler.php.stub'), ['{{ class }}' => $class]));
        return ['job' => $job, 'handler' => $handler];
    }

    /** @return array{listener: string, config: string} */
    public function assistant(string $root, string $name = 'AssistantListener'): array
    {
        $class = $this->normalizeClassName($name);
        $listenerDirectory = rtrim($root, DIRECTORY_SEPARATOR) . '/app/Listeners';
        $configDirectory = rtrim($root, DIRECTORY_SEPARATOR) . '/config';
        $listener = "{$listenerDirectory}/{$class}.php";
        $config = "{$configDirectory}/assistant.config.php";
        foreach ([$listener, $config] as $target) {
            if (file_exists($target)) {
                throw new RuntimeException("Refusing to overwrite existing assistant scaffold {$target}.");
            }
        }
        $this->makeDirectory($listenerDirectory);
        $this->makeDirectory($configDirectory);
        $this->write($listener, strtr($this->stub('assistant-listener.php.stub'), [
            '{{ class }}' => $class,
        ]));
        $this->write($config, strtr($this->stub('assistant.config.php.stub'), [
            '{{ class }}' => $class,
        ]));

        return ['listener' => $listener, 'config' => $config];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function eventPayload(string $selector): array
    {
        $typedEvents = [
            'app_mention' => ['Events\\AppMention', 'AppMention'],
            'message' => ['Events\\Message as SlackMessage', 'SlackMessage'],
            'function_executed' => ['Events\\FunctionExecuted', 'FunctionExecuted'],
            'assistant_thread_started' => ['Events\\AssistantThreadStarted', 'AssistantThreadStarted'],
            'assistant_thread_context_changed' => [
                'Events\\AssistantThreadContextChanged',
                'AssistantThreadContextChanged',
            ],
            'entity_details_requested' => ['Events\\EntityDetailsRequested', 'EntityDetailsRequested'],
            'entity_comments_requested' => ['Events\\EntityCommentsRequested', 'EntityCommentsRequested'],
            'reaction_added' => ['Events\\ReactionAdded', 'ReactionAdded'],
            'reaction_removed' => ['Events\\ReactionRemoved', 'ReactionRemoved'],
            'member_joined_channel' => ['Events\\MemberJoinedChannel', 'MemberJoinedChannel'],
            'member_left_channel' => ['Events\\MemberLeftChannel', 'MemberLeftChannel'],
            'link_shared' => ['Events\\LinkShared', 'LinkShared'],
            'file_shared' => ['Events\\FileShared', 'FileShared'],
            'app_uninstalled' => ['Events\\AppUninstalled', 'AppUninstalled'],
            'tokens_revoked' => ['Events\\TokensRevoked', 'TokensRevoked'],
            'agent_session_stopped' => ['Events\\AgentSessionStopped', 'AgentSessionStopped'],
            'agent_session_title_changed' => ['Events\\AgentSessionTitleChanged', 'AgentSessionTitleChanged'],
        ];
        [$payloadUse, $payloadClass] = $typedEvents[$selector] ?? ['Event as SlackEvent', 'SlackEvent'];

        return ['Event', $payloadUse, $payloadClass];
    }

    private function interactionSelectorArgument(string $kind, string $selector, ?string $type): string
    {
        $legacyCallbackTypes = [
            'action' => ['interactive_message', 'dialog_submission'],
            'options' => ['interactive_message', 'dialog_suggestion'],
        ];

        if (in_array($type, $legacyCallbackTypes[$kind] ?? [], true)) {
            return 'callbackId: ' . var_export($selector, true);
        }

        return var_export($selector, true);
    }

    private function resolveNewProjectPath(string $input): string
    {
        $input = trim($input);
        if (
            $input === ''
            || str_contains($input, "\0")
            || str_starts_with($input, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $input)
        ) {
            throw new RuntimeException('Project path must be a new relative path without traversal.');
        }
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $input)) {
            throw new RuntimeException(
                'Project path may contain only letters, numbers, dot, underscore, dash, and slash.',
            );
        }
        $project = getcwd() . '/' . trim($input, '/');
        if (file_exists($project) || is_link($project)) {
            throw new RuntimeException("Refusing to create a project in existing path {$input}.");
        }

        return $project;
    }

    private function normalizeClassName(string $name): string
    {
        $name = trim($name);
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)) {
            throw new RuntimeException(
                'Listener name must be a PHP class name using letters, numbers, and underscores.',
            );
        }
        $tokens = token_get_all("<?php class {$name} {}");
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }
            $candidate = $tokens[$index + 2] ?? null;
            if (!is_array($candidate) || $candidate[0] !== T_STRING) {
                throw new RuntimeException("Listener name {$name} is a reserved PHP keyword.");
            }
            break;
        }

        return $name;
    }

    private function slug(string $name): string
    {
        return trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name)), '-') ?: 'bolt-app';
    }

    private function copyStub(string $stub, string $target): void
    {
        $this->write($target, $this->stub($stub));
    }

    private function stub(string $name): string
    {
        $contents = file_get_contents("{$this->packageRoot}/stubs/{$name}");
        if ($contents === false) {
            throw new RuntimeException("Unable to read scaffold stub {$name}.");
        }

        return $contents;
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->write(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        );
    }

    private function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write {$path}.");
        }
    }

    private function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException("Unable to create {$path}.");
        }
    }
}
