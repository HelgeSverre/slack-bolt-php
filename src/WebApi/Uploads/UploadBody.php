<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Uploads;

use Psr\Http\Message\StreamInterface;

/** @internal Represents upload bytes without making a network request. */
interface UploadBody
{
    public function length(): int;

    public function open(): StreamInterface;
}
