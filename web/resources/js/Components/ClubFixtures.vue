<script setup>
import { shortDate } from '@/lib/matchDates';

defineProps({
    title: { type: String, required: true },
    /** 'calendar' — предстоящие матчи, 'results' — сыгранные (со счётом и чипом В/Н/П) */
    mode: { type: String, default: 'calendar' },
    /** [{ tournament, tournament_name, stage, date, time, venue, opponent, opponent_id, gf?, ga?, result? }] */
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
            <table class="miq-club-table min-w-[36rem]">
                <thead>
                    <tr>
                        <th class="is-left">Дата</th>
                        <th class="is-left">Турнир</th>
                        <th class="is-left">Тур / стадия</th>
                        <th class="is-left">Соперник</th>
                        <th>Дома / в гостях</th>
                        <template v-if="mode === 'results'">
                            <th>Счёт</th>
                            <th title="Результат">Рез.</th>
                        </template>
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
                        <td class="is-left font-semibold text-miq-ink">{{ m.opponent }}</td>
                        <td>{{ m.venue === 'home' ? 'дома' : 'в гостях' }}</td>
                        <template v-if="mode === 'results'">
                            <td class="num-display">{{ m.gf }}:{{ m.ga }}</td>
                            <td>
                                <span class="miq-form-chip" :class="RESULT_CLASSES[m.result]" :title="RESULT_TITLES[m.result]">{{ m.result }}</span>
                            </td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
