<?php

declare(strict_types=1);

namespace App\Demo\Jobs;

use Thunder\Bolt\Assistant\AppContext;
use Thunder\Bolt\Jobs\Job;

/** A new command preserves the serialized contract of already queued Answer jobs. */
final readonly class AgentAnswer implements Job
{
    public function __construct(
        public string $text,
        public array $appContext = [],
    ) {
        if (strlen($text) > 8000 || strlen(json_encode($appContext, JSON_THROW_ON_ERROR)) > 16000) {
            throw new \InvalidArgumentException('An agent turn needs bounded text and app context.');
        }
        AppContext::fromArray($appContext);
    }
}
