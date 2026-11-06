import * as Sentry from "@sentry/react";

type SentryInitOptions = Parameters<typeof Sentry.init>[0];

/** Marqueur fixe qui remplace un jeton de page publique dans tout ce qui part à Sentry. */
const TOKEN_PLACEHOLDER = ":token";

// Séparateur de chemin, en clair ou encodé (`/login?next=%2Finvitation%2F<jeton>`).
const SEP = "(?:/|%2F)";
// Un jeton s'arrête au prochain séparateur de chemin, de query, de hash ou d'encodage — ou,
// pour une URL citée dans un TEXTE (message d'erreur), à un blanc, un guillemet ou un chevron.
const TOKEN = "[^/?#&%\\s\"'`<>]+";

/**
 * Les chemins dont un segment EST l'identité (CLAUDE.md §6 « Pages publiques à token ») —
 * déduits de `app/routes.tsx` (pages) et des appels d'API de ces pages
 * (`features/auth/api.ts`, `features/coach-wishes/publicApi.ts`). Groupe 1 = le préfixe
 * conservé, le segment qui suit est le jeton. Maison UNIQUE de cette liste.
 */
const TOKEN_PATTERNS: readonly RegExp[] = [
  // Pages : réinitialisation, vérification et changement d'e-mail, approbation, invitation.
  new RegExp(`(${SEP}(?:reset-password|verify-email|confirm-email|club-approval|invitation)${SEP})${TOKEN}`, "gi"),
  // Page doléance du coach — sauf l'aperçu authentifié `/doleances/apercu/:campaignId`.
  new RegExp(`(${SEP}doleances${SEP})(?!apercu(?:/|%2F|[?#&]|$))${TOKEN}`, "gi"),
  // API publiques : approbation de club, doléance, invitation (consultation + acceptation).
  new RegExp(`(${SEP}api${SEP}(?:club-approvals|coach-wishes${SEP}public|invitations${SEP}public)${SEP})${TOKEN}`, "gi"),
  // API d'acceptation d'invitation par un compte connecté : `/api/invitations/{token}/accept`.
  new RegExp(`(${SEP}api${SEP}invitations${SEP})(?!public(?:/|%2F))${TOKEN}(?=${SEP}accept)`, "gi"),
];

/**
 * Remplace tout jeton de page publique d'une URL (absolue ou relative, query et hash
 * compris) par {@link TOKEN_PLACEHOLDER}, le reste de l'URL intact. Fonction PURE.
 */
export function scrubTokenUrl(url: string): string {
  return TOKEN_PATTERNS.reduce((acc, pattern) => acc.replace(pattern, `$1${TOKEN_PLACEHOLDER}`), url);
}

type SentryEvent = {
  request?: { url?: string; query_string?: unknown };
  transaction?: string;
  exception?: { values?: { value?: string }[] };
};
type SentryBreadcrumb = { message?: string; data?: Record<string, unknown> };

function scrubEvent<T extends SentryEvent>(event: T): T {
  if (event.request) {
    if (typeof event.request.url === "string") event.request.url = scrubTokenUrl(event.request.url);
    if (typeof event.request.query_string === "string") {
      event.request.query_string = scrubTokenUrl(event.request.query_string);
    }
  }
  if (typeof event.transaction === "string") event.transaction = scrubTokenUrl(event.transaction);
  // Le message d'une erreur ky cite méthode + URL (« …: GET https://…/api/club-approvals/<jeton> »).
  for (const exception of event.exception?.values ?? []) {
    if (typeof exception.value === "string") exception.value = scrubTokenUrl(exception.value);
  }
  return event;
}

function scrubBreadcrumb<T extends SentryBreadcrumb>(breadcrumb: T): T {
  if (typeof breadcrumb.message === "string") breadcrumb.message = scrubTokenUrl(breadcrumb.message);
  const data = breadcrumb.data;
  if (data) {
    for (const key of ["from", "to", "url"] as const) {
      const value = data[key];
      if (typeof value === "string") data[key] = scrubTokenUrl(value);
    }
  }
  return breadcrumb;
}

/**
 * Options d'init Sentry — ERREURS uniquement (pas d'APM/replay, `tracesSampleRate: 0`),
 * extraites en fonction PURE pour être verrouillées par un test (`sentryOptions.test.ts`).
 *
 * ⚠ RGPD — en Sentry v11, l'option `sendDefaultPii` a DISPARU au profit de `dataCollection`,
 * et un `dataCollection` NON DÉFINI collecte TOUT par défaut : identité utilisateur +
 * `ip_address`, cookies, en-têtes, corps de requête/réponse, query params… — l'INVERSE du
 * défaut v10 (où un `sendDefaultPii` non défini était restrictif). On fige donc EXPLICITEMENT
 * chaque catégorie au niveau le plus restrictif pour conserver EXACTEMENT le comportement v10
 * (collecte minimale, aucune PII) — pas d'IP, pas d'utilisateur, pas de cookies/en-têtes, pas
 * de corps de requête. `frameContextLines` reste au défaut (5) : ce sont les lignes de NOTRE
 * propre source autour de la trace, nécessaires au triage, jamais une donnée de l'utilisateur.
 *
 * Jeton des pages publiques (FRT-43) : il vit DANS LE CHEMIN, que `dataCollection` ne filtre
 * pas — `beforeSend` le masque dans `request.url`/`transaction` et le message des exceptions
 * (`exception.values[].value`, une erreur ky y cite son URL), `beforeBreadcrumb` dans le
 * `message` et les navigations et fetch/xhr (`data.from`/`to`/`url`). Pas de `beforeSendTransaction` : le
 * tracing n'est pas branché (`tracesSampleRate: 0`, aucune intégration de tracing).
 */
export function buildSentryOptions(dsn: string, environment: string): SentryInitOptions {
  return {
    dsn,
    environment,
    tracesSampleRate: 0,
    dataCollection: {
      userInfo: false, // pas d'id/email/username NI d'ip_address
      cookies: false,
      httpHeaders: false,
      httpBodies: [], // aucun type de corps requête/réponse
      urlQueryParams: false,
      graphQL: { document: false, variables: false },
      genAI: { inputs: false, outputs: false },
      databaseQueryData: false,
      queues: false,
      stackFrameVariables: false,
    },
    beforeSend: (event) => scrubEvent(event),
    beforeBreadcrumb: (breadcrumb) => scrubBreadcrumb(breadcrumb),
  };
}
