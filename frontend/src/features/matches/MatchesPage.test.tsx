import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { setTodayOverride } from "@/shared/lib/clock";
import { pickListboxOption } from "@/test/pickListboxOption";
import { renderWithProviders } from "@/test/utils";

import * as matchesApi from "./api";
import { MatchesPage } from "./MatchesPage";
import { useMatchesStore } from "./store";

const { placeFixture, unplaceFixture, submitFixture } = vi.hoisted(() => ({
  placeFixture: vi.fn(() => Promise.resolve({})),
  unplaceFixture: vi.fn(() => Promise.resolve({})),
  submitFixture: vi.fn(() => Promise.resolve({})),
}));

// Matches are unlocked only once the season's socle is validated. `club`
// (avec entitlements) est mutable pour piloter le solde de crédits par test.
const meState = vi.hoisted(() => ({ club: undefined as Record<string, unknown> | undefined }));
vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: "s1", hasFinishedVersion: true }, club: meState.club } }),
}));

// PR-1 — les jointures coach⇄équipe viennent de planning/queries. Mutables par test
// (expansion du filtre « par coach »).
const planningLinks = vi.hoisted(() => ({ teamCoaches: [] as unknown[], coachPlayers: [] as unknown[] }));
vi.mock("@/features/planning/queries", () => ({
  useTeamCoaches: () => ({ data: planningLinks.teamCoaches }),
  useCoachPlayers: () => ({ data: planningLinks.coachPlayers }),
}));

vi.mock("./api", () => ({
  getFixtures: vi.fn(() =>
    Promise.resolve([
      { id: "fx-unplaced", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Voisins", status: "UNPLACED", venueId: null, kickoffTime: null, externalRef: null },
      // externalRef renseigné → repère « n° 26 » rendu dans la grille (fait #2, jamais une clé).
      { id: "fx-placed", teamId: "team-2", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Rivaux", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", externalRef: "26" },
      // P1-4 PR E2 — an away match of the same weekend (visible in the away band).
      { id: "fx-away", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-04", homeAway: "AWAY", opponentLabel: "Grenoble", status: "UNPLACED", venueId: null, kickoffTime: null, fbiVenueLabel: "Halle Clemenceau", externalRef: null },
    ]),
  ),
  getCompetitions: vi.fn(() => Promise.resolve([])),
  getTeams: vi.fn(() =>
    Promise.resolve([
      { id: "team-1", name: "U13", sportCategoryId: "cat-1", level: null, gender: null, priorityTierId: 3, tierOrder: 0 },
      { id: "team-2", name: "Seniors", sportCategoryId: "cat-2", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
    ]),
  ),
  getPriorityTiers: vi.fn(() =>
    Promise.resolve([
      { id: 1, label: "S", name: "Fanion", color: null },
      { id: 3, label: "B", name: "Moyenne", color: null },
    ]),
  ),
  getVenues: vi.fn(() => Promise.resolve([{ id: "venue-1", name: "Gymnase Alpha", color: "#00aa00", externalLabels: [] }])),
  getCategories: vi.fn(() => Promise.resolve([{ id: "cat-1", name: "U13" }, { id: "cat-2", name: "Seniors" }])),
  getCoaches: vi.fn(() => Promise.resolve([{ id: "coach-1", firstName: "Jean", lastName: "Dupont" }])),
  getLeagueWindows: vi.fn(() => Promise.resolve({ league: "AURA", items: [], resolvedTeamWindows: {} })),
  // Capacity layer (P1-4 PR B) — empty: no window declared, nothing blocks.
  getVenueMatchWindows: vi.fn(() => Promise.resolve([])),
  getVenueUnavailabilities: vi.fn(() => Promise.resolve([])),
  // Preferences layer (P1-4 PR C) — empty: no habit, no link.
  getTeamMatchHabits: vi.fn(() => Promise.resolve([])),
  getTeamLinks: vi.fn(() => Promise.resolve([])),
  getMatchSlotRotations: vi.fn(() => Promise.resolve([])),
  // Auto-placement (P1-4 PR D).
  placeMatches: vi.fn(() =>
    Promise.resolve({
      placed: 1,
      skipped: 0,
      unplaced: [{ matchId: "fx-unplaced", reason: "no_access_window", message: "Aucune fenêtre d'accès match ne contient l'empreinte de 2h15 ce jour-là." }],
      diagnostics: [],
    }),
  ),
  getConflicts: vi.fn(() =>
    Promise.resolve({
      clubId: "c",
      seasonId: "s",
      conflicts: [
        {
          type: "MATCH_MATCH",
          severity: 3,
          coachRole: "MAIN",
          coachId: "coach-1",
          start: "2026-10-03T15:30:00+00:00",
          end: "2026-10-03T16:00:00+00:00",
          left: { fixtureId: "fx-unplaced", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: null, windowStart: "", windowEnd: "" },
          right: { fixtureId: "fx-placed", teamId: "team-2", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" },
        },
      ],
    }),
  ),
  createFixture: vi.fn(() => Promise.resolve({})),
  placeFixture,
  // Manual loop (P1-4 PR E1).
  updateFixture: vi.fn(() => Promise.resolve({})),
  deleteFixture: vi.fn(() => Promise.resolve()),
  unplaceFixture,
  moveFixture: vi.fn(() => Promise.resolve({})),
  lockFixture: vi.fn(() => Promise.resolve({})),
  unlockFixture: vi.fn(() => Promise.resolve({})),
  swapFixtures: vi.fn(() => Promise.resolve()),
  // RMM-1 PR1/PR3 — close the loop.
  submitFixture,
  reopenFixture: vi.fn(() => Promise.resolve({})),
  // RMM-3 — le « gardien » : par défaut première visite (muet, aucun bandeau) pour
  // ne pas perturber les assertions des autres tests. Surchargé au besoin.
  postModuleVisit: vi.fn(() => Promise.resolve({ firstVisit: true, newFixturesCount: 0, newConflictFingerprints: [], planningChanged: false, referenceTakenAt: "2026-08-24T10:00:00+00:00" })),
  // RMM-4 — la fraîcheur : un dépôt existe → rappel discret près du rail semaine.
  getLatestFbiIngestion: vi.fn(() => Promise.resolve({ latest: { depositedAt: "2026-08-20T09:00:00+00:00", source: "FBI_XLSX", created: 10, updated: 2, unchanged: 3, deviationsCount: 0 } })),
  getOpponentTravel: vi.fn(() => Promise.resolve([])),
}));

beforeEach(() => {
  placeFixture.mockClear();
  unplaceFixture.mockClear();
  submitFixture.mockClear();
  meState.club = undefined;
  planningLinks.teamCoaches = [];
  planningLinks.coachPlayers = [];
  setTodayOverride(null);
  useMatchesStore.setState({ selectedWeekend: null, railStep: null, selectedFixtureId: null, swapSourceId: null, fixtureFormOpen: false, importDialogOpen: false, filterMode: "equipe", filterIds: [] });
});

/** Click a rail step by its label (the rail is the only <nav> here). */
async function gotoStep(user: ReturnType<typeof userEvent.setup>, label: RegExp): Promise<void> {
  const rail = await screen.findByRole("navigation");
  await user.click(within(rail).getByRole("button", { name: label }));
}

describe("MatchesPage — la boucle guidée (RMM-1 PR3)", () => {
  it("au chargement, rend le FullPageSpinner PLEINE PAGE (cohérence cockpit/planning), pas un spinner nu", () => {
    // getFixtures qui ne résout jamais → isLoading reste vrai, on capture l'état de chargement.
    vi.mocked(matchesApi.getFixtures).mockReturnValueOnce(new Promise(() => {}));
    renderWithProviders(<MatchesPage />);
    // Discriminant : `FullPageSpinner` rend son Spinner en `size-8` (pleine page) ; l'ancien rendu
    // était un `<Spinner>` NU en `size-5` par défaut dans un `py-16` (demi-page). Le `.min-h-screen`
    // ne discrimine pas (le harness de test wrappe déjà) — c'est la taille qui prouve la primitive.
    expect(screen.getByLabelText("Chargement")).toHaveClass("size-8");
  });

  it("dérive un rail de 5 étapes ; le premier trou (Conflits) est la vue par défaut", async () => {
    renderWithProviders(<MatchesPage />);
    const rail = await screen.findByRole("navigation");
    // Les 5 étapes, comptes DANS le label (Conflits (1) : le conflit de W ; Saisi 0/2).
    expect(within(rail).getByRole("button", { name: /Batch importé/ })).toBeInTheDocument();
    expect(within(rail).getByRole("button", { name: /Placés au modèle/ })).toBeInTheDocument();
    expect(within(rail).getByRole("button", { name: /Conflits \(1\)/ })).toBeInTheDocument();
    expect(within(rail).getByRole("button", { name: /Domiciles posés/ })).toBeInTheDocument();
    expect(within(rail).getByRole("button", { name: /Saisi dans FBI \(0\/2\)/ })).toBeInTheDocument();
    // Premier trou = Conflits (batch+model done) → la vue radar est à l'écran d'emblée.
    expect(await screen.findByText("Jean Dupont")).toBeInTheDocument();
    expect(screen.getByText(/U13 et Seniors/)).toBeInTheDocument();
  });

  it("cliquer une étape change la VUE ; la vue sélectionnée reste même si elle est « done »", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);
    // « Placés au modèle » est DONE (défaut = Conflits) — le sélectionner l'affiche
    // quand même : le rail ne saute jamais sous le choix de l'utilisateur.
    await gotoStep(user, /Placés au modèle/);
    // Vue modèle : la grille (le domicile placé) est là, plus le radar.
    expect(await screen.findByRole("button", { name: /Seniors.*Rivaux/ })).toBeInTheDocument();
    expect(screen.queryByText("Jean Dupont")).not.toBeInTheDocument();
  });

  it("affiche le n° de rencontre comme repère, absent quand null (pas de « n° — »)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);
    await gotoStep(user, /Placés au modèle/);
    // fx-placed porte externalRef 26 → repère rendu ; fx-unplaced n'en a pas.
    expect(await screen.findByText("n° 26")).toBeInTheDocument();
    expect(screen.queryByText(/n° —/)).not.toBeInTheDocument();
  });

  it("étape « Saisi dans FBI » (L9) : liste par équipe, cocher = « Marquer saisi »", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);
    await gotoStep(user, /Saisi dans FBI/);
    // La vue de saisie L9 groupe par équipe : le seul domicile PLACED (Seniors)
    // a son en-tête de groupe, et sa ligne se coche par « Marquer saisi : … ».
    expect(await screen.findByRole("heading", { name: "Seniors" })).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /Marquer saisi.*Rivaux/ }));
    expect(submitFixture).toHaveBeenCalledWith(expect.objectContaining({ id: "fx-placed" }));
  });

  it("liste le match à placer et pose le domicile (vue Domiciles)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);
    await gotoStep(user, /Domiciles posés/);

    // Vue Domiciles : la liste « à placer ».
    await user.click(await screen.findByRole("button", { name: /vs Voisins/ }));
    await screen.findByRole("button", { name: /Gymnase/ }); // le panneau de placement est monté
    await pickListboxOption(user, "Gymnase", "Gymnase Alpha");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "15:00");
    await user.click(screen.getByRole("button", { name: "Placer" }));

    expect(placeFixture).toHaveBeenCalledOnce();
    expect(placeFixture).toHaveBeenCalledWith(expect.objectContaining({ id: "fx-unplaced" }), { venueId: "venue-1", kickoffTime: "15:00" });
  });

  it("auto-place à la demande et fait remonter la raison de non-placement (P1-4 PR D)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);
    await gotoStep(user, /Domiciles posés/);

    await user.click(await screen.findByRole("button", { name: /Placer automatiquement/ }));
    const { placeMatches: placeMatchesMock } = await import("./api");
    expect(placeMatchesMock).toHaveBeenCalledOnce();
    expect(await screen.findByText(/Aucune fenêtre d'accès match/)).toBeInTheDocument();
  });

  it("P1-3 §4bis — « Placer automatiquement » affiche le solde et se désactive à 0 (Découverte bridée)", async () => {
    meState.club = { entitlements: { planCode: "decouverte", planName: "Découverte", maxTeams: null, teamsUsed: 4, creditsMax: 10, creditsUsed: 10, canGenerate: false, canPlaceMatches: false, canExportPdf: false, seasonTransition: false } };
    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);
    await gotoStep(user, /Domiciles posés/);
    const place = await screen.findByRole("button", { name: /Placer automatiquement \(0\)/ });
    expect(place).toBeDisabled();
  });

  it("P1-3 §4bis — offre payante : « Placer automatiquement » n'affiche AUCUN solde", async () => {
    meState.club = { entitlements: { planCode: "essentiel", planName: "Essentiel", maxTeams: 20, teamsUsed: 4, creditsMax: null, creditsUsed: 0, canGenerate: true, canPlaceMatches: true, canExportPdf: true, seasonTransition: true } };
    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);
    await gotoStep(user, /Domiciles posés/);
    const place = await screen.findByRole("button", { name: "Placer automatiquement" });
    expect(place).toBeEnabled();
  });

  it("montre la bande extérieur et le radar gradué (vues Domiciles puis Conflits)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);
    // Bande extérieur : présente dans la vue Domiciles (comme dans la vue modèle).
    await gotoStep(user, /Domiciles posés/);
    expect(await screen.findByText(/à Grenoble \(Halle Clemenceau\)/)).toBeInTheDocument();
    // Radar gradué : vue Conflits (groupe sévérité 3 = coach principal en double).
    await gotoStep(user, /Conflits/);
    expect(await screen.findByText("Coach principal en double")).toBeInTheDocument();
  });

  it("clic sur une cellule placée ouvre le panneau de boucle manuelle (P1-4 PR E1)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);
    await gotoStep(user, /Domiciles posés/);

    await user.click(await screen.findByRole("button", { name: /Seniors.*Rivaux/ }));
    expect(await screen.findByRole("button", { name: "Déplacer" })).toBeDisabled();
    await user.click(screen.getByRole("button", { name: "Dé-placer" }));
    expect(unplaceFixture).toHaveBeenCalledWith(expect.objectContaining({ id: "fx-placed" }));
  });

  // ── Contexte stable (L6, RMM-1 PR4) ─────────────────────────────────────────
  it("L6 — le slot de panneau est PERMANENT : état vide « Sélectionnez un match » sans sélection", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);
    await gotoStep(user, /Domiciles posés/);

    // Rien de sélectionné → l'état vide occupe le slot (la colonne ne saute plus).
    expect(await screen.findByText("Sélectionnez un match")).toBeInTheDocument();
    // Sélectionner un domicile placé → le panneau remplace l'état vide.
    await user.click(await screen.findByRole("button", { name: /Seniors.*Rivaux/ }));
    expect(await screen.findByRole("button", { name: "Dé-placer" })).toBeInTheDocument();
    expect(screen.queryByText("Sélectionnez un match")).not.toBeInTheDocument();
    // Fermer → retour à l'état vide, le slot n'a jamais disparu.
    await user.click(screen.getByRole("button", { name: "Fermer" }));
    expect(await screen.findByText("Sélectionnez un match")).toBeInTheDocument();
  });

  it("L6 — Échap sort du mode échange ; le bandeau reste tant qu'il est armé", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);
    await gotoStep(user, /Domiciles posés/);

    await user.click(await screen.findByRole("button", { name: /Seniors.*Rivaux/ }));
    await user.click(screen.getByRole("button", { name: /Échanger avec/ }));
    // Le bandeau d'échange reste affiché tant que le mode est armé (conservé PR3).
    expect(screen.getByText(/cliquez le match à échanger/)).toBeInTheDocument();
    // Échap désarme le mode.
    await user.keyboard("{Escape}");
    expect(screen.queryByText(/cliquez le match à échanger/)).not.toBeInTheDocument();
  });

  // ── La barre utilitaire réduite (L5) — l'action primaire vit dans la vue ─────
  it("la barre du haut ne porte QUE « Nouveau match » ; les actions primaires sont dans les vues", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);
    await screen.findByRole("navigation");

    // Actions rares parties en Configuration (inchangé PR2), toggle A/B disparu.
    expect(screen.queryByRole("button", { name: "Habitudes & passerelles" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Engagements FFBB" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Week-end type/ })).not.toBeInTheDocument();

    // La barre du haut : Nouveau match seulement. « Placer auto » / « Importer FBI »
    // ne sont PAS dans la barre — ils sont l'action primaire de LEUR étape.
    expect(screen.getByRole("button", { name: /Nouveau match/ })).toBeInTheDocument();
    // Vue par défaut = Conflits : ni placer auto ni importer visibles.
    expect(screen.queryByRole("button", { name: /Placer automatiquement/ })).not.toBeInTheDocument();
    // Étape Batch → « Importer FBI » primaire ; étape Domiciles → « Placer auto » primaire.
    await gotoStep(user, /Batch importé/);
    expect(screen.getByRole("button", { name: /Importer FBI/ })).toBeInTheDocument();
    await gotoStep(user, /Domiciles posés/);
    expect(screen.getByRole("button", { name: /Placer automatiquement/ })).toBeInTheDocument();
  });

  // ── RMM-3 — le « gardien » : bandeau au-dessus du rail, rail INCHANGÉ ─────────
  it("un delta plein affiche le bandeau du gardien SANS toucher aux labels du rail (non-régression)", async () => {
    const { postModuleVisit } = await import("./api");
    (postModuleVisit as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
      firstVisit: false,
      newFixturesCount: 12,
      newConflictFingerprints: ["a", "b", "c"],
      planningChanged: true,
      referenceTakenAt: "2026-08-24T10:00:00+00:00",
    });
    renderWithProviders(<MatchesPage />);

    // Le bandeau résumé, en tête (role="status"), avec les trois morceaux.
    const banner = await screen.findByRole("status");
    expect(banner).toHaveTextContent("Depuis votre dernière visite");
    expect(banner).toHaveTextContent("12 matchs arrivés");
    expect(banner).toHaveTextContent("3 nouveaux conflits");
    expect(banner).toHaveTextContent("le planning de saison a changé");

    // Les labels du rail restent BYTE-IDENTIQUES — le gardien est un ornement, il
    // ne pose aucun `done`, aucun badge sur le rail, ne change aucun compte.
    const rail = await screen.findByRole("navigation");
    expect(within(rail).getByRole("button", { name: /Batch importé/ })).toBeInTheDocument();
    expect(within(rail).getByRole("button", { name: /Placés au modèle/ })).toBeInTheDocument();
    expect(within(rail).getByRole("button", { name: /Conflits \(1\)/ })).toBeInTheDocument();
    expect(within(rail).getByRole("button", { name: /Domiciles posés/ })).toBeInTheDocument();
    expect(within(rail).getByRole("button", { name: /Saisi dans FBI \(0\/2\)/ })).toBeInTheDocument();
    // Le rail ne porte aucune chip « Nouveau ».
    expect(within(rail).queryByText("Nouveau")).not.toBeInTheDocument();
  });

  // ── RMM-4 — rappel de fraîcheur DISCRET près du rail semaine ──────────────────
  it("affiche un rappel discret du dernier dépôt FBI (muted, sans bandeau)", async () => {
    renderWithProviders(<MatchesPage />);
    expect(await screen.findByText(/Dernier dépôt FBI/i)).toBeInTheDocument();
  });

  // ── P4-133 — un échec de chargement ne se déguise PAS en « Aucun match importé » ──
  it("P4-133 — une lecture des rencontres en échec DIT l'échec, jamais « Aucun match importé »", async () => {
    // getFixtures échoue sans rien en cache → `readFailed(fixtures)` : l'écran doit
    // céder la place à un message d'erreur avec retry, PAS au faux vide « Aucun
    // match importé » (au-dessus duquel le gestionnaire ré-importerait).
    vi.mocked(matchesApi.getFixtures).mockRejectedValueOnce(new Error("réseau"));
    renderWithProviders(<MatchesPage />);

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("Le chargement a échoué.");
    expect(within(alert).getByRole("button", { name: "Réessayer" })).toBeInTheDocument();
    // Le faux vide ne s'affiche PAS quand la lecture a échoué.
    expect(screen.queryByText("Aucun match importé")).not.toBeInTheDocument();
  });

  it("P4-133 — un échec de lecture des gymnases cède aussi la place au message d'échec", async () => {
    // Les gymnases sont une lecture FONDATRICE de l'écran (grille de placement) :
    // sa panne sans cache doit dire l'échec, pas rendre une page trompeuse.
    vi.mocked(matchesApi.getVenues).mockRejectedValueOnce(new Error("réseau"));
    renderWithProviders(<MatchesPage />);

    expect(await screen.findByText("Le chargement a échoué.")).toBeInTheDocument();
  });
});

// ── PR-1 — filtres de la vue Semaine ────────────────────────────────────────
describe("MatchesPage — filtres (PR-1)", () => {
  it("sans filtre : axe « équipe » par défaut, rail et radar inchangés (pass-through)", async () => {
    renderWithProviders(<MatchesPage />);
    expect(await screen.findByRole("button", { name: "Par équipe" })).toHaveAttribute("aria-pressed", "true");
    // La vue par défaut (Conflits) et le compte du rail restent ceux d'avant le filtre.
    expect(await screen.findByText("Jean Dupont")).toBeInTheDocument();
    const rail = await screen.findByRole("navigation");
    expect(within(rail).getByRole("button", { name: /Conflits \(1\)/ })).toBeInTheDocument();
  });

  it("filtre « par coach » : grille, rail et radar recadrés sur le périmètre du coach", async () => {
    vi.mocked(matchesApi.getCoaches).mockResolvedValueOnce([
      { id: "thomas", firstName: "Thomas", lastName: "Martin" },
      { id: "autre", firstName: "Autre", lastName: "Coach" },
    ]);
    vi.mocked(matchesApi.getTeams).mockResolvedValueOnce([
      { id: "sm1", name: "SM1", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
      { id: "u15m1", name: "U15M1", sportCategoryId: "c", level: null, gender: null, priorityTierId: 3, tierOrder: 0 },
      { id: "hors", name: "HorsPerim", sportCategoryId: "c", level: null, gender: null, priorityTierId: 3, tierOrder: 1 },
    ]);
    vi.mocked(matchesApi.getFixtures).mockResolvedValueOnce([
      { id: "fx-sm1", teamId: "sm1", seasonId: "s", competitionId: null, matchDate: "2026-10-04", homeAway: "HOME", opponentLabel: "AlphaOpp", status: "PLACED", venueId: "venue-1", kickoffTime: "14:00", externalRef: null, fbiVenueLabel: null, placementSource: null, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, suggestedVenueId: null },
      { id: "fx-u15", teamId: "u15m1", seasonId: "s", competitionId: null, matchDate: "2026-10-04", homeAway: "HOME", opponentLabel: "BetaOpp", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", externalRef: null, fbiVenueLabel: null, placementSource: null, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, suggestedVenueId: null },
      { id: "fx-hors", teamId: "hors", seasonId: "s", competitionId: null, matchDate: "2026-10-04", homeAway: "HOME", opponentLabel: "GammaOpp", status: "PLACED", venueId: "venue-1", kickoffTime: "18:00", externalRef: null, fbiVenueLabel: null, placementSource: null, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, suggestedVenueId: null },
      { id: "fx-sm1-away", teamId: "sm1", seasonId: "s", competitionId: null, matchDate: "2026-10-04", homeAway: "AWAY", opponentLabel: "DeltaOpp", status: "UNPLACED", venueId: null, kickoffTime: null, fbiVenueLabel: "Halle X", externalRef: null, placementSource: null, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, suggestedVenueId: null },
    ]);
    vi.mocked(matchesApi.getConflicts).mockResolvedValueOnce({
      clubId: "c",
      seasonId: "s",
      seasonPlanChosen: true,
      conflicts: [
        // Conflit du coach Thomas (SM1 × U15M1, même dimanche).
        { type: "MATCH_MATCH", severity: 3, coachRole: "MAIN", coachId: "thomas", left: { fixtureId: "fx-sm1", teamId: "sm1", homeAway: "HOME", matchDate: "2026-10-04", kickoffTime: "14:00", windowStart: "", windowEnd: "" }, right: { fixtureId: "fx-u15", teamId: "u15m1", homeAway: "HOME", matchDate: "2026-10-04", kickoffTime: "16:00", windowStart: "", windowEnd: "" } },
        // Conflit SANS lien avec Thomas (équipe hors-périmètre) → doit être exclu.
        { type: "VENUE_OVERLAP", severity: 2, venueId: "venue-1", left: { fixtureId: "fx-hors", teamId: "hors", homeAway: "HOME", matchDate: "2026-10-04", kickoffTime: "18:00", windowStart: "", windowEnd: "" }, right: { fixtureId: "fx-hors", teamId: "hors", homeAway: "HOME", matchDate: "2026-10-04", kickoffTime: "18:00", windowStart: "", windowEnd: "" } },
      ],
    });
    planningLinks.teamCoaches = [
      { id: "tc1", teamId: "sm1", coachId: "thomas", role: "ASSISTANT" },
      { id: "tc2", teamId: "u15m1", coachId: "thomas", role: "MAIN" },
    ];

    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);

    await user.click(await screen.findByRole("button", { name: "Par coach" }));
    await user.click(screen.getByRole("button", { name: /Coachs :/ }));
    // L'option de la puce (un bouton) — « Thomas Martin » apparaît aussi comme titre
    // du conflit dans le radar, d'où le ciblage par rôle bouton.
    await user.click(await screen.findByRole("button", { name: "Thomas Martin" }));

    // Radar (vue par défaut = Conflits) : le conflit du coach est là (résumé
    // « SM1 et U15M1 »), le conflit hors-périmètre est exclu.
    expect(await screen.findByText(/SM1 et U15M1/)).toBeInTheDocument();
    expect(screen.queryByText("Deux matchs sur le même créneau")).not.toBeInTheDocument();
    // Rail recadré : seul le conflit du coach compte (2 → 1).
    const rail = await screen.findByRole("navigation");
    expect(within(rail).getByRole("button", { name: /Conflits \(1\)/ })).toBeInTheDocument();

    // Grille (vue Placés au modèle) : SM1 et U15M1, jamais l'équipe hors-périmètre.
    await gotoStep(user, /Placés au modèle/);
    expect(await screen.findByRole("button", { name: /SM1/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /U15M1/ })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /HorsPerim/ })).not.toBeInTheDocument();
    // Le rôle est annoncé sur le match extérieur de SM1 (Thomas assistant).
    expect(screen.getByText("assistant")).toBeInTheDocument();
  });

  it("semaine par défaut : la première semaine ≥ la semaine courante, pas la plus vieille", async () => {
    // Aujourd'hui = 2026-09-10 (semaine du samedi 2026-09-12). Une rencontre passée
    // (2026-09-05) et une future (2026-09-19) : la page doit ouvrir la future.
    setTodayOverride("2026-09-10");
    vi.mocked(matchesApi.getFixtures).mockResolvedValueOnce([
      { id: "fx-past", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-09-05", homeAway: "HOME", opponentLabel: "Anciens", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", externalRef: null, fbiVenueLabel: null, placementSource: null, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, suggestedVenueId: null },
      { id: "fx-future", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-09-19", homeAway: "HOME", opponentLabel: "Futurs", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", externalRef: null, fbiVenueLabel: null, placementSource: null, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, suggestedVenueId: null },
    ]);
    vi.mocked(matchesApi.getConflicts).mockResolvedValueOnce({ clubId: "c", seasonId: "s", seasonPlanChosen: true, conflicts: [] });
    renderWithProviders(<MatchesPage />);
    // Le navigateur ouvre la semaine du 14 au 20 sept. (celle du 19), pas celle du 5.
    expect(await screen.findByText(/Semaine du 14 sept\. au 20 sept\./)).toBeInTheDocument();
    expect(screen.queryByText(/au 6 sept\./)).not.toBeInTheDocument();
  });

  it("P4-192 — un week-end 100 % extérieur atterrit sur la grille (homeSlots), jamais sur « Aucun domicile à recopier »", async () => {
    // Mesuré le 2026-09-08 (SF1, SM1, U21M1) : sans domicile, fbiEntry est le premier
    // trou mais VIDE ; y atterrir affichait « Aucun domicile à recopier dans FBI » alors
    // qu'il y a bien des matchs, tous à l'extérieur. defaultLoopStep bascule sur homeSlots.
    vi.mocked(matchesApi.getFixtures).mockResolvedValueOnce([
      { id: "fx-away-1", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-04", homeAway: "AWAY", opponentLabel: "Grenoble", status: "UNPLACED", venueId: null, kickoffTime: null, fbiVenueLabel: "Halle Clemenceau", externalRef: null, placementSource: null, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, suggestedVenueId: null },
    ]);
    vi.mocked(matchesApi.getConflicts).mockResolvedValueOnce({ clubId: "c", seasonId: "s", seasonPlanChosen: true, conflicts: [] });
    renderWithProviders(<MatchesPage />);

    // Aucun clic sur le rail : la vue par défaut est homeSlots (bascule P4-192). La grille
    // de placement est rendue (en-tête « À placer ») ET la bande extérieur.
    expect(await screen.findByRole("heading", { name: "À placer" })).toBeInTheDocument();
    expect(screen.getByText(/À l'extérieur ce week-end/)).toBeInTheDocument();
    // Le faux message de la vue fbiEntry ne s'affiche PAS.
    expect(screen.queryByText(/Aucun domicile à recopier/)).not.toBeInTheDocument();
  });

  it("filtre « par gymnase » : les extérieurs (sans gymnase) sont exclus", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchesPage />);

    await user.click(await screen.findByRole("button", { name: "Par gymnase" }));
    await user.click(screen.getByRole("button", { name: /Gymnases :/ }));
    await user.click(await screen.findByText("Gymnase Alpha"));

    await gotoStep(user, /Placés au modèle/);
    // Le domicile posé à Gymnase Alpha reste ; l'extérieur (Grenoble, sans gymnase) disparaît.
    expect(await screen.findByRole("button", { name: /Seniors.*Rivaux/ })).toBeInTheDocument();
    expect(screen.queryByText(/à Grenoble/)).not.toBeInTheDocument();
  });
});
