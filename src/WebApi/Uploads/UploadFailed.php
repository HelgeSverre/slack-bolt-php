<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Uploads;

use Thunder\Bolt\WebApi\Response;

final class UploadFailed extends UploadException
{
    /**
     * @param 'reserve'|'upload'|'complete' $phase
     * @param list<string> $fileIds
     * @param list<string> $uploadedFileIds
     * @param list<Response> $completions
     */
    public function __construct(
        public readonly string $phase,
        public readonly array $fileIds,
        public readonly array $uploadedFileIds,
        public readonly array $completions,
        \Throwable $cause,
    ) {
        parent::__construct('Slack upload failed during ' . $phase . '.', previous: $cause);
    }
}
