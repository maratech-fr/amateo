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

// Profondeur maximale du parcours récursif (au-delà, la valeur part telle quelle).
const MAX_SCRUB_DEPTH = 6;

/**
 * Parcours RÉCURSIF d'une valeur quelconque : une chaîne revient nettoyée ; un tableau ou un
 * objet est nettoyé EN PLACE (propriétés énumérables, plus `message`/`stack` d'une `Error`,
 * non énumérables) et revient tel quel. Profondeur bornée, cycles ignorés (WeakSet), ne lève
 * jamais : une propriété non inscriptible ou un getter qui lève est sauté, l'envoi continue.
 */
function scrubDeep(value: unknown, depth = 0, seen = new WeakSet<object>()): unknown {
  if (typeof value === "string") return scrubTokenUrl(value);
  if (value === null || typeof value !== "object" || depth >= MAX_SCRUB_DEPTH || seen.has(value)) return value;
  seen.add(value);
  try {
    const target = value as Record<string, unknown>;
    const keys = new Set(Object.keys(target));
    for (const key of ["message", "stack"]) {
      if (typeof target[key] === "string") keys.add(key);
    }
    for (const key of keys) {
      try {
        const current = target[key];
        const next = scrubDeep(current, depth + 1, seen);
        if (next !== current) target[key] = next;
      } catch {
        // propriété non inscriptible ou getter qui lève : on la saute, sans casser l'envoi
      }
    }
  } catch {
    // objet exotique (proxy…) dont l'énumération lève : laissé tel quel
  }
  return value;
}

type SentryEvent = {
  request?: { url?: string; query_string?: unknown };
  transaction?: string;
  message?: string;
  exception?: { values?: { value?: string }[] };
  logentry?: unknown;
  extra?: unknown;
  contexts?: unknown;
  tags?: unknown;
  breadcrumbs?: unknown;
};
type SentryBreadcrumb = { message?: string; data?: Record<string, unknown> };

function scrubEvent<T extends SentryEvent>(event: T): T {
  if (typeof event.message === "string") event.message = scrubTokenUrl(event.message);
  // Nettoyés en place : `logentry.message`/`.params`, `extra` (dont `__serialized__`),
  // `contexts`, `tags`, et les breadcrumbs déjà embarqués dans l'événement.
  for (const part of [event.logentry, event.extra, event.contexts, event.tags, event.breadcrumbs]) {
    scrubDeep(part);
  }
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
  // TOUT `data` : `from`/`to`/`url` des navigations et fetch/xhr, mais aussi `arguments` d'un
  // breadcrumb console (`console.error("Route error:", error)` y range l'erreur ky et son URL).
  scrubDeep(breadcrumb.data);
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
 * (`exception.values[].value`, une erreur ky y cite son URL), ainsi que, par parcours récursif,
 * `message`, `logentry`, `extra`, `contexts`, `tags` et les breadcrumbs embarqués ;
 * `beforeBreadcrumb` dans le `message` et TOUT `data` (navigations, fetch/xhr, `arguments` console). Pas de `beforeSendTransaction` : le
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
