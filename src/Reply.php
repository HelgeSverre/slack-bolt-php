<?php

declare(strict_types=1);

namespace Thunder\Bolt;

use Thunder\Bolt\Exceptions\MissingReplyContext;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\Response;

final readonly class Reply
{
    public function __construct(
        private Client $client,
        private ?string $channelId,
        private ?string $threadTs,
        private ?string $userId,
        #[\SensitiveParameter]
        private ?string $responseUrl = null,
    ) {}

    public function inChannel(string|Message $message): Response
    {
        // The helper selects the destination: a reusable Message cannot force a thread here.
        $arguments = $this->messageArguments($message, $this->channel());
        unset($arguments['thread_ts']);

        return $this->client->call('chat.postMessage', $arguments);
    }

    public function inThread(string|Message $message): Response
    {
        // The incoming event's root timestamp wins over any destination carried by Message.
        $threadTs = $this->threadTs ?? throw new MissingReplyContext('message timestamp');
        $arguments = $this->messageArguments($message, $this->channel());
        $arguments['thread_ts'] = $threadTs;

        return $this->client->call('chat.postMessage', $arguments);
    }

    public function ephemeral(string|Message $message): Response
    {
        $arguments = $this->messageArguments($message, $this->channel());
        $arguments['user'] = $this->userId ?? throw new MissingReplyContext('user ID');

        return $this->client->call('chat.postEphemeral', $arguments);
    }

    /** Post through the delivery's response_url, without sending the bot token. */
    public function respond(string|Message|array $message): Response
    {
        $url = $this->responseUrl ?? throw new MissingReplyContext('response URL');
        $arguments = self::responseArguments(
            is_array($message)
                ? $message
                : ($message instanceof Message ? $message : Message::text($message))->toArray(),
        );

        return $this->client->respond($url, $arguments);
    }

    public function replaceOriginal(string|Message $message): Response
    {
        $arguments = ($message instanceof Message ? $message : Message::text($message))->toArray();

        return $this->respond([...$arguments, 'replace_original' => true]);
    }

    public function deleteOriginal(): Response
    {
        return $this->respond(['delete_original' => true]);
    }

    private function channel(): string
    {
        return $this->channelId ?? throw new MissingReplyContext('channel ID');
    }

    /** @return array<string, mixed> */
    private function messageArguments(string|Message $message, string $channel): array
    {
        return [
            'channel' => $channel,
            ...($message instanceof Message ? $message : Message::text($message))->toArray(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return array<string, mixed>
     */
    private static function responseArguments(array $arguments): array
    {
        foreach ($arguments as $key => $_) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Slack response fields must use string keys.');
            }
        }

        /** @var array<string, mixed> $arguments */
        return $arguments;
    }
}
