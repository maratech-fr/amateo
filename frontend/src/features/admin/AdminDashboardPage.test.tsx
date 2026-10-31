import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import { renderWithProviders } from "@/test/utils";

import type { AdminActionsResponse, AdminClubRequestsResponse, AdminClubsResponse, AdminFeedbackResponse, AdminFreshnessResponse, AdminHealthResponse, AdminJobsResponse, AdminOverviewResponse, AdminSystemErrorsResponse } from "./api";
import { getAdminActions, getAdminClubRequests, getAdminClubs, getAdminFeedback, getAdminFreshness, getAdminHealth, getAdminJobs, getAdminOverview, getAdminPendingMemberships, getAdminSession, getAdminSystemErrors, runAdminClubAction, runAdminJob } from "./api";
import { AdminDashboardPage } from "./AdminDashboardPage";
import { useAdminSession } from "./queries";
import { useAdminStore } from "./store";
import { ADMIN_DASHBOARD_TEXTS } from "./texts/dashboard";

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
    // PR1 console — pollées au niveau page pour la pastille Incidents (signalements non
    // traités + erreurs système ≤ 7 j), en plus de leurs sections.
    getAdminFeedback: vi.fn(),
    getAdminSystemErrors: vi.fn(),
    // #22 — « Actualiser » doit rafraîchir TOUTES les données de la console, y compris les
    // demandes d'inscription de clubs (montées par ClubRequestsSection) ; la session, elle,
    // ne doit jamais être re-demandée.
    getAdminClubRequests: vi.fn(),
    getAdminPendingMemberships: vi.fn(),
    getAdminSession: vi.fn(),
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

// PR1 — signaux de la pastille Incidents. Lignes de base SANS incident (aucun signalement
// non traité, aucune erreur système récente), surchargées au cas par cas.
function feedbackResponse(oldestUntreatedAgeHours: number | null): AdminFeedbackResponse {
  return {
    items: [],
    pagination: { page: 1, limit: 50, total: 0, pages: 0 },
    qos: { treatDelayByMonth: [], volumeByTopicMonth: [], treatedShare: 1, oldestUntreatedAgeHours },
  };
}

function systemErrorsResponse(total: number): AdminSystemErrorsResponse {
  return { items: [], pagination: { page: 1, limit: 1, total, pages: total > 0 ? 1 : 0 } };
}

// Fraîcheur TOUTE verte — la fixture `freshness` par défaut porte des périmés (allumerait
// la pastille Santé) : les cas « tout au vert » la remplacent par celle-ci.
const freshnessAllFresh: AdminFreshnessResponse = {
  items: [{ key: "school-holidays", label: "Vacances scolaires", lastUpdatedAt: "2026-07-14T04:00:00+00:00", staleAfterDays: 100, stale: false }],
};

const mockOverview = vi.mocked(getAdminOverview);
const mockHealth = vi.mocked(getAdminHealth);
const mockJobs = vi.mocked(getAdminJobs);
const mockClubs = vi.mocked(getAdminClubs);
const mockRunJob = vi.mocked(runAdminJob);
const mockActions = vi.mocked(getAdminActions);
const mockRunClubAction = vi.mocked(runAdminClubAction);
const mockFreshness = vi.mocked(getAdminFreshness);
const mockFeedback = vi.mocked(getAdminFeedback);
const mockSystemErrors = vi.mocked(getAdminSystemErrors);
const mockClubRequests = vi.mocked(getAdminClubRequests);
const mockPendingMemberships = vi.mocked(getAdminPendingMemberships);
const mockSession = vi.mocked(getAdminSession);

// #22 — une demande d'inscription de club servie par l'API (createdAt/expiresAt au format
// `date` Y-m-d du contrat, PAS RFC 3339 : snapshot `specs/courantes/openapi-snapshot.json`).
const newClubRequest: AdminClubRequestsResponse = {
  items: [
    {
      id: "req-new",
      clubName: "AS Val de Marne",
      ara: "ARA909",
      clubEmail: null,
      status: "pending",
      requesterName: "Alex Martin",
      requesterEmail: "alex@asvdm.test",
      createdAt: "2026-10-20",
      expiresAt: "2026-11-03",
    },
  ],
};

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
    mockFeedback.mockReset().mockResolvedValue(feedbackResponse(null));
    mockSystemErrors.mockReset().mockResolvedValue(systemErrorsResponse(0));
    mockClubRequests.mockReset().mockResolvedValue({ items: [] });
    mockPendingMemberships.mockReset().mockResolvedValue({ items: [] });
    mockSession.mockReset().mockResolvedValue({ id: "admin-1", email: "ops@example.test", csrfToken: "csrf-123" });
    useAdminStore.setState({ identity: { id: "admin-1", email: "ops@example.test" }, csrfToken: "csrf-123" });
  });

  it("renders fleet, health and club data from the SA2 APIs", async () => {
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText("Basket Club des Lacs")).toBeInTheDocument();
    expect(screen.getByText("42")).toBeInTheDocument();
    expect(screen.getByText(ADMIN_DASHBOARD_TEXTS.healthServiceDatabase)).toBeInTheDocument();
    expect(screen.getByText("Découverte")).toBeInTheDocument();
    expect(screen.getByText("Rappels de périodes")).toBeInTheDocument();
    expect(screen.getByText(ADMIN_DASHBOARD_TEXTS.cadenceDaily)).toBeInTheDocument();
    expect(screen.getByRole("columnheader", { name: ADMIN_DASHBOARD_TEXTS.jobColNextRun, hidden: true })).toBeInTheDocument();
    expect(screen.getByText(ADMIN_DASHBOARD_TEXTS.jobStatusLabel("succeeded"))).toBeInTheDocument();
    expect(screen.getAllByText(ADMIN_DASHBOARD_TEXTS.jobNeverRun)).toHaveLength(2);
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
    expect(screen.getByText(ADMIN_DASHBOARD_TEXTS.clubPlanDiverges("Bêta"))).toBeInTheDocument();
    expect(screen.queryByText("Payant")).not.toBeInTheDocument();
    // L'offre réglée affiche « Bêta » (badge seul, aucun sous-texte de divergence).
    expect(screen.getByText("Bêta")).toBeInTheDocument();
  });

  it("renders the usage stats: plans by type, time-to-close and club sizes (SA2-stats)", async () => {
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=statistiques" });

    expect(await screen.findByText(ADMIN_DASHBOARD_TEXTS.usageHeading)).toBeInTheDocument();
    // Plans par type (libellés FR — « Saison » apparaît aussi dans la carte solveur) + « dont validés ».
    expect(screen.getAllByText(ADMIN_DASHBOARD_TEXTS.planTypeLabel("SEASON")).length).toBeGreaterThanOrEqual(2);
    expect(screen.getByText(ADMIN_DASHBOARD_TEXTS.planTypeLabel("HOLIDAY"))).toBeInTheDocument();
    expect(screen.getByText(ADMIN_DASHBOARD_TEXTS.usagePlansValidated(11))).toBeInTheDocument();
    // Temps de clôture : création → 1re validation (36 h médiane saison ; P95 320 h → « 13 j » ;
    // une clôture de période en 25 min s'affiche en minutes, plus jamais « 0 h »).
    // (36 h / 13 j / 25 min = sorties de formatMinutes — durées, hors texts centralisés.)
    expect(screen.getByText("36 h")).toBeInTheDocument();
    expect(screen.getByText("13 j")).toBeInTheDocument();
    expect(screen.getByText("25 min")).toBeInTheDocument();
    expect(screen.getByText(ADMIN_DASHBOARD_TEXTS.usageCloseCounts(11, 9))).toBeInTheDocument();
    // Tailles de clubs : tranches + médiane gymnases.
    expect(screen.getByRole("columnheader", { name: ADMIN_DASHBOARD_TEXTS.usageColVenuesMedian })).toBeInTheDocument();
    expect(screen.getByText("11-20")).toBeInTheDocument();
  });

  it("shows the usage panel as unavailable (never crashes) when the backend predates the usage block", async () => {
    // Rollback backend / décalage de déploiement : l'ancien overview n'a pas `usage`.
    const legacyOverview: AdminOverviewResponse = { clubs: overview.clubs, solver: overview.solver };
    mockOverview.mockReset().mockResolvedValue(legacyOverview);
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText(ADMIN_DASHBOARD_TEXTS.usageError)).toBeInTheDocument();
    expect(screen.queryByText(ADMIN_DASHBOARD_TEXTS.usageHeading)).not.toBeInTheDocument();
  });

  it("renders the data-freshness board: up-to-date, stale and never-imported referentials", async () => {
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText(ADMIN_DASHBOARD_TEXTS.freshnessHeading)).toBeInTheDocument();
    expect(screen.getByText("Vacances scolaires")).toBeInTheDocument();
    expect(screen.getByText(ADMIN_DASHBOARD_TEXTS.freshnessFresh)).toBeInTheDocument();
    // Deux référentiels périmés (dont un jamais importé — fail-visible).
    expect(screen.getAllByText(ADMIN_DASHBOARD_TEXTS.freshnessStale)).toHaveLength(2);
    expect(screen.getByText(ADMIN_DASHBOARD_TEXTS.freshnessNeverImported)).toBeInTheDocument();
  });

  it("runs a non-dangerous support action from the club row after a simple confirm (SA4)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });

    await user.click(await screen.findByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubRowActions }));
    await user.click(await screen.findByRole("button", { name: /Réinitialiser les crédits de sortie/ }));
    // Non-dangerous : pas de saisie nominative, exécution directe.
    await user.click(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubActionExecute }));

    await waitFor(() => expect(mockRunClubAction).toHaveBeenCalledWith("club-1", "reset-credits", "csrf-123"));
  });

  it("gates a dangerous support action behind typing the exact club name (SA4)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });

    await user.click(await screen.findByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubRowActions }));
    await user.click(await screen.findByRole("button", { name: /Réinitialiser la saison courante/ }));

    // Tant que le nom exact n'est pas tapé, le bouton reste désactivé — le clic
    // réflexe ne détruit jamais une saison.
    const execute = screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubActionExecute });
    expect(execute).toBeDisabled();
    await user.type(screen.getByLabelText(ADMIN_DASHBOARD_TEXTS.clubActionDangerConfirmLabel("Basket Club des Lacs")), "Mauvais nom");
    expect(execute).toBeDisabled();

    await user.clear(screen.getByLabelText(ADMIN_DASHBOARD_TEXTS.clubActionDangerConfirmLabel("Basket Club des Lacs")));
    await user.type(screen.getByLabelText(ADMIN_DASHBOARD_TEXTS.clubActionDangerConfirmLabel("Basket Club des Lacs")), "Basket Club des Lacs");
    expect(execute).toBeEnabled();
    await user.click(execute);

    await waitFor(() => expect(mockRunClubAction).toHaveBeenCalledWith("club-1", "reset-current-season", "csrf-123"));
  });

  it("lists the full closed catalogue (7 items) in the support-actions modal (A3)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });

    await user.click(await screen.findByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubRowActions }));
    const dialog = await screen.findByRole("dialog", { name: ADMIN_DASHBOARD_TEXTS.clubActionsModalTitle("Basket Club des Lacs") });
    // Le catalogue est FERMÉ : exactement 6 entrées, dont l'unique bouton « Offre » (A3).
    expect(within(dialog).getAllByRole("listitem")).toHaveLength(6);
    expect(within(dialog).getByRole("button", { name: /^Offre/ })).toBeInTheDocument();
  });

  it("renders the offer pickers FROM the served schema and sends the chosen offer + season (A3)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });

    await user.click(await screen.findByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubRowActions }));
    await user.click(await screen.findByRole("button", { name: /^Offre/ }));

    // Le picker d'offre n'offre QUE les codes servis par le schéma (aucune liste en dur).
    const planPicker = screen.getByLabelText("Offre", { selector: "select" });
    const planOptions = within(planPicker).getAllByRole("option").map((option) => option.textContent);
    expect(planOptions).toEqual([ADMIN_DASHBOARD_TEXTS.clubActionArgPlaceholder, "Découverte", "Essentiel", "Club", "Grand club", "Sans limite", "Bêta"]);

    // Tant que l'offre n'est pas choisie, pas de sélecteur de saison ; le bouton reste bloqué.
    expect(screen.queryByLabelText("Saison encaissée")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubActionExecute })).toBeDisabled();

    await user.selectOptions(planPicker, "essentiel");
    // Offre payante → la saison encaissée APPARAÎT et devient exigée : soumission
    // impossible tant qu'elle n'est pas choisie.
    const seasonPicker = screen.getByLabelText("Saison encaissée", { selector: "select" });
    expect(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubActionExecute })).toBeDisabled();
    await user.selectOptions(seasonPicker, "next");
    expect(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubActionExecute })).toBeEnabled();

    // Popup de confirmation OBLIGATOIRE, nommant l'offre et la saison, avant tout envoi.
    await user.click(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubActionExecute }));
    const confirm = await screen.findByRole("dialog", { name: ADMIN_DASHBOARD_TEXTS.clubActionConfirmTitle("Offre") });
    expect(within(confirm).getByText(/Essentiel/)).toBeInTheDocument();
    expect(within(confirm).getByText(/Saison suivante/)).toBeInTheDocument();
    expect(mockRunClubAction).not.toHaveBeenCalled();

    await user.click(within(confirm).getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubActionConfirmAttribution }));
    await waitFor(() => expect(mockRunClubAction).toHaveBeenCalledWith("club-1", "set-plan", "csrf-123", { plan: "essentiel", paidSeason: "next" }));
  });

  it("hides the season selector for Découverte and never sends it (A3)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });

    await user.click(await screen.findByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubRowActions }));
    await user.click(await screen.findByRole("button", { name: /^Offre/ }));

    await user.selectOptions(screen.getByLabelText("Offre", { selector: "select" }), "decouverte");
    // Découverte : aucune saison à encaisser → pas de sélecteur, exécution possible directe.
    expect(screen.queryByLabelText("Saison encaissée")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubActionExecute })).toBeEnabled();

    await user.click(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubActionExecute }));
    const confirm = await screen.findByRole("dialog", { name: ADMIN_DASHBOARD_TEXTS.clubActionConfirmTitle("Offre") });
    await user.click(within(confirm).getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubActionConfirmAttribution }));
    // Le corps envoyé ne porte QUE l'offre — jamais paidSeason sur Découverte.
    await waitFor(() => expect(mockRunClubAction).toHaveBeenCalledWith("club-1", "set-plan", "csrf-123", { plan: "decouverte" }));
  });

  it("searches and paginates through the clubs API", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });
    await screen.findByText("Basket Club des Lacs");

    await user.type(screen.getByRole("searchbox", { name: ADMIN_DASHBOARD_TEXTS.clubSearchLabel }), "  Lacs  ");
    await user.click(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubSearchSubmit }));
    await waitFor(() => expect(mockClubs).toHaveBeenCalledWith(1, 25, "Lacs"));

    await user.click(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubPageNext }));
    await waitFor(() => expect(mockClubs).toHaveBeenCalledWith(2, 25, "Lacs"));
  }, 10_000);

  it("refreshes all four monitoring feeds on demand", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });
    await screen.findByText("Basket Club des Lacs");

    await user.click(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.refresh }));

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

    expect(screen.getAllByText(ADMIN_DASHBOARD_TEXTS.jobSupervisionOnly)).toHaveLength(2);
    await user.click(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.jobRerun }));
    expect(screen.getByRole("dialog", { name: ADMIN_DASHBOARD_TEXTS.jobConfirmTitle })).toBeInTheDocument();
    expect(mockRunJob).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.jobConfirmLabel }));
    await waitFor(() => expect(mockRunJob).toHaveBeenCalledWith("import-school-holidays", "csrf-123"));
    await waitFor(() => expect(mockJobs).toHaveBeenCalledTimes(2));
  });

  it("renders a readable cadence label for a weekly job (db-restore-check)", async () => {
    // La console superadmin sert désormais un job hebdomadaire (contrôle de restauration,
    // dimanche 04:00). La colonne « Cadence » doit en afficher un libellé lisible, jamais
    // `undefined` (map de formatCadence incomplète = cellule vide).
    const weeklyJobs: AdminJobsResponse = {
      items: [
        {
          key: "db-restore-check",
          label: "Contrôle de restauration",
          command: "app:db:restore-check",
          cadence: "weekly",
          manualTriggerAllowed: false,
          nextRunAt: "2099-07-19T04:00:00+02:00",
          latestRun: null,
        },
      ],
    };
    mockJobs.mockReset().mockResolvedValue(weeklyJobs);
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=jobs" });

    expect(await screen.findByText("Contrôle de restauration")).toBeInTheDocument();
    expect(screen.getByText(ADMIN_DASHBOARD_TEXTS.cadenceWeekly)).toBeInTheDocument();
  });

  it("keeps the other monitoring panels visible when health fails", async () => {
    mockHealth.mockRejectedValue(new Error("health unavailable"));
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText(ADMIN_DASHBOARD_TEXTS.healthError)).toBeInTheDocument();
    expect(screen.getByText("Basket Club des Lacs")).toBeInTheDocument();
    expect(screen.getByText(ADMIN_DASHBOARD_TEXTS.overviewEyebrow)).toBeInTheDocument();
  });

  it("keeps the other monitoring panels visible when jobs fail", async () => {
    mockJobs.mockRejectedValue(new Error("jobs unavailable"));
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText(ADMIN_DASHBOARD_TEXTS.jobsError)).toBeInTheDocument();
    expect(screen.getByText("Basket Club des Lacs")).toBeInTheDocument();
    expect(screen.getByText(ADMIN_DASHBOARD_TEXTS.healthHeading)).toBeInTheDocument();
  });

  it("shows an explicit empty search result", async () => {
    mockClubs.mockResolvedValue({ ...clubs, items: [], pagination: { ...clubs.pagination, total: 0, pages: 0 } });
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });

    await user.type(screen.getByRole("searchbox", { name: ADMIN_DASHBOARD_TEXTS.clubSearchLabel }), "introuvable");
    await user.click(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubSearchSubmit }));

    expect(await screen.findByText(ADMIN_DASHBOARD_TEXTS.clubsEmptySearch("introuvable"))).toBeInTheDocument();
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
  
    await user.click(await screen.findByRole("tab", { name: /Incidents/ }));
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

  // PR1 console superadmin — « silence quand tout va bien » : une pastille sur l'onglet
  // Santé ou Incidents suffit à dire qu'il faut aller voir. Les signaux sont pollés au
  // niveau page (les panneaux montent toujours leurs enfants). On prouve ici QUAND chaque
  // pastille s'allume — à la source — et qu'elle reste ÉTEINTE au vert.
  describe("pastilles d'alerte des onglets Santé et Incidents", () => {
    // Attend que les lectures de la pastille aient répondu (sinon « éteint » serait juste
    // « pas encore chargé » — faux négatif). Le résultat correct au vert ÉTANT éteint, ce
    // settle rend l'assertion « éteint » fiable, et les cas positifs prouvent le mécanisme.
    async function settleBadgeSignals() {
      await screen.findByRole("tab", { name: /Santé/ });
      await waitFor(() => {
        expect(mockHealth).toHaveBeenCalled();
        expect(mockJobs).toHaveBeenCalled();
        expect(mockFreshness).toHaveBeenCalled();
        expect(mockFeedback).toHaveBeenCalled();
        expect(mockSystemErrors).toHaveBeenCalled();
      });
    }

    function allGreen() {
      mockHealth.mockReset().mockResolvedValue(health);
      mockJobs.mockReset().mockResolvedValue(jobs);
      mockFreshness.mockReset().mockResolvedValue(freshnessAllFresh);
      mockFeedback.mockReset().mockResolvedValue(feedbackResponse(null));
      mockSystemErrors.mockReset().mockResolvedValue(systemErrorsResponse(0));
    }

    it("n'allume AUCUNE pastille quand santé, jobs, fraîcheur, signalements et erreurs sont au vert (le journal d'audit n'allume jamais)", async () => {
      allGreen();
      renderWithProviders(<AdminDashboardPage />, { route: "/admin" });
      await settleBadgeSignals();

      expect(screen.getByRole("tab", { name: /Santé/ }).textContent).not.toMatch(/à vérifier/i);
      expect(screen.getByRole("tab", { name: /Incidents/ }).textContent).not.toMatch(/à vérifier/i);
    });

    it("allume Santé quand l'état de santé est dégradé", async () => {
      allGreen();
      mockHealth.mockReset().mockResolvedValue({ ...health, status: "degraded" });
      renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

      expect(await screen.findByRole("tab", { name: /Santé.*à vérifier/i })).toBeInTheDocument();
    });

    it("allume Santé quand un conteneur est à l'arrêt", async () => {
      allGreen();
      mockHealth.mockReset().mockResolvedValue({
        ...health,
        containers: [{ key: "php-fpm", name: "php-fpm", status: "down" }],
      });
      renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

      expect(await screen.findByRole("tab", { name: /Santé.*à vérifier/i })).toBeInTheDocument();
    });

    it("n'allume PAS Santé pour un conteneur « inconnu » ni une dépendance externe à l'arrêt (décision fondateur)", async () => {
      allGreen();
      mockHealth.mockReset().mockResolvedValue({
        ...health,
        containers: [{ key: "worker", name: "worker", status: "unknown" }],
        externalDependencies: [{ key: "ign", name: "IGN", status: "down" }],
      });
      renderWithProviders(<AdminDashboardPage />, { route: "/admin" });
      await settleBadgeSignals();

      expect(screen.getByRole("tab", { name: /Santé/ }).textContent).not.toMatch(/à vérifier/i);
    });

    it("allume Santé quand un job a échoué ou a été interrompu", async () => {
      allGreen();
      mockJobs.mockReset().mockResolvedValue({
        items: [{ ...jobs.items[0], key: "k", latestRun: { ...jobs.items[0].latestRun!, status: "failed" } }],
      });
      renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

      expect(await screen.findByRole("tab", { name: /Santé.*à vérifier/i })).toBeInTheDocument();
    });

    it("allume Santé quand un référentiel est périmé", async () => {
      allGreen();
      mockFreshness.mockReset().mockResolvedValue({
        items: [{ key: "ffbb", label: "Ligues & comités FFBB", lastUpdatedAt: null, staleAfterDays: 400, stale: true }],
      });
      renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

      expect(await screen.findByRole("tab", { name: /Santé.*à vérifier/i })).toBeInTheDocument();
    });

    it("allume Santé quand une lecture de santé échoue (isError)", async () => {
      allGreen();
      mockHealth.mockReset().mockRejectedValue(new Error("health down"));
      renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

      expect(await screen.findByRole("tab", { name: /Santé.*à vérifier/i })).toBeInTheDocument();
    });

    it("allume Incidents (pas Santé) quand un signalement n'est pas traité", async () => {
      allGreen();
      mockFeedback.mockReset().mockResolvedValue(feedbackResponse(12));
      renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

      expect(await screen.findByRole("tab", { name: /Incidents.*à vérifier/i })).toBeInTheDocument();
      expect(screen.getByRole("tab", { name: /Santé/ }).textContent).not.toMatch(/à vérifier/i);
    });

    it("allume Incidents (pas Santé) quand des messages asynchrones ont échoué", async () => {
      allGreen();
      mockHealth.mockReset().mockResolvedValue({ ...health, messenger: { ...health.messenger, failed: 3 } });
      renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

      expect(await screen.findByRole("tab", { name: /Incidents.*à vérifier/i })).toBeInTheDocument();
      expect(screen.getByRole("tab", { name: /Santé/ }).textContent).not.toMatch(/à vérifier/i);
    });

    it("allume Incidents pour une erreur système des 7 derniers jours, en passant la fenêtre `since` au backend", async () => {
      allGreen();
      mockSystemErrors.mockReset().mockResolvedValue(systemErrorsResponse(1));
      renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

      expect(await screen.findByRole("tab", { name: /Incidents.*à vérifier/i })).toBeInTheDocument();
      // Le comptage de la pastille borne la fenêtre à 7 j : au moins un appel porte un
      // `since` (une date ISO), distinct du sous-onglet paginé qui n'en passe pas.
      const sevenDaysAgo = new Date(Date.now() - 7 * 86_400_000).toISOString().slice(0, 10);
      expect(mockSystemErrors.mock.calls.some((args) => args[2] === sevenDaysAgo)).toBe(true);
    });

    it("allume Incidents quand la lecture des erreurs système échoue (isError)", async () => {
      allGreen();
      mockSystemErrors.mockReset().mockRejectedValue(new Error("errors down"));
      renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

      expect(await screen.findByRole("tab", { name: /Incidents.*à vérifier/i })).toBeInTheDocument();
    });
  });

  // PR2 console superadmin — « stats par club » : la ligne club se DÉPLIE (aria-expanded)
  // pour révéler membres / onboarding / crédits, porte un lien vers l'onglet Incidents, et
  // affiche un signal « club qui coince » (1re raison repliée, toutes au détail). La ligne
  // démo n'en porte jamais, et le bouton « Actions » reste présent dans tous les cas.
  describe("ligne club dépliable (stats par club)", () => {
    // La page monte TOUS ses panneaux d'onglets en même temps (pollés pour les pastilles) :
    // des nombres identiques (14, 47…) vivent dans Statistiques/Santé. On borne donc chaque
    // sonde à la TABLE des clubs, sinon un « 14 » venu d'ailleurs ferait un faux vert.
    const clubsTable = (): HTMLElement => screen.getByText("Union Sportive de Test").closest("table") as HTMLElement;
    // Compte les nœuds FEUILLES (sans enfant) dont le texte correspond, DANS la table clubs —
    // évite qu'un parent qui agrège plusieurs cellules fasse matcher un nombre par accident.
    const leafCount = (re: RegExp): number =>
      within(clubsTable()).queryAllByText((_content, el) => (el?.children.length ?? 1) === 0 && re.test(el?.textContent ?? "")).length;

    // Un club à détail riche : membres, onboarding, crédits, signalements et deux raisons
    // de blocage. Les valeurs (14 / 47) sont choisies pour n'apparaître nulle part ailleurs
    // dans la ligne repliée.
    const clubWithDetail = {
      ...clubs.items[0],
      id: "club-detail",
      name: "Union Sportive de Test",
      members: { active: 14, pending: 6 },
      onboarding: { completed: false, ffbbTeamsImportedAt: "2026-09-15T10:00:00+00:00", socleChosen: true },
      creditsUsed: 47,
      feedback: { untreated: 2 },
      stuck: { reasons: ["Onboarding en cours depuis 6 jours sans génération", "Aucune activité depuis 15 jours"] },
    };
    const demoClub = {
      ...clubs.items[0],
      id: "club-demo",
      name: "Club Démo Test",
      isDemo: true,
      members: { active: 3, pending: 0 },
      onboarding: { completed: true, ffbbTeamsImportedAt: null, socleChosen: true },
      creditsUsed: 0,
      feedback: { untreated: 0 },
      stuck: null,
    };
    const oneClub = (item: typeof clubWithDetail): AdminClubsResponse => ({
      items: [item],
      pagination: { page: 1, limit: 25, total: 1, pages: 1 },
      metricsWindowDays: 30,
    });

    it("déplie la ligne (aria-expanded) et révèle membres, onboarding et crédits, le bouton Actions restant présent", async () => {
      mockClubs.mockReset().mockResolvedValue(oneClub(clubWithDetail));
      const user = userEvent.setup();
      renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });
      await screen.findByText("Union Sportive de Test");

      // Replié : ni crédits, ni compteur de membres dans la table.
      expect(leafCount(/\b47\b/)).toBe(0);
      expect(leafCount(/\b14\b/)).toBe(0);

      const toggle = within(clubsTable()).getByRole("button", { expanded: false });
      await user.click(toggle);
      expect(toggle).toHaveAttribute("aria-expanded", "true");

      // Déplié : les trois familles de données sont rendues.
      expect(leafCount(/\b14\b/)).toBeGreaterThan(0); // membres actifs
      expect(leafCount(/\b47\b/)).toBeGreaterThan(0); // crédits consommés
      expect(leafCount(/membre/i)).toBeGreaterThan(0);
      expect(leafCount(/crédit/i)).toBeGreaterThan(0);
      expect(leafCount(/socle|onboarding|import/i)).toBeGreaterThan(0);

      // Le bouton Actions reste accessible dans la ligne, dépliée.
      const row = screen.getByText("Union Sportive de Test").closest("tr") as HTMLElement;
      expect(within(row).getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubRowActions })).toBeInTheDocument();
    });

    it("montre la première raison du signal sur la ligne et toutes les raisons au détail", async () => {
      mockClubs.mockReset().mockResolvedValue(oneClub(clubWithDetail));
      const user = userEvent.setup();
      renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });
      await screen.findByText("Union Sportive de Test");

      // La première raison est visible sans déplier ; la seconde ne l'est pas encore.
      expect(within(clubsTable()).getByText("Onboarding en cours depuis 6 jours sans génération")).toBeInTheDocument();
      expect(screen.queryByText("Aucune activité depuis 15 jours")).not.toBeInTheDocument();

      await user.click(within(clubsTable()).getByRole("button", { expanded: false }));
      expect(within(clubsTable()).getByText("Aucune activité depuis 15 jours")).toBeInTheDocument();
    });

    it("offre un lien vers l'onglet Incidents depuis le détail du club", async () => {
      mockClubs.mockReset().mockResolvedValue(oneClub(clubWithDetail));
      const user = userEvent.setup();
      renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });
      await screen.findByText("Union Sportive de Test");

      await user.click(within(clubsTable()).getByRole("button", { expanded: false }));
      const link = await within(clubsTable()).findByRole("link", { name: /signalement/i });
      expect(link).toHaveAttribute("href", expect.stringContaining("tab=incidents"));
    });

    it("n'affiche aucun signal sur une ligne démo, qui garde son bouton Actions", async () => {
      mockClubs.mockReset().mockResolvedValue({
        items: [clubWithDetail, demoClub],
        pagination: { page: 1, limit: 25, total: 2, pages: 1 },
        metricsWindowDays: 30,
      });
      renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });
      await screen.findByText("Club Démo Test");

      // Le signal n'appartient qu'au club qui coince (stuck non nul), jamais à la démo.
      expect(within(clubsTable()).getAllByText("Onboarding en cours depuis 6 jours sans génération")).toHaveLength(1);

      const demoRow = screen.getByText("Club Démo Test").closest("tr") as HTMLElement;
      expect(within(demoRow).getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.clubRowActions })).toBeInTheDocument();
    });
  });

  // #22 — le fondateur a dû se déconnecter pour voir une nouvelle demande d'inscription :
  // « Actualiser » ne rafraîchissait qu'une liste FIXE (overview/health/jobs/clubs/freshness/
  // feedback/systemErrors) et oubliait les demandes de création de clubs. La promesse : un clic
  // rafraîchit TOUTES les clés `admin-*` (dont `admin-club-requests`) SAUF `admin-session`.
  describe("« Actualiser » rafraîchit toute la console", () => {
    // La session admin est MONTÉE à côté de la page (comme sous AdminShell en vrai), pour
    // observer qu'Actualiser ne la re-demande jamais (ne jamais déconnecter sur un refresh).
    function PageUnderSession() {
      useAdminSession();
      return <AdminDashboardPage />;
    }

    it("re-demande les demandes d'inscription (le bug fondateur) et ne re-demande jamais la session", async () => {
      const user = userEvent.setup();
      // 1er appel : aucune demande ; 2e appel (après Actualiser) : une nouvelle demande arrive.
      mockClubRequests.mockReset().mockResolvedValueOnce({ items: [] }).mockResolvedValue(newClubRequest);
      renderWithProviders(<PageUnderSession />, { route: "/admin?tab=clubs" });

      await screen.findByText("Basket Club des Lacs");
      await waitFor(() => expect(mockSession).toHaveBeenCalledOnce());
      // Rien côté demandes avant le rafraîchissement.
      expect(screen.queryByText("AS Val de Marne")).not.toBeInTheDocument();

      await user.click(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.refresh }));

      // La nouvelle demande apparaît : `admin-club-requests` a bien été re-demandée.
      expect(await screen.findByText("AS Val de Marne")).toBeInTheDocument();
      // La session n'a PAS été re-demandée (clé `admin-session` exclue du rafraîchissement).
      expect(mockSession).toHaveBeenCalledOnce();
    });
  });

  // D3 — la console ouvre sur son nom et son geste, sans eyebrow ni description de présentation.
  it("l'en-tête se réduit au titre de la console et à « Actualiser »", async () => {
    renderWithProviders(<AdminDashboardPage />, { route: "/admin?tab=clubs" });

    expect(ADMIN_DASHBOARD_TEXTS.headerTitle).toBe("Console d'administration");
    expect(await screen.findByRole("heading", { level: 1, name: ADMIN_DASHBOARD_TEXTS.headerTitle })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.refresh })).toBeInTheDocument();
    expect(ADMIN_DASHBOARD_TEXTS, "plus d'eyebrow").not.toHaveProperty("headerEyebrow");
    expect(ADMIN_DASHBOARD_TEXTS, "plus de description").not.toHaveProperty("headerDescription");
  });
});
