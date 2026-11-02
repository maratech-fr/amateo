import { renderHook } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { Schedule } from "../api";
import { useVersionLanding } from "./useVersionLanding";

/**
 * Plan « version chargée » — atterrir sur l'étape Génération, c'est atterrir sur la version chargée,
 * sauf une génération en vol, ou un échec dans la session qui l'a lancée (état du store, jamais une date).
 */
const v = (id: string, createdAt: string, over: Partial<Schedule> = {}): Schedule => ({ id, name: "Plan", status: "COMPLETED", score: null, createdAt, updatedAt: createdAt, planType: "SEASON", schedulePlanId: "season-plan", ...over });

const V1 = "2026-01-01T10:00:00+00:00";
const V2 = "2026-02-01T10:00:00+00:00";
const V3 = "2026-03-01T10:00:00+00:00";

function landing(schedules: Schedule[], sessionLaunched: string | null = null): string | null {
  const select = vi.fn();
  const { result } = renderHook(() => useVersionLanding(schedules, null, true, null, select, sessionLaunched));
  return result.current.landingScheduleId;
}

describe("useVersionLanding — étape Génération (version chargée)", () => {
  it("atterrit sur la version chargée, pas sur la plus récente", () => {
    expect(landing([v("v1", V1, { isLiveContext: true }), v("v2", V2)])).toBe("v1");
  });

  it("une génération en vol passe devant la version chargée", () => {
    expect(landing([v("v1", V1, { isLiveContext: true }), v("v2", V2, { status: "GENERATING" })])).toBe("v2");
  });

  it("un échec lancé dans cette session passe devant la version chargée", () => {
    expect(landing([v("v1", V1, { isLiveContext: true }), v("v2", V2, { status: "FAILED" })], "v2")).toBe("v2");
  });

  it("un échec d'une autre session ne détourne pas de la version chargée", () => {
    expect(landing([v("v1", V1, { isLiveContext: true }), v("v2", V2, { status: "FAILED" })], null)).toBe("v1");
  });

  it("sans pointeur, la dernière terminée, jamais un échec plus récent", () => {
    expect(landing([v("v1", V1), v("v2", V2), v("v3", V3, { status: "FAILED" })])).toBe("v2");
  });

  it("aucune version terminée : la dernière, pour montrer son échec", () => {
    expect(landing([v("v1", V1, { status: "FAILED" })])).toBe("v1");
  });

  it("un plan de période garde sa règle : la plus récente de la portée", () => {
    const period = [v("p1", V1, { planType: "CLOSURE", schedulePlanId: "p", isLockSource: true }), v("p2", V2, { planType: "CLOSURE", schedulePlanId: "p" })];
    const { result } = renderHook(() => useVersionLanding(period, "p", true, null, vi.fn(), null));
    expect(result.current.landingScheduleId).toBe("p2");
  });
});
