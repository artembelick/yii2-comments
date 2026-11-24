<?php

declare(strict_types=1);

namespace common\tests\unit\models;

use Closure;
use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use common\models\Comment;
use common\models\User;
use common\tests\UnitTester;
use PHPUnit\Framework\Attributes\TestDox;
use Yii;
use yii\db\IntegrityException;

final class CommentTest extends Unit
{
    protected UnitTester $tester;

    #[TestDox('UNIT-AR-01: isDeleted() — false у активного и true у удалённого')]
    public function testIsDeletedFollowsDeletedAt(): void
    {
        $admin = $this->tester->haveAdmin();
        $active = $this->tester->haveComment();
        $deleted = $this->tester->haveComment(['deleted_at' => '2026-11-24 10:00:00+00', 'deleted_by' => $admin->id]);

        $flags = [$active->isDeleted(), $deleted->isDeleted()];

        self::assertSame([false, true], $flags);
    }

    #[TestDox('UNIT-AR-02: active() и deleted() разделяют выборку без пересечений')]
    public function testActiveAndDeletedSplitComments(): void
    {
        $admin = $this->tester->haveAdmin();
        $active = $this->tester->haveComment();
        $deleted = $this->tester->haveComment(['deleted_at' => '2026-11-24 10:00:00+00', 'deleted_by' => $admin->id]);
        $own = [$active->id, $deleted->id];

        $split = [
            'active' => Comment::find()->active()->andWhere(['id' => $own])->select('id')->column(),
            'deleted' => Comment::find()->deleted()->andWhere(['id' => $own])->select('id')->column(),
        ];

        self::assertSame(['active' => [$active->id], 'deleted' => [$deleted->id]], $split);
    }

    #[TestDox('UNIT-AR-03: newestFirst() — новые сверху, при одинаковом created_at выше больший id')]
    public function testNewestFirstBreaksTiesById(): void
    {
        $tag = $this->tester->fixtureFactory()->tag();
        $older = $this->tester->haveComment(['author' => "older {$tag}", 'created_at' => '2026-11-24 09:00:00+00']);
        $tieFirst = $this->tester->haveComment(['author' => "tie {$tag}", 'created_at' => '2026-11-24 10:00:00+00']);
        $tieSecond = $this->tester->haveComment(['author' => "tie {$tag}", 'created_at' => '2026-11-24 10:00:00+00']);

        $order = Comment::find()->andWhere(['like', 'author', $tag])->newestFirst()->select('id')->column();

        self::assertSame([$tieSecond->id, $tieFirst->id, $older->id], $order);
    }

    /**
     * @param Closure(?Comment): ?User $relation
     */
    #[TestDox('UNIT-AR-04: связь аудита у комментария без правки и удаления — null')]
    #[DataProvider('auditRelations')]
    public function testAuditRelationOfUntouchedCommentIsNull(string $column, Closure $relation): void
    {
        $comment = $this->tester->haveComment();

        $admin = $relation(Comment::findOne($comment->id));

        self::assertNull($admin);
    }

    /**
     * @param Closure(?Comment): ?User $relation
     */
    #[TestDox('UNIT-AR-04: связь аудита отдаёт админа из своей колонки')]
    #[DataProvider('auditRelations')]
    public function testAuditRelationReturnsAdminOfItsColumn(string $column, Closure $relation): void
    {
        $expected = $this->tester->haveAdmin();
        $other = $this->tester->haveAdmin();
        $comment = $this->tester->haveComment(array_replace(
            ['updated_at' => '2026-11-24 10:00:00+00', 'updated_by' => $other->id, 'deleted_at' => '2026-11-24 11:00:00+00', 'deleted_by' => $other->id],
            [$column => $expected->id],
        ));

        $admin = $relation(Comment::findOne($comment->id));

        self::assertSame($expected->username, $admin?->username);
    }

    /**
     * Колонка аудита и связь, которая по ней читает админа. В другой колонке — другой админ.
     *
     * @return array<string, array{string, Closure(?Comment): ?User}>
     */
    public static function auditRelations(): array
    {
        return [
            'updatedByUser' => ['updated_by', static fn(?Comment $comment): ?User => $comment?->updatedByUser],
            'deletedByUser' => ['deleted_by', static fn(?Comment $comment): ?User => $comment?->deletedByUser],
        ];
    }

    #[TestDox('UNIT-AR-05: БД отклоняет deleted_at без deleted_by и наоборот')]
    #[DataProvider('halfDeletedRows')]
    public function testDatabaseRejectsHalfDeletedComment(string $onlyColumn): void
    {
        $admin = $this->tester->haveAdmin();
        $deletion = ['deleted_at' => '2026-11-24 10:00:00+00', 'deleted_by' => $admin->id];
        $row = $this->tester->fixtureFactory()->commentRow([$onlyColumn => $deletion[$onlyColumn]]);

        $this->expectException(IntegrityException::class);

        Yii::$app->db->createCommand()->insert(Comment::tableName(), $row)->execute();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function halfDeletedRows(): array
    {
        return [
            'deleted_at без deleted_by' => ['deleted_at'],
            'deleted_by без deleted_at' => ['deleted_by'],
        ];
    }
}
