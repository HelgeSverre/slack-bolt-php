<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Uploads;

/** @internal A same-destination batch for files.completeUploadExternal. */
final class CompletionGroup
{
    /** @var list<array<string, string>> */
    private array $files = [];

    /** @param array<string, mixed> $destination */
    public function __construct(
        private readonly array $destination,
    ) {}

    public function add(string $fileId, Upload $upload): void
    {
        $title = $upload->metadata->text('title');
        $highlightType = $upload->metadata->text('highlight_type');
        $file = ['id' => $fileId, 'title' => $title ?? $upload->filename];
        if ($highlightType !== null) {
            $file['highlight_type'] = $highlightType;
        }
        $this->files[] = $file;
    }

    /** @return array<string, mixed> */
    public function arguments(): array
    {
        return [...$this->destination, 'files' => $this->files];
    }
}
