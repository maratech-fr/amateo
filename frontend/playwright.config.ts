import { defineConfig, devices } from '@playwright/test'

import { SUPERADMIN_STORAGE_STATE } from './tests/e2e/support-admin'

export default defineConfig({
  testDir: './tests/e2e',
  // Preflight: bring the local Docker stack up + healthy before any test (a
  // stopped messenger-worker/engine was the recurring flake, not the tests).
  globalSetup: './tests/e2e/global-setup.ts',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: process.env.E2E_BASE_URL || 'http://localhost:8081',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    // Ouvre UNE session superadmin par run et la fige dans un storageState
    // (superadmin.setup.ts) — voir le POURQUOI (quota admin_auth) dans ce fichier.
    {
      name: 'setup',
      testMatch: /superadmin\.setup\.ts$/,
    },
    // Les specs superadmin réutilisent la session figée : zéro login propre. D'où
    // `retries: 0` — un retry ici ne rejouerait AUCUN login (il repart du même
    // storageState), il ne gagnerait rien et masquerait un vrai échec. Les 2 retries
    // restent pour les specs club (projet `chromium`, qui hérite de la racine).
    {
      name: 'superadmin',
      testMatch: /modal-reachability\.spec\.ts$/,
      dependencies: ['setup'],
      retries: 0,
      use: { ...devices['Desktop Chrome'], storageState: SUPERADMIN_STORAGE_STATE },
    },
    // Tout le reste — session club propre à chaque test, `retries: 2` en CI.
    {
      name: 'chromium',
      testIgnore: /(superadmin\.setup|modal-reachability\.spec)\.ts$/,
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
