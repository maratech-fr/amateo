import ky from "ky";

export interface AdminIdentity {
  id: string;
  email: string;
}

interface PasswordResponse {
  mfaRequired: true;
}

interface TotpResponse {
  authenticated: true;
  csrfToken: string;
}

export interface AdminSessionResponse extends AdminIdentity {
  csrfToken: string;
}

export interface AdminOverviewResponse {
  clubs: {
    total: number;
    active7d: number;
    active30d: number;
    new7d: number;
    unsubscribed: number;
  };
  solver: {
    windowDays: number;
    generations: number;
    completed: number;
    failed: number;
    infeasible: number;
    infeasibleRate: number;
    p50WallTimeMs: number | null;
    p95WallTimeMs: number | null;
    daily: Array<{
      date: string;
      generations: number;
      infeasible: number;
      p50WallTimeMs: number | null;
      p95WallTimeMs: number | null;
    }>;
  };
  /** Stats d'usage (SA2-stats). Optionnel : un backend antérieur au lot (rollback,
   *  décalage de déploiement) sert l'ancien overview sans ce bloc — l'UI ne doit
   *  pas planter, elle affiche l'indisponibilité. */
  usage?: {
    plansByType: Array<{ type: string; total: number; validated: number }>;
    timeToFirstValidation: {
      season: { count: number; p50Minutes: number | null; p95Minutes: number | null };
      period: { count: number; p50Minutes: number | null; p95Minutes: number | null };
    };
    solverByPlanType: Array<{ planType: string; generations: number; p50WallTimeMs: number | null; p95WallTimeMs: number | null }>;
    clubSizes: Array<{ bucket: string; clubs: number; medianVenues: number | null }>;
  };
}

/**
 * P5-10 — agrégats de CAPACITÉ lus de la télémétrie `solver_metrics` (fenêtre 90 j).
 * Tout est nullable côté valeurs : l'historique pré-2.6 et les chemins terminaux
 * (échec/timeout) laissent leurs champs vides — l'UI affiche « — », jamais un chiffre inventé.
 */
export interface AdminCapacityResponse {
  windowDays: number;
  totalSolves: number;
  volume: {
    perDay: { p50: number | null; max: number | null; maxDate: string | null };
    /** Heures LOCALES (Europe/Paris) peuplées seulement — le backend omet les heures vides. */
    hourly: Array<{ hour: number; solves: number }>;
  };
  wait: {
    queueP50Ms: number | null;
    queueP95Ms: number | null;
    queueMaxMs: number | null;
    /** Attente côté engine (sémaphore par club), p95. */
    engineWaitP95Ms: number | null;
  };
  /** Une ligne par tranche de taille de problème PRÉSENTE (bucket vide = pas de ligne). */
  bySize: Array<{
    bucket: string;
    solves: number;
    p50WallTimeMs: number | null;
    p95WallTimeMs: number | null;
    /** Fraction du budget consommée au p95 (0.65 = 65 %). */
    p95BudgetRatio: number | null;
    /** Fraction des solves FEASIBLE (trouvé, pas prouvé optimal) parmi ceux à détail. */
    unclosedProofRate: number;
  }>;
  memory: {
    peakP50Mb: number | null;
    peakP95Mb: number | null;
    peakMaxMb: number | null;
    /** RSS du process avant solve, dernière valeur connue (« à vide, le worker pèse déjà »). */
    lastBaselineMb: number | null;
  };
  issues: {
    byStatus: Array<{ status: string; solves: number }>;
    payloadP95Bytes: number | null;
  };
}

type HealthStatus = "up" | "down" | "unknown";

export interface AdminHealthContainer {
  key: string;
  name: string;
  status: "up" | "down" | "unknown";
  lastHeartbeatAt?: string;
  ageSeconds?: number;
  latencyMs?: number;
}

export interface AdminHealthExternalDependency {
  key: string;
  name: string;
  status: "up" | "down";
  latencyMs?: number;
}

export interface AdminHealthResponse {
  status: "healthy" | "degraded";
  checkedAt: string;
  services: {
    database: { status: HealthStatus; latencyMs: number | null };
    redis: { status: HealthStatus; latencyMs: number | null };
    engine: { status: HealthStatus; latencyMs: number | null };
    mercure: { status: HealthStatus; latencyMs: number | null };
    worker: {
      status: HealthStatus;
      lastHeartbeatAt: string | null;
      ageSeconds: number | null;
    };
  };
  messenger: {
    status: "up" | "degraded" | "unknown";
    backlog: number | null;
    failed: number | null;
    retriesToday: number | null;
    backlogWarningThreshold: number;
  };
  containers: AdminHealthContainer[];
  externalDependencies: AdminHealthExternalDependency[];
}

export interface AdminClub {
  id: string;
  name: string;
  slug: string;
  ffbbClubCode: string | null;
  /** P2-4 — club de démonstration : badgé dans la liste, exclu des KPI. */
  isDemo: boolean;
  /** Offre STOCKÉE (plan_id résolu) : null en Découverte par défaut. */
  plan: { code: string; name: string } | null;
  paidSeasonYear: number | null;
  /** Offre EFFECTIVE calculée côté backend (règle pivot) — ce que la console affiche. */
  effectivePlan: { code: string; name: string };
  billingCycle: string | null;
  generationCountSeason: number;
  createdAt: string;
  lastActivityAt: string | null;
  unsubscribed: boolean;
  currentSeason: { id: string; name: string; status: string } | null;
  volumes: { teams: number; venues: number; coaches: number; constraints: number };
  solver: {
    generations: number;
    infeasible: number;
    infeasibleRate: number;
    p50WallTimeMs: number | null;
    p95WallTimeMs: number | null;
    latestStatus: string | null;
    latestAt: string | null;
  };
}

export interface AdminClubsResponse {
  items: AdminClub[];
  pagination: { page: number; limit: number; total: number; pages: number };
  metricsWindowDays: number;
}

export type AdminJobStatus = "running" | "succeeded" | "failed" | "interrupted";

export interface AdminJob {
  key: string;
  label: string;
  command: string;
  cadence: "every_10_minutes" | "daily" | "quarterly";
  manualTriggerAllowed: boolean;
  nextRunAt: string;
  latestRun: {
    id: string;
    status: AdminJobStatus;
    source: "scheduled" | "cli" | "superadmin";
    startedAt: string;
    finishedAt: string | null;
    durationMs: number | null;
    exitCode: number | null;
  } | null;
}

export interface AdminJobsResponse {
  items: AdminJob[];
}

export interface AdminJobRunResponse {
  key: string;
  status: "succeeded";
  exitCode: 0;
}

/** Un choix d'un argument fermé (valeur CLI + libellé lisible pour le picker). */
export interface AdminActionChoice {
  value: string;
  label: string;
}

/**
 * Un argument runtime BORNÉ d'une action (A3) : enum fermée de choix, servie par le
 * backend — la console rend ses pickers DEPUIS ce schéma, jamais d'une liste en dur.
 * `gate` présent = argument conditionnel : masqué quand la valeur du gate ∈
 * `forbiddenValues`, requis sinon (règle de set-plan : saison encaissée exigée pour
 * une offre payante, interdite sur Découverte).
 */
export interface AdminActionArgumentSpec {
  key: string;
  label: string;
  required: boolean;
  choices: AdminActionChoice[];
  gate?: { argument: string; forbiddenValues: string[] };
}

/** SA4 — action support sur un club, du catalogue FERMÉ (backend AdminActionCatalog). */
export interface AdminAction {
  key: string;
  label: string;
  description: string;
  /** Destructif → confirmation nominative (taper le nom du club). */
  dangerous: boolean;
  /** Schéma d'arguments fermé (vide = l'action ne prend aucun body). */
  arguments: AdminActionArgumentSpec[];
}

export interface AdminActionsResponse {
  items: AdminAction[];
}

/** P3-4 PR B — demande de création de club en attente d'arbitrage. */
export interface AdminClubRequest {
  id: string;
  clubName: string;
  ara: string;
  /** null = mail FFBB introuvable — la console est la SEULE voie. */
  clubEmail: string | null;
  status: "pending" | "expired";
  requesterName: string;
  requesterEmail: string;
  createdAt: string;
  expiresAt: string;
}

export interface AdminClubRequestsResponse {
  items: AdminClubRequest[];
}

/** P3-4 PR B — adhésion en attente (le gestionnaire en place n'a pas tranché). */
export interface AdminPendingMembership {
  id: string;
  clubName: string;
  ara: string;
  userName: string;
  userEmail: string;
  createdAt: string;
}

export interface AdminPendingMembershipsResponse {
  items: AdminPendingMembership[];
}

export interface AdminClubActionRunResponse {
  key: string;
  clubId: string;
  status: "succeeded";
  exitCode: 0;
}

/** Data-freshness board : un référentiel externe et l'âge de sa dernière mise à jour. */
export interface AdminFreshnessItem {
  key: string;
  label: string;
  lastUpdatedAt: string | null;
  staleAfterDays: number;
  stale: boolean;
}

export interface AdminFreshnessResponse {
  items: AdminFreshnessItem[];
}

export interface AdminAuditLogItem {
  id: string;
  actorId: string | null;
  actorEmail: string | null;
  route: string | null;
  /** `details` JSON du log d'audit — objet arbitraire, ou null. */
  context: Record<string, unknown> | null;
  /** Code HTTP de l'action auditée (status_code), ou null si non enregistré. */
  status: number | null;
  createdAt: string;
}

export interface AdminAuditLogResponse {
  items: AdminAuditLogItem[];
  pagination: { page: number; limit: number; total: number; pages: number };
}

export interface AdminMessengerFailedItem {
  id: string;
  class: string;
  failedAt: string;
  lastErrorMessage: string;
}

export interface AdminMessengerFailedResponse {
  items: AdminMessengerFailedItem[];
  pagination: { page: number; limit: number; total: number; pages: number };
}

export interface AdminSystemErrorItem {
  source: string;
  message: string;
  severity: string;
  createdAt: string;
}

export interface AdminSystemErrorsResponse {
  items: AdminSystemErrorItem[];
  pagination: { page: number; limit: number; total: number; pages: number };
}

/**
 * P5-12 — une note du journal, vue de l'atelier superadmin (brouillons inclus).
 * `publishedAt` null = brouillon (invisible des membres). `date` est éditoriale.
 */
export interface AdminReleaseNote {
  id: string;
  title: string;
  body: string;
  date: string;
  publishedAt: string | null;
  createdAt: string;
}

export interface AdminReleaseNotesResponse {
  items: AdminReleaseNote[];
}

/** Corps d'écriture d'une note (création + édition). */
export interface ReleaseNoteWritePayload {
  title: string;
  body: string;
  noteDate: string;
}

// P5-6 — l'inbox des signalements (rail SA mergé) : liste + QoS + détail + verdicts.

export type AdminFeedbackTopic = "bug" | "missing_constraint" | "idea";
export type AdminFeedbackStatus = "untreated" | "treated";

export interface AdminFeedbackItem {
  id: string;
  clubId: string;
  clubName: string | null;
  topic: AdminFeedbackTopic;
  message: string;
  createdAt: string;
  status: AdminFeedbackStatus;
  treatedAt: string | null;
  hasHeavyContext: boolean;
}

/** Panneau qualité de service (§3ter) — agrégats GLOBAUX, calculés côté serveur. */
export interface AdminFeedbackQos {
  treatDelayByMonth: { month: string; avgHours: number | null; p95Hours: number | null }[];
  volumeByTopicMonth: { month: string; topic: AdminFeedbackTopic; count: number }[];
  treatedShare: number;
  oldestUntreatedAgeHours: number | null;
}

export interface AdminFeedbackResponse {
  items: AdminFeedbackItem[];
  pagination: { page: number; limit: number; total: number; pages: number };
  qos: AdminFeedbackQos;
}

/**
 * Contexte du détail — les 4 champs légers envoyés par le client + le lourd enrichi
 * par le serveur (snapshot/diagnostics opaques). Rendu en TEXTE PUR ; le snapshot n'est
 * jamais déplié dans le DOM (métadonnées + « copier le JSON » seulement).
 */
export interface AdminFeedbackContext {
  screen?: string;
  url?: string;
  requestId?: string;
  userAgent?: string;
  scheduleId?: string;
  seasonId?: string;
  scheduleStatus?: string;
  snapshot?: unknown;
  diagnostics?: unknown[];
  [key: string]: unknown;
}

export interface AdminFeedbackDetail extends AdminFeedbackItem {
  context: AdminFeedbackContext | null;
}

/** Session-cookie client for /api/admin. It deliberately never reads the club JWT store. */
export const adminApi = ky.create({
  prefix: "/api/admin",
  credentials: "same-origin",
});

export function startAdminPassword(body: { email: string; password: string }): Promise<PasswordResponse> {
  return adminApi.post("auth/password", { json: body }).json();
}

export function completeAdminTotp(code: string): Promise<TotpResponse> {
  return adminApi.post("auth/totp", { json: { code } }).json();
}

export function getAdminSession(): Promise<AdminSessionResponse> {
  return adminApi.get("auth/me").json();
}

export function getAdminOverview(): Promise<AdminOverviewResponse> {
  return adminApi.get("overview").json();
}

export function getAdminHealth(): Promise<AdminHealthResponse> {
  return adminApi.get("health").json();
}

export function getAdminCapacity(): Promise<AdminCapacityResponse> {
  return adminApi.get("capacity").json();
}

export function getAdminClubs(page: number, limit: number, query: string): Promise<AdminClubsResponse> {
  return adminApi.get("clubs", { searchParams: { page, limit, query } }).json();
}

export function getAdminJobs(): Promise<AdminJobsResponse> {
  return adminApi.get("jobs").json();
}

export function runAdminJob(key: string, csrfToken: string): Promise<AdminJobRunResponse> {
  return adminApi.post(`jobs/${encodeURIComponent(key)}/run`, { headers: { "X-CSRF-Token": csrfToken } }).json();
}

export function getAdminActions(): Promise<AdminActionsResponse> {
  return adminApi.get("actions").json();
}

export function getAdminFreshness(): Promise<AdminFreshnessResponse> {
  return adminApi.get("freshness").json();
}

export function runAdminClubAction(clubId: string, key: string, csrfToken: string, body?: Record<string, string>): Promise<AdminClubActionRunResponse> {
  // Body OPTIONNEL : seulement pour une action à schéma (ex. set-plan). Absent → POST
  // sans corps (l'action sans schéma n'accepte aucun argument, garde fail-closed côté backend).
  return adminApi
    .post(`clubs/${encodeURIComponent(clubId)}/actions/${encodeURIComponent(key)}`, {
      headers: { "X-CSRF-Token": csrfToken },
      ...(body ? { json: body } : {}),
    })
    .json();
}

export function logoutAdmin(csrfToken: string): Promise<void> {
  return adminApi.post("auth/logout", { headers: { "X-CSRF-Token": csrfToken } }).then(() => undefined);
}

export function getAdminAuditLog(page: number, limit: number): Promise<AdminAuditLogResponse> {
  return adminApi.get("audit-log", { searchParams: { page, limit } }).json();
}

export function getAdminMessengerFailed(page: number, limit: number): Promise<AdminMessengerFailedResponse> {
  return adminApi.get("messenger/failed", { searchParams: { page, limit } }).json();
}

export function getAdminSystemErrors(page: number, limit: number): Promise<AdminSystemErrorsResponse> {
  return adminApi.get("system-errors", { searchParams: { page, limit } }).json();
}

export function getAdminClubRequests(): Promise<AdminClubRequestsResponse> {
  return adminApi.get("club-requests").json();
}

export function decideAdminClubRequest(id: string, decision: "approve" | "refuse", csrfToken: string): Promise<{ status: string }> {
  return adminApi.post(`club-requests/${encodeURIComponent(id)}/decision`, { json: { decision }, headers: { "X-CSRF-Token": csrfToken } }).json();
}

export function getAdminPendingMemberships(): Promise<AdminPendingMembershipsResponse> {
  return adminApi.get("pending-memberships").json();
}

export function activateAdminMembership(id: string, csrfToken: string): Promise<{ status: string }> {
  return adminApi.post(`pending-memberships/${encodeURIComponent(id)}/activate`, { headers: { "X-CSRF-Token": csrfToken } }).json();
}

// P5-12 — l'atelier du journal de nouveautés (écritures CSRF-protégées).

export function getAdminReleaseNotes(): Promise<AdminReleaseNotesResponse> {
  return adminApi.get("release-notes").json();
}

export function createAdminReleaseNote(body: ReleaseNoteWritePayload, csrfToken: string): Promise<AdminReleaseNote> {
  return adminApi.post("release-notes", { json: body, headers: { "X-CSRF-Token": csrfToken } }).json();
}

export function publishAdminReleaseNote(id: string, csrfToken: string): Promise<AdminReleaseNote> {
  return adminApi.post(`release-notes/${encodeURIComponent(id)}/publish`, { headers: { "X-CSRF-Token": csrfToken } }).json();
}

export function deleteAdminReleaseNote(id: string, csrfToken: string): Promise<void> {
  return adminApi.delete(`release-notes/${encodeURIComponent(id)}`, { headers: { "X-CSRF-Token": csrfToken } }).then(() => undefined);
}

// P5-6 — signalements (lecture + verdicts CSRF-protégés).

export function getAdminFeedback(params: { page?: number; limit?: number; status?: AdminFeedbackStatus }): Promise<AdminFeedbackResponse> {
  const searchParams: Record<string, string | number> = {};
  if (params.page) searchParams.page = params.page;
  if (params.limit) searchParams.limit = params.limit;
  if (params.status) searchParams.status = params.status;
  return adminApi.get("feedback", { searchParams }).json();
}

export function getAdminFeedbackDetail(id: string): Promise<AdminFeedbackDetail> {
  return adminApi.get(`feedback/${encodeURIComponent(id)}`).json();
}

export function treatAdminFeedback(id: string, csrfToken: string): Promise<AdminFeedbackItem> {
  return adminApi.post(`feedback/${encodeURIComponent(id)}/treat`, { headers: { "X-CSRF-Token": csrfToken } }).json();
}

export function untreatAdminFeedback(id: string, csrfToken: string): Promise<AdminFeedbackItem> {
  return adminApi.post(`feedback/${encodeURIComponent(id)}/untreat`, { headers: { "X-CSRF-Token": csrfToken } }).json();
}

// Démos — pilotage des deux comptes de démonstration (PR B du lot Démos).

export type AdminDemoTarget = "bccl" | "prospect";

/**
 * État d'un compte démo. `activeUntil` est un ISO (UTC) rendu à l'heure de Paris côté écran ;
 * `clubName` est le club démo courant du compte (null s'il n'existe pas). `simulatedToday`
 * (YYYY-MM-DD) n'existe QUE pour la démo BCCL — l'horloge simulée.
 */
export interface AdminDemoAccount {
  email: string;
  activeUntil: string | null;
  clubName: string | null;
  simulatedToday?: string | null;
}

/** Un club démo CONSERVÉ (P4-294) : détaché de l'animateur, gardé 14 jours. Nom + échéance (YYYY-MM-DD). */
export interface AdminRetainedDemoClub {
  name: string;
  retainedUntil: string;
}

export interface AdminDemosResponse {
  bccl: AdminDemoAccount;
  prospect: AdminDemoAccount;
  /** Les clubs démo conservés, lus par la table (jamais par adhésion) ; pas de prolongation (14 j fixes). */
  retained: AdminRetainedDemoClub[];
}

export function getAdminDemos(): Promise<AdminDemosResponse> {
  return adminApi.get("demos").json();
}

export function activateAdminDemo(target: AdminDemoTarget, csrfToken: string): Promise<{ target: AdminDemoTarget; activeUntil: string }> {
  return adminApi.post(`demos/${target}/activate`, { headers: { "X-CSRF-Token": csrfToken } }).json();
}

export function deactivateAdminDemo(target: AdminDemoTarget, csrfToken: string): Promise<{ target: AdminDemoTarget; activeUntil: null }> {
  return adminApi.post(`demos/${target}/deactivate`, { headers: { "X-CSRF-Token": csrfToken } }).json();
}

export function resetAdminDemoBccl(csrfToken: string): Promise<{ status: "reset" }> {
  return adminApi.post("demos/bccl/reset", { headers: { "X-CSRF-Token": csrfToken } }).json();
}

/** Pose (`{date}`) ou relâche (`{clear:true}`) l'horloge simulée d'un compte démo (bccl ou prospect). */
export function setAdminDemoClock(target: AdminDemoTarget, body: { date: string } | { clear: true }, csrfToken: string): Promise<{ simulatedToday: string | null }> {
  return adminApi.post(`demos/${target}/clock`, { json: body, headers: { "X-CSRF-Token": csrfToken } }).json();
}

/** Conserve 14 jours le club démo prospect (P4-294) — refusé (409) tant que la fenêtre d'accès est ouverte. */
export function retainAdminDemoProspect(csrfToken: string): Promise<{ retainedUntil: string }> {
  return adminApi.post("demos/prospect/retain", { headers: { "X-CSRF-Token": csrfToken } }).json();
}
