<?php

declare(strict_types=1);

namespace Thunder\Bolt\Views;

interface View extends \JsonSerializable
{ /** @return array<string,mixed> */
    public function toArray(): array;
}
