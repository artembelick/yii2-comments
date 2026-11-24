# Система комментариев на Yii2

Гость оставляет комментарий в публичной ленте без входа, админ правит, удаляет и восстанавливает комментарии в админке и получает новые по WebSocket.

**Отчёт о тестах:** https://artembelick.github.io/yii2-comments/ — итог прогона `main`, покрытие кода и требований, путь комментария с тестом на каждом шаге, методики и стек.

## Запуск

На хосте нужны только Docker и make.

```bash
make install   # зависимости PHP и JS в контейнерах
make up        # dev-стенд и миграции
printf '%s\n' 'пароль-админа' | docker compose exec -T php ./yii admin/create anna
```

Публичная часть — http://comments.localhost:8080, админка — http://admin.comments.localhost:8080. Пароль админа (от 12 символов) читается из STDIN. Git-хуки (линтеры на pre-commit, unit на pre-push): `brew install lefthook && lefthook install`.

## Команды

| Команда | Что делает |
|---|---|
| `make up` | Поднять dev-стенд |
| `make check` | Все проверки на свежем тестовом стенде: lint → unit → smoke → regression → e2e |
| `make report` | После `make check`: отчёт Allure, покрытие и страница отчёта в `site/index.html` |
| `make help` | Все цели |

## Наборы тестов

- **unit** — классы и JS-модули изолированно (Codeception, Vitest), порог покрытия 100%.
- **smoke** — стенд жив: страницы, вход, WS (Playwright).
- **regression** — контракты HTTP и WS: валидация, права, безопасность (Codeception REST, Playwright).
- **e2e** — путь комментария в браузере: гость и несколько админов (Playwright).

Спецификация — `.scratch/comments-system/spec.md`, термины — `GLOSSARY.md`, решения — `docs/adr/`, тест-планы и правила тестов — `docs/test-plans/`.
