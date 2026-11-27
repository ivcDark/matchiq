# Загрузка данных (ingestion) и наблюдаемость

**Статус:** схема ingest **принята пользователем 2026-11-26** (уточняет [№ 36](../database/open-questions.md), заменяет формулировку «Go пишет в ту же БД»): **с PostgreSQL работает только Laravel**; Laravel и Go общаются **через RabbitMQ** — команды туда, результаты обратно. Primary-источник — **SStats.net** ([обзор и OpenAPI](../ingestion/README.md)). Код сборщиков Go здесь не приводим — только структура и контракт.  
Слои и каталоги Laravel — [README.md](README.md). Таблицы — [../database/schema.md](../database/schema.md#7-источники-данных-и-маппинг).

## Цели

1. **Источник сменяем:** SStats.net → другой API/парсер без смены внутренних `id`, URL и доменных таблиц. Единственное место, знающее источник, — Go-сборщик; наружу он отдаёт канонические DTO.
2. **Прозрачность в будущей админке:** для любой сущности видно, из какого источника она пришла, когда и каким прогоном обновлялась; для любого прогона — счётчики и **ошибки по конкретным сущностям**.
3. **Идемпотентность:** повторный прогон тех же данных не создаёт дублей и не меняет БД; повторная доставка сообщения из очереди безопасна.
4. **Одна точка записи:** все модели, репозитории и миграции — в Laravel; Go не знает схему БД и не получает `DATABASE_URL`.

## Кто что делает

| Роль | Кто | Что делает |
|------|-----|------------|
| Создание джобы | **Laravel** (`artisan` по расписанию; позже — админка) | Берёт lock, создаёт `ingestion_runs` (`status = running`), публикует **команду** в RabbitMQ. Команда/job тонкие: ни HTTP-запросов к источнику, ни парсинга |
| Сбор | **Go-сборщик** (`collector/`) | Читает команду из RabbitMQ, по `job_type` запускает нужный сборщик (расписание, результаты, статистика, составы, live…), ходит в **SStats.net**, нормализует ответ в **канонические DTO**, публикует **результат** в RabbitMQ (батчи сущностей + ошибки по сущностям + финальное сообщение). **В PostgreSQL не пишет и не читает** |
| Сохранение | **Laravel consumer** (`artisan`-воркер, очередь результатов) | Читает результат, резолвит сущности (`external_ids` → `team_aliases` → ревью), сохраняет через репозитории/модели, обновляет `external_ids`, пишет `ingestion_run_errors`, считает `stats` и закрывает `ingestion_runs` |
| Чтение итогов | Laravel (админка позже) | `IngestionRunRepository` по `ingestion_runs`, `ingestion_run_errors`, `external_ids` |

Всё, что требует знания БД (resolve, сравнение с текущими значениями, upsert, транзакции, `name_i18n`, очередь несматченных), живёт в Laravel. Всё, что требует знания источника (URL, пагинация, лимиты, формат ответа, ретраи HTTP), — в Go. Граница — контракт сообщений ниже.

Смена источника — новый сборщик/адаптер в Go плюс строки `source_providers` и `competition_sources` (создаёт Laravel). Доменные таблицы, репозитории и Laravel-консьюмер не меняются, пока адаптер отдаёт тот же канон DTO.

## Поток данных

```
Laravel (artisan / scheduler; позже админка)
   │ 1. lock + INSERT ingestion_runs (running)
   │ 2. publish command ───────────────►  RabbitMQ: ingest.commands
   │                                              │
   │                                              ▼
   │                                      Go collector (consumer)
   │                                        job_type → сборщик
   │                                        fetch  ← SStats.net (apikey, лимиты, ретраи)
   │                                        normalize → канон DTO
   │                                              │ publish batch / entity errors / done
   │                                              ▼
   │                                      RabbitMQ: ingest.results
   │                                              │
   ▼                                              ▼
Laravel consumer (artisan ingest:consume)  ◄──────┘
   resolve → upsert через репозитории → external_ids (last_seen_at, last_ingestion_run_id)
   ingestion_run_errors, ingestion_runs.stats / status / finished_at
        │
        ▼
   PostgreSQL  (пишет только Laravel)
```

### Топология RabbitMQ (набросок)

| Объект | Тип / имя | Назначение |
|--------|-----------|------------|
| Exchange | `matchiq.ingest` (direct, durable) | Единая точка публикации |
| Очередь команд | `ingest.commands` (durable), routing key `command` | Laravel → Go. Prefetch у Go-консьюмера ограничен (по умолчанию 1–4 команды параллельно, чтобы не превысить лимиты SStats) |
| Очередь результатов | `ingest.results` (durable), routing key `result` | Go → Laravel |
| DLQ | `ingest.commands.dlq`, `ingest.results.dlq` | Сообщения, которые не удалось обработать после N попыток (по умолчанию 3); разбираются вручную/админкой |

Правила: сообщения persistent, ручной `ack` только после обработки (Go — после публикации результата; Laravel — после коммита транзакции), формат — JSON UTF-8, `content_type = application/json`. Один прогон = одна команда = несколько сообщений результата (батчи). Порядок батчей внутри прогона не гарантируем — поэтому у сообщений есть `seq`, а завершение определяется финальным сообщением с `batches_total`.

Размер сообщения ограничен (по умолчанию ≤ 1 МБ, ~сотни сущностей на батч): сезон РПЛ режется на несколько батчей. Сырые ответы SStats в очередь **не** кладём (см. «Что НЕ делаем»).

## Контракт сообщений (набросок)

Общий конверт — для команд и результатов:

| Поле | Смысл |
|------|-------|
| `schema_version` | версия контракта (целое, сейчас `1`); несовместимое изменение — новая версия, консьюмеры отвергают неизвестную в DLQ |
| `message_id` | UUID сообщения (идемпотентность консьюмера, трассировка) |
| `run_id` | `ingestion_runs.id` — сквозной id прогона |
| `job_type` | тип джобы = `ingestion_runs.kind`: `schedule` \| `match_stats` \| `lineups` \| `live` (новые типы, например `results`, добавляются вместе с enum/CHECK в Laravel) |
| `provider` | `source_providers.code` (например `sstats`) |
| `sent_at` | ISO-8601 UTC |

### Команда (Laravel → Go)

```json
{
  "schema_version": 1,
  "message_id": "b6f1…",
  "run_id": 4812,
  "job_type": "schedule",
  "provider": "sstats",
  "sent_at": "2026-11-26T12:00:00Z",
  "payload": {
    "competition": { "id": 1, "external_id": "310", "season_external_id": "2025" },
    "window": { "from": "2026-11-01", "to": "2027-05-31" },
    "match_external_ids": [],
    "context": { "trigger": "schedule", "requested_by": null }
  }
}
```

`payload` зависит от `job_type`: для `schedule` — лига + окно дат; для `match_stats`/`lineups`/`live` — `match_external_ids`. Внешние id для Go берёт Laravel из `competition_sources.settings` и `external_ids` — Go справочников БД не читает. `context` копируется в `ingestion_runs.stats.context`.

### Результат (Go → Laravel)

Три вида сообщений (поле `kind`):

**`batch`** — пачка нормализованных сущностей и ошибок этой пачки:

```json
{
  "schema_version": 1,
  "message_id": "9d20…",
  "run_id": 4812,
  "job_type": "schedule",
  "provider": "sstats",
  "kind": "batch",
  "seq": 1,
  "entities": [
    {
      "entity_type": "match",
      "external_id": "1415356",
      "external_url": null,
      "data": {
        "competition_external_id": "310",
        "season_external_id": "2025",
        "round": "15",
        "home_team": { "external_id": "4021", "name": "Zenit", "name_local": "Зенит" },
        "away_team": { "external_id": "4033", "name": "Spartak Moscow", "name_local": "Спартак" },
        "kickoff_at": "2026-11-29T16:30:00Z",
        "status": "scheduled",
        "home_goals": null,
        "away_goals": null
      }
    }
  ],
  "errors": [
    {
      "entity_type": "match",
      "external_id": "1415999",
      "phase": "normalize",
      "message": "не распознан статус матча",
      "context": { "raw_status": "Xyz" }
    }
  ]
}
```

- `entities[].data` — **канонические DTO**, нейтральные к источнику: ссылки между сущностями только через внешние id (`*_external_id`) либо вложенные объекты с `external_id`; наши `id` Go не знает. Типы `entity_type` — значения `ExternalEntityType` (`team`, `match`, `player`, …) плюс дочерние (`match_event`, `lineup`, `match_team_stats`, `match_player_stats`) внутри `data` родителя.
- Статусы, периоды, типы событий — уже наши enum-значения (нормализация в Go); неизвестное значение источника — ошибка сущности (`phase = normalize`), а не падение прогона.
- `errors[]` — **ошибки по сущностям на стороне Go** (`phase` = `fetch` \| `parse` \| `normalize`). Без секретов и сырых HTML.

**`done`** — финал сборки (ровно одно на прогон, после всех `batch`):

```json
{ "kind": "done", "batches_total": 5, "entities_total": 412, "errors_total": 3,
  "source": { "requests": 7, "rate_limited": 0 } }
```

**`failed`** — прогон не состоялся целиком (SStats недоступен, неверный `apikey`, исчерпан лимит запросов, неверная команда): `{ "kind": "failed", "message": "…", "retryable": false }`. Laravel ставит `ingestion_runs.status = failed`, `error = message`.

### Обработка в Laravel consumer

1. Сообщение валидируется (схема, `schema_version`, существование `run_id` и `status = running`); невалидное — в DLQ.
2. `batch`: для каждой сущности — resolve (`external_ids` → `team_aliases`/естественные ключи → очередь ревью, `unmatched`), сравнение с текущими полями, upsert в транзакции **на сущность**; `external_ids.last_seen_at` и `last_ingestion_run_id` обновляются всегда. Ошибки Go из `errors[]` и свои (`match`, `upsert`) пишутся в `ingestion_run_errors`. Счётчики добавляются в `stats`.
3. `done`/`failed`: Laravel сверяет число принятых батчей с `batches_total`, считает итоговый `status`, ставит `finished_at`. Если батчи не пришли (потеря/таймаут) — работает уборщик (см. ниже).
4. Повторная доставка того же `batch` безопасна: upsert идемпотентен (ключ — `external_ids`), счётчики прогона не удваиваются благодаря учёту `seq` (принятые `seq` хранятся в `stats.context.batches_seen` либо в Redis по `run_id`; точная реализация — при кодировании).

## Структура Go-сборщика (без кода)

```
collector/
├── cmd/collector/            # точка входа: конфиг, подключение к RabbitMQ, graceful shutdown, /healthz
└── internal/
    ├── mq/                   # consumer команд, publisher результатов, конверт, ретраи/DLQ
    ├── contract/             # DTO сообщений и канонические DTO (Team, Match, Event…) — единый источник контракта в Go
    ├── jobs/                 # реестр: job_type → обработчик (schedule, results, match_stats, lineups, live)
    ├── sources/
    │   └── sstats/           # клиент SStats.net: apikey (из env), rate limit, ретраи, маппинг ответов
    ├── normalize/            # SStats → канон DTO (статусы, периоды, события), без БД
    └── config/               # env: RABBITMQ_URL, SSTATS_API_KEY, лимиты, prefetch
```

- **Сборщик на тип джобы:** реестр `job_type → Collector`. Новый тип (например, статистика игроков) = новый сборщик + строка реестра + новый `kind` в Laravel; mq-слой не меняется.
- **Адаптер источника изолирован** в `sources/<provider>`; `normalize` и `contract` не импортируют клиентов API.
- Секреты (`SSTATS_API_KEY`) — только env; в сообщения и логи не попадают. У сервиса `collector` в Compose нет `DATABASE_URL` и зависимости от `postgres`.
- Rate limit SStats (без ключа 30 запросов/мин на IP, с ключом — по аккаунту; `GET /Account/Info`) соблюдается внутри клиента; превышение → `failed` с `retryable = true` или отложенный повтор.

## Реализация первого среза (каталог и структура сезона)

Реализовано в `collector/` и `web/`: `sync_catalog`, `sync_structure`, `sync_fixtures`, `sync_match_details`, `sync_squads` (см. ниже); остальные job types (`live`) — следующие срезы; scheduler и воркеры — раздел «Расписание и постоянные воркеры». Расписание матчей в коде — `sync_fixtures`, а не `schedule` из наброска.

**Отличия от наброска выше (источник правды — код):**

- `job_type` реализованных срезов: `sync_catalog` (страны, лиги/соревнования, их сезоны), `sync_structure` (стадии и команды сезона) и `sync_fixtures` (матчи сезона/стадии). Значения живут в `ingestion_runs.kind` (varchar, без CHECK) и в `App\Enums\IngestionKind`.
- `payload` `sync_catalog`: `{"filters": {"country": "Russia", "league_name": "Premier League", "league_ids": []}, "context": {...}}`; `sync_structure`: `{"competition": {"id": 1, "external_id": "..."}, "season_external_id": "...", "context": {...}}` (сезон опционален: берётся текущий). `context` всегда JSON-объект.
- Результат: сообщения `batch` содержат `entities[] = {entity_type, external_id, data}` для типов `country | competition | season | stage | team`, отсортированных по зависимостям (справочники → сезоны → стадии/команды); ошибки нормализации — `errors[]` в последнем батче; завершение — `done` с `batches_total` либо `failed` с `retryable`.
- `sync_structure` дополнительно читает из `/Ls/SeasonStructure` `standingTables`, `rounds`, `stageTeams`, а для стадий с ≥2 таблицами — `/Ls/Standings` (состав групп). Сущности: `stage_group` (по `stage`+`name`), `round` (external_id = id раунда SStats; номер тура или `sortOrder` раунда плей-офф, `code` из названия: `1/16-finals` → `R32`, `Final` → `F`), `stage_team` (по `stage`+`team`, `group_name`). Стадия `table` с ≥2 таблицами получает тип `group`. Порядок применения: … → стадии → группы/раунды → `stage_team` → матчи. Явных пар (`ties`) SStats не отдаёт: `tie_id`/`leg` не заполняются, клуб группирует матчи по раунду и сопернику. `sync_fixtures` передаёт `round_external_id`; `MatchUpserter` ставит `stage_group_id` по общей группе команд в `stage_teams`, а `StageTeamUpserter` дозаполняет группу у уже загруженных матчей.
- Очереди ошибок: отдельные `ingest.commands.dlq` / `ingest.results.dlq` (через `x-dead-letter-*`), а не единая `ingest.dlq`. Невалидное сообщение отвергается без requeue и попадает в DLQ; при сбое публикации результата команда один раз возвращается в очередь, затем — в DLQ.
- Go-пакеты: `cmd/collector`, `internal/{config,dto,amqp,sstats,jobs}` (вместо `mq/contract/sources/normalize`; нормализация живёт в `internal/jobs`).
- Go собирается, тестируется и запускается только в Docker (`docker/collector/Dockerfile`, multi-stage, Go 1.27): `docker compose --profile tools build collector-test` (тесты выполняются на сборке стадии `test`), `docker compose build collector`.
- Laravel: `php artisan ingest:dispatch {kind}` создаёт `ingestion_runs` и публикует команду; `php artisan ingest:consume` читает `ingest.results` и применяет батчи через репозитории (`App\Services\Ingestion\ApplyIngestionResult`). Повторная доставка батча безопасна (`stats.context.batches_seen`), на каждый прогон пишется не более 1000 строк `ingestion_run_errors`.

**`sync_fixtures` — расписание и результаты матчей** (эндпоинт SStats `/Ls/List`, пагинация по `Offset`, до 1000 матчей на запрос):

- Payload: `{"competition": {"id", "external_id"}, "season_external_id": "...", "stage_external_id": "..."?, "window": {"from": "YYYY-MM-DD", "to": "YYYY-MM-DD"}?, "context": {...}}`. Сезон обязателен (для Laravel — текущий, если не указан `--season`); `stage_external_id` и `window` необязательны, окно включительное с обеих сторон (UTC).
- Результат: `entities[]` с `entity_type = match`; `data` — канонический матч: `season_external_id`, `stage_external_id`, `round_name`/`round_number` (из «Round N»), `home_team_external_id`/`away_team_external_id`, `kickoff_at` (UTC), `status` (`scheduled | live | finished | postponed | cancelled | abandoned`), `period` (только live, если известен), счёт `home_goals`/`away_goals` (после основного и доп. времени, без серии пенальти; `null`, пока матч не начался), `*_ht`, `*_et`, `home_pens`/`away_pens`. Статус вне известной таблицы SStats — ошибка сущности (`normalize`), не падение прогона.
- Laravel: `MatchUpserter` → `MatchRepository`/`RoundRepository`. Команды, стадии и сезон резолвятся по `external_ids` (их должны создать `sync_catalog`/`sync_structure`; иначе ошибка `match` по строке матча); `competition_id` берётся из сезона, стадия обязана принадлежать сезону; тур (`rounds`) создаётся по номеру только для «Round N». Новый `external_id` сначала ищет ещё не привязанный матч той же пары в сезоне в ±1 день (страховка от дублей), иначе создаёт. Поля, которых источник не отдаёт (площадка, судья, погода, `tie_id`, `finished_at`, `minute`), не трогаются.
- Запуск: `php artisan ingest:dispatch sync_fixtures --competition=<slug|id> [--season=<slug>] [--stage=<id|название>] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]`. Фильтры соревнования/сезона те же, что у `sync_structure`; блокировка прогона — на (провайдер, соревнование, `sync_fixtures`).

**`sync_match_details` — составы, события и статистика матча** (эндпоинты SStats `/Ls/GameInfo` и `/Ls/GameLineups`, по два запроса на матч):

- Payload: `{"competition": {"id", "external_id"}, "season_external_id": "...", "match_external_ids": ["..."], "context": {...}}`. Список матчей выбирает Laravel (завершённые, свежие первыми), Go получает готовые внешние id; максимум 500 матчей на прогон. Сбой одного матча (сеть, пустой ответ, неизвестный код действия) уходит в `errors[]` и не останавливает прогон; `401/403` и исчерпанный лимит запросов — `failed` целиком (`retryable` у лимита).
- Сущности батчей (порядок по зависимостям): `country` (4) → `coach`, `player` (7) → `match_details` (8). `match_details` — одна самодостаточная сущность на матч (`external_id` = id матча): `match_external_id`, `teams[]` (сторона, команда, схема `formation`, тренер), `lineup[]`, `events[]`, `team_stats[]`, `player_stats[]`. Игроки и тренеры идут отдельными сущностями с `external_ids`; `has_profile = false` означает, что профиля SStats не было и уже известные поля в БД не затираются. Батчи ограничены и по числу сущностей (`COLLECTOR_BATCH_SIZE`), и по размеру (~900 КБ при лимите контракта 1 МБ).
- Составы: `lineup[]` — игрок, команда, `is_starter`, `position` (GK/DF/MF/FW), `shirt_number`, `line` (индекс линии построения из `formationRow`, 0 = вратарь; хранится числовой строкой), `minute_in`/`minute_out` (из замен). Координат x/y в API нет, только номер линии. Запас — `kind = 1`, тренеры (`kind = 2`) — в `coach`; отсутствующие (`kind = 3/4`) пока не загружаются.
- События (`match_events`, ключ синхронизации — `sort_order`): `1` жёлтая, `2` красная (жёлтая + красная одного игрока = `second_yellow`), `3` гол, `4` автогол, `6/7` замена (пара выход/выход → один `substitution` с игроком-«out» и связанным «in»), `8` голевая передача (приклеивается к голу как `related_player`), `10` гол с пенальти, `11` незабитый пенальти, `47` — «вне поля» (карточка тренера; сохраняется с `details.not_on_pitch`). `5` (назначен пенальти) пропускается, период 4 (серия пенальти) пропускается. Любой другой код — ошибка нормализации матча.
- Командная статистика (`match_team_stats`, ключ `team_id:period`): SStats отдаёт пары `{home, away}` по периодам `full_time | first_half | second_half`; считаются владение, удары, удары в створ, угловые, фолы, карточки, пасы (точность = точные/всего·100), `xg`, `xga` (= xG соперника); остальное — в `extra`.
- Игровая статистика (`match_player_stats`, ключ — игрок): минуты, голы, передачи, пенальти, удары, `xg`, `xa` (точность `decimal(5,2)`), карточки, `rating`, `is_motm` (`isBestRating`), `xGOT` и прочие показатели — в `extra`. Игрок страны не имеет — берётся страна его команды (`players.country_id` NOT NULL).
- Laravel: `MatchDetailsUpserter` (`PlayerUpserter`, `CoachUpserter`, репозитории `MatchTeam/MatchLineup/MatchEvent/MatchStats`). Матч, команды и сезон резолвятся по `external_ids`; сущность `match_details` атомарна — любая битая ссылка откатывает весь матч и пишет `ingestion_run_errors`. Дочерние секции синхронизируются целиком: непустая секция приводится к состоянию из ответа (лишние строки удаляются), пустая секция ничего не удаляет. Счётчики дочерних таблиц (`match_team`, `match_lineup`, `match_event`, `match_team_stat`, `match_player_stat`) идут только в `stats.by_entity`, в общие `created/updated/unchanged` входят `country`, `coach`, `player`, `match_details`. Сравнение JSON-полей (`details`, `extra`) не зависит от порядка ключей (jsonb в PostgreSQL его переставляет), поэтому повторная загрузка даёт `unchanged`.
- Запуск: `php artisan ingest:dispatch sync_match_details --competition=<slug|id> [--season=<slug>] [--stage=<id|название>] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--limit=N] [--missing]` или `--match=<внешний id>` (можно несколько) вместо выборки по сезону. По умолчанию `--limit=50`, максимум 500; `--missing` берёт только матчи, по которым деталей ещё нет. `ingestion_runs.kind` расширен до varchar(32).
- Проверено вживую на РПЛ 2026-2027: `--limit=1`, затем `--limit=3` (6 `match_teams`, 135 игроков в составах, 6 тренеров, 46 событий, 18 строк командной и 91 игровой статистики, 3 MOTM); повторный прогон тех же матчей — 170 `unchanged`, 0 ошибок.
- TODO: отсутствующие игроки (`kind = 3/4`) → `player_absences`; судья/стадион/зрители; события серии пенальти; VAR и «гол отменён» (сейчас неизвестные коды — ошибки); xG/xA точнее `decimal(5,2)` (xGOT и остальное — в `extra`); координаты x/y (API даёт только линию); сопоставление игроков-дублей между источниками.

**`sync_squads` — составы клубов** (эндпоинт SStats `/Ls/Players?teamId=<внешний id команды>`, один запрос на команду; пагинация до 1000 записей):

- Payload: `{"competition": {"id", "external_id"}, "season_external_id": "...", "team_external_ids": ["..."], "context": {...}}`. Список команд выбирает Laravel (`TeamRepository::idsInSeason`: команды этапов сезона и участники его матчей — у РПЛ строк `stage_teams` нет, поэтому берутся и из матчей), максимум 200 команд на прогон. Явный список — `--team=<slug|внешний id>` (повторяемая опция).
- Ответ `/Ls/Players` фильтруется по текущей команде профиля. `role`: `Goalkeeper/Defender/Midfielder/Forward` → `GK/DF/MF/FW`; `Coach` пропускается. `profileUpdatedAt` бывает `null` — тогда игрок идёт как `has_profile = false`, и `PlayerUpserter` не затирает уже известные поля (дата рождения, рост и т. п.); позицию без роли он тоже не обнуляет. Относительный `photoUrl` отбрасывается.
- Сущности (порядок по зависимостям): `country` (4) → `player` (9) → `squad` (10). `player` — те же канонические игроки, что в `sync_match_details` (повторный прогон даёт `unchanged`); `squad` — одна сущность на команду (`external_id` = id команды): `team_external_id`, `players[] = {player_external_id, position?}`. `squad` публикуется только при полностью полученном и непустом списке: Laravel закрывает заявки игроков, которых в списке нет, поэтому сбой запроса или пустой ответ уходят в `errors[]`, а состав команды остаётся как был. `401/403` и исчерпанный лимит запросов — `failed` целиком.
- Laravel: `SquadUpserter` + `SquadMembershipRepository` (`squad_memberships`). Для каждого игрока из списка открывается заявка (`left_on IS NULL`, позиция — из ответа, иначе из `players.position`); существующая обновляется только при смене позиции. Заявки игроков, которых в составе больше нет, закрываются (`left_on = сегодня`, считаются как `updated`), а заявки игрока в других клубах закрываются при переходе. Игроки, которых нет в БД, пропускаются и считаются `skipped` в `squad_players` (в `by_entity`, как и прочие дочерние счётчики; в общие `created/updated/unchanged` входят `country`, `player`, `squad`). Дата вступления (`joined_on`) неизвестна и остаётся `null`.
- Запуск: `php artisan ingest:dispatch sync_squads --competition=<slug|id> [--season=<slug>] [--team=<slug|внешний id>]...`; блокировка — на (провайдер, соревнование, `sync_squads`). Плановая задача `squads` (ниже) синхронизирует РПЛ раз в сутки; Кубок России (102 команды, ≈ 102 запроса при лимите 30/мин) запускается вручную: `php artisan ingest:dispatch sync_squads --competition=russian-cup`.
- Проверено вживую на РПЛ 2026-2027: один прогон — 16 команд, 508 заявок (`squad_players.created = 508`), 45 новых игроков, 0 ошибок. Состав (ГК/ЗЩ/ПЗ/НАП): Зенит 4/13/7/6 (30), Спартак 5/6/9/9 (29), ЦСКА 5/9/11/8 (33), Краснодар 5/9/10/5 (29).
- UI: блок «Игроки» страницы клуба строится из действующего состава (`squad_memberships` без `left_on`) плюс игроки с выступлениями в матчах среза; игроки без матчей показываются с нулевой статистикой, ушедшие из клуба — с пометкой «не в составе». Таблица сгруппирована ГК → ЗЩ → ПЗ → НАП (игроки без позиции — в конце), внутри группы сортировка по выбранной колонке (по умолчанию — минуты).

**Запуск локально:**

```bash
docker compose up -d --build        # postgres, redis, rabbitmq, app, collector, ingest-consume, scheduler
docker compose exec app php artisan ingest:dispatch sync_catalog --country=Russia --league="Premier League"
docker compose exec app php artisan ingest:dispatch sync_structure --competition=premier-league
docker compose exec app php artisan ingest:dispatch sync_fixtures --competition=premier-league --stage=Main   # весь сезон; окно — --from/--to
docker compose exec app php artisan ingest:dispatch sync_match_details --competition=premier-league --limit=3   # составы, события, статистика; --match=<id> — конкретный матч
docker compose exec app php artisan ingest:dispatch sync_squads --competition=premier-league   # составы клубов; --team=<slug|id> — отдельные клубы
```

`ingest:consume` и scheduler поднимаются самим compose — вручную consume запускать не нужно (ручной `docker compose exec app php artisan ingest:consume` допустим для отладки, но не нужен).

Нужные env: `SSTATS_API_KEY` (collector), `RABBITMQ_HOST/PORT/USER/PASSWORD/VHOST` (web), `COLLECTOR_RABBITMQ_URL` (collector), опционально `SSTATS_RATE_PER_MINUTE`, `COLLECTOR_PREFETCH`, `COLLECTOR_BATCH_SIZE`. Ответы `/Ls/Leagues`, `/Ls/SeasonStructure`, `/Ls/List`, `/Ls/GameInfo`, `/Ls/GameLineups` проверены вживую с реальным ключом на РПЛ 2026-2027 (240 матчей, стадия Main).

## Расписание и постоянные воркеры

После `docker compose up` данные обновляются сами: четыре долгоживущих процесса и плановые прогоны.

| Сервис compose | Команда | Роль |
|----------------|---------|------|
| `collector` | `/collector` (`restart: unless-stopped`) | читает `ingest.commands`, ходит в SStats, пишет в `ingest.results` |
| `ingest-consume` | `php artisan ingest:consume --max-time=3600` (`restart: unless-stopped`) | применяет результаты к БД; после часа работы штатно завершается и поднимается заново (чистая память и соединения) |
| `scheduler` | `php artisan schedule:work` (`restart: unless-stopped`) | вызывает плановые задачи ниже |
| `app` | `artisan serve` | healthcheck `/up`: воркеры стартуют только после него, т.е. когда `vendor` установлен и миграции применены (воркеры миграции не гоняют: `RUN_MIGRATIONS=0`) |

Без Compose (supervisor/systemd): два процесса `php artisan ingest:consume --max-time=3600` (перезапуск при выходе) и `php artisan schedule:work`; либо cron `* * * * * php artisan schedule:run` вместо `schedule:work`. Collector — отдельный бинарник/контейнер с перезапуском.

**Плановые задачи** (`App\Console\IngestSchedule`, регистрируется в `bootstrap/app.php`; интервалы и окна — `config/ingest.php`, env `INGEST_*`, см. `web/.env.example`). Задачи вызывают `ingest:run-scheduled {job}`: соревнования определяются **в момент запуска** — явный список `INGEST_COMPETITIONS` (slug'и), иначе все `competitions.status = live` с внешним id SStats; на каждое соревнование — обычный `ingest:dispatch ... --scheduled`.

| Задача (`job`) | Что делает | По умолчанию | Env |
|----------------|------------|--------------|-----|
| `fixtures` | `sync_fixtures` с окном [сегодня − 2 дня, сегодня + 2 дня] (даты по Москве), все стадии | каждые 10 мин (`*/10 * * * *`) | `INGEST_FIXTURES_EVERY_MINUTES`, `_DAYS_BACK`, `_DAYS_FORWARD` |
| `details_missing` | `sync_match_details --missing --limit=10`: завершённые матчи без деталей, свежие первыми | каждые 15 мин | `INGEST_DETAILS_MISSING_EVERY_MINUTES`, `_LIMIT` |
| `details_recent` | `sync_match_details` по матчам, завершённым за последние сутки (окно [вчера, сегодня]), `--limit=10`: поздние поправки статистики | раз в час | `INGEST_DETAILS_RECENT_EVERY_MINUTES`, `_DAYS_BACK`, `_LIMIT` |
| `fixtures_full` | `sync_fixtures` на весь текущий сезон без окна: переносы матчей, новые туры | ежедневно 03:30 МСК | `INGEST_FIXTURES_FULL_DAILY_AT` |
| `catalog` | `sync_catalog --country=Russia --league="Premier League"` | ежедневно 04:00 МСК | `INGEST_CATALOG_DAILY_AT`, `_COUNTRY`, `_LEAGUE` |
| `structure` | `sync_structure` (стадии и команды текущего сезона) | ежедневно 04:10 МСК | `INGEST_STRUCTURE_DAILY_AT` |
| `squads` | `sync_squads`: составы клубов текущего сезона соревнований из `INGEST_SQUADS_COMPETITIONS` | ежедневно 04:20 МСК | `INGEST_SQUADS_DAILY_AT`, `INGEST_SQUADS_COMPETITIONS` (по умолчанию `premier-league`) |
| `ingest:reap-stuck` | уборщик зависших прогонов (ниже) | каждые 10 мин | `INGEST_REAP_EVERY_MINUTES`, `INGEST_STUCK_AFTER_MINUTES` |

- Интервал `0` выключает задачу, пустое время (`*_DAILY_AT=`) — тоже; `INGEST_SCHEDULE_ENABLED=false` выключает всё расписание. Интервалы ≥ 60 мин округляются до целых часов. Время ежедневных задач (и «сегодня» в окнах дат) — в часовом поясе приложения: `APP_TIMEZONE`, по умолчанию `Europe/Moscow` (задачи вешают `->timezone(config('app.timezone'))`; в compose переменная пробрасывается всем сервисам, включая `scheduler`). Сессия PostgreSQL тоже в этом поясе (`database.connections.pgsql.timezone`, `DB_TIMEZONE`): `timestamptz` хранит моменты как есть, `kickoff_at` из collector (UTC) приводится к поясу приложения при записи.
- Rate-friendly: `details_*` берут не более 10 матчей (≈ 20 запросов к SStats) за прогон, `fixtures` — один запрос на окно. Один `running` на (провайдер, соревнование, `kind`): если прошлый прогон ещё идёт, плановый запуск пропускается (`ingest:dispatch --scheduled` пишет «Пропущено» и завершается успешно; так же — «нечего грузить»). Плановые прогоны помечены `stats.context.trigger = schedule`.
- Соревнование для регулярной загрузки включается так: `sync_catalog` → `sync_structure` (разово руками, как в «Запуске локально») → `competitions.status = 'live'` (или slug в `INGEST_COMPETITIONS`). `sync_catalog` создаёт соревнование со статусом `hidden`, сам он `live` не ставит.
- Проверка: `docker compose exec app php artisan schedule:list`; ручной запуск одной задачи — `php artisan ingest:run-scheduled fixtures`.

**`ingest:reap-stuck [--minutes=N] [--dry-run]`.** Находит `ingestion_runs` со `status = running` и `started_at` старше порога (`--minutes`, по умолчанию `INGEST_STUCK_AFTER_MINUTES`, 60) и закрывает их `failed` с `error = 'timeout'`, `finished_at = now()` (под lock строки: прогон, который consumer закрыл за это время, не трогается). Поздние сообщения по закрытому прогону consumer отбрасывает. `StartIngestionRun` и так закрывает зависший прогон того же ключа перед стартом нового, но уборщик убирает остальные.

**Порядок батчей при нескольких consumer'ах.** Если `ingest:consume` запущен в нескольких экземплярах, батчи одного прогона применялись параллельно и в произвольном порядке (на практике: `batches_seen = [1, 2, 4, 3, …]`) — детали матча из батча 4 обгоняли игроков и тренеров из батча 3, что давало ошибки «не найдена сущность player». Внутри батча и в collector порядок зависимостей уже соблюдался (справочники → игроки/тренеры → `match_details`; Go сортирует сущности перед нарезкой на батчи, Laravel — перед применением). Теперь батч N ждёт, пока применён батч N−1 (проверка `stats.context.batches_seen`, опрос раз в 250 мс), максимум `INGEST_BATCH_ORDER_WAIT_SECONDS` (30) — после таймаута применяется как есть (например, если N−1 ушёл в DLQ). Одиночный consumer не ждёт никогда.

## Роль Laravel (код — в `web/app`)

| Слой | Что |
|------|-----|
| `Console/Commands` | `ingest:dispatch {type} {--competition=}` — создаёт прогон и публикует команду; `ingest:consume` — воркер очереди результатов; `ingest:reap-stuck` — уборщик; `ingest:run-scheduled {job}` — плановые прогоны для scheduler; `App\Console\IngestSchedule` — расписание (`bootstrap/app.php`) |
| `Services/Ingestion` | `StartIngestionRun` (lock → `ingestion_runs` → publish), `ApplyIngestionResult` (батч → репозитории), `FinishIngestionRun` (статус, `finished_at`) |
| `Repositories` | upsert сущностей, `ExternalIdRepository`, `IngestionRunRepository`; вся запись в БД только здесь |
| `Data/` | readonly DTO канона на стороне PHP (зеркало `contract/` в Go; соответствие проверяется контрактными тестами на общих JSON-фикстурах) |
| `Domains/Naming` | нормализация названий для `team_aliases` |
| Админка (позже) | `POST /admin/ingestion-runs` → тот же `StartIngestionRun` |

Брокер для PHP — отдельный драйвер/клиент AMQP (выбор пакета — при реализации); очередь Laravel `redis` для обычных job'ов остаётся как есть и с RabbitMQ-обменом ingest не смешивается.

## Жизненный цикл прогона (`ingestion_runs`)

1. **Старт (Laravel):** строка `ingestion_runs` со `status = running`, `started_at`, `source_provider_id`, `competition_id` (если прогон по лиге), `kind` = `job_type`. Сразу после этого — публикация команды в RabbitMQ (если публикация упала — прогон сразу `failed`).
2. **Сборка (Go):** сбой одной сущности изолирован и **не прерывает** сборку: уходит в `errors[]` батча. Сбой целиком — сообщение `failed`.
3. **Сохранение (Laravel):** по каждой сущности — своя транзакция; успех — `created` / `updated` / `unchanged`; пропуск по правилу — `skipped`; сбой — строка в `ingestion_run_errors` и счётчик `failed`.
4. **Финиш (Laravel):** после `done` (или `failed`) выставляет `finished_at` и итоговый статус:

| `status` | Когда |
|----------|-------|
| `running` | идёт (команда в очереди, Go собирает или Laravel ещё применяет результат) |
| `ok` | завершён, `failed = 0` |
| `partial` | завершён, есть и успешные сущности, и ошибки (`failed > 0`) |
| `failed` | прогон не состоялся целиком: источник недоступен, ошибка авторизации, сообщение `failed` от Go, ошибка публикации команды, либо ни одной успешной при `failed > 0`; причина — `ingestion_runs.error` |

Зависшие прогоны (`running` дольше порога, по умолчанию 1 час — команда не взята Go, результат не дошёл или `done` не пришёл) помечает `failed` artisan-«уборщик» `ingest:reap-stuck` (по расписанию раз в 10 минут) с `error = 'timeout'`. Поздние сообщения по уже закрытому `run_id` консьюмер отбрасывает (ack + лог).

### Счётчики — `ingestion_runs.stats` (jsonb, фиксированный контракт)

Счётчики считает **Laravel** (он один знает created/updated/unchanged).

```json
{
  "created": 12,
  "updated": 40,
  "unchanged": 300,
  "skipped": 2,
  "failed": 3,
  "unmatched": 1,
  "by_entity": {
    "team":  { "created": 1, "updated": 0, "unchanged": 15, "skipped": 0, "failed": 0 },
    "match": { "created": 11, "updated": 40, "unchanged": 285, "skipped": 2, "failed": 3 }
  },
  "context": { "trigger": "schedule", "requested_by": null }
}
```

- Верхнеуровневые числа — суммы по `by_entity`; `failed` равен числу строк `ingestion_run_errors` (если не сработал лимит, см. ниже). Ключи — значения `ExternalEntityType`.
- `unmatched` — сколько сущностей ушло в очередь ревью (не ошибка, но видно в админке).
- `context.trigger`: `schedule` | `manual` | `cli`; `requested_by` — `users.id` администратора, если запуск ручной.
- Колонки `created_count` и т.п. не заводим: список прогонов читает `stats` как есть, сортировка/фильтры идут по `status`, `started_at`, провайдеру, лиге.

### Ошибки по сущностям — `ingestion_run_errors`

Одна строка = одна сбойная сущность в прогоне. Пишет **Laravel consumer**: ошибки Go (`fetch`/`parse`/`normalize`) — из `errors[]` результата, свои (`match`/`upsert`) — по месту.

| Поле | Смысл |
|------|-------|
| `ingestion_run_id` | прогон (`ON DELETE CASCADE`) |
| `entity_type` | `ExternalEntityType` (`team`, `match`, …), nullable — если сбой не привязан к сущности |
| `external_id` | id в источнике (по нему же ищем в админке и в `external_ids`) |
| `entity_id` | наш id, если сущность уже создана/сопоставлена (иначе NULL) |
| `phase` | где упало: `fetch` \| `parse` \| `normalize` \| `match` \| `upsert` (`IngestionPhase`) |
| `message` | короткое человекочитаемое описание (без секретов и сырых HTML) |
| `context` | jsonb: фрагмент входных данных, название, нарушенное ограничение — для отладки |
| `created_at` | время фиксации |

Ограничения: на прогон пишем не более **1000** строк ошибок (дальше — только счётчик `stats.failed` и пометка в `context`); ошибки старше **90 дней** чистятся командой, сами `ingestion_runs` хранятся дольше. `context` не содержит секретов и персональных данных.

## Идемпотентность

- **Ключ маппинга:** `external_ids` UNIQUE (`source_provider_id`, `entity_type`, `external_id`). Laravel ищет сущность по нему; нашлась — `UPDATE`, нет — резолв и `INSERT` (+ новая строка `external_ids`). Повтор того же прогона или повторная доставка сообщения всегда приходит к той же строке.
- **Естественные ключи** как страховка от дублей (матч: пара команд + `kickoff_at` в окне; игрок: имя + дата рождения + страна) используются только на этапе резолва нового `external_id`, не в горячем пути.
- **Только реальные изменения:** Laravel сравнивает нормализованные поля с текущими; если нечего менять — `unchanged`, `updated_at` сущности не трогаем, но `external_ids.last_seen_at` и `last_ingestion_run_id` обновляем (сущность «жива» в источнике).
- **Атомарность:** одна сущность (с дочерними — например матч с событиями) — одна транзакция; батч и прогон целиком — **не** в одной транзакции.
- **Порядок зависимостей:** справочники → команды/игроки → матчи → события/составы/статистика. Go кладёт в батчи сущности в таком порядке; Laravel при ссылке на ещё не загруженную сущность фиксирует ошибку `match` по этой строке, а не падение прогона; следующий прогон подхватит.
- **Конкурентность:** на пару (`source_provider_id`, `competition_id`, `kind`) одновременно один `running`. Lock берёт Laravel до публикации команды (`Cache::lock` или advisory lock); второй запуск пропускается и логируется.
- Поля `name_i18n` Laravel **не перезаписывает** из источника (кроме правила № 29 для источника без EN-имён) — см. [i18n.md](../database/i18n.md).

## Смена источника без смены внутренних id

Внутренние `id` наши и никогда не равны внешним; доменные таблицы про источники ничего не знают.

1. Добавить строку `source_providers` (`code`, `kind`) и `competition_sources` (`priority`, `is_enabled`, `settings`) и реализовать сборщик/адаптер в Go под новый `provider`.
2. Новый провайдер сначала работает в «теневом» режиме (`is_enabled = true`, но его `priority` ниже текущего): Laravel сопоставляет его записи с нашими сущностями через `team_aliases` и естественные ключи и создаёт **вторые** строки `external_ids` (`matched_by = auto | alias | manual`) с тем же `entity_id`. Несопоставленное уходит в очередь ревью ([№ 14](../database/open-questions.md)), а не создаёт дубли.
3. После проверки доли `unmatched` и отчётов прогонов — поменять `priority` (новый — меньше число): он становится основным источником записи фактов, старый — запасным или выключается (`is_enabled = false`). Строки старого провайдера в `external_ids` не удаляем (история и откат).
4. URL (`/matches/{id}`, `/clubs/{id}`), picks, прогнозы и рейтинг остаются нетронутыми — они ссылаются на наши `id`.

Правило конфликта источников — по умолчанию **один основной источник записи на лигу** (минимальный `priority` среди `is_enabled`); остальные заполняют только `external_ids` и пустые поля ([№ 37](../database/open-questions.md)). Поэтапного происхождения каждого поля («provenance») не ведём.

## Что показывает админка (будущая)

Админки пока нет; данные для неё уже есть в схеме. Экраны — по REST-ресурсам (`Admin\IngestionRunController`, `Admin\SourceProviderController`, …), чтение через `IngestionRunRepository`; запуск прогона из админки — тот же `StartIngestionRun`, что и у artisan; доступ — `users.role = admin`.

| Экран | Содержимое | Откуда |
|-------|------------|--------|
| **Прогоны** (список) | провайдер, лига, `kind`, статус, начало/конец/длительность, `created / updated / unchanged / failed`, `unmatched`; фильтры по провайдеру, лиге, статусу, периоду | `ingestion_runs` (`stats`) |
| **Прогон** (карточка) | счётчики по типам сущностей, итоговая `error`, таблица ошибок: тип, `external_id`, ссылка на нашу сущность (если есть `entity_id`), фаза, сообщение, контекст | `ingestion_runs.stats` + `ingestion_run_errors` |
| **Источники** | провайдеры (`kind`, `is_active`), лиги и приоритеты (`competition_sources`), время последнего прогона и последнего **успешного** (`max(finished_at)` со статусом `ok`/`partial`), число ошибок в последнем прогоне | `source_providers`, `competition_sources`, `ingestion_runs` |
| **Сущность** (карточка команды/матча/игрока…, блок «Источники») | все её строки `external_ids`: провайдер, `external_id`, `external_url`, `matched_by`, `last_seen_at`, ссылка на **последний прогон** (`last_ingestion_run_id`) и его статус; последние ошибки по этой сущности (`ingestion_run_errors` по `entity_type` + `external_id` через прогон нужного провайдера); если строк `external_ids` нет — «источник: manual» | `external_ids`, `ingestion_runs`, `ingestion_run_errors` |
| **Несматченные** (ревью) | сущности, ожидающие ручного сопоставления/слияния; шаг «задать EN-имя» ([№ 29](../database/open-questions.md)) | `external_ids.meta` (флаг ревью), `team_aliases` |

Где что читать для «успеха» сущности: `external_ids.last_seen_at` — когда источник последний раз её отдал; `last_ingestion_run_id` — каким прогоном; `updated_at` самой сущности — когда она реально изменилась. «Есть ли проблема сейчас» — последняя ошибка по её `external_id` новее `last_seen_at`.

## Что сознательно НЕ делаем

- Запись из Go в PostgreSQL (прямая или через staging-таблицы) — только Laravel.
- Код сборщиков SStats и consumer'а Laravel — этот документ фиксирует только схему и контракт; реализация — отдельными задачами.
- Хранение сырых payload'ов (`raw_payloads`) в БД и в очереди — позже, вне основной БД или с retention ([schema.md](../database/schema.md#7-источники-данных-и-маппинг)).
- Таблицу «статус по каждой сущности в каждом прогоне» (успехи пишем только счётчиком; построчно — лишь ошибки): иначе объём растёт как число сущностей × число прогонов.
- Полевую provenance, UI админки.

## Открытые вопросы схемы (пересмотр при реализации)

- Набор `job_type` сверх `schedule` / `match_stats` / `lineups` / `live` (в частности отдельный `results` для обновления счетов завершённых матчей) — добавляется в enum/CHECK `ingestion_runs.kind` миграцией Laravel.
- Клиент AMQP для PHP (пакет/драйвер) и способ дедупликации `seq`.
- Политика ретраев и backoff при лимитах SStats; размеры батчей и prefetch — подбираются на реальных прогонах.
- Live-режим: частота команд и нагрузка на очереди — отдельное решение, когда дойдём до live ([schema.md](../database/schema.md)).
