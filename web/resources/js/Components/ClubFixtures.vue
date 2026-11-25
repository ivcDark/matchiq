<script setup>
import { shortDate } from '@/lib/matchDates';

defineProps({
    title: { type: String, required: true },
    /** 'calendar' — предстоящие матчи, 'results' — сыгранные (со счётом и чипом В/Н/П) */
    mode: { type: String, default: 'calendar' },
    /** [{ tournament, tournament_name, stage, date, time, venue, opponent, opponent_id, home, away, home_score?, away_score?, gf?, ga?, result? }] */
    matches: { type: Array, required: true },
    emptyText: { type: String, required: true },
});

const RESULT_CLASSES = { В: 'is-w', Н: 'is-d', П: 'is-l' };
const RESULT_TITLES = { В: 'Победа', Н: 'Ничья', П: 'Поражение' };
</script>

<template>
    <section class="miq-panel" :aria-label="title">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">{{ title }}</h2>
            <p class="text-xs text-miq-muted">{{ matches.length }} матч.</p>
        </div>

        <p v-if="!matches.length" class="miq-club-placeholder">{{ emptyText }}</p>

        <div v-else class="overflow-x-auto">
            <table class="miq-club-table min-w-[44rem]">
                <thead>
                    <tr>
                        <th class="is-left">Дата</th>
                        <th class="is-left">Турнир</th>
                        <th class="is-left">Тур / стадия</th>
                        <th class="is-left">Матч</th>
                        <th>Дома / в гостях</th>
                        <th v-if="mode === 'results'" title="Результат">Рез.</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in matches" :key="`${m.tournament}-${m.date}-${m.opponent}`">
                        <td class="is-left whitespace-nowrap">
                            {{ shortDate(m.date) }}
                            <span v-if="mode === 'calendar'" class="text-miq-muted">{{ m.time }}</span>
                        </td>
                        <td class="is-left whitespace-nowrap">{{ m.tournament_name }}</td>
                        <td class="is-left whitespace-nowrap">{{ m.stage }}</td>
                        <td class="is-left">
                            <div class="grid min-w-[18rem] grid-cols-[1fr_auto_1fr] items-center gap-x-3" data-test="fixture-match">
                                <span class="text-right font-semibold text-miq-ink">{{ m.home }}</span>
                                <span v-if="mode === 'results'" class="num-display min-w-[3.5rem] text-center" data-test="fixture-score">{{ m.home_score }} : {{ m.away_score }}</span>
                                <span v-else class="num-display min-w-[3.5rem] text-center text-miq-muted" data-test="fixture-score">– : –</span>
                                <span class="text-left font-semibold text-miq-ink">{{ m.away }}</span>
                            </div>
                        </td>
                        <td>{{ m.venue === 'home' ? 'дома' : 'в гостях' }}</td>
                        <td v-if="mode === 'results'">
                            <span class="miq-form-chip" :class="RESULT_CLASSES[m.result]" :title="RESULT_TITLES[m.result]">{{ m.result }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
