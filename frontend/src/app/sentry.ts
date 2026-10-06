import * as Sentry from "@sentry/react";

type SentryInitOptions = Parameters<typeof Sentry.init>[0];

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
  };
}
