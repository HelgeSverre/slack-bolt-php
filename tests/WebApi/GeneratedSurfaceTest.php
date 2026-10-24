<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\WebApi;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Generated\WebApi\Models\Requests\EntityAcknowledgeCommentActionRequest;
use Thunder\Bolt\Generated\WebApi\Models\Requests\EntityPresentCommentsRequest;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\TypedResponse;

final class GeneratedSurfaceTest extends TestCase
{
    public function test_documentation_supplement_models_preserve_optional_comment_challenge_fields(): void
    {
        self::assertSame(
            ['trigger_id' => 'trigger'],
            new EntityPresentCommentsRequest(triggerId: 'trigger')->toArray(),
        );
        self::assertSame(
            [
                'trigger_id' => 'trigger',
                'comment' => ['id' => 'comment-1'],
            ],
            new EntityAcknowledgeCommentActionRequest(triggerId: 'trigger', comment: ['id' => 'comment-1'])->toArray(),
        );
    }

    public function test_every_pinned_endpoint_is_callable_with_its_exact_wire_path_and_typed_response(): void
    {
        $catalog = json_decode(
            file_get_contents(__DIR__ . '/../../docs/research/node-sdk-api-surface.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $http = new RecordingHttpClient();
        $client = new Client($http, 'test-token');
        foreach ($catalog['webApiMethods'] as $index => $method) {
            $path = explode('.', $method);
            $leaf = array_pop($path);
            $resource = $client;
            foreach ($path as $part) {
                $resource = $resource->{$part}();
            }
            $arguments = $method === 'oauth.v2.exchange' ? ['token' => 'old-token'] : [];
            $response = $resource->{$leaf}($arguments);
            self::assertInstanceOf(TypedResponse::class, $response, $method);
            self::assertSame('https://slack.com/api/' . $method, (string) $http->requests[$index]->getUri(), $method);
        }
        self::assertCount(count($catalog['webApiMethods']), $http->requests);
    }
}
