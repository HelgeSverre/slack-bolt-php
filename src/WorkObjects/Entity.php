<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects;

final readonly class Entity implements \JsonSerializable
{
    private function __construct(
        public EntityType $type,
        public ExternalReference $reference,
        public string $url,
        private array $attributes,
        private array $fields = [],
        private array $customFields = [],
        private array $order = [],
        private ?array $comments = null,
        private ?string $unfurl = null,
    ) {}

    public static function make(EntityType $type, ExternalReference $reference, string $url, string $title): self
    {
        Guard::url($url);
        Guard::text($title);
        return new self($type, $reference, $url, ['title' => ['text' => $title]]);
    }

    public static function task(ExternalReference|string $reference, string $url, string $title): self
    {
        return self::make(
            EntityType::Task,
            is_string($reference) ? new ExternalReference($reference) : $reference,
            $url,
            $title,
        );
    }

    public static function file(ExternalReference|string $reference, string $url, string $title): self
    {
        return self::make(
            EntityType::File,
            is_string($reference) ? new ExternalReference($reference) : $reference,
            $url,
            $title,
        );
    }

    public function attribute(string $name, mixed $value): self
    {
        Guard::text($name, 255);
        if ($name === 'title') {
            throw new \InvalidArgumentException('Set the entity title through its factory.');
        }
        json_encode($value, JSON_THROW_ON_ERROR);
        return clone($this, ['attributes' => [...$this->attributes, $name => $value]]);
    }

    public function field(string $name, EntityField $field): self
    {
        Guard::text($name, 255);
        if ($this->type === EntityType::Item) {
            throw new \InvalidArgumentException('General items use custom fields.');
        }
        return clone($this, ['fields' => [...$this->fields, $name => $field->toArray()]]);
    }

    public function customField(string $key, string $label, string $type, EntityField $field): self
    {
        Guard::text($key, 255);
        Guard::text($label, 255);
        Guard::text($type, 255);
        $fields = $this->customFields;
        $fields[$key] = [...$field->toArray(), 'key' => $key, 'label' => $label, 'type' => $type];
        return clone($this, ['customFields' => $fields]);
    }

    public function displayOrder(string ...$fields): self
    {
        foreach ($fields as $field) {
            Guard::text($field, 255);
        }
        return clone($this, ['order' => array_values($fields)]);
    }

    public function comments(int $count, ?int $latestTimestamp = null): self
    {
        if ($count < 0 || $latestTimestamp !== null && $latestTimestamp < 0) {
            throw new \InvalidArgumentException('Invalid comment summary.');
        }
        return clone($this, ['comments' => [
            'count' => $count,
            ...($latestTimestamp !== null ? ['latest_timestamp' => $latestTimestamp] : []),
        ]]);
    }

    public function unfurlUrl(string $url): self
    {
        Guard::url($url);
        return clone($this, ['unfurl' => $url]);
    }

    public function toArray(): array
    {
        $payload = ['attributes' => $this->attributes];
        if ($this->fields !== []) {
            $payload['fields'] = $this->fields;
        }
        if ($this->customFields !== []) {
            $payload['custom_fields'] = array_values($this->customFields);
        }
        if ($this->order !== []) {
            $payload['display_order'] = $this->order;
        }
        return [
            'entity_type' => $this->type->value,
            'external_ref' => $this->reference->toArray(),
            'url' => $this->url,
            'entity_payload' => $payload,
            ...($this->comments !== null ? ['entity_comments' => $this->comments] : []),
            ...($this->unfurl !== null ? ['app_unfurl_url' => $this->unfurl] : []),
        ];
    }

    public function details(): array
    {
        $data = $this->toArray();
        unset($data['app_unfurl_url']);
        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
