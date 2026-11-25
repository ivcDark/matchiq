<script setup>
import PlayerAvatar from '@/Components/PlayerAvatar.vue';

defineProps({
    /** { home: [{ number, name, position, rating, sub_in }], away: [...] } */
    players: { type: Object, required: true },
    home: { type: String, required: true },
    away: { type: String, required: true },
});

function tone(rating) {
    if (rating >= 7.5) return 'is-high';
    if (rating >= 6.5) return 'is-mid';
    return 'is-low';
}
</script>

<template>
    <section class="miq-panel" aria-label="Лучшие игроки матча">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">Лучшие игроки матча</h2>
            <p class="text-[11px] font-medium text-miq-muted">рейтинг по матчу</p>
        </div>
        <div class="miq-mc-best">
            <div v-for="side in ['home', 'away']" :key="side" class="miq-mc-best-col">
                <h3 class="miq-mc-best-title">{{ side === 'home' ? home : away }}</h3>
                <ul>
                    <li v-for="player in players[side]" :key="player.number + player.name" class="miq-mc-best-row">
                        <PlayerAvatar :name="player.name" size="sm" />
                        <span class="miq-mc-best-num tabular-nums">{{ player.number }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[13px] font-semibold text-miq-ink">{{ player.name }}</span>
                            <span class="block truncate text-[11px] text-miq-muted">
                                <span v-if="player.sub_in" class="font-semibold text-miq-pitch">▲ {{ player.sub_in }}' </span>{{ player.position }}
                            </span>
                        </span>
                        <span class="miq-mc-rating tabular-nums" :class="tone(player.rating)">{{ player.rating.toFixed(1) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
