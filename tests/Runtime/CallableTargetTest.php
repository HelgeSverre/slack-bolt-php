<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Exceptions\InvalidListener;
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class CallableTargetTest extends TestCase
{
    protected function setUp(): void
    {
        CapturedMentions::$users = [];
    }

    public function test_class_method_array_is_container_resolved(): void
    {
        $app = $this->app()->event('app_mention', [CapturedMentions::class, 'handle']);
        $app->dispatch($this->mention());
        self::assertSame(['U123'], CapturedMentions::$users);
    }

    public function test_named_function_is_a_valid_listener(): void
    {
        $app = $this->app()->event('app_mention', __NAMESPACE__ . '\\onMention');
        $app->dispatch($this->mention());
        self::assertSame(['U123'], CapturedMentions::$users);
    }

    public function test_preconstructed_invokable_listener_keeps_its_instance(): void
    {
        $listener = new class {
            /** @var list<string> */
            public array $users = [];

            public function __invoke(AppMention $mention): void
            {
                $this->users[] = $mention->userId;
            }
        };
        $this->app()->event('app_mention', $listener)->dispatch($this->mention());
        self::assertSame(['U123'], $listener->users);
    }

    public function test_private_method_fails_during_registration(): void
    {
        $this->expectException(InvalidListener::class);
        $this->app()->event('app_mention', [CapturedMentions::class, 'hidden']);
    }

    private function app(): App
    {
        return new App(
            new GenericContainer(),
            new Client(new RecordingHttpClient(), 'test'),
            new BoltConfig('test', 'secret'),
        );
    }

    /** @return array<string, mixed> */
    private function mention(): array
    {
        return [
            'type' => 'event_callback',
            'event' => [
                'type' => 'app_mention',
                'user' => 'U123',
                'channel' => 'C123',
                'text' => 'hello',
                'ts' => '1700000000.000001',
            ],
        ];
    }
}

final class CapturedMentions
{
    /** @var list<string> */
    public static array $users = [];

    public function handle(AppMention $mention): void
    {
        self::$users[] = $mention->userId;
    }

    private function hidden(): void {}
}

function onMention(AppMention $mention): void
{
    CapturedMentions::$users[] = $mention->userId;
}
