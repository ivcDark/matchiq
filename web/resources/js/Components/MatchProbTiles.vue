<script setup>
import { computed } from 'vue';
import { hasResultProbs, resultProbMaxKey } from '@/lib/matchProbs';

const props = defineProps({
    match: { type: Object, required: true },
    size: { type: String, default: 'md' },
});

const known = computed(() => hasResultProbs(props.match));
const topKey = computed(() => resultProbMaxKey(props.match));

const tiles = computed(() => [
    { key: 'home', label: 'П1', value: props.match.prob_home },
    { key: 'draw', label: 'Х', value: props.match.prob_draw },
    { key: 'away', label: 'П2', value: props.match.prob_away },
]);

const ariaLabel = computed(() => (known.value
    ? `П1 ${props.match.prob_home}% · Х ${props.match.prob_draw}% · П2 ${props.match.prob_away}%`
    : 'Прогноз появится позже'));
</script>

<template>
    <div
        class="miq-prob3"
        :class="[`miq-prob3--${size}`, { 'is-empty': !known }]"
        role="group"
        :aria-label="ariaLabel"
        :title="known ? undefined : 'Прогноз появится позже'"
    >
        <span
            v-for="t in tiles"
            :key="t.key"
            class="miq-prob3-tile"
            :class="{ 'is-top': known && topKey === t.key }"
        >
            <span class="miq-prob3-label">{{ t.label }}</span>
            <span class="miq-prob3-val">{{ known ? `${t.value}%` : '—' }}</span>
        </span>
    </div>
</template>
