import { describe, expect, it } from "vitest";

import type { Conflict, Fixture, TeamMatchHabit } from "../api";
import { defaultLoopStep, deriveLoopSteps, isOffModel, offModelCount } from "./loopSteps";

/** A HOME fixture builder — everything placed by default, overridable. */
function fx(over: Partial<Fixture> = {}): Fixture {
  return {
    id: over.id ?? "fx",
    teamId: over.teamId ?? "team-1",
    seasonId: "s",
    competitionId: null,
    matchDate: over.matchDate ?? "2026-10-03", // a Saturday
    homeAway: over.homeAway ?? "HOME",
    opponentLabel: "Adv",
    status: over.status ?? "PLACED",
    venueId: over.venueId ?? "venue-1",
    kickoffTime: over.kickoffTime ?? "16:00",
    externalRef: over.externalRef ?? null,
    fbiVenueLabel: null,
    placementSource: over.placementSource ?? "MANUAL",
    ...over,
  };
}

/** ISO weekday of 2026-10-03 is Saturday = 6. */
function habit(over: Partial<TeamMatchHabit> = {}): TeamMatchHabit {
  return { id: over.id ?? "h", teamId: over.teamId ?? "team-1", dayOfWeek: over.dayOfWeek ?? 6, kickoffTime: over.kickoffTime ?? "16:00", venueId: over.venueId ?? null };
}

function conflictOn(fixtureId: string): Conflict {
  return { type: "MATCH_MATCH", severity: 3, left: { fixtureId, teamId: "t", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" } };
}

function id(steps: ReturnType<typeof deriveLoopSteps>, stepId: string) {
  const step = steps.find((s) => s.id === stepId);
  if (undefined === step) {
    throw new Error(`step ${stepId} absent`);
  }
  return step;
}

describe("deriveLoopSteps — les 5 états DÉRIVÉS de la semaine (zéro état stocké)", () => {
  it("étape 1 (Batch importé) : done ⇔ des matchs existent cette semaine", () => {
    expect(id(deriveLoopSteps({ weekFixtures: [], habits: [], conflicts: [] }), "batch").done).toBe(false);
    expect(id(deriveLoopSteps({ weekFixtures: [fx()], habits: [], conflicts: [] }), "batch").done).toBe(true);
  });

  it("étape 2 (Placés au modèle) : 1 HOME UNPLACED d'une équipe À HABITUDE ⇒ non-done", () => {
    const weekFixtures = [fx({ id: "u", status: "UNPLACED", venueId: null, kickoffTime: null })];
    // Sans habitude déclarée : l'UNPLACED ne compte pas — l'étape reste done.
    expect(id(deriveLoopSteps({ weekFixtures, habits: [], conflicts: [] }), "model").done).toBe(true);
    // Avec une habitude sur cette équipe : l'UNPLACED compte — l'étape n'est plus done.
    expect(id(deriveLoopSteps({ weekFixtures, habits: [habit()], conflicts: [] }), "model").done).toBe(false);
  });

  it("étape 2 : l'ÉCART AU MODÈLE ne rend JAMAIS l'étape non-done (signal, pas blocage)", () => {
    // Un HOME PLACÉ mais hors habitude (jour ≠ dimanche déclaré) : écart présent…
    const offModel = fx({ id: "off", status: "PLACED", matchDate: "2026-10-03", kickoffTime: "18:30" });
    const habits = [habit({ kickoffTime: "16:00" })]; // habitude 16:00, placé 18:30 → écart
    expect(isOffModel(offModel, habits)).toBe(true);
    expect(offModelCount([offModel], habits)).toBe(1);
    // …et pourtant l'étape 2 (comme la 4) reste DONE : tout est placé.
    const steps = deriveLoopSteps({ weekFixtures: [offModel], habits, conflicts: [] });
    expect(id(steps, "model").done).toBe(true);
    expect(id(steps, "homeSlots").done).toBe(true);
  });

  it("étape 3 (Litiges) : un conflit rattaché à un fixture de W compte ; un conflit SANS date NON", () => {
    const weekFixtures = [fx({ id: "w1" })];
    // Conflit sur w1 (dans W) → compte, non-done, count dans le label.
    const withDate = deriveLoopSteps({ weekFixtures, habits: [], conflicts: [conflictOn("w1")] });
    expect(id(withDate, "disputes").done).toBe(false);
    expect(id(withDate, "disputes").label).toBe("Litiges (1)");
    // Conflit SANS fixture (COMPETITION_INCOMPLETE) → hors compte hebdo, done.
    const dateless: Conflict = { type: "COMPETITION_INCOMPLETE", severity: 6, competitionId: "c", teamId: "team-1", imported: 3, expected: 6 };
    const withoutDate = deriveLoopSteps({ weekFixtures, habits: [], conflicts: [dateless] });
    expect(id(withoutDate, "disputes").done).toBe(true);
    expect(id(withoutDate, "disputes").label).toBe("Litiges (0)");
    // Conflit sur un fixture d'une AUTRE semaine → pas dans le compte de W.
    const otherWeek = deriveLoopSteps({ weekFixtures, habits: [], conflicts: [conflictOn("not-in-w")] });
    expect(id(otherWeek, "disputes").done).toBe(true);
  });

  it("étape 4 (Domiciles posés) : done ⇔ 0 HOME UNPLACED (habitude ou non)", () => {
    const unplacedNoHabit = [fx({ id: "u", status: "UNPLACED", venueId: null, kickoffTime: null })];
    // Étape 2 done (pas d'habitude) MAIS étape 4 non-done : la distinction validée fondateur.
    const steps = deriveLoopSteps({ weekFixtures: unplacedNoHabit, habits: [], conflicts: [] });
    expect(id(steps, "model").done).toBe(true);
    expect(id(steps, "homeSlots").done).toBe(false);
  });

  it("étape 5 (Saisi dans FBI) : tout HOME SUBMITTED/VALIDATED et H non vide ⇒ done + label n/m", () => {
    const one = fx({ id: "a", status: "PLACED" });
    const partial = deriveLoopSteps({ weekFixtures: [one], habits: [], conflicts: [] });
    expect(id(partial, "fbiEntry").done).toBe(false);
    expect(id(partial, "fbiEntry").label).toBe("Saisi dans FBI (0/1)");
    // Une semaine où tout est SUBMITTED → 5/5 cochés (état « veille »).
    const submitted = [fx({ id: "a", status: "SUBMITTED" }), fx({ id: "b", status: "VALIDATED" })];
    const all = deriveLoopSteps({ weekFixtures: submitted, habits: [], conflicts: [] });
    expect(id(all, "fbiEntry").done).toBe(true);
    expect(id(all, "fbiEntry").label).toBe("Saisi dans FBI (2/2)");
    expect(all.every((s) => s.done)).toBe(true);
  });

  it("étape 5 : H vide ⇒ non-done (rien à saisir ne se coche pas comme fait)", () => {
    const awayOnly = [fx({ id: "away", homeAway: "AWAY", status: "UNPLACED" })];
    expect(id(deriveLoopSteps({ weekFixtures: awayOnly, habits: [], conflicts: [] }), "fbiEntry").done).toBe(false);
  });
});

describe("defaultLoopStep — le PREMIER TROU (première étape non-done)", () => {
  it("renvoie la première étape non-done", () => {
    // Batch done (W>0), model non-done (habitude + unplaced) → défaut = model.
    const steps = deriveLoopSteps({
      weekFixtures: [fx({ id: "u", status: "UNPLACED", venueId: null, kickoffTime: null })],
      habits: [habit()],
      conflicts: [],
    });
    expect(defaultLoopStep(steps)).toBe("model");
  });

  it("tout done → dernière étape (rien à traiter, on est au bout)", () => {
    const steps = deriveLoopSteps({ weekFixtures: [fx({ status: "SUBMITTED" })], habits: [], conflicts: [] });
    expect(steps.every((s) => s.done)).toBe(true);
    expect(defaultLoopStep(steps)).toBe("fbiEntry");
  });
});

describe("isOffModel — divergence d'un domicile placé vs son habitude", () => {
  it("pas d'habitude sur l'équipe ⇒ jamais un écart (pas de modèle de référence)", () => {
    expect(isOffModel(fx({ kickoffTime: "20:00" }), [])).toBe(false);
  });
  it("UNPLACED ⇒ jamais un écart (rien de placé à comparer)", () => {
    expect(isOffModel(fx({ status: "UNPLACED", venueId: null, kickoffTime: null }), [habit()])).toBe(false);
  });
  it("jour non habituel ⇒ écart", () => {
    // Placé un dimanche (7) alors que l'habitude est le samedi (6).
    expect(isOffModel(fx({ matchDate: "2026-10-04" }), [habit({ dayOfWeek: 6 })])).toBe(true);
  });
  it("même jour, gymnase habituel divergent ⇒ écart", () => {
    expect(isOffModel(fx({ venueId: "venue-2" }), [habit({ venueId: "venue-1" })])).toBe(true);
  });
  it("même jour, heure et gymnase conformes ⇒ pas d'écart", () => {
    expect(isOffModel(fx({ kickoffTime: "16:00", venueId: "venue-1" }), [habit({ kickoffTime: "16:00", venueId: "venue-1" })])).toBe(false);
  });
});
