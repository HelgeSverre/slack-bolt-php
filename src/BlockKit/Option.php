<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Option implements \JsonSerializable
{
    private function __construct(
        private string $label,
        private string $optionValue,
        private ?string $optionDescription = null,
        private ?string $optionUrl = null,
        private bool $markdown = false,
    ) {}

    public static function text(string $label, string $value): self
    {
        self::validateText($label, 'label', 75);
        self::validateText($value, 'value', 75);

        return new self($label, $value);
    }

    public static function markdown(string $label, string $value): self
    {
        return clone(self::text($label, $value), ['markdown' => true]);
    }

    public function requirePlainText(): void
    {
        if ($this->markdown) {
            throw new \InvalidArgumentException(
                'This control requires plain-text options. Markdown options belong to checkboxes and radio buttons.',
            );
        }
    }

    #[\NoDiscard]
    public function description(string $description): self
    {
        self::validateText($description, 'description', 75);

        return clone($this, ['optionDescription' => $description]);
    }

    #[\NoDiscard]
    public function url(string $url): self
    {
        self::validateText($url, 'URL', 3000);
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('An option URL must be a valid absolute URL.');
        }

        return clone($this, ['optionUrl' => $url]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'text' => ['type' => $this->markdown ? 'mrkdwn' : 'plain_text', 'text' => $this->label],
            'value' => $this->optionValue,
        ];
        if ($this->optionDescription !== null) {
            $data['description'] = ['type' => 'plain_text', 'text' => $this->optionDescription];
        }
        if ($this->optionUrl !== null) {
            $data['url'] = $this->optionUrl;
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function validateText(string $value, string $field, int $maximum): void
    {
        if ($value === '' || mb_strlen($value) > $maximum) {
            throw new \InvalidArgumentException("An option {$field} needs between 1 and {$maximum} characters.");
        }
    }
}
