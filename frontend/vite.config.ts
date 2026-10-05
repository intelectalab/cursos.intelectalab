import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
  ],
  server: {
    host: '127.0.0.1',
    // La API se consume en el mismo origen. Se conserva el Host original
    // ({organización}.localhost) para que el backend resuelva el tenant.
    proxy: {
      '/api': {
        // API_PORT permite levantar el backend en otro puerto si el 8000 está ocupado
        target: `http://127.0.0.1:${process.env.API_PORT ?? 8000}`,
        changeOrigin: false,
      },
    },
  },
})
