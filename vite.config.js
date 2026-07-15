import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/pages/auth.js',
                'resources/js/pages/onboarding.js',
                'resources/js/pages/telegram.js',
                'resources/js/pages/atualizacoes.js',
                'resources/js/pages/registrar-gasto.js',
                'resources/js/pages/categorias.js',
                'resources/js/pages/chat.js',
                'resources/js/pages/configuracoes.js',
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
