<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\Ai\JobCancellation;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Jobs\JobCancelled;
use Thunder\Bolt\Jobs\JobContext;
use Thunder\Bolt\Jobs\JobExecution;
use Thunder\Bolt\Jobs\RetryPolicy;
use Thunder\Bolt\Jobs\SqliteJobStore;

use function Amp\delay;

final class DurableAiCancellationTest extends TestCase
{
    public function test_persisted_cancel_interrupts_the_amp_token_while_a_request_is_in_flight(): void
    {
        $store = SqliteJobStore::forTesting();
        $result = $store->enqueue(
            'App\\Demo\\Jobs\\Answer',
            ['text' => 'slow request', 'thread' => '1.0', 'title' => null],
            JobContext::workspace('T1', 'C1', '1.0', 'U1'),
            'cancel-me',
            RetryPolicy::once(),
        );
        $record = $store->claim(60);
        self::assertNotNull($record);

        $bridge = new JobCancellation(new JobExecution($store, $record, 60));
        try {
            $token = $bridge->start();
            $store->cancel($result->id, 'team:T1');
            delay(0.3);

            self::assertTrue($token->isRequested());
            $this->expectException(JobCancelled::class);
            $bridge->checkpoint();
        } finally {
            $bridge->close();
        }
    }
}
