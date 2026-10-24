<?php

declare(strict_types=1);

namespace App\Demo\Functions;

use App\Demo\DemoConfig;
use Tempest\CommandBus\CommandHandler;
use Thunder\Bolt\Functions\Execution;
use Thunder\Bolt\WebApi\Client;

/** Native Tempest handler for the reference-only async command. */
final readonly class FormatMessageWorker
{
    public function __construct(
        private DemoConfig $config,
        private Client $client,
    ) {}

    #[CommandHandler]
    public function handle(FormatMessageCommand $command): void
    {
        $vault = new FunctionExecutionVault($this->config->directory . '/function-executions');
        $claimed = $vault->claim($command->reference);
        if ($claimed === null) {
            return;
        }
        $token = $claimed->context->workflowToken();
        if ($token === null) {
            $vault->markUncertain($command->reference);
            throw new FunctionExecutionUnavailable('The protected function credential is unavailable.');
        }
        $execution = new Execution($this->client->withToken($token), $claimed->context);

        try {
            $message = trim($execution->inputs()->string('message'));
            if ($message === '') {
                throw new \InvalidArgumentException('The message input cannot be empty.');
            }
            $execution->complete(['message' => 'Formatted: ' . $message]);
            $vault->settle($command->reference, 'completed');
        } catch (\Throwable) {
            // Business failure is made terminal once. If that API response is
            // ambiguous, retain the encrypted credential for manual inspection.
            try {
                if (!$execution->hasResponded()) {
                    $execution->fail('The format-message demo could not process this step.');
                    $vault->settle($command->reference, 'failed');
                    return;
                }
            } catch (\Throwable) {
                $vault->markUncertain($command->reference);
            }

            $vault->markUncertain($command->reference);
            throw new FunctionExecutionUnavailable(
                'The function completion outcome is uncertain; inspect the vault record.',
            );
        }
    }
}
