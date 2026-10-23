<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\Terminal\TerminalHub;
use App\Demo\Terminal\TerminalSessionNotFound;
use App\Demo\Terminal\TerminalSnapshot;
use App\Demo\Terminal\TerminalUnauthorized;
use PHPUnit\Framework\TestCase;

final class TerminalHubTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/thunder-bolt-terminal-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        mkdir($this->directory . '/nested', 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory . '/nested')) {
            rmdir($this->directory . '/nested');
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function test_a_persistent_local_shell_keeps_its_working_directory_and_output_screen(): void
    {
        $hub = $this->hub();
        $sessionId = $this->sessionId('a');
        $hub->open($sessionId, 'U_OWNER');

        try {
            $hub->input($sessionId, 'U_OWNER', 'printf "terminal-ready\\n"');
            self::assertStringContainsString('terminal-ready', $this->untilOutput($hub, $sessionId));

            $hub->input($sessionId, 'U_OWNER', 'pwd');
            self::assertStringContainsString($this->directory, $this->untilOutput($hub, $sessionId));

            $hub->input($sessionId, 'U_OWNER', 'cd nested');
            $hub->input($sessionId, 'U_OWNER', 'pwd');
            $screen = $this->untilOutput($hub, $sessionId);
            self::assertStringContainsString($this->directory . '/nested', $screen);
        } finally {
            $hub->shutdown();
        }
    }

    public function test_terminal_output_removes_terminal_escapes_and_keeps_a_bounded_rolling_screen(): void
    {
        $hub = $this->hub(maxOutputBytes: 128);
        $sessionId = $this->sessionId('b');
        $hub->open($sessionId, 'U_OWNER');

        try {
            $hub->input($sessionId, 'U_OWNER', "printf '\\033[31mred\\033[0m\\n'");
            $screen = $this->untilOutput($hub, $sessionId);
            self::assertStringContainsString('red', $screen);
            self::assertStringNotContainsString("\x1b", $screen);

            $hub->input($sessionId, 'U_OWNER', "printf '\\377\\n'");
            $screen = $this->untilOutput($hub, $sessionId);
            self::assertTrue(mb_check_encoding($screen, 'UTF-8'));

            $hub->input(
                $sessionId,
                'U_OWNER',
                'printf "abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyz\\n"',
            );
            $screen = $this->untilOutput($hub, $sessionId, 'abcdefghijklmnopqrstuvwxyz');
            self::assertLessThanOrEqual(128, strlen($screen));
            self::assertStringStartsWith('[terminal output truncated]', $screen);
        } finally {
            $hub->shutdown();
        }
    }

    public function test_every_operation_requires_the_allowed_session_owner(): void
    {
        $hub = $this->hub();
        $sessionId = $this->sessionId('c');

        $this->expectException(TerminalUnauthorized::class);
        $hub->open($sessionId, 'U_OTHER');
    }

    public function test_an_empty_allowlist_disables_terminal_opening_without_breaking_the_demo(): void
    {
        $hub = new TerminalHub($this->directory, []);

        $this->expectException(TerminalUnauthorized::class);
        $hub->open($this->sessionId('z'), 'U_OWNER');
    }

    public function test_other_users_cannot_input_interrupt_or_close_an_owned_session(): void
    {
        $hub = $this->hub();
        $sessionId = $this->sessionId('d');
        $hub->open($sessionId, 'U_OWNER');

        try {
            foreach (['input', 'interrupt', 'close'] as $operation) {
                try {
                    if ($operation === 'input') {
                        $hub->input($sessionId, 'U_OTHER', 'pwd');
                    } elseif ($operation === 'interrupt') {
                        $hub->interrupt($sessionId, 'U_OTHER');
                    } else {
                        $hub->close($sessionId, 'U_OTHER');
                    }
                    self::fail("Expected {$operation} to reject another user.");
                } catch (TerminalUnauthorized) {
                    self::addToAssertionCount(1);
                }
            }
        } finally {
            $hub->shutdown();
        }
    }

    public function test_idle_sessions_are_closed_and_reported_once(): void
    {
        $now = 100;
        $hub = $this->hub(idleTimeoutSeconds: 5, clock: static function () use (&$now): int {
            return $now;
        });
        $sessionId = $this->sessionId('e');
        $hub->open($sessionId, 'U_OWNER');
        $hub->poll();
        $now = 105;

        $update = $hub->poll()[$sessionId] ?? null;
        self::assertInstanceOf(TerminalSnapshot::class, $update);
        self::assertSame('expired', $update->status);
        self::assertSame([], $hub->poll());

        $this->expectException(TerminalSessionNotFound::class);
        $hub->input($sessionId, 'U_OWNER', 'pwd');
    }

    public function test_a_shell_that_exits_reports_one_closed_snapshot_and_is_cleaned_up(): void
    {
        $hub = $this->hub();
        $sessionId = $this->sessionId('f');
        $hub->open($sessionId, 'U_OWNER');

        $hub->input($sessionId, 'U_OWNER', 'exit');
        $closed = null;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            usleep(10_000);
            $update = $hub->poll()[$sessionId] ?? null;
            if ($update instanceof TerminalSnapshot && $update->status === 'closed') {
                $closed = $update;

                break;
            }
        }

        self::assertInstanceOf(TerminalSnapshot::class, $closed);
        self::assertSame('closed', $closed->status);
        self::assertSame([], $hub->poll());
    }

    public function test_interrupt_reaches_the_foreground_command_without_losing_the_next_input(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || !is_executable('/usr/bin/script')) {
            self::markTestSkipped('This foreground terminal test requires the POSIX script utility.');
        }

        $hub = $this->hub();
        $sessionId = $this->sessionId('g');
        $hub->open($sessionId, 'U_OWNER');
        $hub->poll();

        try {
            $hub->input($sessionId, 'U_OWNER', 'sleep 5');
            usleep(300_000);
            $started = microtime(true);
            $hub->interrupt($sessionId, 'U_OWNER');
            $hub->input($sessionId, 'U_OWNER', 'printf "%s%s\\n" AFTER_ INTERRUPT_DONE');

            $screen = $this->untilOutput($hub, $sessionId, 'AFTER_INTERRUPT_DONE');
            self::assertStringContainsString('AFTER_INTERRUPT_DONE', $screen);
            self::assertLessThan(2.0, microtime(true) - $started);
        } finally {
            $hub->shutdown();
        }
    }

    public function test_shutdown_does_not_wait_for_a_shell_that_ignores_term(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || !is_executable('/usr/bin/script')) {
            self::markTestSkipped('This bounded-shutdown test requires the POSIX script utility.');
        }

        $hub = $this->hub();
        $sessionId = $this->sessionId('h');
        $hub->open($sessionId, 'U_OWNER');

        $hub->input($sessionId, 'U_OWNER', "trap '' TERM; sleep 5");
        usleep(100_000);
        $started = microtime(true);
        $hub->shutdown();

        self::assertLessThan(1.0, microtime(true) - $started);
        self::assertSame('closed', ($hub->poll()[$sessionId] ?? throw new \LogicException())->status);
    }

    private function hub(
        int $maxOutputBytes = 12_000,
        int $idleTimeoutSeconds = 900,
        ?callable $clock = null,
    ): TerminalHub {
        return new TerminalHub(
            $this->directory,
            ['U_OWNER'],
            maxOutputBytes: $maxOutputBytes,
            idleTimeoutSeconds: $idleTimeoutSeconds,
            clock: $clock,
        );
    }

    private function untilOutput(TerminalHub $hub, string $sessionId, string $needle = ''): string
    {
        $screen = '';
        for ($attempt = 0; $attempt < 50; $attempt++) {
            usleep(10_000);
            $snapshot = $hub->poll()[$sessionId] ?? null;
            if ($snapshot instanceof TerminalSnapshot) {
                $screen = $snapshot->output;
            }
            if ($screen !== '' && ($needle === '' || str_contains($screen, $needle))) {
                return $screen;
            }
        }

        self::fail('The terminal did not produce expected output.');
    }

    private function sessionId(string $character): string
    {
        return str_repeat($character, 64);
    }
}
