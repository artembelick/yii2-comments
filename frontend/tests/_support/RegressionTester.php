<?php

declare(strict_types=1);

namespace frontend\tests;

use Codeception\Actor;
use common\models\Comment;
use frontend\tests\_generated\RegressionTesterActions;
use RuntimeException;
use Yii;
use yii\db\Expression;
use yii\helpers\Html;

/**
 * Актор REST-regression публичной части. Действия модулей генерирует Codeception в _generated,
 * здесь — шаги, которые убирают шум из тестов (ЧТ-6): отправка формы с CSRF, сброс лимита, тексты ленты.
 */
final class RegressionTester extends Actor
{
    use RegressionTesterActions;

    /** Имя CSRF-поля публичной части. */
    public const string CSRF_PARAM = '_csrf-frontend';

    /**
     * Отправка формы гостя как есть: поля уходят без обёртки, CSRF-токен берётся со страницы ленты.
     * Перед отправкой счётчик лимита сбрасывается: все тесты приходят на стенд с одного IP.
     * Ответ не разворачивается: 302 виден как 302. Ответ читается через REST (`seeResponseContains`):
     * crawler PhpBrowser остаётся на странице ленты.
     *
     * @param array<mixed> $fields
     */
    public function postComment(array $fields): void
    {
        $this->resetCommentLimit();
        $this->postCommentWithinLimit($fields);
    }

    /**
     * Отправка формы гостя без сброса лимита: для тестов самого лимита (REG-G-22).
     *
     * @param array<mixed> $fields
     * @param array<string, string> $headers
     */
    public function postCommentWithinLimit(array $fields, array $headers = []): void
    {
        $this->amOnPage('/');
        $token = $this->grabCsrfToken();
        $this->stopFollowingRedirects();
        foreach ($headers as $name => $value) {
            $this->haveHttpHeader($name, $value);
        }

        // Тело собирается само: REST кодирует массив параметров в JSON для лога и падает на невалидном UTF-8.
        $this->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $this->sendPost('/comments', http_build_query([self::CSRF_PARAM => $token, ...$fields]));
        $this->startFollowingRedirects();
    }

    /** Текст ошибки поля в последнем ответе на отправку. */
    public function seeFieldError(string $messageKey): void
    {
        $this->seeResponseContains('<div class="invalid-feedback">' . Html::encode(Yii::t('app', $messageKey)) . '</div>');
    }

    /**
     * Сбрасывает счётчики лимита отправок всех IP: IP, с которым тесты приходят на стенд, тест не знает.
     * Ключи перебираются через SCAN, а не KEYS: KEYS блокирует Redis на время обхода.
     */
    public function resetCommentLimit(): void
    {
        $redis = Yii::$app->get('redis');
        $cursor = '0';
        do {
            /** @var array{string, list<string>} $reply */
            $reply = $redis->executeCommand('SCAN', [$cursor, 'MATCH', 'rate-limit:comment:*', 'COUNT', '100']);
            [$cursor, $keys] = $reply;
            foreach ($keys as $key) {
                $redis->executeCommand('DEL', [$key]);
            }
        } while ($cursor !== '0');
    }

    /** Адрес публичной части стенда из `PUBLIC_URL`. */
    public function publicUrl(string $path): string
    {
        return $this->standUrl('PUBLIC_URL', $path);
    }

    /** Адрес админки стенда из `ADMIN_URL`. */
    public function adminUrl(string $path): string
    {
        return $this->standUrl('ADMIN_URL', $path);
    }

    /** Хост админки стенда: домен для cookie, которые тест переносит в админку. */
    public function adminHost(): string
    {
        return (string) parse_url($this->adminUrl('/'), PHP_URL_HOST);
    }

    /**
     * Метка created_at ровно посередине между двумя комментариями: SQL-выражение, его считает Postgres.
     * Так комментарий встаёт между соседями по дате, но не по id.
     */
    public function createdAtBetween(Comment $older, Comment $newer): Expression
    {
        return new Expression(
            '(SELECT o.created_at + (n.created_at - o.created_at) / 2 FROM comment o, comment n WHERE o.id = :older AND n.id = :newer)',
            [':older' => $older->id, ':newer' => $newer->id],
        );
    }

    /** Значение cookie или null, если её нет. */
    public function grabCookieValue(string $name): ?string
    {
        $value = $this->grabCookie($name);

        return is_string($value) ? $value : null;
    }

    /** Открывает страницу по полному адресу и возвращает CSRF-токен из её meta-тега. */
    public function grabCsrfTokenAt(string $url): string
    {
        $this->amOnUrl($url);

        return $this->grabCsrfToken();
    }

    /** CSRF-токен из meta-тега текущей страницы. */
    public function grabCsrfToken(): string
    {
        $token = $this->grabAttributeFrom('meta[name="csrf-token"]', 'content');

        return is_string($token) ? $token : '';
    }

    /**
     * Открывает ленту и возвращает тексты комментариев сверху вниз. Crawler остаётся на этой странице:
     * после отправки формы это и есть проверка результата (флэш, первый в ленте), а не второе действие.
     *
     * @return list<string>
     */
    public function grabFeedTexts(string $url): array
    {
        $this->amOnPage($url);
        $this->seeResponseCodeIs(200);

        /** @var list<string> */
        return array_values($this->grabMultiple('section[aria-labelledby="feed-title"] li .comment-text'));
    }

    private function standUrl(string $variable, string $path): string
    {
        $base = getenv($variable);
        if (!is_string($base) || $base === '') {
            throw new RuntimeException($variable . ' не задан: адреса стенда берутся из .env.test.');
        }

        return rtrim($base, '/') . $path;
    }
}
