<?php

declare(strict_types=1);

namespace App\Demo\Jobs;

use Tempest\CommandBus\CommandHandler;
use Thunder\Bolt\Assistant\MessageStream;
use Thunder\Bolt\Assistant\Session;
use Thunder\Bolt\Assistant\Streaming\TaskStatus;
use Thunder\Bolt\Assistant\Streaming\TaskUpdate;
use Thunder\Bolt\Assistant\ThreadIdentity;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Jobs\JobCancelled;
use Thunder\Bolt\Jobs\JobContext;
use Thunder\Bolt\Jobs\JobExecution;
use Thunder\Bolt\Jobs\LeaseLost;
use Thunder\Bolt\WebApi\Client;

use function Amp\delay;

final readonly class StreamKitchenSinkHandler
{
    public function __construct(
        private Client $client,
        private JobContext $context,
        private JobExecution $execution,
    ) {}

    #[CommandHandler]
    public function handle(StreamKitchenSink $job): void
    {
        $channel = $this->context->channelId ?? throw new \LogicException('A session needs a channel.');
        $root = $this->context->threadTs ?? throw new \LogicException('A session needs a thread.');
        $user = $this->context->userId ?? throw new \LogicException('A session needs a recipient.');
        $identity = new ThreadIdentity(
            new ConversationKey(
                $channel,
                teamId: $this->context->isEnterpriseInstall ? null : $this->context->teamId,
                enterpriseId: $this->context->enterpriseId,
                isEnterpriseInstall: $this->context->isEnterpriseInstall,
                threadTs: $root,
            ),
            $user,
        );
        $session = new Session($this->client, $identity);
        $stream = new MessageStream($this->client, $identity, ['task_display_mode' => 'timeline']);
        try {
            $this->execution->checkpoint();
            $session->processing();
            $stream->append(
                "A named SQLite job is running this session. Stop it with Slack’s Stop control or the button above.\n\n",
            );
            $this->step($stream, 'persist', 'Durable intake', 'The job was committed before acknowledgement.');
            $this->step(
                $stream,
                'scope',
                'Fresh delivery context',
                'The worker resolved current credentials and isolated thread state.',
            );
            $this->step(
                $stream,
                'cancel',
                'Cancellation checkpoints',
                'Stop reaches running work. AI requests use the same persisted cancellation signal.',
            );
            $stream->append(
                "\nDone. Ask AI runs a real model request; the other buttons open the pet, a work item and a typed modal.",
            );
            $stream->stop();
        } catch (JobCancelled $exception) {
            // Slack may already have stopped this stream before delivering its stop event.
            try {
                if ($stream->timestamp() !== null) {
                    $stream->stop();
                }
            } catch (\Throwable) {
                // Session status is settled independently below.
            }
            $session->active();
            $this->client
                ->chat()
                ->postMessage([
                    'channel' => $channel,
                    'thread_ts' => $root,
                    'text' => 'Stopped. The durable job will settle as cancelled.',
                ]);
            throw $exception;
        } catch (LeaseLost $exception) {
            // Recovery owns this attempt; do not overwrite a successor's session.
            throw $exception;
        } catch (\Throwable $exception) {
            try {
                $session->active();
            } catch (\Throwable) {
                // Keep the original failure for durable job inspection.
            }
            throw $exception;
        }
    }

    private function step(MessageStream $stream, string $id, string $title, string $text): void
    {
        $this->execution->heartbeat();
        $task = TaskUpdate::make($id, $title);
        $stream->chunks([$task]);
        for ($tick = 0; $tick < 16; $tick++) {
            delay(0.25);
            $this->execution->checkpoint();
        }
        $stream->chunks([$task->status(TaskStatus::Complete)]);
        $stream->append($text . "\n");
    }
}
