import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Fragua Real — frontend SPA (Vue 3 + TS), consume la API de Laravel vía
// Sanctum por token (no por cookie/SPA). Ver README.md de esta carpeta.
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    proxy: {
      // En desarrollo, /api apunta al backend Laravel (php artisan serve).
      // Ajusta el target si tu backend corre en otro puerto.
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
})
