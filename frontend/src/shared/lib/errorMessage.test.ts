import { HTTPError, TimeoutError } from "ky";
import { describe, expect, it } from "vitest";

import { errorMessage } from "./errorMessage";

function httpError(status: number, body?: unknown, requestId?: string): HTTPError {
  const headers: Record<string, string> = { "content-type": "application/json" };
  if (requestId !== undefined) {
    headers["X-Request-Id"] = requestId;
  }
  const response = new Response(body === undefined ? null : JSON.stringify(body), {
    status,
    headers,
  });
  const request = new Request("http://localhost/api/teams");
  const error = new HTTPError(response, request, {} as never);
  // ky 2.x consumes the response stream itself and exposes the parsed body as
  // error.data before any consumer runs — mirror that contract.
  (error as unknown as { data?: unknown }).data = body;
  return error;
}

describe("errorMessage", () => {
  it("prefers a server-provided message", async () => {
    expect(await errorMessage(httpError(422, { error: "Nom déjà pris" }))).toBe("Nom déjà pris");
    expect(await errorMessage(httpError(400, { message: "Requête invalide" }))).toBe("Requête invalide");
  });

  it("joins API Platform violations", async () => {
    const msg = await errorMessage(
      httpError(422, { violations: [{ message: "sessionsPerWeek doit être positif" }, { message: "matchDay hors bornes" }] }),
    );
    expect(msg).toBe("sessionsPerWeek doit être positif · matchDay hors bornes");
  });

  it("falls back to a French status sentence when no body message", async () => {
    expect(await errorMessage(httpError(403))).toBe("Accès refusé.");
    expect(await errorMessage(httpError(404))).toBe("Ressource introuvable.");
    expect(await errorMessage(httpError(409))).toBe("Conflit : l'action n'a pas pu être effectuée.");
    expect(await errorMessage(httpError(422))).toBe("Données invalides. Vérifiez votre saisie.");
    expect(await errorMessage(httpError(500))).toBe("Erreur serveur. Réessayez plus tard.");
  });

  /**
   * AUD-FRT-18 — 401 et 429 tombaient dans le repli « Une erreur est survenue (429) ».
   *
   * Le backend émet les deux pour de bon (`AuthController:322,453` ; SEC-11, gardé par
   * `ApiRateLimitTest`). Un nombre ne dit ni ce qui s'est passé, ni quoi faire — et sur
   * 429 il pousse même à RE-CLIQUER, ce qui prolonge la fenêtre de blocage.
   *
   * Le test épingle le SENS (se reconnecter / patienter), pas la phrase exacte : la
   * formulation peut évoluer, la conduite à tenir non.
   */
  it("dit quoi faire sur une session expirée et sur un throttle", async () => {
    expect(await errorMessage(httpError(401))).toMatch(/reconnect/i);
    expect(await errorMessage(httpError(401))).not.toMatch(/401/);
    expect(await errorMessage(httpError(429))).toMatch(/patientez/i);
    expect(await errorMessage(httpError(429))).not.toMatch(/429/);
  });

  // NR P4-5 / SEC-08 — un 5xx ne doit JAMAIS reprendre la chaîne du serveur :
  // elle n'est pas actionnable et peut porter des détails internes.
  it("n'expose jamais le message serveur d'une erreur 5xx", async () => {
    expect(await errorMessage(httpError(500, { detail: 'SQLSTATE[42501]: table "club_user"' }))).toBe("Erreur serveur. Réessayez plus tard.");
    expect(await errorMessage(httpError(502, { error: "upstream engine unreachable at engine:8000" }))).toBe("Erreur serveur. Réessayez plus tard.");
    expect(await errorMessage(httpError(503, { violations: [{ message: "connexion Doctrine perdue" }] }))).toBe("Erreur serveur. Réessayez plus tard.");
    // Contre-épreuve : côté 4xx, le message métier reste affiché tel quel.
    expect(await errorMessage(httpError(409, { error: "Une collecte existe déjà pour cette période." }))).toBe("Une collecte existe déjà pour cette période.");
  });

  /**
   * P5-11 — un 5xx joint une référence d'incident (les 8 premiers caractères du
   * X-Request-Id de la réponse) : le gestionnaire peut la communiquer au support,
   * qui la retrouve dans les logs corrélés. ABSENTE des 4xx (erreurs métier, pas
   * d'incident serveur à tracer) et absente si le header manque.
   */
  it("joint une référence d'incident sur un 5xx porteur d'un X-Request-Id", async () => {
    const msg = await errorMessage(httpError(500, undefined, "abcd1234-5678-4abc-8def-000000000000"));
    expect(msg).toContain("réf. incident : abcd1234");
    expect(msg).not.toContain("5678"); // 8 premiers caractères seulement
  });

  it("n'ajoute pas de référence d'incident sur un 4xx ni sans header", async () => {
    expect(await errorMessage(httpError(409, undefined, "abcd1234-5678-4abc-8def-000000000000"))).not.toContain(
      "réf. incident",
    );
    expect(await errorMessage(httpError(500))).not.toContain("réf. incident");
  });

  it("handles timeouts and network errors", async () => {
    expect(await errorMessage(new TimeoutError(new Request("http://x")))).toBe("La requête a expiré. Réessayez.");
    expect(await errorMessage(new Error("Failed to fetch"))).toBe("Problème de connexion. Vérifiez votre réseau.");
    expect(await errorMessage("weird")).toBe("Une erreur est survenue.");
  });
});

describe("errorMessage — filet status-text anglais (P4-263)", () => {
  // P4-263 — le backend francise les messages ATTEIGNABLES, mais un 4xx nu de
  // Symfony/API Platform (NotFoundHttpException sans message, garde de contrat)
  // peut encore porter la reason-phrase anglaise brute (« Not Found », « Forbidden »…).
  // Ce n'est pas une copie écrite pour l'utilisateur : le filet l'ignore et tombe sur
  // le repli FR par statut. Liste FERMÉE (reason-phrases HTTP standard) — pas une
  // heuristique « ça a l'air anglais ».
  it("remplace un status-text anglais brut par le repli FR du statut", async () => {
    expect(await errorMessage(httpError(404, { detail: "Not Found" }))).toBe("Ressource introuvable.");
    expect(await errorMessage(httpError(403, { error: "Forbidden" }))).toBe("Accès refusé.");
    expect(await errorMessage(httpError(400, { message: "Bad Request" }))).toBe("Requête invalide.");
    expect(await errorMessage(httpError(409, { error: "Conflict" }))).toBe("Conflit : l'action n'a pas pu être effectuée.");
    expect(await errorMessage(httpError(422, { detail: "Unprocessable Content" }))).toBe("Données invalides. Vérifiez votre saisie.");
    expect(await errorMessage(httpError(422, { detail: "Unprocessable Entity" }))).toBe("Données invalides. Vérifiez votre saisie.");
  });

  it("ignore le status-text même en 401/429 (repli parlant, pas le brut anglais)", async () => {
    expect(await errorMessage(httpError(401, { message: "Unauthorized" }))).toMatch(/reconnect/i);
    expect(await errorMessage(httpError(429, { error: "Too Many Requests" }))).toMatch(/patientez/i);
  });

  it("reconnaît le status-text quelle que soit la casse ou l'espacement", async () => {
    expect(await errorMessage(httpError(404, { detail: "not found" }))).toBe("Ressource introuvable.");
    expect(await errorMessage(httpError(403, { error: "  Forbidden  " }))).toBe("Accès refusé.");
  });

  it("laisse TOUJOURS passer un message métier français (le filet ne l'attrape pas)", async () => {
    expect(await errorMessage(httpError(404, { detail: "Cette période n'existe plus — rechargez le calendrier." }))).toBe(
      "Cette période n'existe plus — rechargez le calendrier.",
    );
    // Le refus de connexion francisé par le backend passe tel quel — jamais le repli « Session expirée ».
    expect(await errorMessage(httpError(401, { message: "Identifiants invalides." }))).toBe("Identifiants invalides.");
  });

  it("a un repli FR pour chaque statut de la liste fermée, dont 405", async () => {
    expect(await errorMessage(httpError(405, { error: "Method Not Allowed" }))).toBe("Action non autorisée.");
    expect(await errorMessage(httpError(405))).toBe("Action non autorisée.");
  });
});
