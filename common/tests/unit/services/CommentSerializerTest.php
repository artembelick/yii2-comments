<?php

declare(strict_types=1);

namespace common\tests\unit\services;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\models\Comment;
use common\models\User;
use common\services\CommentSerializer;
use PHPUnit\Framework\Attributes\TestDox;

final class CommentSerializerTest extends Unit
{
    #[TestDox('UNIT-SER-01: ровно поля события, даты в ISO 8601 UTC, updatedBy и deletedBy — логины')]
    public function testSerializesAllFieldsWithLoginsAndUtcDates(): void
    {
        $comment = $this->comment([
            'id' => 42,
            'author' => 'Ян',
            'text' => "первая\nвторая",
            'created_at' => '2026-11-24 14:05:00.123456+03',
            'updated_at' => '2026-11-24 12:30:00+00',
            'updated_by' => 7,
            'deleted_at' => '2026-11-24 13:00:00+00',
            'deleted_by' => 8,
        ]);
        $comment->populateRelation('updatedByUser', $this->admin('anna'));
        $comment->populateRelation('deletedByUser', $this->admin('boris'));

        $serialized = (new CommentSerializer())->toArray($comment);

        self::assertSame([
            'id' => 42,
            'author' => 'Ян',
            'text' => "первая\nвторая",
            'status' => 'deleted',
            'createdAt' => '2026-11-24T11:05:00Z',
            'updatedAt' => '2026-11-24T12:30:00Z',
            'updatedBy' => 'anna',
            'deletedBy' => 'boris',
        ], $serialized);
    }

    #[TestDox('UNIT-SER-01: без правок и удаления updatedAt, updatedBy и deletedBy — null')]
    public function testUntouchedCommentHasNullAudit(): void
    {
        $comment = $this->comment(['id' => 42, 'author' => 'Ян', 'text' => 'текст', 'created_at' => '2026-11-24 11:05:00+00']);
        $comment->populateRelation('updatedByUser', null);
        $comment->populateRelation('deletedByUser', null);

        $serialized = (new CommentSerializer())->toArray($comment);

        self::assertSame(
            ['updatedAt' => null, 'updatedBy' => null, 'deletedBy' => null],
            array_intersect_key($serialized, array_flip(['updatedAt', 'updatedBy', 'deletedBy'])),
        );
    }

    /**
     * @param array<string, mixed> $deletion
     */
    #[TestDox('UNIT-SER-02: status вычисляется из deleted_at')]
    #[DataProvider('deletions')]
    public function testStatusFollowsDeletedAt(array $deletion, string $expectedStatus): void
    {
        $comment = $this->comment(['id' => 42, 'author' => 'Ян', 'text' => 'текст', 'created_at' => '2026-11-24 11:05:00+00', ...$deletion]);
        $comment->populateRelation('updatedByUser', null);
        $comment->populateRelation('deletedByUser', null);

        $serialized = (new CommentSerializer())->toArray($comment);

        self::assertSame($expectedStatus, $serialized['status']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function deletions(): array
    {
        return [
            'deleted_at пуст → active' => [['deleted_at' => null], 'active'],
            'deleted_at задан → deleted' => [['deleted_at' => '2026-11-24 13:00:00+00', 'deleted_by' => 8], 'deleted'],
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function comment(array $attributes): Comment
    {
        $comment = new Comment();
        $comment->setAttributes($attributes, false);

        return $comment;
    }

    private function admin(string $username): User
    {
        $admin = new User();
        $admin->username = $username;

        return $admin;
    }
}
