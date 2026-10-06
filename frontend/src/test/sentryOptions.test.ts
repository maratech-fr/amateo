import { describe, expect, it } from "vitest";

import { buildSentryOptions } from "@/app/sentry";

/**
 * Verrou des options d'init Sentry — RGPD + « erreurs uniquement ».
 *
 * En Sentry v11, `sendDefaultPii` a disparu : la collecte est pilotée par `dataCollection`,
 * et un `dataCollection` NON DÉFINI collecte TOUT par défaut (IP, utilisateur, cookies,
 * en-têtes, corps de requête/réponse) — l'inverse du défaut v10. Ce test garde le fait que
 * `buildSentryOptions` fige chaque catégorie au niveau le plus restrictif (collecte minimale,
 * aucune PII) et que les traces de performance restent coupées (`tracesSampleRate: 0`).
 * Un relâchement de n'importe quelle catégorie rougit ici.
 */
describe("buildSentryOptions", () => {
  const options = buildSentryOptions("https://public@example.ingest.sentry.io/42", "production");

  it("passe le DSN et l'environnement tels quels", () => {
    expect(options?.dsn).toBe("https://public@example.ingest.sentry.io/42");
    expect(options?.environment).toBe("production");
  });

  it("n'envoie AUCUNE trace de performance (erreurs uniquement)", () => {
    expect(options?.tracesSampleRate).toBe(0);
  });

  it("fige dataCollection au niveau le plus restrictif (aucune PII)", () => {
    expect(options?.dataCollection).toEqual({
      userInfo: false, // pas d'id/email/username NI d'ip_address
      cookies: false,
      httpHeaders: false,
      httpBodies: [],
      urlQueryParams: false,
      graphQL: { document: false, variables: false },
      genAI: { inputs: false, outputs: false },
      databaseQueryData: false,
      queues: false,
      stackFrameVariables: false,
    });
  });
});
