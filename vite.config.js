import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // WP-10: GrapesJS template builder (loaded only on the builder page)
                'resources/js/template-builder.js',
                // F-2.4.2-001: automation canvas (Alpine only, no new packages)
                'resources/js/automation-canvas.js',
                // F-2.13-003: CRM pipeline board (Alpine only, no new packages)
                'resources/js/crm-board.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
