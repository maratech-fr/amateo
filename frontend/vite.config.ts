import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import fs from 'node:fs'
import path from 'node:path'

// ⚠ `tooling/`, PAS `build/` : `.gitignore` ignore `build/` (règle large visant les sorties
// de build), si bien qu'un garde posé là n'aurait jamais été versionné — vert en local,
// absent en CI. C'est exactement ce qui est arrivé au premier jet.
import { CSP_PATH, sentryCspError } from './tooling/sentryCspGuard'

// P4-65 — activer Sentry demande DEUX gestes, pas un : poser `VITE_SENTRY_DSN` ET autoriser
// son hôte d'ingestion dans la CSP. Oublier le second ne casse rien de visible — le SDK
// s'initialise, et le navigateur jette chaque envoi. Ce garde échoue le BUILD plutôt que de
// laisser découvrir le trou en cherchant une erreur de production. Inerte sans DSN.
const cspFile = path.resolve(__dirname, CSP_PATH)
const sentryProbleme = sentryCspError(
  process.env.VITE_SENTRY_DSN,
  fs.existsSync(cspFile) ? fs.readFileSync(cspFile, 'utf8') : null,
)
if (null !== sentryProbleme) {
  throw new Error(`[P4-65] ${sentryProbleme}`)
}

// https://vite.dev/config/
const config = defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    // P4-33 — le service compose `e2e` joint Vite par son nom interne : sans
    // cette entrée, le host-check de Vite répond 403 « Blocked request ».
    // Dev-only (le serveur Vite n'existe pas en prod), hostname interne au
    // réseau compose — rien d'exposé.
    allowedHosts: ['frontend-dev'],
    // Dev-only proxies — production uses Nginx
    proxy: {
      '/api': {
        target: process.env.API_PROXY_TARGET ?? 'http://127.0.0.1:8080',
        changeOrigin: true,
      },
      // P4-357 — plus de proxy `/exports` : le rendu PDF ne se télécharge que par la route
      // authentifiée `/api/schedules/{id}/export-pdf/{filename}` (proxifiée par `/api` ci-dessus).
      '/.well-known/mercure': {
        // D-15 — le défaut ignorait `MERCURE_PORT`, alors que le hub est publié sur CE port
        // (`docker-compose.yml`). Dans Docker le compose passe `MERCURE_PROXY_TARGET`, donc
        // rien ne se voyait ; sur l'hôte, un `npm run dev` proxifiait vers un port mort — la
        // SSE tombait et le polling prenait le relais, masquant la panne.
        target: process.env.MERCURE_PROXY_TARGET ?? `http://127.0.0.1:${process.env.MERCURE_PORT ?? '3000'}`,
        changeOrigin: true,
      },
      // FRT-17: no `/engine` proxy — the frontend NEVER calls the engine directly
      // (boundary §2); it goes through the backend `/api`. The dead proxy is removed.
    },
  },
})

export default config
