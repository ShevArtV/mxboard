import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

export default defineConfig({
    plugins: [vue()],
    build: {
        outDir: 'vue-dist',
        emptyOutDir: true,
        rollupOptions: {
            // Vue/Pinia/PrimeVue и composables приходят из Import Map пакета
            // VueTools — НЕ бандлим их, в выхлопе остаётся только код приложения.
            external: ['vue', 'pinia', 'primevue', /^@vuetools\//],
            input: {
                board: resolve(__dirname, 'main-board.js'),
            },
            output: {
                entryFileNames: '[name].min.js',
                chunkFileNames: '[name].min.js',
                assetFileNames: (assetInfo) => {
                    if (assetInfo.name && assetInfo.name.endsWith('.css')) {
                        return 'board.min.css';
                    }
                    return '[name].[ext]';
                },
                // Один файл (без вендор-сплита): имя статичное, cache-bust по ?v=mtime.
                inlineDynamicImports: true,
            },
        },
    },
});
