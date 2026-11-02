<?php

declare(strict_types=1);

namespace App;

use Tempest\CommandBus\CommandHandler;
use Thunder\Bolt\Jobs\JobExecution;

final readonly class LocalReceiptHandler
{
    public function __construct(
        private JobExecution $execution,
    ) {}

    #[CommandHandler]
    public function handle(LocalReceipt $job): void
    {
        $this->execution->checkpoint();
        file_put_contents(
            dirname(__DIR__) . '/.thunder-bolt/state/receipt-' . hash('sha256', $job->name) . '.txt',
            $job->name,
            LOCK_EX,
        );
    }
}
