# Забыли пароль

**URL:** `/forgot-password`  
**Статус:** MVP  
**Inertia:** `Auth/ForgotPassword`

## Что на экране

1. Бренд + «Восстановить пароль».
2. Инструкция + поле email + «Отправить ссылку».
3. «Назад ко входу».
4. Письма: `MAIL_MAILER=log` (смотреть лог), ссылка на `/reset-password/{token}`.

## Состояния

| Состояние | Поведение |
|-----------|-----------|
| Success | Flash из `passwords.sent` (русский) |
| Validation | Ошибка у email |
