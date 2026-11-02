<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant\Streaming;

use Thunder\Bolt\BlockKit\URLSourceElement;

final readonly class TaskUpdate implements Chunk
{
    /** @param list<URLSourceElement> $sources */
    private function __construct(
        public string $id,
        public string $title,
        public TaskStatus $status,
        private ?string $details = null,
        private ?string $output = null,
        private array $sources = [],
    ) {}

    public static function make(string $id, string $title, TaskStatus|string $status = TaskStatus::InProgress): self
    {
        if (trim($id) === '' || trim($title) === '') {
            throw new \InvalidArgumentException('A task update needs an ID and title.');
        }
        if (is_string($status)) {
            $status = TaskStatus::tryFrom($status) ?? throw new \InvalidArgumentException('Invalid task status.');
        }
        return new self($id, $title, $status);
    }

    #[\NoDiscard]
    public function status(TaskStatus $status): self
    {
        return clone($this, ['status' => $status]);
    }

    #[\NoDiscard]
    public function details(string $details): self
    {
        self::validateText($details);
        return clone($this, ['details' => $details]);
    }

    #[\NoDiscard]
    public function output(string $output): self
    {
        self::validateText($output);
        return clone($this, ['output' => $output]);
    }

    #[\NoDiscard]
    public function sources(URLSourceElement ...$sources): self
    {
        if (count($sources) > 50) {
            throw new \InvalidArgumentException('A task supports at most 50 sources.');
        }
        return clone($this, ['sources' => array_values($sources)]);
    }

    public function toArray(): array
    {
        return [
            'type' => 'task_update',
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            ...($this->details !== null ? ['details' => $this->details] : []),
            ...($this->output !== null ? ['output' => $this->output] : []),
            ...(
                $this->sources !== []
                    ? ['sources' => array_map(
                        static fn(URLSourceElement $source): array => $source->toArray(),
                        $this->sources,
                    )] : []
            ),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function validateText(string $text): void
    {
        if (mb_strlen($text) > 12000) {
            throw new \InvalidArgumentException('Task details and output support at most 12000 characters each.');
        }
    }
}
