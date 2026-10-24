<?php

declare(strict_types=1);

namespace App\Demo\Functions;

use Closure;
use Thunder\Bolt\Functions\ExecutionContext;

/**
 * A deliberately small, local credential vault for the Tempest async demo.
 *
 * It is not a general queue: the command bus persists only the opaque reference
 * returned by store(). The workflow token is encrypted at rest and is released
 * only to the worker that atomically changes the record from pending to running.
 */
final readonly class FunctionExecutionVault
{
    private const int MAX_TTL_SECONDS = 900;

    private const int DEFAULT_TTL_SECONDS = 840;

    /** @var Closure(): int */
    private Closure $now;

    public function __construct(
        private string $directory,
        private int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        ?callable $now = null,
    ) {
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('The queued custom-function demo requires the PHP sodium extension.');
        }
        if ($this->ttlSeconds < 1 || $this->ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new \InvalidArgumentException('Function execution vault TTL must be between 1 and 900 seconds.');
        }
        $this->now = $now === null
            ? static fn(): int => time()
            : static function () use ($now): int {
                $timestamp = $now();
                if (!is_int($timestamp)) {
                    throw new \LogicException('The function execution clock must return an integer timestamp.');
                }

                return $timestamp;
            };
        $this->ensureDirectory($this->directory);
    }

    /**
     * Store one token-free context snapshot plus an encrypted, short-lived
     * workflow credential. The tenant comes from trusted delivery metadata.
     */
    public function store(ExecutionContext $context, string $tenant = 'single-demo'): string
    {
        $token = $context->workflowToken();
        if ($token === null) {
            throw new \InvalidArgumentException(
                'The queued custom-function demo requires Slack workflow bot_access_token.',
            );
        }

        if (trim($tenant) === '' || str_contains($tenant, "\0")) {
            throw new \InvalidArgumentException('A trusted function execution tenant is required.');
        }
        $reference = hash('sha256', $tenant . "\0" . $context->executionId);
        $snapshot = $context->snapshot();
        $this->withLock($reference, function () use ($reference, $snapshot, $token): void {
            if (is_file($this->recordPath($reference))) {
                $record = $this->readRecord($reference);
                if (($record['context'] ?? null) !== $snapshot) {
                    throw new FunctionExecutionUnavailable(
                        'The function execution ID was redelivered with conflicting trusted context.',
                    );
                }

                return;
            }
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($token, $nonce, $this->key());
            $createdAt = $this->timestamp();
            $this->writeRecord($reference, [
                'version' => 1,
                'state' => 'pending',
                'created_at' => $createdAt,
                'expires_at' => $createdAt + $this->ttlSeconds,
                'context' => $snapshot,
                'credential' => [
                    'nonce' => base64_encode($nonce),
                    'ciphertext' => base64_encode($ciphertext),
                ],
            ]);
        });

        return $reference;
    }

    /**
     * Atomically claims work once. A running or terminal record returns null so
     * a duplicate Tempest command is a no-op. Expired and malformed records
     * fail closed for manual inspection.
     */
    public function claim(string $reference): ?ClaimedFunctionExecution
    {
        $claimed = null;
        $this->withLock($reference, function () use ($reference, &$claimed): void {
            $record = $this->readRecord($reference);
            if ($this->expired($record)) {
                $record['state'] = 'expired';
                unset($record['credential']);
                $this->writeRecord($reference, $record);
                throw new FunctionExecutionUnavailable('The queued function execution expired before it could run.');
            }
            if (in_array($record['state'] ?? null, ['running', 'completed', 'failed'], true)) {
                return;
            }
            if (($record['state'] ?? null) !== 'pending') {
                throw new FunctionExecutionUnavailable(
                    'The queued function execution is not pending and requires manual inspection.',
                );
            }

            if (!is_array($record['credential'] ?? null) || !is_array($record['context'] ?? null)) {
                throw new FunctionExecutionUnavailable('The protected function execution record is malformed.');
            }
            $token = $this->decrypt($record['credential']);
            $record['state'] = 'running';
            $record['claimed_at'] = $this->timestamp();
            $this->writeRecord($reference, $record);

            $claimed = new ClaimedFunctionExecution($reference, ExecutionContext::restore($record['context'], $token));
        });
        if ($claimed === null) {
            return null;
        }

        return $claimed;
    }

    /** Record a known terminal outcome and remove the encrypted credential. */
    public function settle(string $reference, string $state): void
    {
        if (!in_array($state, ['completed', 'failed'], true)) {
            throw new \InvalidArgumentException('A function execution can settle only as completed or failed.');
        }
        $this->withLock($reference, function () use ($reference, $state): void {
            $record = $this->readRecord($reference);
            if (($record['state'] ?? null) !== 'running') {
                throw new FunctionExecutionUnavailable('Only a running function execution can settle.');
            }
            $record['state'] = $state;
            $record['settled_at'] = $this->timestamp();
            unset($record['credential']);
            $this->writeRecord($reference, $record);
        });
    }

    /** A response transport failure is deliberately retained for a human to inspect. */
    public function markUncertain(string $reference): void
    {
        $this->withLock($reference, function () use ($reference): void {
            $record = $this->readRecord($reference);
            if (!in_array($record['state'] ?? null, ['pending', 'running'], true)) {
                return;
            }
            $record['state'] = 'uncertain';
            $record['uncertain_at'] = $this->timestamp();
            $this->writeRecord($reference, $record);
        });
    }

    /** @return array{state:string, createdAt:int, expiresAt:int, executionId:string} */
    public function inspect(string $reference): array
    {
        $inspection = null;
        $this->withLock($reference, function () use ($reference, &$inspection): void {
            $record = $this->readRecord($reference);
            if (
                !is_array($record['context'] ?? null)
                || !is_string($record['state'] ?? null)
                || !is_int($record['created_at'] ?? null)
                || !is_int($record['expires_at'] ?? null)
                || !is_string($record['context']['execution_id'] ?? null)
            ) {
                throw new FunctionExecutionUnavailable('The protected function execution record is malformed.');
            }
            $inspection = [
                'state' => $record['state'],
                'createdAt' => $record['created_at'],
                'expiresAt' => $record['expires_at'],
                'executionId' => $record['context']['execution_id'],
            ];
        });
        if (!is_array($inspection)) {
            throw new \LogicException('The protected function execution inspection did not complete.');
        }
        return $inspection;
    }

    private function key(): string
    {
        $path = $this->directory . '/.key';
        $lockPath = $this->directory . '/.key.lock';
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new \RuntimeException('Could not open the protected function vault key lock.');
        }
        try {
            chmod($lockPath, 0600);
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Could not lock the protected function vault key.');
            }
            try {
                if (is_file($path)) {
                    $key = file_get_contents($path);
                    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                        throw new FunctionExecutionUnavailable('The function execution vault key is invalid.');
                    }
                    chmod($path, 0600);
                    return $key;
                }

                $key = sodium_crypto_secretbox_keygen();
                $handle = fopen($path, 'x+b');
                if ($handle === false) {
                    throw new \RuntimeException('Could not create the protected function vault key.');
                }
                try {
                    chmod($path, 0600);
                    $this->writeAll($handle, $key);
                    fflush($handle);
                    $this->sync($handle);
                } finally {
                    fclose($handle);
                }

                return $key;
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    private function decrypt(array $credential): string
    {
        if (!is_string($credential['nonce'] ?? null) || !is_string($credential['ciphertext'] ?? null)) {
            throw new FunctionExecutionUnavailable('The protected function credential is malformed.');
        }
        $nonce = base64_decode($credential['nonce'], true);
        $ciphertext = base64_decode($credential['ciphertext'], true);
        if (!is_string($nonce) || !is_string($ciphertext) || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new FunctionExecutionUnavailable('The protected function credential is malformed.');
        }
        $token = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key());
        if ($token === false || $token === '') {
            throw new FunctionExecutionUnavailable('The protected function credential cannot be opened.');
        }

        return $token;
    }

    private function expired(array $record): bool
    {
        return !is_int($record['expires_at'] ?? null) || $record['expires_at'] <= $this->timestamp();
    }

    private function readRecord(string $reference): array
    {
        $this->assertReference($reference);
        $contents = file_get_contents($this->recordPath($reference));
        if (!is_string($contents)) {
            throw new FunctionExecutionUnavailable('The protected function execution does not exist.');
        }
        try {
            if (
                !is_array($record = json_decode($contents, true, flags: JSON_THROW_ON_ERROR)) || array_is_list($record)
            ) {
                throw new FunctionExecutionUnavailable('The protected function execution record is malformed.');
            }
        } catch (\JsonException $exception) {
            throw new FunctionExecutionUnavailable(
                'The protected function execution record is unreadable.',
                previous: $exception,
            );
        }
        return $record;
    }

    private function writeRecord(string $reference, array $record): void
    {
        $this->atomicWrite(
            $this->recordPath($reference),
            json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    private function withLock(string $reference, Closure $operation): mixed
    {
        $this->assertReference($reference);
        $path = $this->directory . '/' . $reference . '.lock';
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Could not open the protected function execution lock.');
        }
        try {
            chmod($path, 0600);
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock the protected function execution.');
            }
            try {
                return $operation();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('Could not create the protected function execution record.');
        }
        try {
            chmod($temporary, 0600);
            $this->writeAll($handle, $contents);
            fflush($handle);
            $this->sync($handle);
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('Could not atomically store the protected function execution record.');
            }
            chmod($path, 0600);
        } finally {
            fclose($handle);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the function execution vault directory.');
        }
        chmod($directory, 0700);
    }

    private function assertReference(string $reference): void
    {
        if (!preg_match('/\\A[a-f0-9]{64}\\z/', $reference)) {
            throw new \InvalidArgumentException('Invalid protected function execution reference.');
        }
    }

    private function recordPath(string $reference): string
    {
        return $this->directory . '/' . $reference . '.json';
    }

    /** @param resource $handle */
    private function writeAll($handle, string $contents): void
    {
        $written = 0;
        $length = strlen($contents);
        while ($written < $length) {
            $count = fwrite($handle, substr($contents, $written));
            if ($count === false || $count === 0) {
                throw new \RuntimeException('Could not write protected function execution data.');
            }
            $written += $count;
        }
    }

    /** @param resource $handle */
    private function sync($handle): void
    {
        if (function_exists('fsync')) {
            fsync($handle);
        }
    }

    private function timestamp(): int
    {
        return ($this->now)();
    }
}
