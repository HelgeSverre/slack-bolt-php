<?php

declare(strict_types=1);

namespace App\Demo\Jobs;

use App\Demo\KitchenSinkView;
use Tempest\CommandBus\CommandHandler;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Jobs\JobContext;
use Thunder\Bolt\Jobs\JobExecution;
use Thunder\Bolt\WebApi\Client;

final readonly class ShowKitchenSinkHandler
{
    public function __construct(
        private Client $client,
        private Jobs $jobs,
        private JobContext $context,
        private JobExecution $execution,
    ) {}

    #[CommandHandler]
    public function handle(ShowKitchenSink $job): void
    {
        $channel = $this->context->channelId ?? throw new \LogicException('A showcase needs a channel.');
        $this->execution->checkpoint();
        $response = $this->client
            ->chat()
            ->postMessage(['channel' => $channel, ...KitchenSinkView::message()->toArray()]);
        $thread = $response->get('ts');
        if (!is_string($thread) || $thread === '') {
            throw new \UnexpectedValueException('Slack did not return the showcase thread.');
        }
        $this->execution->checkpoint();
        $this->jobs->in(new JobContext(
            teamId: $this->context->teamId,
            enterpriseId: $this->context->enterpriseId,
            isEnterpriseInstall: $this->context->isEnterpriseInstall,
            channelId: $channel,
            threadTs: $thread,
            userId: $this->context->userId,
        ))->dispatch(new StreamKitchenSink(), key: $this->execution->job->id . ':session');
    }
}
