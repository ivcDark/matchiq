<script setup>
import UserProfile from '@/Components/UserProfile.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    profile: { type: Object, required: true },
    season: { type: Object, default: null },
    ratings: { type: Array, default: () => [] },
    championships: { type: Array, default: () => [] },
    bestPick: { type: Object, default: null },
    frequentEvents: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="`Профиль ${profile.nick}`" />

    <AppLayout>
        <UserProfile
            :profile="profile"
            :season="season"
            :ratings="ratings"
            :championships="championships"
            :best-pick="bestPick"
            :frequent-events="frequentEvents"
        />
    </AppLayout>
</template>
