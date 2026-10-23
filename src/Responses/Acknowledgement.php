<?php

declare(strict_types=1);

namespace Thunder\Bolt\Responses;

interface Acknowledgement
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
