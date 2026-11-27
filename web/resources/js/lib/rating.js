/** «балл / балла / баллов» по числу. */
export function pointsWord(n) {
    const mod10 = Math.abs(n) % 10;
    const mod100 = Math.abs(n) % 100;
    if (mod10 === 1 && mod100 !== 11) return 'балл';
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'балла';
    return 'баллов';
}

/** «+46 баллов» */
export function formatPoints(n) {
    return `+${n} ${pointsWord(n)}`;
}

/** Подпись состояния сохранённого выбора. */
export function pickStatusText(pick) {
    if (!pick) return '';
    if (pick.status === 'won') return `Зашло · ${formatPoints(pick.points)}`;
    if (pick.status === 'lost') return 'Мимо · 0 баллов';
    if (pick.status === 'void') return 'Матч отменён · без баллов';
    return 'Ждёт матча';
}
