<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Card implements Block
{
    /** @param list<Button> $actions */
    private function __construct(
        private ?ImageElement $heroImage = null,
        private ?ImageElement $icon = null,
        private ?Text $title = null,
        private ?Text $subtitle = null,
        private ?Text $body = null,
        private array $actions = [],
    ) {}

    public static function make(): self
    {
        return new self();
    }

    #[\NoDiscard]
    public function hero(ImageElement $image): self
    {
        return clone($this, ['heroImage' => $image]);
    }

    #[\NoDiscard]
    public function icon(ImageElement $image): self
    {
        return clone($this, ['icon' => $image]);
    }

    #[\NoDiscard]
    public function title(string|Text $text): self
    {
        return clone($this, ['title' => self::markdown(
            $text,
            150,
            'A card title needs markdown up to 150 characters.',
        )]);
    }

    #[\NoDiscard]
    public function subtitle(string|Text $text): self
    {
        return clone($this, ['subtitle' => self::markdown(
            $text,
            150,
            'A card subtitle needs markdown up to 150 characters.',
        )]);
    }

    #[\NoDiscard]
    public function body(string|Text $text): self
    {
        return clone($this, ['body' => self::markdown($text, 200, 'A card body needs markdown up to 200 characters.')]);
    }

    #[\NoDiscard]
    public function actions(Button ...$actions): self
    {
        if (count($actions) > 3) {
            throw new \InvalidArgumentException('A card supports at most three action buttons.');
        }

        return clone($this, ['actions' => $actions]);
    }

    public function toArray(): array
    {
        if ($this->heroImage === null && $this->title === null && $this->body === null && $this->actions === []) {
            throw new \LogicException('A card needs a hero image, title, body, or action.');
        }

        $data = ['type' => 'card'];
        if ($this->heroImage !== null) {
            $data['hero_image'] = $this->heroImage->toArray();
        }
        if ($this->icon !== null) {
            $data['icon'] = $this->icon->toArray();
        }
        if ($this->title !== null) {
            $data['title'] = $this->title->toArray();
        }
        if ($this->subtitle !== null) {
            $data['subtitle'] = $this->subtitle->toArray();
        }
        if ($this->body !== null) {
            $data['body'] = $this->body->toArray();
        }
        if ($this->actions !== []) {
            $data['actions'] = array_map(static fn(Button $button): array => $button->toArray(), $this->actions);
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function markdown(string|Text $text, int $maximum, string $message): Text
    {
        $text = is_string($text) ? Text::markdown($text) : $text;
        if ($text->type !== 'mrkdwn' || mb_strlen($text->value) > $maximum) {
            throw new \InvalidArgumentException($message);
        }

        return $text;
    }
}
