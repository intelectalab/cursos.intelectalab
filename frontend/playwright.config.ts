import { defineConfig, devices } from '@playwright/test';

// Recorridos de punta a punta contra una academia ya levantada (web + API).
// No arrancan servidores: se indica dónde está con E2E_BASE_URL.
//   E2E_BASE_URL     dirección de la academia (por defecto, la demo local)
//   E2E_ADMIN_EMAIL  cuenta de administrador de esa academia
//   E2E_PASSWORD     su contraseña (obligatoria; no se guarda en el repositorio)
export default defineConfig({
  testDir: './e2e',
  // Comparten una academia y sus contadores de peticiones: uno a la vez
  workers: 1,
  fullyParallel: false,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://demo.localhost:5173',
    locale: 'es-PE',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
