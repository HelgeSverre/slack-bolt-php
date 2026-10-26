<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\WebApi;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\RetryPolicy;
use Thunder\Bolt\WebApi\Uploads\Upload;
use Thunder\Bolt\WebApi\Uploads\UploadException;
use Thunder\Bolt\WebApi\Uploads\UploadFailed;
use Thunder\Bolt\WebApi\Uploads\UploadMetadata;

final class UploadTest extends TestCase
{
    public function test_upload_reserves_posts_without_a_bearer_token_and_completes(): void
    {
        $api = new RecordingHttpClient([
            $this->json([
                'ok' => true,
                'file_id' => 'F1',
                'upload_url' => 'https://files.slack.com/upload/F1?signature=abc',
            ]),
            $this->json(['ok' => true, 'files' => [['id' => 'F1']]]),
        ]);
        $external = new RecordingHttpClient([new Response(200)]);
        $client = new Client($api, 'xoxb-secret', uploadHttp: $external);

        $result = $client->upload(
            Upload::bytes('hello', 'hello.txt')
                ->title('Greeting')
                ->altText('Five greeting bytes')
                ->snippetType('text')
                ->highlightType('text')
                ->toChannel('C1')
                ->inThread('1700000000.000001')
                ->comment('A greeting'),
        );

        self::assertSame(['F1'], $result->fileIds);
        self::assertCount(1, $result->completions);
        self::assertSame('https://slack.com/api/files.getUploadURLExternal', (string) $api->requests[0]->getUri());
        self::assertSame('Bearer xoxb-secret', $api->requests[0]->getHeaderLine('Authorization'));
        self::assertSame(
            [
                'filename' => 'hello.txt',
                'length' => 5,
                'alt_txt' => 'Five greeting bytes',
                'snippet_type' => 'text',
            ],
            $api->sentPayload(0),
        );
        self::assertSame('https://files.slack.com/upload/F1?signature=abc', (string) $external->requests[0]->getUri());
        self::assertSame('', $external->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('5', $external->requests[0]->getHeaderLine('Content-Length'));
        self::assertSame('hello', (string) $external->requests[0]->getBody());
        self::assertSame('https://slack.com/api/files.completeUploadExternal', (string) $api->requests[1]->getUri());
        self::assertSame(
            [
                'channel_id' => 'C1',
                'thread_ts' => '1700000000.000001',
                'initial_comment' => 'A greeting',
                'files' => [['id' => 'F1', 'title' => 'Greeting', 'highlight_type' => 'text']],
            ],
            $api->sentPayload(1),
        );
    }

    public function test_upload_many_groups_files_with_the_same_completion_metadata(): void
    {
        $api = new RecordingHttpClient([
            $this->json(['ok' => true, 'file_id' => 'F1', 'upload_url' => 'https://files.slack.com/upload/F1']),
            $this->json(['ok' => true, 'file_id' => 'F2', 'upload_url' => 'https://files.slack.com/upload/F2']),
            $this->json(['ok' => true, 'files' => [['id' => 'F1'], ['id' => 'F2']]]),
        ]);
        $external = new RecordingHttpClient([new Response(200), new Response(200)]);
        $client = new Client($api, 'xoxb-secret', uploadHttp: $external);
        $metadata = UploadMetadata::make()->toChannel('C1')->comment('Both files')->blocks(Section::markdown('*one*'));

        $result = $client->uploadMany(
            Upload::bytes('one', 'one.txt')->with($metadata),
            Upload::bytes('two', 'two.txt')->with($metadata),
        );

        self::assertSame(['F1', 'F2'], $result->fileIds);
        self::assertCount(2, $external->requests);
        self::assertCount(3, $api->requests);
        self::assertSame(
            [
                'channel_id' => 'C1',
                'initial_comment' => 'Both files',
                'blocks' => [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*one*']]],
                'files' => [['id' => 'F1', 'title' => 'one.txt'], ['id' => 'F2', 'title' => 'two.txt']],
            ],
            $api->sentPayload(2),
        );
    }

    public function test_path_and_stream_sources_send_their_exact_bytes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'thunder-bolt-upload-');
        self::assertNotFalse($path);
        file_put_contents($path, "path\n");
        $stream = fopen('php://temp', 'w+b');
        self::assertNotFalse($stream);
        fwrite($stream, 'stream');
        rewind($stream);

        try {
            $api = new RecordingHttpClient([
                $this->json(['ok' => true, 'file_id' => 'F1', 'upload_url' => 'https://files.slack.com/upload/F1']),
                $this->json(['ok' => true, 'file_id' => 'F2', 'upload_url' => 'https://files.slack.com/upload/F2']),
                $this->json(['ok' => true]),
            ]);
            $external = new RecordingHttpClient([new Response(200), new Response(200)]);
            new Client($api, 'token', uploadHttp: $external)->uploadMany(
                Upload::path($path),
                Upload::stream($stream, 6, 'stream.bin'),
            );

            self::assertSame("path\n", (string) $external->requests[0]->getBody());
            self::assertSame('stream', (string) $external->requests[1]->getBody());
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    public function test_unsafe_upload_urls_are_rejected_before_the_external_post(): void
    {
        $api = new RecordingHttpClient([$this->json([
            'ok' => true,
            'file_id' => 'F1',
            'upload_url' => 'http://files.slack.com/upload/F1',
        ])]);
        $external = new RecordingHttpClient();

        $this->expectException(UploadException::class);
        try {
            new Client($api, 'token', uploadHttp: $external)->upload(Upload::bytes('bytes', 'file.txt'));
        } finally {
            self::assertSame([], $external->requests);
        }
    }

    public function test_external_upload_failure_does_not_complete_the_file(): void
    {
        $api = new RecordingHttpClient([
            $this->json(['ok' => true, 'file_id' => 'F1', 'upload_url' => 'https://files.slack.com/upload/F1']),
            $this->json(['ok' => true, 'file_id' => 'F2', 'upload_url' => 'https://files.slack.com/upload/F2']),
        ]);
        $external = new RecordingHttpClient([new Response(200), new Response(503)]);

        try {
            new Client($api, 'token', uploadHttp: $external)->uploadMany(
                Upload::bytes('one', 'one.txt'),
                Upload::bytes('two', 'two.txt'),
            );
            self::fail('The second transfer should fail.');
        } catch (UploadFailed $failure) {
            self::assertSame('upload', $failure->phase);
            self::assertSame(['F1', 'F2'], $failure->fileIds);
            self::assertSame(['F1'], $failure->uploadedFileIds);
            self::assertSame([], $failure->completions);
            self::assertSame('external_upload_http_503', $failure->getPrevious()?->getMessage());
            self::assertCount(2, $api->requests);
            self::assertSame('', $external->requests[0]->getHeaderLine('Authorization'));
        }
    }

    public function test_second_completion_failure_preserves_the_first_completion(): void
    {
        $api = new RecordingHttpClient([
            $this->json(['ok' => true, 'file_id' => 'F1', 'upload_url' => 'https://files.slack.com/upload/F1']),
            $this->json(['ok' => true, 'file_id' => 'F2', 'upload_url' => 'https://files.slack.com/upload/F2']),
            $this->json(['ok' => true, 'files' => [['id' => 'F1']]]),
            $this->json(['ok' => false, 'error' => 'completion_failed']),
        ]);
        $external = new RecordingHttpClient([new Response(200), new Response(200)]);
        $first = Upload::bytes('one', 'one.txt')->with(UploadMetadata::make()->toChannel('C1'));
        $second = Upload::bytes('two', 'two.txt')->with(UploadMetadata::make()->toChannel('C2'));

        try {
            new Client($api, 'token', uploadHttp: $external)->uploadMany($first, $second);
            self::fail('The second completion should fail.');
        } catch (UploadFailed $failure) {
            self::assertSame('complete', $failure->phase);
            self::assertSame(['F1', 'F2'], $failure->fileIds);
            self::assertSame(['F1', 'F2'], $failure->uploadedFileIds);
            self::assertCount(1, $failure->completions);
            self::assertStringContainsString('completion_failed', $failure->getPrevious()?->getMessage() ?? '');
        }
    }

    public function test_external_uploads_are_never_retried_even_when_api_retries_are_enabled(): void
    {
        $api = new RecordingHttpClient([$this->json([
            'ok' => true,
            'file_id' => 'F1',
            'upload_url' => 'https://files.slack.com/upload/F1',
        ])]);
        $external = new RecordingHttpClient([new Response(503), new Response(200)]);

        try {
            new Client($api, 'token', uploadHttp: $external)
                ->retrying(RetryPolicy::transientErrors(initialDelay: 0, jitter: false), static function (): void {})
                ->upload(Upload::bytes('bytes', 'file.txt'));
            self::fail('The consumed external body must not be retried.');
        } catch (UploadException) {
            self::assertCount(1, $external->requests);
        }
    }

    public function test_metadata_validates_thread_timestamps_and_serializes_named_blocks_as_a_list(): void
    {
        $metadata = UploadMetadata::make()->toChannel('C1')->blocks(first: Section::markdown('*one*'));

        self::assertSame(
            [
                'channel_id' => 'C1',
                'blocks' => [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*one*']]],
            ],
            $metadata->completionDestination(),
        );

        $this->expectException(\InvalidArgumentException::class);
        (void) $metadata->inThread('not-a-slack-timestamp');
    }

    public function test_a_transport_cannot_close_a_caller_owned_stream_after_success_or_failure(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertNotFalse($stream);
        fwrite($stream, 'stream');
        rewind($stream);

        try {
            foreach ([200, 503] as $status) {
                $responses = [$this->json([
                    'ok' => true,
                    'file_id' => 'F1',
                    'upload_url' => 'https://files.slack.com/upload/F1',
                ])];
                if ($status === 200) {
                    $responses[] = $this->json(['ok' => true]);
                }
                $api = new RecordingHttpClient($responses);
                $external = new class($status) implements ClientInterface {
                    public function __construct(
                        private readonly int $status,
                    ) {}

                    public function sendRequest(RequestInterface $request): ResponseInterface
                    {
                        TestCase::assertSame('stream', (string) $request->getBody());

                        return new Response($this->status);
                    }
                };

                try {
                    new Client($api, 'token', uploadHttp: $external)->upload(Upload::stream($stream, 6, 'stream.bin'));
                } catch (UploadException) {
                    // A failed external POST has the same ownership rule.
                }
                gc_collect_cycles();
                self::assertIsResource($stream);
                rewind($stream);
            }
        } finally {
            fclose($stream);
        }
    }

    public function test_stream_uploads_send_only_the_declared_window_from_the_current_cursor(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertNotFalse($stream);
        fwrite($stream, 'abcdef');
        fseek($stream, 2);

        try {
            $api = new RecordingHttpClient([
                $this->json(['ok' => true, 'file_id' => 'F1', 'upload_url' => 'https://files.slack.com/upload/F1']),
                $this->json(['ok' => true]),
            ]);
            $external = new RecordingHttpClient([new Response(200)]);
            new Client($api, 'token', uploadHttp: $external)->upload(Upload::stream($stream, 3, 'window.bin'));

            self::assertSame('cde', (string) $external->requests[0]->getBody());
            self::assertSame(5, ftell($stream));
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }
}
