<?php

declare(strict_types=1);

namespace Thunder\Bolt\Runtime;

use Dotenv\Dotenv;
use Tempest\Cryptography\Encryption\EncryptionAlgorithm;
use Tempest\Cryptography\Encryption\EncryptionKey;

/** Atomically updates the local cookie key without printing or silently rotating it. */
final readonly class SigningKeyFile
{
    public function __construct(
        private string $root,
    ) {}

    public function generate(EncryptionAlgorithm $algorithm, bool $force = false): bool
    {
        $path = $this->root . '/.env';
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException(
                'Create a regular .env file from .env.example first; manage symlinked secrets externally.',
            );
        }
        // Lock a separate file so Windows can atomically replace .env too.
        $lockPath = $this->root . '/.bolt-key.lock';
        if (is_link($lockPath)) {
            throw new \RuntimeException('The key-generation lock must not be a symlink.');
        }
        $lock = fopen($lockPath, 'c+');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open the key-generation lock. Check project directory permissions.');
        }
        $temporary = null;
        try {
            if (!chmod($lockPath, 0600)) {
                throw new \RuntimeException('Cannot secure the key-generation lock.');
            }
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock the local .env file.');
            }
            $content = file_get_contents($path);
            if ($content === false) {
                throw new \RuntimeException('Cannot read the local .env file.');
            }
            try {
                $values = Dotenv::parse($content);
            } catch (\Throwable) {
                throw new \RuntimeException('The local .env file is invalid. Fix its syntax before generating a key.');
            }
            if (!$force && trim($values['SIGNING_KEY'] ?? '') !== '') {
                return false;
            }
            $line = 'SIGNING_KEY=' . EncryptionKey::generate($algorithm)->toString();
            $pattern = '/^[ \t]*(?:export[ \t]+)?SIGNING_KEY[ \t]*=.*$/m';
            $updated = preg_match($pattern, $content)
                ? preg_replace($pattern, $line, $content)
                : rtrim($content) . PHP_EOL . $line . PHP_EOL;
            $temporary = tempnam($this->root, '.bolt-key-');
            if (
                !is_string($updated)
                || $temporary === false
                || file_put_contents($temporary, $updated, LOCK_EX) === false
                || !chmod($temporary, 0600)
                || !rename($temporary, $path)
            ) {
                throw new \RuntimeException('Could not save SIGNING_KEY; the existing .env was preserved.');
            }

            return true;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
