import type { Breadcrumb, ErrorEvent, EventHint } from "@sentry/react";
import { describe, expect, it } from "vitest";

import { buildSentryOptions, scrubTokenUrl } from "@/app/sentry";

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

/**
 * FRT-43 — le jeton des pages publiques (il EST l'identité, CLAUDE.md §6) vit dans le CHEMIN
 * de l'URL, que `dataCollection` ne filtre pas. Ces tests appellent les VRAIS `beforeSend` /
 * `beforeBreadcrumb` rendus par `buildSentryOptions` : retirer le nettoyage de la prod rougit.
 * Jetons factices uniquement.
 */
describe("buildSentryOptions — masquage du jeton des pages publiques", () => {
  const TOK = "tok-test-0000";
  const options = buildSentryOptions("https://public@example.ingest.sentry.io/42", "production");

  function send(event: Record<string, unknown>): Record<string, unknown> {
    const beforeSend = options?.beforeSend;
    if (!beforeSend) throw new Error("beforeSend absent");
    return beforeSend(event as unknown as ErrorEvent, {} as EventHint) as unknown as Record<string, unknown>;
  }

  function crumb(breadcrumb: Record<string, unknown>): Record<string, unknown> {
    const beforeBreadcrumb = options?.beforeBreadcrumb;
    if (!beforeBreadcrumb) throw new Error("beforeBreadcrumb absent");
    return beforeBreadcrumb(breadcrumb as Breadcrumb) as unknown as Record<string, unknown>;
  }

  it.each([
    [`https://app.example.test/doleances/${TOK}`, "https://app.example.test/doleances/:token"],
    [`https://app.example.test/club-approval/${TOK}?ref=mail#top`, "https://app.example.test/club-approval/:token?ref=mail#top"],
    [`/invitation/${TOK}#form`, "/invitation/:token#form"],
    [`/reset-password/${TOK}`, "/reset-password/:token"],
    [`/verify-email/${TOK}?x=1`, "/verify-email/:token?x=1"],
    [`/confirm-email/${TOK}`, "/confirm-email/:token"],
    [`/login?next=%2Finvitation%2F${TOK}`, "/login?next=%2Finvitation%2F:token"],
  ])("beforeSend masque le jeton de request.url (%s)", (url, expected) => {
    const out = send({ request: { url }, transaction: url });
    expect(out.request).toEqual({ url: expected });
    expect(out.transaction).toBe(expected);
    expect(JSON.stringify(out)).not.toContain(TOK);
  });

  it("beforeSend laisse intacte une URL sans jeton (aperçu authentifié compris)", () => {
    const url = "https://app.example.test/doleances/apercu/42?tab=1#x";
    expect(send({ request: { url } }).request).toEqual({ url });
    expect(send({ request: { url: "/planning?week=2026-11-02" } }).request).toEqual({ url: "/planning?week=2026-11-02" });
  });

  it("beforeBreadcrumb masque le jeton d'une navigation (from ET to)", () => {
    const out = crumb({ category: "navigation", data: { from: "/login", to: `/doleances/${TOK}?step=2` } });
    expect(out.data).toEqual({ from: "/login", to: "/doleances/:token?step=2" });
  });

  it.each([
    [`https://app.example.test/api/coach-wishes/public/${TOK}`, "https://app.example.test/api/coach-wishes/public/:token"],
    [`/api/club-approvals/${TOK}`, "/api/club-approvals/:token"],
    [`/api/invitations/public/${TOK}/accept`, "/api/invitations/public/:token/accept"],
    [`/api/invitations/${TOK}/accept`, "/api/invitations/:token/accept"],
  ])("beforeBreadcrumb masque le jeton d'un fetch vers l'API publique (%s)", (url, expected) => {
    const out = crumb({ category: "fetch", data: { method: "POST", url, status_code: 200 } });
    expect(out.data).toEqual({ method: "POST", url: expected, status_code: 200 });
  });

  it("beforeSend masque le jeton cité dans le message d'une erreur ky, le reste intact", () => {
    const value = `Request failed with status code 404 Not Found: GET https://exemple.test/api/club-approvals/${TOK}`;
    const out = send({ exception: { values: [{ type: "HTTPError", value }] } });
    expect(out.exception).toEqual({
      values: [{ type: "HTTPError", value: "Request failed with status code 404 Not Found: GET https://exemple.test/api/club-approvals/:token" }],
    });
  });

  it("beforeSend masque un jeton cité entre guillemets au milieu d'un texte", () => {
    const value = `Échec du chargement de "/doleances/${TOK}" (réseau)`;
    const out = send({ exception: { values: [{ value }] } });
    expect(out.exception).toEqual({ values: [{ value: 'Échec du chargement de "/doleances/:token" (réseau)' }] });
  });

  it("beforeSend laisse intact un message d'exception sans URL", () => {
    const value = "Cannot read properties of undefined (reading 'id')";
    expect(send({ exception: { values: [{ value }] } }).exception).toEqual({ values: [{ value }] });
  });

  it("beforeBreadcrumb masque le jeton du message d'un breadcrumb, le reste intact", () => {
    const message = `GET https://exemple.test/api/invitations/public/${TOK} 404`;
    expect(crumb({ category: "console", message }).message).toBe("GET https://exemple.test/api/invitations/public/:token 404");
  });

  it("beforeBreadcrumb laisse intact un message sans URL", () => {
    expect(crumb({ category: "console", message: "rendu terminé" }).message).toBe("rendu terminé");
  });

  it("scrubTokenUrl est idempotent (un événement repassé ne s'abîme pas)", () => {
    const once = scrubTokenUrl(`/api/invitations/${TOK}/accept`);
    expect(scrubTokenUrl(once)).toBe(once);
  });

  it("beforeBreadcrumb laisse intacte une API authentifiée sans jeton", () => {
    const url = "/api/invitations/17/resend";
    expect(crumb({ category: "xhr", data: { url } }).data).toEqual({ url });
  });
});
