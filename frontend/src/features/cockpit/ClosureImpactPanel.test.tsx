import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { dayLabelLong, isoDayOf } from "@/shared/lib/days";

import { ClosureImpactPanel } from "./ClosureImpactPanel";
import { COCKPIT_TEXTS } from "./texts";

/**
 * Promesse (chantier « Fermetures de gymnase », PR 1, décisions C3 / C5 / E3) : l'aperçu d'une
 * fermeture dit, AVANT l'enregistrement, ce qu'elle touche — équipes à l'entraînement, matchs à
 * déplacer NOMMÉS, et les verrous / réservations qui seront RETIRÉS (« Enregistrer vaut geste ») —
 * sans jamais rien inventer : il AFFICHE la réponse de `/api/venue-closure-impact` (le serveur seul
 * calcule ; la parité aperçu == retrait est gardée côté serveur par
 * `ClosureImpactSaysTheSameEverywhereTest` / `ClosurePinRemoverTest`).
 *
 * Toute phrase est vérifiée par sa CLÉ `COCKPIT_TEXTS`, jamais recopiée.
 */

/** « 27/12 » — format j/n du plan (C3). */
const jn = (iso: string): string => `${Number(iso.slice(8, 10))}/${Number(iso.slice(5, 7))}`;

type Plan = {
  entryId: string;
  title: string;
  startDate: string;
  endDate: string;
  chosenScheduleId: string | null;
  loadedScheduleId: string | null;
  hasVersions: boolean;
  sessions: { teamId: string; teamName: string; date: string; venueId: string }[];
  toRemove: { locks: number; reservations: { teamId: string; teamName: string; dayOfWeek: number }[] };
  regeneratedWithout: boolean;
};

const plan = (over: Partial<Plan> = {}): Plan => ({
  entryId: "e-noel",
  title: "Vacances de Noël",
  startDate: "2999-12-19",
  endDate: "2999-12-31",
  chosenScheduleId: "s-noel",
  loadedScheduleId: "s-noel",
  hasVersions: true,
  sessions: [],
  toRemove: { locks: 0, reservations: [] },
  regeneratedWithout: false,
  ...over,
});

const match = (i: number, date: string, kickoffTime: string | null = null) => ({
  fixtureId: `f${i}`,
  teamId: `t${i}`,
  teamName: `Équipe ${i}`,
  opponentLabel: `Adversaire ${i}`,
  matchDate: date,
  kickoffTime,
});

const impact = (over: Record<string, unknown> = {}) => ({
  venueIds: ["v-mistral"],
  startDate: "2999-12-26",
  endDate: "2999-12-28",
  seasonPlanChosen: true,
  teams: [
    { teamId: "t-sm1", teamName: "SM1", tierLabel: "S", tierWeight: 100, occurrences: 2 },
    { teamId: "t-u13", teamName: "U13", tierLabel: "C", tierWeight: 30, occurrences: 1 },
  ],
  trainingOccurrences: 3,
  trainingSlotCount: 2,
  matchesToMove: [] as ReturnType<typeof match>[],
  governingPlans: [] as Plan[],
  fullyGoverned: false,
  closedSessions: [],
  ...over,
});

// Le nom du gymnase vient du formulaire (le gestionnaire vient de le choisir) : l'aperçu servi ne
// porte que des ids (§D). Contrat supposé par ce test, à trancher : prop `venueName`.
const renderPanel = (props: { impact: ReturnType<typeof impact> | null; state: "idle" | "loading" | "ready" | "failed" | "out_of_season" }) =>
  render(<ClosureImpactPanel venueName="Mistral" {...props} />);

describe("ClosureImpactPanel — l'aperçu d'une fermeture (C3)", () => {
  it("en vol : dit qu'il évalue, jamais un « aucune séance » rassurant", () => {
    renderPanel({ impact: null, state: "loading" });
    expect(screen.getByText(COCKPIT_TEXTS.closureImpactEvaluating)).toBeInTheDocument();
    expect(screen.queryByText(COCKPIT_TEXTS.closureImpactNone)).not.toBeInTheDocument();
  });

  it("lecture en échec : « impact non évalué — réessayez », jamais un vide", () => {
    renderPanel({ impact: null, state: "failed" });
    expect(screen.getByText(COCKPIT_TEXTS.closureImpactFailed)).toBeInTheDocument();
    expect(screen.queryByText(COCKPIT_TEXTS.closureImpactNone)).not.toBeInTheDocument();
  });

  it("au repos ou hors saison : n'affirme rien sur l'impact", () => {
    for (const state of ["idle", "out_of_season"] as const) {
      const { unmount } = renderPanel({ impact: null, state });
      expect(screen.queryByText(COCKPIT_TEXTS.closureImpactEvaluating)).not.toBeInTheDocument();
      expect(screen.queryByText(COCKPIT_TEXTS.closureImpactFailed)).not.toBeInTheDocument();
      expect(screen.queryByText(COCKPIT_TEXTS.closureImpactNone)).not.toBeInTheDocument();
      unmount();
    }
  });

  it("nomme le nombre d'équipes touchées et la plus prioritaire avec son rang", () => {
    renderPanel({ impact: impact(), state: "ready" });
    expect(screen.getByText(COCKPIT_TEXTS.closureImpactTeams(2, "SM1", "S"))).toBeInTheDocument();
  });

  it("aucune séance sur ces dates : le dit en clair", () => {
    renderPanel({ impact: impact({ teams: [], trainingOccurrences: 0, trainingSlotCount: 0 }), state: "ready" });
    expect(screen.getByText(COCKPIT_TEXTS.closureImpactNone)).toBeInTheDocument();
  });

  it("socle non pointé : impact sur les entraînements non évalué (jamais « aucune séance »)", () => {
    renderPanel({ impact: impact({ seasonPlanChosen: false, teams: [], trainingOccurrences: 0, trainingSlotCount: 0 }), state: "ready" });
    expect(screen.getByText(COCKPIT_TEXTS.closureImpactSeasonUnvalidated)).toBeInTheDocument();
    expect(screen.queryByText(COCKPIT_TEXTS.closureImpactNone)).not.toBeInTheDocument();
  });

  it("aucun match placé : « Aucun match placé à déplacer. »", () => {
    renderPanel({ impact: impact(), state: "ready" });
    expect(screen.getByText(COCKPIT_TEXTS.closureNoMatches)).toBeInTheDocument();
  });

  it("NOMME les matchs à déplacer (heure si connue), 5 au plus puis « + n autres » sur 8 matchs", () => {
    const dates = ["2999-12-26", "2999-12-26", "2999-12-27", "2999-12-27", "2999-12-27", "2999-12-28", "2999-12-28", "2999-12-28"];
    const matches = dates.map((d, i) => match(i + 1, d, 0 === i ? "18:00" : null));
    renderPanel({ impact: impact({ matchesToMove: matches }), state: "ready" });

    expect(screen.getByText(COCKPIT_TEXTS.closureMatchesHeading)).toBeInTheDocument();
    const line = (m: ReturnType<typeof match>) =>
      COCKPIT_TEXTS.closureMatchLine(m.teamName, m.opponentLabel, dayLabelLong(isoDayOf(m.matchDate)), jn(m.matchDate), m.kickoffTime ?? undefined);
    // Les 5 premiers (le serveur sert la liste COMPLÈTE triée par date, le front la borne).
    for (const m of matches.slice(0, 5)) {
      expect(screen.getByText(line(m))).toBeInTheDocument();
    }
    for (const m of matches.slice(5)) {
      expect(screen.queryByText(line(m))).not.toBeInTheDocument();
    }
    expect(screen.getByText(COCKPIT_TEXTS.closureMatchesMore(3))).toBeInTheDocument();
    expect(screen.queryByText(COCKPIT_TEXTS.closureNoMatches)).not.toBeInTheDocument();
  });

  it("annonce le retrait des verrous avec leur NOMBRE, au pluriel comme au singulier, par plan gouvernant", () => {
    renderPanel({
      impact: impact({
        governingPlans: [
          plan({ toRemove: { locks: 10, reservations: [] } }),
          plan({ entryId: "e-mars", title: "Vacances de mars", chosenScheduleId: "s-mars", loadedScheduleId: "s-mars", toRemove: { locks: 1, reservations: [] } }),
        ],
      }),
      state: "ready",
    });
    expect(screen.getByText(COCKPIT_TEXTS.closureLocksRemoval(10, "Mistral", "Vacances de Noël"))).toBeInTheDocument();
    expect(screen.getByText(COCKPIT_TEXTS.closureLocksRemoval(1, "Mistral", "Vacances de mars"))).toBeInTheDocument();
    // Un plan VALIDÉ touché sera périmé : la consigne de régénérer est dite AVANT d'enregistrer.
    expect(screen.getByText(COCKPIT_TEXTS.planStaleByClosureFuture("Vacances de Noël"))).toBeInTheDocument();
  });

  it("annonce le retrait des réservations (équipe + jour) au gymnase fermé", () => {
    const reservations = [
      { teamId: "t-sm1", teamName: "SM1", dayOfWeek: 2 },
      { teamId: "t-u13", teamName: "U13", dayOfWeek: 4 },
    ];
    renderPanel({ impact: impact({ governingPlans: [plan({ toRemove: { locks: 0, reservations } })] }), state: "ready" });
    expect(screen.getByText(COCKPIT_TEXTS.closureReservationsRemoval(reservations, "Mistral"))).toBeInTheDocument();
    // Zéro verrou : aucune ligne de verrou (jamais « 0 séance verrouillée »).
    expect(screen.queryByText(COCKPIT_TEXTS.closureLocksRemoval(0, "Mistral", "Vacances de Noël"))).not.toBeInTheDocument();
  });

  it("plan en cours (versions, rien de pointé) : l'annonce dit « ouvrez-le et régénérez-le »", () => {
    renderPanel({
      impact: impact({ governingPlans: [plan({ chosenScheduleId: null, loadedScheduleId: "s-noel-v2", toRemove: { locks: 2, reservations: [] } })] }),
      state: "ready",
    });
    expect(screen.getByText(COCKPIT_TEXTS.closureLocksRemoval(2, "Mistral", "Vacances de Noël"))).toBeInTheDocument();
    expect(screen.getByText(COCKPIT_TEXTS.planInProgressClosedFuture("Vacances de Noël"))).toBeInTheDocument();
  });

  it("E3 — plan SANS version : les réservations sont annoncées dès qu'elles existent, aucun verrou", () => {
    const reservations = [{ teamId: "t-sm1", teamName: "SM1", dayOfWeek: 2 }];
    renderPanel({
      impact: impact({
        governingPlans: [plan({ chosenScheduleId: null, loadedScheduleId: null, hasVersions: false, toRemove: { locks: 0, reservations } })],
      }),
      state: "ready",
    });
    expect(screen.getByText(COCKPIT_TEXTS.closureReservationsRemoval(reservations, "Mistral"))).toBeInTheDocument();
    expect(screen.queryByText(COCKPIT_TEXTS.closureLocksRemoval(0, "Mistral", "Vacances de Noël"))).not.toBeInTheDocument();
  });

  it("plan SANS version ni réservation : aucune ligne de retrait ni de péremption", () => {
    renderPanel({
      impact: impact({ governingPlans: [plan({ chosenScheduleId: null, loadedScheduleId: null, hasVersions: false })] }),
      state: "ready",
    });
    expect(screen.queryByText(COCKPIT_TEXTS.planStaleByClosureFuture("Vacances de Noël"))).not.toBeInTheDocument();
    expect(screen.queryByText(COCKPIT_TEXTS.planInProgressClosedFuture("Vacances de Noël"))).not.toBeInTheDocument();
  });
});
