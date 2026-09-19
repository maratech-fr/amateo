import { describe, expect, it } from "vitest";

import type { Fixture, Team, Venue } from "../api";
import { buildWeekendGrid, isPlacedOnGrid, listWeekends, resolveActiveWeekend, weekendKeyOf, weekendShortLabel, weekLabel } from "./weekendGrid";

const fixture = (over: Partial<Fixture> = {}): Fixture => ({
  id: "fx-1",
  teamId: "team-1",
  seasonId: "s",
  competitionId: null,
  matchDate: "2026-10-03",
  homeAway: "HOME",
  opponentLabel: "Adv",
  status: "PLACED",
  venueId: "venue-1",
  kickoffTime: "16:00",
  externalRef: null,
  fbiVenueLabel: null,
  placementSource: null,
  unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null, ...over,
});

const venues = new Map<string, Venue>([["venue-1", { id: "venue-1", name: "Gymnase Alpha", color: "#00aa00", externalLabels: [] }]]);
const teams = new Map<string, Team>([["team-1", { id: "team-1", name: "U13", sportCategoryId: "cat", level: null, gender: null, priorityTierId: 3, tierOrder: 0 }]]);

describe("weekendKeyOf", () => {
  it("buckets Saturday and its Sunday into the same weekend (the Saturday)", () => {
    expect(weekendKeyOf("2026-10-03")).toBe("2026-10-03"); // Saturday
    expect(weekendKeyOf("2026-10-04")).toBe("2026-10-03"); // Sunday → same weekend
  });
});

describe("weekendShortLabel (étiquette courte du week-end, onglet Conflits)", () => {
  it("samedi + dimanche du même mois : « 7-8 oct. »", () => {
    // 2026-10-03 est un samedi ; son dimanche est le 4 → même mois.
    expect(weekendShortLabel("2026-10-03")).toBe("3-4 oct.");
  });

  it("à cheval sur deux mois : « 31 oct.-1 nov. »", () => {
    // 2026-10-31 est un samedi ; son dimanche est le 1er novembre.
    expect(weekendShortLabel("2026-10-31")).toBe("31 oct.-1 nov.");
  });
});

describe("isPlacedOnGrid", () => {
  it("is true only for home fixtures with venue + kickoff", () => {
    expect(isPlacedOnGrid(fixture())).toBe(true);
    expect(isPlacedOnGrid(fixture({ kickoffTime: null }))).toBe(false);
    expect(isPlacedOnGrid(fixture({ venueId: null }))).toBe(false);
    expect(isPlacedOnGrid(fixture({ homeAway: "AWAY" }))).toBe(false);
  });
});

describe("listWeekends", () => {
  it("returns sorted distinct weekend buckets", () => {
    const list = listWeekends([fixture(), fixture({ id: "fx-2", matchDate: "2026-10-04" }), fixture({ id: "fx-3", matchDate: "2026-10-10" })]);
    expect(list).toEqual(["2026-10-03", "2026-10-10"]);
  });
});

describe("buildWeekendGrid", () => {
  it("is empty when no home fixture is placed", () => {
    const grid = buildWeekendGrid([fixture({ kickoffTime: null })], venues, teams);
    expect(grid.empty).toBe(true);
    expect(grid.cells).toHaveLength(0);
  });

  it("lays a lone placed match as a block starting at kickoff, spanning the match duration", () => {
    const grid = buildWeekendGrid([fixture()], venues, teams);
    expect(grid.empty).toBe(false);
    expect(grid.columns).toHaveLength(1);
    expect(grid.dateGroups[0].dateKey).toBe("2026-10-03");
    expect(grid.cells).toHaveLength(1);
    const cell = grid.cells[0];
    // Retour fondateur 2026-09-14 : le bloc DÉMARRE au coup d'envoi (plus de −30) et
    // dure la durée du match (repli 105). 16:00 → 16:00–17:45 = 105 min = 7 pas de 15.
    expect(cell.kickoffLabel).toBe("16:00");
    expect(cell.footprintLabel).toBe("16:00–17:45");
    expect(cell.gridRowSpan).toBe(7);
    expect(cell.outOfEnvelope).toBe(false);
  });

  it("marks a fixture flagged out of envelope", () => {
    const grid = buildWeekendGrid([fixture()], venues, teams, new Set(["fx-1"]));
    expect(grid.cells[0].outOfEnvelope).toBe(true);
  });

  it("puts two overlapping matches of the same venue in separate lanes", () => {
    const grid = buildWeekendGrid([fixture(), fixture({ id: "fx-2", kickoffTime: "16:30", opponentLabel: "Adv2" })], venues, teams);
    expect(grid.cells).toHaveLength(2);
    expect(grid.cells.map((c) => c.laneCount)).toEqual([2, 2]);
    expect(new Set(grid.cells.map((c) => c.lane))).toEqual(new Set([0, 1]));
  });
});

describe("enchaînement des blocs (PR F — retour fondateur 2026-09-14)", () => {
  const footprints = (grid: ReturnType<typeof buildWeekendGrid>): Map<string, string> =>
    new Map(grid.cells.map((c) => [c.fixtureId, c.footprintLabel]));

  it("(a) quatre domiciles du même gymnase s'enchaînent sans trou", () => {
    const grid = buildWeekendGrid(
      [
        fixture({ id: "a", kickoffTime: "10:00", opponentLabel: "A" }),
        fixture({ id: "b", kickoffTime: "12:00", opponentLabel: "B" }),
        fixture({ id: "c", kickoffTime: "14:15", opponentLabel: "C" }),
        fixture({ id: "d", kickoffTime: "16:30", opponentLabel: "D" }),
      ],
      venues,
      teams,
    );
    const fp = footprints(grid);
    // Fins naturelles 11:45 / 13:45 / 16:00 / 18:15 ; écarts 15 / 30 / 30 min → tous ≤ 30.
    expect(fp.get("a")).toBe("10:00–12:00");
    expect(fp.get("b")).toBe("12:00–14:15");
    expect(fp.get("c")).toBe("14:15–16:30");
    expect(fp.get("d")).toBe("16:30–18:15"); // dernier match : durée seule
    // La grille part du premier coup d'envoi (plus de −30).
    expect(grid.startMin).toBe(600);
    expect(grid.rows[0].label).toBe("10:00");
    // Quatre blocs qui se suivent = une seule voie.
    expect(grid.cells.every((c) => 1 === c.laneCount)).toBe(true);
  });

  it("(b) un écart > 30 min laisse le trou visible : le bloc garde sa durée", () => {
    const grid = buildWeekendGrid([fixture({ id: "a", kickoffTime: "10:00" }), fixture({ id: "b", kickoffTime: "13:00" })], venues, teams);
    const fp = footprints(grid);
    // fin naturelle de a = 11:45 ; b à 13:00 → écart 75 min > 30 → pas d'enchaînement.
    expect(fp.get("a")).toBe("10:00–11:45");
    expect(fp.get("b")).toBe("13:00–14:45");
  });

  it("(c) un écart d'exactement 30 min enchaîne encore", () => {
    const grid = buildWeekendGrid([fixture({ id: "a", kickoffTime: "10:00" }), fixture({ id: "b", kickoffTime: "12:15" })], venues, teams);
    const fp = footprints(grid);
    // fin naturelle de a = 11:45 ; b à 12:15 = 11:45 + 30 → à la limite → enchaîné.
    expect(fp.get("a")).toBe("10:00–12:15");
    expect(fp.get("b")).toBe("12:15–14:00");
  });

  it("(d) deux gymnases ne s'enchaînent jamais entre eux", () => {
    const twoVenues = new Map<string, Venue>([
      ["venue-1", { id: "venue-1", name: "Gymnase Alpha", color: "#00aa00", externalLabels: [] }],
      ["venue-2", { id: "venue-2", name: "Gymnase Beta", color: "#0000aa", externalLabels: [] }],
    ]);
    const grid = buildWeekendGrid(
      [fixture({ id: "a", kickoffTime: "10:00", venueId: "venue-1" }), fixture({ id: "b", kickoffTime: "12:00", venueId: "venue-2" })],
      twoVenues,
      teams,
    );
    const fp = footprints(grid);
    // a seul dans SON gymnase : durée seule, aucun étirement vers le match de l'autre salle.
    expect(fp.get("a")).toBe("10:00–11:45");
    expect(fp.get("b")).toBe("12:00–13:45");
  });

  it("(e) la durée de catégorie servie est respectée, repli 105 sinon", () => {
    // 8ᵉ argument = durées EFFECTIVES par sportCategoryId (déjà résolues côté serveur, jamais recalculées ici).
    const withDuration = buildWeekendGrid([fixture()], venues, teams, new Set(), [], "2026-10-03", 15, new Map([["cat", 75]]));
    expect(withDuration.cells[0].footprintLabel).toBe("16:00–17:15"); // U11 = 75 min
    const fallback = buildWeekendGrid([fixture()], venues, teams);
    expect(fallback.cells[0].footprintLabel).toBe("16:00–17:45"); // repli 105 (MatchDurationProfile::fallback)
  });

  it("(f) footprintLabel = les bornes DESSINÉES du bloc, et gridRowSpan concorde", () => {
    const grid = buildWeekendGrid([fixture({ id: "a", kickoffTime: "10:00" }), fixture({ id: "b", kickoffTime: "12:00" })], venues, teams);
    const a = grid.cells.find((c) => "a" === c.fixtureId);
    expect(a?.footprintLabel).toBe("10:00–12:00"); // étiré jusqu'au coup d'envoi suivant
    expect(a?.gridRowSpan).toBe(Math.round((720 - 600) / 15)); // (12:00 − 10:00)/15 = 8
  });
});

describe("lock badge (P1-4 PR E1 · cadenas resserré 2026-09-17)", () => {
  it("verrouille les placés MANUELS (PLACED/SUBMITTED/VALIDATED), jamais SOLVER, jamais null, jamais UNPLACED", () => {
    const grid = buildWeekendGrid(
      [
        fixture({ id: "m", placementSource: "MANUAL" }), // PLACED + MANUAL → verrouillé
        fixture({ id: "sub", kickoffTime: "17:00", status: "SUBMITTED", placementSource: "MANUAL" }),
        fixture({ id: "val", kickoffTime: "17:30", status: "VALIDATED", placementSource: "MANUAL" }),
        fixture({ id: "n", kickoffTime: "18:00", placementSource: null }), // legacy null → PLUS verrouillé
        fixture({ id: "s", kickoffTime: "20:00", placementSource: "SOLVER" }),
        fixture({ id: "u", kickoffTime: "21:00", status: "UNPLACED", placementSource: "MANUAL" }), // UNPLACED jamais
      ],
      venues,
      teams,
    );
    const byId = new Map(grid.cells.map((c) => [c.fixtureId, c.locked]));
    expect(byId.get("m")).toBe(true);
    expect(byId.get("sub")).toBe(true);
    expect(byId.get("val")).toBe(true);
    expect(byId.get("n")).toBe(false);
    expect(byId.get("s")).toBe(false);
    expect(byId.get("u")).toBe(false);
  });
});

describe("toConfirm — un statut backend présenté, pas une décision front", () => {
  it("true pour un domicile porté sur la grille mais UNPLACED (gymnase + heure repris de l'import)", () => {
    // Le fixture par défaut a gymnase + heure (isPlacedOnGrid true) ; statut UNPLACED.
    const grid = buildWeekendGrid([fixture({ status: "UNPLACED" })], venues, teams);
    expect(grid.cells).toHaveLength(1);
    expect(grid.cells[0].toConfirm).toBe(true);
    expect(grid.cells[0].locked).toBe(false); // un à-confirmer n'est jamais verrouillé
    expect(grid.cells[0].weekday).toBe("sam."); // 2026-10-03 est un samedi (nom accessible)
  });

  it("false dès que le match est réellement placé (PLACED / SUBMITTED / VALIDATED)", () => {
    const grid = buildWeekendGrid(
      [fixture({ id: "p", status: "PLACED" }), fixture({ id: "s", kickoffTime: "18:00", status: "SUBMITTED" }), fixture({ id: "v", kickoffTime: "19:00", status: "VALIDATED" })],
      venues,
      teams,
    );
    expect(grid.cells.every((c) => false === c.toConfirm)).toBe(true);
  });
});

describe("habit ghosts (P1-4 PR C)", () => {
  const habit = (over: Partial<import("../api").TeamMatchHabit> = {}): import("../api").TeamMatchHabit => ({
    id: "h-1",
    teamId: "team-1",
    dayOfWeek: 6, // Saturday
    kickoffTime: "15:30",
    venueId: "venue-1",
    ...over,
  });

  it("renders a translucent ghost on the habit's weekday when the team has no fixture", () => {
    const grid = buildWeekendGrid([], venues, teams, new Set(), [habit()], "2026-10-03");
    expect(grid.empty).toBe(false);
    expect(grid.cells).toHaveLength(1);
    expect(grid.cells[0].ghost).toBe(true);
    expect(grid.cells[0].teamLabel).toBe("U13");
    expect(grid.cells[0].kickoffLabel).toBe("15:30");
    // The ghost creates its date/venue column.
    expect(grid.columns).toHaveLength(1);
    expect(grid.columns[0].dateKey).toBe("2026-10-03");
  });

  it("dissolves the ghost when ANY fixture of the team sits on that date — away frees the slot", () => {
    const away = fixture({ id: "fx-away", homeAway: "AWAY", venueId: null, kickoffTime: null, matchDate: "2026-10-03" });
    const grid = buildWeekendGrid([away], venues, teams, new Set(), [habit()], "2026-10-03");
    expect(grid.cells.filter((c) => c.ghost)).toHaveLength(0);
  });

  it("skips venue-less habits (the grid is venue-columned) and never blocks real cells", () => {
    const grid = buildWeekendGrid([fixture()], venues, teams, new Set(), [habit({ teamId: "team-ghost", venueId: null })], "2026-10-03");
    expect(grid.cells.filter((c) => c.ghost)).toHaveLength(0);
    expect(grid.cells).toHaveLength(1);
  });

  it("lays a ghost and a real match of the same venue side by side (lanes)", () => {
    const grid = buildWeekendGrid([fixture()], venues, teams, new Set(), [habit({ teamId: "team-ghost", kickoffTime: "16:00" })], "2026-10-03");
    const ghost = grid.cells.find((c) => c.ghost);
    const real = grid.cells.find((c) => !c.ghost);
    expect(ghost?.laneCount).toBe(2);
    expect(real?.laneCount).toBe(2);
    expect(ghost?.lane).not.toBe(real?.lane);
  });

  it("RMM-1 PR3 — la cellule PORTE le n° de rencontre (externalRef), null pour un ghost", () => {
    const grid = buildWeekendGrid([fixture({ externalRef: "26" })], venues, teams, new Set(), [habit({ teamId: "team-ghost", kickoffTime: "16:00" })], "2026-10-03");
    expect(grid.cells.find((c) => !c.ghost)?.externalRef).toBe("26");
    expect(grid.cells.find((c) => c.ghost)?.externalRef).toBeNull();
  });
});

describe("colonne extérieur (lot 3 PR-3a)", () => {
  const away = (over: Partial<Fixture> = {}): Fixture =>
    fixture({ id: "ax", homeAway: "AWAY", venueId: null, kickoffTime: null, opponentLabel: "Épinouze", ...over });
  const habitSat = (over: Partial<import("../api").TeamMatchHabit> = {}): import("../api").TeamMatchHabit => ({
    id: "h", teamId: "team-1", dayOfWeek: 6, kickoffTime: "15:30", venueId: null, ...over,
  });
  const travelEntry = (over: Partial<import("../api").OpponentTravel> = {}): import("../api").OpponentTravel => ({
    opponentOrganismeCode: "C1", opponentTeamKey: "EPI-1", opponentLabel: "Épinouze", located: true, hasLogo: false, precision: "VENUE",
    locationName: "Halle Y", city: null, postalCode: null, travelMinutes: 45, approximated: false, source: "AUTO", scope: "CLUB", overrideVenueLabel: null, travelStatus: "done", ...over,
  });

  it("ajoute la colonne « Extérieur » EN DERNIER du groupe de date, jamais de pastille", () => {
    const grid = buildWeekendGrid([fixture(), away({ kickoffTime: "18:00" })], venues, teams);
    const last = grid.columns[grid.columns.length - 1];
    expect(last.away).toBe(true);
    expect(last.label).toBe("Extérieur");
    expect(last.color).toBeNull();
    expect(last.venueId).toBeNull();
    // Le groupe de date englobe le gymnase ET l'extérieur.
    expect(grid.dateGroups[0].span).toBe(2);
  });

  it("aucune colonne « Extérieur » sur un jour sans extérieur", () => {
    const grid = buildWeekendGrid([fixture()], venues, teams);
    expect(grid.columns.some((c) => true === c.away)).toBe(false);
  });

  it("heure RÉELLE : jamais estimée ; heure ESTIMÉE depuis l'habitude du jour", () => {
    const real = buildWeekendGrid([away({ kickoffTime: "18:00" })], venues, teams);
    const realCell = real.cells.find((c) => true === c.away);
    expect(realCell?.kickoffLabel).toBe("18:00");
    expect(realCell?.estimated).toBe(false);
    expect(realCell?.unknownHour).toBe(false);

    // Sans heure réelle mais habitude samedi 15:30 (le match est un samedi).
    const est = buildWeekendGrid([away()], venues, teams, new Set(), [habitSat()], "2026-10-03");
    const estCell = est.cells.find((c) => true === c.away);
    expect(estCell?.kickoffLabel).toBe("15:30");
    expect(estCell?.estimated).toBe(true);
    expect(estCell?.unknownHour).toBe(false);
  });

  it("sans heure ni habitude : bande « sans heure » en TÊTE, blocs empilés (2 rangées)", () => {
    const grid = buildWeekendGrid(
      [away({ id: "u1", opponentLabel: "AAA" }), away({ id: "u2", opponentLabel: "BBB" })],
      venues, teams, new Set(), [], "2026-10-03",
    );
    const band = grid.cells.filter((c) => true === c.unknownHour);
    expect(band).toHaveLength(2);
    expect(band.every((c) => 2 === c.gridRowSpan)).toBe(true);
    // Empilés : deux blocs de 2 rangées, aux rangées 3 et 5.
    expect(new Set(band.map((c) => c.gridRowStart))).toEqual(new Set([3, 5]));
    // La bande précède les rangées horaires ; sa première rangée porte la gouttière « h ? ».
    expect(grid.rows[0].unknownHour).toBe(true);
    expect(grid.rows[0].label).toBe("h ?");
    // Empilés PLEINE largeur, jamais en couloirs.
    expect(band.every((c) => 1 === c.laneCount)).toBe(true);
  });

  it("la bande décale les rangées horaires du domicile (offset), HOME sinon byte-identique", () => {
    // Un domicile 16:00 + un extérieur sans heure → bande de 2 rangées, le domicile glisse.
    const withBand = buildWeekendGrid([fixture(), away()], venues, teams, new Set(), [], "2026-10-03");
    const home = withBand.cells.find((c) => "fx-1" === c.fixtureId);
    expect(home?.gridRowStart).toBe(3 + 2); // 2 rangées de bande insérées au-dessus

    // Sans extérieur ni trajet : HOME strictement inchangé (offset 0, aucune colonne away).
    const plain = buildWeekendGrid([fixture()], venues, teams);
    const plainHome = plain.cells[0];
    expect(plainHome.gridRowStart).toBe(3);
    expect(plainHome.gridRowSpan).toBe(7);
    expect(plainHome.footprintLabel).toBe("16:00–17:45");
    expect(plain.columns.some((c) => true === c.away)).toBe(false);
    expect(plain.rows[0].unknownHour ?? false).toBe(false);
  });

  it("un extérieur ne s'ENCHAÎNE JAMAIS : chaque bloc garde sa durée de match", () => {
    const grid = buildWeekendGrid(
      [away({ id: "a", kickoffTime: "10:00" }), away({ id: "b", kickoffTime: "12:00" })],
      venues, teams,
    );
    const a = grid.cells.find((c) => "a" === c.fixtureId);
    // 10:00 + 105 = 11:45, NON étiré vers 12:00 (pas d'enchaînement extérieur).
    expect(a?.footprintLabel).toBe("10:00–11:45");
  });

  it("deux extérieurs qui se chevauchent partent en couloirs", () => {
    const grid = buildWeekendGrid(
      [away({ id: "a", kickoffTime: "10:00" }), away({ id: "b", kickoffTime: "10:30" })],
      venues, teams,
    );
    const cells = grid.cells.filter((c) => true === c.away);
    expect(cells.map((c) => c.laneCount)).toEqual([2, 2]);
    expect(new Set(cells.map((c) => c.lane))).toEqual(new Set([0, 1]));
  });

  it("porte le libellé de trajet servi (« 45 min », joint par (code, teamKey))", () => {
    const grid = buildWeekendGrid(
      [away({ kickoffTime: "18:00", opponentOrganismeCode: "C1", opponentTeamKey: "EPI-1" })],
      venues, teams, new Set(), [], "2026-10-03", 15, new Map(), [travelEntry()],
    );
    expect(grid.cells.find((c) => true === c.away)?.travelLabel).toBe("45 min");
    // Approché → préfixe « ~ ».
    const approx = buildWeekendGrid(
      [away({ kickoffTime: "18:00", opponentOrganismeCode: "C1", opponentTeamKey: "EPI-1" })],
      venues, teams, new Set(), [], "2026-10-03", 15, new Map(), [travelEntry({ approximated: true })],
    );
    expect(approx.cells.find((c) => true === c.away)?.travelLabel).toBe("~45 min");
  });

  it("showGhosts=false n'éteint QUE les fantômes d'habitude : l'heure d'un extérieur reste estimée", () => {
    const habits = [
      // Habitude samedi de team-1 → l'extérieur samedi de team-1 emprunte 15:30 (estimé).
      { id: "ha", teamId: "team-1", dayOfWeek: 6, kickoffTime: "15:30", venueId: null } as import("../api").TeamMatchHabit,
      // Habitude d'une équipe FANTÔME (sans match) → un fantôme QUAND showGhosts.
      { id: "hg", teamId: "team-ghost", dayOfWeek: 6, kickoffTime: "14:00", venueId: "venue-1" } as import("../api").TeamMatchHabit,
    ];
    // 10ᵉ argument `showGhosts=false` : pas de fantôme, MAIS l'extérieur reste estimé (habitudes pleines).
    const grid = buildWeekendGrid([away()], venues, teams, new Set(), habits, "2026-10-03", 15, new Map(), [], false);
    expect(grid.cells.filter((c) => true === c.ghost)).toHaveLength(0);
    const awayCell = grid.cells.find((c) => true === c.away);
    expect(awayCell?.estimated).toBe(true);
    expect(awayCell?.kickoffLabel).toBe("15:30");
    // showGhosts=true (défaut) : le fantôme réapparaît, l'extérieur reste estimé.
    const withGhost = buildWeekendGrid([away()], venues, teams, new Set(), habits, "2026-10-03", 15, new Map(), []);
    expect(withGhost.cells.filter((c) => true === c.ghost)).toHaveLength(1);
    expect(withGhost.cells.find((c) => true === c.away)?.estimated).toBe(true);
  });

  it("un week-end 100 % extérieur sans heure n'est PAS vide (grille = la bande seule)", () => {
    const grid = buildWeekendGrid([away()], venues, teams, new Set(), [], "2026-10-03");
    expect(grid.empty).toBe(false);
    expect(grid.cells.filter((c) => true === c.unknownHour)).toHaveLength(1);
    // Aucune rangée horaire (pas de contenu à heure) : seule la bande existe.
    expect(grid.rows.every((r) => true === r.unknownHour)).toBe(true);
  });
});

describe("weekLabel — l'axe SEMAINE (L7)", () => {
  it("étiquette lundi→dimanche de la semaine du samedi bucket", () => {
    // 2026-10-03 est un samedi → semaine du lundi 28 sept. au dimanche 4 oct.
    expect(weekLabel("2026-10-03")).toBe("Semaine du 28 sept. au 4 oct.");
  });
});

describe("resolveActiveWeekend — semaine par défaut (PR-1)", () => {
  const weekends = ["2026-08-29", "2026-09-12", "2026-09-26"]; // samedis, triés

  it("garde la sélection si elle est encore dans la liste", () => {
    expect(resolveActiveWeekend(weekends, "2026-09-12", "2026-09-12")).toBe("2026-09-12");
  });

  it("sans sélection : première semaine ≥ la semaine courante (pas la plus vieille)", () => {
    expect(resolveActiveWeekend(weekends, null, "2026-09-05")).toBe("2026-09-12");
  });

  it("sélection retirée par un filtre : repli sur la première semaine ≥ courante", () => {
    expect(resolveActiveWeekend(weekends, "2026-11-07", "2026-09-05")).toBe("2026-09-12");
  });

  it("tout est passé : repli sur la dernière semaine", () => {
    expect(resolveActiveWeekend(weekends, null, "2026-12-01")).toBe("2026-09-26");
  });

  it("une semaine dont la clé égale la semaine courante est éligible (≥, pas >)", () => {
    expect(resolveActiveWeekend(weekends, null, "2026-08-29")).toBe("2026-08-29");
  });

  it("aucune semaine : null", () => {
    expect(resolveActiveWeekend([], null, "2026-09-05")).toBeNull();
  });
});
