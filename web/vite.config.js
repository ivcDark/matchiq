import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
            // Host-mapped port from docker-compose (VITE_HOST_PORT); defaults to 5173.
            clientPort: Number(process.env.VITE_HOST_PORT || 5173),
        },
        watch: {
            usePolling: true,
        },
    },
});
