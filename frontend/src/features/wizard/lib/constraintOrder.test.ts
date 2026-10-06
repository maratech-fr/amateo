import { describe, expect, it } from "vitest";

import type { Coach, Constraint, PriorityTier, Team, TeamTag, Venue } from "@/features/wizard/api";

import { FAMILY_ORDER, groupConstraints } from "./constraintOrder";

const teams = [
  { id: "t-b", name: "SM1", priorityTierId: 3, tierOrder: 0 },
  { id: "t-s", name: "Fanion", priorityTierId: 1, tierOrder: 0 },
] as unknown as Team[];
const tags = [
  { id: "g-fem", name: "FEMININE", axis: "GENRE" },
  { id: "g-adulte", name: "ADULTE", axis: "AGE" },
] as unknown as TeamTag[];
const coaches = [
  { id: "co-vol", firstName: "Zoe", lastName: "V", isEmployee: false },
  { id: "co-sal", firstName: "Ana", lastName: "S", isEmployee: true },
] as unknown as Coach[];
const venues = [
  { id: "v-camus", name: "Camus" },
  { id: "v-mateo", name: "Matéo" },
] as unknown as Venue[];

const tiers = [
  { id: 1, label: "S", name: "Fanion" },
  { id: 3, label: "B", name: "Moyenne" },
] as unknown as PriorityTier[];

const ctx = {
  teams,
  tiers,
  tags,
  coaches,
  coachPlayerIds: new Set<string>(),
  venues,
  coachName: (id: string) => coaches.find((c) => c.id === id)?.firstName ?? "Coach",
  venueName: (id: string) => venues.find((v) => v.id === id)?.name ?? "Gymnase",
};

const c = (over: Partial<Constraint>): Constraint => ({ id: Math.random().toString(), name: "n", scope: "TEAM", scopeTargetId: null, family: "TIME", ruleType: "HARD", config: {}, isActive: true, ...over }) as Constraint;

describe("groupConstraints", () => {
  it("FAMILY_ORDER couvre toutes les familles de contrainte", () => {
    // FACILITY_CAPACITY en faisait partie jusqu'au 2026-08-08 : retirée, aucun
    // chemin UI ne la créait (la capacité se règle par créneau).
    expect(FAMILY_ORDER).toEqual(["TIME", "DAY", "FACILITY", "COACH_AVAILABILITY"]);
  });

  it("TIME/DAY → groups by tag axis (Genre before Âge), then teams by their RANG group", () => {
    const sections = groupConstraints(
      [
        c({ scope: "CLUB", config: { targetTag: "ADULTE" } }), // axis AGE
        c({ scope: "CLUB", config: { targetTag: "FEMININE" } }), // axis GENRE
        c({ scope: "TEAM", scopeTargetId: "t-b" }), // SM1 (tier B)
        c({ scope: "TEAM", scopeTargetId: "t-s" }), // Fanion (tier S)
      ],
      "TIME",
      ctx,
    ).map((s) => s.label);
    expect(sections).toEqual(["Genre", "Âge", "S · Fanion", "B · Moyenne"]);
  });

  it("TIME/DAY → une contrainte à CIBLE COMBINÉE se range sous l'axe de son tag PRINCIPAL (P2-29)", () => {
    // targetTags:[ADULTE(AGE), FEMININE(GENRE)] → tag principal ADULTE → axe Âge (jamais « Autres »
    // ni « Toutes les équipes »). L'affinage vit dans le NOM, pas dans la section.
    const sections = groupConstraints(
      [c({ scope: "CLUB", config: { targetTags: ["ADULTE", "FEMININE"], excludeTags: ["FEMININE"] } })],
      "TIME",
      ctx,
    ).map((s) => s.label);
    expect(sections).toEqual(["Âge"]);
  });

  it("FACILITY → groups by venue, A→Z", () => {
    const sections = groupConstraints(
      [
        c({ family: "FACILITY", config: { preferredVenueId: "v-mateo" } }),
        c({ family: "FACILITY", config: { forcedVenueId: "v-camus" } }),
      ],
      "FACILITY",
      ctx,
    ).map((s) => s.label);
    expect(sections).toEqual(["Camus", "Matéo"]);
  });

  // D4 (P2-22) — une fermeture (venue_closed) porte son gymnase dans scopeTargetId (scope
  // FACILITY), PAS dans config : sans le repli elle atterrissait dans « Autres ».
  it("FACILITY → une fermeture se range sous SON gymnase via scopeTargetId", () => {
    const sections = groupConstraints(
      [c({ family: "FACILITY", scope: "FACILITY", scopeTargetId: "v-camus", config: { type: "venue_closed", startDate: "2026-05-01", endDate: "2026-05-10" } })],
      "FACILITY",
      ctx,
    ).map((s) => s.label);
    expect(sections).toEqual(["Camus"]);
  });

  // …et le repli n'est QU'UN repli : une contrainte de gymnase config-based reste rangée par
  // sa clé config (la cible d'une contrainte TEAM·préfère est une équipe, pas un gymnase).
  it("FACILITY → une contrainte config-based reste rangée par sa clé config, pas scopeTargetId", () => {
    const sections = groupConstraints(
      [c({ family: "FACILITY", scope: "TEAM", scopeTargetId: "t-b", config: { preferredVenueId: "v-mateo" } })],
      "FACILITY",
      ctx,
    ).map((s) => s.label);
    expect(sections).toEqual(["Matéo"]);
  });

  it("COACH_AVAILABILITY → groups by staffing (Salariés before Bénévoles)", () => {
    const sections = groupConstraints(
      [
        c({ family: "COACH_AVAILABILITY", scope: "COACH", scopeTargetId: "co-vol" }),
        c({ family: "COACH_AVAILABILITY", scope: "COACH", scopeTargetId: "co-sal" }),
      ],
      "COACH_AVAILABILITY",
      ctx,
    ).map((s) => s.label);
    expect(sections).toEqual(["Salariés", "Bénévoles"]);
  });

  it("never drops a coach constraint whose coach is absent (→ « Coach retiré »)", () => {
    const sections = groupConstraints([c({ family: "COACH_AVAILABILITY", scope: "COACH", scopeTargetId: "co-gone" })], "COACH_AVAILABILITY", ctx);
    expect(sections).toHaveLength(1);
    expect(sections[0].label).toBe("Coach retiré·e");
    expect(sections[0].items).toHaveLength(1);
  });
});
