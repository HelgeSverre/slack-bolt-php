<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Confirm implements \JsonSerializable
{
    private function __construct(
        private Text $title,
        private Text $text,
        private Text $confirm,
        private Text $deny,
        private ?string $style = null,
    ) {}

    public static function make(
        string|Text $title,
        string|Text $text,
        string|Text $confirm = 'Confirm',
        string|Text $deny = 'Cancel',
    ): self {
        $texts = array_map(static fn($value): Text => is_string($value) ? Text::plain($value) : $value, [
            $title,
            $text,
            $confirm,
            $deny,
        ]);
        [$title, $text, $confirm, $deny] = $texts;
        foreach ([$title, $confirm, $deny] as $plainText) {
            if ($plainText->type !== 'plain_text') {
                throw new \InvalidArgumentException('Confirmation title and buttons require plain text.');
            }
        }
        if (
            mb_strlen($title->value) > 100
            || mb_strlen($text->value) > 300
            || mb_strlen($confirm->value) > 30
            || mb_strlen($deny->value) > 30
        ) {
            throw new \InvalidArgumentException('Confirmation text exceeds Slack\'s title, body, or button limits.');
        }

        return new self($title, $text, $confirm, $deny);
    }

    #[\NoDiscard]
    public function danger(): self
    {
        return clone($this, ['style' => 'danger']);
    }

    #[\NoDiscard]
    public function primary(): self
    {
        return clone($this, ['style' => 'primary']);
    }

    public function toArray(): array
    {
        $d = [
            'title' => $this->title->toArray(),
            'text' => $this->text->toArray(),
            'confirm' => $this->confirm->toArray(),
            'deny' => $this->deny->toArray(),
        ];
        if ($this->style !== null)
            $d['style'] = $this->style;
        return $d;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
