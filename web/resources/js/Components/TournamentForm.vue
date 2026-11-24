<script setup>
const props = defineProps({
    title: { type: String, default: 'Форма команд' },
    round: { type: Number, required: true },
    /** [{ pos, team, matches: [{ round, result: В|Н|П, venue: home|away, opponent, score, date }] }] */
    teams: { type: Array, required: true },
});

const CLASSES = { В: 'is-w', Н: 'is-d', П: 'is-l' };
const NAMES = { В: 'победа', Н: 'ничья', П: 'поражение' };

function chipTitle(m) {
    const where = m.venue === 'home' ? 'дома' : 'в гостях';

    return `Тур ${m.round} · ${NAMES[m.result]} ${m.score} · ${where} · ${m.opponent}`;
}

function summary(team) {
    const count = (letter) => team.matches.filter((m) => m.result === letter).length;

    return `${count('В')} В, ${count('Н')} Н, ${count('П')} П`;
}
</script>

<template>
    <section class="miq-panel miq-tt-formboard" :aria-label="title">
        <div class="miq-panel-head">
            <h2 class="miq-panel-title">{{ title }}</h2>
            <p class="text-xs text-miq-muted">все матчи · после {{ round }} тура · слева первый тур</p>
        </div>

        <ul class="miq-tt-legend" aria-label="Обозначения">
            <li><span class="miq-form-chip is-w">В</span> победа</li>
            <li><span class="miq-form-chip is-d">Н</span> ничья</li>
            <li><span class="miq-form-chip is-l">П</span> поражение</li>
        </ul>

        <ol class="miq-tt-formlist">
            <li v-for="team in teams" :key="team.team" class="miq-tt-formrow">
                <span class="miq-tt-formrow-pos">{{ team.pos }}</span>
                <span class="miq-tt-formrow-team" :title="team.team">{{ team.team }}</span>
                <span class="miq-tt-formrow-chips" role="img" :aria-label="`${team.team}: ${summary(team)}`">
                    <span
                        v-for="m in team.matches"
                        :key="m.round"
                        class="miq-form-chip"
                        :class="CLASSES[m.result]"
                        :title="chipTitle(m)"
                    >{{ m.result }}</span>
                </span>
            </li>
        </ol>
    </section>
</template>
