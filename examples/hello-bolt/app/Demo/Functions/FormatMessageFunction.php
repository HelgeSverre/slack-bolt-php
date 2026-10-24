<?php

declare(strict_types=1);

namespace App\Demo\Functions;

use App\Demo\DemoConfig;
use Tempest\CommandBus\CommandBus;
use Thunder\Bolt\Attributes\SlackFunction;
use Thunder\Bolt\Functions\Execution;
use Thunder\Bolt\Invocation\Delivery;

/** Shows an acknowledgement-safe handoff: persist first, then dispatch one opaque command. */
final readonly class FormatMessageFunction
{
    public function __construct(
        private DemoConfig $config,
    ) {}

    #[SlackFunction('demo_format_message')]
    public function __invoke(Execution $execution, CommandBus $commands, Delivery $delivery): void
    {
        $source = $delivery->authorizationSource;
        $tenant = $source?->isEnterpriseInstall === true
            ? ($source->enterpriseId !== null ? 'enterprise:' . $source->enterpriseId : null)
            : ($source?->teamId !== null ? 'team:' . $source->teamId : null);
        if ($tenant === null) {
            throw new \InvalidArgumentException('The queued function demo requires an identified installation.');
        }
        $reference = $this->vault()->store($execution->context, $tenant);
        $commands->dispatch(new FormatMessageCommand($reference));
    }

    private function vault(): FunctionExecutionVault
    {
        return new FunctionExecutionVault($this->config->directory . '/function-executions');
    }
}
