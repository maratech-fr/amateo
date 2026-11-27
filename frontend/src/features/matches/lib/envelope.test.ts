import { describe, expect, it } from "vitest";

import type { Fixture, LeagueWindow } from "../api";
import { isInEnvelope, isoWeekday, resolveEnvelope, timeToMinutes } from "./envelope";

const fixture = (over: Partial<Fixture> = {}): Fixture => ({
  id: "fx-1",
  teamId: "team-1",
  seasonId: "s",
  competitionId: null,
  matchDate: "2026-10-03", // Saturday
  homeAway: "HOME",
  opponentLabel: "Adv",
  status: "UNPLACED",
  venueId: null,
  kickoffTime: null,
  externalRef: null,
  fbiVenueLabel: null,
  placementSource: null,
  unplacedReason: null, neutralGround: false, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null, ...over,
});
const window = (over: Partial<LeagueWindow> = {}): LeagueWindow => ({
  id: "w-1",
  league: "AURA",
  category: "U13",
  level: "DEPARTEMENTAL",
  gender: null,
  dayOfWeek: 6, // Saturday
  kickoffMin: "13:00",
  kickoffMax: "18:00",
  ...over,
});

describe("envelope helpers", () => {
  it("timeToMinutes tolerates HH:MM and HH:MM:SS", () => {
    expect(timeToMinutes("13:00")).toBe(780);
    expect(timeToMinutes("18:30:00")).toBe(1110);
  });

  it("isoWeekday returns 6 for Saturday, 7 for Sunday", () => {
    expect(isoWeekday("2026-10-03")).toBe(6);
    expect(isoWeekday("2026-10-04")).toBe(7);
  });
});

// P1-4 PR E2 (dette iv): the team↔window join lives on the SERVER — the client
// only looks up `resolvedTeamWindows`. These tests pin the lookup semantics.
describe("resolveEnvelope", () => {
  it("maps a team via the server-resolved ids and validates day + time", () => {
    const env = resolveEnvelope(fixture(), { "team-1": ["w-1"] }, [window()], []);
    expect(env.mapped).toBe(true);
    expect(env.dayOk).toBe(true);
    expect(env.timeOk("14:00")).toBe(true);
    expect(env.timeOk("20:00")).toBe(false); // past kickoffMax
  });

  it("does not map a team the server resolved to [] (unmapped = advisory)", () => {
    const env = resolveEnvelope(fixture(), { "team-1": [] }, [window()], []);
    expect(env.mapped).toBe(false);
    expect(env.windows).toHaveLength(0);
  });

  it("does not map a team absent from the server resolution", () => {
    const env = resolveEnvelope(fixture(), {}, [window()], []);
    expect(env.mapped).toBe(false);
  });

  it("ignores resolved ids that no longer exist in the catalog items", () => {
    const env = resolveEnvelope(fixture(), { "team-1": ["w-gone"] }, [window()], []);
    expect(env.mapped).toBe(false);
  });

  it("flags the wrong day as out of envelope", () => {
    // A Sunday match against a Saturday-only window.
    const env = resolveEnvelope(fixture({ matchDate: "2026-10-04" }), { "team-1": ["w-1"] }, [window()], []);
    expect(env.mapped).toBe(true);
    expect(env.dayOk).toBe(false);
  });
});

describe("isInEnvelope", () => {
  it("never blocks an unmapped team (advisory degradation)", () => {
    const env = resolveEnvelope(fixture(), { "team-1": [] }, [window()], []);
    expect(env.mapped).toBe(false);
    expect(isInEnvelope(env, "23:00")).toBe(true);
  });

  it("blocks a mapped team placed outside its window", () => {
    const env = resolveEnvelope(fixture(), { "team-1": ["w-1"] }, [window()], []);
    expect(isInEnvelope(env, "14:00")).toBe(true);
    expect(isInEnvelope(env, "20:00")).toBe(false);
  });
});

/** D7 : la ligue a posé ce jour exprès : un férié est dans l'enveloppe, miroir du radar (`RuleWindowConflicts.php:47`). */
describe("resolveEnvelope — jour férié", () => {
  const HOLIDAY = "2026-11-11"; // mercredi, hors de la fenêtre du samedi

  it("un férié est dans l'enveloppe et le dit", () => {
    const env = resolveEnvelope(fixture({ matchDate: HOLIDAY }), { "team-1": ["w-1"] }, [window()], [HOLIDAY]);
    expect(env.holiday).toBe(true);
    expect(isInEnvelope(env, "20:00")).toBe(true);
  });

  it("un mardi non férié reste hors enveloppe : l'alerte ligue reste", () => {
    const env = resolveEnvelope(fixture({ matchDate: "2026-11-24" }), { "team-1": ["w-1"] }, [window()], [HOLIDAY]);
    expect(env.holiday).toBe(false);
    expect(isInEnvelope(env, "20:00")).toBe(false);
  });
});

/**
 * Lot 7, PR 4b — la nature du match (`competitionType`) décide de sa règle : un championnat obéit à la fenêtre de
 * ligue de son équipe ; une coupe n'en a pas et se joue entre 08:00 et 21:00, tout jour, férié compris, équipe
 * cadrée ou non ; un amical n'a aucune enveloppe. La coupe hors horaires est une enveloppe ACTIVE (`mapped`) que la
 * grille marque comme un match hors fenêtre (`CalendarPage`, `envelope.mapped && !isInEnvelope`). Jumeau serveur :
 * `ConflictRadarLeagueWindowTest` (le radar juge la coupe sur ses heures, jamais sur la ligue).
 */
describe("resolveEnvelope — la nature du match décide de sa règle", () => {
  const WEDNESDAY = "2026-10-07"; // la fenêtre de ligue de l'équipe n'ouvre que le samedi
  const HOLIDAY = "2026-11-11";
  const cup = (over: Partial<Fixture> = {}): Fixture => fixture({ competitionId: "comp-coupe", competitionType: "CUP", ...over });

  it("une coupe un mercredi à 20:00 est dans sa règle : la fenêtre de ligue ne la juge pas", () => {
    const env = resolveEnvelope(cup({ matchDate: WEDNESDAY }), { "team-1": ["w-1"] }, [window()], []);
    expect(isInEnvelope(env, "20:00")).toBe(true);
  });

  it("une coupe un samedi à 21:30 sort de ses horaires, et la grille la marque", () => {
    const env = resolveEnvelope(cup(), { "team-1": ["w-1"] }, [window()], []);
    expect(env.mapped).toBe(true);
    expect(isInEnvelope(env, "21:30")).toBe(false);
    expect(isInEnvelope(env, "21:00")).toBe(true);
  });

  it("une coupe d'une équipe sans fenêtre de ligue garde ses horaires", () => {
    const env = resolveEnvelope(cup({ matchDate: WEDNESDAY }), { "team-1": [] }, [window()], []);
    expect(env.mapped).toBe(true);
    expect(isInEnvelope(env, "08:00")).toBe(true);
    expect(isInEnvelope(env, "07:45")).toBe(false);
  });

  it("un jour férié, la borne des coupes tient", () => {
    const env = resolveEnvelope(cup({ matchDate: HOLIDAY }), { "team-1": ["w-1"] }, [window()], [HOLIDAY]);
    expect(isInEnvelope(env, "21:30")).toBe(false);
  });

  it("un championnat un mercredi à 20:00 reste hors de sa fenêtre de ligue", () => {
    const env = resolveEnvelope(fixture({ matchDate: WEDNESDAY, competitionId: "comp-champ", competitionType: "CHAMPIONSHIP" }), { "team-1": ["w-1"] }, [window()], []);
    expect(isInEnvelope(env, "20:00")).toBe(false);
  });

  it("un amical n'a aucune enveloppe : hors de la fenêtre de ligue, il reste libre", () => {
    const env = resolveEnvelope(fixture({ competitionId: "comp-amicaux", competitionType: "FRIENDLY" }), { "team-1": ["w-1"] }, [window()], []);
    expect(isInEnvelope(env, "20:00")).toBe(true);
  });
});
