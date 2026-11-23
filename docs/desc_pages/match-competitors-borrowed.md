# Match page — что взяли у конкурентов

Короткий список блоков, вдохновлённых ресёрчем (`internal/match-page-research.md`), и как они легли в MatchIQ.

| Блок | Откуда паттерн | Как у нас |
|------|----------------|-----------|
| Шапка: стадион, судья, турнир | Sofascore / FotMob / Flashscore | В карточке матча, без оверлеев |
| Dual-bar сравнение last N | WhoScored / FotMob / Understat-стиль | Голы, xG, удары, SOT, угловые, карточки, фолы + possession / BTTS / CS |
| Home/away split averages | Sofascore / Forebet previews | Отдельная секция «Дома / в гостях» |
| Мини-таблица вокруг команд | FotMob / ESPN | Slice с highlight обеих сторон |
| Бомбардиры сторон | Transfermarkt / league widgets | Две колонки, голы+ассисты |
| Absences / doubtful | Sofascore / FotMob | Теги «Вне заявки» / «Сомнительно» |
| Goal timing bands | Understat / WhoScored | Dual bars по 15-мин отрезкам |
| Form sticks + H2H list | Flashscore / BBC / FotMob | Палочки + последние встречи |
| Community tips % + comments | Forebet / Goal.com-ish | `Выбрали N%` на событии + демо-тред |

## Уникальность MatchIQ (не клоним)

- Вероятности **0–100** как первичный рынок (не коэффициенты букмекера).
- **Один pick** на матч для рейтинга; фильтры лучшие / худшие / популярные.
- AI-текст **2–4 предложения**, не фактор-лист.
- Multi-league badge; светлый pitch daylight (Syne/Manrope), не тёмный клон FotMob.
