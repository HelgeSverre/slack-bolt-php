<?php

declare(strict_types=1);

namespace Thunder\Bolt\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromInputs {}
