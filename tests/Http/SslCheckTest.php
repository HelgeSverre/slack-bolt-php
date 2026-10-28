<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Http\Receiver;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class SslCheckTest extends TestCase
{
    public static function probes(): iterable
    {
        yield 'bare' => ['ssl_check=1'];
        yield 'with token' => ['ssl_check=1&token=private-token'];
        yield 'encoded key' => ['%73sl_check=%31'];
        yield 'smuggled event' => [http_build_query([
            'ssl_check' => '1',
            'payload' => '{"type":"event_callback","event":{"type":"message","text":"injected"}}',
        ])];
        yield 'smuggled command' => ['ssl_check=1&command=%2Finjected&response_url=https%3A%2F%2Fexample.test'];
    }

    #[DataProvider('probes')]
    public function test_probe_never_enters_app_middleware_or_authorization(string $body): void
    {
        $http = new RecordingHttpClient();
        $config = new BoltConfig();
        $app = new App(new GenericContainer(), new Client($http, ''), $config);
        $app->authorizeUsing(static function (): never {
            self::fail('Probe reached authorization.');
        });
        $app->use(static function (): never {
            self::fail('Probe reached middleware.');
        });
        $response = new Receiver($app, $config)->handle($body, [], 'Application/X-WWW-Form-Urlencoded; charset=utf-8');
        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame([], $http->requests);
    }

    public static function invalidProbes(): iterable
    {
        foreach ([
            'ssl_check=0',
            'ssl_check=true',
            'ssl_check=',
            'ssl_check[]=1',
            'ssl_check=0&ssl_check=1',
            'ssl.check=1',
            'ssl_check=1&ssl_check[]=1',
            'payload=%7B%22ssl_check%22%3A%221%22%7D',
        ] as $body)
            yield $body => [$body, 'application/x-www-form-urlencoded'];
        yield 'JSON' => ['{"ssl_check":"1","command":"/injected"}', 'application/json'];
        yield 'text' => ['ssl_check=1', 'text/plain'];
    }

    #[DataProvider('invalidProbes')]
    public function test_other_requests_still_require_a_signature(string $body, string $type): void
    {
        $config = new BoltConfig(token: 'fixture', signingSecret: 'secret');
        $app = new App(new GenericContainer(), new Client(new RecordingHttpClient(), 'fixture'), $config);
        $response = new Receiver($app, $config)->handle($body, [], $type);
        self::assertSame(401, $response->status);
    }

    public function test_size_limit_applies_to_probes(): void
    {
        $config = new BoltConfig(maxBodyBytes: 4);
        $app = new App(new GenericContainer(), new Client(new RecordingHttpClient(), ''), $config);
        self::assertSame(
            413,
            new Receiver($app, $config)->handle('ssl_check=1', [], 'application/x-www-form-urlencoded')->status,
        );
    }

    public function test_probe_inspection_bounds_form_fields_before_signature_verification(): void
    {
        $config = new BoltConfig(signingSecret: 'secret');
        $app = new App(new GenericContainer(), new Client(new RecordingHttpClient(), ''), $config);
        $body = 'ssl_check=1&' . str_repeat('unused=&', 1000);
        self::assertSame(
            401,
            new Receiver($app, $config)->handle($body, [], 'application/x-www-form-urlencoded')->status,
        );
    }
}
