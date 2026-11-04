<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Alert implements Block
{
    private const LEVELS = ['default', 'info', 'warning', 'error', 'success'];

    private function __construct(
        private Text $text,
        private string $level = 'default',
    ) {}

    public static function make(string|Text $text, string $level = 'default'): self
    {
        if (!in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException('An alert level must be default, info, warning, error, or success.');
        }

        $text = is_string($text) ? Text::plain($text) : $text;
        if (mb_strlen($text->value) > 200) {
            throw new \InvalidArgumentException('An alert message needs at most 200 characters.');
        }

        return new self($text, $level);
    }

    #[\NoDiscard]
    public function level(string $level): self
    {
        return self::make($this->text, $level);
    }

    #[\NoDiscard]
    public function info(): self
    {
        return $this->level('info');
    }

    #[\NoDiscard]
    public function warning(): self
    {
        return $this->level('warning');
    }

    #[\NoDiscard]
    public function error(): self
    {
        return $this->level('error');
    }

    #[\NoDiscard]
    public function success(): self
    {
        return $this->level('success');
    }

    public function toArray(): array
    {
        $data = ['type' => 'alert', 'text' => $this->text->toArray()];
        if ($this->level !== 'default') {
            $data['level'] = $this->level;
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
