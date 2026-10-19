import { defineConfig } from 'vite';

export default defineConfig({
    publicDir: false,
    build: {
        outDir: 'public/network-editor',
        emptyOutDir: true,
        lib: {
            entry: 'resources/js/network-editor.js',
            formats: ['es'],
            fileName: () => 'network-editor.js',
            cssFileName: 'network-editor',
        },
    },
});
