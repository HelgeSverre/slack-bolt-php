<?php

declare(strict_types=1);

namespace App\Demo\Jobs;

use Thunder\Bolt\Jobs\Job;

/** Durable, deterministic onboarding for Slack's native assistant thread. */
final readonly class StartAssistantSession implements Job {}
