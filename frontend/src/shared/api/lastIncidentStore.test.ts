import { afterEach, describe, expect, it } from "vitest";

import { clearLastIncident, readRecentIncident, readRecentIncidentRequestId, recordIncident } from "./lastIncidentStore";
import * as incidentStore from "./lastIncidentStore";

const TEN_MINUTES_MS = 10 * 60 * 1000;

afterEach(() => {
  clearLastIncident();
});

describe("lastIncidentStore", () => {
  it("retient le dernier incident serveur en entier (statut, URL, code, request-id)", () => {
    recordIncident({ status: 500, url: "/api/teams", code: "boom", requestId: "req-abc" }, 1_000);
    expect(readRecentIncident(1_000)).toEqual({ status: 500, url: "/api/teams", code: "boom", requestId: "req-abc", at: 1_000 });
  });

  it("le rend tant qu'il date de moins de 10 minutes", () => {
    recordIncident({ status: 500, url: "/api/teams", requestId: "req-abc" }, 1_000);
    expect(readRecentIncident(1_000 + TEN_MINUTES_MS - 1)?.requestId).toBe("req-abc");
  });

  // ⚠ Garde de fraîcheur : un incident vieux de plus de 10 min ne colle plus au geste
  // de l'utilisateur. Falsification prévue : retirer ce garde rend ce test rouge.
  it("l'ignore une fois passé 10 minutes", () => {
    recordIncident({ status: 500, url: "/api/teams", requestId: "req-abc" }, 1_000);
    expect(readRecentIncident(1_000 + TEN_MINUTES_MS + 1)).toBeNull();
  });

  it("renvoie null quand aucun incident n'a été enregistré", () => {
    expect(readRecentIncident(50_000)).toBeNull();
  });

  it("ne garde que le dernier incident", () => {
    recordIncident({ status: 500, url: "/api/a", requestId: "req-1" }, 1_000);
    recordIncident({ status: 502, url: "/api/b", requestId: "req-2" }, 2_000);
    expect(readRecentIncident(2_000)?.requestId).toBe("req-2");
  });

  // P4-129 — le 502 nginx SANS X-Request-Id est bien retenu (statut + URL) : c'est
  // l'incident déclencheur, que l'ancien rail (qui exigeait un request-id) perdait.
  it("retient un incident SANS request-id (le 502 nginx déclencheur)", () => {
    recordIncident({ status: 502, url: "/api/generate" }, 1_000);
    const incident = readRecentIncident(1_000);
    expect(incident).toMatchObject({ status: 502, url: "/api/generate" });
    expect(incident?.requestId).toBeUndefined();
  });

  describe("readRecentIncidentRequestId (wrapper compat P5-6)", () => {
    it("rend le request-id d'un incident frais qui en porte un", () => {
      recordIncident({ status: 500, url: "/api/teams", requestId: "req-abc" }, 1_000);
      expect(readRecentIncidentRequestId(1_000)).toBe("req-abc");
    });

    it("rend null quand l'incident frais n'a PAS de request-id", () => {
      recordIncident({ status: 502, url: "/api/generate" }, 1_000);
      expect(readRecentIncidentRequestId(1_000)).toBeNull();
    });

    it("rend null au-delà de la fenêtre de fraîcheur", () => {
      recordIncident({ status: 500, url: "/api/teams", requestId: "req-abc" }, 1_000);
      expect(readRecentIncidentRequestId(1_000 + TEN_MINUTES_MS + 1)).toBeNull();
    });

    it("rend null sans aucun incident", () => {
      expect(readRecentIncidentRequestId(50_000)).toBeNull();
    });
  });

  /**
   * D17 (PR B, essai fondateur 30/10) — `recentErrors` : les 5 dernières erreurs API ≥ 400, écrites
   * par `client.ts`, jointes au contexte de signalement du LINK. Ring buffer (pas un seul incident),
   * chaque entrée {method, route, status, code, errors[], requestId, at}. `readRecentIncident`
   * (≥ 500, bloc dev) reste INCHANGÉ — les tests ci-dessus le PROUVENT verts. ROUGE aujourd'hui :
   * `readRecentIncidents` n'existe pas (le store ne retient qu'UN incident, sans method/route/errors).
   */
  describe("recentErrors — ring buffer des 5 dernières erreurs ≥ 400 (D17)", () => {
    it("expose readRecentIncidents, bornée aux 5 plus récentes, avec method/route/status/code/errors[]/requestId", () => {
      expect(incidentStore.readRecentIncidents, "D17 : un lecteur ring-buffer des erreurs récentes").toBeDefined();

      for (let i = 1; i <= 6; i++) {
        recordIncident({ status: 400 + i, url: `/api/x${i}`, method: "POST", route: `/api/x${i}`, code: `c${i}`, errors: [`e${i}`], requestId: `r${i}` } as never, i);
      }
      const recent = (incidentStore.readRecentIncidents as unknown as (now?: number) => Array<Record<string, unknown>>)(7);
      expect(recent, "au plus les 5 plus récentes").toHaveLength(5);
      expect(recent.some((e) => "r1" === e.requestId), "la plus ancienne (6ᵉ avant) est évincée du ring").toBe(false);
      const latest = recent[0];
      expect(latest.method).toBe("POST");
      expect(latest.route).toBeTruthy();
      expect(latest.errors).toBeDefined();
    });
  });
});
