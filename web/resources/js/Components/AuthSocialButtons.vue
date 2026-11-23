<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

const oauth = computed(() => page.props.oauth || { yandex: false, vk: false });

function hrefFor(provider) {
    return route('oauth.redirect', { provider });
}
</script>

<template>
    <div class="space-y-2.5">
        <div class="miq-auth-divider" role="separator">
            <span>или</span>
        </div>

        <a
            :href="hrefFor('yandex')"
            class="miq-oauth-btn is-yandex"
        >
            <svg class="miq-oauth-icon" viewBox="0 0 24 24" aria-hidden="true">
                <rect width="24" height="24" rx="4" fill="#FC3F1D" />
                <path
                    fill="#fff"
                    d="M13.1 18.5h-2.1V12.2c0-1.7-.6-2.7-2-2.7-.8 0-1.5.4-1.9 1.1v7.9H5V5.8h2.1v1.3c.6-.9 1.7-1.6 3.1-1.6 2.4 0 3.9 1.6 3.9 4.5v8.5Z"
                />
            </svg>
            <span>Войти через Яндекс ID</span>
            <span
                v-if="!oauth.yandex"
                class="miq-oauth-hint"
            >скоро</span>
        </a>

        <a
            :href="hrefFor('vk')"
            class="miq-oauth-btn is-vk"
        >
            <svg class="miq-oauth-icon" viewBox="0 0 24 24" aria-hidden="true">
                <rect width="24" height="24" rx="4" fill="#0077FF" />
                <path
                    fill="#fff"
                    d="M12.9 17.2c-4.4 0-6.9-3-7-8h2.2c.1 3.6 1.7 5.1 3 5.4V9.2h2.1v3.1c1.3-.1 2.6-1.5 3.1-3.1H16.5c-.4 2-1.7 3.5-2.4 3.9.9.4 2.3 1.7 2.8 4.1h-2.3c-.4-1.4-1.4-2.5-2.8-2.6v2.6h-.9Z"
                />
            </svg>
            <span>Войти через VK ID</span>
            <span
                v-if="!oauth.vk"
                class="miq-oauth-hint"
            >скоро</span>
        </a>
    </div>
</template>
