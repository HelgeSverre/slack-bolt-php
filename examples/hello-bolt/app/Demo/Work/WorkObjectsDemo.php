<?php

declare(strict_types=1);

namespace App\Demo\Work;

use App\Demo\DemoConfig;
use App\Demo\DemoJob;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\Events\EntityCommentsRequested;
use Thunder\Bolt\Payloads\Events\EntityDetailsRequested;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WorkObjects\Actor;
use Thunder\Bolt\WorkObjects\Comments\CommentOperation;
use Thunder\Bolt\WorkObjects\Comments\Comments;
use Thunder\Bolt\WorkObjects\Entity;
use Thunder\Bolt\WorkObjects\EntityField;
use Thunder\Bolt\WorkObjects\ExternalReference;

final readonly class WorkObjectsDemo
{
    private WorkItemStore $store;
    private Comments $comments;

    public function __construct(
        private DemoConfig $config,
        private Client $client,
    ) {
        $this->store = new WorkItemStore($config->directory . '/work-items.json');
        $this->comments = new Comments($client, $this->store, $this->store->allows(...), 'demo_work_delete_comment');
    }

    public function handle(DemoJob $job, string $id): void
    {
        $actor = new Actor($job->team, $job->user);
        switch ($job->operation) {
            case 'open':
                $reference = new ExternalReference(substr($id, 0, 12), 'bolt-work-item');
                $this->store->ensureItem($actor, $reference->id, trim($job->text) ?: 'A small piece of work');
                $entity = $this->entity($actor, $reference);
                $message = Message::text('Work item: ' . (trim($job->text) ?: 'A small piece of work'))->entities(
                    $entity,
                );
                $this->client
                    ->chat()
                    ->postMessage([
                        'channel' => $job->channel,
                        ...$message->toArray(),
                        ...($job->thread !== null ? ['thread_ts' => $job->thread] : []),
                    ]);
                break;
            case 'details':
                $event = EntityDetailsRequested::fromArray($job->payload);
                if (!$this->store->allows($actor, $event->reference, CommentOperation::Read)) {
                    $this->client
                        ->entity()
                        ->presentDetails([
                            'trigger_id' => $event->triggerId,
                            'error' => ['message' => 'This work item is unavailable.'],
                        ]);
                    break;
                }
                $this->client->workObjects()->details($event->triggerId, $this->entity($actor, $event->reference));
                break;
            case 'comments':
                $this->comments->present(EntityCommentsRequested::fromArray($job->payload), $actor);
                break;
            case 'post_comment':
            case 'edit_comment':
                $this->comments->submit(View::fromArray($job->payload), $actor, $id);
                break;
            case 'delete_comment':
                $this->comments->delete(Action::fromArray($job->payload), $actor, $id);
                break;
            case 'unfurl':
                $links = $job->payload['links'] ?? [];
                foreach (is_array($links) ? $links : [] as $link) {
                    if (!is_array($link) || !is_string($link['url'] ?? null)) {
                        continue;
                    }
                    $reference = $this->referenceForUrl($link['url']);
                    if ($reference === null || !$this->store->allows($actor, $reference, CommentOperation::Read)) {
                        continue;
                    }
                    $this->client->workObjects()->unfurl(
                        $job->channel,
                        $job->message ?? '',
                        $this->entity($actor, $reference)->unfurlUrl($link['url']),
                    );
                }
                break;
            default:
                throw new \InvalidArgumentException('Unknown work item operation.');
        }
    }

    private function referenceForUrl(string $url): ?ExternalReference
    {
        $base = rtrim($this->config->workObjectBaseUrl, '/') . '/';
        if (!str_starts_with($url, $base)) {
            return null;
        }
        $id = substr($url, strlen($base));
        return preg_match('/\A[a-f0-9]{12}\z/', $id) ? new ExternalReference($id, 'bolt-work-item') : null;
    }

    private function entity(Actor $actor, ExternalReference $reference): Entity
    {
        $item = $this->store->item($actor, $reference) ?? throw new \RuntimeException('Work item not found.');
        return Entity::task(
            $reference,
            rtrim($this->config->workObjectBaseUrl, '/') . '/' . $reference->id,
            $item['title'],
        )
            ->attribute('display_id', $reference->id)
            ->field('status', EntityField::status('Open', 'blue'))
            ->field(
                'description',
                EntityField::text(
                    'A shared work item. Open Comments to discuss it; you can edit or delete your own comments.',
                ),
            )
            ->comments($item['comment_count'], $item['latest_timestamp']);
    }
}
