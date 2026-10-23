<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

use RuntimeException;

final readonly class ProjectScaffold
{
    public function __construct(
        private string $packageRoot,
    ) {}

    public function create(string $directory): string
    {
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
            'require' => ['php' => '^8.5', 'thunder/bolt' => '0.1.x-dev'],
            'repositories' => [[
                'type' => 'path',
                'url' => $this->packageRoot,
                'options' => ['symlink' => true, 'versions' => ['thunder/bolt' => '0.1.x-dev']],
            ]],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ]);
        $this->write(
            "{$project}/.env.example",
            "SLACK_BOT_TOKEN=xoxb-replace-me\nSLACK_SIGNING_SECRET=replace-me\nSLACK_BOT_USER_ID=\nSLACK_IGNORE_SELF=true\n\n# Optional multi-workspace OAuth. Set all three to enable.\nSLACK_CLIENT_ID=\nSLACK_CLIENT_SECRET=\nSLACK_OAUTH_REDIRECT_URI=\nSLACK_BOT_SCOPES=app_mentions:read,chat:write\nSLACK_USER_SCOPES=\n# Store outside public/. Default: .thunder-bolt/oauth\nSLACK_INSTALLATION_STORE_PATH=\n\n# Context: file (default), sqlite, memory, or disabled.\nSLACK_CONTEXT_STORE=file\n# File directory or SQLite database path; default storage stays outside public/.\nSLACK_CONTEXT_STORE_PATH=\n",
        );
        $this->write("{$project}/.gitignore", "/vendor/\n/.env\n/.thunder-bolt/\n");
        $this->write(
            "{$project}/slack-manifest.yaml",
            "_metadata:\n  major_version: 1\ndisplay_information:\n  name: Thunder Bolt App\nfeatures:\n  bot_user:\n    display_name: Thunder Bolt\noauth_config:\n  scopes:\n    bot:\n      - app_mentions:read\n      - chat:write\nsettings:\n  event_subscriptions:\n    request_url: https://example.test/slack/events\n    bot_events:\n      - app_mention\n",
        );
        $this->copyStub('public-index.php.stub', "{$project}/public/index.php");
        $this->copyStub('mention-listener.php.stub', "{$project}/app/Listeners/GreetOnMention.php");
        $this->write(
            "{$project}/bolt",
            "#!/usr/bin/env php\n<?php\n\ndeclare(strict_types=1);\n\nrequire __DIR__ . '/vendor/autoload.php';\nrequire __DIR__ . '/vendor/thunder/bolt/bin/bolt';\n",
        );
        chmod("{$project}/bolt", 0755);

        return $project;
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

        [$attribute, $payloadUse, $payloadClass] = match ($kind) {
            'event' => ['Event', 'Events\\AppMention', 'AppMention'],
            'message' => ['MessageAttribute', 'Events\\Message as SlackMessage', 'SlackMessage'],
            default => throw new RuntimeException("Unknown listener kind {$kind}."),
        };
        $contents = strtr($this->stub('listener.php.stub'), [
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
