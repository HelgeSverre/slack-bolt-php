<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Picker implements Element
{
    private function __construct(
        private string $type,
        private string $actionId,
        private array $fields = [],
    ) {}

    public static function users(string $id): self
    {
        return self::select('users_select', $id);
    }

    public static function multiUsers(string $id): self
    {
        return self::select('multi_users_select', $id);
    }

    public static function channels(string $id): self
    {
        return self::select('channels_select', $id);
    }

    public static function multiChannels(string $id): self
    {
        return self::select('multi_channels_select', $id);
    }

    public static function conversations(string $id): self
    {
        return self::select('conversations_select', $id);
    }

    public static function multiConversations(string $id): self
    {
        return self::select('multi_conversations_select', $id);
    }

    public static function date(string $id): self
    {
        return self::select('datepicker', $id);
    }

    public static function time(string $id): self
    {
        return self::select('timepicker', $id);
    }

    public static function dateTime(string $id): self
    {
        return self::select('datetimepicker', $id);
    }

    public static function multiExternal(string $id): self
    {
        return self::select('multi_external_select', $id);
    }

    public static function email(string $id): self
    {
        return self::select('email_text_input', $id);
    }

    public static function url(string $id): self
    {
        return self::select('url_text_input', $id);
    }

    public static function file(string $id): self
    {
        return self::select('file_input', $id);
    }

    public static function richText(string $id): self
    {
        return self::select('rich_text_input', $id);
    }

    public static function number(string $id): self
    {
        return self::select('number_input', $id, ['is_decimal_allowed' => false]);
    }

    public static function checkboxes(string $id, Option ...$options): self
    {
        return self::options('checkboxes', $id, 10, array_values($options));
    }

    public static function multiStatic(string $id, Option ...$options): self
    {
        return self::options('multi_static_select', $id, 100, array_values($options));
    }

    public static function radio(string $id, Option ...$options): self
    {
        return self::options('radio_buttons', $id, 10, array_values($options));
    }

    public static function overflow(string $id, Option ...$options): self
    {
        return self::options('overflow', $id, 5, array_values($options));
    }

    #[\NoDiscard]
    public function placeholder(string|Text $text): self
    {
        $this->requires('placeholder', [
            'users_select',
            'multi_users_select',
            'channels_select',
            'multi_channels_select',
            'conversations_select',
            'multi_conversations_select',
            'static_select',
            'multi_static_select',
            'external_select',
            'multi_external_select',
            'datepicker',
            'timepicker',
            'email_text_input',
            'url_text_input',
            'number_input',
            'rich_text_input',
        ]);
        $text = is_string($text) ? Text::plain($text) : $text;
        if ($text->type !== 'plain_text' || mb_strlen($text->value) > 150) {
            throw new \InvalidArgumentException('A picker placeholder requires plain text of at most 150 characters.');
        }

        return $this->with('placeholder', $text);
    }

    #[\NoDiscard]
    public function confirm(Confirm $confirmation): self
    {
        $this->requires('a confirmation dialog', [
            'users_select',
            'multi_users_select',
            'channels_select',
            'multi_channels_select',
            'conversations_select',
            'multi_conversations_select',
            'datepicker',
            'datetimepicker',
            'timepicker',
            'checkboxes',
            'multi_static_select',
            'multi_external_select',
            'radio_buttons',
            'overflow',
        ]);

        return $this->with('confirm', $confirmation);
    }

    #[\NoDiscard]
    public function focusOnLoad(bool $enabled = true): self
    {
        $this->requires('focus on load', [
            'users_select',
            'multi_users_select',
            'channels_select',
            'multi_channels_select',
            'conversations_select',
            'multi_conversations_select',
            'datepicker',
            'datetimepicker',
            'timepicker',
            'checkboxes',
            'multi_static_select',
            'multi_external_select',
            'radio_buttons',
            'email_text_input',
            'url_text_input',
            'number_input',
            'rich_text_input',
        ]);

        return $this->with('focus_on_load', $enabled);
    }

    #[\NoDiscard]
    public function initial(string|int $value): self
    {
        $field = match ($this->type) {
            'datepicker' => 'initial_date',
            'datetimepicker' => 'initial_date_time',
            'timepicker' => 'initial_time',
            'users_select' => 'initial_user',
            'channels_select' => 'initial_channel',
            'conversations_select' => 'initial_conversation',
            'email_text_input', 'url_text_input', 'number_input' => 'initial_value',
            default => throw new \LogicException("{$this->type} does not accept one initial value."),
        };
        if ($value === '' || $this->type === 'datetimepicker' && !is_int($value)) {
            throw new \InvalidArgumentException('The initial value does not match this picker type.');
        }
        if ($this->type === 'datepicker' && (!is_string($value) || !self::dateValue($value))) {
            throw new \InvalidArgumentException('A date picker initial value must be YYYY-MM-DD.');
        }
        if (
            $this->type === 'timepicker'
            && (!is_string($value) || preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $value) !== 1)
        ) {
            throw new \InvalidArgumentException('A time picker initial value must be HH:mm.');
        }

        return $this->with($field, $value);
    }

    #[\NoDiscard]
    public function initialOptions(Option ...$options): self
    {
        $this->requires('initial options', ['checkboxes', 'multi_static_select', 'multi_external_select']);
        self::optionCount($options, 100, 'initial options');
        if ($this->type !== 'checkboxes') {
            foreach ($options as $option)
                $option->requirePlainText();
        }

        return $this->with('initial_options', array_values($options));
    }

    #[\NoDiscard]
    public function initialOption(Option $option): self
    {
        $this->requires('an initial option', ['radio_buttons']);

        return $this->with('initial_option', $option);
    }

    #[\NoDiscard]
    public function initialItems(string ...$ids): self
    {
        $field = match ($this->type) {
            'multi_users_select' => 'initial_users',
            'multi_channels_select' => 'initial_channels',
            'multi_conversations_select' => 'initial_conversations',
            default => throw new \LogicException("{$this->type} does not accept multiple initial items."),
        };
        if ($ids === [] || count($ids) > 100 || array_any($ids, static fn(string $id): bool => $id === '')) {
            throw new \InvalidArgumentException('Initial items need between 1 and 100 non-empty IDs.');
        }

        return $this->with($field, array_values($ids));
    }

    #[\NoDiscard]
    public function maxItems(int $count): self
    {
        $this->requires('a maximum item count', [
            'multi_users_select',
            'multi_channels_select',
            'multi_conversations_select',
            'multi_static_select',
            'multi_external_select',
        ]);
        if ($count < 1 || $count > 100) {
            throw new \InvalidArgumentException('Maximum selected items must be between 1 and 100.');
        }

        return $this->with('max_selected_items', $count);
    }

    #[\NoDiscard]
    public function minimumQueryLength(int $length): self
    {
        $this->requires('a query length', ['external_select', 'multi_external_select']);
        if ($length < 0 || $length > 100) {
            throw new \InvalidArgumentException('The minimum query length must be between 0 and 100.');
        }

        return $this->with('min_query_length', $length);
    }

    #[\NoDiscard]
    public function defaultToCurrentConversation(bool $enabled = true): self
    {
        $this->requires('a current-conversation default', ['conversations_select', 'multi_conversations_select']);

        return $this->with('default_to_current_conversation', $enabled);
    }

    #[\NoDiscard]
    public function conversationTypes(string ...$include): self
    {
        $this->requires('a conversation filter', ['conversations_select', 'multi_conversations_select']);
        foreach ($include as $type) {
            if (!in_array($type, ['im', 'mpim', 'private', 'public'], true)) {
                throw new \InvalidArgumentException('Conversation filters accept im, mpim, private, or public.');
            }
        }
        if ($include === []) {
            throw new \InvalidArgumentException('A conversation filter needs at least one conversation type.');
        }

        return $this->withFilter('include', array_values(array_unique($include)));
    }

    #[\NoDiscard]
    public function excludeExternalSharedChannels(): self
    {
        $this->requires('a conversation filter', ['conversations_select', 'multi_conversations_select']);

        return $this->withFilter('exclude_external_shared_channels', true);
    }

    #[\NoDiscard]
    public function excludeBotUsers(): self
    {
        $this->requires('a conversation filter', ['conversations_select', 'multi_conversations_select']);

        return $this->withFilter('exclude_bot_users', true);
    }

    #[\NoDiscard]
    public function responseUrlEnabled(bool $enabled = true): self
    {
        $this->requires('response URLs', ['conversations_select', 'channels_select']);

        return $this->with('response_url_enabled', $enabled);
    }

    #[\NoDiscard]
    public function timezone(string $timezone): self
    {
        $this->requires('a timezone', ['timepicker']);
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new \InvalidArgumentException('A time picker timezone must be a valid IANA timezone.');
        }

        return $this->with('timezone', $timezone);
    }

    #[\NoDiscard]
    public function allowDecimals(bool $allowed = true): self
    {
        $this->requires('decimal numbers', ['number_input']);

        return $this->with('is_decimal_allowed', $allowed);
    }

    #[\NoDiscard]
    public function range(int|float|string $minimum, int|float|string $maximum): self
    {
        $this->requires('a numeric range', ['number_input']);
        if (!is_numeric($minimum) || !is_numeric($maximum) || (float) $minimum > (float) $maximum) {
            throw new \InvalidArgumentException('A numeric range must contain ordered numeric values.');
        }

        return $this->with('min_value', (string) $minimum)->with('max_value', (string) $maximum);
    }

    #[\NoDiscard]
    public function fileTypes(string ...$types): self
    {
        $this->requires('file types', ['file_input']);
        if (
            $types === []
            || array_any($types, static fn(string $type): bool => $type === '' || str_contains($type, '/'))
        ) {
            throw new \InvalidArgumentException('File types need one or more file extensions.');
        }

        return $this->with('filetypes', array_values(array_unique($types)));
    }

    #[\NoDiscard]
    public function maxFiles(int $count): self
    {
        $this->requires('a maximum file count', ['file_input']);
        if ($count < 1 || $count > 10) {
            throw new \InvalidArgumentException('A file input allows between 1 and 10 files.');
        }

        return $this->with('max_files', $count);
    }

    #[\NoDiscard]
    public function dispatchOn(string ...$triggers): self
    {
        $this->requires('dispatch configuration', [
            'email_text_input',
            'url_text_input',
            'number_input',
            'rich_text_input',
        ]);
        if (
            $triggers === []
            || array_any(
                $triggers,
                static fn(string $trigger): bool => !in_array(
                    $trigger,
                    ['on_enter_pressed', 'on_character_entered'],
                    true,
                ),
            )
        ) {
            throw new \InvalidArgumentException('Dispatch requires on_enter_pressed and/or on_character_entered.');
        }

        return $this->with('dispatch_action_config', ['trigger_actions_on' => array_values(array_unique($triggers))]);
    }

    #[\NoDiscard]
    public function initialRichText(RichText $value): self
    {
        $this->requires('an initial rich text value', ['rich_text_input']);

        return $this->with('initial_value', $value);
    }

    #[\NoDiscard]
    public function visibleLines(int $minimum, ?int $maximum = null): self
    {
        $this->requires('visible lines', ['rich_text_input']);
        if (
            $minimum < 1
            || $minimum > 100
            || $maximum !== null && ($maximum < 1 || $maximum > 100 || $maximum < $minimum)
        ) {
            throw new \InvalidArgumentException('Rich text visible lines must be ordered and between 1 and 100.');
        }

        return $this->with('min_lines', $minimum)->with('max_lines', $maximum);
    }

    public function toArray(): array
    {
        return ['type' => $this->type, 'action_id' => $this->actionId, ...$this->serializedFields()];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function select(string $type, string $id, array $fields = []): self
    {
        self::actionId($id);

        return new self($type, $id, $fields);
    }

    /** @param list<Option> $options */
    private static function options(string $type, string $id, int $maximum, array $options): self
    {
        self::actionId($id);
        self::optionCount($options, $maximum, 'options');
        if (!in_array($type, ['checkboxes', 'radio_buttons'], true)) {
            foreach ($options as $option)
                $option->requirePlainText();
        }

        return new self($type, $id, ['options' => array_values($options)]);
    }

    private static function actionId(string $id): void
    {
        if ($id === '' || mb_strlen($id) > 255) {
            throw new \InvalidArgumentException('An action ID needs between 1 and 255 characters.');
        }
    }

    private static function optionCount(array $options, int $maximum, string $field): void
    {
        if ($options === [] || count($options) > $maximum) {
            throw new \InvalidArgumentException("Picker {$field} need between 1 and {$maximum} options.");
        }
    }

    private static function dateValue(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function requires(string $feature, array $types): void
    {
        if (!in_array($this->type, $types, true)) {
            throw new \LogicException("{$this->type} does not support {$feature}.");
        }
    }

    private function withFilter(string $key, bool|array $value): self
    {
        if (!isset($this->fields['filter'])) {
            return $this->with('filter', [$key => $value]);
        }
        if (!is_array($this->fields['filter'])) {
            throw new \LogicException('The conversation filter is malformed.');
        }

        return $this->with('filter', [...$this->fields['filter'], $key => $value]);
    }

    /** @return array<string, mixed> */
    private function serializedFields(): array
    {
        $serialized = [];
        foreach (array_keys($this->fields) as $key) {
            if (!is_string($key) || self::serialize($this->fields[$key]) === null) {
                continue;
            }
            $serialized[$key] = self::serialize($this->fields[$key]);
        }

        return $serialized;
    }

    private static function serialize(mixed $value): mixed
    {
        if (
            $value instanceof Text
            || $value instanceof Confirm
            || $value instanceof Option
            || $value instanceof RichText
        ) {
            return $value->toArray();
        }
        if (is_array($value) && $value !== [] && array_is_list($value) && $value[0] instanceof Option) {
            return array_map(static fn(mixed $option): array => (
                $option instanceof Option ? $option->toArray() : []
            ), $value);
        }

        return $value;
    }

    private function with(string $key, mixed $value): self
    {
        return new self($this->type, $this->actionId, [...$this->fields, $key => $value]);
    }
}
