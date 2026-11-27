/** П1 / Х / П2 — все три вероятности исхода, если заданы. */
export function hasResultProbs(match) {
    return (
        typeof match?.prob_home === 'number' &&
        typeof match?.prob_draw === 'number' &&
        typeof match?.prob_away === 'number'
    );
}

/** Ключ исхода с максимальной вероятностью (home | draw | away) или null. */
export function resultProbMaxKey(match) {
    if (!hasResultProbs(match)) return null;
    const vals = [
        { key: 'home', value: match.prob_home },
        { key: 'draw', value: match.prob_draw },
        { key: 'away', value: match.prob_away },
    ];
    vals.sort((a, b) => b.value - a.value);
    return vals[0].key;
}

/** Максимальная вероятность исхода матча (для выбора «Матча дня»), иначе -1. */
export function maxResultProb(match) {
    if (!hasResultProbs(match)) return -1;
    return Math.max(match.prob_home, match.prob_draw, match.prob_away);
}

/** Лучшие события матча (до `limit`): top_markets с бэка, иначе единственный top_market. */
export function bestMarkets(match, limit = 3) {
    const list = Array.isArray(match?.top_markets) && match.top_markets.length
        ? match.top_markets
        : match?.top_market
            ? [match.top_market]
            : [];
    return list
        .filter((m) => m && typeof m.label === 'string' && typeof m.prob === 'number')
        .slice(0, limit);
}

/** Первое предложение превью — заголовок в рейле «Превью матчей». */
export function firstSentence(text, max = 110) {
    const s = String(text || '').trim();
    if (!s) return '';
    const m = /^.+?[.!?…](?=\s|$)/.exec(s);
    const out = m ? m[0] : s;
    return out.length > max ? `${out.slice(0, max - 1).trimEnd()}…` : out;
}
