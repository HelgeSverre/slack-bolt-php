<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant;

use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\Response;

final class Thread
{
    public function __construct(
        private readonly Client $client,
        public readonly ThreadIdentity $identity,
        private readonly ThreadContextStore $store,
        private ?ThreadContext $eventContext = null,
        private readonly ?AppContext $agentContext = null,
    ) {}

    public function context(): ?ThreadContext
    {
        if ($this->agentContext !== null) {
            return null;
        }
        return $this->eventContext ?? $this->store->get($this->identity, $this->client);
    }

    public function appContext(): ?AppContext
    {
        return $this->agentContext;
    }

    public function saveContext(?ThreadContext $context = null): void
    {
        if ($this->agentContext !== null) {
            throw new \LogicException(
                'Agent View focus belongs to this delivery. Persist application state explicitly.',
            );
        }
        $context ??= $this->eventContext;
        if ($context === null) {
            throw new \LogicException('No assistant context was supplied by Slack or the caller.');
        }
        $this->store->save($this->identity, $context, $this->client);
        $this->eventContext = $context;
    }

    public function say(string|Message $message): Response
    {
        $arguments = $message instanceof Message ? $message->toArray() : Message::text($message)->toArray();
        $context = $this->context();
        if ($context !== null) {
            $metadata = is_array($arguments['metadata'] ?? null) ? $arguments['metadata'] : [];
            if ($metadata === [] || ($metadata['event_type'] ?? null) === 'assistant_thread_context') {
                $payload = is_array($metadata['event_payload'] ?? null) ? $metadata['event_payload'] : [];
                $payload = [...$context->toArray(), ...$payload];
                $arguments['metadata'] = [
                    'event_type' => 'assistant_thread_context',
                    'event_payload' => $payload === [] ? new \stdClass() : $payload,
                ];
            }
        }

        return $this->client
            ->chat()
            ->postMessage([
                ...$arguments,
                'channel' => $this->identity->channelId(),
                'thread_ts' => $this->identity->threadTs(),
            ]);
    }

    /** @param array<string, mixed> $options */
    public function sayStream(array $options = []): MessageStream
    {
        return new MessageStream($this->client, $this->identity, $options);
    }

    /** Modern agent-session controls. Legacy assistant-thread controls remain below. */
    public function session(): Session
    {
        return new Session($this->client, $this->identity);
    }

    /** @param list<string> $loadingMessages */
    public function setStatus(string|Status $status, array $loadingMessages = []): Response
    {
        if ($status instanceof Status) {
            if ($loadingMessages !== []) {
                throw new \InvalidArgumentException(
                    'Pass loading messages through Status::loadingMessages() when using a Status value.',
                );
            }
            $arguments = $status->toArray();
        } else {
            $arguments = Status::make($status)->loadingMessages(...$loadingMessages)->toArray();
        }

        return $this->client
            ->assistant()
            ->threads()
            ->setStatus([
                'channel_id' => $this->identity->channelId(),
                'thread_ts' => $this->identity->threadTs(),
                ...$arguments,
            ]);
    }

    public function clearStatus(): Response
    {
        return $this->setStatus('');
    }

    public function setTitle(string $title): Response
    {
        self::text($title, 'Assistant thread title', 1000);

        return $this->client
            ->assistant()
            ->threads()
            ->setTitle([
                'channel_id' => $this->identity->channelId(),
                'thread_ts' => $this->identity->threadTs(),
                'title' => $title,
            ]);
    }

    /** @param list<SuggestedPrompt> $prompts */
    public function setSuggestedPrompts(array $prompts, ?string $title = null): Response
    {
        if (
            count($prompts) > 4
            || array_any($prompts, static fn(mixed $prompt): bool => !$prompt instanceof SuggestedPrompt)
        ) {
            throw new \InvalidArgumentException(
                'Assistant suggestions must contain zero to four SuggestedPrompt values.',
            );
        }
        if ($title !== null) {
            self::text($title, 'Assistant prompt title', 1000);
        }

        return $this->client
            ->assistant()
            ->threads()
            ->setSuggestedPrompts([
                'channel_id' => $this->identity->channelId(),
                ...($this->agentContext === null ? ['thread_ts' => $this->identity->threadTs()] : []),
                'prompts' => array_map(
                    static fn(SuggestedPrompt $prompt): array => $prompt->toArray(),
                    array_values($prompts),
                ),
                ...($title !== null ? ['title' => $title] : []),
            ]);
    }

    private static function text(string $value, string $name, int $limit): void
    {
        if (trim($value) === '' || mb_strlen($value) > $limit) {
            throw new \InvalidArgumentException("{$name} must contain 1 to {$limit} characters.");
        }
    }
}
