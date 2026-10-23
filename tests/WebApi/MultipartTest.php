<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\WebApi;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\MultipartFile;
use Thunder\Bolt\WebApi\RateLimited;
use Thunder\Bolt\WebApi\RetryPolicy;

final class MultipartTest extends TestCase
{
    public function test_binary_parts_preserve_bytes_and_structured_fields_without_leaking_token(): void
    {
        $http = new RecordingHttpClient();
        new Client($http, 'default')
            ->files()
            ->remote()
            ->add([
                'external_id' => 'external',
                'external_url' => 'https://example.test/file',
                'title' => 'File',
                'preview_image' => MultipartFile::bytes("\x00\x01\xff", 'preview.png', 'image/png'),
                'indexable_file_contents' => ['text' => 'indexed'],
                'token' => 'override',
            ]);
        $request = $http->requests[0];
        $body = (string) $request->getBody();
        self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        self::assertSame('Bearer override', $request->getHeaderLine('Authorization'));
        self::assertStringContainsString("\x00\x01\xff", $body);
        self::assertStringContainsString('filename="preview.png"', $body);
        self::assertStringContainsString('Content-Type: image/png', $body);
        self::assertStringContainsString('{"text":"indexed"}', $body);
        self::assertStringNotContainsString('override', $body);
        self::assertStringNotContainsString('name="token"', $body);
    }

    public function test_rate_limit_retry_recreates_replayable_multipart_with_same_boundary(): void
    {
        $http = new RecordingHttpClient([
            new Response(429, ['Retry-After' => '0']),
            new Response(200, [], '{"ok":true}'),
        ]);
        new Client($http, 'token')
            ->retrying()
            ->call('files.remote.add', [
                'preview_image' => MultipartFile::bytes('same bytes', 'image.bin'),
            ]);
        self::assertCount(2, $http->requests);
        self::assertSame((string) $http->requests[0]->getBody(), (string) $http->requests[1]->getBody());
    }

    public function test_borrowed_stream_is_never_replayed_or_closed(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'bytes');
        rewind($stream);
        $http = new RecordingHttpClient([new Response(429, ['Retry-After' => '0'])]);
        try {
            new Client($http, 'token')
                ->retrying()
                ->call('files.remote.add', [
                    'preview_image' => MultipartFile::stream($stream, 5, 'image.bin'),
                ]);
            self::fail('Expected rate limit.');
        } catch (RateLimited) {
            self::assertCount(1, $http->requests);
            unset($http);
            gc_collect_cycles();
            self::assertIsResource($stream);
        } finally {
            fclose($stream);
        }
    }

    public function test_borrowed_stream_multipart_uses_its_declared_window_from_the_current_cursor(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'abcdef');
        fseek($stream, 2);

        try {
            $file = MultipartFile::stream($stream, 3, 'window.bin');
            self::assertSame(3, $file->open()->getSize());
            $http = new RecordingHttpClient();
            new Client($http, 'token')->call('files.remote.add', ['preview_image' => $file]);

            $body = (string) $http->requests[0]->getBody();
            self::assertStringContainsString("\r\n\r\ncde\r\n", $body);
            self::assertStringNotContainsString('abcdef', $body);
            self::assertSame(5, ftell($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_helper_name_is_not_sent_as_a_fake_endpoint(): void
    {
        $http = new RecordingHttpClient();
        try {
            new Client($http, 'token')->call('files.uploadV2');
            self::fail('Expected helper hint.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('not an API endpoint', $exception->getMessage());
            self::assertCount(0, $http->requests);
        }
    }

    public function test_multipart_names_cannot_inject_headers(): void
    {
        $http = new RecordingHttpClient();
        try {
            new Client($http, 'token')->call('files.remote.add', [
                'preview_image' => MultipartFile::bytes('bytes', 'image.bin'),
                "field\r\nX-Injected: yes" => 'value',
            ]);
            self::fail('Expected invalid multipart name.');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $http->requests);
        }
    }
}
