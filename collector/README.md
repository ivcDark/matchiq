# MatchIQ collector (stub)

Go-сервис сбора данных (ingestion). Сейчас только HTTP-заглушка:

- `GET /` — текст-placeholder
- `GET /healthz` — JSON health

Позже: consumer команд из RabbitMQ, сборщики по типу джобы (расписание, результаты, статистика…), клиент SStats.net, публикация результата (канон DTO) в RabbitMQ. **В PostgreSQL коллектор не пишет** — только Laravel. Схема и контракт сообщений: [../docs/architecture/ingestion.md](../docs/architecture/ingestion.md).

```bash
go run ./cmd/collector
# или через docker compose service `collector`
```
