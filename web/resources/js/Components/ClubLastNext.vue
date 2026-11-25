<script setup>
import { shortDate } from '@/lib/matchDates';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    /** { tournament, tournament_name, stage, date, time, venue, opponent, opponent_id, match_id, gf, ga, result } | null */
    last: { type: Object, default: null },
    /** { tournament, tournament_name, stage, date, time, venue, opponent, opponent_id, match_id } | null */
    next: { type: Object, default: null },
    team: { type: String, required: true },
});

const RESULT_CLASSES = { В: 'is-w', Н: 'is-d', П: 'is-l' };
const RESULT_TITLES = { В: 'Победа', Н: 'Ничья', П: 'Поражение' };

const lastScore = computed(() => {
    if (!props.last) {
        return null;
    }
    const { gf, ga, venue } = props.last;

    return venue === 'home' ? `${gf}:${ga}` : `${ga}:${gf}`;
});

const pair = (match) => (match.venue === 'home' ? [props.team, match.opponent] : [match.opponent, props.team]);
</script>

<template>
    <section class="miq-panel" aria-label="Последний и следующий матч">
        <div class="miq-club-ln">
            <div class="miq-club-ln-cell" aria-label="Последний матч">
                <p class="miq-club-ln-label">Последний матч</p>
                <template v-if="last">
                    <p class="miq-club-ln-main">
                        <span class="miq-form-chip" :class="RESULT_CLASSES[last.result]" :title="RESULT_TITLES[last.result]">{{ last.result }}</span>
                        <component
                            :is="last.match_id ? Link : 'span'"
                            v-bind="last.match_id ? { href: route('matches.show', last.match_id) } : {}"
                            class="miq-club-ln-teams"
                            :class="{ 'miq-club-link': last.match_id }"
                        >
                            {{ pair(last)[0] }}
                            <span class="num-display miq-club-ln-score">{{ lastScore }}</span>
                            {{ pair(last)[1] }}
                        </component>
                    </p>
                    <p class="miq-club-ln-meta">
                        {{ shortDate(last.date) }} · {{ last.tournament_name }} · {{ last.stage }} ·
                        {{ last.venue === 'home' ? 'дома' : 'в гостях' }}
                    </p>
                </template>
                <p v-else class="miq-club-ln-meta">Сыгранных матчей пока нет.</p>
            </div>

            <div class="miq-club-ln-cell" aria-label="Следующий матч">
                <p class="miq-club-ln-label">Следующий матч</p>
                <template v-if="next">
                    <p class="miq-club-ln-main">
                        <span class="miq-club-ln-venue" :title="next.venue === 'home' ? 'Дома' : 'В гостях'">{{ next.venue === 'home' ? 'дома' : 'в гостях' }}</span>
                        <component
                            :is="next.match_id ? Link : 'span'"
                            v-bind="next.match_id ? { href: route('matches.show', next.match_id) } : {}"
                            class="miq-club-ln-teams"
                            :class="{ 'miq-club-link': next.match_id }"
                        >
                            {{ pair(next)[0] }} — {{ pair(next)[1] }}
                        </component>
                    </p>
                    <p class="miq-club-ln-meta">
                        {{ shortDate(next.date) }}, {{ next.time }} · {{ next.tournament_name }} · {{ next.stage }}
                    </p>
                </template>
                <p v-else class="miq-club-ln-meta">Ближайших матчей нет.</p>
            </div>
        </div>
    </section>
</template>
