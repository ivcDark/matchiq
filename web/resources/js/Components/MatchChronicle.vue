<script setup>
import MatchStatIcon from '@/Components/MatchStatIcon.vue';
import { computed } from 'vue';

const props = defineProps({
    /** [{ minute, side: home|away, type: goal|yellow|red|sub, player, assist, player_in, player_out, score_home, score_away }] */
    events: { type: Array, required: true },
    live: { type: Boolean, default: false },
});

const rows = computed(() => {
    const byMinute = new Map();
    props.events.forEach((event) => {
        if (!byMinute.has(event.minute)) {
            byMinute.set(event.minute, { minute: event.minute, home: [], away: [] });
        }
        byMinute.get(event.minute)[event.side].push(event);
    });

    return [...byMinute.values()].sort((a, b) => a.minute - b.minute);
});
</script>

<template>
    <section class="miq-panel" aria-label="Хроника матча">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">Хроника</h2>
            <p class="text-[11px] font-medium text-miq-muted">{{ live ? 'по ходу матча' : 'события матча' }}</p>
        </div>

        <p v-if="!rows.length" class="px-3.5 py-4 text-sm text-miq-muted">Значимых событий пока нет.</p>

        <ol v-else class="miq-mc-chron">
            <li v-for="row in rows" :key="row.minute" class="miq-mc-chron-row">
                <ul class="miq-mc-chron-side is-home">
                    <li v-for="(event, i) in row.home" :key="`h-${i}`" class="miq-mc-event" :class="`is-${event.type}`">
                        <template v-if="event.type === 'sub'">
                            <span class="miq-mc-out">{{ event.player_out }}</span>
                            <span class="miq-mc-arrow is-out" aria-label="Ушёл">↓</span>
                            <span class="miq-mc-in">{{ event.player_in }}</span>
                            <span class="miq-mc-arrow is-in" aria-label="Вышел">↑</span>
                        </template>
                        <template v-else>
                            <span v-if="event.assist" class="miq-mc-assist-name">{{ event.assist }}</span>
                            <span v-if="event.assist" class="miq-mc-assist" title="Голевая передача">А</span>
                            <span class="miq-mc-player">{{ event.player }}</span>
                            <span v-if="event.type === 'goal'" class="miq-mc-score tabular-nums">{{ event.score_home }}–{{ event.score_away }}</span>
                            <MatchStatIcon v-if="event.type === 'goal'" kind="goals" class="miq-mc-goal" />
                            <span v-else class="miq-mc-card" :class="event.type === 'red' ? 'is-red' : 'is-yellow'" :title="event.type === 'red' ? 'Красная карточка' : 'Жёлтая карточка'" />
                        </template>
                    </li>
                </ul>

                <span class="miq-mc-minute tabular-nums">{{ row.minute }}'</span>

                <ul class="miq-mc-chron-side is-away">
                    <li v-for="(event, i) in row.away" :key="`a-${i}`" class="miq-mc-event" :class="`is-${event.type}`">
                        <template v-if="event.type === 'sub'">
                            <span class="miq-mc-arrow is-in" aria-label="Вышел">↑</span>
                            <span class="miq-mc-in">{{ event.player_in }}</span>
                            <span class="miq-mc-arrow is-out" aria-label="Ушёл">↓</span>
                            <span class="miq-mc-out">{{ event.player_out }}</span>
                        </template>
                        <template v-else>
                            <MatchStatIcon v-if="event.type === 'goal'" kind="goals" class="miq-mc-goal" />
                            <span v-else class="miq-mc-card" :class="event.type === 'red' ? 'is-red' : 'is-yellow'" :title="event.type === 'red' ? 'Красная карточка' : 'Жёлтая карточка'" />
                            <span v-if="event.type === 'goal'" class="miq-mc-score tabular-nums">{{ event.score_home }}–{{ event.score_away }}</span>
                            <span class="miq-mc-player">{{ event.player }}</span>
                            <span v-if="event.assist" class="miq-mc-assist" title="Голевая передача">А</span>
                            <span v-if="event.assist" class="miq-mc-assist-name">{{ event.assist }}</span>
                        </template>
                    </li>
                </ul>
            </li>
        </ol>
    </section>
</template>
