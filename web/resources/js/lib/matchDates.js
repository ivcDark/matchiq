const WEEKDAY = new Intl.DateTimeFormat('ru-RU', { weekday: 'short' });
const DAY_MONTH = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short' });

/** «2026-11-28» → локальная дата без сдвига часового пояса. */
function parse(iso) {
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d);
}

/** «сб, 28 нояб.» → «сб 28.11» (коротко для строки матча). */
export function shortDate(iso) {
    const d = parse(iso);
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');

    return `${WEEKDAY.format(d)} ${dd}.${mm}`;
}

/** «28 нояб.» */
export function dayMonth(iso) {
    return DAY_MONTH.format(parse(iso)).replace('.', '');
}

/** «27–29 нояб.» или «28 нояб.» для диапазона дат тура. */
export function dateRange(from, to) {
    if (from === to) {
        return dayMonth(from);
    }
    const a = parse(from);
    const b = parse(to);
    if (a.getMonth() === b.getMonth()) {
        return `${a.getDate()}–${dayMonth(to)}`;
    }

    return `${dayMonth(from)} – ${dayMonth(to)}`;
}
