<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Functions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Functions\Execution;
use Thunder\Bolt\Http\Receiver;
use Thunder\Bolt\Responses\ActionResponse;
use Thunder\Bolt\Tests\Support\FunctionPayloads;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class TokenAttachmentPolicyTest extends TestCase
{
    public static function modes(): iterable
    {
        yield 'default attachment' => [true, 'Bearer xwfp-function-token'];
        yield 'installation opt-out' => [false, 'Bearer installation'];
    }

    #[DataProvider('modes')]
    public function test_selected_credential_reaches_client_reply_and_completion_without_changing_root(
        bool $attach,
        string $expected,
    ): void {
        $http = new RecordingHttpClient();
        $root = new Client($http, 'root');
        $app = new App(
            new GenericContainer(),
            $root,
            new BoltConfig(token: 'installation', attachFunctionToken: $attach),
        );
        $app->function('collect_feedback', static function (
            Client $client,
            Execution $execution,
            \Thunder\Bolt\Reply $reply,
        ): void {
            $client->call('auth.test');
            $reply->inChannel('fixture');
            $execution->complete(['result' => 'done']);
        });
        $body = FunctionPayloads::executed();
        $body['event']['channel'] = 'C1';
        $app->dispatch($body);
        $root->call('auth.test');
        self::assertSame(
            [$expected, $expected, $expected, 'Bearer root'],
            array_map(static fn($r) => $r->getHeaderLine('Authorization'), $http->requests),
        );
    }

    public function test_opt_out_uses_authorized_user_grant_when_no_bot_grant_is_available(): void
    {
        $http = new RecordingHttpClient();
        $app = new App(new GenericContainer(), new Client($http, 'root'), new BoltConfig(attachFunctionToken: false));
        $app->authorizeUsing(static fn() => new Authorization(userToken: 'user-installation', teamId: 'T-function'));
        $app->function('collect_feedback', static function (Execution $execution): void {
            $execution->fail('fixture failure');
        });
        $app->dispatch(FunctionPayloads::executed());
        self::assertSame('Bearer user-installation', $http->requests[0]->getHeaderLine('Authorization'));
        self::assertStringEndsWith('/functions.completeError', $http->requests[0]->getUri()->getPath());
    }

    public function test_function_scoped_interactions_and_constructor_clients_honor_opt_out(): void
    {
        $http = new RecordingHttpClient();
        $app = new App(
            new GenericContainer(),
            new Client($http, 'root'),
            new BoltConfig(token: 'installation', attachFunctionToken: false),
        );
        $app->action('approve_feedback', PolicyActionListener::class);
        $app->dispatch(FunctionPayloads::action());
        self::assertSame('Bearer installation', $http->requests[0]->getHeaderLine('Authorization'));
    }

    public function test_opt_out_requires_an_installation_even_when_the_body_contains_a_workflow_token(): void
    {
        $http = new RecordingHttpClient();
        $config = new BoltConfig(signingSecret: 'secret', attachFunctionToken: false);
        $app = new App(new GenericContainer(), new Client($http, ''), $config);
        $app->function('collect_feedback', static function (): never {
            self::fail('No installation credential.');
        });
        self::assertFalse($app->hasAuthorization(FunctionPayloads::executed()));
        $raw = json_encode(FunctionPayloads::executed(), JSON_THROW_ON_ERROR);
        $time = (string) time();
        $response = new Receiver($app, $config)->handle($raw, [
            'X-Slack-Request-Timestamp' => $time,
            'X-Slack-Signature' => 'v0=' . hash_hmac('sha256', "v0:{$time}:{$raw}", 'secret'),
        ]);
        self::assertSame(503, $response->status);
        self::assertSame([], $http->requests);
        $this->expectException(\Thunder\Bolt\Authorization\AuthorizationException::class);
        $app->dispatch(FunctionPayloads::executed());
    }

    public function test_workflow_token_cannot_bypass_a_rejecting_authorizer_in_either_mode(): void
    {
        foreach ([true, false] as $attach) {
            $http = new RecordingHttpClient();
            $app = new App(
                new GenericContainer(),
                new Client($http, 'root'),
                new BoltConfig(attachFunctionToken: $attach),
            );
            $app->authorizeUsing(static fn() => throw new \RuntimeException('policy rejection'));
            try {
                $app->dispatch(FunctionPayloads::executed());
                self::fail('Policy must reject.');
            } catch (\RuntimeException $error) {
                self::assertSame('policy rejection', $error->getMessage());
            }
            self::assertSame([], $http->requests);
        }
    }
}

final readonly class PolicyActionListener
{
    public function __construct(
        private Client $client,
    ) {}

    public function __invoke(): ActionResponse
    {
        $this->client->call('auth.test');
        return ActionResponse::empty();
    }
}
