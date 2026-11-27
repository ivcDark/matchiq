const dateFormat = new Intl.DateTimeFormat('ru-RU', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

export function formatNewsDate(iso) {
    if (!iso) {
        return '';
    }

    return dateFormat.format(new Date(iso));
}
