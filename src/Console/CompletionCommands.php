<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

use RuntimeException;
use Tempest\Console\Actions\BuildCompletionMetadata;
use Tempest\Console\Console;
use Tempest\Console\ConsoleArgument;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Tempest\Core\Kernel;

/**
 * Project-local completion commands for the `bolt` executable.
 *
 * Tempest's equivalents target its own executable and install under a user's
 * home directory. Bolt keeps generated metadata and optional scripts with the
 * project, so they can be reviewed and sourced explicitly.
 */
final readonly class CompletionCommands
{
    public function __construct(
        private Console $console,
        private Kernel $kernel,
        private BuildCompletionMetadata $buildMetadata,
    ) {}

    #[ConsoleCommand(name: 'completion:generate', description: 'Generate Bolt shell completion metadata')]
    public function generate(
        #[ConsoleArgument(description: 'Optional completion metadata JSON path')]
        ?string $path = null,
    ): ExitCode {
        $path ??= $this->metadataPath();

        try {
            $this->writeJson($path);
        } catch (RuntimeException) {
            $this->console->error("Unable to write completion metadata to {$path}.");

            return ExitCode::ERROR;
        }

        $this->console->success("Wrote Bolt completion metadata to {$path}");

        return ExitCode::SUCCESS;
    }

    #[ConsoleCommand(name: 'completion:show', description: 'Print a Bolt shell completion script')]
    public function show(#[ConsoleArgument(description: 'Shell: bash, zsh, or fish')] ?string $shell = null): ExitCode
    {
        $shell = $this->shell($shell);
        if ($shell === null) {
            return ExitCode::INVALID;
        }

        $script = file_get_contents($this->scriptPath($shell));
        if ($script === false) {
            $this->console->error("Bolt {$shell} completion script is unavailable.");

            return ExitCode::ERROR;
        }
        $this->console->writeRaw($script);

        return ExitCode::SUCCESS;
    }

    #[ConsoleCommand(name: 'completion:install', description: 'Write a project-local Bolt completion script')]
    public function install(
        #[ConsoleArgument(description: 'Shell: bash, zsh, or fish')]
        ?string $shell = null,
    ): ExitCode {
        $shell = $this->shell($shell);
        if ($shell === null) {
            return ExitCode::INVALID;
        }
        $script = file_get_contents($this->scriptPath($shell));
        if ($script === false) {
            $this->console->error("Bolt {$shell} completion script is unavailable.");

            return ExitCode::ERROR;
        }

        $directory = $this->completionDirectory();
        $target = "{$directory}/bolt.{$shell}";
        try {
            $this->writeJson($this->metadataPath());
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create the completion directory.');
            }
            if (file_put_contents($target, $script, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write the completion script.');
            }
        } catch (RuntimeException) {
            $this->console->error("Unable to install project-local {$shell} completion.");

            return ExitCode::ERROR;
        }

        $this->console->success("Wrote {$target}");
        $this->console->instructions(["Source it in this shell: source {$target}"]);

        return ExitCode::SUCCESS;
    }

    #[ConsoleCommand(name: 'completion:uninstall', description: 'Remove a project-local Bolt completion script')]
    public function uninstall(
        #[ConsoleArgument(description: 'Shell: bash, zsh, or fish')]
        ?string $shell = null,
    ): ExitCode {
        $shell = $this->shell($shell);
        if ($shell === null) {
            return ExitCode::INVALID;
        }
        $target = $this->completionDirectory() . "/bolt.{$shell}";
        if (!is_file($target)) {
            $this->console->info("No project-local {$shell} completion is installed.");

            return ExitCode::SUCCESS;
        }
        if (!unlink($target)) {
            $this->console->error("Unable to remove {$target}.");

            return ExitCode::ERROR;
        }
        $this->console->success("Removed {$target}");

        return ExitCode::SUCCESS;
    }

    private function shell(?string $shell): ?string
    {
        $shell ??= match (true) {
            str_contains((string) getenv('SHELL'), 'zsh') => 'zsh',
            str_contains((string) getenv('SHELL'), 'fish') => 'fish',
            default => 'bash',
        };
        if (!in_array($shell, ['bash', 'zsh', 'fish'], true)) {
            $this->console->error('Shell must be bash, zsh, or fish.');

            return null;
        }

        return $shell;
    }

    private function completionDirectory(): string
    {
        return $this->kernel->internalStorage . '/completion';
    }

    private function metadataPath(): string
    {
        return $this->completionDirectory() . '/commands.json';
    }

    private function scriptPath(string $shell): string
    {
        return dirname(__DIR__, 2) . "/stubs/completion.{$shell}";
    }

    private function writeJson(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the metadata directory.');
        }
        $json = json_encode(($this->buildMetadata)(), JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write completion metadata.');
        }
    }
}
