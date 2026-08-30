import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import { renderWithProviders } from "@/test/utils";

import type { AdminActionsResponse, AdminClubsResponse, AdminFreshnessResponse, AdminHealthResponse, AdminJobsResponse, AdminOverviewResponse } from "./api";
import { getAdminActions, getAdminClubs, getAdminFreshness, getAdminHealth, getAdminJobs, getAdminOverview, runAdminClubAction, runAdminJob } from "./api";
import { AdminDashboardPage } from "./AdminDashboardPage";
import { useAdminStore } from "./store";

vi.mock("./api", async (importOriginal) => {
  const original = await importOriginal<typeof import("./api")>();
  return {
    ...original,
    getAdminOverview: vi.fn(),
    getAdminHealth: vi.fn(),
    getAdminJobs: vi.fn(),
    getAdminClubs: vi.fn(),
    runAdminJob: vi.fn(),
    getAdminActions: vi.fn(),
    runAdminClubAction: vi.fn(),
    getAdminFreshness: vi.fn(),
  };
});

const overview: AdminOverviewResponse = {
  clubs: { total: 18, active7d: 7, active30d: 12, new7d: 2, unsubscribed: 1 },
  solver: {
    windowDays: 30,
    generations: 42,
    completed: 36,
    failed: 2,
    infeasible: 4,
    infeasibleRate: 4 / 42,
    p50WallTimeMs: 850,
    p95WallTimeMs: 2400,
    daily: [
      { date: "2026-07-15", generations: 14, infeasible: 1, p50WallTimeMs: 700, p95WallTimeMs: 1900 },
      { date: "2026-07-16", generations: 28, infeasible: 3, p50WallTimeMs: 900, p95WallTimeMs: 2600 },
    ],
  },
  usage: {
    plansByType: [
      { type: "SEASON", total: 18, validated: 11 },
      { type: "CLOSURE", total: 6, validated: 4 },
      { type: "HOLIDAY", total: 9, validated: 5 },
    ],
    timeToFirstValidation: {
      // Minutes (SA2-stats round 1) : 36 h · 320 h→13 j · 25 min (le cas « clôture rapide » que l'arrondi heures effaçait).
      season: { count: 11, p50Minutes: 36 * 60, p95Minutes: 320 * 60 },
      period: { count: 9, p50Minutes: 25, p95Minutes: 30 * 60 },
    },
    solverByPlanType: [
      { planType: "SEASON", generations: 30, p50WallTimeMs: 900, p95WallTimeMs: 2600 },
      { planType: "CLOSURE", generations: 12, p50WallTimeMs: 400, p95WallTimeMs: 1100 },
    ],
    clubSizes: [
      { bucket: "1-5", clubs: 8, medianVenues: 1 },
      { bucket: "11-20", clubs: 5, medianVenues: 3 },
    ],
  },
};

const health: AdminHealthResponse = {
  status: "healthy",
  checkedAt: "2026-07-16T10:30:00+00:00",
  services: {
    database: { status: "up", latencyMs: 4 },
    redis: { status: "up", latencyMs: 2 },
    engine: { status: "up", latencyMs: 18 },
    mercure: { status: "up", latencyMs: 9 },
    worker: { status: "up", lastHeartbeatAt: "2026-07-16T10:29:55+00:00", ageSeconds: 5 },
  },
  messenger: { status: "up", backlog: 3, failed: 0, retriesToday: 1, backlogWarningThreshold: 100 },
  containers: [],
  externalDependencies: [],
};

const clubs: AdminClubsResponse = {
  items: [
    {
      id: "club-1",
      name: "Basket Club des Lacs",
      slug: "basket-club-des-lacs",
      ffbbClubCode: "ARA001",
      isDemo: false,
      plan: null,
      paidSeasonYear: null,
      effectivePlan: { code: "decouverte", name: "Découverte" },
      billingCycle: null,
      generationCountSeason: 3,
      createdAt: "2026-06-01T09:00:00+00:00",
      lastActivityAt: "2026-07-16T08:00:00+00:00",
      unsubscribed: false,
      currentSeason: { id: "season-1", name: "2026-2027", status: "DRAFT" },
      volumes: { teams: 12, venues: 3, coaches: 8, constraints: 29 },
      solver: {
        generations: 8,
        infeasible: 1,
        infeasibleRate: 0.125,
        p50WallTimeMs: 780,
        p95WallTimeMs: 1900,
        latestStatus: "COMPLETED",
        latestAt: "2026-07-16T08:10:00+00:00",
      },
    },
  ],
  pagination: { page: 1, limit: 25, total: 26, pages: 2 },
  metricsWindowDays: 30,
};

const jobs: AdminJobsResponse = {
  items: [
    {
      key: "period-reminders",
      label: "Rappels de périodes",
      command: "app:periods:remind",
      cadence: "daily",
      manualTriggerAllowed: false,
      nextRunAt: "2099-07-17T08:00:00+02:00",
      latestRun: {
        id: "run-1",
        status: "succeeded",
        source: "scheduled",
        startedAt: "2026-07-16T10:00:00+00:00",
        finishedAt: "2026-07-16T10:00:01+00:00",
        durationMs: 950,
        exitCode: 0,
      },
    },
    {
      key: "purge-seasons",
      label: "Purge des anciennes saisons",
      command: "app:seasons:purge",
      cadence: "quarterly",
      manualTriggerAllowed: false,
      nextRunAt: "2099-10-01T03:00:00+02:00",
      latestRun: null,
    },
    {
      key: "import-school-holidays",
      label: "Import des vacances scolaires",
      command: "app:school-holidays:import",
      cadence: "quarterly",
      manualTriggerAllowed: true,
      nextRunAt: "2099-10-01T04:00:00+02:00",
      latestRun: null,
    },
  ],
};

// SA4 — catalogue fermé d'actions support (miroir du backend AdminActionCatalog : 6 items,
// dont « Offre » à SCHÉMA fermé). Les pickers viennent de ce schéma, jamais d'une liste en dur.
const actions: AdminActionsResponse = {
  items: [
    { key: "ffbb-resync", label: "Resynchroniser depuis la FFBB", description: "Ré-importe l’identité FFBB du club.", dangerous: false, arguments: [] },
    { key: "mark-next-season-paid", label: "Marquer la saison suivante payée", description: "Enregistre le paiement de la saison suivante.", dangerous: false, arguments: [] },
    {
      key: "set-plan",
      label: "Offre",
      description: "Attribue une offre au club et, pour toute offre payante, marque la saison encaissée.",
      dangerous: false,
      arguments: [
        {
          key: "plan",
          label: "Offre",
          required: true,
          choices: [
            { value: "decouverte", label: "Découverte" },
            { value: "essentiel", label: "Essentiel" },
            { value: "club", label: "Club" },
            { value: "grand-club", label: "Grand club" },
            { value: "sans-limite", label: "Sans limite" },
            { value: "beta", label: "Bêta" },
          ],
        },
        {
          key: "paidSeason",
          label: "Saison encaissée",
          required: false,
          gate: { argument: "plan", forbiddenValues: ["decouverte"] },
          choices: [
            { value: "current", label: "Saison en cours (le club a réglé)" },
            { value: "next", label: "Saison suivante" },
          ],
        },
      ],
    },
    { key: "reset-credits", label: "Réinitialiser les crédits de sortie", description: "Remet à zéro le pool de crédits.", dangerous: false, arguments: [] },
    { key: "reset-current-season", label: "Réinitialiser la saison courante", description: "Vide toutes les données de la saison courante.", dangerous: true, arguments: [] },
    { key: "purge-old-seasons", label: "Purger les anciennes saisons", description: "Supprime les saisons au-delà de la rétention.", dangerous: true, arguments: [] },
  ],
};

// Data-freshness : un référentiel à jour, un périmé (badge), un jamais importé.
const freshness: AdminFreshnessResponse = {
  items: [
    { key: "school-holidays", label: "Vacances scolaires", lastUpdatedAt: "2026-07-01T04:00:00+00:00", staleAfterDays: 100, stale: false },
    { key: "public-holidays", label: "Jours fériés", lastUpdatedAt: "2026-01-02T04:30:00+00:00", staleAfterDays: 100, stale: true },
    { key: "ffbb-directory", label: "Ligues & comités FFBB", lastUpdatedAt: null, staleAfterDays: 400, stale: true },
  ],
};

const mockOverview = vi.mocked(getAdminOverview);
const mockHealth = vi.mocked(getAdminHealth);
const mockJobs = vi.mocked(getAdminJobs);
const mockClubs = vi.mocked(getAdminClubs);
const mockRunJob = vi.mocked(runAdminJob);
const mockActions = vi.mocked(getAdminActions);
const mockRunClubAction = vi.mocked(runAdminClubAction);
const mockFreshness = vi.mocked(getAdminFreshness);

describe("AdminDashboardPage", () => {
  beforeEach(() => {
    localStorage.clear();
    mockOverview.mockReset().mockResolvedValue(overview);
    mockHealth.mockReset().mockResolvedValue(health);
    mockJobs.mockReset().mockResolvedValue(jobs);
    mockClubs.mockReset().mockResolvedValue(clubs);
    mockRunJob.mockReset().mockResolvedValue({ key: "import-school-holidays", status: "succeeded", exitCode: 0 });
    mockActions.mockReset().mockResolvedValue(actions);
    mockRunClubAction.mockReset().mockResolvedValue({ key: "reset-credits", clubId: "club-1", status: "succeeded", exitCode: 0 });
    mockFreshness.mockReset().mockResolvedValue(freshness);
    useAdminStore.setState({ identity: { id: "admin-1", email: "ops@example.test" }, csrfToken: "csrf-123" });
  });

  it("renders fleet, health and club data from the SA2 APIs", async () => {
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText("Basket Club des Lacs")).toBeInTheDocument();
    expect(screen.getByText("42")).toBeInTheDocument();
    expect(screen.getByText("Base de données")).toBeInTheDocument();
    expect(screen.getByText("Découverte")).toBeInTheDocument();
    expect(screen.getByText("Rappels de périodes")).toBeInTheDocument();
    expect(screen.getByText("Quotidien")).toBeInTheDocument();
    expect(screen.getByRole("columnheader", { name: "Prochain passage", hidden: true })).toBeInTheDocument();
    expect(screen.getByText("Réussi")).toBeInTheDocument();
    expect(screen.getAllByText("Jamais exécuté")).toHaveLength(2);
    expect(mockOverview).toHaveBeenCalledOnce();
    expect(mockHealth).toHaveBeenCalledOnce();
    expect(mockJobs).toHaveBeenCalledOnce();
    expect(mockClubs).toHaveBeenCalledWith(1, 25, "");
  });

  it("shows the EFFECTIVE offer in the club badge, never the stored one (A1)", async () => {
    const divergent: AdminClubsResponse = {
      items: [
        {
          ...clubs.items[0],
          id: "club-beta-unpaid",
          name: "BCCL (Bêta non réglée)",
          // Offre Bêta POSÉE mais saison non réglée → effective = Découverte.
          plan: { code: "beta", name: "Bêta" },
          paidSeasonYear: null,
          effectivePlan: { code: "decouverte", name: "Découverte" },
        },
        {
          ...clubs.items[0],
          id: "club-beta-paid",
          name: "Club Bêta réglé",
          plan: { code: "beta", name: "Bêta" },
          paidSeasonYear: 2026,
          effectivePlan: { code: "beta", name: "Bêta" },
        },
      ],
      pagination: { page: 1, limit: 25, total: 2, pages: 1 },
      metricsWindowDays: 30,
    };
    mockClubs.mockReset().mockResolvedValue(divergent);
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText("BCCL (Bêta non réglée)")).toBeInTheDocument();
    // Le badge de l'offre non réglée dit l'EFFECTIVE (Découverte), jamais « Payant ».
    expect(screen.getByText("Découverte")).toBeInTheDocument();
    expect(screen.getByText("Bêta posée — saison non réglée")).toBeInTheDocument();
    expect(screen.queryByText("Payant")).not.toBeInTheDocument();
    // L'offre réglée affiche « Bêta » (badge seul, aucun sous-texte de divergence).
    expect(screen.getByText("Bêta")).toBeInTheDocument();
  });

  it("renders the usage stats: plans by type, time-to-close and club sizes (SA2-stats)", async () => {
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText("Plans, clôtures et tailles de clubs")).toBeInTheDocument();
    // Plans par type (libellés FR — « Saison » apparaît aussi dans la carte solveur) + « dont validés ».
    expect(screen.getAllByText("Saison").length).toBeGreaterThanOrEqual(2);
    expect(screen.getByText("Vacances")).toBeInTheDocument();
    expect(screen.getByText(/dont 11 validés/)).toBeInTheDocument();
    // Temps de clôture : création → 1re validation (36 h médiane saison ; P95 320 h → « 13 j » ;
    // une clôture de période en 25 min s'affiche en minutes, plus jamais « 0 h »).
    expect(screen.getByText("36 h")).toBeInTheDocument();
    expect(screen.getByText("13 j")).toBeInTheDocument();
    expect(screen.getByText("25 min")).toBeInTheDocument();
    expect(screen.getByText(/11 saisons · 9 périodes clôturées/)).toBeInTheDocument();
    // Tailles de clubs : tranches + médiane gymnases.
    expect(screen.getByRole("columnheader", { name: "Gymnases (médiane)" })).toBeInTheDocument();
    expect(screen.getByText("11-20")).toBeInTheDocument();
  });

  it("shows the usage panel as unavailable (never crashes) when the backend predates the usage block", async () => {
    // Rollback backend / décalage de déploiement : l'ancien overview n'a pas `usage`.
    const legacyOverview: AdminOverviewResponse = { clubs: overview.clubs, solver: overview.solver };
    mockOverview.mockReset().mockResolvedValue(legacyOverview);
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText("Les statistiques d’usage sont indisponibles.")).toBeInTheDocument();
    expect(screen.queryByText("Plans, clôtures et tailles de clubs")).not.toBeInTheDocument();
  });

  it("renders the data-freshness board: up-to-date, stale and never-imported referentials", async () => {
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText("Fraîcheur des données")).toBeInTheDocument();
    expect(screen.getByText("Vacances scolaires")).toBeInTheDocument();
    expect(screen.getByText("À jour")).toBeInTheDocument();
    // Deux référentiels périmés (dont un jamais importé — fail-visible).
    expect(screen.getAllByText("Périmé")).toHaveLength(2);
    expect(screen.getByText("Jamais importé")).toBeInTheDocument();
  });

  it("runs a non-dangerous support action from the club row after a simple confirm (SA4)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });

    await user.click(await screen.findByRole("button", { name: "Actions" }));
    await user.click(await screen.findByRole("button", { name: /Réinitialiser les crédits de sortie/ }));
    // Non-dangerous : pas de saisie nominative, exécution directe.
    await user.click(screen.getByRole("button", { name: "Exécuter" }));

    await waitFor(() => expect(mockRunClubAction).toHaveBeenCalledWith("club-1", "reset-credits", "csrf-123"));
  });

  it("gates a dangerous support action behind typing the exact club name (SA4)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });

    await user.click(await screen.findByRole("button", { name: "Actions" }));
    await user.click(await screen.findByRole("button", { name: /Réinitialiser la saison courante/ }));

    // Tant que le nom exact n'est pas tapé, le bouton reste désactivé — le clic
    // réflexe ne détruit jamais une saison.
    const execute = screen.getByRole("button", { name: "Exécuter" });
    expect(execute).toBeDisabled();
    await user.type(screen.getByLabelText(/tapez le nom exact du club/i), "Mauvais nom");
    expect(execute).toBeDisabled();

    await user.clear(screen.getByLabelText(/tapez le nom exact du club/i));
    await user.type(screen.getByLabelText(/tapez le nom exact du club/i), "Basket Club des Lacs");
    expect(execute).toBeEnabled();
    await user.click(execute);

    await waitFor(() => expect(mockRunClubAction).toHaveBeenCalledWith("club-1", "reset-current-season", "csrf-123"));
  });

  it("lists the full closed catalogue (7 items) in the support-actions modal (A3)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });

    await user.click(await screen.findByRole("button", { name: "Actions" }));
    const dialog = await screen.findByRole("dialog", { name: /Actions support/ });
    // Le catalogue est FERMÉ : exactement 6 entrées, dont l'unique bouton « Offre » (A3).
    expect(within(dialog).getAllByRole("listitem")).toHaveLength(6);
    expect(within(dialog).getByRole("button", { name: /^Offre/ })).toBeInTheDocument();
  });

  it("renders the offer pickers FROM the served schema and sends the chosen offer + season (A3)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });

    await user.click(await screen.findByRole("button", { name: "Actions" }));
    await user.click(await screen.findByRole("button", { name: /^Offre/ }));

    // Le picker d'offre n'offre QUE les codes servis par le schéma (aucune liste en dur).
    const planPicker = screen.getByLabelText("Offre", { selector: "select" });
    const planOptions = within(planPicker).getAllByRole("option").map((option) => option.textContent);
    expect(planOptions).toEqual(["Choisir…", "Découverte", "Essentiel", "Club", "Grand club", "Sans limite", "Bêta"]);

    // Tant que l'offre n'est pas choisie, pas de sélecteur de saison ; le bouton reste bloqué.
    expect(screen.queryByLabelText("Saison encaissée")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Exécuter" })).toBeDisabled();

    await user.selectOptions(planPicker, "essentiel");
    // Offre payante → la saison encaissée APPARAÎT et devient exigée : soumission
    // impossible tant qu'elle n'est pas choisie.
    const seasonPicker = screen.getByLabelText("Saison encaissée", { selector: "select" });
    expect(screen.getByRole("button", { name: "Exécuter" })).toBeDisabled();
    await user.selectOptions(seasonPicker, "next");
    expect(screen.getByRole("button", { name: "Exécuter" })).toBeEnabled();

    // Popup de confirmation OBLIGATOIRE, nommant l'offre et la saison, avant tout envoi.
    await user.click(screen.getByRole("button", { name: "Exécuter" }));
    const confirm = await screen.findByRole("dialog", { name: /Confirmer : Offre/ });
    expect(within(confirm).getByText(/Essentiel/)).toBeInTheDocument();
    expect(within(confirm).getByText(/Saison suivante/)).toBeInTheDocument();
    expect(mockRunClubAction).not.toHaveBeenCalled();

    await user.click(within(confirm).getByRole("button", { name: "Confirmer l’attribution" }));
    await waitFor(() => expect(mockRunClubAction).toHaveBeenCalledWith("club-1", "set-plan", "csrf-123", { plan: "essentiel", paidSeason: "next" }));
  });

  it("hides the season selector for Découverte and never sends it (A3)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });

    await user.click(await screen.findByRole("button", { name: "Actions" }));
    await user.click(await screen.findByRole("button", { name: /^Offre/ }));

    await user.selectOptions(screen.getByLabelText("Offre", { selector: "select" }), "decouverte");
    // Découverte : aucune saison à encaisser → pas de sélecteur, exécution possible directe.
    expect(screen.queryByLabelText("Saison encaissée")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Exécuter" })).toBeEnabled();

    await user.click(screen.getByRole("button", { name: "Exécuter" }));
    const confirm = await screen.findByRole("dialog", { name: /Confirmer : Offre/ });
    await user.click(within(confirm).getByRole("button", { name: "Confirmer l’attribution" }));
    // Le corps envoyé ne porte QUE l'offre — jamais paidSeason sur Découverte.
    await waitFor(() => expect(mockRunClubAction).toHaveBeenCalledWith("club-1", "set-plan", "csrf-123", { plan: "decouverte" }));
  });

  it("searches and paginates through the clubs API", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });
    await screen.findByText("Basket Club des Lacs");

    await user.type(screen.getByRole("searchbox", { name: /rechercher un club/i }), "  Lacs  ");
    await user.click(screen.getByRole("button", { name: "Rechercher" }));
    await waitFor(() => expect(mockClubs).toHaveBeenCalledWith(1, 25, "Lacs"));

    await user.click(screen.getByRole("button", { name: "Page suivante" }));
    await waitFor(() => expect(mockClubs).toHaveBeenCalledWith(2, 25, "Lacs"));
  }, 10_000);

  it("refreshes all four monitoring feeds on demand", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });
    await screen.findByText("Basket Club des Lacs");

    await user.click(screen.getByRole("button", { name: "Actualiser" }));

    await waitFor(() => {
      expect(mockOverview).toHaveBeenCalledTimes(2);
      expect(mockHealth).toHaveBeenCalledTimes(2);
      expect(mockJobs).toHaveBeenCalledTimes(2);
      expect(mockClubs).toHaveBeenCalledTimes(2);
    });
  });

  it("confirms and runs only a manually allowed reference import", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=jobs" });
    await screen.findByText("Import des vacances scolaires");

    expect(screen.getAllByText("Supervision seule")).toHaveLength(2);
    await user.click(screen.getByRole("button", { name: "Relancer" }));
    expect(screen.getByRole("dialog", { name: "Relancer cet import ?" })).toBeInTheDocument();
    expect(mockRunJob).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "Relancer l’import" }));
    await waitFor(() => expect(mockRunJob).toHaveBeenCalledWith("import-school-holidays", "csrf-123"));
    await waitFor(() => expect(mockJobs).toHaveBeenCalledTimes(2));
  });

  it("keeps the other monitoring panels visible when health fails", async () => {
    mockHealth.mockRejectedValue(new Error("health unavailable"));
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText("La santé technique est indisponible.")).toBeInTheDocument();
    expect(screen.getByText("Basket Club des Lacs")).toBeInTheDocument();
    expect(screen.getByText("Activité globale")).toBeInTheDocument();
  });

  it("keeps the other monitoring panels visible when jobs fail", async () => {
    mockJobs.mockRejectedValue(new Error("jobs unavailable"));
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText("Les jobs opérationnels sont indisponibles.")).toBeInTheDocument();
    expect(screen.getByText("Basket Club des Lacs")).toBeInTheDocument();
    expect(screen.getByText("Santé technique")).toBeInTheDocument();
  });

  it("shows an explicit empty search result", async () => {
    mockClubs.mockResolvedValue({ ...clubs, items: [], pagination: { ...clubs.pagination, total: 0, pages: 0 } });
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });

    await user.type(screen.getByRole("searchbox", { name: /rechercher un club/i }), "introuvable");
    await user.click(screen.getByRole("button", { name: "Rechercher" }));

    expect(await screen.findByText("Aucun club ne correspond à « introuvable »." )).toBeInTheDocument();
  });

  it("has no structural accessibility violations with populated data", async () => {
    const { container } = renderWithProviders(<AdminDashboardPage />, { route: "/admin" });
    await screen.findByText("Basket Club des Lacs");

    expect(await axe(container)).toHaveNoViolations();
  }, 10_000);

  // Revue #346 — le déplacement de `Tabs` vers `shared/` a introduit une peau par défaut
  // (`app`, thème clair) : un `variant="console"` oublié sur un site d'appel peint des tokens
  // clairs sur la coque sombre (`--console-surface`) et rend l'onglet actif illisible. C'est arrivé aux
  // sous-onglets Journaux, et RIEN ne l'avait vu — le test du composant épingle le composant,
  // pas la PAGE. Cette assertion garde le site d'appel.
  it("habille TOUS ses onglets de la peau console, sous-onglets compris", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });
  
    await user.click(await screen.findByRole("tab", { name: /Journaux/ }));
    for (const tab of screen.getAllByRole("tab")) {
      expect(tab.className).toMatch(/text-white|text-console-text-dim/);
      expect(tab.className).not.toContain("text-muted-foreground");
    }
    // Les PANNEAUX aussi : leur anneau de focus vient du même `variant`, et l'oublier
    // rendrait invisible la position du focus sur la coque sombre (revue #346 round 2).
    for (const panel of screen.getAllByRole("tabpanel", { hidden: true })) {
      expect(panel.className).toContain("ring-console-accent/20");
    }
  });
});
