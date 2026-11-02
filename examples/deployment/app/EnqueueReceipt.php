<?php

declare(strict_types=1);

namespace App;

use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Jobs\JobContext;
use Thunder\Bolt\Jobs\RetryPolicy;

final class EnqueueReceipt
{
    #[ConsoleCommand(name: 'demo:enqueue', description: 'Queue an idempotent local receipt without contacting Slack')]
    public function enqueue(string $name = 'deployment-check'): ExitCode
    {
        $result = \Tempest\Container\get(Jobs::class)
            ->in(JobContext::workspace('T-deployment-example', 'C-local'))
            ->dispatch(new LocalReceipt($name), key: $name, retry: RetryPolicy::idempotent());
        fwrite(STDOUT, json_encode(['id' => $result->id, 'duplicate' => $result->duplicate], JSON_THROW_ON_ERROR)
            . PHP_EOL);
        return ExitCode::SUCCESS;
    }
}
