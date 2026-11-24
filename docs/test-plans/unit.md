# Unit

**Зачем:** проверить каждую единицу логики изолированно и быстро. Карта классов и сигнатуры ниже — исходный дизайн. TDD может его менять: тогда план правится в том же срезе вместе с тестом и кодом (см. [ADR 0001](../adr/0001-double-loop-tdd.md)).

**Как:**
- **PHP:** Codeception unit на PHPUnit, покрытие через pcov.
- **JS:** Vitest с jsdom, покрытие через `@vitest/coverage-v8`.
- **Порог покрытия:** 100% по строкам и методам для PHP и по строкам, функциям и веткам для JS. Покрытие PHP считается по приложениям: класс из `common` покрывают тесты `common/tests`, вызовы из тестов `backend` в него не засчитываются.

**Когда:** на pre-push, в CI на каждый push и в `make check`.

**Изоляция:**
- **Всё, что выше репозиториев** (сервисы, формы, контроллеры, WS), получает моки зависимостей через DI-контейнер Yii.
- **Время** приходит через `Psr\Clock\ClockInterface`: в тестах замороженные часы, в Vitest `vi.useFakeTimers()`. Тестовые процессы работают в `TZ=UTC`, а часовой пояс отображения передаётся явно.
- **Redis и Workerman** заменяются моками клиента. Тест проверяет команды: INCR и EXPIRE, SET NX EX, GETDEL, PUBLISH.
- **Репозитории и ActiveRecord** — единственное исключение из «без БД». Они тестируются на тестовой Postgres внутри транзакции с откатом (модуль Yii2, `part: orm, fixtures`).

**Формат:** ID поведения стоит в имени теста. Тексты сообщений берутся из [каталога](messages.md).

---

## 1. Правила текста

### `common\text\CommentTextNormalizer`

```php
public function normalizeName(string $value): string
public function normalizeText(string $value): string
```

| ID | Поведение |
|---|---|
| UNIT-NORM-01 | `normalizeName` обрезает по краям ASCII-пробелы, Unicode-пробелы (NBSP, U+3000) и символы нулевой ширины (U+200B–200D, U+FEFF). Внутреннее содержимое не меняется |
| UNIT-NORM-02 | Имя из одних пробелов и символов нулевой ширины → `""` |
| UNIT-NORM-03 | `normalizeText` заменяет `\r\n` и одиночный `\r` на `\n` |
| UNIT-NORM-04 | `normalizeText` обрезает по краям то же, что `normalizeName`, плюс переносы строк. Внутренние переносы и пробелы сохраняются |
| UNIT-NORM-05 | Невалидный UTF-8 возвращается без изменений: отклонять его — задача валидатора |

### `common\validators\PlainTextValidator`

Yii-валидатор с параметрами `min`, `max`, `multiline`, `messagePrefix` (`name` или `text`). Получает уже нормализованное значение: нормализует форма перед валидацией. Пустое значение не пропускает (`skipOnEmpty = false`), обязательность проверяет сам.

| ID | Поведение |
|---|---|
| UNIT-VAL-01 | Провайдер для имени (2–64): длины 1, 2, 64, 65 → `too_short`, ок, ок, `too_long`. Длина считается через `mb_strlen` |
| UNIT-VAL-02 | Провайдер для текста (1–2000): длины 1, 2000, 2001 → ок, ок, `too_long` |
| UNIT-VAL-03 | Провайдер символов категории Cc: `\0`, `\x07`, `\x7F`, `\x85`, `\t` → `invalid_chars` |
| UNIT-VAL-04 | `\n` при `multiline=false` → `name.multiline`; при `multiline=true` → ок |
| UNIT-VAL-05 | Провайдер bidi-символов U+202A–202E, U+2066–2069 → `invalid_chars` |
| UNIT-VAL-06 | ZWJ, ZWNJ и VS16 разрешены. Эмодзи «семья» проходит и считается как 5 символов |
| UNIT-VAL-07 | Невалидный UTF-8 → `invalid_chars` |
| UNIT-VAL-08 | Провайдер: массив, число, `null` → `field.invalid` |
| UNIT-VAL-09 | Ошибка на поле одна, по порядку: тип → обязательность (пустая строка → `required`) → недопустимые символы → многострочность → длина |
| UNIT-VAL-10 | Провайдер конфигурации без `min` или без `max` → `InvalidConfigException`: умолчания длины нет |

### `common\forms\CommentForm`

```php
public mixed $name; public mixed $text; // поле может прийти массивом или не прийти: тип проверяет валидатор
public function formName(): string        // '' — поля без обёртки: name, text
public function getNormalizedName(): string
public function getNormalizedText(): string
```

| ID | Поведение |
|---|---|
| UNIT-CF-01 | Валидные данные → `validate()` возвращает true, геттеры отдают нормализованные значения |
| UNIT-CF-02 | Провайдер ошибок по полям: тексты из каталога, ровно одна ошибка на поле |
| UNIT-CF-03 | Пустые после нормализации поля → `name.required` и `text.required` |
| UNIT-CF-04 | Safe-атрибуты только `name` и `text`. Остальные ключи из `load()` игнорируются |
| UNIT-CF-05 | Поле пришло массивом → `field.invalid`, без исключения |
| UNIT-CF-06 | Подписи полей — `form.name` и `form.text` из каталога: по ним гость и тесты находят поля |

### `common\forms\CommentTextForm`

```php
public string $text;
public function getNormalizedText(): string
public function isSameAs(string $currentText): bool
```

| ID | Поведение |
|---|---|
| UNIT-CTF-01 | Правила текста совпадают с `CommentForm`: тот же провайдер, что в UNIT-CF-02, для поля `text` |
| UNIT-CTF-02 | `isSameAs` сравнивает нормализованный ввод с текущим текстом: отличие только в пробелах по краям или в `\r\n` → true |
| UNIT-CTF-03 | Safe-атрибут только `text` |

### Каталог сообщений

| ID | Поведение |
|---|---|
| UNIT-MSG-01 | Ключи и тексты в `tests/_data/messages.json` и в файле переводов Yii совпадают в обе стороны |

---

## 2. Данные и сценарии

### Схема (миграции)

**`user`** — урезанная шаблонная таблица:

| Колонка | Тип | Ограничения |
|---|---|---|
| `id` | serial | PK |
| `username` | varchar(64) | not null, уникальный индекс по `lower(username)` |
| `password_hash` | varchar(255) | not null |
| `auth_key` | varchar(32) | not null |
| `status` | smallint | not null; 10 = активен, 0 = заблокирован |
| `created_at`, `updated_at` | timestamptz | not null |

**`comment`:**

| Колонка | Тип | Ограничения |
|---|---|---|
| `id` | bigserial | PK |
| `author` | varchar(64) | not null |
| `text` | varchar(2000) | not null |
| `created_at` | timestamptz | not null |
| `updated_at` | timestamptz | null, пока не правили |
| `updated_by` | int | FK `user`, null |
| `deleted_at` | timestamptz | null; null = активен |
| `deleted_by` | int | FK `user`, null |

- **CHECK:** `(deleted_at IS NULL) = (deleted_by IS NULL)`.
- **Индекс:** `(deleted_at, created_at DESC, id DESC)`.
- **Порядок везде:** `created_at DESC, id DESC`.
- **Время:** в БД UTC. В JSON и событиях ISO 8601 UTC. На экране Europe/Moscow в формате `dd.MM.yyyy HH:mm`.

### `common\models\Comment` (AR) и `common\models\CommentQuery`

```php
// Comment
public function isDeleted(): bool
public function getUpdatedByUser(): ActiveQuery
public function getDeletedByUser(): ActiveQuery
// CommentQuery
public function active(): self
public function deleted(): self
public function newestFirst(): self
```

| ID | Поведение (на тестовой Postgres) |
|---|---|
| UNIT-AR-01 | `isDeleted()` возвращает false при `deleted_at = null` и true при заданном |
| UNIT-AR-02 | `active()` и `deleted()` разделяют выборку без пересечений |
| UNIT-AR-03 | `newestFirst()`: при одинаковом `created_at` выше стоит больший id |
| UNIT-AR-04 | Связи `updatedByUser` и `deletedByUser` отдают админа или null |
| UNIT-AR-05 | БД отклоняет `deleted_at` без `deleted_by` и наоборот |

### `common\repositories\CommentRepositoryInterface` и `ActiveRecordCommentRepository`

```php
public function create(string $author, string $text, DateTimeImmutable $now): Comment
public function findById(int $id): ?Comment
public function updateText(int $id, string $text, int $adminId, DateTimeImmutable $now): bool // false — комментарий удалён
public function markDeleted(int $id, int $adminId, DateTimeImmutable $now): bool              // false — уже удалён
public function markRestored(int $id): bool                                                   // false — уже активен
public function page(CommentStatus $status, int $page, int $perPage): CommentPage
```

`CommentStatus` — enum `Active` или `Deleted`. `CommentPage` — объект только для чтения: `items`, `totalCount`, `page`, `pageCount`.

| ID | Поведение (на тестовой Postgres) |
|---|---|
| UNIT-REPO-01 | `create` сохраняет автора, текст и `created_at` с микросекундами (иначе комментарий встаёт ниже соседей той же секунды). Поля `updated_*` и `deleted_*` пустые |
| UNIT-REPO-02 | `findById` для несуществующего id возвращает null |
| UNIT-REPO-03 | `updateText` для активного комментария меняет текст, `updated_at`, `updated_by` и возвращает true. Для удалённого ничего не меняет и возвращает false |
| UNIT-REPO-04 | `markDeleted` работает как условный UPDATE: первый вызов → true, второй (другим админом) → false, а `deleted_by` остаётся за первым |
| UNIT-REPO-05 | `markRestored` очищает `deleted_at` и `deleted_by` → true. Для активного → false |
| UNIT-REPO-06 | `page` фильтрует по статусу, сортирует по `created_at DESC, id DESC` и отдаёт `perPage` записей |
| UNIT-REPO-07 | Провайдер `page`: страница за пределами → последняя; 0 и отрицательная → первая; пустая выборка → `pageCount = 1`, `page = 1` |

### `common\models\User` (AR, IdentityInterface)

```php
public static function findIdentity($id): ?self            // только активные, через UserRepositoryInterface
public static function findIdentityByAccessToken($token, $type = null): never // NotSupportedException
public function getId(): int
public function getAuthKey(): string
public function validateAuthKey($authKey): bool
public function validatePassword(string $password): bool
public function isBlocked(): bool
```

| ID | Поведение |
|---|---|
| UNIT-USER-01 | `findIdentity` возвращает активного админа и null для заблокированного или несуществующего. Из-за этого заблокированный теряет сессию на следующем запросе |
| UNIT-USER-02 | `findIdentityByAccessToken` выбрасывает `NotSupportedException` |
| UNIT-USER-03 | `validatePassword` возвращает true для верного пароля и false для неверного |
| UNIT-USER-04 | `validateAuthKey` сравнивает ключ за постоянное время |
| UNIT-USER-05 | `isBlocked` возвращает true для статуса «заблокирован» и false для активного |

### `common\repositories\UserRepositoryInterface` и `ActiveRecordUserRepository`

```php
public function findActiveById(int $id): ?User
public function findByUsername(string $username): ?User  // без учёта регистра, любой статус
public function create(string $username, string $passwordHash, string $authKey, DateTimeImmutable $now): User
public function setStatus(int $id, int $status, DateTimeImmutable $now): void
public function setPassword(int $id, string $passwordHash, string $authKey, DateTimeImmutable $now): void // хэш и auth_key вместе
```

| ID | Поведение (на тестовой Postgres) |
|---|---|
| UNIT-UREPO-01 | `findByUsername('ANNA')` находит `anna` |
| UNIT-UREPO-02 | `create` логина, который отличается от существующего только регистром, падает на уникальном индексе |
| UNIT-UREPO-03 | `findActiveById` не находит заблокированного |
| UNIT-UREPO-04 | `setStatus` меняет статус, `auth_key` и `updated_at`; `setPassword` меняет хэш, `auth_key` и `updated_at`. Остальные поля не трогаются |
| UNIT-UREPO-05 | `create` сохраняет активного админа с переданными логином, хэшем, `auth_key` и временем в `created_at` и `updated_at` |

### `common\services\CommentSerializer`

```php
public function toArray(Comment $comment): array
```

| ID | Поведение |
|---|---|
| UNIT-SER-01 | Отдаёт ровно поля `id`, `author`, `text`, `status` (`active`/`deleted`), `createdAt`, `updatedAt`, `updatedBy`, `deletedBy`. Даты в ISO 8601 UTC, `updatedBy` и `deletedBy` — логин или null |
| UNIT-SER-02 | `status` вычисляется из `deleted_at` |

### `common\events\EventPublisherInterface` и `RedisEventPublisher`

```php
public function publish(string $channel, array $message): void
```

Каналы: `comments.events` (события комментариев) и `ws.control` (управляющие сообщения для WS).

| ID | Поведение |
|---|---|
| UNIT-PUB-01 | Кодирует сообщение в JSON (`JSON_THROW_ON_ERROR`, без экранирования Unicode) и отправляет `PUBLISH channel payload` в мок Redis |
| UNIT-PUB-02 | Исключение клиента Redis пробрасывается наружу как `EventPublishException` |

### `common\services\CommentService`

```php
public function create(CommentForm $form): Comment
public function updateText(int $id, CommentTextForm $form, User $admin): UpdateResult // enum Updated | Unchanged
public function delete(int $id, User $admin): bool   // false — уже удалён
public function restore(int $id, User $admin): bool  // false — уже активен
```

Исключения: `CommentNotFoundException` (контроллер → 404), `CommentIsDeletedException` (контроллер → 409).

| ID | Поведение (репозиторий, публикатор, часы и логгер — моки) |
|---|---|
| UNIT-SVC-01 | `create` передаёт в репозиторий нормализованные имя и текст и время из часов, затем публикует `comment.created` с сериализованным комментарием |
| UNIT-SVC-02 | `create`: если публикация упала (`EventPublishException`), комментарий всё равно возвращается, ошибка пишется в лог с id комментария |
| UNIT-SVC-03 | `updateText` для несуществующего → `CommentNotFoundException`, для удалённого → `CommentIsDeletedException` |
| UNIT-SVC-04 | `updateText`: если `isSameAs` вернул true → `Unchanged`, репозиторий не вызывается, событий нет |
| UNIT-SVC-05 | `updateText` с новым текстом → `updateText` репозитория, событие `comment.updated` с `updatedBy` → `Updated` |
| UNIT-SVC-06 | `updateText`: если репозиторий вернул false (удалили параллельно) → `CommentIsDeletedException`, событий нет |
| UNIT-SVC-07 | `delete`: если `markDeleted` вернул true → событие `comment.deleted`, результат true; если false → результат false, событий нет |
| UNIT-SVC-08 | `restore`: то же для `markRestored` и `comment.restored` |
| UNIT-SVC-09 | `delete` и `restore` для несуществующего → `CommentNotFoundException` |
| UNIT-SVC-10 | Если публикация упала в `updateText`, `delete` или `restore`, результат действия не меняется, ошибка пишется в лог |

### Тестовые фикстуры

| ID | Поведение (на тестовой Postgres) |
|---|---|
| UNIT-FIX-01 | Колонки таблиц `user` и `comment` из `information_schema` совпадают с полями, которые заполняют генераторы Yii-фикстур. Пара для TS-фабрик — E2E-FIX-01 |
| UNIT-FIX-02 | Два вызова генератора дают разные метки, и обе записи вставляются без конфликта уникальности |

---

## 3. Безопасность

### `common\security\RateLimiterInterface` и `RateLimiter`

```php
public function hit(string $key, int $limit, int $windowSeconds): bool // true — в пределах лимита
public function isExceeded(string $key, int $limit): bool
public function reset(string $key): void
```

Фиксированное окно. Вызывающий код передаёт ключ без префикса (`comment:{ip}`, `login:{lower(login)}:{ip}`), `RateLimiter` сам кладёт его под `rate-limit:`: в Redis это `rate-limit:comment:{ip}` (5 за 60 секунд) и `rate-limit:login:{lower(login)}:{ip}` (5 за 900 секунд). IP берётся только из `REMOTE_ADDR`. Форма входа и контроллеры получают интерфейс: в их тестах лимит — мок.

| ID | Поведение (мок Redis) |
|---|---|
| UNIT-RL-01 | `hit` выполняет INCR. Если результат равен 1, ставит EXPIRE на окно; при последующих хитах EXPIRE не вызывается |
| UNIT-RL-02 | Провайдер значений INCR: 1…5 → true, 6 → false |
| UNIT-RL-03 | `isExceeded`: GET < limit → false; ≥ limit → true; ключа нет → false |
| UNIT-RL-04 | `reset` выполняет DEL ключа |
| UNIT-RL-05 | Если Redis недоступен, все методы выбрасывают `RateLimiterUnavailableException`. Что делать дальше, решает вызывающий код: форма гостя пропускает запрос (UNIT-FC), вход отказывает (UNIT-LOGIN-07) |

### `common\redis\RedisClientInterface` и `YiiRedisClient`

Адаптер над `yiisoft/yii2-redis`: код зависит от интерфейса, unit-тесты мокают его, а не компонент. Набор команд растёт вместе с потребителями (INCR, EXPIRE, GET, DEL — для лимита; SET NX EX, GETDEL и GET — для токена WS и метки завершённой сессии; PUBLISH — для событий).

| ID | Поведение (мок `yii\redis\Connection`) |
|---|---|
| UNIT-RDS-01 | Провайдер методов: каждый отправляет свою команду с аргументами и приводит ответ к типу интерфейса (`incr` → int, `get` → строка или null, `publish` → PUBLISH) |
| UNIT-RDS-02 | Ошибка соединения `yii\db\Exception` → `RedisUnavailableException` |

### `backend\forms\LoginForm`

```php
public function __construct(yii\web\User $webUser, UserRepositoryInterface $users, RateLimiterInterface $limiter)
public string $username; public string $password;
public function login(string $ip): LoginResult // enum Success | Invalid | TooManyAttempts | Unavailable
```

IP передаёт контроллер (`Request::getRemoteIP()`, то есть `REMOTE_ADDR`): форма не читает запрос сама.

| ID | Поведение (моки: UserRepository, RateLimiter, компонент user) |
|---|---|
| UNIT-LOGIN-01 | Пустые поля → ошибки `login.username_required` и `login.password_required`, `login()` не вызывает репозиторий |
| UNIT-LOGIN-02 | Попытка засчитывается через `hit` до проверки пароля; `hit` вернул false (лимит исчерпан) → `TooManyAttempts`, пароль не проверяется. Решение по значению INCR, а не по отдельному GET: параллельные запросы не проскакивают мимо лимита |
| UNIT-LOGIN-03 | Верные данные активного админа → `Success`. Попытка засчитана, затем счётчик сброшен, вызван `user->login()`: он сам записывает в сессию `auth_key` админа (`__authKey`, см. «Сессия после смены пароля или блокировки»). Id сессии перегенерирует сам `user->login()`, это проверяет REG-A-11 |
| UNIT-LOGIN-04 | Провайдер неудач: неверный пароль, несуществующий логин, заблокированный админ → `Invalid`, ошибка `login.invalid`, счётчик вырос на 1 и не сброшен |
| UNIT-LOGIN-05 | Для несуществующего логина `password_verify` всё равно выполняется по фиктивному хэшу |
| UNIT-LOGIN-06 | Логин передаётся в репозиторий как есть, а ключ лимита строится от `lower(login)` |
| UNIT-LOGIN-07 | `RateLimiterUnavailableException` → `Unavailable` (контроллер отвечает 503 с `login.unavailable`), пароль не проверяется, ошибка пишется в лог |

### Сессия после смены пароля или блокировки

Своего поведения (`AdminSessionGuard`) нет: это уже делает `yii\web\User`. `login()` кладёт в сессию `auth_key` админа (`__authKey`), а на каждом запросе `renewAuthStatus` ищет админа через `findIdentity` (только активные) и сверяет ключ сессии через `validateAuthKey`. Не нашёлся или ключ другой — запрос гостевой, и доступ решает `AdminAccessControl`. Тесты фиксируют это на приложении админки: сессия с id админа и ключом на момент входа, действие под `AdminAccessControl` (`backend/tests/unit/SessionAuthKeyTest.php`).

| ID | Поведение (мок UserRepository) |
|---|---|
| UNIT-GUARD-01 | `auth_key` в сессии совпадает с текущим `auth_key` админа → запрос продолжается |
| UNIT-GUARD-02 | Не совпадает (пароль сменили) → запрос гостевой: HTML-маршрут уходит на логин, `/api/*` отвечает 401 |
| UNIT-GUARD-03 | Админа заблокировали после входа (`findActiveById` → null) → запрос гостевой, HTML-маршрут уходит на логин |

### `common\security\WsTokenService`

```php
public function issue(int $userId, string $sessionId, string $authKey): string
public function consume(string $token): ?WsTokenClaims // WsTokenClaims: userId, sessionId, authKey
public function endSession(string $sessionId): void
```

`authKey` — `auth_key` админа на момент выдачи: после смены пароля он другой, и WS-сервер такой токен отклоняет (UNIT-CHK-04). `endSession` при logout помечает сессию завершённой вдвое дольше времени жизни токена: её ещё не использованные токены не действуют, в том числе токен, выданный параллельным запросом чуть позже метки (ADR 0002).

| ID | Поведение (мок Redis) |
|---|---|
| UNIT-TOK-01 | `issue` генерирует 32 случайных байта в base64url (43 символа) и сохраняет `ws-token:{token}` → JSON `{userId, sessionId, authKey}` через SET NX EX 60 |
| UNIT-TOK-02 | Два `issue` подряд дают разные токены |
| UNIT-TOK-03 | `consume` выполняет GETDEL: есть значение → claims; нет → null |
| UNIT-TOK-04 | Провайдер токенов неверного формата (пусто, 42 символа, недопустимые символы) → null без обращения к Redis |
| UNIT-TOK-05 | Битый JSON в Redis → null и запись в лог |
| UNIT-TOK-06 | TTL 60 секунд передаётся в SET; истечение моделируется пустым ответом GETDEL |
| UNIT-TOK-07 | `endSession` пишет метку `ws-session-ended:{sessionId}` через SET NX EX 120 — вдвое дольше жизни токенов сессии |
| UNIT-TOK-08 | Токен сессии с меткой «завершена» → `consume` гасит его (GETDEL) и возвращает null |

### `common\security\SessionTerminator`

```php
public function __construct(EventPublisherInterface $events, WsTokenService $tokens)
public function sessionEnded(string $sessionId): void
public function accessRevoked(int $userId): void // @throws EventPublishException
```

| ID | Поведение (моки: EventPublisher, Redis под настоящим `WsTokenService`) |
|---|---|
| UNIT-TERM-01 | `sessionEnded` публикует в `ws.control` сообщение `{type: "session.ended", sessionId}` |
| UNIT-TERM-02 | `accessRevoked` публикует `{type: "access.revoked", userId}` |
| UNIT-TERM-03 | Публикация `session.ended` упала → исключение не выходит наружу, ошибка пишется в лог: пользователь всё равно вышел |
| UNIT-TERM-04 | `sessionEnded` гасит токены WS сессии (`WsTokenService::endSession`). Redis недоступен → исключение не выходит наружу, ошибка в логе, `session.ended` всё равно публикуется |
| UNIT-TERM-05 | Публикация `access.revoked` упала → `EventPublishException` выходит наружу: команду запускает оператор, и он должен узнать, что WS-сервер об отзыве не узнал (UNIT-CLI-06) |

### `common\services\AdminAccountService`

```php
public function create(string $username, string $password): User
public function block(string $username): void
public function unblock(string $username): void
public function changePassword(string $username, string $password): void
```

Исключения: `InvalidAdminDataException` (со списком ошибок) и `AdminNotFoundException`. `block` и `changePassword` пропускают наружу `EventPublishException` из `accessRevoked`: изменение в БД к этому моменту уже сохранено.

| ID | Поведение |
|---|---|
| UNIT-ACC-01 | Провайдер логинов: 2 символа, 65 символов, пробел, кириллица → `InvalidAdminDataException`; 3 и 64 символа из `[A-Za-z0-9_.-]` → ок |
| UNIT-ACC-02 | Пароль из 11 символов → ошибка, из 12 → ок |
| UNIT-ACC-03 | `create` занятого логина (в том числе в другом регистре) → `InvalidAdminDataException` |
| UNIT-ACC-04 | `create` хэширует пароль, генерирует `auth_key` и создаёт активного админа |
| UNIT-ACC-05 | `block` ставит статус «заблокирован» вместе с новым `auth_key` и после этого вызывает `accessRevoked`. Новый ключ нужен, чтобы сессия, в которую во время блокировки никто не заходил, не ожила после `unblock` (REG-A-09d) |
| UNIT-ACC-06 | `unblock` ставит статус «активен» с новым `auth_key` и `accessRevoked` не вызывает |
| UNIT-ACC-07 | `changePassword` меняет хэш, перегенерирует `auth_key` (HTTP-сессии гаснут на проверке ключа в `yii\web\User`, UNIT-GUARD) и после этого вызывает `accessRevoked`. Пароль короче 12 символов → `InvalidAdminDataException`, ничего не меняется |
| UNIT-ACC-08 | Провайдер операций с несуществующим логином → `AdminNotFoundException` |

### `console\controllers\AdminController`

Команды `admin/create <login>`, `admin/block <login>`, `admin/unblock <login>`, `admin/password <login>`. Пароль — первая строка STDIN: `printf '%s\n' "$PASSWORD" | ./yii admin/create anna`. Потоки STDIN, STDOUT и STDERR — свойства контроллера `input`, `output` и `errorOutput`, в тестах это потоки `php://memory`. Отдельный `PasswordReaderInterface` не понадобился.

| ID | Поведение (настоящий `AdminAccountService`; моки: UserRepository и часы) |
|---|---|
| UNIT-CLI-01 | Успех → код 0 и строка-подтверждение в stdout |
| UNIT-CLI-02 | `InvalidAdminDataException` → код 1, ошибки в stderr |
| UNIT-CLI-03 | `AdminNotFoundException` → код 2, `cli.admin.not_found` в stderr |
| UNIT-CLI-04 | `create` и `password` читают пароль из STDIN и никогда не печатают его |
| UNIT-CLI-05 | Поток ввода или вывода не открыт (например, закрыт) → `LogicException`. Проверка явная, не `assert()`: в production `assert` выключен |
| UNIT-CLI-06 | Провайдер `admin/block`, `admin/password`: публикация `access.revoked` упала (Redis недоступен) → код 3, `cli.admin.ws_not_notified` в stderr, stdout пуст. Подробности ошибки — в лог консоли (stderr) |

---

## 4. Веб-слой

Маршруты:

| Хост | Метод и путь | Действие |
|---|---|---|
| `comments.localhost` | `GET /` | `comment/index` (`?page=`) |
| `comments.localhost` | `POST /comments` | `comment/create` |
| `admin.comments.localhost` | `GET\|POST /login` | `auth/login` |
| `admin.comments.localhost` | `POST /logout` | `auth/logout` |
| `admin.comments.localhost` | `GET /` | `comment/index` (`?status=active\|deleted&page=`) |
| `admin.comments.localhost` | `GET\|POST /comments/{id}/edit` | `comment/update` |
| `admin.comments.localhost` | `POST /comments/{id}/delete` | `comment/delete` |
| `admin.comments.localhost` | `POST /comments/{id}/restore` | `comment/restore` |
| `admin.comments.localhost` | `GET /api/comments` | `comment/list` (JSON) |
| `admin.comments.localhost` | `POST /api/ws-token` | `ws-token/create` (JSON) |
| `admin.comments.localhost` | `/ws` | nginx проксирует в контейнер `ws` |

Контроллеры тестируются на приложении Yii, поднятом в тесте (без БД). Сервисы, репозитории и RateLimiter подменяются моками через DI-контейнер. Проверка CSRF — механизм фреймворка, её проверяет regression, а не unit.

### `frontend\controllers\CommentController`

`CommentService` — final readonly, поэтому в тестах контроллера он настоящий, а мокаются его границы: репозиторий и часы. Лимит отправок (`RateLimiterInterface`) — граница самого контроллера, он тоже мок. «Сервис не вызывается» проверяется как «репозиторий не получил `create`».

| ID | Поведение |
|---|---|
| UNIT-FC-01 | `index` запрашивает у репозитория `page(Active, ?page, 20)` и отдаёт во view страницу и пустую `CommentForm` |
| UNIT-FC-02 | `create`: сначала `RateLimiter::hit`, потом валидация. Это значит, что любой POST тратит лимит: невалидный POST при исчерпанном лимите получает 429, а не 422 |
| UNIT-FC-03 | `create` валидный → `CommentService::create`, флэш `comment.created`, редирект 302 на `/` |
| UNIT-FC-04 | `create` невалидный → 422, view ленты с формой, ошибками и введёнными значениями, сервис не вызывается |
| UNIT-FC-05 | Лимит исчерпан → 429, view с сообщением `comment.rate_limited` и введёнными значениями, сервис не вызывается |
| UNIT-FC-06 | `RateLimiterUnavailableException` → запрос продолжается как обычно, ошибка пишется в лог |
| UNIT-FC-07 | `behaviors()`: `create` разрешён только через POST |

### `backend\controllers\AuthController`

| ID | Поведение |
|---|---|
| UNIT-AUTH-01 | `login` через GET от гостя → форма; от вошедшего → редирект на список |
| UNIT-AUTH-02 | `Success` → редирект на `returnUrl`, если это относительный путь этого же хоста; иначе на список. Провайдер `returnUrl`: `/comments/5/edit` → туда; `https://evil.example`, `//evil.example`, `javascript:alert(1)`, `/\evil.example`, управляющие символы и пробелы (`/\t/evil.example`, `/\n/evil.example`, NUL в пути) → список |
| UNIT-AUTH-03 | Провайдер результатов: `Invalid` → 422 и форма; `TooManyAttempts` → 429 и `login.too_many_attempts`; `Unavailable` → 503 и `login.unavailable` |
| UNIT-AUTH-04 | `logout` вызывает `SessionTerminator::sessionEnded(id текущей сессии)` до `user->logout()`, затем пользователь — гость и редирект на логин. `SessionTerminator` настоящий, мокаются его границы: EventPublisher и Redis |
| UNIT-AUTH-05 | `behaviors()`: `login` доступен гостям, `logout` — вошедшим и только через POST |

### `backend\components\AdminAccessControl`

Общий фильтр доступа для backend.

| ID | Поведение |
|---|---|
| UNIT-ACL-01 | Гость на HTML-маршруте → редирект на логин с сохранением `returnUrl` |
| UNIT-ACL-02 | Гость на маршруте `/api/*` → 401 и JSON `{error: "unauthorized"}`, без редиректа |

### `backend\controllers\CommentController`

| ID | Поведение |
|---|---|
| UNIT-BC-01 | Провайдер `index`: `status=active` или без параметра → `page(Active, …)`; `status=deleted` → `page(Deleted, …)`; `status=foo` → 400 |
| UNIT-BC-02 | `update` через GET: активный → форма с текущим текстом; удалённый → 409 с `admin.edit_deleted`; несуществующий → 404 |
| UNIT-BC-03 | Провайдер `update` через POST: `Updated` → флэш `admin.updated` (success) и редирект; `Unchanged` → флэш `admin.unchanged` (info) и редирект; невалидно, в том числе без поля `text`, → 422 и форма; `CommentIsDeletedException` → 409; `CommentNotFoundException` из сервиса (комментарий исчез после чтения контроллером) → 404 без текста исключения |
| UNIT-BC-04 | `delete`: true → флэш `admin.deleted` (success); false → `admin.already_deleted` (info); редирект в список с сохранением вкладки и страницы (скрытые поля `status` и `page` формы строки; неверные или нет — первая страница «Активных»); несуществующий → 404 без текста исключения сервиса |
| UNIT-BC-05 | `restore`: то же с `admin.restored` и `admin.already_active` |
| UNIT-BC-06 | `list` → JSON `{items: [CommentSerializer], page, pageCount, totalCount}`; неверный `status` → 400 и JSON `{error: "invalid_status"}` |
| UNIT-BC-07 | `behaviors()`: все действия только для вошедших; VerbFilter: `delete` и `restore` только POST, `update` только GET и POST |

### `backend\controllers\WsTokenController`

| ID | Поведение |
|---|---|
| UNIT-WTC-01 | `create` вызывает `WsTokenService::issue(id админа, id сессии, auth_key админа)` и возвращает JSON `{token}` |
| UNIT-WTC-02 | Ошибка Redis → 503 и JSON `{error: "unavailable"}` |
| UNIT-WTC-03 | `behaviors()`: только вошедшие и только POST |

---

## 5. WS-сервер (PHP)

**Устройство:**
- Один процесс Workerman. Он сам подписан на каналы Redis `comments.events` и `ws.control` и рассылает сообщения своим соединениям.
- Чтобы масштабироваться, достаточно поднять число процессов или контейнеров. Кода для этого не нужно.

**Покрытие:**
- Код, который работает со статикой Workerman (запуск Worker, `Timer::add`, асинхронная подписка на Redis), лежит в `ws/runtime/` и исключён из покрытия как entrypoint.
- Логики в нём нет. Линтер ограничивает каждый файл папки 60 строками и запрещает в ней `if`, `match`, `switch`, циклы и тернарные операторы.
- Этот слой покрыт smoke, e2e и REG-WS.
- Остальная логика стоит за интерфейсами и покрыта unit на 100%.

### Интерфейсы, которые реализует `ws/runtime`

```php
interface ConnectionInterface { public function id(): string; public function send(array $message): void; public function close(int $code): void; public function reject(): void; }
interface TimerInterface      { public function after(float $seconds, callable $fn): int; public function cancel(int $timerId): void; }
```

`reject()` отвечает на handshake HTTP 403: Workerman отдаёт `Origin` только в момент handshake, поэтому проверка Origin живёт в `MessageHandler::onConnect`, а не в отдельном слое runtime с ветвлением.

### `ws\ConnectionRegistry`

```php
public function add(ConnectionInterface $c): void
public function authenticate(ConnectionInterface $c, WsTokenClaims $claims): void
public function remove(ConnectionInterface $c): void
public function isAuthenticated(ConnectionInterface $c): bool
public function claimsOf(ConnectionInterface $c): ?WsTokenClaims // токен, которым соединение авторизовалось
public function authenticated(): iterable
public function bySession(string $sessionId): iterable
public function byUser(int $userId): iterable
```

| ID | Поведение |
|---|---|
| UNIT-REG-01 | Добавленное соединение не авторизовано, `claimsOf` → null, в `authenticated()` его нет |
| UNIT-REG-02 | После `authenticate` соединение находится через `authenticated`, `bySession` и `byUser`, `claimsOf` отдаёт его claims |
| UNIT-REG-03 | `remove` убирает соединение из всех выборок; повторный `remove` не падает |
| UNIT-REG-04 | Несколько соединений одной сессии и одного админа находятся все; чужие не попадают |

### `ws\OriginPolicy`

```php
public function __construct(array $allowedOrigins) // из env: http://admin.comments.localhost
public function isAllowed(?string $origin): bool
```

| ID | Поведение |
|---|---|
| UNIT-ORIG-01 | Точное совпадение схемы, хоста и порта → true |
| UNIT-ORIG-02 | Провайдер: null, пустая строка, `http://comments.localhost`, `http://admin.comments.localhost.evil.example`, `https://` вместо `http://`, другой порт → false |

### `ws\ActiveAdminChecker`

```php
public function hasValidAccess(int $userId, string $authKey): bool // активен и auth_key тот же, что при выдаче токена; проверка при auth и на каждом ping
```

| ID | Поведение (моки: UserRepository, соединение с БД) |
|---|---|
| UNIT-CHK-01 | Активный → true, заблокированный или несуществующий → false |
| UNIT-CHK-02 | Ошибка соединения с БД → переподключение и один повтор. Повтор успешен → результат |
| UNIT-CHK-03 | Повтор тоже упал → `AdminCheckUnavailableException` |
| UNIT-CHK-04 | Админ активен, но `auth_key` не тот, что в токене (пароль сменили после выдачи) → false |

### `ws\MessageHandler`

```php
public function __construct(ConnectionRegistry $registry, WsTokenService $tokens, ActiveAdminChecker $admins, TimerInterface $timer, OriginPolicy $origins)
public function onConnect(ConnectionInterface $c, ?string $origin): void
public function onMessage(ConnectionInterface $c, string $raw): void
public function onClose(ConnectionInterface $c): void
```

Реестр, `WsTokenService` и `ActiveAdminChecker` в тестах настоящие (классы final, как `CommentService` в разделе FC): мокаются их границы — Redis, репозиторий админов и соединение с БД. Соединение и таймер — фейки из `ws/tests/_support`: тест видит отправленные сообщения, код закрытия и запланированные задержки без реального времени. Часы не нужны: время задают только таймеры.

| ID | Поведение (фейки: соединение, таймер; моки: Redis, репозиторий админов) |
|---|---|
| UNIT-MH-01 | `onConnect` добавляет соединение в реестр и ставит таймер авторизации на 5 секунд и таймер тишины на 60 |
| UNIT-MH-02 | Таймер сработал, а соединение не авторизовано → `close(4401)` |
| UNIT-MH-03 | Верный `auth` → `consume`, проверка активности, `authenticate`, отмена таймера авторизации, отправка `{type: "auth.ok"}` |
| UNIT-MH-04 | Провайдер: `consume` вернул null; админ не активен; `auth_key` сменился после выдачи токена; сессия токена завершена (logout) → `close(4401)` |
| UNIT-MH-05 | Провайдер: `AdminCheckUnavailableException`, Redis недоступен при `consume` → `close(1011)` |
| UNIT-MH-06 | Провайдер нарушений протокола: больше 4096 байт, не JSON, JSON не объект, JSON без `type`, `type` не строка, неизвестный `type`, `ping` до авторизации, повторный `auth` → `close(4400)`. Ровно 4096 байт — не нарушение |
| UNIT-MH-07 | `ping` после авторизации, доступ админа ещё действует → `{type: "pong"}` |
| UNIT-MH-08 | Каждое входящее сообщение отменяет таймер тишины и ставит новый на 60 секунд. Таймер сработал → `close(1001)` |
| UNIT-MH-09 | `onClose` удаляет соединение из реестра и отменяет его таймеры; для отклонённого handshake ничего не ломает |
| UNIT-MH-10 | `onConnect` с `Origin`, который не пропускает `OriginPolicy` → `reject()` (HTTP 403), соединение не в реестре, таймеров нет |
| UNIT-MH-11 | `ping` после авторизации перепроверяет доступ (`hasValidAccess` по claims соединения). Провайдер: админ заблокирован, `auth_key` сменился → `close(4403)`, `pong` не уходит. БД недоступна и после переподключения → `close(1011)`. Страховка на случай, если `access.revoked` из `ws.control` потерялся (ADR 0002) |

### `ws\RedisDispatcher`

```php
public function dispatch(string $channel, string $payload): void
```

| ID | Поведение |
|---|---|
| UNIT-DSP-01 | `comments.events` → сообщение уходит как есть всем авторизованным соединениям и не уходит неавторизованным |
| UNIT-DSP-02 | `ws.control` `session.ended` → `close(4403)` всем соединениям этой сессии; остальные не тронуты |
| UNIT-DSP-03 | `ws.control` `access.revoked` → `close(4403)` всем соединениям админа |
| UNIT-DSP-04 | Провайдер: битый JSON, JSON не объект, неизвестный канал, неизвестный `type`, нет `type`, событие комментария в `ws.control` и управляющее сообщение в `comments.events`, `session.ended` без `sessionId`, `access.revoked` с `userId` не числом → ничего не отправлено и не закрыто, предупреждение в логе |
| UNIT-DSP-05 | Если одно соединение упало на `send`, рассылка остальным продолжается, а упавшее закрывается с 1011 |

Подписку на Redis держит `ws/runtime/RedisSubscriber` (workerman/redis). Процесс подписан на `comments.events` (срез 05) и `ws.control` (срез 07, DSP-02, DSP-03).

### `ws\RedisSubscriberSettings`

```php
public static function of(yii\redis\Connection $redis): self // → address, options, auth для workerman/redis
```

Подписчик берёт настройки у того же компонента `redis`, что и публикация: иначе пароль или TLS, заданные только компоненту, молча оставили бы WS-сервер без событий. Номер базы не нужен: каналы pub/sub общие для всех баз.

| ID | Поведение |
|---|---|
| UNIT-SUB-01 | Адрес: `redis://host:port`; если задан `unixSocket` — `unix://путь` |
| UNIT-SUB-02 | Опции: `connect_timeout` из `connectionTimeout`, `ssl` из `useSSL`, `context` из `contextOptions` |
| UNIT-SUB-03 | Провайдер: нет пароля → AUTH не нужен; пароль → `password`; логин и пароль → `[username, password]` |

---

## 6. JS-клиент админки

**Где лежит:** ES-модули без сборки в `backend/web/js/`.

**Как тестируется:**
- Vitest с jsdom.
- Все зависимости передаются в фабрики, глобалов нет: `WebSocket`, `fetch`, таймеры, `location`.
- Время подменяется через `vi.useFakeTimers()`.

**Исключение из покрытия:** только `main.js`. Он связывает модули между собой, а настройки страницы читает через `page.js`. Ветвлений в нём нет, и это проверяет тот же линтер, что и `ws/runtime/`.

**Как показывается текст:**
- Текст комментария выводится как обычный экранированный текст со стилем `white-space: pre-line`, и в ленте, и в админке.
- `nl2br` не используется. Тогда строка от PHP и строка от JS устроены одинаково.

### `backoff.js`

```js
export function createBackoff({ baseMs = 1000, maxMs = 30000 } = {}) // → { next(): number, reset(): void }
```

| ID | Поведение |
|---|---|
| UNIT-JS-BO-01 | Последовательность `next()`: 1000, 2000, 4000, 8000, 16000, 30000, 30000 |
| UNIT-JS-BO-02 | После `reset()` снова 1000 |

### `api.js`

```js
export function createApi({ fetchImpl, csrfToken, baseUrl }) // → { fetchToken(): Promise<string>, fetchList(status, page): Promise<Page> }
```

Ошибки лежат в своих модулях (`unauthorized-error.js`, `unavailable-error.js`, `page-markup-error.js`): линтер допускает один класс на файл.

```js
export class UnauthorizedError extends Error {}
export class UnavailableError extends Error {}
```

| ID | Поведение (мок fetch) |
|---|---|
| UNIT-JS-API-01 | `fetchToken` отправляет POST `/api/ws-token` с заголовком `X-CSRF-Token` и `credentials: 'same-origin'`, возвращает `token` |
| UNIT-JS-API-02 | `fetchList` отправляет GET `/api/comments?status=…&page=…` и возвращает разобранный JSON |
| UNIT-JS-API-03 | Ответ 401 → `UnauthorizedError` |
| UNIT-JS-API-04 | Провайдер: 5xx, сетевая ошибка, невалидный JSON, JSON без строкового `token` → `UnavailableError`. Для `fetchList` то же при 5xx и JSON без массива `items` |

### `format.js`

```js
export function formatDate(iso, timeZone)   // 'dd.MM.yyyy HH:mm'
export function formatNewBanner(count, pattern) // pattern — ICU plural `admin.banner.new`, форма через Intl.PluralRules('ru')
```

| ID | Поведение |
|---|---|
| UNIT-JS-FMT-01 | `2026-11-24T11:05:00Z` в Europe/Moscow → `24.11.2026 14:05` (тот же формат, что у PHP) |
| UNIT-JS-FMT-02 | Провайдер n: 1, 2, 5, 11, 21, 22, 25 → «1 новый комментарий», «2 новых комментария», «5 новых комментариев», «11 новых комментариев», «21 новый комментарий», «22 новых комментария», «25 новых комментариев» |

### `row-renderer.js`

```js
export function createRowRenderer({ template, timeZone, messages }) // → { render(comment): HTMLTableRowElement }
```

| ID | Поведение |
|---|---|
| UNIT-JS-ROW-01 | Клонирует `<template>`, ставит `data-id` и `data-created-at` (ISO, по нему `comment-list` ставит строку на место) и заполняет автора, текст, дату и аудит через `textContent`; шаблон не меняется. В шаблоне нет `<tr>` или поля → `PageMarkupError` |
| UNIT-JS-ROW-02 | HTML в имени и тексте остаётся текстом: в строке нет элементов, которых не было в шаблоне |
| UNIT-JS-ROW-03 | `updatedBy` → «изменил: login»; `deletedBy` → «удалил: login»; null → элемент аудита пустой и скрыт. Логин с `$&` и `$'` подставляется как есть |
| UNIT-JS-ROW-04 | Действия в строке соответствуют статусу: у активного ссылка `admin.action.edit` и форма с кнопкой `admin.action.delete`, у удалённого форма с кнопкой `admin.action.restore`. У кнопок есть `data-confirm` с текстом из каталога. В шаблоне блоки `data-actions` для обоих статусов и `{id}` в адресах: лишний блок убирается, `{id}` заменяется id |

### `comment-list.js`

```js
export function createCommentList({ table, tbody, emptyState, view: { status, page, perPage }, renderRow, banner, bannerText, timers }) // → { apply(event): void, replaceAll(items): void }
```

Таблица есть и на пустой вкладке, скрытая (`hidden`), рядом текст пустого состояния: в неё встаёт комментарий, пришедший по WS. Подсветка — атрибут `data-highlighted="true"` на строке.

| ID | Поведение |
|---|---|
| UNIT-JS-LIST-01 | `created` на «Активных», страница 1 → строка первой и подсвечена, подсветка снимается через 3 секунды. На полной странице последняя строка уходит. На пустой вкладке таблица показывается, текст пустого состояния скрывается |
| UNIT-JS-LIST-02 | Провайдер видов: «Активные» стр. 2, «Удалённые» стр. 1 + `created` → строка не добавлена, баннер `formatNewBanner(1)`; следующий `created` → `(2)` |
| UNIT-JS-LIST-03 | `updated` для видимой строки → строка перерисована на месте; для невидимой → ничего. Если статус в событии не совпадает с вкладкой (событие правки опубликовано после удаления или восстановления), строка убирается, а не перерисовывается |
| UNIT-JS-LIST-04 | `deleted` на «Активных» → строка удалена, страница не дозаполняется. На «Удалённых» → строка встаёт на место по `createdAt` (при равной дате выше больший id), если попадает в диапазон страницы: новее первой строки не на стр. 1 и старше последней на полной странице — не на этой странице |
| UNIT-JS-LIST-05 | `restored`: зеркально к LIST-04 |
| UNIT-JS-LIST-06 | Повторное событие с тем же состоянием (строка уже есть или её уже нет) ничего не дублирует |
| UNIT-JS-LIST-07 | `replaceAll` перерисовывает tbody целиком, скрывает баннер и обнуляет его счётчик. Пустой список → таблица скрыта, текст пустого состояния показан |
| UNIT-JS-LIST-08 | Пустая таблица после удаления последней строки → показывается текст пустого состояния вкладки |

### `list-reload.js`

```js
export function createListReload({ list, fetchPage, backoff, timers, navigateToLogin }) // → { apply(event): void, reload(): void }
```

Связывает список с перечитыванием страницы после переподключения (ADR 0002). Пока ответ `/api/comments` в пути, события WS уже идут: они копятся и применяются после `replaceAll`, иначе снимок страницы их затёр бы. Тесты используют настоящий `comment-list` и мок `fetchPage`.

| ID | Поведение (мок `fetchPage`, фейковые таймеры) |
|---|---|
| UNIT-JS-RELOAD-01 | Вне перечитывания событие сразу уходит в список. `created`, пришедший, пока ответ в пути, после ответа остаётся первой строкой |
| UNIT-JS-RELOAD-02 | Два перечитывания подряд, ответ первого пришёл последним → на странице снимок второго. Отказ устаревшего перечитывания снимок не трогает и повтора не планирует |
| UNIT-JS-RELOAD-03 | Отказ `UnavailableError` → накопленные события применяются, повтор через `backoff.next()`; удачный повтор → `backoff.reset()` |
| UNIT-JS-RELOAD-04 | `UnauthorizedError` → `navigateToLogin()`, повтора нет |

### `page.js`

```js
export function readPage(document) // → { wsUrl, loginUrl, csrfToken, indicator, statusTexts, view, timeZone, table, tbody, emptyState, banner, bannerPattern, template, auditTexts }
```

Настройки клиента сервер кладёт в разметку списка: адрес WS, адрес логина, вид списка (вкладка, страница, строк на странице) и часовой пояс в data-атрибутах блока заголовка, тексты индикатора в data-атрибутах самого индикатора, шаблон баннера в `data-pattern` баннера, тексты аудита в data-атрибутах `<template>` строки, CSRF-токен в meta. Чтение вынесено из `main.js`: проверка, что всё на месте, — это ветвления, а `main.js` их не допускает.

| ID | Поведение |
|---|---|
| UNIT-JS-PAGE-01 | Адрес WS, адрес логина, CSRF-токен, индикатор и его тексты, вид списка, часовой пояс, таблица, tbody, текст пустой вкладки, баннер и его шаблон, шаблон строки и тексты аудита берутся из разметки |
| UNIT-JS-PAGE-02 | Провайдер: нет блока с адресом WS, индикатора, CSRF-токена, адреса логина, текста состояния, часового пояса, таблицы, tbody, текста пустой вкладки, баннера, шаблона строки или текста аудита → ошибка |

### `status-indicator.js`

| ID | Поведение |
|---|---|
| UNIT-JS-IND-01 | Провайдер `set('online' \| 'reconnecting' \| 'offline')` → текст из каталога и атрибут `data-state` |

### `ws-client.js`

```js
export function createWsClient({ url, api, backoff, WebSocketImpl, timers, onEvent, onStatus, onReconnected, navigateToLogin }) // → { start(), stop() }
```

`onEvent` и `onReconnected` появились со срезом 05 (JS-WS-03, JS-WS-08), закрытие 4403 — со срезом 07 (JS-WS-04). Любой другой код закрытия — разрыв с переподключением.

| ID | Поведение (фейковый WebSocket, мок api, фейковые таймеры) |
|---|---|
| UNIT-JS-WS-01 | `start` берёт токен, открывает соединение, после `open` отправляет `{type: "auth", token}` и показывает статус `reconnecting` до `auth.ok` |
| UNIT-JS-WS-02 | `auth.ok` → статус `online`, `backoff.reset()`, ping каждые 25 секунд. Провайдер других сообщений (`pong`, не объект, без `type`, не JSON) статус не меняет |
| UNIT-JS-WS-03 | Событие `comment.created`, `updated`, `deleted`, `restored` с объектом `comment` → `onEvent(event)`; `pong`, неизвестный `type` и событие без объекта `comment` событием не считаются |
| UNIT-JS-WS-04 | Закрытие с 4403 → статус `offline`, `navigateToLogin()`, переподключения нет |
| UNIT-JS-WS-05 | Провайдер кодов 4400, 4401, 1011, 1001, 1006 → статус `reconnecting`, пауза `backoff.next()`, новый токен, новое соединение. Ping разорванного соединения больше не уходит |
| UNIT-JS-WS-06 | Новый токен не выдали (`UnauthorizedError`) → `navigateToLogin()` |
| UNIT-JS-WS-07 | `UnavailableError` при получении токена → следующая попытка снова через backoff |
| UNIT-JS-WS-08 | Первый `auth.ok` после переподключения → `onReconnected()` (страница перечитывается). При первом подключении не вызывается |
| UNIT-JS-WS-09 | `stop` закрывает соединение, отменяет ping и запланированные попытки, переподключения нет. `stop` во время запроса токена: соединение после ответа не открывается, отказ (503) новую попытку не планирует |
