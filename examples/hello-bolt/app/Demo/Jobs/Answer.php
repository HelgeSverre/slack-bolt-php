<?php

declare(strict_types=1);

namespace App\Demo\Jobs;

use Thunder\Bolt\Jobs\Job;

/** One user turn. The worker reads credentials and history at execution time. */
final readonly class Answer implements Job
{
    public function __construct(
        public string $text,
        public ?string $thread = null,
        public ?string $title = null,
    ) {
        if (strlen($text) > 8_000 || $thread !== null && $thread === '') {
            throw new \InvalidArgumentException('An AI turn needs bounded text and an optional root timestamp.');
        }
        if ($title !== null && (trim($title) === '' || mb_strlen($title) > 1_000)) {
            throw new \InvalidArgumentException('An AI session title must contain at most 1000 characters.');
        }
    }
}
