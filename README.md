# Система комментариев на Yii2

Гость оставляет комментарий в публичной ленте без входа, админ правит, удаляет и восстанавливает комментарии в админке и получает новые по WebSocket. Спецификация — `.scratch/comments-system/spec.md`, язык предметной области — `GLOSSARY.md`, решения — `docs/adr/`, тест-планы и правила тестов — `docs/test-plans/`.

## Запуск

На хосте нужны только Docker и make. PHP, Node и браузеры живут в контейнерах.

```bash
make install
```

```bash
make up
```

После `make up` обе части доступны на порту nginx **8080** (`NGINX_PORT` в `.env`):

| Часть | Адрес |
|---|---|
| Публичная часть | http://comments.localhost:8080 |
| Админка | http://admin.comments.localhost:8080 |

Имена `*.localhost` браузеры резолвят в `127.0.0.1` сами, править `/etc/hosts` не нужно. `.env` создаётся из `.env.example` при первом `make up`. Список переменных и назначение каждой — в `.env.example`.

## Проверки

```bash
make check
```

`make check` идёт от быстрого к медленному: lint → unit → smoke → regression → e2e. Первый красный шаг останавливает цепочку. Интеграционные наборы работают на отдельном тестовом стенде (compose-проект `comments-test`, `.env.test`): `make check` поднимает его с чистой БД и после прогона сносит. Dev-данные не затрагиваются.

| Цель | Что делает |
|---|---|
| `make lint` | phpstan, php-cs-fixer, rector `--dry-run`, phpcs, `composer validate`, oxlint, oxfmt, tsc, hadolint, свои проверки `tools/checks` |
| `make test-unit` | Codeception unit (pcov) и Vitest, порог покрытия 100% |
| `make test-smoke`, `make test-regression`, `make test-e2e` | Интеграционные наборы на поднятом тестовом стенде (`make stand-up`) |
| `make coverage` | Отчёты покрытия в `tests/_output` |
| `make fix` | Автоисправление стиля |
| `make help` | Все цели |

Тесты идут в случайном порядке, seed печатается в начале прогона. Повторить порядок: `SEED=<число> make test-unit`.

Свои проверки (`tools/checks`) запрещают inline-отключения линтеров, `skip` и `only`, логику и реальное время в тестах, CSS-локаторы в `/e2e`, нестрогие проверки, ветвления в entrypoint'ах и сверяют ID в именах тестов с `docs/test-plans/`. Правила и их причины — в `tools/checks/rules/`.

## Git-хуки

```bash
brew install lefthook && lefthook install
```

На pre-commit — линтеры по изменённым файлам, на pre-push — `make test-unit`.
