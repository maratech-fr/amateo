import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { createMemoryRouter, RouterProvider } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { frDateWeekdayNoYear } from "@/shared/lib/date";
import { SESSION_TEXTS } from "@/shared/session/texts";
import { useToastStore } from "@/shared/stores/toastStore";

import type { AttachVenueLabelResult, FbiIngestionLatest, Fixture, FixtureReviewState, LeagueValidationOutlook, PendingDeviation, ReviewFixturesResult, Venue, VenueLabelInventoryRow } from "./api";
import { ImportPage } from "./ImportPage";
import { fieldConsequence } from "./lib/deviationConsequence";
import { weekendKeyOf } from "./lib/weekendGrid";
import { useMatchesStore } from "./store";
import { MATCHES_TEXTS } from "./texts";

const {
  getTeams,
  getPriorityTiers,
  getFixtures,
  getVenues,
  getLatestFbiIngestion,
  getFfbbRencontres,
  applyFfbbRencontres,
  reviewFixtures,
  resolveFixtureDeviation,
  attachVenueLabel,
  getVenueLabelInventory,
  getLeagueValidationOutlook,
  confirmLeagueValidatedFixtures,
} = vi.hoisted(() => ({
  getTeams: vi.fn(),
  getPriorityTiers: vi.fn(() => Promise.resolve([{ id: 1, label: "S", name: "Fanion", color: null }, { id: 2, label: "A", name: "Réserve", color: null }])),
  getFixtures: vi.fn(),
  getVenues: vi.fn((): Promise<Venue[]> => Promise.resolve([{ id: "venue-1", name: "Gymnase Alpha", color: null, externalLabels: [] }])),
  getLatestFbiIngestion: vi.fn((): Promise<{ latest: FbiIngestionLatest | null }> => Promise.resolve({ latest: null })),
  getFfbbRencontres: vi.fn(),
  applyFfbbRencontres: vi.fn(() => Promise.resolve({ created: 0, updated: 0, unresolvedDeviations: [], depositedAt: "2026-08-24T14:06:00+00:00" })),
  reviewFixtures: vi.fn((): Promise<ReviewFixturesResult> => Promise.resolve({ reviewed: 1, skipped: [] })),
  resolveFixtureDeviation: vi.fn(() => Promise.resolve({ fixtureId: "x", reviewState: "REVIEWED", reviewedAt: "2026-10-02T10:00:00+00:00", pendingDeviations: [] })),
  attachVenueLabel: vi.fn((): Promise<AttachVenueLabelResult> => Promise.resolve({ venueId: "venue-1", label: "GYMNASE MATEO", attached: 2 })),
  getVenueLabelInventory: vi.fn((): Promise<VenueLabelInventoryRow[]> => Promise.resolve([])),
  getLeagueValidationOutlook: vi.fn(() => Promise.resolve<LeagueValidationOutlook>({ matured: [], toTreat: [], missingDeadline: [], totalValidatable: 0, swept: 0 })),
  confirmLeagueValidatedFixtures: vi.fn(() => Promise.resolve({ confirmed: 0 })),
}));

vi.mock("./api", () => ({ getTeams, getPriorityTiers, getFixtures, getVenues, getLatestFbiIngestion, getFfbbRencontres, applyFfbbRencontres, reviewFixtures, resolveFixtureDeviation, attachVenueLabel, getVenueLabelInventory, getLeagueValidationOutlook, confirmLeagueValidatedFixtures, getCompetitions: () => Promise.resolve([]) }));
// Chantier Membre (PR 2) : `useCanManage` piloté par cas (gestionnaire par défaut) ; le reste de la session reste réel.
const session = vi.hoisted(() => ({ canManage: true }));
vi.mock("@/shared/session/queries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/shared/session/queries")>()),
  useCanManage: () => session.canManage,
}));

/** ky 2.x expose le corps parsé sur `error.data` — on reproduit ce contrat pour le 422 nommé. */
function httpError(status: number, body: unknown): HTTPError {
  const error = new HTTPError(new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } }), new Request("http://localhost/api/venues/venue-1/external-labels"), {} as never);
  (error as unknown as { data?: unknown }).data = body;
  return error;
}

const teams = [
  { id: "team-1", name: "SM1", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
  { id: "team-2", name: "SF2", sportCategoryId: "c", level: null, gender: null, priorityTierId: 2, tierOrder: 0 },
];

let seq = 0;
function fx(teamId: string, reviewState: FixtureReviewState, matchDate: string, extra: Partial<Fixture> = {}): Fixture {
  seq += 1;
  return {
    id: `fx-${seq}`,
    teamId,
    seasonId: "s1",
    competitionId: null,
    matchDate,
    homeAway: "HOME",
    opponentLabel: "BRON",
    status: "PLACED",
    venueId: null,
    kickoffTime: null,
    externalRef: null,
    fbiVenueLabel: null,
    placementSource: null,
    unplacedReason: null,
    neutralGround: false,
    reviewState,
    reviewedAt: null,
    pendingDeviations: [],
    ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null,
    suggestedVenueId: null,
    ...extra,
  };
}

const dateDev: PendingDeviation = { field: "date", appValue: "2026-12-05", sourceValue: "2026-12-12", channel: "FBI_XLSX", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: false };
const autoDev: PendingDeviation = { field: "kickoff", appValue: "15:00", sourceValue: "16:00", channel: "FFBB_API", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: true };

function renderPage(fixtures: Fixture[], route = "/matchs/importer") {
  getTeams.mockResolvedValue(teams);
  getFixtures.mockResolvedValue(fixtures);
  getVenues.mockResolvedValue([{ id: "venue-1", name: "Gymnase Alpha", color: null, externalLabels: [] }]);
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const router = createMemoryRouter(
    [
      { path: "/matchs/importer", element: <ImportPage /> },
      { path: "/matchs", element: <div>BOUCLE</div> },
      { path: "/matchs/reconciliation", element: <div>RECONCILIATION</div> },
      { path: "/matchs/configuration", element: <div>CONFIG</div> },
    ],
    { initialEntries: [route] },
  );
  const utils = render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
  return { ...utils, router };
}

beforeEach(() => {
  seq = 0; // ids déterministes par test (fx-1, fx-2, …)
  vi.clearAllMocks();
  getPriorityTiers.mockResolvedValue([{ id: 1, label: "S", name: "Fanion", color: null }, { id: 2, label: "A", name: "Réserve", color: null }]);
  getLatestFbiIngestion.mockResolvedValue({ latest: null });
  applyFfbbRencontres.mockResolvedValue({ created: 0, updated: 0, unresolvedDeviations: [], depositedAt: "2026-08-24T14:06:00+00:00" });
  reviewFixtures.mockResolvedValue({ reviewed: 1, skipped: [] });
  getVenues.mockResolvedValue([{ id: "venue-1", name: "Gymnase Alpha", color: null, externalLabels: [] }]);
  attachVenueLabel.mockResolvedValue({ venueId: "venue-1", label: "GYMNASE MATEO", attached: 2 });
  getLeagueValidationOutlook.mockResolvedValue({ matured: [], toTreat: [], missingDeadline: [], totalValidatable: 0, swept: 0 });
  confirmLeagueValidatedFixtures.mockResolvedValue({ confirmed: 0 });
  useMatchesStore.setState({ reconciliation: null, filterMode: "equipe", filterIds: [], selectedWeekend: null, selectedFixtureId: null });
  useToastStore.setState({ toasts: [] });
});

describe("ImportPage — les entrées de données", () => {
  it("porte les trois entrées (Importer FBI · Vérifier via le Canal FFBB · Engagements FFBB) + la fraîcheur", async () => {
    renderPage([]);
    expect(await screen.findByRole("button", { name: /Importer FBI/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Vérifier via le Canal FFBB/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Engagements FFBB/ })).toBeInTheDocument();
    expect(screen.getByText(/Aucun dépôt FBI cette saison/i)).toBeInTheDocument();
  });

  /**
   * Chantier Membre (PR 2) : Matchs › Importer se LIT par un Membre (fraîcheur du dernier dépôt),
   * sous la bannière de consultation, sans aucune des trois entrées — dont deux appellent la FFBB
   * (aucun appel sortant déclenché par un Membre). Témoin : le cas précédent, même décor.
   */
  it("Membre : fraîcheur lisible, aucune entrée d'import, aucun appel FFBB", async () => {
    session.canManage = false;
    try {
      renderPage([]);
      expect(await screen.findByText(/Aucun dépôt FBI cette saison/i)).toBeInTheDocument();
      expect(screen.getByText(SESSION_TEXTS.readOnlyNotice)).toBeInTheDocument();
      expect(screen.queryByRole("button", { name: /Importer FBI/ })).not.toBeInTheDocument();
      expect(screen.queryByRole("button", { name: /Vérifier via le Canal FFBB/i })).not.toBeInTheDocument();
      expect(screen.queryByRole("button", { name: /Engagements FFBB/ })).not.toBeInTheDocument();
      expect(getFfbbRencontres).not.toHaveBeenCalled();
    } finally {
      session.canManage = true;
    }
  });

  it("un dépôt existe → fraîcheur en relatif", async () => {
    getLatestFbiIngestion.mockResolvedValueOnce({ latest: { depositedAt: "2026-08-22T09:00:00+00:00", depositedDate: "2026-08-22", source: "FBI_XLSX", created: 5, updated: 1, unchanged: 2, deviationsCount: 0 } });
    renderPage([]);
    expect(await screen.findByText(/Dernier dépôt FBI/i)).toBeInTheDocument();
  });

  it("E2 — des libellés non appariés ⇒ bandeau de renvoi dans la carte « Données de match »", async () => {
    getVenueLabelInventory.mockResolvedValueOnce([{ labelKey: "gymnase mateo", displayLabel: "GYMNASE MATEO", venueId: null, suggestedVenueId: null, homeCount: 3, placedCount: 0, unplacedCount: 3 }]);
    renderPage([]);
    expect(await screen.findByText(/1 libellé de salle non apparié/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Apparier les salles/ })).toBeInTheDocument();
  });

  it("E2 — tout apparié (inventaire vide) ⇒ aucun bandeau", async () => {
    renderPage([]);
    await screen.findByRole("button", { name: /Importer FBI/ });
    expect(screen.queryByText(/non apparié/)).not.toBeInTheDocument();
  });

  it("Lot L — un renvoi vers les « Échéances de saisie » (deep-link ?section=echeances)", async () => {
    const user = userEvent.setup();
    const { router } = renderPage([]);
    await user.click(await screen.findByRole("button", { name: /Échéances de saisie/ }));
    expect(await screen.findByText("CONFIG")).toBeInTheDocument();
    expect(router.state.location.search).toContain("section=echeances");
  });
});

const MATURED_OUTLOOK = {
  matured: [{ competitionId: "c1", name: "PNM", deadline: "2026-11-10", deadlineSource: "club" as const, maturedBy: "deadline" as const, firstMatchDate: null, validatableCount: 3 }],
  toTreat: [],
  missingDeadline: [],
  totalValidatable: 3,
  swept: 0,
};

describe("ImportPage — un championnat commencé passe seul « Déjà dans FBI » (aucun geste à proposer)", () => {
  it("des validables servis ⇒ aucun geste « déjà dans FBI » sur la page, aucune écriture déclenchée", async () => {
    getLeagueValidationOutlook.mockResolvedValue(MATURED_OUTLOOK);
    renderPage([]);
    await screen.findByRole("button", { name: /Importer FBI/ });
    await waitFor(() => expect(getLeagueValidationOutlook).toHaveBeenCalled());

    expect(screen.queryByRole("button", { name: /déjà dans FBI/i })).not.toBeInTheDocument();
    expect(confirmLeagueValidatedFixtures).not.toHaveBeenCalled();
  });
});

describe("ImportPage — la file de traitement", () => {
  it("« Rien à traiter » quand tout est traité", async () => {
    renderPage([fx("team-1", "REVIEWED", "2026-11-01", { reviewedAt: "2026-10-01T10:00:00+00:00" })]);
    expect(await screen.findByText("Rien à traiter")).toBeInTheDocument();
  });

  it("liste l'équipe avec son en-tête compté (à valider + écarts)", async () => {
    renderPage([fx("team-1", "NEW", "2026-11-07"), fx("team-1", "OUT_OF_SYNC", "2026-12-05", { pendingDeviations: [dateDev] })]);
    expect(await screen.findByRole("button", { name: /SM1 · 1 nouveauté à voir · 1 écart à trancher/ })).toBeInTheDocument();
  });

  it("Valider en ligne traite CETTE rencontre ({fixtureIds})", async () => {
    const user = userEvent.setup();
    renderPage([fx("team-1", "NEW", "2026-11-07")], "/matchs/importer?equipe=team-1");
    const validate = await screen.findByRole("button", { name: /^Marquer vu :/ });
    await user.click(validate);
    expect(reviewFixtures).toHaveBeenCalledWith({ fixtureIds: ["fx-1"] });
  });

  it("Tout marquer comme vu traite l'équipe ({teamId}) et NOMME les sautées (équipe + date, jamais l'id)", async () => {
    reviewFixtures.mockResolvedValueOnce({ reviewed: 1, skipped: [{ fixtureId: "fx-2", reason: "pending_deviations" }] });
    const user = userEvent.setup();
    renderPage([fx("team-1", "NEW", "2026-11-07"), fx("team-1", "OUT_OF_SYNC", "2026-12-05", { pendingDeviations: [dateDev] })], "/matchs/importer?equipe=team-1");
    await user.click(await screen.findByRole("button", { name: MATCHES_TEXTS.reviewMarkAllSeen }));
    expect(reviewFixtures).toHaveBeenCalledWith({ teamId: "team-1" });
    await waitFor(() => {
      const messages = useToastStore.getState().toasts.map((t) => t.message);
      // Lot prod Matchs PR 2 — le toast du geste équipe vit dans texts.ts : équipe, nombre, dates.
      expect(messages).toContain(MATCHES_TEXTS.reviewSkippedToast("SM1", 1, [frDateWeekdayNoYear("2026-12-05")]));
      // FALSIFICATION — l'id de la rencontre sautée n'apparaît jamais.
      expect(messages.some((m) => m.includes("fx-2"))).toBe(false);
    });
  });

  /** Forme « 0 saut » du geste équipe : aucun toast « laissées de côté » (jamais « 0 rencontre à écart »). */
  it("Tout marquer comme vu sans rencontre laissée de côté : aucun toast « laissées de côté »", async () => {
    reviewFixtures.mockResolvedValueOnce({ reviewed: 2, skipped: [] });
    const user = userEvent.setup();
    renderPage([fx("team-1", "NEW", "2026-11-07"), fx("team-1", "NEW", "2026-11-14")], "/matchs/importer?equipe=team-1");
    await user.click(await screen.findByRole("button", { name: MATCHES_TEXTS.reviewMarkAllSeen }));
    expect(reviewFixtures).toHaveBeenCalledWith({ teamId: "team-1" });
    // Le succès invalide la liste (2ᵉ lecture) : les rappels de succès ont alors tourné.
    await waitFor(() => expect(getFixtures).toHaveBeenCalledTimes(2));
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(useToastStore.getState().toasts.map((t) => t.message)).toEqual([]);
  });

  /**
   * Un écart se lit en deux colonnes Amateo / FBI avec la conséquence de chaque choix, et se
   * tranche ; sur un match déjà dans FBI, plus d'avertissement « repassera à Placé » (prendre le
   * fichier garde le statut).
   */
  it("un écart se lit en deux colonnes Amateo / FBI avec la conséquence, et se tranche", async () => {
    const user = userEvent.setup();
    renderPage([fx("team-1", "OUT_OF_SYNC", "2026-12-05", { status: "VALIDATED", pendingDeviations: [dateDev] })], "/matchs/importer?equipe=team-1");
    await screen.findByText("Amateo");
    expect(screen.getByText("FBI")).toBeInTheDocument();
    expect(screen.getByText("2026-12-05")).toBeInTheDocument();
    expect(screen.getByText("2026-12-12")).toBeInTheDocument();
    expect(screen.getByText(fieldConsequence("date").takeFile)).toBeInTheDocument();
    expect(screen.queryByText(/repasser à « Placé »/)).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Prendre FBI" }));
    expect(resolveFixtureDeviation).toHaveBeenCalledWith({ fixtureId: "fx-1", field: "date", choice: "take_source" });

    await user.click(screen.getByRole("button", { name: "Garder Amateo" }));
    expect(resolveFixtureDeviation).toHaveBeenCalledWith({ fixtureId: "fx-1", field: "date", choice: "keep_app" });
  });

  it("une valeur auto-appliquée hors périmètre se signale (bandeau), pas d'arbitrage", async () => {
    renderPage([fx("team-2", "OUT_OF_SYNC", "2026-12-05", { pendingDeviations: [autoDev] })], "/matchs/importer?equipe=team-2");
    // Bandeau P4-199 : « <source> a déplacé ce match (champ) : ancien → nouveau ».
    expect(await screen.findByText(/Canal FFBB a déplacé ce match.*15:00 → 16:00/)).toBeInTheDocument();
    // Rien à arbitrer → un « Valider » d'acquittement, pas de « Prendre FBI ».
    expect(screen.getByRole("button", { name: /^Marquer vu :/ })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Prendre/ })).not.toBeInTheDocument();
  });

  it("« Afficher les traitées » révèle les rencontres traitées", async () => {
    const user = userEvent.setup();
    renderPage([fx("team-1", "REVIEWED", "2026-11-01", { reviewedAt: "2026-10-01T10:00:00+00:00" })]);
    // Masquées par défaut → « Rien à traiter ».
    expect(await screen.findByText("Rien à traiter")).toBeInTheDocument();
    await user.click(screen.getByRole("checkbox", { name: "Afficher les traitées" }));
    expect(await screen.findByRole("button", { name: /SM1/ })).toBeInTheDocument();
  });

  it("traitées affichées : la liste d'une équipe reste triée par date (une traitée du 1er passe avant une à traiter du 7)", async () => {
    const user = userEvent.setup();
    // `?equipe=` ouvre l'accordéon de l'équipe : les lignes ne sont rendues que dépliées.
    renderPage([fx("team-1", "NEW", "2026-11-07"), fx("team-1", "REVIEWED", "2026-11-01", { reviewedAt: "2026-10-01T10:00:00+00:00" })], "/matchs/importer?equipe=team-1");
    await screen.findByRole("button", { name: /SM1/ });
    await user.click(screen.getByRole("checkbox", { name: "Afficher les traitées" }));
    // L'espace entre le jour et le mois est insécable (Intl fr) : \s le couvre.
    await screen.findByText(/\b1\snov\./);
    const dates = screen
      .getAllByRole("listitem")
      .map((li) => li.textContent ?? "")
      .map((t): string | null => (/\b1\snov\./.test(t) ? "1 nov." : /\b7\snov\./.test(t) ? "7 nov." : null))
      .filter((d): d is string => null !== d);
    expect(dates).toEqual(["1 nov.", "7 nov."]);
  });

  it("Replacer pose le filtre équipe + week-end + la rencontre sélectionnée, et renvoie vers le Calendrier", async () => {
    const user = userEvent.setup();
    // Rencontre À VENIR quel que soit le jour du run : « Replacer » n'est offert qu'avant le match.
    const upcoming = "2099-11-07";
    renderPage([fx("team-1", "NEW", upcoming)], "/matchs/importer?equipe=team-1");
    await user.click(await screen.findByRole("button", { name: "Replacer" }));
    const state = useMatchesStore.getState();
    expect(state.filterMode).toBe("equipe");
    expect(state.filterIds).toContain("team-1");
    expect(state.selectedWeekend).toBe(weekendKeyOf(upcoming));
    // PR 3b — le panneau de placement du Calendrier est PERMANENT : la rencontre pointée
    // suffit à l'ouvrir (plus de vue de rail à poser).
    expect(state.selectedFixtureId).toBe("fx-1");
    expect(await screen.findByText("BOUCLE")).toBeInTheDocument();
  });

  it("`?equipe=` ouvre l'accordéon de l'équipe au montage", async () => {
    renderPage([fx("team-1", "NEW", "2026-11-07")], "/matchs/importer?equipe=team-1");
    const header = await screen.findByRole("button", { name: /SM1/ });
    expect(header).toHaveAttribute("aria-expanded", "true");
    expect(within(header.closest("div") as HTMLElement).getByRole("button", { name: /^Marquer vu :/ })).toBeInTheDocument();
  });
});

describe("ImportPage — masquer les extérieurs (PR A)", () => {
  it("la bascule masque les AWAY : recompte l'en-tête, fait disparaître une équipe 100 % extérieur, et miroir ?exterieurs=masques", async () => {
    const user = userEvent.setup();
    const { router } = renderPage([
      fx("team-1", "NEW", "2026-11-07", { homeAway: "HOME" }),
      fx("team-1", "NEW", "2026-11-14", { homeAway: "AWAY" }),
      fx("team-2", "NEW", "2026-11-21", { homeAway: "AWAY" }),
    ]);
    // Avant : SM1 compte 2 à valider ; SF2 (100 % extérieur) est présente.
    expect(await screen.findByRole("button", { name: /SM1 · 2 nouveautés à voir/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /SF2/ })).toBeInTheDocument();

    await user.click(screen.getByRole("checkbox", { name: "Masquer les extérieurs" }));

    // Après : SM1 recompté à 1 (le domicile seul), SF2 disparue, URL en miroir.
    expect(await screen.findByRole("button", { name: /SM1 · 1 nouveauté à voir/ })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /SF2/ })).not.toBeInTheDocument();
    expect(router.state.location.search).toContain("exterieurs=masques");
  });

  it("montée avec ?exterieurs=masques : les extérieurs sont masqués d'emblée + case cochée", async () => {
    renderPage(
      [fx("team-1", "NEW", "2026-11-07", { homeAway: "HOME" }), fx("team-2", "NEW", "2026-11-21", { homeAway: "AWAY" })],
      "/matchs/importer?exterieurs=masques",
    );
    expect(await screen.findByRole("button", { name: /SM1 · 1 nouveauté à voir/ })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /SF2/ })).not.toBeInTheDocument();
    expect(screen.getByRole("checkbox", { name: "Masquer les extérieurs" })).toBeChecked();
  });
});

/**
 * Lot prod Matchs PR 2 — « Tout marquer comme vu — extérieurs » : un bouton GLOBAL, dans la rangée
 * des interrupteurs, acquitte d'un clic les extérieurs ouverts ({scope: "away"}) ; un toast dit
 * combien sont vus et NOMME (équipe + date, jamais l'id) ceux laissés de côté pour un écart à
 * trancher. Désactivé, raison écrite en clair à côté, quand aucun extérieur n'est acquittable ;
 * absent pour un Membre ; présent même extérieurs masqués, l'état vide disant alors qu'il reste des
 * extérieurs à marquer comme vus. Textes : clés de `texts.ts`, jamais la phrase.
 */
describe("ImportPage — tout marquer comme vu, extérieurs (lot prod Matchs PR 2)", () => {
  const awayGlobal = (): HTMLElement => screen.getByRole("button", { name: MATCHES_TEXTS.reviewMarkAwaySeen });

  /** Désactivé = `disabled` natif OU `aria-disabled` (les deux régimes de `Button`). */
  function expectDisabled(button: HTMLElement): void {
    expect((button as HTMLButtonElement).disabled || "true" === button.getAttribute("aria-disabled")).toBe(true);
  }

  /**
   * La raison est VISIBLE en clair à côté du bouton : rendue une seule fois (jamais doublée par le
   * `<span sr-only>` de `disabledReason` — exception « raison déjà en clair » d'A11Y-30), hors
   * `sr-only`, hors du bouton (son nom accessible reste le geste), sans infobulle `title`, et dans la
   * rangée du bouton — leur plus proche ancêtre commun ne contient ni la file ni l'état vide.
   */
  function expectReasonShownBeside(button: HTMLElement, reason: string): void {
    const occurrences = screen.getAllByText(reason);
    expect(occurrences).toHaveLength(1);
    const shown = occurrences[0];
    expect(shown.closest(".sr-only")).toBeNull();
    expect(button.contains(shown)).toBe(false);
    expect(button).not.toHaveAttribute("title");
    let row: HTMLElement | null = button.parentElement;
    while (null !== row && !row.contains(shown)) {
      row = row.parentElement;
    }
    expect(row).not.toBeNull();
    const shared = within(row as HTMLElement);
    expect(shared.queryByText(MATCHES_TEXTS.reviewHelp)).not.toBeInTheDocument();
    expect(shared.queryByText(MATCHES_TEXTS.reviewNothingToTreatTitle)).not.toBeInTheDocument();
    expect(shared.queryByRole("button", { name: /SM1|SF2/ })).not.toBeInTheDocument();
  }

  it("acquitte les extérieurs d'un clic ({scope: 'away'}) et dit combien en toast", async () => {
    reviewFixtures.mockResolvedValueOnce({ reviewed: 2, skipped: [] });
    const user = userEvent.setup();
    renderPage([
      fx("team-1", "NEW", "2026-12-05", { homeAway: "AWAY", status: "UNPLACED" }),
      fx("team-2", "REVIEWED", "2026-12-12", { homeAway: "AWAY", status: "UNPLACED", pendingDeviations: [autoDev] }),
      fx("team-1", "NEW", "2026-12-06"),
    ]);
    await screen.findByRole("button", { name: /SM1/ });
    expect(awayGlobal()).not.toHaveAttribute("aria-disabled", "true");
    await user.click(awayGlobal());
    expect(reviewFixtures).toHaveBeenCalledWith({ scope: "away" });
    await waitFor(() => {
      const messages = useToastStore.getState().toasts.map((t) => t.message);
      expect(messages).toContain(MATCHES_TEXTS.reviewAwaySeenToast(2, []));
    });
  });

  it("nomme l'extérieur laissé de côté (équipe + date) et jamais son id", async () => {
    reviewFixtures.mockResolvedValueOnce({ reviewed: 1, skipped: [{ fixtureId: "fx-2", reason: "pending_deviations" }] });
    const user = userEvent.setup();
    renderPage([
      fx("team-1", "NEW", "2026-12-12", { homeAway: "AWAY", status: "UNPLACED" }),
      // L'ex-domicile devenu extérieur, qui garde son écart de date à trancher.
      fx("team-2", "OUT_OF_SYNC", "2026-12-05", { homeAway: "AWAY", status: "UNPLACED", pendingDeviations: [dateDev] }),
    ]);
    await screen.findByRole("button", { name: /SM1/ });
    await user.click(awayGlobal());
    await waitFor(() => {
      const messages = useToastStore.getState().toasts.map((t) => t.message);
      expect(messages).toContain(MATCHES_TEXTS.reviewAwaySeenToast(1, [{ team: "SF2", date: frDateWeekdayNoYear("2026-12-05") }]));
      expect(messages.some((m) => m.includes("fx-2"))).toBe(false);
    });
  });

  /**
   * Contrôle visuel PR 2 : la raison du bouton désactivé n'était lisible qu'au survol / lecteur
   * d'écran — l'œil voyait un bouton grisé sans savoir pourquoi. Elle s'écrit désormais EN CLAIR à
   * côté du bouton (clé `reviewMarkAwaySeenDisabled`), une seule fois.
   */
  it("désactivé quand aucun extérieur n'est acquittable (seul un extérieur à écart à trancher) : la raison est écrite en clair à côté", async () => {
    const user = userEvent.setup();
    renderPage([
      fx("team-2", "OUT_OF_SYNC", "2026-12-05", { homeAway: "AWAY", status: "UNPLACED", pendingDeviations: [dateDev] }),
      // Un REVIEWED dont l'alerte appliquée d'office côtoie un écart à trancher n'est pas acquittable non plus.
      fx("team-2", "REVIEWED", "2026-12-12", { homeAway: "AWAY", status: "UNPLACED", pendingDeviations: [autoDev, dateDev] }),
      fx("team-1", "NEW", "2026-12-06"),
    ]);
    await screen.findByRole("button", { name: /SM1/ });
    expectDisabled(awayGlobal());
    expectReasonShownBeside(awayGlobal(), MATCHES_TEXTS.reviewMarkAwaySeenDisabled);
    await user.click(awayGlobal());
    expect(reviewFixtures).not.toHaveBeenCalled();
  });

  it("désactivé quand il n'y a aucun extérieur ouvert (seuls des domiciles à voir)", async () => {
    renderPage([fx("team-1", "NEW", "2026-12-06"), fx("team-2", "REVIEWED", "2026-12-12", { homeAway: "AWAY", status: "UNPLACED", reviewedAt: "2026-10-01T10:00:00+00:00" })]);
    await screen.findByRole("button", { name: /SM1/ });
    expectDisabled(awayGlobal());
  });

  /** Témoin du cas précédent : bouton actif ⇒ aucune raison de désactivation à l'écran (elle mentirait). */
  it("actif : la raison de désactivation n'est écrite nulle part", async () => {
    renderPage([fx("team-2", "NEW", "2026-12-05", { homeAway: "AWAY", status: "UNPLACED" })]);
    await screen.findByRole("button", { name: /SF2/ });
    expect(awayGlobal()).toBeEnabled();
    expect(awayGlobal()).not.toHaveAttribute("aria-disabled", "true");
    expect(screen.queryByText(MATCHES_TEXTS.reviewMarkAwaySeenDisabled)).not.toBeInTheDocument();
  });

  /**
   * Contrôle visuel PR 2 : extérieurs masqués, la file est vide à l'écran MAIS un extérieur reste à
   * marquer comme vu — « Toutes les rencontres importées sont à jour » contredirait le bouton actif
   * juste au-dessus. L'état vide dit alors en clair que les extérieurs masqués ont des changements à
   * marquer comme vus (clé `reviewNothingToTreatAwayHiddenDescription`). Témoin : le cas suivant.
   */
  it("extérieurs masqués avec un extérieur à voir : l'état vide ne dit pas « à jour », il renvoie aux extérieurs masqués", async () => {
    renderPage([fx("team-2", "NEW", "2026-12-05", { homeAway: "AWAY", status: "UNPLACED" })], "/matchs/importer?exterieurs=masques");
    expect(await screen.findByRole("button", { name: MATCHES_TEXTS.reviewMarkAwaySeen })).toBeEnabled();
    expect(screen.getByText(MATCHES_TEXTS.reviewNothingToTreatAwayHiddenDescription)).toBeInTheDocument();
    expect(screen.queryByText(MATCHES_TEXTS.reviewNothingToTreatDescription)).not.toBeInTheDocument();
  });

  it("extérieurs masqués sans extérieur acquittable : l'état vide d'origine, bouton désactivé raison en clair", async () => {
    renderPage([fx("team-2", "REVIEWED", "2026-12-12", { homeAway: "AWAY", status: "UNPLACED", reviewedAt: "2026-10-01T10:00:00+00:00" })], "/matchs/importer?exterieurs=masques");
    expect(await screen.findByText(MATCHES_TEXTS.reviewNothingToTreatDescription)).toBeInTheDocument();
    expect(screen.queryByText(MATCHES_TEXTS.reviewNothingToTreatAwayHiddenDescription)).not.toBeInTheDocument();
    expectDisabled(awayGlobal());
    expectReasonShownBeside(awayGlobal(), MATCHES_TEXTS.reviewMarkAwaySeenDisabled);
  });

  it("présent et actif même extérieurs masqués : le geste et son toast disent tout", async () => {
    reviewFixtures.mockResolvedValueOnce({ reviewed: 1, skipped: [] });
    const user = userEvent.setup();
    // Seuls des extérieurs à voir, masqués : la file est vide à l'écran, le geste reste offert.
    renderPage([fx("team-2", "NEW", "2026-12-05", { homeAway: "AWAY", status: "UNPLACED" })], "/matchs/importer?exterieurs=masques");
    await user.click(await screen.findByRole("button", { name: MATCHES_TEXTS.reviewMarkAwaySeen }));
    expect(reviewFixtures).toHaveBeenCalledWith({ scope: "away" });
    await waitFor(() => {
      expect(useToastStore.getState().toasts.map((t) => t.message)).toContain(MATCHES_TEXTS.reviewAwaySeenToast(1, []));
    });
  });

  it("un refus du serveur (saison archivée, 409) est toasté tel quel", async () => {
    reviewFixtures.mockRejectedValueOnce(httpError(409, { detail: "Cette saison est archivée — elle est en lecture seule." }));
    const user = userEvent.setup();
    renderPage([fx("team-2", "NEW", "2026-12-05", { homeAway: "AWAY", status: "UNPLACED" })]);
    await user.click(await screen.findByRole("button", { name: MATCHES_TEXTS.reviewMarkAwaySeen }));
    await waitFor(() => {
      expect(useToastStore.getState().toasts.map((t) => t.message)).toContain("Cette saison est archivée — elle est en lecture seule.");
    });
  });

  it("Membre : aucun geste global sur les extérieurs", async () => {
    session.canManage = false;
    try {
      renderPage([fx("team-2", "NEW", "2026-12-05", { homeAway: "AWAY", status: "UNPLACED" })]);
      await screen.findByRole("button", { name: /SF2/ });
      expect(screen.queryByRole("button", { name: MATCHES_TEXTS.reviewMarkAwaySeen })).not.toBeInTheDocument();
    } finally {
      session.canManage = true;
    }
  });
});

describe("ImportPage — rattacher un gymnase depuis le libellé (P4-187b)", () => {
  it("l'en-tête compte les domiciles sans gymnase (« N sans gymnase »)", async () => {
    renderPage([fx("team-1", "NEW", "2026-11-07", { fbiVenueLabel: "GYMNASE MATEO" })]);
    expect(await screen.findByRole("button", { name: /SM1 · 1 nouveauté à voir · 1 sans gymnase/ })).toBeInTheDocument();
  });

  it("Confirmer poste le libellé BRUT sur le gymnase choisi + toast succès nommant le gymnase", async () => {
    const user = userEvent.setup();
    renderPage([fx("team-1", "NEW", "2026-11-07", { fbiVenueLabel: "GYMNASE MATEO", suggestedVenueId: "venue-1" })], "/matchs/importer?equipe=team-1");
    await user.click(await screen.findByRole("button", { name: "Rattacher" }));
    await user.click(screen.getByRole("button", { name: "Confirmer" }));
    expect(attachVenueLabel).toHaveBeenCalledWith({ venueId: "venue-1", label: "GYMNASE MATEO" });
    await waitFor(() => {
      const messages = useToastStore.getState().toasts.map((t) => t.message);
      expect(messages.some((m) => m.includes("Gymnase Alpha") && m.includes("2 domiciles"))).toBe(true);
    });
  });

  it("un 422 nommé du serveur est affiché TEL QUEL (message serveur, pas un repli)", async () => {
    attachVenueLabel.mockRejectedValueOnce(httpError(422, { error: "Ce libellé est déjà porté par un autre gymnase. Retirez-le d'abord." }));
    const user = userEvent.setup();
    renderPage([fx("team-1", "NEW", "2026-11-07", { fbiVenueLabel: "GYMNASE MATEO", suggestedVenueId: "venue-1" })], "/matchs/importer?equipe=team-1");
    await user.click(await screen.findByRole("button", { name: "Rattacher" }));
    await user.click(screen.getByRole("button", { name: "Confirmer" }));
    await waitFor(() => {
      const messages = useToastStore.getState().toasts.map((t) => t.message);
      expect(messages.some((m) => m.includes("déjà porté par un autre gymnase"))).toBe(true);
    });
  });
});

describe("ImportPage — Vérifier via le Canal FFBB (D3, cas a/b/c)", () => {
  it("(a) des rencontres à créer → payload API porté + navigation vers la vue d'intégration", async () => {
    getFfbbRencontres.mockResolvedValueOnce({
      deviations: [],
      creatable: [{ rencontreId: "renc-a", competitionNom: "AMICAL", date: "2026-09-23", kickoff: "20:00", homeAway: "HOME", opponentLabel: "BRON", venueLabel: null, numeroJournee: null, suggestedTeamId: null }],
      fetchedAt: "2026-08-24T14:05:00+00:00",
    });
    const user = userEvent.setup();
    renderPage([]);
    await user.click(await screen.findByRole("button", { name: /Vérifier via le Canal FFBB/i }));
    await waitFor(() => expect(getFfbbRencontres).toHaveBeenCalledOnce());
    const carried = useMatchesStore.getState().reconciliation;
    expect(carried?.channel).toBe("api");
    expect(await screen.findByText("RECONCILIATION")).toBeInTheDocument();
    // FALSIFICATION — aucun apply dans le cas « à créer » (la vue applique).
    expect(applyFfbbRencontres).not.toHaveBeenCalled();
  });

  it("(b) que des écarts → apply sans création (les consigne dans la file)", async () => {
    getFfbbRencontres.mockResolvedValueOnce({
      deviations: [{ fixtureId: "fx-1", externalRef: "1", division: "DF2", teamId: "team-1", status: "PLACED", persisting: false, fields: { date: { app: "a", file: "b" } } }],
      creatable: [],
      fetchedAt: "2026-08-24T14:05:00+00:00",
    });
    const user = userEvent.setup();
    renderPage([]);
    await user.click(await screen.findByRole("button", { name: /Vérifier via le Canal FFBB/i }));
    await waitFor(() => expect(applyFfbbRencontres).toHaveBeenCalledWith([], []));
    // Pas de navigation vers la vue d'intégration (rien à créer).
    expect(screen.queryByText("RECONCILIATION")).not.toBeInTheDocument();
  });

  it("(c) rien à faire → aucun apply, on ne date pas un dépôt pour rien", async () => {
    getFfbbRencontres.mockResolvedValueOnce({ deviations: [], creatable: [], fetchedAt: "2026-08-24T14:05:00+00:00" });
    const user = userEvent.setup();
    renderPage([]);
    await user.click(await screen.findByRole("button", { name: /Vérifier via le Canal FFBB/i }));
    await waitFor(() => expect(getFfbbRencontres).toHaveBeenCalledOnce());
    expect(applyFfbbRencontres).not.toHaveBeenCalled();
    expect(useMatchesStore.getState().reconciliation).toBeNull();
    const messages = useToastStore.getState().toasts.map((t) => t.message);
    expect(messages.some((m) => /en phase/i.test(m))).toBe(true);
  });
});
