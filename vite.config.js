import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/scss/app.scss', 'resources/js/app.js'],
            refresh: ['resources/views/**', 'modules/**/resources/views/**', 'routes/**', 'modules/**/routes/**'],
            fonts: [
                bunny('Montserrat', { weights: [500, 600, 700] }),
                bunny('Open Sans', { weights: [400, 600, 700] }),
            ],
        }),
    ],
    css: {
        preprocessorOptions: {
            scss: { quietDeps: true, silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'mixed-decls'] },
        },
    },
    server: {
        watch: { ignored: ['**/storage/framework/views/**', '**/vendor/**', '**/reference/**'] },
    },
});
