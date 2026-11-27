<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    hitRate: { type: Number, default: null },
    isPlaceholder: { type: Boolean, default: false },
});

const open = ref(false);
const hasRate = computed(() => typeof props.hitRate === 'number');
</script>

<template>
    <section class="miq-trust" aria-label="Как считаем">
        <div class="miq-trust-row">
            <div class="miq-trust-main">
                <span v-if="hasRate" class="miq-trust-rate" :title="isPlaceholder ? 'Временное значение, пока нет итоговой статистики' : undefined">
                    <span class="num-display text-lg leading-none text-miq-pitch-deep">{{ hitRate }}%</span>
                    <span class="text-xs font-semibold text-miq-ink-soft">точность модели</span>
                </span>
                <button
                    type="button"
                    class="miq-trust-toggle"
                    :aria-expanded="open"
                    @click="open = !open"
                >
                    Как мы считаем
                    <svg class="miq-trust-chevron" :class="{ 'is-open': open }" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M5 7.5l5 5 5-5" />
                    </svg>
                </button>
            </div>
            <Link :href="route('leaderboard')" class="miq-trust-cta">
                Выбери событие — попади в рейтинг
                <span aria-hidden="true">→</span>
            </Link>
        </div>
        <p v-if="open" class="miq-trust-more">
            MatchIQ оценивает вероятность исходов матча и событий по шкале 0–100. Вы выбираете одно событие до начала матча:
            угадали — получаете очки (чем менее вероятно событие, тем их больше), не угадали — 0.
        </p>
    </section>
</template>
