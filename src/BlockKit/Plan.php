<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Plan implements Block
{
    /** @param list<TaskCard> $tasks */
    private function __construct(
        private string $title,
        private array $tasks = [],
    ) {}

    public static function make(string $title, TaskCard ...$tasks): self
    {
        if ($title === '') {
            throw new \InvalidArgumentException('A plan needs a title.');
        }

        return new self($title, array_values($tasks));
    }

    #[\NoDiscard]
    public function tasks(TaskCard ...$tasks): self
    {
        return clone($this, ['tasks' => $tasks]);
    }

    public function toArray(): array
    {
        $data = ['type' => 'plan', 'title' => $this->title];
        if ($this->tasks !== []) {
            $data['tasks'] = array_map(static fn(TaskCard $task): array => $task->toArray(), $this->tasks);
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
