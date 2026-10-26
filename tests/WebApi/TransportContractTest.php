<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\WebApi;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\MethodTransport;
use Thunder\Bolt\WebApi\SlackApiException;

final class TransportContractTest extends TestCase
{
    public static function contracts(): iterable
    {
        $snapshot = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/docs/research/web-api-transport.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($snapshot['methods'] as $method => $contract) {
            yield $method => [$method, $contract];
        }
    }

    #[DataProvider('contracts')]
    public function test_endpoint_uses_a_documented_encoding_without_body_credentials(
        string $method,
        array $contract,
    ): void {
        $http = new RecordingHttpClient();
        $arguments = ['cursor' => 'next+/=& value', 'include_locale' => false];
        if ($method === 'oauth.v2.exchange') {
            $arguments['token'] = 'exchange-secret';
        }
        new Client($http, 'header-secret')->call($method, $arguments);
        $request = $http->requests[0];
        $get = in_array($method, MethodTransport::GET, true);
        self::assertSame($get ? 'GET' : 'POST', $request->getMethod());
        if ($get) {
            self::assertSame('GET', $contract['verb']);
            self::assertSame('', (string) $request->getBody());
            self::assertFalse($request->hasHeader('Content-Type'));
            self::assertSame(['cursor' => 'next+/=& value', 'include_locale' => 'false'], $http->sentQuery());
        } else {
            $type = explode(';', $request->getHeaderLine('Content-Type'))[0];
            self::assertContains($type, $contract['contentTypes']);
            self::assertSame('', $request->getUri()->getQuery());
        }
        self::assertStringNotContainsString('secret', (string) $request->getUri());
        self::assertStringNotContainsString('header-secret', (string) $request->getBody());
    }

    public function test_form_only_methods_preserve_false_zero_and_opaque_values(): void
    {
        foreach (['auth.teams.list', 'admin.apps.activities.list'] as $method) {
            $http = new RecordingHttpClient();
            new Client($http, 'secret')->call($method, ['cursor' => '+/=& space', 'limit' => 0, 'flag' => false]);
            self::assertSame('application/x-www-form-urlencoded', $http->requests[0]->getHeaderLine('Content-Type'));
            self::assertSame('cursor=%2B%2F%3D%26%20space&limit=0&flag=0', (string) $http->requests[0]->getBody());
        }
    }

    public function test_complex_read_filters_remain_json_instead_of_php_query_brackets(): void
    {
        $http = new RecordingHttpClient();
        new Client($http, 'secret')
            ->admin()
            ->roles()
            ->listAssignments(
                new \Thunder\Bolt\Generated\WebApi\Models\Requests\AdminRolesListAssignmentsRequest(entityIds: [
                    'T1',
                    'T2',
                ]),
            );
        self::assertSame('POST', $http->requests[0]->getMethod());
        self::assertSame(['entity_ids' => ['T1', 'T2']], $http->sentPayload());
    }

    public function test_gzipped_analytics_rows_fill_the_existing_typed_response(): void
    {
        $http = new RecordingHttpClient([new Response(
            200,
            ['Content-Type' => 'application/gzip'],
            gzencode("{\"user_id\":\"U1\"}\n{\"user_id\":\"U2\"}\n"),
        )]);
        $result = new Client($http, 'secret')
            ->admin()
            ->analytics()
            ->getFile(['type' => 'member', 'date' => '2026-10-25']);
        self::assertSame([['user_id' => 'U1'], ['user_id' => 'U2']], $result->fileData);
        self::assertSame('GET', $http->requests[0]->getMethod());
    }

    public function test_analytics_error_envelopes_are_not_treated_as_downloads(): void
    {
        $http = new RecordingHttpClient([new Response(200, [], '{"ok":false,"error":"missing_scope"}')]);
        $this->expectExceptionMessage('missing_scope');
        new Client($http, 'secret')->call('admin.analytics.getFile');
    }

    public function test_transparently_decompressed_analytics_are_supported(): void
    {
        $http = new RecordingHttpClient([new Response(200, [], "{\"user_id\":\"U1\"}\n{\"user_id\":\"U2\"}\n")]);
        $result = new Client($http, 'secret')->call('admin.analytics.getFile');
        self::assertSame([['user_id' => 'U1'], ['user_id' => 'U2']], $result->get('file_data'));
    }

    public function test_corrupt_gzip_is_redacted_and_restores_the_error_handler(): void
    {
        $handler = static fn(): bool => true;
        set_error_handler($handler);
        try {
            $http = new RecordingHttpClient([new Response(200, [], "\x1f\x8bprivate broken gzip")]);
            try {
                new Client($http, 'secret')->call('admin.analytics.getFile');
                self::fail('Corrupt gzip must fail.');
            } catch (SlackApiException $exception) {
                self::assertSame('invalid_or_oversized_analytics', $exception->error);
            }
            self::assertSame($handler, set_error_handler($handler));
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }

    public function test_invalid_analytics_rows_fail_without_exposing_download_contents(): void
    {
        $http = new RecordingHttpClient([new Response(200, [], gzencode("private invalid json\n"))]);
        $this->expectException(SlackApiException::class);
        $this->expectExceptionMessage('invalid_json');
        new Client($http, 'secret')->call('admin.analytics.getFile');
    }
}
