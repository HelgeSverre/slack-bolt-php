<?php

declare(strict_types=1);

namespace App\Demo\Terminal;

/** @internal The hub owns session creation, authorization, and lifecycle. */
final class TerminalSession
{
    /** @param array<int, resource> $pipes */
    private function __construct(
        private mixed $process,
        private array $pipes,
        public readonly bool $usesPty,
    ) {}

    /**
     * A terminal is deliberately not a sandbox. The caller must ensure its owner
     * is trusted before a shell is started.
     *
     * @param array<string, string> $environment
     */
    public static function start(string $workingDirectory, array $environment): self
    {
        $command = self::command();

        if (self::usesScriptWrapper()) {
            $pipes = self::openPipes($command, $workingDirectory, $environment);
            if ($pipes === null) {
                throw new TerminalException('Unable to start the local terminal shell.');
            }

            return new self($pipes['process'], $pipes['pipes'], true);
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            $pty = self::openPty($command, $workingDirectory, $environment);
            if ($pty !== null) {
                return new self($pty['process'], $pty['pipes'], true);
            }
        }

        $pipes = self::openPipes($command, $workingDirectory, $environment);
        if ($pipes === null) {
            throw new TerminalException('Unable to start the local terminal shell.');
        }

        return new self($pipes['process'], $pipes['pipes'], false);
    }

    public function input(string $text): void
    {
        $stdin = $this->pipes[0] ?? null;
        if (!is_resource($stdin) || !$this->running()) {
            throw new TerminalSessionNotFound('The terminal session is no longer running.');
        }

        $this->write($stdin, str_ends_with($text, "\n") ? $text : $text . "\n");
    }

    public function interrupt(): void
    {
        if (!$this->running()) {
            return;
        }

        if ($this->usesPty && isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            $this->write($this->pipes[0], "\x03");

            return;
        }

        $process = $this->processOrNull();
        if ($process !== null) {
            proc_terminate($process);
        }
    }

    public function running(): bool
    {
        $process = $this->processOrNull();

        return $process !== null && proc_get_status($process)['running'];
    }

    public function readOutput(int $maximumBytes): string
    {
        $output = '';
        foreach ([1, 2] as $index) {
            $stream = $this->pipes[$index] ?? null;
            if (!is_resource($stream)) {
                continue;
            }
            while (strlen($output) < $maximumBytes) {
                $chunk = stream_get_contents($stream, min(8192, $maximumBytes - strlen($output)));
                if (!is_string($chunk) || $chunk === '') {
                    break;
                }
                $output .= $chunk;
            }
        }

        return $output;
    }

    public function close(): void
    {
        $process = $this->processOrNull();
        if ($process === null) {
            return;
        }

        $stdin = $this->pipes[0] ?? null;
        if ($this->running() && is_resource($stdin)) {
            fwrite($stdin, "exit\n");
        }
        foreach ($this->pipes as $pipe) {
            if (!is_resource($pipe)) {
                continue;
            }
            fclose($pipe);
        }
        $this->pipes = [];

        $this->terminate($process);
        if (!$this->running()) {
            proc_close($process);
        }
        $this->process = null;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     * @return array{process: resource, pipes: array<int, resource>}|null
     */
    private static function openPty(array $command, string $workingDirectory, array $environment): ?array
    {
        if ($workingDirectory === '') {
            return null;
        }
        $pipes = [];
        $previous = set_error_handler(static fn(): bool => true);
        try {
            // @mago-expect analysis:possibly-invalid-argument -- PHP's POSIX proc_open supports pty descriptors.
            $process = proc_open(
                $command,
                [0 => ['pty'], 1 => ['pty'], 2 => ['pty']],
                $pipes,
                $workingDirectory,
                $environment,
            );
        } finally {
            restore_error_handler();
        }
        unset($previous);

        return self::ready($process, $pipes);
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     * @return array{process: resource, pipes: array<int, resource>}|null
     */
    private static function openPipes(array $command, string $workingDirectory, array $environment): ?array
    {
        if ($workingDirectory === '') {
            return null;
        }
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
            $environment,
        );

        return self::ready($process, $pipes);
    }

    /**
     * @param array<array-key, mixed> $pipes
     * @return array{process: resource, pipes: array<int, resource>}|null
     */
    private static function ready(mixed $process, array $pipes): ?array
    {
        if (!is_resource($process) || !isset($pipes[0], $pipes[1], $pipes[2])) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }

            return null;
        }
        if (!is_resource($pipes[0]) || !is_resource($pipes[1]) || !is_resource($pipes[2])) {
            foreach ([0, 1, 2] as $index) {
                if (!isset($pipes[$index]) || !is_resource($pipes[$index])) {
                    continue;
                }
                fclose($pipes[$index]);
            }
            proc_terminate($process);
            proc_close($process);

            return null;
        }

        $pipes = [0 => $pipes[0], 1 => $pipes[1], 2 => $pipes[2]];
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        return ['process' => $process, 'pipes' => $pipes];
    }

    /** @return resource|null */
    private function processOrNull(): mixed
    {
        return is_resource($this->process) ? $this->process : null;
    }

    /** @return list<string> */
    private static function command(): array
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return [getenv('ComSpec') ?: 'cmd.exe', '/Q'];
        }

        if (PHP_OS_FAMILY === 'Darwin' && is_executable('/usr/bin/script')) {
            return ['/usr/bin/script', '-q', '/dev/null', '/bin/sh'];
        }
        if (is_executable('/usr/bin/script')) {
            return ['/usr/bin/script', '-qefc', '/bin/sh', '/dev/null'];
        }

        return ['/bin/sh'];
    }

    private static function usesScriptWrapper(): bool
    {
        return DIRECTORY_SEPARATOR !== '\\' && is_executable('/usr/bin/script');
    }

    /** @param resource $process */
    private function terminate(mixed $process): void
    {
        if (!proc_get_status($process)['running']) {
            return;
        }

        proc_terminate($process);
        $deadline = hrtime(true) + 200_000_000;
        while (proc_get_status($process)['running'] && hrtime(true) < $deadline) {
            usleep(10_000);
        }
        if (!proc_get_status($process)['running']) {
            return;
        }

        proc_terminate($process, defined('SIGKILL') ? SIGKILL : 9);
        $deadline = hrtime(true) + 200_000_000;
        while (proc_get_status($process)['running'] && hrtime(true) < $deadline) {
            usleep(10_000);
        }
    }

    /** @param resource $stream */
    private function write(mixed $stream, string $input): void
    {
        $offset = 0;
        $attempts = 0;
        while ($offset < strlen($input)) {
            $written = fwrite($stream, substr($input, $offset));
            if ($written === false) {
                throw new TerminalSessionNotFound('The terminal session is no longer writable.');
            }
            if ($written > 0) {
                $offset += $written;
                $attempts = 0;

                continue;
            }
            if (++$attempts > 5) {
                throw new TerminalException('The terminal is busy; try again shortly.');
            }
            usleep(10_000);
        }
    }
}
