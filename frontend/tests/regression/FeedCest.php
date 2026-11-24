<?php

declare(strict_types=1);

namespace frontend\tests\regression;

use Codeception\Attribute\DataProvider;
use Codeception\Attribute\Group;
use Codeception\Example;
use common\tests\_support\Examples;
use frontend\tests\RegressionTester;
use Yii;
use yii\db\Expression;

final class FeedCest
{
    /** REG-G-01: GET / → 200, форма со скрытым CSRF-полем, блок ленты */
    #[Group('parallel')]
    public function feedPageHasFormAndFeed(RegressionTester $I): void
    {
        $I->amOnPage('/');

        $I->seeResponseCodeIs(200);
        $I->seeElement('#comment-form input[type="hidden"][name="' . RegressionTester::CSRF_PARAM . '"]');
        $I->seeElement('#comment-form input[name="name"]');
        $I->seeElement('#comment-form textarea[name="text"]');
        $I->see(Yii::t('app', 'feed.title'), 'h1');
        $I->seeElement('section[aria-labelledby="feed-title"]');
    }

    /** REG-G-02: лента без комментариев → «Пока нет комментариев» */
    #[Group('pristine')]
    public function emptyFeedShowsEmptyState(RegressionTester $I): void
    {
        $I->amOnPage('/');

        $I->seeResponseCodeIs(200);
        $I->see(Yii::t('app', 'feed.empty'), 'section[aria-labelledby="feed-title"]');
        $I->dontSeeElement('section[aria-labelledby="feed-title"] li');
    }

    /** REG-G-03: три комментария с разными датами → новые сверху, порядок по дате, а не по id */
    #[Group('exclusive')]
    public function newestCommentsComeFirst(RegressionTester $I): void
    {
        $oldest = $I->haveComment();
        $newest = $I->haveComment();
        $betweenOldestAndNewest = $I->createdAtBetween($oldest, $newest);
        $middle = $I->haveComment(['created_at' => $betweenOldestAndNewest]);

        $texts = $I->grabFeedTexts('/');

        $I->assertSame([$newest->text, $middle->text, $oldest->text], array_slice($texts, 0, 3));
    }

    /**
     * REG-G-04: 20 на странице, на ?page=2 первым 21-й, abc, -1 и page[]=1 → первая, всегда 200
     *
     * @param Example<string, mixed> $example
     */
    #[Group('exclusive')]
    #[DataProvider('pages')]
    public function feedIsPaginatedByTwenty(RegressionTester $I, Example $example): void
    {
        $newestFirst = array_reverse($I->haveComments(21));

        $texts = $I->grabFeedTexts('/?' . Examples::string($example, 'query'));

        $I->assertSame(array_slice($newestFirst, (int) Examples::string($example, 'offset'), 1), array_slice($texts, 0, 1));
        $I->assertCount(20, $texts);
    }

    /**
     * @return array<string, array{query: string, offset: string}>
     */
    private function pages(): array
    {
        return [
            '?page=2 → 21-й первым на второй странице' => ['query' => 'page=2', 'offset' => '20'],
            '?page=abc → первая' => ['query' => 'page=abc', 'offset' => '0'],
            '?page=-1 → первая' => ['query' => 'page=-1', 'offset' => '0'],
            '?page[]=1 → первая' => ['query' => 'page[]=1', 'offset' => '0'],
        ];
    }

    /** REG-G-04: ?page=999 → последняя страница, 200 */
    #[Group('exclusive')]
    public function pageBeyondRangeShowsLastPage(RegressionTester $I): void
    {
        $I->haveComments(21);

        $I->amOnPage('/?page=999');

        $I->seeResponseCodeIs(200);
        $I->assertSame(
            $I->grabTextFrom('nav[aria-label="' . Yii::t('app', 'pager.label') . '"] li:nth-last-child(2)'),
            $I->grabTextFrom('nav[aria-label="' . Yii::t('app', 'pager.label') . '"] li.active'),
        );
    }

    /** REG-G-05: удалённого комментария в ленте нет, активный рядом с ним есть */
    #[Group('exclusive')]
    public function deletedCommentIsHidden(RegressionTester $I): void
    {
        $admin = $I->haveAdmin();
        $active = $I->haveComment();
        $deleted = $I->haveComment(['deleted_at' => new Expression('now()'), 'deleted_by' => $admin->id]);

        $I->amOnPage('/');

        $I->see($active->text, 'section[aria-labelledby="feed-title"]');
        $I->dontSee($deleted->text);
    }

    /**
     * REG-G-07: разметка в имени и тексте экранирована, перенос строки показан переносом
     *
     * @param Example<string, mixed> $example
     */
    #[Group('exclusive')]
    #[DataProvider('markup')]
    public function markupIsEscaped(RegressionTester $I, Example $example): void
    {
        $tag = $I->fixtureFactory()->tag();
        $I->haveComment([Examples::string($example, 'column') => Examples::string($example, 'value') . ' ' . $tag]);

        $I->amOnPage('/');

        $I->seeInSource(Examples::string($example, 'escaped') . ' ' . $tag);
        $I->dontSeeInSource(Examples::string($example, 'value') . ' ' . $tag);
    }

    /**
     * @return array<string, array{column: string, value: string, escaped: string}>
     */
    private function markup(): array
    {
        return [
            'HTML в имени' => ['column' => 'author', 'value' => '<b>Ян</b>', 'escaped' => '&lt;b&gt;Ян&lt;/b&gt;'],
            '<script> в имени' => [
                'column' => 'author',
                'value' => '<script>window.__xss=1</script>',
                'escaped' => '&lt;script&gt;window.__xss=1&lt;/script&gt;',
            ],
            'HTML в тексте' => [
                'column' => 'text',
                'value' => '<img src=x onerror="window.__xss=1">',
                'escaped' => '&lt;img src=x onerror=&quot;window.__xss=1&quot;&gt;',
            ],
            '<script> в тексте' => [
                'column' => 'text',
                'value' => '<script>window.__xss=1</script>',
                'escaped' => '&lt;script&gt;window.__xss=1&lt;/script&gt;',
            ],
        ];
    }

    /** REG-G-07: перенос строки в тексте показан переносом, а не склеен */
    #[Group('exclusive')]
    public function lineBreakIsKept(RegressionTester $I): void
    {
        $tag = $I->fixtureFactory()->tag();
        $I->haveComment(['text' => "первая строка\nвторая строка " . $tag]);

        $I->amOnPage('/');

        $I->seeInSource("<div class=\"comment-text\">первая строка\nвторая строка " . $tag . '</div>');
    }
}
