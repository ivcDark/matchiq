<script setup>
import { computed, ref } from 'vue';
import MotmStar from '@/Components/MotmStar.vue';

const props = defineProps({
    /** { home: { formation, coach, players[11], substitutes[] }, away: {...} } */
    lineups: { type: Object, required: true },
    home: { type: String, required: true },
    away: { type: String, required: true },
});

const LINES = { gk: 'ВРТ', def: 'ЗАЩ', mid: 'ПЗ', fwd: 'НАП' };

const TABS = [
    { id: 'starters', label: 'Стартовый состав' },
    { id: 'bench', label: 'Запасные' },
];

const tab = ref('starters');

const teams = computed(() => [
    { side: 'home', name: props.home, lineup: props.lineups.home },
    { side: 'away', name: props.away, lineup: props.lineups.away },
]);

const count = (id) => props.lineups.home[id === 'starters' ? 'players' : 'substitutes']?.length ?? 0;
</script>

<template>
    <section class="miq-panel" aria-label="Составы команд">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">Составы команд</h2>
            <p class="text-[11px] font-medium text-miq-muted">старт · запасные · тренер</p>
        </div>

        <div class="miq-mc-tabs" role="tablist" aria-label="Состав команд">
            <button
                v-for="t in TABS"
                :key="t.id"
                type="button"
                role="tab"
                class="miq-mc-tab"
                :class="{ 'is-active': tab === t.id }"
                :aria-selected="tab === t.id"
                @click="tab = t.id"
            >
                {{ t.label }}
                <span class="miq-mc-tab-count tabular-nums">{{ count(t.id) }}</span>
            </button>
        </div>

        <div class="miq-mc-squads">
            <div v-for="team in teams" :key="team.side" class="miq-mc-squad" :class="`is-${team.side}`">
                <div class="miq-mc-squad-head">
                    <span class="truncate font-semibold">{{ team.name }}</span>
                    <span class="text-xs opacity-80">{{ team.lineup.formation }}</span>
                </div>

                <ul v-if="tab === 'starters'" role="tabpanel" aria-label="Стартовый состав">
                    <li v-for="p in team.lineup.players" :key="`s-${p.number}`" class="miq-mc-bench-row">
                        <span class="miq-mc-best-num tabular-nums">{{ p.number }}</span>
                        <span class="min-w-0 flex-1 truncate text-[13px] font-semibold text-miq-ink">{{ p.name }}</span>
                        <MotmStar v-if="p.motm" />
                        <span v-if="p.sub_out" class="text-[11px] font-semibold text-miq-warn tabular-nums" :title="`Замена ${p.sub_out}'`">▼ {{ p.sub_out }}'</span>
                        <span v-if="p.red" class="miq-mc-card is-red" title="Красная карточка" />
                        <span v-else-if="p.yellow" class="miq-mc-card is-yellow" title="Жёлтая карточка" />
                        <span class="miq-mc-squad-line">{{ LINES[p.line] }}</span>
                    </li>
                </ul>

                <ul v-else role="tabpanel" aria-label="Запасные">
                    <li v-for="p in team.lineup.substitutes" :key="`b-${p.number}`" class="miq-mc-bench-row">
                        <span class="miq-mc-best-num tabular-nums">{{ p.number }}</span>
                        <span class="min-w-0 flex-1 truncate text-[13px] font-semibold text-miq-ink">{{ p.name }}</span>
                        <MotmStar v-if="p.motm" />
                        <span v-if="p.sub_in" class="text-[11px] font-semibold text-miq-pitch tabular-nums" :title="`Вышел на ${p.sub_in}'`">▲ {{ p.sub_in }}'</span>
                        <span class="miq-mc-squad-line">{{ LINES[p.line] }}</span>
                    </li>
                </ul>

                <p class="miq-mc-bench-title">Тренер</p>
                <p class="miq-mc-squad-coach">{{ team.lineup.coach }}</p>
            </div>
        </div>
    </section>
</template>
