/**
 * P4-65 — poser `VITE_SENTRY_DSN` ne suffit PAS à activer Sentry.
 *
 * Le SDK n'émet que si la CSP l'autorise : `docker/frontend/csp.conf` doit porter l'hôte
 * d'ingestion du DSN dans `connect-src` (l'org du fondateur y est posée). Sans cet hôte, le
 * navigateur refuse chaque envoi. Or **rien ne le dit** : le build passe, le
 * SDK s'initialise, l'application paraît instrumentée, et les erreurs ne partent jamais. On
 * le découvrirait le jour où on cherche une erreur de production — c'est-à-dire au pire
 * moment.
 *
 * Ce garde transforme cette panne silencieuse en **échec de build**. Il ne se déclenche que
 * si un DSN est posé : sans DSN, Sentry est inerte et il n'y a rien à vérifier.
 *
 * ⚠ La vérification cherche l'hôte **n'importe où** dans le fichier, pas seulement dans
 * `connect-src`. Volontaire : parser la directive rendrait le garde fragile (sauts de
 * ligne, ordre, guillemets) alors que sa valeur est de rappeler un oubli. Un hôte présent
 * dans ce fichier y a été mis exprès.
 */

/** Chemin du fichier de CSP, relatif à `frontend/`. Copié dans l'image de build (Dockerfile). */
export const CSP_PATH = '../docker/frontend/csp.conf';

/**
 * Rend le message d'erreur à lever, ou `null` si tout va bien.
 *
 * @param dsn         valeur de `VITE_SENTRY_DSN` (souvent absente)
 * @param cspContent  contenu de `csp.conf`, ou `null` s'il est illisible
 */
export function sentryCspError(dsn: string | undefined, cspContent: string | null): string | null {
  const value = (dsn ?? '').trim();
  if ('' === value) {
    // Pas de DSN = Sentry inerte. Rien à garder.
    return null;
  }

  let host: string;
  try {
    host = new URL(value).host;
  } catch {
    return `VITE_SENTRY_DSN n'est pas une URL exploitable (${value.slice(0, 24)}…) — impossible d'en déduire l'hôte d'ingestion à autoriser dans ${CSP_PATH}.`;
  }

  if ('' === host) {
    return `VITE_SENTRY_DSN ne porte aucun hôte — impossible de déduire l'hôte d'ingestion à autoriser dans ${CSP_PATH}.`;
  }

  if (null === cspContent) {
    // On ne peut pas vérifier : échouer plutôt que laisser croire que c'est bon. Poser un
    // DSN est un geste délibéré, il mérite une confirmation, pas un silence.
    return `VITE_SENTRY_DSN est posé mais ${CSP_PATH} est illisible : impossible de vérifier que « ${host} » est autorisé. Sans cette autorisation, Sentry ne recevrait rien.`;
  }

  if (cspContent.includes(host)) {
    return null;
  }

  return [
    `VITE_SENTRY_DSN est posé, mais « ${host} » n'apparaît pas dans ${CSP_PATH}.`,
    `La CSP déclare « connect-src 'self' blob: » : le navigateur BLOQUERAIT chaque envoi, en silence.`,
    `Ajoutez cet hôte à la directive connect-src dans le même changement que le DSN.`,
  ].join('\n');
}
