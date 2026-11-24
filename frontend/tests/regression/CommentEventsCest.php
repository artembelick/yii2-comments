<?php

declare(strict_types=1);

namespace frontend\tests\regression;

use Codeception\Attribute\Group;
use common\tests\_support\RedisChannelProbe;
use frontend\tests\RegressionTester;

/**
 * События о комментариях гостя в канале comments.events (docs/test-plans/regression.md, «События»).
 * Правка, удаление и восстановление — в backend/tests/regression.
 */
#[Group('exclusive')]
final class CommentEventsCest
{
    /** REG-A-70: создание → ровно одно событие comment.created с полным комментарием */
    public function creationPublishesOneCreatedEvent(RegressionTester $I): void
    {
        $text = 'Событие ' . $I->fixtureFactory()->tag();
        $events = RedisChannelProbe::subscribe('comments.events');

        $I->postComment(['name' => 'Ян', 'text' => $text]);

        $I->seeResponseCodeIs(302);

        $messages = $events->messagesUntilMarker();
        $comments = RedisChannelProbe::commentsOf($messages);
        $I->assertSame(['comment.created'], array_column($messages, 'type'));
        $I->assertSame(
            [['id', 'author', 'text', 'status', 'createdAt', 'updatedAt', 'updatedBy', 'deletedBy']],
            array_map(array_keys(...), $comments),
        );
        $I->assertSame(
            [['author' => 'Ян', 'text' => $text, 'status' => 'active', 'updatedAt' => null, 'updatedBy' => null, 'deletedBy' => null]],
            array_map(static fn(array $comment): array => array_diff_key($comment, ['id' => true, 'createdAt' => true]), $comments),
        );
    }

    /** REG-A-71: невалидная отправка → событий нет */
    public function invalidSubmissionPublishesNothing(RegressionTester $I): void
    {
        $events = RedisChannelProbe::subscribe('comments.events');

        $I->postComment(['name' => 'Я', 'text' => 'Невалидное имя']);

        $I->seeResponseCodeIs(422);
        $I->assertSame([], $events->messagesUntilMarker());
    }

    /** REG-A-71: отказ по rate limit → событий нет */
    public function rateLimitedSubmissionPublishesNothing(RegressionTester $I): void
    {
        $I->exhaustCommentLimit();
        $events = RedisChannelProbe::subscribe('comments.events');

        $I->postCommentWithinLimit(['name' => 'Ян', 'text' => 'Шестой ' . $I->fixtureFactory()->tag()]);

        $I->seeResponseCodeIs(429);
        $I->assertSame([], $events->messagesUntilMarker());
    }
}
