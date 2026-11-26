# MatchIQ — производительность и объём данных

Часть [схемы БД](README.md). **Статус:** предложения приняты как архитектурные defaults (решение № 28 в [open-questions.md](open-questions.md)); индексы реализованы в миграциях `web/database/migrations/2026_11_25_*` (2026-11-25). Цель — быстрые страницы (лента, матч, турнир, клуб, рейтинг) при очень большом объёме истории, без преждевременной сложности в MVP.

## Принципы

1. **Читаем по ключу и по индексу, не считаем.** Горячая страница — это несколько запросов по PK / покрывающему индексу + Redis. Агрегаты по истории (таблицы, бомбардиры, форма) — либо материализованы, либо в кэше, но не пересчитываются на запрос.
2. **Запись дорогая и редкая, чтение дешёвое и частое.** Матчей завершается десятки в день, страниц открывается миллионы. Всё лишнее время тратим при записи (job после матча), а не при чтении.
3. **Бюджет запросов на страницу.** Главная ≤ 5 SQL, страница матча ≤ 8, турнир ≤ 6, клуб ≤ 6, рейтинг ≤ 3 (холодный кэш); целевой DB-time p95 < 50 мс. Бюджет проверяется тестами: `Model::preventLazyLoading()` вне production и feature-тесты с assert на число запросов.
4. **Нет N+1, нет тяжёлых JOIN в списках.** Списки собираются «матчи (+ 1 JOIN на текущий прогноз) → `whereIn` по командам/лигам одним запросом». Переводы живут в jsonb сущности — **0 JOIN** ([i18n.md](i18n.md)).
5. **Пагинация — keyset.** Списки результатов/расписания/историй — по курсору (`kickoff_at`, `id`) с `LIMIT n+1` (флаг `has_more` вместо `COUNT(*)`); `OFFSET` на глубоких страницах запрещён. Размер страницы ограничен сверху.
6. **Узкие выборки.** Не `SELECT *`: списки не тянут `rules`, `extra`, `details`, `source_*`, `facts`, AI-текст.
7. **Денормализация — только осознанная** и с владельцем (кто пишет, как проверяем): см. таблицу ниже. Без триггеров БД на старте — согласованность держит сервис записи и тесты.
8. **Измеряем, потом оптимизируем.** `pg_stat_statements`, `EXPLAIN (ANALYZE, BUFFERS)` на реальных объёмах, логи медленных запросов (> 100 мс). Новые материализации и индексы — по цифрам.

## Оценка объёма

На один завершённый матч: ~2 `match_teams`, ~36 `match_lineups`, ~28 `match_player_stats`, ~6 `match_team_stats` (3 периода × 2), ~20 `match_events`, ~1–3 прогона × ~60–100 `prediction_outcomes` + до 49 `prediction_scorelines`. Доминирует **`prediction_outcomes`** (десятки миллионов строк на миллион матчей), затем `match_lineups`, `match_player_stats`, `match_events`. Сами `matches` — единицы миллионов за все годы и лиги: для PostgreSQL это небольшая таблица.

Следствия: (а) `matches` и справочники **не партиционируем в MVP**; (б) растущие дочерние таблицы проектируем так, чтобы все запросы шли по `match_id` (он первый в каждом UNIQUE/индексе) — это даёт простой путь к hash-партиционированию позже; (в) ограничиваем рост `prediction_*` политикой хранения.

## Горячие пути и бюджет запросов

| Страница | Запросы (холодный кэш) | Что в кэше |
|----------|------------------------|------------|
| Главная | (1) справочник лиг — из `ref:*`; (2) `matches` дня ⨝ `match_predictions` (текущий, `p_*`) по `(competition_id, kickoff_at)` / `(kickoff_at)`; (3) `teams` одним `whereIn` (`id, name, name_i18n, short_name, short_name_i18n, logo_url`); (4) `standings` `is_current` мини-таблицы; (5) `player_season_stats` топ-5 | вся лента дня целиком |
| Матч | (1) `matches` по PK; (2) `teams`/`venues`/`referees`/`match_teams` (+ `coaches`) `whereIn`; (3) текущий прогноз + `prediction_outcomes` по `match_prediction_id`; (4) `match_events`, `match_lineups`, `match_team_stats`, `match_player_stats` — по `match_id` (параллельно или одним сервисом); (5) форма и H2H (индексный скан `LIMIT 5`); (6) превью по локали | `match:{id}` целиком (finished — надолго) |
| Турнир | (1) `competitions`/`seasons`/`stages` (справочник); (2) `standings` `WHERE is_current`; (3) `matches` тура по `(season_id, round_id)`; (4) `player_season_stats` лидеры | таблица, лидеры, тур |
| Клуб | (1) `teams` по PK; (2) `standings` строка клуба; (3) `matches` клуба — `(home/away_team_id, kickoff_at)` + keyset; (4) `squad_memberships` ⨝ `players` | форма, состав |
| Рейтинг | (1) `leaderboard_entries` по `(period…, rank)` `LIMIT 50`; (2) строка текущего пользователя по `(user_id, …)` | топ-N в Redis `ZSET` |

## Что материализуем (храним готовым)

| Срез | Таблица / поле | Как обновляется | Что заменяет |
|------|----------------|-----------------|--------------|
| Турнирные таблицы по турам и контекстам | `standings` (+ `is_current`, `form`, `zone_code`) | job после `finished` — пересчёт этапа в транзакции; `is_current` переключается там же | агрегат по всем матчам при каждом открытии |
| Окна рейтинга | `leaderboard_entries` (+ Redis `ZSET` для топа) | job после резолва picks | `GROUP BY user` по `picks` |
| **Лидеры игроков сезона** | **`player_season_stats`** (новая) | job после `finished`: пересчёт строк игроков этого матча суммой по сезону (≈ 22 игрока) | `SUM` по `match_player_stats` ⨝ `matches` для бомбардиров/ассистентов/рейтинга |
| Лучшие команды, Атака/Защита, голы по 15-минуткам | `team_season_stats` (новая, **позже**) | тот же job | агрегаты по `match_team_stats` / `match_events`; до введения — Redis-кэш |
| Заголовок прогноза для списков | `match_predictions.p_home / p_draw / p_away` | пишется вместе с `prediction_outcomes` в одной транзакции | JOIN в `prediction_outcomes` ×3 на каждую карточку ленты |
| Форма команды в таблице | `standings.form` | пересчёт вместе со `standings` | запрос последних 5 матчей на команду |
| Страна/лига/сезон на матче | `matches.competition_id`, `season_id` (уже приняты) | сервис записи + тест согласованности | JOIN через `stages` |

Остальные агрегаты (форма на странице матча, H2H, статистика судьи, «частые события» профиля) считаются запросом **по индексу с LIMIT** и кэшируются в Redis. Если конкретный запрос всё равно медленный по `EXPLAIN` — вводим `*_season_stats` / materialized view точечно.

**Не материализуем «на каждый чих»:** `REFRESH MATERIALIZED VIEW` по всей таблице после каждого матча — нет; обновляем инкрементально только затронутые строки.

### `player_season_stats` (новая, MVP: да)

Одна строка на (`season_id`, `player_id`, `team_id`) — игрок за сезон мог сменить команду. Поля: `competition_id` (денорм.), `appearances`, `starts`, `minutes`, `goals`, `assists`, `penalties_scored`, `shots`, `shots_on_target`, `xg`, `xa`, `yellow_cards`, `red_cards`, `rating_sum`, `rated_matches` (средний рейтинг = `rating_sum / rated_matches`, чтобы пересчёт был точечным), `motm_count`, `clean_sheets`, `computed_at`. UNIQUE (`season_id`, `player_id`, `team_id`); индексы под сортировки лидеров: (`season_id`, `goals` DESC), (`season_id`, `assists` DESC), (`season_id`, `team_id`), (`player_id`) для профиля игрока. Источник правды — `match_player_stats`; таблицу можно полностью пересобрать командой `artisan`. Нужна и главной (мини-бомбардиры лиги), и турниру, и клубу — это горячий путь, поэтому в MVP.

## Что кэшируем (Redis)

Три слоя: **L1** CDN / HTTP-кэш для публичных страниц гостя; **L2** Redis — агрегаты и готовые payload'ы; **L3** PostgreSQL — индексы и материализованные срезы.

| Что | Ключ (пример) | TTL / инвалидация |
|-----|---------------|-------------------|
| Справочники: страны, соревнования, `market_types` | `ref:competitions` | бессрочно, сброс при изменении (десятки строк) |
| Лента матчей дня по лигам | `feed:{competition_scope}:{date}` (локаленезависимый) | scheduled-день: 1–5 мин; с live — 10–15 с; сброс событием «матч обновлён» |
| Страница матча (finished) | `match:{id}` | долго (сутки+): данные неизменяемы; сброс только при правке |
| Страница матча (live) | `match:{id}:live` | 5–15 с; сброс при голе/смене периода |
| Таблица (текущая) | `standings:{stage}:{group}:{context}` | до пересчёта `standings` (инвалидация job'ом) |
| Лидеры | `leaders:{season}:{metric}` | до пересчёта `player_season_stats` |
| Форма / H2H | `team:{id}:form`, `h2h:{a}:{b}` | сброс при завершении матча команды |
| Рейтинг: топ-N | Redis `ZSET` + кэш страницы | инкрементально при резолве picks |
| «Выбрали N%» (`popular_pct`) | счётчики `pickcnt:{match}:{outcome}` (`HINCRBY` при создании/замене pick) | ночная сверка с БД; `COUNT(*)` по `picks` на запрос не делаем |

Правила: ключи **без локали** (язык выбирается при сериализации, см. [i18n.md](i18n.md)); инвалидация по тегам/событиям (`match:{id}`, `competition:{id}:day:{date}`), а не по TTL «на удачу»; защита от stampede — atomic lock или stale-while-revalidate (`Cache::flexible`); live-страницы без CDN, публичные гостевые страницы (лента) — `Cache-Control: s-maxage=10–60`.

## Ключевые индексы

Индексы подобраны под типовые запросы UI. Нотация: `(a, b DESC)`, `INCLUDE (…)` — покрывающие поля, `WHERE …` — partial.

### `matches`

| Индекс | Запрос |
|--------|--------|
| `(kickoff_at, id)` | лента дня по всем лигам; keyset-пагинация |
| `(competition_id, kickoff_at, id)` | лента «лига + день», расписание/результаты лиги |
| `(season_id, round_id, kickoff_at)` | тур турнира (расписание тура) |
| `(stage_id, round_id)`, `(tie_id, leg)` | сетка плей-офф, пары |
| `(home_team_id, kickoff_at DESC)` и `(away_team_id, kickoff_at DESC)`, обе `WHERE status = 'finished'` | форма команды, результаты клуба: два индексных скана `LIMIT n` + слияние |
| `(LEAST(home_team_id, away_team_id), GREATEST(home_team_id, away_team_id), kickoff_at DESC) WHERE status = 'finished'` | H2H независимо от того, кто был хозяином |
| `(kickoff_at, id) WHERE status = 'live'` | «Сейчас идут» (крошечный индекс) |
| `(kickoff_at, id) WHERE status = 'scheduled'` | job'ы: lock прогноза на kickoff, закрытие приёма picks |
| `(referee_id, kickoff_at DESC) WHERE referee_id IS NOT NULL` | статистика судьи |

Отдельный широкий индекс `(status, kickoff_at)` из черновика **заменяется** partial-индексами выше: статус низкоселективен, и индекс по нему целиком — пустая трата записи.

HOT-обновления: в таблицу `matches` часто пишет Laravel (live-результаты из Go через RabbitMQ) (`minute`, `period`, счёт). Эти колонки **не индексируем**, `fillfactor = 85–90` — тогда большинство обновлений остаются HOT и не раздувают индексы.

### Остальные горячие таблицы

| Таблица | Индекс | Запрос |
|---------|--------|--------|
| `match_predictions` | partial UNIQUE (`match_id`) `WHERE is_current` | прогноз матча одним lookup'ом; JOIN в ленте |
| `prediction_outcomes` | UNIQUE (`match_prediction_id`, `market_type_id`, `line`, `outcome_key`); (`match_prediction_id`, `market_type_id`) | рынки матча, «Лучшие / Худшие» |
| `match_events` | (`match_id`, `sort_order`) [или (`match_id`, `minute`, `sort_order`)] | хроника матча |
| `match_lineups` | UNIQUE (`match_id`, `player_id`); (`match_id`, `team_id`, `is_starter`) | составы |
| `match_player_stats` | UNIQUE (`match_id`, `player_id`); (`player_id`, `match_id`) | профиль игрока |
| `match_team_stats` | UNIQUE (`match_id`, `team_id`, `period`) | статистика матча |
| `standings` | UNIQUE (`stage_id`, `stage_group_id`, `round_id`, `context`, `team_id`); (`stage_id`, `context`, `position`) `WHERE is_current` | «текущая» таблица без поиска «последнего тура» |
| `player_season_stats` | см. выше | лидеры |
| `squad_memberships` | (`team_id`, `left_on`); (`player_id`, `joined_on DESC`) | состав клуба, карьера игрока |
| `picks` | partial UNIQUE (`user_id`, `match_id`) `WHERE superseded_at IS NULL`; (`user_id`, `picked_at DESC`); (`match_id`) `WHERE status = 'pending'` | pick пользователя, профиль/история, резолв после матча |
| `leaderboard_entries` | UNIQUE (`period_type`, `period_key`, `competition_id`, `user_id`); (`period_type`, `period_key`, `competition_id`, `rank`) `INCLUDE (points, picks_count, correct_count, streak)`; (`user_id`, `period_type`, `period_key`) | топ-N по рангу без сортировки; место пользователя |
| `external_ids` | UNIQUE (`source_provider_id`, `entity_type`, `external_id`) | upsert Laravel consumer'а ingest (самый частый запрос на запись) |

Индексы по jsonb (`extra`, `details`, `rules`, `facts`) **не создаём**: в запросах UI их нет. GIN — только по факту появившегося фильтра.

## Партиционирование (предложение)

**В MVP не делаем.** Причины: `matches` — единицы миллионов строк; PostgreSQL требует, чтобы ключ партиционирования входил в PK, поэтому FK из десятков дочерних таблиц на `matches(id)` пришлось бы заменить составными (`id`, `kickoff_at`) или отказаться от FK — цена выше пользы, пока таблица помещается в память.

**Когда и как — по порогам** (ориентиры: таблица > ~50–100 млн строк, `VACUUM`/создание индексов занимает часы, рабочий набор не помещается в RAM):

1. **Дочерние таблицы по `match_id`** — первыми: `prediction_outcomes`, `prediction_scorelines`, `match_lineups`, `match_player_stats`, `match_events`. `PARTITION BY HASH (match_id)` на 16–64 партиций; все запросы уже идут по `match_id` → partition pruning работает без изменения кода. Партиционированная таблица может иметь FK на **непартиционированную** `matches`, поэтому `matches` остаётся цельной. PK становится (`match_id`, `id`) — он уже совпадает с ведущим полем UNIQUE.
2. **`matches` по `kickoff_at`** (RANGE по сезону/году) — только если дошли до сотен миллионов или нужен быстрый сброс «холодных» лет: потребует композитных ключей (`id`, `kickoff_at`) в FK дочерних таблиц либо отказа от FK. Решение откладываем до реальных цифр.
3. **Холодные данные** — старые сезоны без изменений можно держать в отдельных партициях/табличном пространстве и реже вакуумировать; для `prediction_*` — политика хранения ниже.

**Политика хранения (включаем сразу, она дёшева):** устаревшие прогоны `kind = pre_match` (не `is_current`, не `locked`) **без ссылающихся picks** удаляются job'ом через N дней (по умолчанию 7); у завершённых матчей хранится только `locked`-прогон и текущий. `prediction_scorelines` у неактуальных прогонов — удаляются вместе с ними. Это держит `prediction_outcomes` в разумных пределах без партиционирования.

## Что делаем и чего **не** делаем в MVP

**Делаем сразу:**

- индексы из таблиц выше; partial-индексы по `status`;
- `standings` (+ `is_current`), `leaderboard_entries`, `player_season_stats`, `match_predictions.p_*`;
- Redis-кэш ленты, матча, таблиц, лидеров; счётчики picks; keyset-пагинация;
- eager loading (`with`/`whereIn`), `preventLazyLoading`, тесты на число запросов;
- политика хранения `prediction_*`; `fillfactor` на `matches`;
- `pg_stat_statements` + лог медленных запросов.

**Не делаем (пока цифры не потребуют):**

- партиционирование `matches` и шардирование (Citus и т. п.);
- read-реплики и PgBouncer — подключаем, когда упрёмся в CPU/соединения (Laravel умеет `read`/`write` + `sticky`);
- Elasticsearch/Meilisearch для поиска (хватит trigram + алиасов);
- ClickHouse/отдельное OLAP-хранилище, CQRS, event sourcing;
- триггеры БД для денормализации; materialized view с полным `REFRESH` после каждого матча;
- `OFFSET`-пагинация, `COUNT(*)` для «всего N», `SELECT *`, запросы в цикле;
- индексы «на всякий случай» и GIN по jsonb;
- перенос live-счёта целиком в Redis/WebSocket (live-поток вне MVP: polling + короткий TTL).

## Открытые вопросы

См. [open-questions.md](open-questions.md#мультиязычность-и-производительность): пороги партиционирования, срок хранения устаревших прогнозов, TTL live-страниц, CDN для гостевых страниц.
