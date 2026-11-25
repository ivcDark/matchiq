import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    // Компонентные классы страницы клуба из @layer components: без safelist они
    // выпадают из CSS, если dev-сервер Vite отдал кеш до появления новых .vue.
    safelist: [{ pattern: /^miq-club-/ }],

    theme: {
        extend: {
            colors: {
                miq: {
                    ink: 'var(--miq-ink)',
                    'ink-soft': 'var(--miq-ink-soft)',
                    muted: 'var(--miq-muted)',
                    pitch: 'var(--miq-pitch)',
                    'pitch-deep': 'var(--miq-pitch-deep)',
                    lime: 'var(--miq-lime)',
                    mist: 'var(--miq-mist)',
                    'mist-deep': 'var(--miq-mist-deep)',
                    chalk: 'var(--miq-chalk)',
                    line: 'var(--miq-line)',
                    warn: 'var(--miq-warn)',
                    live: 'var(--miq-live)',
                },
            },
            fontFamily: {
                sans: ['Manrope', ...defaultTheme.fontFamily.sans],
                display: ['Syne', ...defaultTheme.fontFamily.sans],
            },
            maxWidth: {
                home: '72rem',
            },
        },
    },

    plugins: [forms],
};
