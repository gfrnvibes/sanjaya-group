import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                    optimizedFallbacks: false,
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            // vendor/ & storage/ tidak perlu dipantau; di drive exFAT watcher mengunci folder
            // sehingga composer require dan folder sementara backup (storage/app/backup-temp) gagal dihapus.
            ignored: ['**/storage/**', '**/vendor/**'],
        },
    },
});
