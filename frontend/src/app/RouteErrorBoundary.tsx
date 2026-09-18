import * as Sentry from "@sentry/react";
import { useEffect } from "react";
import { isRouteErrorResponse, useNavigate, useRouteError } from "react-router";

import { ForbiddenPage } from "@/app/ForbiddenPage";
import { OfflineScreen } from "@/app/OfflineScreen";
import { ServerErrorScreen } from "@/app/ServerErrorScreen";
import { useOnline } from "@/shared/lib/online";

/**
 * Le filet des erreurs de ROUTE — indispensable depuis le découpage en chunks (P4-6).
 *
 * `ErrorBoundary` (React) ne voit rien de ce qui casse ici : le data router attrape
 * lui-même la rejection d'un `lazy` et rend son écran par défaut — non stylé, en
 * anglais, sans issue — À LA PLACE DE TOUTE L'APPLICATION, et sans que Sentry
 * n'en sache jamais rien.
 *
 * Deux causes produisent le MÊME message navigateur (« Failed to fetch dynamically
 * imported module ») et le navigateur ne les distingue pas :
 *  - un déploiement a remplacé les `assets/*.js` hachés pendant la session ;
 *  - le réseau a lâché (mobile, wifi de gymnase, onglet hors ligne).
 *
 * Les confondre est nuisible dans les deux sens : dire « rechargez » à quelqu'un
 * hors ligne lui fait perdre le shell encore utilisable pour tomber sur la page
 * d'erreur du navigateur. On tranche donc sur l'état réseau (`useOnline`, la source
 * de vérité unique — miroir de `navigator.onLine` après le seed du boot), et on offre
 * TOUJOURS un « Réessayer » qui rejoue la navigation sans recharger le document.
 */
export function RouteErrorBoundary() {
  const error = useRouteError();
  const navigate = useNavigate();
  const chunkFailed = isChunkLoadError(error);
  // Source de vérité UNIQUE de l'état réseau (P5-14) : le même juge que la file de mutations. Après
  // le seed du boot (`main.tsx`), `useOnline()` équivaut à `navigator.onLine` à l'instant de décision.
  const offline = !useOnline();
  const staleDeploy = chunkFailed && !offline;

  useEffect(() => {
    // Console d'abord (trace lisible sans DSN), puis Sentry — no-op sans SDK.
    console.error("Route error:", error);
    Sentry.captureException(error, {
      tags: { chunkFailed: String(chunkFailed), offline: String(offline) },
    });
  }, [error, chunkFailed, offline]);

  // P5-14 — la porte 403 : un consommateur `throw`e une `Response` 403 (idiome
  // react-router). Muette sur les droits ? Non — le 403 assume le refus d'accès ;
  // c'est la 404 (refus tenant) qui reste muette.
  if (isRouteErrorResponse(error) && 403 === error.status) {
    return <ForbiddenPage />;
  }

  // Hors ligne : le chargement a échoué faute de réseau. Écran PLEINE PAGE (rien
  // derrière), pas le bandeau d'usage normal (hors lot). « Réessayer » rejoue la
  // navigation sans recharger le document.
  if (offline) {
    return <OfflineScreen onRetry={() => void navigate(0)} />;
  }

  // Déploiement pendant la session : un chunk haché a disparu. Cas à part — sa copie
  // n'est PAS dans les maquettes P5-14 (pas une 500), et son geste est « Recharger »
  // (le retry sans reload ne peut pas ramener un asset supprimé). Conservé tel quel.
  if (staleDeploy) {
    return (
      <div className="flex min-h-[70vh] flex-col items-center justify-center gap-4 p-10 text-center text-foreground">
        <h1 className="text-lg font-semibold">Une nouvelle version est disponible</h1>
        <p className="max-w-md text-sm text-muted-foreground">L'application a été mise à jour pendant votre navigation. Rechargez la page pour continuer — vos données ne sont pas perdues.</p>
        <div className="flex gap-2">
          <button type="button" onClick={() => void navigate(0)} className="rounded-md bg-accent px-4 py-2 text-sm font-medium text-accent-foreground hover:bg-accent-hover">
            Réessayer
          </button>
          <button type="button" onClick={() => window.location.reload()} className="rounded-md border border-border px-4 py-2 text-sm font-medium hover:bg-muted">
            Recharger la page
          </button>
        </div>
      </div>
    );
  }

  // Ni offline ni déploiement : erreur de route générique → l'écran 500 partagé.
  return <ServerErrorScreen onRetry={() => void navigate(0)} />;
}

/**
 * Un chunk absent se présente différemment selon le navigateur : `TypeError:
 * Failed to fetch dynamically imported module` (Chrome/Safari), `error loading
 * dynamically imported module` (Firefox), ou un `ChunkLoadError` nommé. On teste
 * le message plutôt que le type, faute d'erreur normalisée — et on ne conclut
 * PAS à un déploiement : seul `navigator.onLine` sépare les deux causes.
 */
function isChunkLoadError(error: unknown): boolean {
  if (!(error instanceof Error)) {
    return false;
  }
  const text = `${error.name} ${error.message}`.toLowerCase();

  return text.includes("chunkloaderror")
    || text.includes("failed to fetch dynamically imported module")
    || text.includes("error loading dynamically imported module");
}
