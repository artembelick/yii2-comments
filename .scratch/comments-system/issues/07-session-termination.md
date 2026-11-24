# 07: Logout, блокировка и смена пароля гасят доступ

**What to build:** logout закрывает WS только своей сессии. Блокировка админа и смена пароля консольными командами завершают все его HTTP-сессии и WS-соединения, другие админы не затронуты. Добавляются команды `admin/block`, `admin/unblock` и `admin/password`.

**Blocked by:** 05.

**Status:** done

**Внешний цикл (пишется первым):**
- E2E-06 (logout закрывает WS только своей сессии).

**Внутренний цикл (TDD, по одному тесту):**
- Unit PHP: UNIT-GUARD-01…03, TERM-01…03, ACC-05…08, CLI-03 (и CLI-01, CLI-02, CLI-04 для новых команд), AUTH-04, UREPO-04, DSP-02, DSP-03
- Unit JS: UNIT-JS-WS-04
- Regression: REG-A-09b, A-09c, REG-WS-08, WS-09, WS-11

**Готово, когда:**
- [x] Перечисленные тесты зелёные, тесты предыдущих срезов остаются зелёными, покрытие 100%
- [x] Токен WS, выданный до logout или смены пароля, после них соединение не открывает (REG-WS-11). Сейчас `MessageHandler` после гашения проверяет только `findActiveById`, и такой токен живёт до 60 секунд TTL; DSP-02 закрывает только уже открытые соединения. Решить и записать в ADR 0002: при logout и смене пароля гасить `ws-token:*` этой сессии или админа (нужен индекс по сессии) либо при `auth` проверять, что сессия жива и `auth_key` совпадает. Найдено на ревью среза 04
- [ ] `make check` и CI зелёные
- [x] `/code-review` среза пройден (ревью проведено, находки исправлены)

Не зависит от 06: срезы 06 и 07 можно делать в любом порядке, если они не идут одновременно в одних файлах.

## Comments

**Реализация (ветка `slice/07-session-termination`).** Внешний цикл: E2E-06 написан первым и был красным по правильной причине — после logout в первой вкладке вторая вкладка А оставалась на списке (`toHaveURL(/login)` не дождался). Зелёный после DSP-02, AUTH-04 и JS-WS-04. `make check` в worktree зелёный. CI не запускался, ветка не запушена, `/code-review` не проводился.

Что сделано:
- Logout: `AuthController::actionLogout` до `user->logout()` вызывает `SessionTerminator::sessionEnded(id сессии)` — метка `ws-session-ended:{id}` в Redis и `{type: "session.ended", sessionId}` в `ws.control`.
- Блокировка и смена пароля: `AdminAccountService::block`, `unblock`, `changePassword` (новый хэш и новый `auth_key`), `AdminNotFoundException`; `block` и `changePassword` после записи в БД вызывают `accessRevoked` → `{type: "access.revoked", userId}`. Репозиторий: `setStatus`, `setPassword` (хэш и `auth_key` вместе). Команды `admin/block`, `admin/unblock`, `admin/password` (пароль из STDIN), коды 0/1/2, сообщения `cli.admin.blocked|unblocked|password_changed|not_found`.
- WS-сервер: подписка на `ws.control`, `RedisDispatcher` закрывает с 4403 (`CloseCode::SESSION_ENDED`) соединения сессии или админа; непонятное управляющее сообщение — предупреждение в логе. Клиент: закрытие 4403 → «Отключено» и логин без переподключения.
- REG-WS-11 (пункт про токен, выданный до logout или смены пароля): решено проверкой при `auth`, записано в ADR 0002. В токене лежит `auth_key` админа на момент выдачи, `ActiveAdminChecker::isActive(userId, authKey)` сверяет его с текущим. Logout пишет метку `ws-session-ended:{id сессии}` на TTL токена, `WsTokenService::consume` гасит токен такой сессии и возвращает null. Индекс токенов не нужен, гонки «выдача токена параллельно с logout или сменой пароля» нет.

Отклонения от плана (внесены в `docs/test-plans/`):
- `AdminSessionGuard` не нужен: `yii\web\User` сам кладёт `auth_key` в сессию (`__authKey`) при `login()` и на каждом запросе сверяет его (`renewAuthStatus`), заблокированного не находит `findIdentity`. UNIT-GUARD-01…03 фиксируют это на приложении админки (`backend/tests/unit/SessionAuthKeyTest.php`), GUARD-03 теперь про заблокированного, а не про гостя. Ручная запись `auth_key` в сессию из `LoginForm` (её никто не читал) убрана вместе с параметром `Session`, LOGIN-03 поправлен.
- Новые ID: UNIT-TOK-07, TOK-08 (метка завершённой сессии), TERM-04 (`sessionEnded` гасит токены сессии, сбой Redis — в лог), CHK-04 (`auth_key` сменился → false). MH-04, DSP-04, TOK-01, WTC-01, ACC-05, ACC-07, UREPO-04 (`setPassword` вместо `setPasswordHash`), AUTH-04, CLI-03 уточнены.
- REG-WS-09 и WS-11 (смена пароля): контейнер Playwright без PHP и не может запустить `./yii`, поэтому `e2e/support/accounts.ts` повторяет эффект команд для WS-сервера (БД + `access.revoked` в `ws.control`). Сами команды на стенде запускают REG-A-09b и A-09c (`RegressionTester::runAdminCommand`), вызов `accessRevoked` — UNIT-ACC-05, ACC-07. Записано в `regression.md`.
- E2E-06, шаг 2: проверяется уход второй вкладки на логин; статус «Отключено» при 4403 — UNIT-JS-WS-04 (вкладка уходит на логин сразу, индикатор не увидеть).

Красная фаза. Unit PHP и JS писались до кода и падали по правильной причине (нет класса или метода, нет поведения: DSP-02/03 после добавления константы падали на `closedWith` null, AUTH-04 — на пустом списке публикаций, JS-WS-04 — на переподключении). REG-WS-11 оба набора были красными до реализации (приходил `auth.ok`). Остальные regression и GUARD — мутациями: код ломался в worktree на стенде, после возврата всё зелёное.

| Мутация | Тест | Результат |
|---|---|---|
| logout без `sessionEnded` | REG-WS-08 | красный: соединение сессии 1 не закрыто 4403 |
| `session.ended` закрывает все авторизованные | REG-WS-08 | красный: соединение сессии 2 закрыто |
| `access.revoked` никого не закрывает | REG-WS-09 | красный |
| `block` без `setStatus` | REG-A-09b | красный: 200 вместо 302 на логин |
| `changePassword` сохраняет прежний `auth_key` | REG-A-09c | красный: старая сессия жива |
| `User::validateAuthKey` всегда true | UNIT-GUARD-02 (оба) | красные |
| `findIdentity` без проверки статуса | UNIT-GUARD-03 | красный |

Оставлено:
- Метка `ws-session-ended:*` пишется на каждый logout и живёт 60 секунд; при сбое Redis на logout токены сессии остаются действовать до TTL (ошибка в логе, соединения всё равно закрываются по `session.ended`, если публикация прошла).
- `admin/unblock` не трогает сессии и WS: заблокированный админ уже вышел, ему нужно войти заново.

**Исправления после ревью среза.**

Исправить до слияния:
- Блокировка не завершала неактивные сессии: после `admin/unblock` сессия, в которую во время блокировки не заходили, оживала. Теперь `setStatus` пишет статус вместе с новым `auth_key`, его меняют и `block`, и `unblock` (UNIT-UREPO-04, ACC-05, ACC-06). Новый REG-A-09d: вошёл → block → unblock без запросов → старая сессия уходит на логин. Красный на мутации «`setStatus` не пишет `auth_key`» (200 вместо 302). Записано в ADR 0002. Пункт «Оставлено» про `admin/unblock` ниже теперь верен: старые сессии после блокировки не действуют.
- При сбое Redis `admin/block` и `admin/password` молча сообщали об успехе. Теперь `SessionTerminator::accessRevoked` пропускает `EventPublishException` наружу (UNIT-TERM-05, бывший TERM-03 для `access.revoked`). Команда печатает в stderr `cli.admin.ws_not_notified` и выходит с кодом 3 (UNIT-CLI-06, текст в `app.php`, `messages.json`, `messages.md`). Logout по-прежнему только пишет в лог (TERM-03). У консоли появилась цель лога (stderr, `bootstrap: log`, без `logVars`). Проверено вручную на стенде: Redis остановлен → код 3, предупреждение и строка лога с причиной.

Мелочи по правилам:
- REG-WS-08 и WS-09: одно действие, «соединение живо» проверяется в Assert хелпером `WsProbe::isAlive()`.
- `AuthControllerTest`: дубль с неверным ID UNIT-AUTH-05 удалён, проверка «после logout пользователь — гость» перенесена в AUTH-04, Arrange AUTH-04 без пустых строк.
- `AdminControllerTest`: коды выхода и id названы (`EXIT_NOT_FOUND`, `EXIT_WS_NOT_NOTIFIED`, `ANNA_ID`).

На усмотрение:
- Регрессия `POST /api/ws-token` → 401 после `admin/block` и после `admin/password` (REG-A-09b, A-09c, `backend/tests/regression/AccessRevocationCest.php`). Красные на мутации «`WsTokenController` без `AdminAccessControl`».
- Сквозная связка «команда → сообщение»: REG-A-09b и A-09c подписываются на `ws.control` (`RedisChannelProbe`) и проверяют, что настоящая команда публикует `{type: "access.revoked", userId}` с id числом. Красные на мутации «`block` и `changePassword` без `accessRevoked`». Метка конца в `ws.control` WS-серверу непонятна: он пишет предупреждение в лог.
- Перепроверка доступа на WS: сделана, укладывается в ADR 0002 (та же проверка по БД, что при `auth`). На каждом `ping` `MessageHandler` вызывает `ActiveAdminChecker::hasValidAccess(userId, authKey)` по claims соединения (реестр теперь хранит `WsTokenClaims`, `claimsOf`). Доступ кончился → 4403, БД недоступна → 1011 (UNIT-MH-11, REG-WS-12: блокировка только в БД без `access.revoked` → на ping 4403). REG-WS-12 был красным на старом ws-процессе (pong вместо закрытия). Записано в ADR 0002 вместе с ограничением: logout такой страховки не получает, перепроверка не знает, жива ли сессия Yii.
- TTL метки `ws-session-ended` — 120 секунд, вдвое больше TTL токена (UNIT-TOK-07, TERM-04). SET NX оставлен: id сессии после входа новый, метка заранее не существует.
- `ActiveAdminChecker::isActive` → `hasValidAccess`, повтор выражения вынесен в приватный `check`. Поле `SessionTerminator $sessions` → `$terminator` в `AuthController` и `AdminAccountService`.
- Дублирование в тестах: `repositoryWith`, `anna` и сборка `SessionTerminator` — в трейте `common/tests/_support/AdminAccountDoubles.php`. Репозиторий с ответами по очереди для MH-11 — `ws/tests/_support/AnsweringUsers.php`.
- `RegressionTester::runAdminCommand` читает один поток (`2>&1`).
- Пароль с терминала: в справке команды сказано, что при вводе с терминала он виден и его передают через pipe. Отключение echo не делалось: документированный способ — pipe, а `stty` в контейнере без TTY лишний.
- `e2e/support/accounts.ts`: `blockAdmin` повторяет новый эффект `admin/block` — статус и новый `auth_key`.
