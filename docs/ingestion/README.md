# Ingestion: источники и схема загрузки

Схема (принята 2026-11-26): **Laravel** создаёт джобы и **единственный работает с БД** → команда в **RabbitMQ** → **Go-коллектор** ходит в **SStats.net**, нормализует в канон DTO → результат в **RabbitMQ** → **Laravel consumer** сохраняет через репозитории и ведёт `ingestion_runs`, `ingestion_run_errors`, `external_ids`. Go в PostgreSQL не пишет.

Основной документ — [../architecture/ingestion.md](../architecture/ingestion.md) (поток, очереди, контракт сообщений, структура Go-сборщика, роль Laravel).

## Документы в каталоге

| Файл | О чём |
|------|-------|
| [sstats-openapi-v1.json](sstats-openapi-v1.json) | OpenAPI 3 **SStats.net** (v0.9.52.0), primary API. Сервер `https://api.sstats.net`, ключ — query-параметр `apikey` (хранится только в env Go-коллектора) |
| [api-football-coverage-vs-ui.md](api-football-coverage-vs-ui.md) | Оценка покрытия UI данными API-Football (запасной/альтернативный источник) |
| [source-evaluation-forebet.md](source-evaluation-forebet.md) | Оценка Forebet — не подходит как primary |

## SStats.net — краткий обзор

Лимиты (из описания API): без ключа 300 запросов/мин на всех и 30 запросов/мин на IP; с ключом — по аккаунту (`GET /Account/Info`). Весь функционал бесплатен с ограничением по запросам.

Две группы идентификаторов: «основные» (`/Games`, `/Leagues`, `/Teams`, `/Players`, `/Seasons`) и расширенные `/Ls/*` (лиги, сезоны, стадии/раунды, составы, standings). Для коллектора наиболее важны:

| Назначение | Эндпоинты |
|------------|-----------|
| Справочник лиг/сезонов | `GET /Leagues`, `GET /Ls/Leagues`, `GET /Ls/Seasons`, `GET /Ls/SeasonStructure` |
| Расписание и результаты | `GET /Games/list` (`LeagueId`, `Year`/`SeasonUid`, `From`, `To`, `Status`, `Ended`), `GET /Ls/List` |
| Матч, события, статистика | `GET /Games/{id}`, `GET /Ls/GameInfo` |
| Составы | `GET /Ls/GameLineups`, `GET /Ls/GamePredictedLineups` |
| Таблицы | `GET /Ls/Standings`, `GET /Seasons/standings`, `GET /Games/season-table` |
| Команды и игроки | `GET /Teams/list`, `GET /Teams/{id}`, `GET /Players/list`, `GET /Ls/Players`, `GET /Ls/PlayerInfo` |
| Травмы, форма, коэффициенты | `GET /Games/injuries`, `GET /Games/last-games-stats`, `GET /Odds/{gameId}`, `GET /Ls/GameOdds` |

Соответствие `ingestion_runs.kind` ↔ эндпоинты и точные поля канона DTO уточняются при реализации сборщиков; покрытие данных относительно UI MatchIQ нужно сверить на реальных ответах (по аналогии с [api-football-coverage-vs-ui.md](api-football-coverage-vs-ui.md)).
