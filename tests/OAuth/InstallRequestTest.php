<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\OAuth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\OAuth\InstallRequest;

final class InstallRequestTest extends TestCase
{
    public function test_fluent_options_are_immutable_and_metadata_is_a_detached_snapshot(): void
    {
        $name = 'original';
        $scope = 'commands';
        $defaults = InstallRequest::make();
        $request = new InstallRequest(scopes: [&$scope])
            ->userScopes('users:read')
            ->team('T123')
            ->metadata(['account' => &$name, 'amount' => 1.0, 'nested' => ['enabled' => true]]);
        $name = 'changed';
        $scope = 'chat:write';

        self::assertNull($defaults->scopes);
        self::assertSame([], $defaults->metadata);
        self::assertSame(['commands'], $request->scopes);
        self::assertSame(['users:read'], $request->userScopes);
        self::assertSame('original', $request->metadata['account']);
        self::assertSame($request->toArray(), InstallRequest::fromArray($request->toArray())->toArray());
        self::assertStringNotContainsString('original', print_r($request, true));
        self::assertSame([], $request->scopes()->scopes);
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_options_are_rejected(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InstallRequest(...$arguments);
    }

    public static function invalidOptions(): iterable
    {
        yield 'scope with spaces' => [['scopes' => ['bad scope']]];
        yield 'scope map' => [['scopes' => ['x' => 'commands']]];
        yield 'invalid workspace' => [['team' => 'https://elsewhere.test']];
        yield 'empty workspace' => [['team' => '']];
        yield 'object metadata' => [['metadata' => ['object' => new \stdClass()]]];
        yield 'oversize metadata' => [['metadata' => ['long' => str_repeat('a', 65_536)]]];
    }

    public function test_recursive_metadata_is_rejected(): void
    {
        $value = [];
        $value['self'] = &$value;
        $this->expectException(\InvalidArgumentException::class);
        new InstallRequest(metadata: $value);
    }
}
