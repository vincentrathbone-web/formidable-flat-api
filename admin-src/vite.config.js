import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';

// Builds a single IIFE bundle (admin.js + admin.css) into ../dist, enqueued by
// class-flat-api-admin.php via wp_enqueue_script/wp_enqueue_style. No dev server is
// used in production — `npm run build` runs locally before packaging a release (see
// ../package.ps1 and ../CLAUDE.md "Admin UI build step").
export default defineConfig({
  plugins: [svelte()],
  build: {
    outDir: '../dist',
    emptyOutDir: true,
    sourcemap: false,
    lib: {
      entry: 'src/main.js',
      name: 'FFAPIAdmin',
      formats: ['iife'],
      fileName: () => 'admin.js',
    },
    rollupOptions: {
      output: {
        assetFileNames: 'admin.[ext]',
      },
    },
  },
});
