<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Uploads;

use Thunder\Bolt\WebApi\Response;

final readonly class UploadResult
{
    /**
     * @param list<string> $fileIds
     * @param list<Response> $completions
     */
    public function __construct(
        public array $fileIds,
        public array $completions,
    ) {}
}
