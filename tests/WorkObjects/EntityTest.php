<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\WorkObjects;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BlockKit\RichText;
use Thunder\Bolt\BlockKit\RichTextSection;
use Thunder\Bolt\BlockKit\RichTextText;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\WorkObjects\Comment;
use Thunder\Bolt\WorkObjects\CommentBody;
use Thunder\Bolt\WorkObjects\Entity;
use Thunder\Bolt\WorkObjects\EntityField;
use Thunder\Bolt\WorkObjects\EntityType;
use Thunder\Bolt\WorkObjects\ExternalReference;
use Thunder\Bolt\WorkObjects\Sender;

final class EntityTest extends TestCase
{
    public function test_comment_ids_are_bounded_to_slacks_live_schema(): void
    {
        $comment = Comment::make(str_repeat('c', 25), Sender::slack('U1'), CommentBody::text('Hello'), 100);
        self::assertSame(25, strlen($comment->id));
        $this->expectException(\InvalidArgumentException::class);
        Comment::make(str_repeat('c', 26), Sender::slack('U1'), CommentBody::text('Hello'), 100);
    }

    public function test_entity_builder_is_immutable_and_serializes_work_object_metadata(): void
    {
        $base = Entity::task(
            new ExternalReference('TASK-42', 'acme-task'),
            'https://work.example.test/TASK-42',
            'Ship it',
        );
        $entity = $base
            ->attribute('priority', 'high')
            ->field('assignee', EntityField::user(Sender::slack('U-ada'))->editable())
            ->comments(2, 1_700_000_002)
            ->unfurlUrl('https://work.example.test/TASK-42?unfurl=1');

        self::assertSame(['title' => ['text' => 'Ship it']], $base->toArray()['entity_payload']['attributes']);
        self::assertSame(
            [
                'entity_type' => 'slack#/entities/task',
                'external_ref' => ['id' => 'TASK-42', 'type' => 'acme-task'],
                'url' => 'https://work.example.test/TASK-42',
                'entity_payload' => [
                    'attributes' => ['title' => ['text' => 'Ship it'], 'priority' => 'high'],
                    'fields' => [
                        'assignee' => [
                            'type' => 'slack#/types/user',
                            'user' => ['user_id' => 'U-ada'],
                            'edit' => ['enabled' => true],
                        ],
                    ],
                ],
                'entity_comments' => ['count' => 2, 'latest_timestamp' => 1_700_000_002],
                'app_unfurl_url' => 'https://work.example.test/TASK-42?unfurl=1',
            ],
            $entity->toArray(),
        );
        self::assertArrayNotHasKey('app_unfurl_url', $entity->details());
    }

    public function test_comment_and_body_values_validate_and_preserve_wire_data(): void
    {
        $body = CommentBody::richText(RichText::make(RichTextSection::make(RichTextText::make('A rich reply'))));
        $comment = Comment::make('C-1', Sender::external('Ada', 'https://cdn.example.test/ada.png'), $body, 100)
            ->inThread('C-root')
            ->permissions(edit: true, delete: false, reply: true)
            ->editedAt(110)
            ->replies(1, 111, Sender::slack('U-ada'));

        self::assertSame('C-root', $comment->toArray()['thread_root_id']);
        self::assertTrue($comment->toArray()['can_edit']);
        self::assertSame(1, $comment->toArray()['reply_count']);
        self::assertSame('rich_text', $comment->toArray()['comment']['blocks']['type']);
        self::assertSame(
            ['enabled' => true],
            Comment::fromArray([
                'id' => 'C-2',
                'sender' => ['user_id' => 'U-ada'],
                'comment' => ['value' => 'Plain'],
                'timestamp' => 101,
                'future_option' => ['enabled' => true],
            ])->toArray()['future_option'],
        );

        $this->expectException(\InvalidArgumentException::class);
        Entity::make(EntityType::Item, new ExternalReference('I-1'), 'https://work.example.test/I-1', 'Item')->field(
            'forbidden',
            EntityField::text('value'),
        );
    }

    public function test_message_entities_use_entity_metadata_and_cannot_mix_with_event_metadata(): void
    {
        $entity = Entity::task('TASK-1', 'https://work.example.test/TASK-1', 'Task');
        $message = Message::text('Shared work item')->entities($entity);

        self::assertSame(['entities' => [$entity->toArray()]], $message->toArray()['metadata']);

        $this->expectException(\LogicException::class);
        (void) $message->metadata('example_event', ['id' => 'EVENT-1']);
    }
}
