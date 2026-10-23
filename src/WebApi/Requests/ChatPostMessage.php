<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Requests;

use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\WebApi\RequestPayload;

final readonly class ChatPostMessage implements RequestPayload
{
    public function __construct(
        public string $channel,
        public string|Message $message,
        public ?string $threadTs = null,
    ) {
        if ($channel === '') {
            throw new \InvalidArgumentException('chat.postMessage requires a channel ID.');
        }
        if ($threadTs !== null && !preg_match('/\A[0-9]+\.[0-9]+\z/', $threadTs)) {
            throw new \InvalidArgumentException(
                'threadTs must be a Slack parent message timestamp, such as 1234567890.123456.',
            );
        }
    }

    public function toArray(): array
    {
        $message = is_string($this->message) ? Message::text($this->message) : $this->message;
        $data = ['channel' => $this->channel, ...$message->toArray()];
        if ($this->threadTs !== null) {
            $data['thread_ts'] = $this->threadTs;
        }

        return $data;
    }
}
