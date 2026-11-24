<?php

declare(strict_types=1);

namespace backend\tests;

use backend\tests\_generated\RegressionTesterActions;
use Codeception\Actor;
use common\models\User;
use common\tests\_support\FixtureFactory;

/**
 * Актор REST-regression админки. Действия модулей генерирует Codeception в _generated,
 * здесь — шаги, которые убирают шум из тестов (ЧТ-6): вход, CSRF-токен, серия неудачных входов.
 */
final class RegressionTester extends Actor
{
    use RegressionTesterActions;

    /** Отправляет форму входа со страницы логина: CSRF-токен берётся из формы. */
    public function submitLogin(string $username, string $password): void
    {
        $this->amOnPage('/login');
        $this->submitForm('#login-form', ['LoginForm[username]' => $username, 'LoginForm[password]' => $password]);
    }

    /** Вход админа из фикстуры с паролем по умолчанию; после входа открыт список. */
    public function amSignedInAs(User $admin): void
    {
        $this->submitLogin($admin->username, FixtureFactory::ADMIN_PASSWORD);
        $this->seeResponseCodeIs(200);
        $this->seeElement('#logout-form');
    }

    /** Серия неудачных входов с неверным паролем. */
    public function failLogin(string $username, int $times): void
    {
        for ($attempt = 1; $attempt <= $times; ++$attempt) {
            $this->submitLogin($username, 'wrong-password');
            $this->seeResponseCodeIs(422);
        }
    }

    /** CSRF-токен из meta-тега текущей страницы. */
    public function grabCsrfToken(): string
    {
        $token = $this->grabAttributeFrom('meta[name="csrf-token"]', 'content');

        return is_string($token) ? $token : '';
    }

    /**
     * POST /api/ws-token вошедшим админом (CSRF-заголовок ставит тест): ответ 200, токен из JSON.
     */
    public function issueWsToken(): string
    {
        $this->sendPost('/api/ws-token');
        $this->seeResponseCodeIs(200);
        $token = $this->grabDataFromResponseByJsonPath('$.token');

        return is_string($token[0] ?? null) ? $token[0] : '';
    }

    /**
     * Тексты комментариев в строках списка на странице, сверху вниз.
     *
     * @return list<string>
     */
    public function grabTextsOfPage(string $url): array
    {
        $this->amOnPage($url);
        $this->seeResponseCodeIs(200);

        /** @var list<string> */
        return array_values($this->grabMultiple('tbody tr .comment-text'));
    }

    /**
     * Заголовки Set-Cookie последнего ответа.
     *
     * @return list<string>
     */
    public function grabSetCookieHeaders(): array
    {
        return array_values(array_filter((array) $this->grabHttpHeader('Set-Cookie', false), is_string(...)));
    }

    /** Значение cookie сессии админки или null, если её нет. */
    public function grabSessionId(): ?string
    {
        $value = $this->grabCookie('comments-admin');

        return is_string($value) ? $value : null;
    }
}
