import * as Sentry from "@sentry/react";
import { StrictMode } from "react";
import { createRoot } from "react-dom/client";

import { AppRouter } from "@/app/router";
import { ErrorBoundary } from "@/app/ErrorBoundary";
import { Providers } from "@/app/providers";
import { buildSentryOptions } from "@/app/sentry";
import { seedOnlineFromNavigator } from "@/shared/lib/online";
import { readPersistedThemeMode } from "@/shared/stores/themeStore";
import "@/index.css";

// Sentry ERREURS uniquement (pas d'APM/replay — quota free tier préservé). DSN
// absent = init sautée, SDK inerte. Options (dont la collecte RGPD minimale v11)
// construites par `buildSentryOptions`, verrouillées par `sentryOptions.test.ts`.
//
// ⚠ L'activer demande DEUX gestes, pas un (P4-65) : poser `VITE_SENTRY_DSN` au build ET
// autoriser l'hôte d'ingestion du DSN dans `connect-src` (`docker/frontend/csp.conf`).
// Le DSN seul initialise le SDK et la CSP jette chaque envoi EN SILENCE. Un garde de build
// refuse désormais cette combinaison (`tooling/sentryCspGuard.ts`). INF-01.
if (import.meta.env.VITE_SENTRY_DSN) {
  Sentry.init(buildSentryOptions(import.meta.env.VITE_SENTRY_DSN, import.meta.env.MODE));
}

// Apply the persisted theme class BEFORE React's first paint. Without this the
// tree renders in the light default, then useApplyTheme flips `.dark` in an
// effect — a flash of the wrong theme plus a `transition-colors` animation that
// briefly leaves surfaces at intermediate, sub-AA colours (A11Y-06). Uses the
// same predicate (=== "dark") + persisted-shape source as useApplyTheme, so the
// pre-paint class and the post-hydration class never disagree.
document.documentElement.classList.toggle("dark", "dark" === readPersistedThemeMode());

// P5-14 — SEED de l'état réseau AVANT le render : l'onlineManager de react-query naît optimiste
// (`#online = true`) et ne bascule que sur les événements window. Sans ce seed, un onglet ouvert
// DÉJÀ hors ligne se croirait en ligne (bandeau muet, mutations lancées au lieu d'être mises en file).
seedOnlineFromNavigator();

const container = document.getElementById("root");
if (!container) {
  throw new Error("Root element #root not found");
}

createRoot(container).render(
  <StrictMode>
    <ErrorBoundary>
      <Providers>
        <AppRouter />
      </Providers>
    </ErrorBoundary>
  </StrictMode>,
);
