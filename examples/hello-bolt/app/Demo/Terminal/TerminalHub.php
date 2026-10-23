<?php

declare(strict_types=1);

namespace App\Demo\Terminal;

final class TerminalHub
{
    private const string RUNNING = 'running';
    private const string CLOSED = 'closed';
    private const string EXPIRED = 'expired';
    private const string TRUNCATED = "[terminal output truncated]\n";

    /** @var array<string, array{owner: string, session: TerminalSession, screen: string, activityAt: int, changed: bool}> */
    private array $sessions = [];

    /** @var array<string, TerminalSnapshot> */
    private array $pending = [];

    /** @var array<string, true> */
    private array $allowedUsers = [];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * The interactive shell is not sandboxed. Only a verified, explicitly
     * allowed Slack user may own a session, and the supplied directory must be
     * a dedicated local demo workspace.
     *
     * @param list<string|int> $allowedUserIds
     * @param null|callable(): int $clock
     */
    public function __construct(
        private readonly string $workingDirectory,
        array $allowedUserIds,
        private readonly int $maxSessions = 3,
        private readonly int $maxInputBytes = 4096,
        private readonly int $maxOutputBytes = 12_000,
        private readonly int $idleTimeoutSeconds = 900,
        ?callable $clock = null,
    ) {
        if (!is_dir($this->workingDirectory) || realpath($this->workingDirectory) === false) {
            throw new \InvalidArgumentException('Terminal working directory must exist.');
        }
        if (
            $this->maxSessions < 1
            || $this->maxInputBytes < 1
            || $this->maxOutputBytes < 128
            || $this->idleTimeoutSeconds < 1
        ) {
            throw new \InvalidArgumentException('Terminal limits must be positive.');
        }
        foreach ($allowedUserIds as $userId) {
            if (!is_string($userId) || $userId === '') {
                throw new \InvalidArgumentException('Terminal allowed user IDs must be non-empty strings.');
            }
            $this->allowedUsers[$userId] = true;
        }
        $this->clock = $clock === null
            ? time(...)
            : static function () use ($clock): int {
                return $clock();
            };
    }

    public function open(string $sessionId, string $userId): void
    {
        $this->assertAuthorized($userId);
        $this->assertSessionId($sessionId);
        if (isset($this->sessions[$sessionId])) {
            throw new TerminalException('The terminal session is already open.');
        }
        if (count($this->sessions) >= $this->maxSessions) {
            throw new TerminalLimitExceeded('The terminal session limit has been reached.');
        }

        $this->sessions[$sessionId] = [
            'owner' => $userId,
            'session' => TerminalSession::start($this->workingDirectory, $this->environment()),
            'screen' => '',
            'activityAt' => $this->now(),
            'changed' => true,
        ];
    }

    public function input(string $sessionId, string $userId, string $text): void
    {
        if ($text === '' || strlen($text) > $this->maxInputBytes) {
            throw new TerminalLimitExceeded('Terminal input exceeds the configured limit.');
        }
        $session = &$this->ownedSession($sessionId, $userId);
        $session['session']->input($text);
        $session['activityAt'] = $this->now();
    }

    public function interrupt(string $sessionId, string $userId): void
    {
        $session = &$this->ownedSession($sessionId, $userId);
        $session['session']->interrupt();
        $session['activityAt'] = $this->now();
    }

    public function close(string $sessionId, string $userId): void
    {
        $session = $this->ownedSession($sessionId, $userId);
        $this->finish($sessionId, $session, self::CLOSED);
    }

    /** @return array<string, TerminalSnapshot> changed sessions keyed by trusted session ID */
    public function poll(): array
    {
        $this->expireIdleSessions();
        $updates = $this->pending;
        $this->pending = [];

        foreach (array_keys($this->sessions) as $sessionId) {
            $session = &$this->sessions[$sessionId];
            $this->captureOutput($session);
            if (!$session['session']->running()) {
                $finished = $session;
                unset($session);
                $this->finish($sessionId, $finished, self::CLOSED);

                continue;
            }
            if ($session['changed']) {
                $updates[$sessionId] = $this->snapshot($sessionId, $session, self::RUNNING);
                $session['changed'] = false;
            }
            unset($session);
        }

        $updates = [...$updates, ...$this->pending];
        $this->pending = [];

        return $updates;
    }

    public function shutdown(): void
    {
        foreach ($this->sessions as $sessionId => $session) {
            $this->finish($sessionId, $session, self::CLOSED);
        }
    }

    /** @return array{owner: string, session: TerminalSession, screen: string, activityAt: int, changed: bool} */
    private function &ownedSession(string $sessionId, string $userId): array
    {
        $this->assertAuthorized($userId);
        $this->assertSessionId($sessionId);
        if (!isset($this->sessions[$sessionId])) {
            throw new TerminalSessionNotFound('The terminal session was not found.');
        }
        if ($this->sessions[$sessionId]['owner'] !== $userId) {
            throw new TerminalUnauthorized('This terminal session belongs to another user.');
        }

        return $this->sessions[$sessionId];
    }

    /** @param array{owner: string, session: TerminalSession, screen: string, activityAt: int, changed: bool} $session */
    private function finish(string $sessionId, array $session, string $status): void
    {
        $this->captureOutput($session);
        $session['session']->close();
        unset($this->sessions[$sessionId]);
        $this->pending[$sessionId] = $this->snapshot($sessionId, $session, $status);
    }

    private function expireIdleSessions(): void
    {
        $now = $this->now();
        foreach ($this->sessions as $sessionId => $session) {
            if (($now - $session['activityAt']) < $this->idleTimeoutSeconds) {
                continue;
            }
            $this->finish($sessionId, $session, self::EXPIRED);
        }
    }

    /** @param array{owner: string, session: TerminalSession, screen: string, activityAt: int, changed: bool} $session */
    private function captureOutput(array &$session): void
    {
        $output = $this->sanitize($session['session']->readOutput($this->maxOutputBytes * 2));
        if ($output === '') {
            return;
        }
        $session['screen'] = $this->trimScreen($session['screen'] . $output);
        $session['changed'] = true;
    }

    /** @param array{owner: string, session: TerminalSession, screen: string, activityAt: int, changed: bool} $session */
    private function snapshot(string $sessionId, array $session, string $status): TerminalSnapshot
    {
        return new TerminalSnapshot($sessionId, $session['screen'], $status);
    }

    private function assertAuthorized(string $userId): void
    {
        if (!isset($this->allowedUsers[$userId])) {
            throw new TerminalUnauthorized('This user is not allowed to use the local terminal.');
        }
    }

    private function assertSessionId(string $sessionId): void
    {
        if (preg_match('/\\A[A-Za-z0-9_-]{16,128}\\z/', $sessionId) !== 1) {
            throw new \InvalidArgumentException('Terminal session IDs must be trusted opaque identifiers.');
        }
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        return [
            'HOME' => $this->workingDirectory,
            'PATH' => '/usr/bin:/bin',
            'TERM' => 'dumb',
            'LANG' => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
            'PS1' => '',
            'ENV' => '',
            'HISTFILE' => '/dev/null',
            'HISTSIZE' => '0',
            'SAVEHIST' => '0',
        ];
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    private function sanitize(string $output): string
    {
        if (!mb_check_encoding($output, 'UTF-8')) {
            $converted = mb_convert_encoding($output, 'UTF-8', 'UTF-8');
            $output = is_string($converted) ? $converted : '[invalid terminal output]';
        }
        $output = preg_replace('/\\x1B\\][^\\x07]*(?:\\x07|\\x1B\\\\\\\\)/', '', $output) ?? $output;
        $output = preg_replace('/\\x1B(?:\\[[0-?]*[ -\\/]*[@-~]|[@-_])/', '', $output) ?? $output;
        $output = str_replace(["\r\n", "\r"], "\n", $output);

        return preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', '', $output) ?? $output;
    }

    private function trimScreen(string $screen): string
    {
        if (strlen($screen) <= $this->maxOutputBytes) {
            return $screen;
        }
        $tail = mb_strcut(
            $screen,
            strlen($screen) - ($this->maxOutputBytes - strlen(self::TRUNCATED)),
            $this->maxOutputBytes - strlen(self::TRUNCATED),
            'UTF-8',
        );

        return self::TRUNCATED . $tail;
    }
}
