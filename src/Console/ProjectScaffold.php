<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

use RuntimeException;

final readonly class ProjectScaffold
{
    public function __construct(
        private string $packageRoot,
    ) {}

    public function create(string $directory, ?ProjectSetup $setup = null, ?ProjectSource $source = null): string
    {
        $setup ??= new ProjectSetup();
        $profile = StarterProfile::from($setup->profile);
        $source ??= ProjectSource::fromOptions(null, null, null, $this->packageRoot);
        if ($profile !== StarterProfile::Mentions && !$source->supports('0.8.0')) {
            throw new \InvalidArgumentException(
                'Interactive and agent starters require a dependency constraint of 0.8.0 or newer, dev-main, or --source=path. Use --profile=mentions for older packages.',
            );
        }
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
            'require' => ['php' => '^8.5', 'helgesverre/slack-bolt' => $source->version],
            ...($source->composerRepository() === null ? [] : ['repositories' => [$source->composerRepository()]]),
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ]);
        $this->write("{$project}/.env.example", $this->environment($setup, $profile));
        $this->write("{$project}/.gitignore", "/vendor/\n/.env\n/.thunder-bolt/\n/.bolt-key*\n");
        $this->write("{$project}/slack-manifest.yaml", $profile->manifest($setup));
        $this->write(
            "{$project}/config/setup.config.php",
            "<?php\n\ndeclare(strict_types=1);\n\nuse Thunder\\Bolt\\Console\\ProjectSetup;\n\nreturn new ProjectSetup(transport: "
            . var_export($setup->transport, true)
            . ', authorization: '
            . var_export($setup->authorization, true)
            . ', profile: '
            . var_export($setup->profile, true)
            . ");\n",
        );
        $this->write("{$project}/README.md", $this->projectReadme($setup, $source));
        $this->copyStub('public-index.php.stub', "{$project}/public/index.php");
        foreach ($profile->files() as $target => $stub) {
            $this->write("{$project}/{$target}", strtr($this->stub($stub), ['{{ class }}' => 'WorkspaceAgent']));
        }
        $this->write(
            "{$project}/bolt",
            "#!/usr/bin/env php\n<?php\n\ndeclare(strict_types=1);\n\nrequire __DIR__ . '/vendor/autoload.php';\nrequire __DIR__ . '/vendor/helgesverre/slack-bolt/bin/bolt';\n",
        );
        chmod("{$project}/bolt", 0755);

        return $project;
    }

    private function environment(ProjectSetup $setup, StarterProfile $profile): string
    {
        $botToken = match ($setup->authorization) {
            'token' => 'xoxb-replace-me',
            default => '',
        };
        $appToken = $setup->transport === 'socket' ? 'xapp-replace-me' : '';
        $scopes = implode(',', $profile->scopes());

        return <<<ENV
            # Use production error rendering by default. Set local only for trusted debugging.
            ENVIRONMENT=production
            # Public browser routes need the actual HTTPS origin here.
            BASE_URI=http://127.0.0.1:8000
            # Applies to HTTP and CLI; relative paths resolve from the project root.
            BOLT_RUNTIME_PATH=
            # Optional custom browser forms/sessions; Slack OAuth does not need this key.
            # Run: php bolt key:generate, then php bolt doctor --sessions
            SIGNING_KEY=
            SLACK_BOT_TOKEN={$botToken}
            SLACK_SIGNING_SECRET=replace-me
            SLACK_BOT_USER_ID=
            SLACK_BOT_ID=
            SLACK_IGNORE_SELF=true
            SLACK_ATTACH_FUNCTION_TOKEN=true

            # Socket Mode requires connections:write on this app-level token.
            SLACK_APP_TOKEN={$appToken}

            # OAuth requires all three values and an HTTPS installation/callback route.
            SLACK_CLIENT_ID=
            SLACK_CLIENT_SECRET=
            SLACK_OAUTH_REDIRECT_URI=
            SLACK_BOT_SCOPES={$scopes}
            SLACK_USER_SCOPES=
            # Optional override; default: BOLT_RUNTIME_PATH/oauth.
            SLACK_INSTALLATION_STORE_PATH=

            # Context: file, sqlite, memory, or disabled. Default: BOLT_RUNTIME_PATH/conversations.
            SLACK_CONTEXT_STORE=file
            SLACK_CONTEXT_STORE_PATH=
            # Default: BOLT_RUNTIME_PATH/jobs/queue.sqlite. Requires pdo_sqlite.
            SLACK_JOB_STORE_PATH=

            ENV;
    }

    private function projectReadme(ProjectSetup $setup, ProjectSource $source): string
    {
        $authorization = $setup->authorization === 'oauth'
            ? 'Set SLACK_CLIENT_ID, SLACK_CLIENT_SECRET and an HTTPS SLACK_OAUTH_REDIRECT_URI. Start php bolt serve for the installation/callback routes (also with Socket Mode), expose them through HTTPS, configure the same callback URL in Slack, then open /slack/install to create an installation.'
            : 'Set SLACK_BOT_TOKEN from your installed Slack app. Run php bolt auth:verify to verify its identity.';
        $transport = $setup->transport === 'socket'
            ? 'Set SLACK_APP_TOKEN to an app-level token with connections:write. The generated manifest enables Socket Mode. OAuth still needs an HTTPS installation/callback route.'
            : "Set SLACK_SIGNING_SECRET. Expose your local HTTP server through trusted HTTPS, then configure Slack's Events API URL as /slack/events.";
        $sourceNote = $source->type === ProjectSource::PACKAGIST
            ? 'This project installs helgesverre/slack-bolt from Packagist using '
            . $source->version
            . '. Commit composer.lock to pin the installed revision.'
            : (
                $source->type === ProjectSource::VCS
                    ? 'This project installs Thunder Bolt from VCS using `'
                    . $source->version
                    . '`. Keep access to that repository available.'
                    : 'This project uses a local Composer path repository. Keep the framework checkout available while developing.'
            );
        $profile = StarterProfile::from($setup->profile);
        return "# Your Slack app\n\nStarter: {$profile->value}. {$profile->instructions()}\n\nThis app uses {$setup->transport} delivery and {$setup->authorization} authorization.\n\n1. Run composer install.\n2. Copy .env.example to .env and fill in your app credentials.\n3. {$authorization}\n4. {$transport}\n5. Run php bolt doctor, then {$setup->startCommand()}.\n\nNever commit .env. {$sourceNote}\n\n## Runtime and browser routes\n\nSet BOLT_RUNTIME_PATH to a writable private directory outside public/. HTTP and CLI resolve it identically; production can use /var/lib/my-slack-app without making the release writable. Existing projects must update public/index.php to Bootstrap::boot(dirname(__DIR__)). Relative store overrides also resolve from the project root.\n\nCustom routes preserve returned status/body/headers, including 4xx/5xx. Thrown exceptions use Tempest renderers. Keep ENVIRONMENT=production when handling credentials. For custom browser sessions, run php bolt key:generate, then php bolt doctor --sessions. Existing keys are preserved; --force rotates the key and invalidates encrypted cookies. Manage production keys externally. Slack OAuth is stateless and needs no SIGNING_KEY.\n\n## Background work\n\nUse php bolt make:job MyJob to scaffold an explicit named job. Run php bolt jobs:work in a separate process when dispatching durable jobs; SQLite requires pdo_sqlite. Configuration readiness does not prove a worker is running.\n\n## Useful commands\n\n- php bolt doctor --format=json\n- php bolt status --format=json --require=jobs\n- php bolt listener:list\n- php bolt make:listener MyListener --event=app_mention\n- php bolt make:job MyJob\n\nThe onboarding defaults live in config/setup.config.php. For an HTTP readiness check alongside Socket Mode, use php bolt doctor --transport=http.\n";
    }

    public function listener(string $root, string $name, string $kind, string $selector, ?string $type = null): string
    {
        $class = $this->normalizeClassName($name);
        $directory = rtrim($root, DIRECTORY_SEPARATOR) . '/app/Listeners';
        $this->makeDirectory($directory);
        $target = "{$directory}/{$class}.php";
        if (file_exists($target) || is_link($target)) {
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
            if (file_exists($target) || is_link($target)) {
                throw new RuntimeException("Refusing to overwrite existing job scaffold {$target}.");
            }
        }
        $this->makeDirectory($directory);
        $this->write($job, strtr($this->stub('job.php.stub'), ['{{ class }}' => $class]));
        $this->write($handler, strtr($this->stub('job-handler.php.stub'), ['{{ class }}' => $class]));
        return ['job' => $job, 'handler' => $handler];
    }

    /** @return array{listener: string, config: string} */
    public function assistant(string $root, string $name = 'AssistantListener', bool $agentView = false): array
    {
        $class = $this->normalizeClassName($name);
        $listenerDirectory = rtrim($root, DIRECTORY_SEPARATOR) . '/app/Listeners';
        $configDirectory = rtrim($root, DIRECTORY_SEPARATOR) . '/config';
        $listener = "{$listenerDirectory}/{$class}.php";
        $config = "{$configDirectory}/assistant.config.php";
        foreach ([$listener, $config] as $target) {
            if (file_exists($target) || is_link($target)) {
                throw new RuntimeException("Refusing to overwrite existing assistant scaffold {$target}.");
            }
        }
        $this->makeDirectory($listenerDirectory);
        $this->makeDirectory($configDirectory);
        $this->write($listener, strtr(
            $this->stub($agentView ? 'agent-listener.php.stub' : 'assistant-listener.php.stub'),
            [
                '{{ class }}' => $class,
            ],
        ));
        $this->write($config, strtr($this->stub($agentView ? 'agent.config.php.stub' : 'assistant.config.php.stub'), [
            '{{ class }}' => $class,
        ]));

        return ['listener' => $listener, 'config' => $config];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function eventPayload(string $selector): array
    {
        $class = \Thunder\Bolt\Payloads\EventPayloads::classFor($selector);
        $payloadClass = substr($class, strrpos($class, '\\') + 1);
        $payloadUse = substr($class, strlen('Thunder\\Bolt\\Payloads\\'));
        if ($payloadClass === 'Event' || $payloadClass === 'Message') {
            $alias = $payloadClass === 'Event' ? 'SlackEvent' : 'SlackMessage';
            $payloadUse .= ' as ' . $alias;
            $payloadClass = $alias;
        }

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
