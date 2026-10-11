import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// In development the API is proxied, so the SPA and API share an origin and
// Sanctum's session + XSRF cookies work without any cross-site configuration.
const api = process.env.VITE_DEV_API ?? 'http://127.0.0.1:8000';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    proxy: {
      '/api': { target: api, changeOrigin: false },
      '/sanctum': { target: api, changeOrigin: false },
    },
  },
});
