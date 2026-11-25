<script setup>
import { computed } from 'vue';

const props = defineProps({
    /** { home: { formation, rating, players: [...11, GK first, then by lines], bench }, away: {...} } */
    lineups: { type: Object, required: true },
    home: { type: String, required: true },
    away: { type: String, required: true },
});

function tone(rating) {
    if (rating >= 7.5) return 'is-high';
    if (rating >= 6.5) return 'is-mid';
    return 'is-low';
}

function lastName(name) {
    return name.replace(/^(?:[А-ЯЁA-Z]\.\s*)+/u, '');
}

/** Расставляет игроков по линиям формации: хозяева сверху (вратарь у верхних ворот), гости снизу. */
function layout(lineup, side) {
    const rows = [1, ...lineup.formation.split('-').map(Number)];
    const result = [];
    let cursor = 0;
    rows.forEach((count, rowIdx) => {
        const t = rowIdx / (rows.length - 1);
        const y = side === 'home' ? 7 + t * 38 : 93 - t * 38;
        for (let i = 0; i < count; i += 1) {
            result.push({ ...lineup.players[cursor], x: ((i + 1) / (count + 1)) * 100, y });
            cursor += 1;
        }
    });
    return result;
}

const teams = computed(() => [
    { side: 'home', name: props.home, lineup: props.lineups.home, players: layout(props.lineups.home, 'home') },
    { side: 'away', name: props.away, lineup: props.lineups.away, players: layout(props.lineups.away, 'away') },
]);
</script>

<template>
    <section class="miq-panel" aria-label="Составы">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">Составы</h2>
            <p class="text-[11px] font-medium text-miq-muted">схема и рейтинги</p>
        </div>

        <div class="miq-mc-pitch-wrap">
            <div class="miq-mc-pitch-team is-home">
                <span class="font-semibold text-miq-ink">{{ teams[0].name }}</span>
                <span class="text-xs text-miq-muted">{{ teams[0].lineup.formation }}</span>
                <span class="miq-mc-rating tabular-nums" :class="tone(teams[0].lineup.rating)">{{ teams[0].lineup.rating.toFixed(2) }}</span>
            </div>

            <div class="miq-mc-pitch" role="img" :aria-label="`Схема составов: ${home} — ${away}`">
                <span class="miq-mc-pitch-mid" aria-hidden="true" />
                <span class="miq-mc-pitch-circle" aria-hidden="true" />
                <span class="miq-mc-pitch-box is-top" aria-hidden="true" />
                <span class="miq-mc-pitch-box is-bottom" aria-hidden="true" />
                <template v-for="team in teams" :key="team.side">
                    <div
                        v-for="player in team.players"
                        :key="`${team.side}-${player.number}`"
                        class="miq-mc-player-dot"
                        :class="`is-${team.side}`"
                        :style="{ left: `${player.x}%`, top: `${player.y}%` }"
                    >
                        <span class="miq-mc-dot" :class="`is-${team.side}`">
                            <span class="tabular-nums">{{ player.number }}</span>
                            <span class="miq-mc-dot-rating tabular-nums" :class="tone(player.rating)">{{ player.rating.toFixed(1) }}</span>
                            <span v-if="player.goals" class="miq-mc-dot-badge is-goal" title="Гол">⚽{{ player.goals > 1 ? player.goals : '' }}</span>
                            <span v-else-if="player.assists" class="miq-mc-dot-badge is-assist" title="Голевая передача">А</span>
                            <span v-if="player.red" class="miq-mc-dot-card is-red" title="Красная карточка" />
                            <span v-else-if="player.yellow" class="miq-mc-dot-card is-yellow" title="Жёлтая карточка" />
                            <span v-if="player.sub_out" class="miq-mc-dot-sub" :title="`Замена ${player.sub_out}'`">↓</span>
                        </span>
                        <span class="miq-mc-dot-name">{{ lastName(player.name) }}</span>
                    </div>
                </template>
            </div>

            <div class="miq-mc-pitch-team is-away">
                <span class="font-semibold text-miq-ink">{{ teams[1].name }}</span>
                <span class="text-xs text-miq-muted">{{ teams[1].lineup.formation }}</span>
                <span class="miq-mc-rating tabular-nums" :class="tone(teams[1].lineup.rating)">{{ teams[1].lineup.rating.toFixed(2) }}</span>
            </div>
        </div>

        <div class="miq-mc-bench">
            <div v-for="team in teams" :key="team.side">
                <p class="miq-mc-bench-title">Вышли на замену · {{ team.name }}</p>
                <p v-if="!team.lineup.bench.length" class="px-3.5 py-2 text-xs text-miq-muted">Замен не было.</p>
                <ul v-else>
                    <li v-for="p in team.lineup.bench" :key="p.number" class="miq-mc-bench-row">
                        <span class="miq-mc-best-num tabular-nums">{{ p.number }}</span>
                        <span class="min-w-0 flex-1 truncate text-[13px] font-semibold text-miq-ink">{{ p.name }}</span>
                        <span class="text-[11px] font-semibold text-miq-pitch tabular-nums">▲ {{ p.sub_in }}'</span>
                        <span class="miq-mc-rating tabular-nums" :class="tone(p.rating)">{{ p.rating.toFixed(1) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
