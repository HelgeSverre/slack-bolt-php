<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

use JsonException;
use RuntimeException;
use Tempest\Console\Console;
use Tempest\Console\ConsoleArgument;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\SocketMode\Config as SocketModeConfig;

final readonly class BoltCommands
{
    public function __construct(
        private Console $console,
    ) {}

    #[ConsoleCommand(name: 'new', description: 'Create a Thunder Bolt project in a new directory')]
    public function createProject(
        #[ConsoleArgument(description: 'New project directory')]
        string $directory,
        #[ConsoleArgument(name: 'no-interaction', description: 'Use defaults and never prompt')]
        bool $noInteraction = false,
    ): ExitCode {
        try {
            $project = new ProjectScaffold(dirname(__DIR__, 2))->create($directory);
        } catch (RuntimeException|JsonException $exception) {
            $this->console->error($exception->getMessage());

            return ExitCode::INVALID;
        }
        $this->console->success("Created {$project}");
        $this->console->instructions([
            "cd {$directory}",
            'composer install',
            'cp .env.example .env',
            'php vendor/bin/bolt doctor',
        ]);

        return ExitCode::SUCCESS;
    }

    #[ConsoleCommand(name: 'make:listener', description: 'Create a discovered Slack listener')]
    public function makeListener(
        #[ConsoleArgument(description: 'Listener PHP class name')]
        string $name,
        #[ConsoleArgument(name: 'event', description: 'Slack event type, currently app_mention')]
        ?string $event = null,
        #[ConsoleArgument(name: 'message', description: 'Message text to match')]
        ?string $message = null,
        #[ConsoleArgument(name: 'slash-command', description: 'Slash command, for example /deploy')]
        ?string $slashCommand = null,
        #[ConsoleArgument(
            name: 'action',
            description: 'Action ID; callback ID for legacy interactive_message or dialog_submission',
        )]
        ?string $action = null,
        #[ConsoleArgument(name: 'shortcut', description: 'Global or message shortcut callback ID')]
        ?string $shortcut = null,
        #[ConsoleArgument(
            name: 'options',
            description: 'External-select action ID; callback ID for legacy interactive_message or dialog_suggestion',
        )]
        ?string $options = null,
        #[ConsoleArgument(name: 'view', description: 'Modal callback ID to match')]
        ?string $view = null,
        #[ConsoleArgument(
            name: 'type',
            description: 'Optional Slack interaction payload type; legacy action/options selectors target callback IDs',
        )]
        ?string $type = null,
    ): ExitCode {
        $choices = array_filter(
            [
                'event' => $event,
                'message' => $message,
                'slash-command' => $slashCommand,
                'action' => $action,
                'shortcut' => $shortcut,
                'options' => $options,
                'view' => $view,
            ],
            static fn(?string $value): bool => $value !== null,
        );
        if (count($choices) !== 1) {
            $this->console->error(
                'Choose exactly one listener selector, such as --event, --action, --shortcut, --options, or --view.',
            );

            return ExitCode::INVALID;
        }
        $kind = array_key_first($choices);
        $selector = $choices[$kind];
        if ($kind === 'event' && $selector !== 'app_mention') {
            $this->console->error('The first milestone supports --event=app_mention.');

            return ExitCode::INVALID;
        }
        if ($kind === 'slash-command' && !str_starts_with($selector, '/')) {
            $this->console->error('A slash command must begin with /.');

            return ExitCode::INVALID;
        }
        if ($type !== null && !in_array($kind, ['action', 'shortcut', 'options', 'view'], true)) {
            $this->console->error('--type is available for interactive action, shortcut, options, and view listeners.');

            return ExitCode::INVALID;
        }
        $interactionTypes = [
            'action' => ['block_actions', 'interactive_message', 'dialog_submission'],
            'shortcut' => ['shortcut', 'message_action'],
            'options' => ['block_suggestion', 'interactive_message', 'dialog_suggestion'],
            'view' => ['view_submission', 'view_closed'],
        ];
        if ($type !== null && !in_array($type, $interactionTypes[$kind], true)) {
            $this->console->error("Unsupported --type={$type} for {$kind} listeners.");

            return ExitCode::INVALID;
        }
        if (in_array($kind, ['shortcut', 'view'], true) && $selector === '') {
            $this->console->error("--{$kind} requires a callback ID.");

            return ExitCode::INVALID;
        }
        if ($kind === 'view') {
            $type ??= 'view_submission';
        }
        try {
            $path = new ProjectScaffold(dirname(__DIR__, 2))->listener(
                getcwd() ?: throw new RuntimeException('Unable to resolve the current directory.'),
                $name,
                $kind,
                $selector,
                $type,
            );
        } catch (RuntimeException $exception) {
            $this->console->error($exception->getMessage());

            return ExitCode::INVALID;
        }
        $this->console->success("Created {$path}");

        return ExitCode::SUCCESS;
    }

    #[ConsoleCommand(name: 'listener:list', description: 'List discovered Slack listeners')]
    public function listenerList(
        #[ConsoleArgument(name: 'format', description: 'Output format: table or json')]
        string $format = 'table',
    ): ExitCode {
        $listeners = \Tempest\Container\get(App::class)->listeners();
        if ($format === 'json') {
            $this->console->writeRaw(
                json_encode($listeners, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            );

            return ExitCode::SUCCESS;
        }
        if ($format !== 'table') {
            $this->console->error('Format must be table or json.');

            return ExitCode::INVALID;
        }
        if ($listeners === []) {
            $this->console->info('No listeners were discovered.');

            return ExitCode::SUCCESS;
        }
        foreach ($listeners as $listener) {
            $this->console->writeln(sprintf(
                '%s  %s  %s',
                $listener['kind'],
                $listener['selector'],
                $listener['source'],
            ));
        }

        return ExitCode::SUCCESS;
    }

    #[ConsoleCommand(name: 'doctor', description: 'Check local Thunder Bolt requirements without contacting Slack')]
    public function doctor(
        #[ConsoleArgument(name: 'format', description: 'Output format: table or json')]
        string $format = 'table',
    ): ExitCode {
        if (!in_array($format, ['table', 'json'], true)) {
            $this->console->error('Format must be table or json.');

            return ExitCode::INVALID;
        }
        $failures = [];
        foreach (['json', 'mbstring', 'openssl'] as $extension) {
            if (!extension_loaded($extension)) {
                $failures[] = "Missing PHP extension: {$extension}.";
            }
        }
        $configuration = [];
        $config = \Tempest\Container\get(BoltConfig::class);
        $socketMode = $config->socketMode ?? new SocketModeConfig();
        if ($config->conversationStoreDriver === 'sqlite' && !extension_loaded('pdo_sqlite')) {
            $failures[] = 'The SQLite context adapter requires the pdo_sqlite extension.';
        }
        $oauth = $config->oauth !== null;
        $values = $oauth
            ? [
                'SLACK_CLIENT_ID' => $config->oauth->clientId,
                'SLACK_CLIENT_SECRET' => $config->oauth->clientSecret,
                'SLACK_OAUTH_REDIRECT_URI' => $config->oauth->redirectUri,
                'SLACK_SIGNING_SECRET' => $config->signingSecret,
            ]
            : ['SLACK_BOT_TOKEN' => $config->token, 'SLACK_SIGNING_SECRET' => $config->signingSecret];
        foreach ($values as $variable => $value) {
            if ($value === '') {
                $configuration[$variable] = 'missing';
            } else {
                $configuration[$variable] = 'configured';
            }
        }
        if ($format === 'json') {
            $this->console->writeRaw(
                json_encode(
                    [
                        'ok' => $failures === [],
                        'ready' => $failures === [] && !in_array('missing', $configuration, true),
                        'php' => PHP_VERSION,
                        'authorization' => $oauth ? 'oauth' : 'static-token',
                        'contextStore' => $config->conversationStoreDriver,
                        'socketTokenConfigured' => $socketMode->isConfigured(),
                        'socketAuthorizationConfigured' => $config->token !== '' || $oauth,
                        'socketReady' =>
                            $failures === [] && $socketMode->isConfigured() && ($config->token !== '' || $oauth),
                        'configuration' => $configuration,
                        'failures' => $failures,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                )
                    . PHP_EOL,
            );

            return $failures === [] ? ExitCode::SUCCESS : ExitCode::ERROR;
        }
        foreach ($configuration as $variable => $status) {
            if ($status === 'missing') {
                $this->console->warning("{$variable} is not set; HTTP dispatch will remain unconfigured.");
            }
        }
        foreach ($failures as $failure) {
            $this->console->error($failure);
        }
        if ($failures !== []) {
            return ExitCode::ERROR;
        }
        if ($oauth) {
            $this->console->info(
                'OAuth is configured. Start installation at /slack/install; successful dispatch requires a stored installation.',
            );
        }
        $this->console->success('Local requirements look good. No network checks were run.');

        return ExitCode::SUCCESS;
    }

    #[ConsoleCommand(name: 'serve', description: 'Run the development HTTP server')]
    public function serve(
        #[ConsoleArgument(name: 'host', description: 'Bind host')]
        string $host = '127.0.0.1',
        #[ConsoleArgument(name: 'port', description: 'Bind port')]
        int $port = 8000,
    ): ExitCode {
        if (filter_var($host, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) {
            $this->console->error('Use a valid IP host and a port from 1 through 65535.');

            return ExitCode::INVALID;
        }
        $directory = getcwd();
        if ($directory === false) {
            $this->console->error('Unable to resolve the current directory.');
            return ExitCode::INVALID;
        }
        $public = $directory . '/public';
        if (!is_dir($public)) {
            $this->console->error('Expected a public/ directory. Run this command from a generated project.');

            return ExitCode::INVALID;
        }
        $this->console->info("Development server listening on http://{$host}:{$port}");
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-S', "{$host}:{$port}", '-t', $public],
            [STDIN, STDOUT, STDERR],
            $pipes,
            $directory,
        );
        if (!is_resource($process)) {
            $this->console->error('Unable to start the PHP development server.');

            return ExitCode::CANNOT_EXECUTE;
        }
        $interrupted = false;
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function () use (&$interrupted): void {
                $interrupted = true;
            });
        }
        do {
            usleep(100_000);
            $status = proc_get_status($process);
        } while ($status['running'] && !$interrupted);
        if ($interrupted && $status['running']) {
            proc_terminate($process);
        }
        $exit = proc_close($process);

        return $interrupted ? ExitCode::TERMINATED : ($exit === 0 ? ExitCode::SUCCESS : ExitCode::ERROR);
    }
}
