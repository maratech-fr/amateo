import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Category, Coach, Fixture, Team, TeamMatchHabit, Venue } from "./api";
import type { HiddenWeekBreakdown } from "./lib/consultFilter";
import type { PlacementGuards } from "./PlacementPanel";
import { useMatchesStore } from "./store";
import { PLACE_HEADING_ID, WeekWorkbench } from "./WeekWorkbench";

// FRT-36 — FILET de l'établi de la semaine AVANT le découpage (PR B). Aucune ligne de
// production ne bouge. On mocke le module `./api` (le patron des tests frères) : les hooks de
// mutation (`./queries`) ne l'appellent qu'au `mutate`, la seule LECTURE au montage est
// `getVenueLabelInventory` (le bandeau des libellés non appariés). `useMe` mocké pour le radar.
const api = vi.hoisted(() => ({
  placeFixture: vi.fn(() => Promise.resolve({})),
  moveFixture: vi.fn(() => Promise.resolve({})),
  unplaceFixture: vi.fn(() => Promise.resolve({})),
  lockFixture: vi.fn(() => Promise.resolve({})),
  unlockFixture: vi.fn(() => Promise.resolve({})),
  deleteFixture: vi.fn(() => Promise.resolve()),
  swapFixtures: vi.fn(() => Promise.resolve()),
  submitFixture: vi.fn(() => Promise.resolve({})),
  reopenFixture: vi.fn(() => Promise.resolve({})),
  getVenueLabelInventory: vi.fn(() => Promise.resolve([])),
}));

vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: { role: "admin" } }) }));
vi.mock("./api", () => api);

const ACTIVE_WEEKEND = "2026-10-03"; // un samedi → bucket week-end = lui-même

const teamsMap = new Map<string, Team>([
  ["team-a", { id: "team-a", name: "Alpha", sportCategoryId: "cat-1", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
  ["team-b", { id: "team-b", name: "Beta", sportCategoryId: "cat-1", level: null, gender: null, priorityTierId: 1, tierOrder: 1 }],
]);
const venue: Venue = { id: "venue-1", name: "Gymnase Alpha", color: null, externalLabels: [] };
const venuesMap = new Map<string, Venue>([["venue-1", venue]]);
const categoriesMap = new Map<string, Category>([["cat-1", { id: "cat-1", name: "U13" }]]);
const coachesMap = new Map<string, Coach>();

/** Une rencontre à domicile PLACÉE par défaut (venue + heure connus) — surchargeable. */
function fx(over: Partial<Fixture> & { id: string }): Fixture {
  return {
    teamId: "team-a",
    seasonId: "s",
    competitionId: null, // amical → aucune enveloppe ligue ne bloque le placement
    matchDate: ACTIVE_WEEKEND,
    homeAway: "HOME",
    opponentLabel: "Adv",
    status: "PLACED",
    venueId: "venue-1",
    kickoffTime: "16:00",
    externalRef: null,
    fbiVenueLabel: null,
    placementSource: "MANUAL",
    unplacedReason: null,
    reviewState: "NEW",
    reviewedAt: null,
    pendingDeviations: [],
    ffbbRencontreId: null,
    suggestedVenueId: null,
    opponentOrganismeCode: null,
    opponentTeamKey: null,
    ...over,
  };
}

const guards: PlacementGuards = { state: "ready", matchWindows: [], unavailabilities: [], retry: vi.fn() };
const emptyBreakdown: HiddenWeekBreakdown = { total: 0, away: 0, byKind: new Map() };

type Props = Parameters<typeof WeekWorkbench>[0];

function baseProps(over: Partial<Props> = {}): Props {
  return {
    activeWeekend: ACTIVE_WEEKEND,
    weekendFixtures: [],
    filteredFixtures: [],
    allFixtures: [],
    radarConflicts: [],
    radarLoaded: true,
    seasonPlanChosen: true,
    conflictsError: false,
    teamsMap,
    venuesMap,
    categoriesMap,
    coachesMap,
    venues: [venue],
    guards,
    habits: [],
    resolvedTeamWindows: {},
    windows: [],
    outOfEnvelope: new Set<string>(),
    matchDurations: new Map<string, number>(),
    warmupDurations: new Map<string, number>(),
    newFingerprints: new Set<string>(),
    showGhosts: false,
    hiddenBreakdown: emptyBreakdown,
    onRevealHidden: vi.fn(),
    onEditFixture: vi.fn(),
    focusedConflict: null,
    onFocusConflict: vi.fn(),
    onQuitFocus: vi.fn(),
    onPlaceWeekend: vi.fn(),
    placePending: false,
    placeCreditsBlocked: false,
    placeCreditSuffix: "",
    ...over,
  };
}

function renderWorkbench(over: Partial<Props> = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <WeekWorkbench {...baseProps(over)} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  useMatchesStore.setState({ selectedFixtureId: null, highlightedFixtureIds: [], swapSourceId: null, selectedWeekend: null, unplacedReasons: new Map() });
});

describe("WeekWorkbench — échange (swap)", () => {
  it("source armée + clic sur une cible PLACED → swapFixtures(a=source, b=cible), sortie du mode, sélection nulle", async () => {
    const source = fx({ id: "fx-src", teamId: "team-a", opponentLabel: "AdvSrc", kickoffTime: "16:00", status: "PLACED", placementSource: "MANUAL" });
    const target = fx({ id: "fx-tgt", teamId: "team-b", opponentLabel: "AdvTgt", kickoffTime: "18:00", status: "PLACED", placementSource: "SOLVER" });
    useMatchesStore.setState({ swapSourceId: "fx-src", selectedFixtureId: null });
    const user = userEvent.setup();
    renderWorkbench({ weekendFixtures: [source, target], allFixtures: [source, target] });
    await user.click(await screen.findByRole("button", { name: /AdvTgt/ }));
    await waitFor(() => expect(api.swapFixtures).toHaveBeenCalledTimes(1));
    // Le BON ordre : a = la source armée, b = la cible cliquée.
    expect(api.swapFixtures).toHaveBeenCalledWith(expect.objectContaining({ id: "fx-src" }), expect.objectContaining({ id: "fx-tgt" }));
    expect(useMatchesStore.getState().swapSourceId).toBeNull();
    expect(useMatchesStore.getState().selectedFixtureId).toBeNull();
  });

  it("annulation par re-clic sur la source = zéro mutation, mode quitté", async () => {
    const source = fx({ id: "fx-src", teamId: "team-a", opponentLabel: "AdvSrc", kickoffTime: "16:00", status: "PLACED", placementSource: "MANUAL" });
    const other = fx({ id: "fx-oth", teamId: "team-b", opponentLabel: "AdvOth", kickoffTime: "18:00", status: "PLACED", placementSource: "SOLVER" });
    useMatchesStore.setState({ swapSourceId: "fx-src" });
    const user = userEvent.setup();
    renderWorkbench({ weekendFixtures: [source, other], allFixtures: [source, other] });
    await user.click(await screen.findByRole("button", { name: /AdvSrc/ }));
    expect(api.swapFixtures).not.toHaveBeenCalled();
    await waitFor(() => expect(useMatchesStore.getState().swapSourceId).toBeNull());
  });

  it("une cible non-PLACED (« à confirmer ») est inerte en mode échange", async () => {
    const source = fx({ id: "fx-src", teamId: "team-a", opponentLabel: "AdvSrc", status: "PLACED", placementSource: "MANUAL" });
    // HOME + gymnase + heure ⇒ sur la grille, mais statut UNPLACED ⇒ case « à confirmer », pas PLACED.
    const confirm = fx({ id: "fx-conf", teamId: "team-b", opponentLabel: "AdvConf", kickoffTime: "18:00", status: "UNPLACED", placementSource: null });
    useMatchesStore.setState({ swapSourceId: "fx-src" });
    const user = userEvent.setup();
    renderWorkbench({ weekendFixtures: [source, confirm], allFixtures: [source, confirm] });
    await user.click(await screen.findByRole("button", { name: /AdvConf/ }));
    expect(api.swapFixtures).not.toHaveBeenCalled();
    // Le mode reste armé : le clic inerte ne l'a pas quitté.
    expect(useMatchesStore.getState().swapSourceId).toBe("fx-src");
  });
});

describe("WeekWorkbench — clic sur un extérieur (correctif 3, lecture seule / édition)", () => {
  it("extérieur IMPORTÉ (externalRef) → fiche LECTURE SEULE avec les temps, JAMAIS la modale d'édition", async () => {
    const away = fx({ id: "fx-imp", teamId: "team-a", opponentLabel: "AdvImport", homeAway: "AWAY", status: "UNPLACED", venueId: null, kickoffTime: null, externalRef: "999", placementSource: null });
    const onEdit = vi.fn();
    const user = userEvent.setup();
    // On clique la CELLULE de la grille (`data-away`), pas la corbeille/crayon de la bande extérieur.
    const { container } = renderWorkbench({ weekendFixtures: [away], allFixtures: [away], onEditFixture: onEdit });
    await user.click(container.querySelector('[data-away="true"]') as HTMLElement);
    // La fiche lecture seule s'ouvre (titre + une info de temps), l'édition n'est PAS appelée.
    expect(await screen.findByText("Match à l'extérieur")).toBeInTheDocument();
    expect(screen.getByText("Durée du match")).toBeInTheDocument();
    expect(onEdit).not.toHaveBeenCalled();
  });

  it("extérieur SAISI À LA MAIN (amical) → la modale d'édition, pas de fiche lecture seule", async () => {
    const away = fx({ id: "fx-man", teamId: "team-a", opponentLabel: "AdvManuel", homeAway: "AWAY", status: "UNPLACED", venueId: null, kickoffTime: null, externalRef: null, ffbbRencontreId: null, placementSource: null });
    const onEdit = vi.fn();
    const user = userEvent.setup();
    const { container } = renderWorkbench({ weekendFixtures: [away], allFixtures: [away], onEditFixture: onEdit });
    await user.click(container.querySelector('[data-away="true"]') as HTMLElement);
    expect(onEdit).toHaveBeenCalledTimes(1);
    expect(screen.queryByText("Match à l'extérieur")).not.toBeInTheDocument();
  });
});

describe("WeekWorkbench — crayon de la bande extérieurs (correctif 3b)", () => {
  it("bande : extérieur IMPORTÉ → « Voir » ouvre la fiche LECTURE SEULE, jamais l'édition", async () => {
    const away = fx({ id: "fx-imp", teamId: "team-a", opponentLabel: "AdvImport", homeAway: "AWAY", status: "UNPLACED", venueId: null, kickoffTime: null, externalRef: "999", placementSource: null });
    const onEdit = vi.fn();
    const user = userEvent.setup();
    renderWorkbench({ weekendFixtures: [away], allFixtures: [away], onEditFixture: onEdit });
    // Le bouton de la BANDE (pas la cellule de grille) : son libellé dit « Voir », l'action ouvre la fiche.
    await user.click(await screen.findByRole("button", { name: "Voir le match contre AdvImport" }));
    expect(await screen.findByText("Match à l'extérieur")).toBeInTheDocument();
    expect(onEdit).not.toHaveBeenCalled();
  });

  it("bande : extérieur SAISI À LA MAIN → « Modifier » ouvre l'édition, pas la fiche", async () => {
    const away = fx({ id: "fx-man", teamId: "team-a", opponentLabel: "AdvManuel", homeAway: "AWAY", status: "UNPLACED", venueId: null, kickoffTime: null, externalRef: null, ffbbRencontreId: null, placementSource: null });
    const onEdit = vi.fn();
    const user = userEvent.setup();
    renderWorkbench({ weekendFixtures: [away], allFixtures: [away], onEditFixture: onEdit });
    await user.click(await screen.findByRole("button", { name: "Modifier le match contre AdvManuel" }));
    expect(onEdit).toHaveBeenCalledTimes(1);
    expect(screen.queryByText("Match à l'extérieur")).not.toBeInTheDocument();
  });
});

describe("WeekWorkbench — placement (« Placer » / « Déplacer »)", () => {
  it("domicile PLACÉ + heure changée → moveFixture, recadre selectedWeekend, rend le focus à la cellule", async () => {
    const sel = fx({ id: "fx-sel", teamId: "team-a", opponentLabel: "AdvSel", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", placementSource: "MANUAL" });
    useMatchesStore.setState({ selectedFixtureId: "fx-sel", selectedWeekend: null });
    const user = userEvent.setup();
    renderWorkbench({ weekendFixtures: [sel], allFixtures: [sel] });
    // Changer l'heure débloque « Déplacer » (sinon `unchanged` fige le bouton).
    fireEvent.change(await screen.findByLabelText("Heure de coup d'envoi"), { target: { value: "17:00" } });
    await user.click(screen.getByRole("button", { name: "Déplacer" }));
    await waitFor(() => expect(api.moveFixture).toHaveBeenCalledTimes(1));
    expect(api.placeFixture).not.toHaveBeenCalled();
    await waitFor(() => expect(useMatchesStore.getState().selectedWeekend).toBe("2026-10-03"));
    // La cellule du match est sur la grille → elle reçoit le focus (rAF, d'où le waitFor).
    await waitFor(() => expect(document.activeElement).toBe(document.querySelector('[data-fixture-id="fx-sel"]')));
  });

  it("domicile à placer → placeFixture ; sans cellule sur la grille, le focus se replie sur le <h2> « À placer »", async () => {
    // Sans gymnase → jamais sur la grille : focusPlaced ne trouve pas la cellule et retombe sur le titre.
    const sel = fx({ id: "fx-new", teamId: "team-a", opponentLabel: "AdvNew", status: "UNPLACED", venueId: null, kickoffTime: null, placementSource: null });
    useMatchesStore.setState({ selectedFixtureId: "fx-new", selectedWeekend: null });
    const user = userEvent.setup();
    renderWorkbench({ weekendFixtures: [sel], allFixtures: [sel] });
    // Le gymnase par défaut du panneau est le seul du club ; il ne reste qu'à poser l'heure.
    fireEvent.change(await screen.findByLabelText("Heure de coup d'envoi"), { target: { value: "15:00" } });
    await user.click(screen.getByRole("button", { name: "Placer" }));
    await waitFor(() => expect(api.placeFixture).toHaveBeenCalledTimes(1));
    expect(api.moveFixture).not.toHaveBeenCalled();
    await waitFor(() => expect(document.activeElement?.id).toBe(PLACE_HEADING_ID));
  });
});

describe("WeekWorkbench — verrou", () => {
  it("source SOLVER → verrouille (lockFixture)", async () => {
    const sel = fx({ id: "fx-solv", opponentLabel: "AdvSolv", status: "PLACED", placementSource: "SOLVER", venueId: "venue-1", kickoffTime: "16:00" });
    useMatchesStore.setState({ selectedFixtureId: "fx-solv" });
    const user = userEvent.setup();
    renderWorkbench({ weekendFixtures: [sel], allFixtures: [sel] });
    await user.click(await screen.findByRole("button", { name: "Verrouiller" }));
    expect(api.lockFixture).toHaveBeenCalledTimes(1);
    expect(api.unlockFixture).not.toHaveBeenCalled();
  });

  it("source MANUAL → déverrouille (unlockFixture)", async () => {
    const sel = fx({ id: "fx-man", opponentLabel: "AdvMan", status: "PLACED", placementSource: "MANUAL", venueId: "venue-1", kickoffTime: "16:00" });
    useMatchesStore.setState({ selectedFixtureId: "fx-man" });
    const user = userEvent.setup();
    renderWorkbench({ weekendFixtures: [sel], allFixtures: [sel] });
    await user.click(await screen.findByRole("button", { name: "Rendre au système" }));
    expect(api.unlockFixture).toHaveBeenCalledTimes(1);
    expect(api.lockFixture).not.toHaveBeenCalled();
  });
});

describe("WeekWorkbench — avertissements & radar", () => {
  it("« planning non validé » PRIME sur « conflits non vérifiés » (ordre du ternaire)", async () => {
    renderWorkbench({ seasonPlanChosen: false, conflictsError: true });
    expect(await screen.findByText("Le planning de la saison n'est plus validé — les conflits avec les entraînements ne sont pas évalués.")).toBeInTheDocument();
    expect(screen.queryByText(/Les conflits n'ont pas pu être vérifiés/)).not.toBeInTheDocument();
  });

  it("radarLoaded=false ⇒ aucun radar (jamais un faux « aucun clash »)", async () => {
    renderWorkbench({ radarLoaded: false, radarConflicts: [] });
    // Laisse le montage se stabiliser (la lecture du bandeau) avant de constater l'absence.
    await waitFor(() => expect(api.getVenueLabelInventory).toHaveBeenCalled());
    expect(screen.queryByText("Aucun conflit détecté.")).not.toBeInTheDocument();
  });

  it("radarLoaded=true ⇒ le radar est rendu", async () => {
    renderWorkbench({ radarLoaded: true, radarConflicts: [] });
    expect(await screen.findByText("Aucun conflit détecté.")).toBeInTheDocument();
  });
});

describe("WeekWorkbench — badges de signal (rendus seulement si > 0, pluriels)", () => {
  it("« hors modèle » : absent à 0, singulier à 1, pluriel à 2", async () => {
    // Une habitude un jour AUTRE que le samedi du match ⇒ le domicile placé le samedi est hors modèle.
    const habits: TeamMatchHabit[] = [
      { id: "h-a", teamId: "team-a", dayOfWeek: 1, kickoffTime: "18:00", venueId: "venue-1", week: "A" },
      { id: "h-b", teamId: "team-b", dayOfWeek: 1, kickoffTime: "18:00", venueId: "venue-1", week: "A" },
    ];
    const first = renderWorkbench({ weekendFixtures: [], allFixtures: [], habits });
    await waitFor(() => expect(api.getVenueLabelInventory).toHaveBeenCalled());
    expect(screen.queryByText(/hors modèle/)).not.toBeInTheDocument();
    first.unmount();

    const one = fx({ id: "fx-1", teamId: "team-a", opponentLabel: "AdvOne", status: "PLACED" });
    const second = renderWorkbench({ weekendFixtures: [one], allFixtures: [one], habits });
    expect(await screen.findByText("1 match hors modèle")).toBeInTheDocument();
    second.unmount();

    const two = fx({ id: "fx-2", teamId: "team-b", opponentLabel: "AdvTwo", status: "PLACED", kickoffTime: "18:00" });
    renderWorkbench({ weekendFixtures: [one, two], allFixtures: [one, two], habits });
    expect(await screen.findByText("2 matchs hors modèle")).toBeInTheDocument();
  });

  it("« créneau partagé » : absent à 0, singulier à 1, pluriel à 2 (P4-271, créneaux idéaux)", async () => {
    const homeA = fx({ id: "fx-a", teamId: "team-a", opponentLabel: "AdvA", status: "PLACED", kickoffTime: "16:00" });
    const homeB = fx({ id: "fx-b", teamId: "team-b", opponentLabel: "AdvB", status: "PLACED", kickoffTime: "18:00" });
    // Sans créneau idéal partagé : aucun signal.
    const first = renderWorkbench({ weekendFixtures: [homeA, homeB], allFixtures: [homeA, homeB], habits: [] });
    await waitFor(() => expect(api.getVenueLabelInventory).toHaveBeenCalled());
    expect(screen.queryByText(/créneau.*partagé/)).not.toBeInTheDocument();
    first.unmount();

    // Un créneau PHYSIQUE partagé : team-a (A) et team-b (B) sur le même gymnase+jour+heure.
    const sharedSlot = (venueId: string, kickoff: string): TeamMatchHabit[] => [
      { id: `${venueId}-a`, teamId: "team-a", dayOfWeek: 6, kickoffTime: kickoff, venueId, week: "A" },
      { id: `${venueId}-b`, teamId: "team-b", dayOfWeek: 6, kickoffTime: kickoff, venueId, week: "B" },
    ];
    const second = renderWorkbench({ weekendFixtures: [homeA, homeB], allFixtures: [homeA, homeB], habits: sharedSlot("venue-1", "16:00") });
    expect(await screen.findByText(/^1 créneau partagé : deux équipes reçoivent ce week-end$/)).toBeInTheDocument();
    second.unmount();

    renderWorkbench({
      weekendFixtures: [homeA, homeB],
      allFixtures: [homeA, homeB],
      habits: [...sharedSlot("venue-1", "16:00"), ...sharedSlot("venue-2", "18:00")],
    });
    expect(await screen.findByText(/^2 créneaux partagés : deux équipes reçoivent ce week-end$/)).toBeInTheDocument();
  });
});

describe("WeekWorkbench — « Placer ce week-end » (P4-240 ④)", () => {
  it("le bouton place la fenêtre Lun→Dim de la semaine affichée (samedi 2026-10-03 → 28/09 au 04/10)", async () => {
    const onPlaceWeekend = vi.fn();
    const user = userEvent.setup();
    renderWorkbench({ onPlaceWeekend });
    await user.click(await screen.findByRole("button", { name: /Placer ce week-end/ }));
    expect(onPlaceWeekend).toHaveBeenCalledTimes(1);
    expect(onPlaceWeekend).toHaveBeenCalledWith({ from: "2026-09-28", to: "2026-10-04" });
  });

  it("porte le suffixe de crédits comme le bouton global, et se désactive à pool épuisé", async () => {
    const onPlaceWeekend = vi.fn();
    const user = userEvent.setup();
    renderWorkbench({ onPlaceWeekend, placeCreditSuffix: " (3)", placeCreditsBlocked: true });
    const button = await screen.findByRole("button", { name: /Placer ce week-end \(3\)/ });
    expect(button).toBeDisabled();
    await user.click(button);
    expect(onPlaceWeekend).not.toHaveBeenCalled();
  });
});
