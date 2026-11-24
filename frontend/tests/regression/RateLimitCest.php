<?php

declare(strict_types=1);

namespace frontend\tests\regression;

use Codeception\Attribute\Group;
use common\models\Comment;
use frontend\tests\RegressionTester;
use Yii;
use yii\helpers\Html;

/** Лимит 5 отправок в минуту с одного IP. Тесты сами управляют счётчиком: postComment() его не сбрасывает. */
#[Group('exclusive')]
final class RateLimitCest
{
    /** REG-G-22: 5 POST подряд (среди них невалидные), затем валидный 6-й → 429 с сообщением, ничего не сохранено */
    public function sixthPostWithinMinuteIsRejected(RegressionTester $I): void
    {
        $text = 'Шестой ' . $I->fixtureFactory()->tag();
        $this->spendLimit($I);

        $I->postCommentWithinLimit(['name' => 'Ян', 'text' => $text]);

        $I->seeResponseCodeIs(429);
        $I->seeResponseContains(Html::encode(Yii::t('app', 'comment.rate_limited')));
        $I->dontSeeRecord(Comment::class, ['text' => $text]);
    }

    /** REG-G-22a: после сброса счётчика валидный POST снова проходит */
    public function postPassesAfterLimitReset(RegressionTester $I): void
    {
        $text = 'После сброса ' . $I->fixtureFactory()->tag();
        $this->spendLimit($I);
        $I->resetCommentLimit();

        $I->postCommentWithinLimit(['name' => 'Ян', 'text' => $text]);

        $I->seeResponseCodeIs(302);
        $I->seeRecord(Comment::class, ['text' => $text]);
    }

    /** REG-G-22b: лимит исчерпан, 6-й запрос с X-Forwarded-For → всё равно 429: заголовок не меняет IP */
    public function forwardedForDoesNotBypassLimit(RegressionTester $I): void
    {
        $text = 'Чужой IP ' . $I->fixtureFactory()->tag();
        $this->spendLimit($I);

        $I->postCommentWithinLimit(['name' => 'Ян', 'text' => $text], ['X-Forwarded-For' => '1.2.3.4']);

        $I->seeResponseCodeIs(429);
        $I->dontSeeRecord(Comment::class, ['text' => $text]);
    }

    /** REG-G-23: GET на адрес отправки → 405 */
    public function getOnSubmitUrlIsNotAllowed(RegressionTester $I): void
    {
        $I->amOnPage('/comments');

        $I->seeResponseCodeIs(405);
    }

    /** Свежий счётчик и пять отправок: две валидные, три невалидные. */
    private function spendLimit(RegressionTester $I): void
    {
        $I->resetCommentLimit();
        $I->postCommentWithinLimit(['name' => 'Ян', 'text' => 'Первый ' . $I->fixtureFactory()->tag()]);
        $I->postCommentWithinLimit(['name' => 'Я', 'text' => 'Невалидное имя']);
        $I->postCommentWithinLimit(['name' => 'Ян', 'text' => '']);
        $I->postCommentWithinLimit(['name' => ['x'], 'text' => 'Имя массивом']);
        $I->postCommentWithinLimit(['name' => 'Ян', 'text' => 'Пятый ' . $I->fixtureFactory()->tag()]);
        $I->seeResponseCodeIs(302);
    }
}
