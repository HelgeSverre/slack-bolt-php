<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class TaskCard implements Block
{
    private const STATUSES = ['pending', 'in_progress', 'complete', 'error'];

    /** @param list<URLSourceElement> $sources */
    private function __construct(
        private string $taskId,
        private string $title,
        private string $status,
        private ?RichText $details = null,
        private ?RichText $output = null,
        private array $sources = [],
    ) {}

    public static function make(string $taskId, string $title, string $status): self
    {
        if ($taskId === '' || $title === '') {
            throw new \InvalidArgumentException('A task card needs a task ID and title.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('A task status must be pending, in_progress, complete, or error.');
        }

        return new self($taskId, $title, $status);
    }

    #[\NoDiscard]
    public function details(RichText $details): self
    {
        return clone($this, ['details' => $details]);
    }

    #[\NoDiscard]
    public function output(RichText $output): self
    {
        return clone($this, ['output' => $output]);
    }

    #[\NoDiscard]
    public function sources(URLSourceElement ...$sources): self
    {
        return clone($this, ['sources' => $sources]);
    }

    public function toArray(): array
    {
        $data = [
            'type' => 'task_card',
            'task_id' => $this->taskId,
            'title' => $this->title,
            'status' => $this->status,
        ];
        if ($this->details !== null) {
            $data['details'] = $this->details->toArray();
        }
        if ($this->output !== null) {
            $data['output'] = $this->output->toArray();
        }
        if ($this->sources !== []) {
            $data['sources'] = array_map(
                static fn(URLSourceElement $source): array => $source->toArray(),
                $this->sources,
            );
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
