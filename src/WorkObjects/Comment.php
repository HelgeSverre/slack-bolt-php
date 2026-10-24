<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects;

final readonly class Comment implements \JsonSerializable
{
    private function __construct(
        public string $id,
        public Sender $sender,
        public CommentBody $body,
        public int $timestamp,
        public ?string $threadRootId = null,
        private array $options = [],
    ) {}

    public static function make(string $id, Sender $sender, CommentBody $body, int $timestamp): self
    {
        Guard::text($id, 25);
        if ($timestamp < 0) {
            throw new \InvalidArgumentException('A comment timestamp must be non-negative.');
        }
        return new self($id, $sender, $body, $timestamp);
    }

    public static function fromArray(array $data): self
    {
        if (
            !is_string($data['id'] ?? null)
            || !is_array($data['sender'] ?? null)
            || !is_array($data['comment'] ?? null)
            || !is_int($data['timestamp'] ?? null)
        ) {
            throw new \InvalidArgumentException('Invalid persisted comment.');
        }
        $comment = self::make(
            $data['id'],
            Sender::fromArray($data['sender']),
            CommentBody::fromArray($data['comment']),
            $data['timestamp'],
        );
        if (is_string($data['thread_root_id'] ?? null)) {
            $comment = $comment->inThread($data['thread_root_id']);
        }
        $options = array_diff_key($data, array_flip(['id', 'sender', 'comment', 'timestamp', 'thread_root_id']));
        return clone($comment, ['options' => $options]);
    }

    public function inThread(string $root): self
    {
        Guard::text($root, 25);
        if ($root === $this->id) {
            throw new \InvalidArgumentException('A comment cannot reply to itself.');
        }
        return clone($this, ['threadRootId' => $root]);
    }

    public function permissions(bool $edit, bool $delete, bool $reply): self
    {
        return clone($this, ['options' => [
            ...$this->options,
            'can_edit' => $edit,
            'can_delete' => $delete,
            'can_reply' => $reply,
        ]]);
    }

    public function url(string $url): self
    {
        Guard::url($url);
        return $this->option('url', $url);
    }

    public function editedAt(int $timestamp): self
    {
        if ($timestamp < $this->timestamp) {
            throw new \InvalidArgumentException('An edit cannot predate its comment.');
        }
        return $this->option('last_edit_ts', $timestamp);
    }

    public function replies(int $count, ?int $latest = null, Sender ...$users): self
    {
        if ($count < 0 || count($users) > 2 || $latest !== null && $latest < 0) {
            throw new \InvalidArgumentException('Invalid reply preview.');
        }
        $comment = $this->option('reply_count', $count)->option('reply_users', array_map(
            static fn(Sender $user): array => $user->toArray(),
            $users,
        ));
        return $latest === null ? $comment : $comment->option('latest_reply_ts', $latest);
    }

    private function option(string $key, mixed $value): self
    {
        return clone($this, ['options' => [...$this->options, $key => $value]]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->options,
            'id' => $this->id,
            'sender' => $this->sender->toArray(),
            'timestamp' => $this->timestamp,
            'comment' => $this->body->toArray(),
            ...($this->threadRootId !== null ? ['thread_root_id' => $this->threadRootId] : []),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
