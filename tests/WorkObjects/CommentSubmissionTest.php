<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\WorkObjects;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\WorkObjects\CommentSubmission;

final class CommentSubmissionTest extends TestCase
{
    public function test_parses_rich_text_for_a_post_submission(): void
    {
        $submission = CommentSubmission::fromView(View::fromArray($this->payload()));

        self::assertSame('TRIGGER-1', $submission->triggerId);
        self::assertSame('WORK-1', $submission->reference->id);
        self::assertNull($submission->commentId);
        self::assertSame('ROOT-1', $submission->threadRootId);
        self::assertSame('rich_text', $submission->body->toArray()['blocks']['type']);
    }

    public function test_rejects_a_non_submission_even_when_it_has_comment_shaped_view_data(): void
    {
        $payload = $this->payload();
        $payload['type'] = 'view_closed';

        $this->expectException(\InvalidArgumentException::class);
        CommentSubmission::fromView(View::fromArray($payload));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'type' => 'view_submission',
            'trigger_id' => 'TRIGGER-1',
            'view' => [
                'id' => 'V-1',
                'type' => 'entity_comment',
                'callback_id' => 'work-object-post-comment',
                'external_ref' => ['id' => 'WORK-1'],
                'thread_root_id' => 'ROOT-1',
                'state' => [
                    'values' => [
                        'comment_input_block' => [
                            'comment_input_action' => [
                                'rich_text_value' => [
                                    'type' => 'rich_text',
                                    'elements' => [[
                                        'type' => 'rich_text_section',
                                        'elements' => [['type' => 'text', 'text' => 'Hello']],
                                    ]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
