# 01: Каркас, стенд, линтеры и CI

**What to build:** пустой, но полностью рабочий проект: обе части отвечают на своих хостах в Docker, все линтеры и все раннеры тестов настроены, `make check` и CI проходят на пустых наборах. Это walking skeleton: дальше каждый срез только добавляет поведение.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

**Внешний цикл:** `make check` проходит до конца на пустых наборах, CI зелёный на первом PR.

## Стек и конфиг

Решения приняты заранее, чтобы срезы не выбирали их заново.

| Что | Решение |
|---|---|
| Язык интерфейса | Только `ru-RU`, одна категория переводов `app` |
| Redis в Yii | `yiisoft/yii2-redis` за своим `common\redis\RedisClientInterface`, чтобы unit-тесты мокали интерфейс, а не компонент |
| Сессии админки | В Redis через `yii\redis\Session` |
| Ключи Redis | Один Redis, данные разделены префиксами: `session:`, `rate-limit:`, `ws-token:`. Тесты сбрасывают только `rate-limit:*` |
| WS-сервер | `workerman/workerman` 5.x и `workerman/redis` (см. ADR 0002) |
| Часы | `symfony/clock`: `NativeClock` в приложении, `MockClock` в тестах |
| phpstan | `yii2-extensions/phpstan`, уровень `max`, strict-rules и deprecation-rules, без `bleedingEdge` |
| Faker | `fakerphp/faker` в PHP, `@faker-js/faker` в TS, seed вычисляется из ID теста |
| JS-инструменты | `package.json` в корне (Vitest, oxlint, oxfmt, typescript) и в `/e2e` (Playwright). Всё JS запускается в контейнерах, Node на хосте не нужен |
| UI | Bootstrap 5 из шаблона, подтверждение удаления и восстановления через `data-confirm` и `yii.js` (см. `docs/test-plans/e2e.md`) |
| Конфиг | Только переменные окружения, конфиги Yii читают их через `getenv()` и падают с понятной ошибкой, если переменной нет. `.env.example` и `.env.test` лежат в git, `.env` нет |

Переменные окружения (назначение каждой описывается в `.env.example`):

| Переменная | Назначение |
|---|---|
| `POSTGRES_HOST`, `POSTGRES_PORT`, `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD` | Подключение к Postgres |
| `REDIS_HOST`, `REDIS_PORT` | Подключение к Redis |
| `YII_ENV`, `YII_DEBUG` | Окружение Yii (`dev`, `test`, `prod`) и режим отладки |
| `FRONTEND_COOKIE_KEY`, `BACKEND_COOKIE_KEY` | `cookieValidationKey` публичной части и админки |
| `PUBLIC_URL`, `ADMIN_URL` | Полные адреса обеих частей. Клиент админки подключается к WS по `/ws` на `ADMIN_URL` |
| `WS_LISTEN` | Адрес, который слушает Workerman внутри контейнера |
| `WS_ALLOWED_ORIGINS` | Разрешённые `Origin` для `OriginPolicy` |
| `DISPLAY_TIMEZONE` | Часовой пояс отображения дат, `Europe/Moscow` |
| `NGINX_PORT` | Порт nginx на хосте |
| `SMOKE_ADMIN_LOGIN`, `SMOKE_ADMIN_PASSWORD` | Админ для smoke, его создаёт `make admin` |


- [x] Ветка `main`, `.gitignore` (в том числе `.DS_Store`, `vendor`, `node_modules`, `runtime`, отчёты тестов)
- [x] Скелет yii2-app-advanced: common, frontend, backend, console и каталог ws. Signup, сброс пароля и верификация email удалены. Механизм `php init` и каталог `environments/` удалены, `index.php` лежат в git
- [x] Dev-compose с зафиксированными версиями образов: php 8.3-fpm (dev-образ с Xdebug), nginx, Postgres 15, Redis 7, заглушка `ws` на том же PHP-образе, `node` (Node 22 LTS) для JS-линтеров и Vitest, `playwright` на официальном образе той же версии, что пакет `@playwright/test`
- [x] nginx отдаёт обе части на `comments.localhost` и `admin.comments.localhost` и проксирует `/ws` на хосте админки. У контейнера nginx есть network alias'ы на оба имени
- [x] Тестовый стенд: отдельный compose-проект (`-p comments-test`) со своими БД `comments_test`, Redis, ws, nginx и `YII_ENV=test`. Поднимается, применяет миграции, после прогона сносится
- [x] Раннеры: Codeception (unit по приложениям и REST-regression, pcov, порог 100%, строгие флаги PHPUnit, случайный порядок с напечатанным seed), Vitest (jsdom, coverage-v8 с порогом 100%, `TZ=UTC`, shuffle), Playwright в своём контейнере (проекты `smoke`, `regression-ws-parallel`, `regression-ws-exclusive`, `e2e-parallel`, `e2e-exclusive` по «Где лежат тесты» в `docs/test-plans/README.md`; `retries: 0`, trace и скриншот при падении, `forbidOnly`, Chromium с `--host-resolver-rules="MAP *.localhost nginx"`)
- [x] Каркас `e2e/support`: `factories.ts` (pg), `redis.ts` (сброс счётчиков лимита), seed Faker из ID теста
- [x] Линтеры из раздела «Линтеры и правила тестов» в `docs/test-plans/README.md`: phpstan, php-cs-fixer, rector `--dry-run`, phpcs со slevomat, oxlint, oxfmt `--check`, `tsc --noEmit` с `checkJs`, hadolint, `composer validate --strict`. Каждое отключённое правило в конфиге с комментарием «почему», baseline нет
- [x] Свои проверки: запрет inline-отключений, `skip` и `only`; лимит «без ветвлений и циклов, до 60 строк» для `ws/runtime` и `main.js`; сверка ID в именах тестов с `docs/test-plans/`; запреты из `docs/test-plans/rules.md` (ДТ-1, ДТ-3, ЧТ-4, БР-1, ПР-4)
- [x] Цели `make up`, `make down`, `make install`, `make migrate`, `make lint`, `make fix`, `make admin`, `make test-unit`, `make test-smoke`, `make test-regression`, `make test-e2e`, `make coverage`, `make check`. Порядок `make check`: lint → unit → smoke → regression → e2e
- [x] lefthook: pre-commit линтеры по изменённым файлам, pre-push `make test-unit`
- [x] GitHub Actions по таблице «Запуски» из `docs/test-plans/README.md`: push → lint и unit; PR и `main` → весь `make check`, разбитый на jobs, с шардами яруса `parallel`; ночной двойной прогон с разными seed. Отчёты и trace сохраняются как артефакты
- [x] Обе части отвечают 200, порт nginx записан в README
- [ ] CI на GitHub зелёный на первом PR

## Comments

**Реализация (ветка `slice/01-skeleton`).** Решения из таблицы «Стек и конфиг», которые требуют кода, перенесены в срез, где появляется первый потребитель, чтобы не заводить код без тестов и без пользователей:

- `common\redis\RedisClientInterface` и адаптер над `yiisoft/yii2-redis` — срез 02 (RateLimiter, RL-01…05). Пакет установлен, компонент `redis` и сессии админки в Redis под `session:` настроены.
- Регистрация `NativeClock` как `Psr\Clock\ClockInterface` в DI — срез 02 (первый класс со временем). `symfony/clock` установлен.
- Seed Faker из ID теста для PHP-фикстур — срез 02 (UNIT-FIX-01, FIX-02). TS-часть есть в `e2e/support/faker.ts`.
- `make admin` вызывает `./yii admin/create`, который появляется в срезе 02 (CLI-04). Там же `make check` начинает вызывать его перед smoke.

Временные послабления, которые снимают следующие срезы: `--allow-empty` у `tools/checks` (срез 02), `--pass-with-no-tests` у Playwright (срез 04), заглушка `PlaceholderController` и UNIT-SKEL-01 (срезы 02 и 03).

Отклонение от плана: «правило линтера требует `expect.hasAssertions()`» заменено на `expect.requireAssertions` в конфиге Vitest, строгость PHPUnit поверх Codeception — на `tools/checks junit`. `docs/test-plans/README.md` поправлен.
