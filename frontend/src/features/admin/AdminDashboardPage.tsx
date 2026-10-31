import { type Query, useIsFetching, useQueryClient } from "@tanstack/react-query";
import {
  AlertTriangle,
  CheckCircle2,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Cpu,
  Database,
  History,
  MapPin,
  Radio,
  RefreshCw,
  RotateCw,
  Search,
  Server,
  Users,
  Workflow,
  Zap,
} from "lucide-react";
import { type FormEvent, type ReactNode, useEffect, useState } from "react";
import { Link, useSearchParams } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyBlock, EmptyHint } from "@/shared/components/ui/empty-hint";
import { Modal } from "@/shared/components/ui/modal";
import { Spinner } from "@/shared/components/ui/spinner";
import { useTodayISO } from "@/shared/lib/clock";
import { cn } from "@/shared/lib/utils";
import { toast } from "@/shared/stores/toastStore";

import type { AdminAction, AdminActionArgumentSpec, AdminClub, AdminFreshnessResponse, AdminHealthResponse, AdminJob, AdminJobStatus, AdminJobsResponse, AdminOverviewResponse } from "./api";
import { useAdminActions, useAdminClubs, useAdminFeedback, useAdminFreshness, useAdminHealth, useAdminJobs, useAdminOverview, useAdminSystemErrors, useRunAdminClubAction, useRunAdminJob } from "./queries";
import { AudienceSection } from "./sections/AudienceSection";
import { CapacitySection } from "./sections/CapacitySection";
import { ClubRequestsSection } from "./sections/ClubRequestsSection";
import { ContainersSection } from "./sections/ContainersSection";
import { DemosSection } from "./sections/DemosSection";
import { ExternalDepsSection } from "./sections/ExternalDepsSection";
import { DeploymentSection } from "./sections/DeploymentSection";
import { FeedbackSection } from "./sections/FeedbackSection";
import { ReleaseNotesSection } from "./sections/ReleaseNotesSection";
import { AuditSubtab } from "./Journaux/AuditSubtab";
import { MessengerFailedSubtab } from "./Journaux/MessengerFailedSubtab";
import { SystemErrorsSubtab } from "./Journaux/SystemErrorsSubtab";
import { ADMIN_TABS, DEFAULT_SUBTAB, STORAGE_KEY, resolveActiveSubTab, resolveActiveTab } from "./tabs/tabsConfig";
import { ADMIN_DASHBOARD_TEXTS } from "./texts/dashboard";
import { TabPanel, Tabs } from "@/shared/components/ui/tabs";

const CLUBS_PER_PAGE = 25;

// #22 — « Actualiser » rafraîchit TOUTES les données `admin-*` de la console (dont les demandes
// d'inscription), SAUF la session : la re-demander déconnecterait le superadmin. Un seul prédicat
// partagé pour l'invalidation (le clic) ET l'état « en cours » du bouton (useIsFetching).
const isRefreshableAdminQuery = (query: Query): boolean => {
  const key = query.queryKey[0];
  return typeof key === "string" && key.startsWith("admin-") && key !== "admin-session";
};

const integerFormatter = new Intl.NumberFormat("fr-FR");
const dateFormatter = new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "short", year: "numeric" });
const shortDateFormatter = new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "short" });

export function AdminDashboardPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [query, setQuery] = useState("");
  const [queryDraft, setQueryDraft] = useState("");
  const overview = useAdminOverview();
  const health = useAdminHealth();
  const jobs = useAdminJobs();
  const freshness = useAdminFreshness();
  const clubs = useAdminClubs(page, CLUBS_PER_PAGE, query);
  // PR1 — « silence quand tout va bien » : deux signaux POLLÉS AU NIVEAU PAGE pour les
  // pastilles d'alerte des onglets Santé/Incidents. Le feedback réutilise la requête déjà
  // hissée (QoS) ; le SEUL ajout réseau est le comptage des erreurs système des 7 derniers
  // jours (limit=1, fenêtre `since`), distinct du sous-onglet paginé.
  const feedback = useAdminFeedback();
  // Fenêtre de 7 jours pour le comptage de la pastille Incidents. L'« aujourd'hui » passe par
  // l'horloge partagée (jamais `new Date()` en rendu) ; la console n'a pas de club, donc c'est
  // la date réelle. On recule de 7 jours au format YYYY-MM-DD attendu par le backend.
  const todayIso = useTodayISO();
  const sinceDate = new Date(`${todayIso}T00:00:00Z`);
  sinceDate.setUTCDate(sinceDate.getUTCDate() - 7);
  const sevenDaysAgo = sinceDate.toISOString().slice(0, 10);
  const recentSystemErrors = useAdminSystemErrors(1, 1, sevenDaysAgo);
  // Bouton « Actualiser » en cours dès qu'une requête `admin-*` (hors session) charge — même
  // prédicat que l'invalidation, pour que toute la console (demandes d'inscription comprises) compte.
  const refreshing = useIsFetching({ predicate: isRefreshableAdminQuery }) > 0;

  // Santé = une défaillance d'infrastructure : état global dégradé, un conteneur À L'ARRÊT
  // (jamais « inconnu »), un job en échec/interrompu, un référentiel périmé, ou une lecture
  // qui échoue. Les dépendances externes N'allument PAS (décision fondateur PR1).
  const santeAlert =
    health.isError ||
    jobs.isError ||
    freshness.isError ||
    health.data?.status === "degraded" ||
    (health.data?.containers.some((c) => c.status === "down") ?? false) ||
    (jobs.data?.items.some((j) => j.latestRun?.status === "failed" || j.latestRun?.status === "interrupted") ?? false) ||
    (freshness.data?.items.some((item) => item.stale) ?? false);

  // Incidents = quelque chose à traiter : un signalement non traité (QoS), un message async
  // en échec, une erreur système des 7 derniers jours, ou une lecture qui échoue. Le journal
  // d'audit (diagnostic) n'allume JAMAIS.
  const incidentsAlert =
    recentSystemErrors.isError ||
    feedback.isError ||
    (feedback.data?.qos.oldestUntreatedAgeHours ?? null) !== null ||
    (health.data?.messenger.failed ?? 0) > 0 ||
    (recentSystemErrors.data ? recentSystemErrors.data.pagination.total > 0 : false);

  const tabs = ADMIN_TABS.map((tab) => ({
    ...tab,
    badge:
      (tab.id === "sante" && santeAlert) || (tab.id === "incidents" && incidentsAlert) ? ADMIN_DASHBOARD_TEXTS.tabAlertBadge : undefined,
  }));

  const [searchParams, setSearchParams] = useSearchParams();
  const activeTab = resolveActiveTab(searchParams.get("tab"), localStorage.getItem(STORAGE_KEY));
  const activeSubTab = resolveActiveSubTab(activeTab, activeTab === "incidents" ? searchParams.get("sub") : null);

  useEffect(() => {
    localStorage.setItem(STORAGE_KEY, activeTab);
  }, [activeTab]);

  // On mount, sync URL if the tab param is missing/invalid (localStorage → URL, replace — no history entry).
  useEffect(() => {
    const urlTab = searchParams.get("tab");
    if (urlTab !== activeTab) {
      setSearchParams(
        (prev) => {
          prev.set("tab", activeTab);
          if (activeTab === "incidents") prev.set("sub", activeSubTab);
          else prev.delete("sub");
          return prev;
        },
        { replace: true },
      );
    }
    // mount-only — we don't want to replace-history on every tab change.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function handleTabChange(id: string) {
    setSearchParams((prev) => {
      prev.set("tab", id);
      if (id === "incidents") prev.set("sub", DEFAULT_SUBTAB);
      else prev.delete("sub");
      return prev;
    });
  }

  function handleSubTabChange(id: string) {
    setSearchParams((prev) => {
      prev.set("sub", id);
      return prev;
    });
  }

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPage(1);
    setQuery(queryDraft.trim());
  }

  function refreshAll() {
    void queryClient.invalidateQueries({ predicate: isRefreshableAdminQuery });
  }

  const incidentsSubTabs =
    ADMIN_TABS.find((t) => t.id === "incidents")?.subTabs?.map((s) => ({ id: s.id, label: s.label })) ?? [];

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <h1 className="sr-only">{ADMIN_DASHBOARD_TEXTS.headerTitle}</h1>
        <Tabs tabs={tabs} activeTab={activeTab} onTabChange={handleTabChange} ariaLabel={ADMIN_DASHBOARD_TEXTS.tabsAriaLabel} idPrefix="admin" variant="console" />
        <Button
          type="button"
          variant="outline"
          className="border-white/15 text-console-text-bright hover:bg-white/10"
          disabled={refreshing}
          onClick={refreshAll}
        >
          {refreshing ? <Spinner className="size-4 text-console-text" /> : <RefreshCw aria-hidden="true" />}
          {ADMIN_DASHBOARD_TEXTS.refresh}
        </Button>
      </div>

      <TabPanel variant="console" tabId="clubs" idPrefix="admin" active={activeTab === "clubs"} className="space-y-8 pt-6">
        {/* P3-4 PR B : les arbitrages (créations + adhésions) vivent avec le parc. */}
        <ClubRequestsSection />
        <section aria-labelledby="clubs-heading" className="space-y-4">
          <div className="flex flex-col justify-between gap-4 md:flex-row md:items-end">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">{ADMIN_DASHBOARD_TEXTS.clubsEyebrow}</p>
              <h2 id="clubs-heading" className="mt-2 text-xl font-semibold text-white">{ADMIN_DASHBOARD_TEXTS.clubsHeading}</h2>
            </div>
            <form className="flex w-full gap-2 md:max-w-md" role="search" onSubmit={submitSearch}>
              <label className="sr-only" htmlFor="club-search">{ADMIN_DASHBOARD_TEXTS.clubSearchLabel}</label>
              <div className="relative min-w-0 flex-1">
                <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-console-muted" aria-hidden="true" />
                <input
                  id="club-search"
                  type="search"
                  value={queryDraft}
                  maxLength={100}
                  onChange={(event) => setQueryDraft(event.target.value)}
                  placeholder={ADMIN_DASHBOARD_TEXTS.clubSearchPlaceholder}
                  className="h-10 w-full rounded-md border border-white/15 bg-white/[0.04] pl-10 pr-3 text-sm text-white outline-none placeholder:text-console-text-faint focus:border-console-accent/70 focus:ring-2 focus:ring-console-accent/20"
                />
              </div>
              <Button type="submit" className="bg-console-accent text-console-surface hover:bg-console-accent-hover">{ADMIN_DASHBOARD_TEXTS.clubSearchSubmit}</Button>
            </form>
          </div>

          {clubs.isPending ? <PanelLoading label={ADMIN_DASHBOARD_TEXTS.clubsLoading} /> : null}
          {clubs.isError ? <PanelError label={ADMIN_DASHBOARD_TEXTS.clubsError} retry={() => void clubs.refetch()} /> : null}
          {clubs.data ? (
            <ClubsTable
              clubs={clubs.data.items}
              page={clubs.data.pagination.page}
              pages={clubs.data.pagination.pages}
              total={clubs.data.pagination.total}
              query={query}
              loading={clubs.isFetching}
              onPageChange={setPage}
            />
          ) : null}
        </section>
        {/* PR1 — les comptes de démonstration vivent désormais en bas de l'onglet Clubs. */}
        <DemosSection />
      </TabPanel>

      <TabPanel variant="console" tabId="statistiques" idPrefix="admin" active={activeTab === "statistiques"} className="space-y-8 pt-6">
        <AudienceSection />
        <OverviewSection data={overview.data} loading={overview.isPending} error={overview.isError} retry={() => void overview.refetch()} />
        <UsageSection data={overview.data} loading={overview.isPending} error={overview.isError} retry={() => void overview.refetch()} />
        <CapacitySection />
      </TabPanel>

      <TabPanel variant="console" tabId="sante" idPrefix="admin" active={activeTab === "sante"} className="space-y-8 pt-6">
        <HealthSection data={health.data} loading={health.isPending} error={health.isError} retry={() => void health.refetch()} />
        <ContainersSection />
        <ExternalDepsSection />
        <JobsSection data={jobs.data} loading={jobs.isPending} error={jobs.isError} retry={() => void jobs.refetch()} />
        <FreshnessSection data={freshness.data} loading={freshness.isPending} error={freshness.isError} retry={() => void freshness.refetch()} />
      </TabPanel>

      <TabPanel variant="console" tabId="incidents" idPrefix="admin" active={activeTab === "incidents"} className="space-y-6 pt-6">
        <Tabs variant="console" tabs={incidentsSubTabs} activeTab={activeSubTab} onTabChange={handleSubTabChange} ariaLabel={ADMIN_DASHBOARD_TEXTS.incidentsTabsAriaLabel} idPrefix="admin-incidents" />
        <TabPanel variant="console" tabId="signalements" idPrefix="admin-incidents" active={activeSubTab === "signalements"}>
          <FeedbackSection />
        </TabPanel>
        <TabPanel variant="console" tabId="errors" idPrefix="admin-incidents" active={activeSubTab === "errors"}>
          <SystemErrorsSubtab />
        </TabPanel>
        <TabPanel variant="console" tabId="messenger" idPrefix="admin-incidents" active={activeSubTab === "messenger"}>
          <MessengerFailedSubtab />
        </TabPanel>
        <TabPanel variant="console" tabId="audit" idPrefix="admin-incidents" active={activeSubTab === "audit"}>
          <AuditSubtab />
        </TabPanel>
      </TabPanel>

      <TabPanel variant="console" tabId="exploitation" idPrefix="admin" active={activeTab === "exploitation"} className="space-y-8 pt-6">
        <DeploymentSection />
        <ReleaseNotesSection />
      </TabPanel>
    </div>
  );
}

function OverviewSection({ data, loading, error, retry }: DataSectionProps<AdminOverviewResponse>) {
  if (loading) return <PanelLoading label={ADMIN_DASHBOARD_TEXTS.overviewLoading} />;
  if (error || !data) return <PanelError label={ADMIN_DASHBOARD_TEXTS.overviewError} retry={retry} />;

  const metrics = [
    { label: ADMIN_DASHBOARD_TEXTS.metricClubs, value: data.clubs.total, detail: ADMIN_DASHBOARD_TEXTS.metricNew7d(integerFormatter.format(data.clubs.new7d)) },
    { label: ADMIN_DASHBOARD_TEXTS.metricActive7d, value: data.clubs.active7d, detail: ADMIN_DASHBOARD_TEXTS.metricActive30d(integerFormatter.format(data.clubs.active30d)) },
    { label: ADMIN_DASHBOARD_TEXTS.metricGenerations, value: data.solver.generations, detail: ADMIN_DASHBOARD_TEXTS.metricWindowDays(data.solver.windowDays) },
    { label: ADMIN_DASHBOARD_TEXTS.metricInfeasibleRate, value: formatRate(data.solver.infeasibleRate), detail: ADMIN_DASHBOARD_TEXTS.metricInfeasibleCount(integerFormatter.format(data.solver.infeasible)) },
  ];

  return (
    <section aria-labelledby="activity-heading" className="space-y-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">{ADMIN_DASHBOARD_TEXTS.overviewEyebrow}</p>
        <h2 id="activity-heading" className="mt-2 text-xl font-semibold text-white">{ADMIN_DASHBOARD_TEXTS.overviewHeading}</h2>
      </div>
      <div className="grid gap-px overflow-hidden rounded-xl border border-white/10 bg-white/10 sm:grid-cols-2 xl:grid-cols-4">
        {metrics.map((metric) => <Metric key={metric.label} {...metric} />)}
      </div>
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1.7fr)_minmax(18rem,1fr)]">
        <SolverChart solver={data.solver} />
        <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
          <p className="text-sm font-medium text-white">{ADMIN_DASHBOARD_TEXTS.solverPerformance}</p>
          <dl className="mt-5 grid grid-cols-2 gap-x-5 gap-y-6">
            <SmallMetric label={ADMIN_DASHBOARD_TEXTS.solverCompleted} value={data.solver.completed} />
            <SmallMetric label={ADMIN_DASHBOARD_TEXTS.solverFailed} value={data.solver.failed} tone={data.solver.failed > 0 ? "danger" : undefined} />
            <SmallMetric label={ADMIN_DASHBOARD_TEXTS.solverMedianDuration} value={formatDuration(data.solver.p50WallTimeMs)} />
            <SmallMetric label={ADMIN_DASHBOARD_TEXTS.solverP95} value={formatDuration(data.solver.p95WallTimeMs)} />
          </dl>
          <div className="mt-6 border-t border-white/10 pt-4 text-xs text-console-muted">
            {ADMIN_DASHBOARD_TEXTS.unsubscribedCount(data.clubs.unsubscribed)}
          </div>
        </article>
      </div>
    </section>
  );
}

const formatMinutes = (minutes: number | null): string => {
  if (null === minutes) return "—";
  if (minutes < 60) return `${integerFormatter.format(minutes)} min`;
  if (minutes < 48 * 60) return `${integerFormatter.format(Math.round(minutes / 60))} h`;
  return `${integerFormatter.format(Math.round(minutes / 1440))} j`;
};

/**
 * Stats d'usage (SA2-stats) : plans par type (dont validés), temps de clôture
 * (création → 1re validation), charge solveur par type, profil des tailles de clubs.
 * Lecture seule — répond « l'app est-elle utilisée, à quel volume ? ».
 */
function UsageSection({ data, loading, error, retry }: DataSectionProps<AdminOverviewResponse>) {
  if (loading) return <PanelLoading label={ADMIN_DASHBOARD_TEXTS.usageLoading} />;
  // `usage` absent = backend antérieur au lot (rollback / décalage de déploiement) :
  // indisponibilité affichée, jamais un crash de rendu.
  if (error || !data?.usage) return <PanelError label={ADMIN_DASHBOARD_TEXTS.usageError} retry={retry} />;

  const { usage } = data;

  return (
    <section aria-labelledby="usage-heading" className="space-y-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">{ADMIN_DASHBOARD_TEXTS.usageEyebrow}</p>
        <h2 id="usage-heading" className="mt-2 text-xl font-semibold text-white">{ADMIN_DASHBOARD_TEXTS.usageHeading}</h2>
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
          <p className="text-sm font-medium text-white">{ADMIN_DASHBOARD_TEXTS.usagePlansByType}</p>
          <p className="mt-1 text-xs text-console-muted">{ADMIN_DASHBOARD_TEXTS.usagePlansByTypeHint}</p>
          <dl className="mt-5 space-y-4">
            {usage.plansByType.length === 0 ? <EmptyHint variant="console">{ADMIN_DASHBOARD_TEXTS.usageNoPlan}</EmptyHint> : null}
            {usage.plansByType.map((row) => (
              <div key={row.type} className="flex items-baseline justify-between gap-3">
                <dt className="text-sm text-console-text-dim">{ADMIN_DASHBOARD_TEXTS.planTypeLabel(row.type)}</dt>
                <dd className="text-sm text-white">
                  {integerFormatter.format(row.total)}
                  <span className="ml-2 text-xs text-console-muted">{ADMIN_DASHBOARD_TEXTS.usagePlansValidated(row.validated)}</span>
                </dd>
              </div>
            ))}
          </dl>
        </article>
        <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
          <p className="text-sm font-medium text-white">{ADMIN_DASHBOARD_TEXTS.usageCloseTime}</p>
          <p className="mt-1 text-xs text-console-muted">{ADMIN_DASHBOARD_TEXTS.usageCloseTimeHint}</p>
          <dl className="mt-5 grid grid-cols-2 gap-x-5 gap-y-6">
            <SmallMetric label={ADMIN_DASHBOARD_TEXTS.usageSeasonMedian} value={formatMinutes(usage.timeToFirstValidation.season.p50Minutes)} />
            <SmallMetric label={ADMIN_DASHBOARD_TEXTS.usageSeasonP95} value={formatMinutes(usage.timeToFirstValidation.season.p95Minutes)} />
            <SmallMetric label={ADMIN_DASHBOARD_TEXTS.usagePeriodMedian} value={formatMinutes(usage.timeToFirstValidation.period.p50Minutes)} />
            <SmallMetric label={ADMIN_DASHBOARD_TEXTS.usagePeriodP95} value={formatMinutes(usage.timeToFirstValidation.period.p95Minutes)} />
          </dl>
          <div className="mt-6 border-t border-white/10 pt-4 text-xs text-console-muted">
            {ADMIN_DASHBOARD_TEXTS.usageCloseCounts(usage.timeToFirstValidation.season.count, usage.timeToFirstValidation.period.count)}
          </div>
        </article>
        <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
          <p className="text-sm font-medium text-white">{ADMIN_DASHBOARD_TEXTS.usageSolverByType}</p>
          <dl className="mt-5 space-y-4">
            {usage.solverByPlanType.length === 0 ? <EmptyHint variant="console">{ADMIN_DASHBOARD_TEXTS.usageNoGenerationWindow}</EmptyHint> : null}
            {usage.solverByPlanType.map((row) => (
              <div key={row.planType} className="flex items-baseline justify-between gap-3">
                <dt className="text-sm text-console-text-dim">{ADMIN_DASHBOARD_TEXTS.planTypeLabel(row.planType)}</dt>
                <dd className="text-sm text-white">
                  {integerFormatter.format(row.generations)}
                  <span className="ml-2 text-xs text-console-muted">{ADMIN_DASHBOARD_TEXTS.usageSolverRowMeta(formatDuration(row.p50WallTimeMs), formatDuration(row.p95WallTimeMs))}</span>
                </dd>
              </div>
            ))}
          </dl>
        </article>
      </div>
      <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
        <p className="text-sm font-medium text-white">{ADMIN_DASHBOARD_TEXTS.usageClubSizes}</p>
        <p className="mt-1 text-xs text-console-muted">{ADMIN_DASHBOARD_TEXTS.usageClubSizesHint}</p>
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[24rem] text-left text-sm">
            <thead>
              <tr className="text-xs uppercase tracking-wide text-console-muted">
                <th className="py-2 pr-4 font-medium">{ADMIN_DASHBOARD_TEXTS.usageColTeams}</th>
                <th className="py-2 pr-4 font-medium">{ADMIN_DASHBOARD_TEXTS.usageColClubs}</th>
                <th className="py-2 font-medium">{ADMIN_DASHBOARD_TEXTS.usageColVenuesMedian}</th>
              </tr>
            </thead>
            <tbody>
              {usage.clubSizes.map((row) => (
                <tr key={row.bucket} className="border-t border-white/10 text-console-text">
                  <td className="py-2 pr-4">{row.bucket}</td>
                  <td className="py-2 pr-4 text-white">{integerFormatter.format(row.clubs)}</td>
                  <td className="py-2">{null === row.medianVenues ? "—" : integerFormatter.format(row.medianVenues)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </article>
    </section>
  );
}

function SolverChart({ solver }: { solver: AdminOverviewResponse["solver"] }) {
  const max = Math.max(...solver.daily.map((day) => day.generations), 1);

  return (
    <figure className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-sm font-medium text-white">{ADMIN_DASHBOARD_TEXTS.solverDailyTitle}</p>
          <p className="mt-1 text-xs text-console-muted">{ADMIN_DASHBOARD_TEXTS.solverDailyHint(solver.windowDays)}</p>
        </div>
        <span className="flex items-center gap-2 text-xs text-console-muted"><span className="size-2 bg-console-accent" /> {ADMIN_DASHBOARD_TEXTS.solverDailyLegend}</span>
      </div>
      {solver.daily.length > 0 ? (
        <>
          <div className="mt-6 flex h-36 items-end gap-1" aria-hidden="true">
            {solver.daily.map((day) => (
              <div key={day.date} className="group relative flex h-full min-w-0 flex-1 items-end">
                <div
                  className="w-full min-w-1 bg-console-accent/60 transition-colors group-hover:bg-console-accent-hover"
                  style={{ height: `${Math.max((day.generations / max) * 100, day.generations > 0 ? 4 : 1)}%` }}
                />
              </div>
            ))}
          </div>
          <div className="mt-2 flex justify-between text-xs text-console-text-faint">
            <span>{formatShortDate(solver.daily[0]?.date)}</span>
            <span>{formatShortDate(solver.daily.at(-1)?.date)}</span>
          </div>
          <figcaption className="sr-only">
            {solver.daily.map((day) => ADMIN_DASHBOARD_TEXTS.solverDayCaption(day.date, day.generations)).join(" ; ")}
          </figcaption>
        </>
      ) : <EmptyHint variant="console" className="mt-10">{ADMIN_DASHBOARD_TEXTS.solverNoGenerationPeriod}</EmptyHint>}
    </figure>
  );
}

function HealthSection({ data, loading, error, retry }: DataSectionProps<AdminHealthResponse>) {
  if (loading) return <PanelLoading label={ADMIN_DASHBOARD_TEXTS.healthLoading} />;
  if (error || !data) return <PanelError label={ADMIN_DASHBOARD_TEXTS.healthError} retry={retry} />;

  const services = [
    { key: "database", label: ADMIN_DASHBOARD_TEXTS.healthServiceDatabase, icon: Database, ...data.services.database },
    { key: "redis", label: ADMIN_DASHBOARD_TEXTS.healthServiceRedis, icon: Zap, ...data.services.redis },
    { key: "engine", label: ADMIN_DASHBOARD_TEXTS.healthServiceEngine, icon: Cpu, ...data.services.engine },
    { key: "mercure", label: ADMIN_DASHBOARD_TEXTS.healthServiceMercure, icon: Radio, ...data.services.mercure },
  ];

  return (
    <section aria-labelledby="health-heading" className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">{ADMIN_DASHBOARD_TEXTS.healthEyebrow}</p>
          <h2 id="health-heading" className="mt-2 text-xl font-semibold text-white">{ADMIN_DASHBOARD_TEXTS.healthHeading}</h2>
        </div>
        <p className="text-xs text-console-muted">{ADMIN_DASHBOARD_TEXTS.healthCheckedAt(formatDateTime(data.checkedAt))}</p>
      </div>
      <div className="grid gap-4 xl:grid-cols-[minmax(0,1.5fr)_minmax(20rem,1fr)]">
        <article className="overflow-hidden rounded-xl border border-white/10 bg-white/[0.04]">
          <div className="flex items-center justify-between border-b border-white/10 px-5 py-4">
            <div className="flex items-center gap-3"><Server className="size-4 text-console-muted" aria-hidden="true" /><span className="text-sm font-medium text-white">{ADMIN_DASHBOARD_TEXTS.healthDependencies}</span></div>
            <StatusChip status={data.status === "healthy" ? "up" : "degraded"} />
          </div>
          <ul className="grid sm:grid-cols-2">
            {services.map(({ key, label, icon: Icon, status, latencyMs }) => (
              <li key={key} className="flex items-center justify-between gap-4 border-b border-white/10 px-5 py-4 last:border-b-0 sm:odd:border-r sm:[&:nth-last-child(-n+2)]:border-b-0">
                <div className="flex items-center gap-3"><Icon className="size-4 text-console-muted" aria-hidden="true" /><span className="text-sm text-console-text">{label}</span></div>
                <div className="text-right"><StatusChip status={status} /><p className="mt-1 text-xs text-console-text-faint">{latencyMs === null ? "—" : `${latencyMs} ms`}</p></div>
              </li>
            ))}
          </ul>
        </article>

        <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
          <div className="flex items-center justify-between gap-4">
            <div className="flex items-center gap-3"><Workflow className="size-4 text-console-muted" aria-hidden="true" /><span className="text-sm font-medium text-white">{ADMIN_DASHBOARD_TEXTS.healthAsyncProcessing}</span></div>
            <StatusChip status={data.messenger.status} />
          </div>
          <dl className="mt-5 grid grid-cols-3 gap-3 border-b border-white/10 pb-5">
            <SmallMetric label={ADMIN_DASHBOARD_TEXTS.healthBacklog} value={nullableInteger(data.messenger.backlog)} />
            <SmallMetric label={ADMIN_DASHBOARD_TEXTS.healthFailed} value={nullableInteger(data.messenger.failed)} tone={(data.messenger.failed ?? 0) > 0 ? "danger" : undefined} />
            <SmallMetric label={ADMIN_DASHBOARD_TEXTS.healthRetries} value={nullableInteger(data.messenger.retriesToday)} />
          </dl>
          <div className="mt-5 flex items-start justify-between gap-4">
            <div><p className="text-xs text-console-muted">{ADMIN_DASHBOARD_TEXTS.healthWorker}</p><p className="mt-1 text-sm text-console-text">{formatHeartbeat(data.services.worker)}</p></div>
            <StatusChip status={data.services.worker.status} />
          </div>
        </article>
      </div>
    </section>
  );
}

function JobsSection({ data, loading, error, retry }: DataSectionProps<AdminJobsResponse>) {
  const [jobToRun, setJobToRun] = useState<AdminJob | null>(null);
  const runJob = useRunAdminJob();

  function confirmRun() {
    if (!jobToRun) return;

    const job = jobToRun;
    setJobToRun(null);
    runJob.mutate(job.key, {
      onSuccess: () => toast.success(ADMIN_DASHBOARD_TEXTS.jobRunSuccess(job.label)),
      onError: () => toast.error(ADMIN_DASHBOARD_TEXTS.jobRunError(job.label)),
    });
  }

  if (loading) return <PanelLoading label={ADMIN_DASHBOARD_TEXTS.jobsLoading} />;
  if (error || !data) return <PanelError label={ADMIN_DASHBOARD_TEXTS.jobsError} retry={retry} />;

  return (
    <section aria-labelledby="jobs-heading" className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">{ADMIN_DASHBOARD_TEXTS.jobsEyebrow}</p>
          <h2 id="jobs-heading" className="mt-2 text-xl font-semibold text-white">{ADMIN_DASHBOARD_TEXTS.jobsHeading}</h2>
        </div>
        <p className="text-xs text-console-muted">{ADMIN_DASHBOARD_TEXTS.jobsHint}</p>
      </div>
      {data.items.length === 0 ? (
        <EmptyBlock variant="console">{ADMIN_DASHBOARD_TEXTS.jobsEmpty}</EmptyBlock>
      ) : (
        <div className="overflow-hidden rounded-xl border border-white/10 bg-white/[0.03]">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[1180px] text-left text-sm">
              <caption className="sr-only">{ADMIN_DASHBOARD_TEXTS.jobsCaption}</caption>
              <thead className="border-b border-white/10 bg-white/[0.03] text-xs uppercase tracking-wider text-console-muted">
                <tr><th className="px-5 py-4 font-medium">{ADMIN_DASHBOARD_TEXTS.jobColJob}</th><th className="px-4 py-4 font-medium">{ADMIN_DASHBOARD_TEXTS.jobColCadence}</th><th className="px-4 py-4 font-medium">{ADMIN_DASHBOARD_TEXTS.jobColNextRun}</th><th className="px-4 py-4 font-medium">{ADMIN_DASHBOARD_TEXTS.jobColLastRun}</th><th className="px-4 py-4 font-medium">{ADMIN_DASHBOARD_TEXTS.jobColDuration}</th><th className="px-4 py-4 font-medium">{ADMIN_DASHBOARD_TEXTS.jobColResult}</th><th className="px-4 py-4 font-medium">{ADMIN_DASHBOARD_TEXTS.jobColAction}</th></tr>
              </thead>
              <tbody className="divide-y divide-white/10">
                {data.items.map((job) => <JobRow key={job.key} job={job} running={runJob.isPending && runJob.variables === job.key} onRun={() => setJobToRun(job)} />)}
              </tbody>
            </table>
          </div>
        </div>
      )}
      <ConfirmDialog
        open={jobToRun !== null}
        title={ADMIN_DASHBOARD_TEXTS.jobConfirmTitle}
        description={jobToRun ? ADMIN_DASHBOARD_TEXTS.jobConfirmDescription(jobToRun.label) : undefined}
        confirmLabel={ADMIN_DASHBOARD_TEXTS.jobConfirmLabel}
        destructive={false}
        onConfirm={confirmRun}
        onCancel={() => setJobToRun(null)}
      />
    </section>
  );
}

function JobRow({ job, running, onRun }: { job: AdminJob; running: boolean; onRun: () => void }) {
  return (
    <tr className="align-top text-console-text hover:bg-white/[0.025]">
      <td className="px-5 py-5"><div className="flex items-start gap-3"><div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md bg-white/[0.06] text-console-muted"><History className="size-4" aria-hidden="true" /></div><div><p className="font-medium text-white">{job.label}</p><p className="mt-1 font-mono text-xs text-console-text-faint">{job.command}</p></div></div></td>
      <td className="px-4 py-5">{formatCadence(job.cadence)}</td>
      <td className="px-4 py-5"><NextRun value={job.nextRunAt} /></td>
      <td className="px-4 py-5">{job.latestRun ? <><p>{formatDateTime(job.latestRun.startedAt)}</p><p className="mt-1 text-xs text-console-text-faint">{formatJobSource(job.latestRun.source)}</p></> : <span className="text-console-muted">{ADMIN_DASHBOARD_TEXTS.jobNeverRun}</span>}</td>
      <td className="px-4 py-5 tabular-nums">{job.latestRun ? formatDuration(job.latestRun.durationMs) : "—"}</td>
      <td className="px-4 py-5"><JobStatus status={job.latestRun?.status ?? null} />{job.latestRun?.exitCode !== null && job.latestRun?.exitCode !== undefined ? <p className="mt-1 text-xs text-console-text-faint">{ADMIN_DASHBOARD_TEXTS.jobExitCode(job.latestRun.exitCode)}</p> : null}</td>
      <td className="px-4 py-5">{job.manualTriggerAllowed ? <Button type="button" size="sm" variant="outline" className="border-white/15 text-console-text-bright hover:bg-white/10" disabled={running} onClick={onRun}>{running ? <Spinner className="size-3.5" /> : <RotateCw className="size-3.5" aria-hidden="true" />} {running ? ADMIN_DASHBOARD_TEXTS.jobRunning : ADMIN_DASHBOARD_TEXTS.jobRerun}</Button> : <span className="text-xs text-console-text-faint">{ADMIN_DASHBOARD_TEXTS.jobSupervisionOnly}</span>}</td>
    </tr>
  );
}

function NextRun({ value }: { value: string }) {
  return <p className="text-console-text">{formatDateTime(value)}</p>;
}

function JobStatus({ status }: { status: AdminJobStatus | null }) {
  if (status === null) return <span className="text-xs font-medium text-console-muted">{ADMIN_DASHBOARD_TEXTS.jobNoHistory}</span>;

  const successful = status === "succeeded";
  const running = status === "running";
  const Icon = successful ? CheckCircle2 : status === "failed" ? AlertTriangle : History;
  return <span className={cn("inline-flex items-center gap-1.5 text-xs font-medium", successful ? "text-console-success" : running ? "text-console-accent" : status === "failed" ? "text-console-warning" : "text-console-text-dim")}><Icon className="size-3.5" aria-hidden="true" />{ADMIN_DASHBOARD_TEXTS.jobStatusLabel(status)}</span>;
}

/**
 * Data-freshness board : « mes données de référence sont-elles à jour ? ». Rend
 * VISIBLE l'import automatique mort en silence — un référentiel jamais importé ou
 * trop vieux s'affiche « Périmé » (le job d'alerte emaile en parallèle).
 */
function FreshnessSection({ data, loading, error, retry }: DataSectionProps<AdminFreshnessResponse>) {
  if (loading) return <PanelLoading label={ADMIN_DASHBOARD_TEXTS.freshnessLoading} />;
  if (error || !data) return <PanelError label={ADMIN_DASHBOARD_TEXTS.freshnessError} retry={retry} />;

  return (
    <section aria-labelledby="freshness-heading" className="space-y-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">{ADMIN_DASHBOARD_TEXTS.freshnessEyebrow}</p>
        <h2 id="freshness-heading" className="mt-2 text-xl font-semibold text-white">{ADMIN_DASHBOARD_TEXTS.freshnessHeading}</h2>
      </div>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {data.items.map((item) => (
          <article key={item.key} className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
            <div className="flex items-start justify-between gap-3">
              <p className="text-sm font-medium text-white">{item.label}</p>
              <span className={cn("shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold", item.stale ? "bg-console-warning-tint/15 text-console-warning" : "bg-console-success-tint/15 text-console-success")}>
                {item.stale ? ADMIN_DASHBOARD_TEXTS.freshnessStale : ADMIN_DASHBOARD_TEXTS.freshnessFresh}
              </span>
            </div>
            <p className="mt-3 text-xs text-console-muted">
              {item.lastUpdatedAt ? ADMIN_DASHBOARD_TEXTS.freshnessLastUpdated(formatDate(item.lastUpdatedAt)) : ADMIN_DASHBOARD_TEXTS.freshnessNeverImported}
            </p>
            <p className="mt-1 text-xs text-console-text-faint">{ADMIN_DASHBOARD_TEXTS.freshnessThreshold(integerFormatter.format(item.staleAfterDays))}</p>
          </article>
        ))}
      </div>
    </section>
  );
}

function ClubsTable({ clubs, page, pages, total, query, loading, onPageChange }: {
  clubs: AdminClub[];
  page: number;
  pages: number;
  total: number;
  query: string;
  loading: boolean;
  onPageChange: (page: number) => void;
}) {
  // SA4 : une action support à la fois — le dialog vit au niveau table, pas par ligne.
  const [actionClub, setActionClub] = useState<AdminClub | null>(null);

  if (clubs.length === 0) {
    return <EmptyBlock variant="console">{query ? ADMIN_DASHBOARD_TEXTS.clubsEmptySearch(query) : ADMIN_DASHBOARD_TEXTS.clubsEmpty}</EmptyBlock>;
  }

  return (
    <div className={cn("overflow-hidden rounded-xl border border-white/10 bg-white/[0.03]", loading && "opacity-70")}>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[1120px] text-left text-sm">
          <caption className="sr-only">{ADMIN_DASHBOARD_TEXTS.clubsCaption}</caption>
          <thead className="border-b border-white/10 bg-white/[0.03] text-xs uppercase tracking-wider text-console-muted">
            <tr><th className="px-5 py-4 font-medium">{ADMIN_DASHBOARD_TEXTS.clubColClub}</th><th className="px-4 py-4 font-medium">{ADMIN_DASHBOARD_TEXTS.clubColActivity}</th><th className="px-4 py-4 font-medium">{ADMIN_DASHBOARD_TEXTS.clubColOffer}</th><th className="px-4 py-4 font-medium">{ADMIN_DASHBOARD_TEXTS.clubColSeasonVolume}</th><th className="px-4 py-4 font-medium">{ADMIN_DASHBOARD_TEXTS.clubColSolver}</th><th className="px-4 py-4 font-medium">{ADMIN_DASHBOARD_TEXTS.clubColSupport}</th></tr>
          </thead>
          <tbody className="divide-y divide-white/10">
            {clubs.map((club) => <ClubRow key={club.id} club={club} onActions={() => setActionClub(club)} />)}
          </tbody>
        </table>
      </div>
      {actionClub ? <ClubActionsDialog club={actionClub} onClose={() => setActionClub(null)} /> : null}
      <div className="flex items-center justify-between gap-4 border-t border-white/10 px-5 py-4">
        <p className="text-xs text-console-muted">{ADMIN_DASHBOARD_TEXTS.clubsPagination(total, page, pages)}</p>
        <div className="flex gap-2">
          <Button type="button" size="sm" variant="ghost" className="text-console-text hover:bg-white/10" aria-label={ADMIN_DASHBOARD_TEXTS.clubPagePrev} disabled={page <= 1 || loading} onClick={() => onPageChange(page - 1)}><ChevronLeft aria-hidden="true" /></Button>
          <Button type="button" size="sm" variant="ghost" className="text-console-text hover:bg-white/10" aria-label={ADMIN_DASHBOARD_TEXTS.clubPageNext} disabled={page >= pages || loading} onClick={() => onPageChange(page + 1)}><ChevronRight aria-hidden="true" /></Button>
        </div>
      </div>
    </div>
  );
}

function ClubRow({ club, onActions }: { club: AdminClub; onActions: () => void }) {
  const [expanded, setExpanded] = useState(false);
  // Signal « club qui coince » calculé côté backend : la 1re raison est visible sur la ligne
  // repliée, toutes au détail (null = rien ne coince, ou club démo/désabonné).
  const firstReason = club.stuck?.reasons[0];

  return (
    <>
      <tr className="align-top text-console-text hover:bg-white/[0.025]">
        <td className="px-5 py-5"><div className="flex items-start gap-3"><button type="button" aria-expanded={expanded} aria-label={ADMIN_DASHBOARD_TEXTS.clubToggleDetail(expanded, club.name)} onClick={() => setExpanded((value) => !value)} className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md bg-white/[0.06] text-console-muted hover:bg-white/10">{expanded ? <ChevronDown className="size-4" aria-hidden="true" /> : <ChevronRight className="size-4" aria-hidden="true" />}</button><div><p className="font-medium text-white">{club.name}</p><p className="mt-1 text-xs text-console-text-faint">{club.ffbbClubCode ?? club.slug}</p>{club.isDemo ? <span className="mt-2 mr-2 inline-block rounded bg-console-demo-surface/20 px-1.5 py-0.5 text-xs font-medium text-console-demo">{ADMIN_DASHBOARD_TEXTS.clubDemo}</span> : null}{club.unsubscribed ? <span className="mt-2 inline-block text-xs font-medium text-console-warning">{ADMIN_DASHBOARD_TEXTS.clubUnsubscribed}</span> : null}{firstReason ? <p className="mt-2 flex items-center gap-1 text-xs font-medium text-console-warning"><AlertTriangle className="size-3 shrink-0" aria-hidden="true" />{firstReason}</p> : null}</div></div></td>
        <td className="px-4 py-5"><p>{club.lastActivityAt ? formatDate(club.lastActivityAt) : ADMIN_DASHBOARD_TEXTS.clubNeverActive}</p><p className="mt-1 text-xs text-console-text-faint">{ADMIN_DASHBOARD_TEXTS.clubCreatedAt(formatDate(club.createdAt))}</p></td>
        <td className="px-4 py-5"><p className="text-white">{club.effectivePlan.name}</p>{club.plan && club.plan.code !== club.effectivePlan.code ? <p className="mt-1 text-xs text-console-warning">{ADMIN_DASHBOARD_TEXTS.clubPlanDiverges(club.plan.name)}</p> : null}<p className="mt-1 text-xs text-console-text-faint">{ADMIN_DASHBOARD_TEXTS.clubGenerationCount(club.generationCountSeason)}{club.billingCycle ? ` · ${club.billingCycle}` : ""}</p></td>
        <td className="px-4 py-5"><p>{club.currentSeason?.name ?? ADMIN_DASHBOARD_TEXTS.clubNoSeason}</p><p className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-console-text-faint"><span className="flex items-center gap-1"><Users className="size-3" aria-hidden="true" />{ADMIN_DASHBOARD_TEXTS.clubTeamsCoaches(club.volumes.teams, club.volumes.coaches)}</span><span className="flex items-center gap-1"><MapPin className="size-3" aria-hidden="true" />{ADMIN_DASHBOARD_TEXTS.clubVenues(club.volumes.venues)}</span><span>{ADMIN_DASHBOARD_TEXTS.clubConstraints(club.volumes.constraints)}</span></p></td>
        <td className="px-4 py-5"><p><span className="font-medium text-white">{club.solver.generations}</span>{ADMIN_DASHBOARD_TEXTS.clubSolverSummary(formatRate(club.solver.infeasibleRate))}</p><p className="mt-1 text-xs text-console-text-faint">{ADMIN_DASHBOARD_TEXTS.clubSolverPercentiles(formatDuration(club.solver.p50WallTimeMs), formatDuration(club.solver.p95WallTimeMs))}</p>{club.solver.latestStatus ? <p className="mt-2 text-xs text-console-muted">{ADMIN_DASHBOARD_TEXTS.clubLatestStatus(club.solver.latestStatus)}</p> : null}</td>
        <td className="px-4 py-5">
          <Button type="button" size="sm" variant="outline" className="border-white/15 text-console-text-bright hover:bg-white/10" onClick={onActions}>
            {ADMIN_DASHBOARD_TEXTS.clubRowActions}
          </Button>
        </td>
      </tr>
      {expanded ? <ClubDetailRow club={club} /> : null}
    </>
  );
}

/** Détail déplié d'une ligne club (stats par club) : membres, onboarding, crédits,
 *  signalements (lien vers l'onglet Incidents) et toutes les raisons du signal « coince ». */
function ClubDetailRow({ club }: { club: AdminClub }) {
  const untreated = club.feedback?.untreated ?? 0;

  return (
    <tr className="bg-white/[0.02] text-console-text">
      <td colSpan={6} className="px-5 pb-6 pt-1">
        <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-console-muted">{ADMIN_DASHBOARD_TEXTS.clubDetailMembers}</p>
            <p className="mt-1 text-sm">{ADMIN_DASHBOARD_TEXTS.clubMembersActive(club.members?.active ?? 0)}</p>
            <p className="mt-1 text-xs text-console-text-faint">{ADMIN_DASHBOARD_TEXTS.clubMembersPending(club.members?.pending ?? 0)}</p>
          </div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-console-muted">{ADMIN_DASHBOARD_TEXTS.clubDetailOnboarding}</p>
            <p className="mt-1 text-sm">{club.onboarding?.completed ? ADMIN_DASHBOARD_TEXTS.clubOnboardingDone : ADMIN_DASHBOARD_TEXTS.clubOnboardingNotDone}</p>
            <p className="mt-1 text-xs text-console-text-faint">{ADMIN_DASHBOARD_TEXTS.clubSocle(club.onboarding?.socleChosen ?? false)}</p>
            <p className="mt-1 text-xs text-console-text-faint">{club.onboarding?.ffbbTeamsImportedAt ? ADMIN_DASHBOARD_TEXTS.clubFfbbImportedAt(formatDate(club.onboarding.ffbbTeamsImportedAt)) : ADMIN_DASHBOARD_TEXTS.clubFfbbNotImported}</p>
          </div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-console-muted">{ADMIN_DASHBOARD_TEXTS.clubDetailCredits}</p>
            <p className="mt-1 text-sm">{ADMIN_DASHBOARD_TEXTS.clubCreditsUsed(club.creditsUsed ?? 0)}</p>
          </div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-console-muted">{ADMIN_DASHBOARD_TEXTS.clubDetailSignalements}</p>
            <Link to="/admin?tab=incidents" className="mt-1 inline-block text-sm text-console-accent underline-offset-2 hover:underline">{ADMIN_DASHBOARD_TEXTS.clubSignalements(untreated)}</Link>
          </div>
        </div>
        {club.stuck ? (
          <div className="mt-5">
            <p className="text-xs font-semibold uppercase tracking-wider text-console-warning">{ADMIN_DASHBOARD_TEXTS.clubDetailStuck}</p>
            <ul className="mt-2 space-y-1 text-sm text-console-warning">
              {club.stuck.reasons.map((reason) => <li key={reason} className="flex items-center gap-1"><AlertTriangle className="size-3 shrink-0" aria-hidden="true" />{reason}</li>)}
            </ul>
          </div>
        ) : null}
      </td>
    </tr>
  );
}

/** Un argument à schéma est-il VISIBLE au vu des valeurs choisies ? Sans gate → toujours.
 *  Avec gate → seulement quand la valeur du gate est posée ET hors des valeurs interdites
 *  (ex. « saison encaissée » masquée tant que l'offre n'est pas choisie, et sur Découverte). */
function isArgVisible(arg: AdminActionArgumentSpec, values: Record<string, string>): boolean {
  if (!arg.gate) return true;
  const gateValue = values[arg.gate.argument];
  return gateValue !== undefined && gateValue !== "" && !arg.gate.forbiddenValues.includes(gateValue);
}

/** Requis pour soumettre : un argument sans gate suit son `required` ; un conditionnel
 *  est requis exactement quand il est visible (offre payante → saison exigée). */
function isArgRequired(arg: AdminActionArgumentSpec, values: Record<string, string>): boolean {
  return arg.gate ? isArgVisible(arg, values) : arg.required;
}

/**
 * SA4 — dialog des actions support sur UN club. Catalogue FERMÉ servi par le backend.
 * Une action à SCHÉMA (ex. « Offre ») rend ses pickers DEPUIS le schéma servi (aucune
 * liste d'offres en dur), puis exige une popup de confirmation NOMMANT l'offre et la
 * saison choisies avant d'exécuter. Une action `dangerous` exige de TAPER LE NOM du
 * club (confirmation nominative, pattern GitHub) — le clic réflexe ne suffit jamais.
 */
function ClubActionsDialog({ club, onClose }: { club: AdminClub; onClose: () => void }) {
  const actions = useAdminActions();
  const run = useRunAdminClubAction();
  const [selected, setSelected] = useState<AdminAction | null>(null);
  const [confirmName, setConfirmName] = useState("");
  const [argValues, setArgValues] = useState<Record<string, string>>({});
  const [confirmingOffer, setConfirmingOffer] = useState(false);

  const schema = selected?.arguments ?? [];
  const hasSchema = schema.length > 0;

  // trim SYMÉTRIQUE : un nom de club stocké avec un espace de bord resterait
  // inconfirmable sinon (revue SA4, finding 5). Un nom VIDE après trim reste
  // INCONFIRMABLE (fail-closed) : sinon un champ vide validerait le destructif
  // sur un club au nom blanc (round 2) — bloquer vaut mieux que contourner.
  const normalizedClubName = club.name.trim();
  const nameBlocked = null !== selected && selected.dangerous && ("" === normalizedClubName || confirmName.trim() !== normalizedClubName);
  const schemaIncomplete = schema.some((arg) => isArgRequired(arg, argValues) && !argValues[arg.key]);
  const primaryBlocked = nameBlocked || (hasSchema && schemaIncomplete) || run.isPending;

  // Corps soumis = seulement les arguments VISIBLES et renseignés (la saison masquée
  // sur Découverte n'est jamais envoyée — le backend la refuserait, fail-closed des deux côtés).
  const submitArgs = (): Record<string, string> => {
    const out: Record<string, string> = {};
    for (const arg of schema) {
      if (isArgVisible(arg, argValues) && argValues[arg.key]) out[arg.key] = argValues[arg.key];
    }
    return out;
  };

  const reset = () => {
    setSelected(null);
    setConfirmName("");
    setArgValues({});
    setConfirmingOffer(false);
  };

  const doRun = () => {
    if (!selected || primaryBlocked) return;
    const action = selected;
    run.mutate(
      { clubId: club.id, key: action.key, args: hasSchema ? submitArgs() : undefined },
      {
        onSuccess: () => {
          toast.success(ADMIN_DASHBOARD_TEXTS.clubActionRunSuccess(action.label, club.name));
          onClose();
        },
        onError: () => toast.error(ADMIN_DASHBOARD_TEXTS.clubActionRunError(action.label, club.name)),
      },
    );
  };

  // Une action à schéma passe TOUJOURS par la popup de confirmation ; les autres exécutent
  // directement (le destructif étant déjà gardé par la saisie nominative ci-dessus).
  const onPrimary = () => {
    if (primaryBlocked) return;
    if (hasSchema) {
      setConfirmingOffer(true);
      return;
    }
    doRun();
  };

  // Résumé nominatif pour la popup : chaque argument visible → « Libellé : Choix ».
  const chosenSummary = schema
    .filter((arg) => isArgVisible(arg, argValues) && argValues[arg.key])
    .map((arg) => ({ label: arg.label, value: arg.choices.find((c) => c.value === argValues[arg.key])?.label ?? argValues[arg.key] }));

  return (
    <Modal
      label={ADMIN_DASHBOARD_TEXTS.clubActionsModalTitle(club.name)}
      title={ADMIN_DASHBOARD_TEXTS.clubActionsModalTitle(club.name)}
      onClose={onClose}
      size="xl"
      // Pied ÉPINGLÉ seulement quand une action est SÉLECTIONNÉE — le catalogue (selected===null)
      // défile sans pied, ses entrées sont elles-mêmes les cibles cliquables.
      footer={
        selected ? (
          <>
            <Button type="button" variant="ghost" onClick={reset} disabled={run.isPending}>
              {ADMIN_DASHBOARD_TEXTS.clubActionBack}
            </Button>
            <Button type="button" variant={selected.dangerous ? "destructive" : "default"} disabled={primaryBlocked} onClick={onPrimary}>
              {run.isPending ? <Spinner className="size-4" /> : null}
              {ADMIN_DASHBOARD_TEXTS.clubActionExecute}
            </Button>
          </>
        ) : undefined
      }
    >
      <div className="mt-4 space-y-4">
        {actions.isPending ? <p className="text-sm text-muted-foreground">{ADMIN_DASHBOARD_TEXTS.clubActionCatalogLoading}</p> : null}
        {actions.isError ? <p className="text-sm text-destructive">{ADMIN_DASHBOARD_TEXTS.clubActionCatalogError}</p> : null}

        {actions.data && null === selected ? (
          <ul className="space-y-2">
            {actions.data.items.map((action) => (
              <li key={action.key}>
                <button
                  type="button"
                  className="w-full rounded-md border border-border px-4 py-3 text-left hover:bg-accent/10"
                  onClick={() => {
                    setSelected(action);
                    setConfirmName("");
                    setArgValues({});
                  }}
                >
                  <span className="flex items-center justify-between gap-3">
                    <span className="text-sm font-medium">{action.label}</span>
                    {action.dangerous ? <span className="shrink-0 text-xs font-semibold uppercase text-destructive">{ADMIN_DASHBOARD_TEXTS.clubActionDangerous}</span> : null}
                  </span>
                  <span className="mt-1 block text-xs text-muted-foreground">{action.description}</span>
                </button>
              </li>
            ))}
          </ul>
        ) : null}

        {selected ? (
          <div className="space-y-3">
            <p className="text-sm font-medium">{selected.label}</p>
            <p className="text-xs text-muted-foreground">{selected.description}</p>

            {schema.map((arg) =>
              isArgVisible(arg, argValues) ? (
                <div key={arg.key} className="space-y-1">
                  <label className="block text-xs text-muted-foreground" htmlFor={`action-arg-${arg.key}`}>
                    {arg.label}
                  </label>
                  <select
                    id={`action-arg-${arg.key}`}
                    value={argValues[arg.key] ?? ""}
                    onChange={(event) => setArgValues((previous) => ({ ...previous, [arg.key]: event.target.value }))}
                    className="h-10 w-full scheme-dark rounded-md border border-border bg-transparent px-3 text-sm outline-none focus:ring-2 focus:ring-primary/40"
                  >
                    <option value="">{ADMIN_DASHBOARD_TEXTS.clubActionArgPlaceholder}</option>
                    {arg.choices.map((choice) => (
                      <option key={choice.value} value={choice.value}>
                        {choice.label}
                      </option>
                    ))}
                  </select>
                </div>
              ) : null,
            )}

            {selected.dangerous ? (
              <div className="space-y-2">
                <label className="block text-xs text-muted-foreground" htmlFor="confirm-club-name">
                  {ADMIN_DASHBOARD_TEXTS.clubActionDangerConfirmLabel(club.name)}
                </label>
                <input
                  id="confirm-club-name"
                  type="text"
                  value={confirmName}
                  onChange={(event) => setConfirmName(event.target.value)}
                  className="h-10 w-full rounded-md border border-border bg-transparent px-3 text-sm outline-none focus:ring-2 focus:ring-destructive/40"
                  autoComplete="off"
                />
              </div>
            ) : null}
          </div>
        ) : null}
      </div>

      <ConfirmDialog
        open={confirmingOffer}
        title={selected ? ADMIN_DASHBOARD_TEXTS.clubActionConfirmTitle(selected.label) : ADMIN_DASHBOARD_TEXTS.clubActionConfirmFallbackTitle}
        description={
          <span className="block space-y-1">
            <span className="block">{ADMIN_DASHBOARD_TEXTS.clubActionConfirmFor(club.name)}</span>
            {chosenSummary.map((line) => (
              <span key={line.label} className="block font-medium">
                {line.label} : {line.value}
              </span>
            ))}
          </span>
        }
        confirmLabel={ADMIN_DASHBOARD_TEXTS.clubActionConfirmAttribution}
        destructive={false}
        confirmDisabled={run.isPending}
        onConfirm={doRun}
        onCancel={() => setConfirmingOffer(false)}
      />
    </Modal>
  );
}

function Metric({ label, value, detail }: { label: string; value: number | string; detail: string }) {
  return <article className="bg-console-surface p-5"><p className="text-xs text-console-muted">{label}</p><p className="mt-3 text-2xl font-semibold tabular-nums text-white">{typeof value === "number" ? integerFormatter.format(value) : value}</p><p className="mt-1 text-xs text-console-text-faint">{detail}</p></article>;
}

function SmallMetric({ label, value, tone }: { label: string; value: number | string; tone?: "danger" }) {
  return <div><dt className="text-xs text-console-muted">{label}</dt><dd className={cn("mt-1 text-lg font-semibold tabular-nums text-white", tone === "danger" && "text-console-warning")}>{typeof value === "number" ? integerFormatter.format(value) : value}</dd></div>;
}

function StatusChip({ status }: { status: "up" | "down" | "unknown" | "degraded" }) {
  const labels = { up: ADMIN_DASHBOARD_TEXTS.statusUp, down: ADMIN_DASHBOARD_TEXTS.statusDown, unknown: ADMIN_DASHBOARD_TEXTS.statusUnknown, degraded: ADMIN_DASHBOARD_TEXTS.statusDegraded };
  const healthy = status === "up";
  const Icon = healthy ? CheckCircle2 : AlertTriangle;
  return <span className={cn("inline-flex items-center gap-1.5 text-xs font-medium", healthy ? "text-console-success" : status === "unknown" ? "text-console-text-dim" : "text-console-warning")}><Icon className="size-3.5" aria-hidden="true" />{labels[status]}</span>;
}

function PanelLoading({ label }: { label: string }) {
  return <div className="flex min-h-40 items-center justify-center rounded-xl border border-white/10 bg-white/[0.03]" role="status"><Spinner className="text-console-accent" /><span className="sr-only">{label}</span></div>;
}

function PanelError({ label, retry }: { label: string; retry: () => void }) {
  return <div className="flex flex-col items-start gap-4 rounded-xl border border-console-warning/20 bg-console-warning/[0.05] p-5" role="alert"><p className="text-sm text-console-warning-bright">{label}</p><Button type="button" size="sm" variant="outline" className="border-console-warning/20 text-console-warning-bright hover:bg-console-warning/10" onClick={retry}>{ADMIN_DASHBOARD_TEXTS.panelRetry}</Button></div>;
}

interface DataSectionProps<T> {
  data: T | undefined;
  loading: boolean;
  error: boolean;
  retry: () => void;
}

function formatRate(value: number): string {
  return new Intl.NumberFormat("fr-FR", { style: "percent", maximumFractionDigits: 1 }).format(value);
}

function formatDuration(milliseconds: number | null): string {
  if (milliseconds === null) return "—";
  if (milliseconds < 1000) return `${milliseconds} ms`;
  return `${new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 1 }).format(milliseconds / 1000)} s`;
}

function formatDate(value: string): string {
  return dateFormatter.format(new Date(value));
}

function formatShortDate(value: string | undefined): string {
  return value ? shortDateFormatter.format(new Date(value)) : "";
}

function formatDateTime(value: string): string {
  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

function nullableInteger(value: number | null): string {
  return value === null ? "—" : integerFormatter.format(value);
}

function formatHeartbeat(worker: AdminHealthResponse["services"]["worker"]): ReactNode {
  if (worker.ageSeconds !== null) return ADMIN_DASHBOARD_TEXTS.heartbeatAgo(worker.ageSeconds);
  if (worker.lastHeartbeatAt) return ADMIN_DASHBOARD_TEXTS.heartbeatAt(formatDateTime(worker.lastHeartbeatAt));
  return ADMIN_DASHBOARD_TEXTS.heartbeatNone;
}

function formatCadence(cadence: AdminJob["cadence"]): string {
  return {
    every_10_minutes: ADMIN_DASHBOARD_TEXTS.cadenceEvery10Minutes,
    daily: ADMIN_DASHBOARD_TEXTS.cadenceDaily,
    weekly: ADMIN_DASHBOARD_TEXTS.cadenceWeekly,
    quarterly: ADMIN_DASHBOARD_TEXTS.cadenceQuarterly,
  }[cadence];
}

function formatJobSource(source: NonNullable<AdminJob["latestRun"]>["source"]): string {
  return { scheduled: ADMIN_DASHBOARD_TEXTS.jobSourceScheduled, cli: ADMIN_DASHBOARD_TEXTS.jobSourceCli, superadmin: ADMIN_DASHBOARD_TEXTS.jobSourceSuperadmin }[source];
}
