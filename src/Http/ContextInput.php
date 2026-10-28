<?php

declare(strict_types=1);

namespace Thunder\Bolt\Http;

use Thunder\Bolt\Invocation\Delivery;

/** Verified HTTP input, available after installation authorization and before middleware. */
final readonly class ContextInput
{
    public array $headers;

    /** @param array<string, string|list<string>> $headers */
    public function __construct(
        public Delivery $delivery,
        array $headers,
        public string $rawBody,
        public string $contentType,
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function __debugInfo(): array
    {
        return ['transport' => 'http', 'headers' => array_keys($this->headers)];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Do not serialize delivery factory input. Dispatch explicit named job data.');
    }
}
