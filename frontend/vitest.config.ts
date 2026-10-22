import { defineConfig } from 'vitest/config';

// Pruebas unitarias (npm test). Los recorridos de punta a punta de e2e/ los corre Playwright.
export default defineConfig({
  test: {
    include: ['src/**/*.test.ts'],
    environment: 'node',
  },
});
