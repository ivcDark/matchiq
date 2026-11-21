# MatchIQ collector (stub)

Go-сервис ingestion. Сейчас только HTTP-заглушка:

- `GET /` — текст-placeholder
- `GET /healthz` — JSON health

Позже: адаптеры `provider` + `league` (`FetchSchedule` / `FetchMatchStats` / `FetchLineup`), запись в Postgres.

```bash
go run ./cmd/collector
# или через docker compose service `collector`
```
