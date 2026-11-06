<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Runtime;

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;
use Tempest\Cryptography\Encryption\EncryptionAlgorithm;
use Tempest\Cryptography\Encryption\EncryptionKey;
use Thunder\Bolt\Runtime\SigningKeyFile;

final class SigningKeyFileTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bolt-key-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        foreach (new \FilesystemIterator($this->root) as $file)
            unlink($file->getPathname());
        rmdir($this->root);
    }

    public function test_generation_preserves_other_settings_and_existing_keys_until_forced(): void
    {
        $path = $this->root . '/.env';
        file_put_contents($path, "# keep this comment\nSLACK_BOT_TOKEN=private-canary\nSIGNING_KEY=\n");
        $file = new SigningKeyFile($this->root);
        self::assertTrue($file->generate(EncryptionAlgorithm::AES_256_GCM));
        $first = file_get_contents($path);
        $values = Dotenv::parse($first);
        self::assertSame('private-canary', $values['SLACK_BOT_TOKEN']);
        self::assertStringContainsString('# keep this comment', $first);
        self::assertSame(32, strlen(base64_decode($values['SIGNING_KEY'], true)));
        self::assertInstanceOf(EncryptionKey::class, EncryptionKey::fromString(
            $values['SIGNING_KEY'],
            EncryptionAlgorithm::AES_256_GCM,
        ));
        self::assertFalse($file->generate(EncryptionAlgorithm::AES_256_GCM));
        self::assertSame($first, file_get_contents($path));
        self::assertTrue($file->generate(EncryptionAlgorithm::AES_256_GCM, force: true));
        self::assertNotSame($values['SIGNING_KEY'], Dotenv::parse(file_get_contents($path))['SIGNING_KEY']);
        self::assertSame([], glob($this->root . '/.bolt-key-*'));
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, fileperms($path) & 0777);
        }
    }

    public function test_missing_env_is_not_silently_created_without_the_app_settings(): void
    {
        $this->expectException(\RuntimeException::class);
        new SigningKeyFile($this->root)->generate(EncryptionAlgorithm::AES_256_GCM);
    }

    public function test_symlinked_secrets_are_not_replaced(): void
    {
        if (PHP_OS_FAMILY === 'Windows')
            self::markTestSkipped('Symlinks require privileges on Windows.');
        file_put_contents($this->root . '/secrets', 'SIGNING_KEY=');
        symlink($this->root . '/secrets', $this->root . '/.env');
        try {
            new SigningKeyFile($this->root)->generate(EncryptionAlgorithm::AES_256_GCM);
            self::fail('A symlinked environment file must not be replaced.');
        } catch (\RuntimeException) {
            self::assertSame('SIGNING_KEY=', file_get_contents($this->root . '/secrets'));
            self::assertTrue(is_link($this->root . '/.env'));
        }
    }
}
