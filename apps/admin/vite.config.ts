import { fileURLToPath, URL } from 'node:url';
import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { quasar, transformAssetUrls } from '@quasar/vite-plugin';
import { swbiScope } from './tools/postcssScope';
import { mockApiPlugin } from './dev/mockApiPlugin';

/** Ship only the woff2 icon font (all supported browsers); drops the 200 KB legacy woff. */
function woff2Only() {
  return {
    name: 'swbi-woff2-only',
    enforce: 'pre' as const,
    transform(code: string, id: string) {
      if (!id.includes('material-icons-round.css')) return null;
      return code.replace(/,\s*url\([^)]*\.woff\d?\??[^)]*\)\s*format\((['"])woff\1\)/g, '');
    },
  };
}

const outDir = resolve(__dirname, '../../assets/admin');

// The mock API is a dev-server middleware only (`apply: 'serve'`). It lives in this config
// file's module graph, never in the browser bundle, so production output cannot contain it.
export default defineConfig(({ command }) => ({
  base: './',
  plugins: [
    woff2Only(),
    vue({ template: { transformAssetUrls } }),
    quasar(),
    ...(command === 'serve' ? [mockApiPlugin()] : []),
  ],
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  css: { postcss: { plugins: [swbiScope()] } },
  server: { port: 5173, host: '127.0.0.1' },
  build: {
    outDir,
    emptyOutDir: true,
    manifest: true,
    cssCodeSplit: false,
    sourcemap: false,
    target: 'es2020',
    modulePreload: { polyfill: false },
    chunkSizeWarningLimit: 900,
    rollupOptions: {
      input: { app: resolve(__dirname, 'src/main.ts') },
      output: {
        entryFileNames: 'app.js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: (info) => {
          const names = info.names ?? (info.name ? [info.name] : []);
          return names.some((n) => n.endsWith('.css'))
            ? 'app.css'
            : 'assets/[name]-[hash][extname]';
        },
      },
    },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
    css: false,
    server: { deps: { inline: ['quasar'] } },
  },
}));
