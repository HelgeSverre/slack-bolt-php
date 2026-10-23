<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Uploads;

use Psr\Http\Client\ClientInterface;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\Response;

/** Executes Slack's get-upload-URL, raw upload, then completion protocol. */
final readonly class Uploader
{
    public function __construct(
        private Client $api,
        private ClientInterface $transport,
    ) {}

    public function upload(Upload $upload): UploadResult
    {
        return $this->uploadMany($upload);
    }

    public function uploadMany(Upload ...$uploads): UploadResult
    {
        if ($uploads === []) {
            throw new \InvalidArgumentException('At least one upload is required.');
        }
        $reservations = [];
        $fileIds = [];
        $uploadedFileIds = [];
        $completions = [];

        foreach ($uploads as $upload) {
            try {
                $reservation = $this->reserve($upload);
            } catch (\Throwable $cause) {
                throw new UploadFailed('reserve', $fileIds, $uploadedFileIds, $completions, $cause);
            }
            $reservations[] = $reservation;
            $fileIds[] = $reservation['fileId'];
        }
        foreach ($reservations as $reservation) {
            try {
                $reservation['external']->send($this->transport, $reservation['upload']);
            } catch (\Throwable $cause) {
                throw new UploadFailed('upload', $fileIds, $uploadedFileIds, $completions, $cause);
            }
            $uploadedFileIds[] = $reservation['fileId'];
        }
        foreach ($this->groups($reservations) as $group) {
            try {
                $completions[] = $this->api->call('files.completeUploadExternal', $group->arguments());
            } catch (\Throwable $cause) {
                throw new UploadFailed('complete', $fileIds, $uploadedFileIds, $completions, $cause);
            }
        }

        return new UploadResult($fileIds, $completions);
    }

    /** @return array{upload:Upload, fileId:string, external:ExternalUpload} */
    private function reserve(Upload $upload): array
    {
        $response = $this->api->call('files.getUploadURLExternal', array_filter(
            [
                'filename' => $upload->filename,
                'length' => $upload->length(),
                'alt_text' => $upload->metadata->text('alt_text'),
                'snippet_type' => $upload->metadata->text('snippet_type'),
            ],
            static fn(mixed $value): bool => $value !== null,
        ));
        $fileId = $this->nonEmptyString($response, 'file_id');
        $url = $this->nonEmptyString($response, 'upload_url');
        if ($fileId === null || $url === null) {
            throw new UploadException('Slack returned an incomplete external upload reservation.');
        }

        return ['upload' => $upload, 'fileId' => $fileId, 'external' => ExternalUpload::fromSlack($url)];
    }

    /**
     * @param list<array{upload:Upload, fileId:string, external:ExternalUpload}> $reservations
     * @return list<CompletionGroup>
     */
    private function groups(array $reservations): array
    {
        /** @var array<string, CompletionGroup> $groups */
        $groups = [];
        foreach ($reservations as $reservation) {
            $metadata = $reservation['upload']->metadata;
            $destination = $metadata->completionDestination();
            $key = hash('sha256', json_encode($destination, JSON_THROW_ON_ERROR));
            $groups[$key] ??= new CompletionGroup($destination);
            $groups[$key]->add($reservation['fileId'], $reservation['upload']);
        }

        return array_values($groups);
    }

    private function nonEmptyString(Response $response, string $key): ?string
    {
        return is_string($response->data[$key] ?? null) && $response->data[$key] !== '' ? $response->data[$key] : null;
    }
}
