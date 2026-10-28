<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Values;

/** A form cell or element action. Unknown Slack controls remain available through raw. */
final readonly class InputValue
{
    public ?string $type;

    public function __construct(
        public array $raw,
    ) {
        $this->type = self::string($raw['type'] ?? null);
    }

    public static function fromArray(array $cell): self
    {
        return new self($cell);
    }

    /** Text, number/email/URL inputs and button values stay strings without coercion. */
    public function text(): ?string
    {
        return self::string($this->raw['value'] ?? null);
    }

    public function selectedValue(): ?string
    {
        $key = match ($this->type) {
            'users_select' => 'selected_user',
            'channels_select' => 'selected_channel',
            'conversations_select' => 'selected_conversation',
            'static_select', 'external_select', 'overflow', 'radio_buttons' => 'selected_option',
            default => null,
        };
        $selected = $key === null ? null : $this->raw[$key] ?? null;
        return (
            $key === 'selected_option'
                ? (is_array($selected) ? self::string($selected['value'] ?? null) : null)
                : self::string($selected)
        );
    }

    /** @return list<string> */
    public function selectedValues(): array
    {
        $key = match ($this->type) {
            'multi_users_select' => 'selected_users',
            'multi_channels_select' => 'selected_channels',
            'multi_conversations_select' => 'selected_conversations',
            'multi_static_select', 'multi_external_select', 'checkboxes', 'select' => 'selected_options',
            default => null,
        };
        $values = $key === null ? null : $this->raw[$key] ?? null;
        if (!is_array($values) || !array_is_list($values)) {
            return [];
        }
        $selected = [];
        foreach ($values as $value) {
            $candidate = $key === 'selected_options' ? (is_array($value) ? $value['value'] ?? null : null) : $value;
            if (is_string($candidate)) {
                $selected[] = $candidate;
            }
        }
        return $selected;
    }

    /** Calendar dates stay timezone-free YYYY-MM-DD strings. */
    public function date(): ?string
    {
        $date = self::string($this->raw['selected_date'] ?? null);
        if ($date === null || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts) !== 1) {
            return null;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $date : null;
    }

    public function time(): ?string
    {
        $time = self::string($this->raw['selected_time'] ?? null);
        return $time !== null && preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $time) === 1 ? $time : null;
    }

    public function timestamp(): ?int
    {
        $timestamp = $this->raw['selected_date_time'] ?? null;
        return is_int($timestamp) ? $timestamp : null;
    }

    /** @return list<UploadedFile> */
    public function files(): array
    {
        $files = $this->raw['files'] ?? null;
        if (!is_array($files) || !array_is_list($files)) {
            return [];
        }
        $uploads = [];
        foreach ($files as $file) {
            if (is_array($file) && is_string($file['id'] ?? null) && $file['id'] !== '') {
                $uploads[] = UploadedFile::fromArray($file);
            }
        }
        return $uploads;
    }

    public function richText(): ?array
    {
        $value = $this->raw['rich_text_value'] ?? null;
        return is_array($value) && ($value['type'] ?? null) === 'rich_text' ? $value : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
