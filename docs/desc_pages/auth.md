# Аутентификация (сводка)

**Статус:** MVP  
**Стек:** Laravel Breeze (Inertia/Vue) + Socialite (Яндекс ID, VK ID)

Отдельные экраны:

| Экран | URL | Файл |
|-------|-----|------|
| Вход | `/login` | [login.md](login.md) |
| Регистрация | `/register` | [register.md](register.md) |
| Забыли пароль | `/forgot-password` | [forgot-password.md](forgot-password.md) |
| Новый пароль | `/reset-password/{token}` | [reset-password.md](reset-password.md) |

## Поведение

- Email + пароль: стандартная сессия Laravel; throttle; ошибки на русском; после входа → `/` (matches.index).
- Регистрация: ник (уникальный), email, пароль + повтор; клиентская и серверная проверка совпадения; подсказка «≥ 8 символов»; показать/скрыть пароль.
- Сброс пароля: письмо в `MAIL_MAILER=log` (смотреть `storage/logs`).
- OAuth: `/auth/yandex`, `/auth/vk` (+ `/callback`). Без ключей в `.env` — редирект на вход с текстом «Вход через … пока не настроен».
- Привязка: таблица `social_accounts` (provider + provider_id); пользователь ищется по email или создаётся.
- Шапка: гость — «Войти» / «Регистрация»; авторизованный — ник → `/me` и «Выйти».
- Без 2FA и без обязательного подтверждения email.

## .env для соцсетей

```
YANDEX_CLIENT_ID=
YANDEX_CLIENT_SECRET=
YANDEX_REDIRECT_URI="${APP_URL}/auth/yandex/callback"

VK_CLIENT_ID=
VK_CLIENT_SECRET=
VK_REDIRECT_URI="${APP_URL}/auth/vk/callback"
```
