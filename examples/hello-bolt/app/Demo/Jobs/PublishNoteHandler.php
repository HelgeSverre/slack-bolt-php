<?php

declare(strict_types=1);

namespace App\Demo\Jobs;

use Tempest\CommandBus\CommandHandler;
use Thunder\Bolt\Jobs\JobExecution;
use Thunder\Bolt\Reply;

final readonly class PublishNoteHandler
{
    public function __construct(
        private Reply $reply,
        private JobExecution $execution,
    ) {}

    #[CommandHandler]
    public function handle(PublishNote $job): void
    {
        $this->execution->checkpoint();
        $this->reply->inThread($job->text);
    }
}
