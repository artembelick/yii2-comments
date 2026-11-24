<?php

declare(strict_types=1);

namespace backend\tests;

use backend\tests\_generated\RegressionTesterActions;
use Codeception\Actor;
use common\models\Comment;
use common\models\User;
use common\repositories\CommentStatus;
use common\tests\_support\FixtureFactory;
use RuntimeException;
use Yii;
use yii\db\Expression;
use yii\helpers\Html;

/**
 * Актор REST-regression админки. Действия модулей генерирует Codeception в _generated,
 * здесь — шаги, которые убирают шум из тестов (ЧТ-6): вход, CSRF-токен, серия неудачных входов.
 */
final class RegressionTester extends Actor
{
    use RegressionTesterActions;

    /** Имя CSRF-поля админки. */
    public const string CSRF_PARAM = '_csrf-backend';

    /**
     * POST формы админки как есть: CSRF-токен со страницы списка, поля без обёртки. Ответ не разворачивается:
     * 302 виден как 302, куда он ведёт — в заголовке Location. Без токена: `$withCsrf = false`.
     *
     * @param array<mixed> $fields
     */
    public function submitAdminForm(string $url, array $fields, bool $withCsrf = true): void
    {
        $this->amOnPage('/');
        $csrf = $withCsrf ? [self::CSRF_PARAM => $this->grabCsrfToken()] : [];
        $this->stopFollowingRedirects();
        // Тело собирается само: REST кодирует массив параметров в JSON для лога и падает на невалидном UTF-8.
        $this->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $this->sendPost($url, http_build_query([...$csrf, ...$fields]));
        $this->startFollowingRedirects();
    }

    /**
     * Ответ — редирект на $url (путь админки). Тест переходит по нему и видит на открытой странице флэш с текстом
     * $messageKey: флэш показывается только после редиректа.
     */
    public function followRedirectAndSeeFlash(string $url, string $messageKey): void
    {
        $this->seeResponseCodeIs(302);
        $this->seeHttpHeader('Location', 'http://admin.comments.localhost' . $url);
        $this->amOnPage($url);
        $this->see(Yii::t('app', $messageKey), '.alert');
    }

    /** Текст ошибки поля в последнем ответе. */
    public function seeFieldError(string $messageKey): void
    {
        $this->seeResponseContains('<div class="invalid-feedback">' . Html::encode(Yii::t('app', $messageKey)) . '</div>');
    }

    /** Активный комментарий или удалённый админом $admin. */
    public function haveCommentWithStatus(CommentStatus $status, User $admin): Comment
    {
        return $this->haveComment(match ($status) {
            CommentStatus::Active => [],
            CommentStatus::Deleted => ['deleted_at' => new Expression('now()'), 'deleted_by' => $admin->id],
        });
    }

    /**
     * Тексты первой страницы JSON-списка вкладки. Комментарий-предусловие с текущей датой стоит на ней, только
     * пока другие тесты не создают комментарии параллельно: такие проверки живут в ярусе exclusive.
     *
     * @return list<string>
     */
    public function grabJsonTexts(string $status): array
    {
        /** @var list<string> */
        return array_column($this->grabJsonList($status, 1)['items'], 'text');
    }

    /**
     * Колонки комментария, как они лежат в БД сейчас.
     *
     * @param list<string> $columns
     *
     * @return array<string, mixed>
     */
    public function grabCommentColumns(int $id, array $columns): array
    {
        $comment = Comment::findOne($id) ?? throw new RuntimeException(sprintf('Комментария %d нет в БД', $id));

        return $comment->getAttributes($columns);
    }

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

    /**
     * Настоящая консольная команда `./yii <command> <username>` в контейнере стенда, $stdin — её STDIN
     * (пароль первой строкой). Команда должна завершиться с кодом 0, иначе тест падает с её выводом.
     */
    public function runAdminCommand(string $command, string $username, string $stdin = ''): void
    {
        // stderr в тот же поток, что stdout (2>&1): два канала, прочитанные по очереди, могут заблокировать друг друга.
        $process = proc_open(
            sprintf('php %s %s %s 2>&1', escapeshellarg(dirname(__DIR__, 3) . '/yii'), escapeshellarg($command), escapeshellarg($username)),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('Команда %s не запустилась', $command));
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $exitCode = proc_close($process);
        $this->assertSame(0, $exitCode, sprintf('Команда %s %s: %s', $command, $username, $output));
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

    /** Текст аудита («изменил: …», «удалил: …») в строке списка с текстом $text на странице $url. */
    public function grabAuditOf(string $url, string $text): string
    {
        $this->amOnPage($url);
        $this->seeResponseCodeIs(200);
        $audits = array_combine($this->grabMultiple('tbody tr [data-field="text"]'), $this->grabMultiple('tbody tr [data-field="audit"]'));

        return $audits[$text] ?? throw new RuntimeException(sprintf('На %s нет строки с текстом %s', $url, $text));
    }

    /**
     * GET /api/comments вошедшим админом: ответ 200, разобранный JSON страницы вкладки.
     *
     * @return array{items: list<array<string, mixed>>, page: int, pageCount: int, totalCount: int}
     */
    public function grabJsonList(string $status, int $page): array
    {
        $this->sendGet('/api/comments', ['status' => $status, 'page' => $page]);
        $this->seeResponseCodeIs(200);

        /** @var array{items: list<array<string, mixed>>, page: int, pageCount: int, totalCount: int} */
        return json_decode($this->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
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

    /**
     * Директивы заголовка Cache-Control последнего ответа: `no-store, no-cache` → `['no-store', 'no-cache']`.
     *
     * @return list<string>
     */
    public function grabCacheControlDirectives(): array
    {
        $header = $this->grabHttpHeader('Cache-Control');

        return is_string($header) ? array_map(trim(...), explode(',', $header)) : [];
    }

    /** Значение cookie сессии админки или null, если её нет. */
    public function grabSessionId(): ?string
    {
        $value = $this->grabCookie('comments-admin');

        return is_string($value) ? $value : null;
    }
}
