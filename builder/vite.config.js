import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
  plugins: [react()],
  root: path.resolve(__dirname, 'assets'),
  /* Chemins relatifs dans le CSS (url ./fa-*.woff2) : compat basePath Symfony / sous-dossier + évite polices FA en 404. */
  base: './',
  build: {
    outDir: path.resolve(__dirname, 'public/build'),
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: path.resolve(__dirname, 'assets/main.jsx'),
    },
  },
  server: {
    port: 5173,
    strictPort: true,
  },
});
