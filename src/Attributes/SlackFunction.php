<?php

declare(strict_types=1);

namespace Thunder\Bolt\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class SlackFunction
{
    public function __construct(
        public string $callbackId,
    ) {
        if (trim($callbackId) === '') {
            throw new \InvalidArgumentException('A Slack function listener needs a callback ID.');
        }
    }
}
