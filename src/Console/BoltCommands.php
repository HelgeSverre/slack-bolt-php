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
        #[ConsoleArgument(description: 'Delivery transport: http or socket')]
        string $transport = 'http',
        #[ConsoleArgument(name: 'auth', description: 'Authorization: token or oauth')]
        string $authorization = 'token',
        #[ConsoleArgument(name: 'source', description: 'Dependency source: packagist, path or vcs')]
        string $source = 'packagist',
        #[ConsoleArgument(description: 'VCS repository URL or absolute local Git repository')]
        ?string $repository = null,
        #[ConsoleArgument(description: 'Composer constraint (0.3.0+) or dev-main')]
        ?string $version = null,
    ): ExitCode {
        try {
            $packageRoot = dirname(__DIR__, 2);
            $projectSource = ProjectSource::fromOptions($source, $repository, $version, $packageRoot);
            $project = new ProjectScaffold(dirname(__DIR__, 2))->create(
                $directory,
                new ProjectSetup($transport, $authorization),
                $projectSource,
            );
        } catch (RuntimeException|JsonException|\InvalidArgumentException $exception) {
            $this->console->error($exception->getMessage());

            return ExitCode::INVALID;
        }
        $this->console->success("Created {$project}");
        $this->console->instructions([
            "cd {$directory}",
            'composer install',
            'cp .env.example .env',
            'php bolt doctor',
            new ProjectSetup($transport, $authorization)->startCommand(),
        ]);

        return ExitCode::SUCCESS;
    }

    #[ConsoleCommand(name: 'make:listener', description: 'Create a discovered Slack listener')]
    public function makeListener(
        #[ConsoleArgument(description: 'Listener PHP class name')]
        string $name,
        #[ConsoleArgument(name: 'event', description: 'Slack event type; known events use typed payloads')]
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
        #[ConsoleArgument(name: 'function', description: 'Slack custom function callback ID')]
        ?string $function = null,
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
                'function' => $function,
            ],
            static fn(?string $value): bool => $value !== null,
        );
        if (count($choices) !== 1) {
            $this->console->error(
                'Choose exactly one listener selector, such as --event, --action, --shortcut, --options, --view, or --function.',
            );

            return ExitCode::INVALID;
        }
        $kind = array_key_first($choices);
        $selector = $choices[$kind];
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
        if (in_array($kind, ['shortcut', 'view', 'function'], true) && trim($selector) === '') {
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

    #[ConsoleCommand(name: 'make:assistant', description: 'Create a configured Slack assistant listener')]
    public function makeAssistant(
        #[ConsoleArgument(description: 'Assistant listener PHP class name')]
        string $name = 'AssistantListener',
        #[ConsoleArgument(
            name: 'agent-view',
            description: 'Generate modern Agent View handlers instead of legacy assistant threads',
        )]
        bool $agentView = false,
    ): ExitCode {
        try {
            $paths = new ProjectScaffold(dirname(__DIR__, 2))->assistant(
                getcwd() ?: throw new RuntimeException('Unable to resolve the current directory.'),
                $name,
                $agentView,
            );
        } catch (RuntimeException $exception) {
            $this->console->error($exception->getMessage());

            return ExitCode::INVALID;
        }
        $this->console->success("Created {$paths['listener']} and {$paths['config']}");
        $this->console->instructions([
            'Add only the assistant capabilities you need to slack-manifest.yaml:',
            'bot scopes: assistant:write, chat:write, im:history',
            $agentView
                ? 'bot events: app_home_opened, app_context_changed, message.im, agent_session_stopped'
                : 'bot events: assistant_thread_started, assistant_thread_context_changed, message.im',
            'Enable Agents → Agent experience in Slack, then reinstall the app.',
            ...(
                $agentView
                    ? [
                        'Enable Agent View (features.agent_view) and App Home Messages.',
                        'Handlers run inline. Use named durable jobs for slow work; Stop cancels thread-bound jobs when a queue is configured.',
                    ]
                    : []
            ),
        ]);

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

    #[ConsoleCommand(
        name: 'doctor',
        description: 'Check local setup and selected transport readiness without contacting Slack',
    )]
    public function doctor(
        #[ConsoleArgument(name: 'format', description: 'Output format: table or json')]
        string $format = 'table',
        #[ConsoleArgument(description: 'Check http or socket; default is the project setup')]
        ?string $transport = null,
        #[ConsoleArgument(name: 'auth', description: 'Check token or oauth; default is the project setup')]
        ?string $authorization = null,
        #[ConsoleArgument(description: 'Also require durable job worker prerequisites')]
        bool $workers = false,
    ): ExitCode {
        if (!in_array($format, ['table', 'json'], true)) {
            $this->console->error('Format must be table or json.');
            return ExitCode::INVALID;
        }
        $container = \Tempest\Container\get(\Tempest\Container\Container::class);
        $config = $container->get(BoltConfig::class);
        $defaults = $container->has(ProjectSetup::class)
            ? $container->get(ProjectSetup::class)
            : new ProjectSetup(
                $config->socketMode?->isConfigured() ?? false ? 'socket' : 'http',
                $config->oauth !== null ? 'oauth' : 'token',
            );
        try {
            $setup = new ProjectSetup($transport ?? $defaults->transport, $authorization ?? $defaults->authorization);
        } catch (\InvalidArgumentException $error) {
            $this->console->error($error->getMessage());
            return ExitCode::INVALID;
        }
        $report = new ProjectDoctor(
            $config,
            $setup,
            getcwd() ?: '.',
            $container->get(\Thunder\Bolt\Jobs\JobConfig::class),
        )->report([
            'SLACK_CLIENT_ID' => (string) \Tempest\env('SLACK_CLIENT_ID', ''),
            'SLACK_CLIENT_SECRET' => (string) \Tempest\env('SLACK_CLIENT_SECRET', ''),
            'SLACK_OAUTH_REDIRECT_URI' => (string) \Tempest\env('SLACK_OAUTH_REDIRECT_URI', ''),
        ], $workers);
        if ($format === 'json') {
            $this->console->writeRaw(
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            );
        } else {
            $this->console->info(
                'Checking '
                . $report['transport']
                . ' / '
                . $report['authorization']
                . ' setup. No network checks were run.',
            );
            foreach ($report['configuration'] as $name => $status)
                $this->console->writeln($name . ': ' . $status);
            foreach ($report['failures'] as $failure)
                $this->console->error($failure);
            foreach ($report['warnings'] as $warning)
                $this->console->warning($warning);
            foreach ($report['nextSteps'] as $step)
                $this->console->writeln($step);
            if ($report['ready'])
                $this->console->success(
                    'Local configuration is ready. Slack authorization and running workers have not been verified.',
                );
            else
                $this->console->warning('Setup is incomplete. Follow the steps above, then run doctor again.');
        }
        return $report['ready'] ? ExitCode::SUCCESS : ExitCode::ERROR;
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
