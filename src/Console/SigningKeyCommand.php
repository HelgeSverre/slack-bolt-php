<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

use Tempest\Console\Console;
use Tempest\Console\ConsoleArgument;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Tempest\Core\Kernel;
use Tempest\Cryptography\Encryption\EncryptionConfig;
use Thunder\Bolt\Runtime\SigningKeyFile;

final readonly class SigningKeyCommand
{
    public function __construct(
        private Console $console,
        private Kernel $kernel,
        private EncryptionConfig $encryption,
    ) {}

    #[ConsoleCommand(name: 'key:generate', description: 'Set a missing local browser-session key without printing it')]
    public function generate(
        #[ConsoleArgument(description: 'Replace an existing key; invalidates encrypted cookies and sessions')]
        bool $force = false,
    ): ExitCode {
        try {
            $generated = new SigningKeyFile($this->kernel->root)->generate($this->encryption->algorithm, $force);
        } catch (\RuntimeException $exception) {
            $this->console->error($exception->getMessage());
            return ExitCode::ERROR;
        }
        $this->console->success(
            $generated
                ? 'SIGNING_KEY saved to .env. Its value was not printed.'
                : 'SIGNING_KEY already exists; left unchanged. Use --force only for intentional rotation.',
        );

        return ExitCode::SUCCESS;
    }
}
