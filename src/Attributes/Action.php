<?php

declare(strict_types=1);

namespace Thunder\Bolt\Attributes;

use Attribute;
use Thunder\Bolt\Routing\Regex;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Action
{
    public function __construct(
        public string|Regex $actionId = '',
        public string|Regex|null $blockId = null,
        public string|Regex|null $callbackId = null,
        public ?string $type = null,
    ) {}
}
