<?php

declare(strict_types=1);

namespace Thunder\Bolt\Attributes;

use Attribute;
use Thunder\Bolt\Routing\Regex;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Shortcut
{
    public function __construct(
        public string|Regex $callbackId,
        public ?string $type = null,
    ) {}
}
