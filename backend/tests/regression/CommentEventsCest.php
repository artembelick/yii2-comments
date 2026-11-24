<?php

declare(strict_types=1);

namespace backend\tests\regression;

use backend\tests\RegressionTester;
use Codeception\Attribute\DataProvider;
use Codeception\Attribute\Group;
use Codeception\Example;
use common\repositories\CommentStatus;
use common\tests\_support\Examples;
use common\tests\_support\RedisChannelProbe;

/**
 * События о правке, удалении и восстановлении в канале comments.events (docs/test-plans/regression.md, «События»).
 * Создание — в frontend/tests/regression: его вызывает гость. Даты в событии проверяет UNIT-SER-01.
 */
#[Group('exclusive')]
final class CommentEventsCest
{
    /** Поля комментария в событии. */
    private const array FIELDS = ['id', 'author', 'text', 'status', 'createdAt', 'updatedAt', 'updatedBy', 'deletedBy'];

    /** Поля, которые тест сверяет по значению: даты проверяет UNIT-SER-01. */
    private const array COMPARED = ['id' => true, 'author' => true, 'text' => true, 'status' => true, 'updatedBy' => true, 'deletedBy' => true];

    /**
     * REG-A-70: правка, удаление и восстановление → ровно одно событие нужного типа с полным комментарием
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('changingActions')]
    public function changingActionPublishesOneEvent(RegressionTester $I, Example $example): void
    {
        $admin = $I->haveAdmin();
        $comment = $I->haveCommentWithStatus(Examples::commentStatus($example, 'commentStatus'), $admin);
        $I->amSignedInAs($admin);
        $events = RedisChannelProbe::subscribe('comments.events');

        $I->submitAdminForm(sprintf('/comments/%d/%s', $comment->id, Examples::string($example, 'action')), Examples::array($example, 'fields'));

        $messages = $events->messagesUntilMarker();
        $I->seeResponseCodeIs(302);
        $I->assertSame([Examples::string($example, 'type')], array_column($messages, 'type'));
        $I->assertSame([self::FIELDS], array_map(array_keys(...), RedisChannelProbe::commentsOf($messages)));
        $I->assertSame(
            [[
                'id' => $comment->id,
                'author' => $comment->author,
                'text' => $comment->text,
                'status' => 'active',
                'updatedBy' => null,
                'deletedBy' => null,
                ...Examples::array($example, 'changed'),
                ...array_fill_keys(Examples::strings($example, 'byAdmin'), $admin->username),
            ]],
            $this->comparedFieldsOf($messages),
        );
    }

    /**
     * Действие, статус комментария до него, поля формы и ожидаемое событие. В событии у комментария те же поля,
     * что до действия, кроме `changed`, а в полях `byAdmin` — логин админа, который действовал.
     *
     * @return array<string, array{action: string, commentStatus: CommentStatus, fields: array<string, string>, type: string, changed: array<string, string>, byAdmin: list<string>}>
     */
    private function changingActions(): array
    {
        return [
            'правка' => [
                'action' => 'edit',
                'commentStatus' => CommentStatus::Active,
                'fields' => ['text' => 'Новый текст после правки'],
                'type' => 'comment.updated',
                'changed' => ['text' => 'Новый текст после правки'],
                'byAdmin' => ['updatedBy'],
            ],
            'удаление' => [
                'action' => 'delete',
                'commentStatus' => CommentStatus::Active,
                'fields' => [],
                'type' => 'comment.deleted',
                'changed' => ['status' => 'deleted'],
                'byAdmin' => ['deletedBy'],
            ],
            'восстановление' => [
                'action' => 'restore',
                'commentStatus' => CommentStatus::Deleted,
                'fields' => [],
                'type' => 'comment.restored',
                'changed' => [],
                'byAdmin' => [],
            ],
        ];
    }

    /**
     * REG-A-71: правка без изменений (в том числе после очистки), повторное удаление или восстановление → событий нет
     *
     * @param Example<string, mixed> $example
     */
    #[DataProvider('noopActions')]
    public function noopActionPublishesNothing(RegressionTester $I, Example $example): void
    {
        $admin = $I->haveAdmin();
        $comment = $I->haveCommentWithStatus(Examples::commentStatus($example, 'commentStatus'), $admin);
        $I->amSignedInAs($admin);
        $events = RedisChannelProbe::subscribe('comments.events');

        $I->submitAdminForm(sprintf('/comments/%d/%s', $comment->id, Examples::string($example, 'action')), ['text' => $comment->text . Examples::string($example, 'textSuffix')]);

        $I->seeResponseCodeIs(302);
        $I->assertSame([], $events->messagesUntilMarker());
    }

    /**
     * Действие и статус комментария, на котором оно ничего не меняет: правка тем же текстом активного
     * (в том числе с вырезаемыми символами в конце — textSuffix), удаление удалённого, восстановление активного.
     *
     * @return array<string, array{action: string, commentStatus: CommentStatus, textSuffix: string}>
     */
    private function noopActions(): array
    {
        return [
            'правка без изменений' => ['action' => 'edit', 'commentStatus' => CommentStatus::Active, 'textSuffix' => ''],
            'правка, совпавшая после очистки' => ['action' => 'edit', 'commentStatus' => CommentStatus::Active, 'textSuffix' => "\0\u{202E}\xFF"],
            'повторное удаление' => ['action' => 'delete', 'commentStatus' => CommentStatus::Deleted, 'textSuffix' => ''],
            'повторное восстановление' => ['action' => 'restore', 'commentStatus' => CommentStatus::Active, 'textSuffix' => ''],
        ];
    }

    /**
     * Поля комментариев из событий, которые сверяются по значению.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    private function comparedFieldsOf(array $messages): array
    {
        return array_map(static fn(array $comment): array => array_intersect_key($comment, self::COMPARED), RedisChannelProbe::commentsOf($messages));
    }
}
