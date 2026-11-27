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

**Эндпоинты SStats по job'ам.** Основной API — `/Leagues`, `/Games/list`, `/Games/{id}`, `/Players/list`. Эндпоинты `/Ls/*` (данные Flashscore) используются только там, где в основном API нет нужных UI/БД полей, и только за этими полями:

| Job | Основной эндпоинт | Запасной `/Ls/*` и зачем |
|---|---|---|
| `sync_catalog` | `GET /Leagues` (лиги со списком сезонов: uid, год, даты, flashScoreId) | — |
| `sync_structure` | — | `/Ls/Seasons` (id Ls-сезона по label, если он ещё не известен), `/Ls/SeasonStructure`, `/Ls/Standings`: стадий, групп, раундов и состава групп в основном API нет (`/Seasons/standings` отдаёт только таблицу без id стадий и групп) |
| `sync_fixtures` | `GET /Games/list?SeasonUid=` (или `LeagueId`+`Year`) — матчи, счёт, статус, команды, коэффициенты | `/Ls/List` (Ls-сезон, то же окно) — только если без него не обойтись: этап и id тура многоэтапного сезона (`ls_stages`) или flash-id команды, которой в `/Games/list` отдан пустой `flashId` (иначе команда задвоилась бы); раз запрос сделан, из него же берётся счёт серии пенальти. Одноэтапный сезон с известными командами — только `/Games/list` |
| `sync_match_details` | `GET /Games/{id}` — составы, события, статистика, состояние, голы доп. времени | — |
| `sync_squads` | `GET /Players/list?teamId=` — игроки и их травмы/дисквалификации | `/Ls/Players` — только позиция игрока (в `/Players/list` её нет; номера нет ни там, ни там) |

Счёта серии пенальти нет ни в `/Games/list`, ни в `/Games/{id}`: `home_pens`/`away_pens` пишет только `sync_fixtures` из уже запрошенного `/Ls/List` (`homeResultAP`/`awayResultAP`); отдельный `/Ls/GameInfo` или лишний `/Ls/List` ради пенальти не делается, без данных Ls значения не затираются.

**Отличия от наброска выше (источник правды — код):**

- `job_type` реализованных срезов: `sync_catalog` (страны, лиги/соревнования, их сезоны), `sync_structure` (стадии и команды сезона) и `sync_fixtures` (матчи сезона/стадии). Значения живут в `ingestion_runs.kind` (varchar, без CHECK) и в `App\Enums\IngestionKind`.
- `payload` `sync_catalog`: `{"filters": {"country": "Russia", "league_name": "Premier League", "league_ids": []}, "context": {...}}` (`league_ids` — числовые id лиг SStats или flash-id, `country` — название или код страны); `sync_structure`: `{"competition": {"id": 1, "external_id": "<flash-id лиги>"}, "season_external_id": "<id Ls-сезона>"?, "season_label": "2026-2027"?, "context": {...}}` — если Ls-id сезона ещё не известен (сезон пришёл из `/Leagues`), Laravel передаёт `season_label`, collector находит сезон через `/Ls/Seasons` и первой сущностью отдаёт `season` с этим Ls-id. `context` всегда JSON-объект.
- `sync_catalog` (`/Leagues`, один запрос): `competition` с `external_id` = числовой id лиги и `data = {api_id, flash_id?, country_code, country_name, name, type, scope, refs}` (`country.code = "WW"` → `scope = continental`); `season` с `external_id` = uid сезона и `data = {api_id, competition_api_id, competition_external_id?, label, is_current, starts_on, ends_on}`. Label — `Y-(Y+1)`, если хоть один сезон лиги заканчивается после года начала, иначе `Y`; текущий — последний начавшийся (иначе ближайший будущий). Несколько лиг с одним flash-id (дубли `/Leagues`) — берётся та, у которой больше сезонов с `flashScoreId`, остальные уходят в `errors[]`. Laravel (`CompetitionUpserter`, `SeasonUpserter`): соревнование ищется по числовому id, затем по flash-id; название и страна берутся из `/Leagues` и у существующего (турниры УЕФА переходят со страны `EUR` на регион `WW` «World»), логотип остаётся прежним, если источник его не дал (в `/Leagues` логотипов нет), тип, охват и slug существующего не меняются; страна — по коду (неизвестный код создаёт страну, `WW` — регион). Сезон — по uid, затем по label в соревновании; даты пишутся, `is_current` — по данным (у сезонов из `sync_structure` признака нет, и он не меняется).
- Результат: сообщения `batch` содержат `entities[] = {entity_type, external_id, data}` для типов `country | competition | season | stage | team`, отсортированных по зависимостям (справочники → сезоны → стадии/команды); ошибки нормализации — `errors[]` в последнем батче; завершение — `done` с `batches_total` либо `failed` с `retryable`.
- `sync_structure` — **fallback на Ls**: основного аналога этапов и групп нет, поэтому job остаётся на `/Ls/SeasonStructure` и `/Ls/Standings`. Он дополнительно читает из `/Ls/SeasonStructure` `standingTables`, `rounds`, `stageTeams`, а для стадий с ≥2 таблицами — `/Ls/Standings` (состав групп). Сущности: `stage_group` (по `stage`+`name`), `round` (external_id = id раунда SStats; номер тура или `sortOrder` раунда плей-офф, `code` из названия: `1/16-finals` → `R32`, `Final` → `F`), `stage_team` (по `stage`+`team`, `group_name`). Стадия `table` с ≥2 таблицами получает тип `group`. Порядок применения: … → стадии → группы/раунды → `stage_team` → матчи. Явных пар (`ties`) SStats не отдаёт: `tie_id`/`leg` не заполняются, клуб группирует матчи по раунду и сопернику. `sync_fixtures` передаёт `round_external_id`; `MatchUpserter` ставит `stage_group_id` по общей группе команд в `stage_teams`, а `StageTeamUpserter` дозаполняет группу у уже загруженных матчей.
- Очереди ошибок: отдельные `ingest.commands.dlq` / `ingest.results.dlq` (через `x-dead-letter-*`), а не единая `ingest.dlq`. Невалидное сообщение отвергается без requeue и попадает в DLQ; при сбое публикации результата команда один раз возвращается в очередь, затем — в DLQ.
- Go-пакеты: `cmd/collector`, `internal/{config,dto,amqp,sstats,jobs}` (вместо `mq/contract/sources/normalize`; нормализация живёт в `internal/jobs`).
- Go собирается, тестируется и запускается только в Docker (`docker/collector/Dockerfile`, multi-stage, Go 1.27): `docker compose --profile tools build collector-test` (тесты выполняются на сборке стадии `test`), `docker compose build collector`.
- Laravel: `php artisan ingest:dispatch {kind}` создаёт `ingestion_runs` и публикует команду; `php artisan ingest:consume` читает `ingest.results` и применяет батчи через репозитории (`App\Services\Ingestion\ApplyIngestionResult`). Повторная доставка батча безопасна (`stats.context.batches_seen`), на каждый прогон пишется не более 1000 строк `ingestion_run_errors`.

**`sync_fixtures` — расписание, результаты и коэффициенты матчей** (эндпоинт SStats `/Games/list`, пагинация по `Offset`, до 1000 матчей на запрос, не более 50 страниц):

- Payload: `{"competition": {"id", "external_id"}, "season_api_id": "<uid>"?, "league_api_id": "236"?, "season_year": 2026?, "season_external_id": "<id Ls-сезона>"?, "stage_external_id": "..."?, "ls_stages": true?, "window": {"from": "YYYY-MM-DD", "to": "YYYY-MM-DD"}?, "context": {...}}`. Сезон задаётся uid (`SeasonUid`), а пока `sync_catalog` его не привязал — лигой и годом (`LeagueId` + `Year` из label). Окно включительное с обеих сторон (UTC). `ls_stages` Laravel ставит, когда у сезона больше одной стадии и `--stage` не задан.
- Этап и тур: `/Games/list` их не отдаёт. Одноэтапный сезон — Laravel берёт единственную стадию сезона из БД. Многоэтапный (`ls_stages`) — collector дополнительно читает `/Ls/List` того же окна и по flash-id матча подставляет `stage_external_id` и `round_external_id`; сбой `/Ls/List` (не 401/403/429) не валит прогон — ошибка `match` на фазе `fetch`, матчи уходят без этапа.
- Команды без `flashId`: у части команд (Leningradets, кубковые любители, иногда Sochi, Shakhtar) `/Games/list` отдаёт `flashId = null` и пустые mappings. Если payload знает Ls-сезон (`season_external_id`), collector читает `/Ls/List` и берёт flash-id команды из записи того же матча (`homeTeamId`/`awayTeamId` по flash-id матча); по нему Laravel находит команду Ls-структуры и привязывает к ней числовой id. Матч, у команды которого flash-id так и не нашёлся (сбой `/Ls/List`, у матча нет `flashId` или записи в Ls), пропускается с ошибкой `match` — новая команда по числовому id в сезоне с Ls-структурой была бы дублем. Без Ls-сезона команда создаётся по числовому id. Без этапа Laravel берёт стадию уже известного матча, иначе — ошибка сущности (`этап матча не известен`). Номер тура — из `roundName` («Round N», «Regular Season - N», «League Stage - N»).
- Результат: сначала `team` (по одной на команду: `external_id` = числовой id команды, `data = {api_id, flash_id?, name, country_code, country_name, logo_url?, refs}`), затем `match`: `external_id` = числовой id матча, `data = {api_id, flash_id?, season_api_id, season_external_id?, stage_external_id?, round_external_id?, round_name, round_number, home_team_api_id, away_team_api_id, home_team_external_id?, away_team_external_id?, kickoff_at (UTC), status, period, home_goals, away_goals, home_goals_ht, away_goals_ht, home_pens?, away_pens?, refs, odds[]}`. Статус — по коду `/Games` (`1,2` scheduled; `3,4,5,6,7,11,12,19` live с периодом; `8,9,10,17,18` finished; `13` abandoned; `14` postponed; `15` cancelled), без кода — по названию; неизвестный — ошибка `normalize`. Счёт (`homeResult` — с доп. временем, без серии пенальти) — только у live/finished. Ключей `*_goals_et` нет (ET пишет `sync_match_details`); `*_pens` — только у завершённых матчей, найденных в запрошенном `/Ls/List`; отсутствующие ключи Laravel не трогает.
- Коэффициенты: `odds[] = {market_id, market_name?, outcome, value}` из `odds[].odds[]` (`marketId` SStats: 1 — 1X2, 2, 5 — тотал, 12, 16, 17; значения ≤ 1 отбрасываются). Laravel хранит последнюю линию в `match_odds` (уникально по матч + провайдер `sstats_api` + `source_market_id` + `outcome`); непустой список синхронизируется целиком, пустой ничего не удаляет. С `market_types` рынки пока не сопоставляются.
- Laravel: `TeamUpserter` — команда по числовому id, затем по flash-id (команды Ls-структуры); существующая не переименовывается, только получает id; без совпадения по id создаётся новая (по имени команды не сопоставляются). `MatchUpserter` — сезон и команды по числовым id (иначе по Ls-id), матч по числовому id, затем по flash-id (матчи, загруженные из Ls), затем та же пара в сезоне в ±1 день без числового id; тур — по `round_external_id`, иначе по номеру. Поля, которых источник не отдаёт (площадка, судья, погода, `tie_id`, `finished_at`, `minute`, ET, пенальти без `/Ls/List`), не трогаются.
- Запуск: `php artisan ingest:dispatch sync_fixtures --competition=<slug|id> [--season=<slug>] [--stage=<id|название>] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]`; блокировка прогона — на (провайдер, соревнование, `sync_fixtures`).

**Идентификаторы SStats: `sstats_api`, `sstats`, `flashscore`, `pari`:**

SStats отдаёт у матча, команды и лиги три идентификатора: собственный числовой `id` (`1577610`; у сезона — uid), `flashId`/`mappings.flashscore` (`IPBoDE9D`) и `mappings.pari` (`70159794`). Модель хранения — одна строка `external_ids` на пару (провайдер, сущность):

| `source_providers.code` | Что лежит в `external_ids.external_id` | Роль |
|---|---|---|
| `sstats_api` | числовой `id` (`/Leagues`, `/Games`, `/Players`), uid сезона | главный ключ сущностей из основного API |
| `sstats` | flash-id (ключ `/Ls/*`: лиги, сезоны, стадии, раунды, команды и игроки Ls) | ключ Ls-fallback'ов и сущностей, загруженных из Ls |
| `flashscore` | `mappings.flashscore` (если нет — `flashId`) | справочный |
| `pari` | первый из `mappings.pari` | справочный |

- Сущности основного API находятся по `sstats_api`, затем по flash-id (`sstats`) — так связываются с данными, загруженными раньше из Ls. Flash-id и mappings привязываются дополнительно (`SstatsApiIds::linkRefs`): у сущности, у которой уже есть другой flash-id, он не заменяется; id, занятый другой сущностью, — не ошибка сущности, а `external_ref.skipped`. Один `external_id` в паре (провайдер, тип) принадлежит ровно одной сущности (UNIQUE).
- `ingestion_runs.source_provider_id` остаётся `sstats`: прогоны и блокировки не делятся по эндпоинтам.
- Сущность `external_refs` (`ExternalRefsUpserter`) и запрос `/Games/list?FlashId=` больше не используются collector'ом; upserter оставлен для совместимости.

**`sync_match_details` — составы, события, статистика и состояние матча** (один эндпоинт SStats `GET /Games/{id}`, один запрос на матч; `/Ls/GameInfo` и `/Ls/GameLineups` не используются):

- Payload: `{"competition": {"id", "external_id"}, "season_external_id": "...", "match_external_ids": ["..."], "match_api_ids": {"<flash-id>": "<числовой id>"}, "context": {...}}`. Ключ матча — flash-id, а у матча без него (пришёл только из `/Games/list`) — числовой id; Laravel находит матч по `sstats`, затем по `sstats_api`. Список матчей выбирает Laravel (завершённые, свежие первыми; для `--live` — идущие), Go получает готовые внешние id; максимум 500 матчей на прогон. `match_api_ids` Laravel берёт из справочных id провайдера `sstats_api` (см. «Идентификаторы SStats»): `/Games/{id}` принимает и числовой id, и flash-id, но числовой надёжнее; матч без числового id запрашивается по flash-id. Сбой одного матча (сеть, 400/404, неизвестный код события) уходит в `errors[]` и не останавливает прогон; `401/403` и исчерпанный лимит запросов — `failed` целиком (`retryable` у лимита).
- Пустой ответ по идущему матчу (нет событий, статистики и составов — у только что начавшегося матча так и бывает) — не ошибка: нормализуется только `state` (статус, минута, счёт), остальные секции пустые и ничего не удаляют.
- Сущности батчей (порядок по зависимостям): `match_details` (8) — одна самодостаточная сущность на матч (`external_id` = flash-id матча). Люди отдельными сущностями больше не идут: `/Games/{id}` называет игроков числовыми id SStats и именами, а не flash-id, которыми заведены `players` в БД. Поэтому `match_details.people_source = "sstats_api"`, а строки `lineup[]`, `events[]`, `player_stats[]` несут `player_external_id` (числовой id) и `player_name`; в строках `teams[]`, `lineup[]`, `events[]`, `team_stats[]`, `player_stats[]` команда задаётся `side` (`home|away`), потому что `flashId` команды в `/Games` бывает `null`.
- `state`: `{status, period, minute, home_goals, away_goals, home_goals_ht, away_goals_ht, home_goals_et?, away_goals_et?}` из `game`; голы доп. времени — `homeResult − homeFTResult` у матчей с доп. временем (коды 6, 7, 9, 10, 11 или название с «Extra Time»/«Penalt»), Laravel пишет их у завершённого матча (`statusName`, `elapsed`, `homeResult/awayResult`, `homeHT/awayHT`). `Not Started` → scheduled; `First Half` / `Halftime` / `Second Half` → live с периодом `first_half` / `half_time` / `second_half`; `Extra Time*` → `extra_time`, `Penalt*` → `penalties`; `Finished*` и `Technical Loss` → finished; `Postponed` / `Cancelled` / `Abandoned`. Неизвестное название статуса не меняет статус. Минута задаётся только у live. Статус Ls `38` (HalfTime) в `sync_fixtures` теперь тоже live с периодом `half_time`.
- Laravel применяет `state` к самому матчу (`matches`, счётчик `match_state`): завершённый матч не «оживает» обратно; счёт переписывается у идущего матча и в момент перехода в finished, у уже завершённого остаётся за `sync_fixtures`; минута и период у не-live обнуляются.
- Игроки (вариант 1, без новых сущностей `player`): `ApiPlayerResolver` ищет игрока по известному числовому id (`external_ids`, провайдер `sstats_api`), иначе по имени среди игроков команды (`squad_memberships` ∪ `match_lineups`): `PersonName::findUnique` сравнивает слова без диакритики и регистра, понимает инициалы («A. Mostovoy» = «Andrey Mostovoy»). Нашёлся ровно один — игрок привязывается (`sstats_api`) и дальше находится по id; неоднозначное или неизвестное имя — не опознан. Не опознан игрок события — `player_id`/`related_player_id` = null, имя сохраняется в `details.person_name` / `details.related_person_name` (гол без игрока допустим); не опознан участник состава или игровой статистики — строка пропускается (`skipped`), и секция в этом случае только дополняется, лишние строки не удаляются. Тренеры из `/Games` не загружаются.
- События (`match_events`, ключ синхронизации — `sort_order`; порядок по минуте, добавленному времени и id события): гол, гол с пенальти, автогол, незабитый пенальти (по `name`), жёлтая, вторая жёлтая, красная, замена (`player` — вышедший, `related` — вышедший на поле, по текущей логике `assistPlayer`). Связанный игрок только у гола/пенальти (автор передачи) и замены. VAR (тип 4) пропускается, неизвестный тип или команда — ошибка нормализации матча. Накопительный счёт события считается Go; если он не сходится с итоговым, у всех событий матча счёт не записывается. `details = {source_event_id, name}`. Автогол приписан команде, получившей гол; игрок ищется среди соперников.
- Составы: `lineup[]` — игрок, сторона, `is_starter`, `position` (G/D/M/F → GK/DF/MF/FW), `shirt_number`, `line` (строка `grid` − 1), капитан, `minute_in`/`minute_out` (из замен). Формации — в `teams[]`.
- Командная статистика (`match_team_stats`, ключ `team_id:period`): плоский объект `statistics` с полями `...Home`/`...Away` (период `full_time`); `xga` = xG соперника, точность паса = точные/всего·100, остальное — в `extra`.
- Игровая статистика (`match_player_stats`, ключ — игрок): из `playerStats`; минуты, голы, передачи, удары, `xg`, `xa`, карточки, `rating`, остальное — в `extra`; `is_motm` — единственный игрок состава с наивысшим рейтингом.
- Laravel: `MatchDetailsUpserter` (+ `MatchReferences`, `ApiPlayerResolver`, репозитории `MatchTeam/MatchLineup/MatchEvent/MatchStats`, `MatchRepository::applyState`). Сущность `match_details` атомарна — любая битая ссылка откатывает весь матч и пишет `ingestion_run_errors`. Дочерние секции синхронизируются целиком: непустая секция приводится к состоянию из ответа (лишние строки удаляются, кроме случая с пропущенными игроками), пустая ничего не удаляет. Счётчики дочерних таблиц (`match_team`, `match_lineup`, `match_event`, `match_team_stat`, `match_player_stat`, `match_state`) идут в `stats.by_entity`. Сравнение JSON-полей не зависит от порядка ключей, поэтому повторная загрузка даёт `unchanged`. Обратная совместимость: payload без `people_source` обрабатывается как раньше (игроки по внешним id провайдера `sstats`).
- Запуск: `php artisan ingest:dispatch sync_match_details --competition=<slug|id> [--season=<slug>] [--stage=<id|название>] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--limit=N] [--missing] [--live]` или `--match=<внешний id>` (можно несколько) вместо выборки по сезону. По умолчанию `--limit=50`, максимум 500; `--missing` берёт только матчи, по которым деталей ещё нет.
- Следствие перехода с Ls: события и составы ранее загруженных матчей пересоздаются данными `/Games` (игроков без опознания по имени в событиях заменяет null + `person_name`).
- Счёт серии пенальти `sync_match_details` не пишет (в `/Games/{id}` его нет; его даёт `sync_fixtures` из `/Ls/List`).
- TODO: судья/стадион/зрители (в `/Games` есть `venue`, `refereeName`); события серии пенальти; тренеры; xG/xA точнее `decimal(5,2)`; координаты x/y.

**`sync_squads` — составы клубов** (эндпоинт SStats `/Players/list?teamId=<числовой id команды>`, один запрос на команду; пагинация до 1000 записей; запасной `/Ls/Players?teamId=<flash-id>` — только за позицией):

- Payload: `{"competition": {"id", "external_id"}, "season_external_id": "...", "team_external_ids": ["<числовой id>"], "team_flash_ids": {"<числовой id>": "<flash-id>"}?, "context": {...}}`. Список команд выбирает Laravel (`TeamRepository::idsInSeason`: команды этапов сезона и участники его матчей), в прогон попадают команды с числовым id SStats (его даёт `sync_fixtures`), максимум 200. Явный список — `--team=<slug|числовой id|flash-id>`.
- Игрок: `name` = «имя фамилия» (иначе `name` источника), `first_name`, `last_name`, `birth_date`, `height_cm` (140–230), `photo_url`, `nationality` (название страны → `countries.name`, иначе страна команды). Позиции в `/Players/list` нет: если у команды есть flash-id, collector читает `/Ls/Players` и сопоставляет человека: та же фамилия и дата рождения (без даты — фамилия и инициал), иначе единственный в составе с той же датой рождения и общим словом имени; найденный даёт `position` и `flash_id`. Сбой `/Ls/Players` (не 401/403/429) не валит состав — ошибка `squad` на фазе `fetch`, игроки уходят без позиции.
- Травмы и дисквалификации: `unavailabilities[]` (`start`, `end`, `typeName`) → `absences[] = {type, reason, since, expected_return}` у игрока состава; берутся только активные (`end` пуст или не раньше сегодня). `type`: `suspen/card/ban/disqual` в `typeName` → `suspension`, `doubt/question` → `doubtful`, иначе `injury`; `reason` — `typeName`.
- Сущности (порядок по зависимостям): `player` (9, `people_source = sstats_api`, `external_id` = числовой id) → `squad` (10, `external_id` = числовой id команды, `players[] = {player_external_id, position?, absences?}`). `squad` публикуется только при полностью полученном и непустом списке: Laravel закрывает заявки игроков, которых в списке нет, поэтому сбой запроса или пустой ответ уходят в `errors[]`, а состав команды остаётся как был. `401/403` и исчерпанный лимит запросов — `failed` целиком.
- Laravel: `PlayerUpserter` — игрок по числовому id, затем по `flash_id` (игроки из Ls), затем единственное совпадение имени среди игроков команды без числового id (`PersonName`); позиция без данных Ls остаётся прежней. `SquadUpserter` + `SquadMembershipRepository` (`squad_memberships`): для каждого игрока открывается заявка (позиция — из ответа, иначе из `players.position`); заявки игроков, которых больше нет, закрываются (`left_on = сегодня`), заявки в других клубах закрываются при переходе; неизвестные игроки — `squad_players.skipped`. `PlayerAbsenceRepository` синхронизирует `player_absences` команды: отсутствие — (игрок, тип, дата начала); пропавшее из источника закрывается (`is_active = false`), счётчики — `player_absences`. Payload без `people_source` (старый формат Ls) обрабатывается как раньше.
- Запуск: `php artisan ingest:dispatch sync_squads --competition=<slug|id> [--season=<slug>] [--team=<slug|id>]...`; блокировка — на (провайдер, соревнование, `sync_squads`). Плановая задача `squads` (ниже) синхронизирует составы всех включённых соревнований раз в сутки; Кубок России (≈ 100 команд, до 2 запросов на команду при лимите 30/мин) запускается вручную: `php artisan ingest:dispatch sync_squads --competition=russian-cup`.
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

Нужные env: `SSTATS_API_KEY` (collector), `RABBITMQ_HOST/PORT/USER/PASSWORD/VHOST` (web), `COLLECTOR_RABBITMQ_URL` (collector), опционально `SSTATS_RATE_PER_MINUTE`, `COLLECTOR_PREFETCH`, `COLLECTOR_BATCH_SIZE`. Ответы `/Leagues`, `/Games/list`, `/Games/{id}`, `/Players/list`, `/Ls/Players`, `/Ls/SeasonStructure`, `/Ls/List` проверены вживую с реальным ключом на ФНЛ и РПЛ 2026-2027.

## Расписание и постоянные воркеры

После `docker compose up` данные обновляются сами: четыре долгоживущих процесса и плановые прогоны.

| Сервис compose | Команда | Роль |
|----------------|---------|------|
| `collector` | `/collector` (`restart: unless-stopped`) | читает `ingest.commands`, ходит в SStats, пишет в `ingest.results` |
| `ingest-consume` | `php artisan ingest:consume --max-time=3600` (`restart: unless-stopped`) | применяет результаты к БД; после часа работы штатно завершается и поднимается заново (чистая память и соединения) |
| `scheduler` | `php artisan schedule:work` (`restart: unless-stopped`) | вызывает плановые задачи ниже |
| `app` | `artisan serve` | healthcheck `/up`: воркеры стартуют только после него, т.е. когда `vendor` установлен и миграции применены (воркеры миграции не гоняют: `RUN_MIGRATIONS=0`) |

Без Compose (supervisor/systemd): два процесса `php artisan ingest:consume --max-time=3600` (перезапуск при выходе) и `php artisan schedule:work`; либо cron `* * * * * php artisan schedule:run` вместо `schedule:work`. Collector — отдельный бинарник/контейнер с перезапуском.

**Плановые задачи** (`App\Console\IngestSchedule`, регистрируется в `bootstrap/app.php`; интервалы и окна — `config/ingest.php`, env `INGEST_*`, см. `web/.env.example`). Задачи вызывают `ingest:run-scheduled {job}`: соревнования определяются **в момент запуска** — явный список `INGEST_COMPETITIONS` (slug'и, override), иначе все `competitions.ingest_enabled = true` с внешним id SStats (витринный `status` не учитывается); на каждое соревнование — обычный `ingest:dispatch ... --scheduled`.

| Задача (`job`) | Что делает | По умолчанию | Env |
|----------------|------------|--------------|-----|
| `fixtures` | `sync_fixtures` с окном [сегодня − 2 дня, сегодня + 2 дня] (даты по Москве), все стадии | каждые 10 мин (`*/10 * * * *`) | `INGEST_FIXTURES_EVERY_MINUTES`, `_DAYS_BACK`, `_DAYS_FORWARD` |
| `details_missing` | `sync_match_details --missing --limit=10`: завершённые матчи без деталей, свежие первыми | каждые 15 мин | `INGEST_DETAILS_MISSING_EVERY_MINUTES`, `_LIMIT` |
| `details_recent` | `sync_match_details` по матчам, завершённым за последние сутки (окно [вчера, сегодня]), `--limit=10`: поздние поправки статистики | раз в час в :10 (`10 * * * *`) | `INGEST_DETAILS_RECENT_EVERY_MINUTES`, `_OFFSET_MINUTES`, `_DAYS_BACK`, `_LIMIT` |
| `details_live` | `sync_match_details --live --limit=20`: идущие сейчас матчи (`matches.status = live`, текущий сезон соревнования, начало не старше 6 ч — страховка от «застрявших» в live) — статистика, события, составы; самые давние по началу первыми. Отдельного «fast live» нет: статус и счёт берёт `fixtures` раз в 10 мин, а этот job — события, статистику, составы и заодно `state` из `/Games/{id}` (статус, минута, счёт) | каждые 15 мин со сдвигом +5 мин (`5-59/15`: :05, :20, :35, :50) | `INGEST_DETAILS_LIVE_EVERY_MINUTES`, `_OFFSET_MINUTES`, `_LIMIT`, `_MAX_AGE_HOURS` |
| `fixtures_full` | `sync_fixtures` на весь текущий сезон без окна: переносы матчей, новые туры | ежедневно 03:30 МСК | `INGEST_FIXTURES_FULL_DAILY_AT` |
| `catalog` | `sync_catalog --league-id=<внешние id лиг соревнований с ingest_enabled>`; если заданы `INGEST_CATALOG_COUNTRY` / `_LEAGUE` — они override (`--country`, `--league`); нет ни включённых соревнований, ни env-фильтров — пропуск | ежедневно 04:00 МСК | `INGEST_CATALOG_DAILY_AT`, `_COUNTRY`, `_LEAGUE` (по умолчанию пусто) |
| `structure` | `sync_structure` (стадии и команды текущего сезона) | ежедневно 04:10 МСК | `INGEST_STRUCTURE_DAILY_AT` |
| `squads` | `sync_squads`: составы клубов текущего сезона всех соревнований с `ingest_enabled` (РПЛ, ФНЛ, Кубок России, еврокубки и топ-4 чемпионата) — только команды с `teams.ingest_enabled`; список slug'ов из `INGEST_SQUADS_COMPETITIONS` — override | ежедневно 04:20 МСК | `INGEST_SQUADS_DAILY_AT`, `INGEST_SQUADS_COMPETITIONS` (по умолчанию пусто) |
| `ingest:reap-stuck` | уборщик зависших прогонов (ниже) | каждые 10 мин | `INGEST_REAP_EVERY_MINUTES`, `INGEST_STUCK_AFTER_MINUTES` |

- Сдвиги `details_live` (+5) и `details_recent` (+10) нужны, потому что один `running` на (провайдер, соревнование, `kind`): все три задачи — `sync_match_details`, и второй плановый запуск в ту же минуту пропускался бы. Минуты старта не пересекаются: `details_missing` — :00/:15/:30/:45, `details_live` — :05/:20/:35/:50, `details_recent` — :10. Для интервалов ≥ 60 мин `_OFFSET_MINUTES` — минута часа. `ingest:dispatch sync_match_details --live` доступен и вручную (несовместим с `--missing`, `--from`, `--to`).
- Интервал `0` выключает задачу, пустое время (`*_DAILY_AT=`) — тоже; `INGEST_SCHEDULE_ENABLED=false` выключает всё расписание. Интервалы ≥ 60 мин округляются до целых часов. Время ежедневных задач (и «сегодня» в окнах дат) — в часовом поясе приложения: `APP_TIMEZONE`, по умолчанию `Europe/Moscow` (задачи вешают `->timezone(config('app.timezone'))`; в compose переменная пробрасывается всем сервисам, включая `scheduler`). Сессия PostgreSQL тоже в этом поясе (`database.connections.pgsql.timezone`, `DB_TIMEZONE`): `timestamptz` хранит моменты как есть, `kickoff_at` из collector (UTC) приводится к поясу приложения при записи.
- Rate-friendly: `details_missing` / `details_recent` берут не более 10 матчей (≈ 10 запросов к SStats) за прогон, `details_live` — не более 20 (≈ 20 запросов раз в 15 мин, только реально идущие матчи включённых соревнований; в обычный игровой день live-матчей заметно меньше лимита), `fixtures` — один запрос на окно. Один `running` на (провайдер, соревнование, `kind`): если прошлый прогон ещё идёт, плановый запуск пропускается (`ingest:dispatch --scheduled` пишет «Пропущено» и завершается успешно; так же — «нечего грузить»). Плановые прогоны помечены `stats.context.trigger = schedule`.
- **Флаги ingest** (отдельно от витрины `status`): `competitions.ingest_enabled boolean NOT NULL DEFAULT false` (индекс) и `teams.ingest_enabled boolean NOT NULL DEFAULT true`. Соревнование для регулярной загрузки включается так: `sync_catalog` → `sync_structure` (разово руками, как в «Запуске локально») → `UPDATE competitions SET ingest_enabled = true WHERE slug = '…'` (в коде — `CompetitionRepository::setIngestEnabled()`; будущая админка пишет туда же). `sync_catalog` создаёт соревнование с `ingest_enabled = false` и `status = hidden`, флаги сам не меняет. Миграция `2026_11_27_100003` включила `premier-league` и `russian-cup`, остальные выключены; на свежей БД после catalog — `php artisan db:seed --class=IngestFlagsSeeder`: сидер включает `ingest_enabled` у `premier-league`, `fnl` (Первая лига / ФНЛ, SStats `jizXHcsM`) и `russian-cup`, а ФНЛ дополнительно делает витринной лигой (`status = live`, `type = league`, `tier = 2`, `sort_order = 15`, `short_name` FNL / «ФНЛ»). Порядок для ФНЛ: `sync_catalog --country=Russia` → `sync_structure` → `sync_fixtures --stage=Main` → `sync_squads` (проверено: сезон 2026-2027, 18 команд, 306 матчей, 552 заявки игроков).

  **Еврокубки и топ-4 чемпионата.** Сидер дополнительно включает `ingest_enabled` и выводит в витрину (`status = live`) семь соревнований; slug'и задаёт `sync_catalog`, внешние id лиг SStats:

  | slug | SStats id | `type` / `scope` | `tier` | `sort_order` | short_name (EN / ru) | name (ru) |
  |------|-----------|------------------|--------|--------------|----------------------|-----------|
  | `champions-league` | `xGrwqq16` | `tournament` / `continental` | — | 30 | UCL / ЛЧ | Лига чемпионов |
  | `europa-league` | `ClDjv3V5` | `tournament` / `continental` | — | 40 | UEL / ЛЕ | Лига Европы |
  | `conference-league` | `GfRbsVWM` | `tournament` / `continental` | — | 50 | UECL / ЛК | Лига конференций |
  | `premier-league-gb-eng` | `dYlOSQOD` | `league` / `domestic` | 1 | 60 | EPL / АПЛ | Премьер-лига |
  | `laliga` | `QVmLl54o` | `league` / `domestic` | 1 | 70 | LaLiga / Ла Лига | Ла Лига |
  | `bundesliga` | `W6BOzpK2` | `league` / `domestic` | 1 | 80 | BL / Бундеслига | Бундеслига |
  | `serie-a` | `COuk57Ci` | `league` / `domestic` | 1 | 90 | SA / Серия A | Серия A |

  Slug английской Премьер-лиги — `premier-league-gb-eng`, потому что `premier-league` занят РПЛ. Порядок: `sync_catalog --league-id=<id>` (по одному `--league-id` на каждую лигу) → `db:seed --class=IngestFlagsSeeder` → для каждой `sync_structure` → `sync_fixtures` → `sync_squads` (последовательно, лимит SStats 30/мин). Сидер также ставит русские названия стран (Англия, Испания, Германия, Италия, Европа) и выключает `teams.ingest_enabled` у клубов, сыгравших в еврокубке текущего сезона только квалификацию (stage `Qualification`) и не входящих ни в основной этап еврокубков, ни в включённые чемпионаты: суточный `squads` не тратит на них лимит. Проверено вживую (сезон 2026-2027, матчи / команды в сезоне / составы): ЛЧ 234 / 81 / 65, ЛЕ 226 / 76 / 55, ЛК 368 / 165 / 36, АПЛ 380 / 20 / 20, Ла Лига 380 / 20 / 20, Бундеслига 306 / 18 / 18, Серия A 380 / 20 / 20; ошибок нет, кроме одного матча Ла Лиги в статусе SStats 37 «Abandoned» (теперь маппится в `abandoned`). Страны-континенты SStats (`abbr2 = ww`, например Europe) collector передаёт как `kind = region` с кодом из `abbr3` (`EUR`), поэтому континентальные лиги получают `scope = continental`.
- **Что учитывает флаги:** `ingest:run-scheduled` — все задачи (`fixtures`, `fixtures_full`, `details_missing`, `details_recent`, `details_live`, `structure`, `squads` берут соревнования с `competitions.ingest_enabled`; `catalog` — их внешние id лиг; `squads` дополнительно отбрасывает команды с `teams.ingest_enabled = false`, в том числе при ручном `ingest:dispatch sync_squads` без `--team`). **Не учитывают:** ручной `ingest:dispatch` с явным `--competition` / `--team` (оператор может загрузить и выключенное соревнование); env-override (`INGEST_COMPETITIONS`, `INGEST_SQUADS_COMPETITIONS`, `INGEST_CATALOG_COUNTRY`/`_LEAGUE`) заменяют выборку по флагу.
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
