import {
  Activity,
  AlertTriangle,
  Building2,
  CheckCircle2,
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
import { useSearchParams } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyBlock, EmptyHint } from "@/shared/components/ui/empty-hint";
import { Modal } from "@/shared/components/ui/modal";
import { Spinner } from "@/shared/components/ui/spinner";
import { cn } from "@/shared/lib/utils";
import { toast } from "@/shared/stores/toastStore";

import type { AdminAction, AdminActionArgumentSpec, AdminClub, AdminFreshnessResponse, AdminHealthResponse, AdminJob, AdminJobStatus, AdminJobsResponse, AdminOverviewResponse } from "./api";
import { useAdminActions, useAdminClubs, useAdminFreshness, useAdminHealth, useAdminJobs, useAdminOverview, useRunAdminClubAction, useRunAdminJob } from "./queries";
import { CapacitySection } from "./sections/CapacitySection";
import { ClubRequestsSection } from "./sections/ClubRequestsSection";
import { ContainersSection } from "./sections/ContainersSection";
import { ExternalDepsSection } from "./sections/ExternalDepsSection";
import { FeedbackSection } from "./sections/FeedbackSection";
import { ReleaseNotesSection } from "./sections/ReleaseNotesSection";
import { AuditSubtab } from "./Journaux/AuditSubtab";
import { MessengerFailedSubtab } from "./Journaux/MessengerFailedSubtab";
import { SystemErrorsSubtab } from "./Journaux/SystemErrorsSubtab";
import { ADMIN_TABS, DEFAULT_SUBTAB, STORAGE_KEY, resolveActiveSubTab, resolveActiveTab } from "./tabs/tabsConfig";
import { TabPanel, Tabs } from "@/shared/components/ui/tabs";

const CLUBS_PER_PAGE = 25;

const integerFormatter = new Intl.NumberFormat("fr-FR");
const dateFormatter = new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "short", year: "numeric" });
const shortDateFormatter = new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "short" });

export function AdminDashboardPage() {
  const [page, setPage] = useState(1);
  const [query, setQuery] = useState("");
  const [queryDraft, setQueryDraft] = useState("");
  const overview = useAdminOverview();
  const health = useAdminHealth();
  const jobs = useAdminJobs();
  const freshness = useAdminFreshness();
  const clubs = useAdminClubs(page, CLUBS_PER_PAGE, query);
  const refreshing = overview.isFetching || health.isFetching || jobs.isFetching || clubs.isFetching || freshness.isFetching;

  const [searchParams, setSearchParams] = useSearchParams();
  const activeTab = resolveActiveTab(searchParams.get("tab"), localStorage.getItem(STORAGE_KEY));
  const activeSubTab = resolveActiveSubTab(activeTab === "journaux" ? searchParams.get("sub") : null);

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
          if (activeTab === "journaux") prev.set("sub", activeSubTab);
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
      if (id === "journaux") prev.set("sub", DEFAULT_SUBTAB);
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
    void Promise.all([overview.refetch(), health.refetch(), jobs.refetch(), clubs.refetch(), freshness.refetch()]);
  }

  const journauxSubTabs =
    ADMIN_TABS.find((t) => t.id === "journaux")?.subTabs?.map((s) => ({ id: s.id, label: s.label })) ?? [];

  return (
    <div className="space-y-8">
      <section className="flex flex-col justify-between gap-5 border-b border-white/10 pb-8 lg:flex-row lg:items-end">
        <div className="max-w-3xl">
          <p className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.2em] text-console-accent">
            <Activity className="size-4" aria-hidden="true" /> Supervision temps réel
          </p>
          <h1 className="text-3xl font-semibold tracking-tight text-white sm:text-4xl">État de la plateforme</h1>
          <p className="mt-3 max-w-2xl text-sm leading-6 text-console-text-dim">
            Santé technique, activité du parc et comportement du solveur réunis dans une vue en lecture seule.
          </p>
        </div>
        <Button
          type="button"
          variant="outline"
          className="self-start border-white/15 text-console-text-bright hover:bg-white/10 lg:self-auto"
          disabled={refreshing}
          onClick={refreshAll}
        >
          {refreshing ? <Spinner className="size-4 text-console-text" /> : <RefreshCw aria-hidden="true" />}
          Actualiser
        </Button>
      </section>

      <Tabs tabs={ADMIN_TABS} activeTab={activeTab} onTabChange={handleTabChange} ariaLabel="Sections admin" idPrefix="admin" variant="console" />

      <TabPanel variant="console" tabId="vue-densemble" idPrefix="admin" active={activeTab === "vue-densemble"} className="space-y-8 pt-6">
        <OverviewSection data={overview.data} loading={overview.isPending} error={overview.isError} retry={() => void overview.refetch()} />
        <UsageSection data={overview.data} loading={overview.isPending} error={overview.isError} retry={() => void overview.refetch()} />
      </TabPanel>

      <TabPanel variant="console" tabId="infrastructure" idPrefix="admin" active={activeTab === "infrastructure"} className="space-y-8 pt-6">
        <HealthSection data={health.data} loading={health.isPending} error={health.isError} retry={() => void health.refetch()} />
        <ContainersSection />
        <ExternalDepsSection />
        <CapacitySection />
      </TabPanel>

      <TabPanel variant="console" tabId="jobs" idPrefix="admin" active={activeTab === "jobs"} className="space-y-8 pt-6">
        <JobsSection data={jobs.data} loading={jobs.isPending} error={jobs.isError} retry={() => void jobs.refetch()} />
      </TabPanel>

      <TabPanel variant="console" tabId="referentiels" idPrefix="admin" active={activeTab === "referentiels"} className="space-y-8 pt-6">
        <FreshnessSection data={freshness.data} loading={freshness.isPending} error={freshness.isError} retry={() => void freshness.refetch()} />
        <ReleaseNotesSection />
      </TabPanel>

      <TabPanel variant="console" tabId="clubs" idPrefix="admin" active={activeTab === "clubs"} className="space-y-8 pt-6">
        {/* P3-4 PR B : les arbitrages (créations + adhésions) vivent avec le parc. */}
        <ClubRequestsSection />
        <section aria-labelledby="clubs-heading" className="space-y-4">
          <div className="flex flex-col justify-between gap-4 md:flex-row md:items-end">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">Parc client</p>
              <h2 id="clubs-heading" className="mt-2 text-xl font-semibold text-white">Comptes clubs</h2>
            </div>
            <form className="flex w-full gap-2 md:max-w-md" role="search" onSubmit={submitSearch}>
              <label className="sr-only" htmlFor="club-search">Rechercher un club</label>
              <div className="relative min-w-0 flex-1">
                <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-console-muted" aria-hidden="true" />
                <input
                  id="club-search"
                  type="search"
                  value={queryDraft}
                  maxLength={100}
                  onChange={(event) => setQueryDraft(event.target.value)}
                  placeholder="Nom, slug ou code FFBB"
                  className="h-10 w-full rounded-md border border-white/15 bg-white/[0.04] pl-10 pr-3 text-sm text-white outline-none placeholder:text-console-text-faint focus:border-console-accent/70 focus:ring-2 focus:ring-console-accent/20"
                />
              </div>
              <Button type="submit" className="bg-console-accent text-console-surface hover:bg-console-accent-hover">Rechercher</Button>
            </form>
          </div>

          {clubs.isPending ? <PanelLoading label="Chargement des clubs" /> : null}
          {clubs.isError ? <PanelError label="Les comptes clubs sont indisponibles." retry={() => void clubs.refetch()} /> : null}
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
      </TabPanel>

      <TabPanel variant="console" tabId="signalements" idPrefix="admin" active={activeTab === "signalements"} className="space-y-8 pt-6">
        <FeedbackSection />
      </TabPanel>

      <TabPanel variant="console" tabId="journaux" idPrefix="admin" active={activeTab === "journaux"} className="space-y-6 pt-6">
        <Tabs variant="console" tabs={journauxSubTabs} activeTab={activeSubTab} onTabChange={handleSubTabChange} ariaLabel="Journaux" idPrefix="admin-journaux" />
        <TabPanel variant="console" tabId="audit" idPrefix="admin-journaux" active={activeSubTab === "audit"}>
          <AuditSubtab />
        </TabPanel>
        <TabPanel variant="console" tabId="messenger" idPrefix="admin-journaux" active={activeSubTab === "messenger"}>
          <MessengerFailedSubtab />
        </TabPanel>
        <TabPanel variant="console" tabId="errors" idPrefix="admin-journaux" active={activeSubTab === "errors"}>
          <SystemErrorsSubtab />
        </TabPanel>
      </TabPanel>
    </div>
  );
}

function OverviewSection({ data, loading, error, retry }: DataSectionProps<AdminOverviewResponse>) {
  if (loading) return <PanelLoading label="Chargement de l’activité" />;
  if (error || !data) return <PanelError label="Les indicateurs d’activité sont indisponibles." retry={retry} />;

  const metrics = [
    { label: "Clubs", value: data.clubs.total, detail: `${integerFormatter.format(data.clubs.new7d)} nouveaux sur 7 j` },
    { label: "Actifs sur 7 j", value: data.clubs.active7d, detail: `${integerFormatter.format(data.clubs.active30d)} actifs sur 30 j` },
    { label: "Générations", value: data.solver.generations, detail: `Fenêtre de ${data.solver.windowDays} jours` },
    { label: "Taux infaisable", value: formatRate(data.solver.infeasibleRate), detail: `${integerFormatter.format(data.solver.infeasible)} générations` },
  ];

  return (
    <section aria-labelledby="activity-heading" className="space-y-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">Activité globale</p>
        <h2 id="activity-heading" className="mt-2 text-xl font-semibold text-white">Parc et solveur</h2>
      </div>
      <div className="grid gap-px overflow-hidden rounded-xl border border-white/10 bg-white/10 sm:grid-cols-2 xl:grid-cols-4">
        {metrics.map((metric) => <Metric key={metric.label} {...metric} />)}
      </div>
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1.7fr)_minmax(18rem,1fr)]">
        <SolverChart solver={data.solver} />
        <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
          <p className="text-sm font-medium text-white">Performance du solveur</p>
          <dl className="mt-5 grid grid-cols-2 gap-x-5 gap-y-6">
            <SmallMetric label="Terminées" value={data.solver.completed} />
            <SmallMetric label="Échecs" value={data.solver.failed} tone={data.solver.failed > 0 ? "danger" : undefined} />
            <SmallMetric label="Durée médiane" value={formatDuration(data.solver.p50WallTimeMs)} />
            <SmallMetric label="P95" value={formatDuration(data.solver.p95WallTimeMs)} />
          </dl>
          <div className="mt-6 border-t border-white/10 pt-4 text-xs text-console-muted">
            {integerFormatter.format(data.clubs.unsubscribed)} compte{data.clubs.unsubscribed > 1 ? "s" : ""} désabonné{data.clubs.unsubscribed > 1 ? "s" : ""}
          </div>
        </article>
      </div>
    </section>
  );
}

/** Libellés FR des types de plan (ADR-0002) + le bucket télémétrie UNKNOWN (historique pré-dimension / plan disparu à la capture). */
const PLAN_TYPE_LABELS: Record<string, string> = { SEASON: "Saison", CLOSURE: "Fermeture", HOLIDAY: "Vacances", UNKNOWN: "Inconnu (historique)" };

const planTypeLabel = (type: string): string => PLAN_TYPE_LABELS[type] ?? type;

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
  if (loading) return <PanelLoading label="Chargement de l’usage" />;
  // `usage` absent = backend antérieur au lot (rollback / décalage de déploiement) :
  // indisponibilité affichée, jamais un crash de rendu.
  if (error || !data?.usage) return <PanelError label="Les statistiques d’usage sont indisponibles." retry={retry} />;

  const { usage } = data;

  return (
    <section aria-labelledby="usage-heading" className="space-y-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">Usage produit</p>
        <h2 id="usage-heading" className="mt-2 text-xl font-semibold text-white">Plans, clôtures et tailles de clubs</h2>
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
          <p className="text-sm font-medium text-white">Plans par type</p>
          <p className="mt-1 text-xs text-console-muted">Parc actuel — un reset ou un effacement retire ses plans</p>
          <dl className="mt-5 space-y-4">
            {usage.plansByType.length === 0 ? <EmptyHint variant="console">Aucun plan.</EmptyHint> : null}
            {usage.plansByType.map((row) => (
              <div key={row.type} className="flex items-baseline justify-between gap-3">
                <dt className="text-sm text-console-text-dim">{planTypeLabel(row.type)}</dt>
                <dd className="text-sm text-white">
                  {integerFormatter.format(row.total)}
                  <span className="ml-2 text-xs text-console-muted">dont {integerFormatter.format(row.validated)} validé{row.validated > 1 ? "s" : ""}</span>
                </dd>
              </div>
            ))}
          </dl>
        </article>
        <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
          <p className="text-sm font-medium text-white">Temps de clôture</p>
          <p className="mt-1 text-xs text-console-muted">Création du plan → première validation</p>
          <dl className="mt-5 grid grid-cols-2 gap-x-5 gap-y-6">
            <SmallMetric label="Saison · médiane" value={formatMinutes(usage.timeToFirstValidation.season.p50Minutes)} />
            <SmallMetric label="Saison · P95" value={formatMinutes(usage.timeToFirstValidation.season.p95Minutes)} />
            <SmallMetric label="Périodes · médiane" value={formatMinutes(usage.timeToFirstValidation.period.p50Minutes)} />
            <SmallMetric label="Périodes · P95" value={formatMinutes(usage.timeToFirstValidation.period.p95Minutes)} />
          </dl>
          <div className="mt-6 border-t border-white/10 pt-4 text-xs text-console-muted">
            {integerFormatter.format(usage.timeToFirstValidation.season.count)} saison{usage.timeToFirstValidation.season.count > 1 ? "s" : ""} · {integerFormatter.format(usage.timeToFirstValidation.period.count)} période{usage.timeToFirstValidation.period.count > 1 ? "s" : ""} clôturée{usage.timeToFirstValidation.period.count > 1 ? "s" : ""}
          </div>
        </article>
        <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
          <p className="text-sm font-medium text-white">Solveur par type · 30 j</p>
          <dl className="mt-5 space-y-4">
            {usage.solverByPlanType.length === 0 ? <EmptyHint variant="console">Aucune génération sur la fenêtre.</EmptyHint> : null}
            {usage.solverByPlanType.map((row) => (
              <div key={row.planType} className="flex items-baseline justify-between gap-3">
                <dt className="text-sm text-console-text-dim">{planTypeLabel(row.planType)}</dt>
                <dd className="text-sm text-white">
                  {integerFormatter.format(row.generations)}
                  <span className="ml-2 text-xs text-console-muted">méd. {formatDuration(row.p50WallTimeMs)} · P95 {formatDuration(row.p95WallTimeMs)}</span>
                </dd>
              </div>
            ))}
          </dl>
        </article>
      </div>
      <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
        <p className="text-sm font-medium text-white">Tailles de clubs</p>
        <p className="mt-1 text-xs text-console-muted">Équipes actives de la saison courante, parc non désabonné</p>
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[24rem] text-left text-sm">
            <thead>
              <tr className="text-xs uppercase tracking-wide text-console-muted">
                <th className="py-2 pr-4 font-medium">Équipes</th>
                <th className="py-2 pr-4 font-medium">Clubs</th>
                <th className="py-2 font-medium">Gymnases (médiane)</th>
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
          <p className="text-sm font-medium text-white">Volume quotidien</p>
          <p className="mt-1 text-xs text-console-muted">Générations sur les {solver.windowDays} derniers jours</p>
        </div>
        <span className="flex items-center gap-2 text-xs text-console-muted"><span className="size-2 bg-console-accent" /> Générations</span>
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
            {solver.daily.map((day) => `${day.date} : ${day.generations} générations`).join(" ; ")}
          </figcaption>
        </>
      ) : <EmptyHint variant="console" className="mt-10">Aucune génération sur cette période.</EmptyHint>}
    </figure>
  );
}

function HealthSection({ data, loading, error, retry }: DataSectionProps<AdminHealthResponse>) {
  if (loading) return <PanelLoading label="Chargement de la santé technique" />;
  if (error || !data) return <PanelError label="La santé technique est indisponible." retry={retry} />;

  const services = [
    { key: "database", label: "Base de données", icon: Database, ...data.services.database },
    { key: "redis", label: "Redis", icon: Zap, ...data.services.redis },
    { key: "engine", label: "Moteur", icon: Cpu, ...data.services.engine },
    { key: "mercure", label: "Mercure", icon: Radio, ...data.services.mercure },
  ];

  return (
    <section aria-labelledby="health-heading" className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">Infrastructure</p>
          <h2 id="health-heading" className="mt-2 text-xl font-semibold text-white">Santé technique</h2>
        </div>
        <p className="text-xs text-console-muted">Vérifié le {formatDateTime(data.checkedAt)}</p>
      </div>
      <div className="grid gap-4 xl:grid-cols-[minmax(0,1.5fr)_minmax(20rem,1fr)]">
        <article className="overflow-hidden rounded-xl border border-white/10 bg-white/[0.04]">
          <div className="flex items-center justify-between border-b border-white/10 px-5 py-4">
            <div className="flex items-center gap-3"><Server className="size-4 text-console-muted" aria-hidden="true" /><span className="text-sm font-medium text-white">Dépendances</span></div>
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
            <div className="flex items-center gap-3"><Workflow className="size-4 text-console-muted" aria-hidden="true" /><span className="text-sm font-medium text-white">Traitements asynchrones</span></div>
            <StatusChip status={data.messenger.status} />
          </div>
          <dl className="mt-5 grid grid-cols-3 gap-3 border-b border-white/10 pb-5">
            <SmallMetric label="En attente" value={nullableInteger(data.messenger.backlog)} />
            <SmallMetric label="Échecs" value={nullableInteger(data.messenger.failed)} tone={(data.messenger.failed ?? 0) > 0 ? "danger" : undefined} />
            <SmallMetric label="Retries" value={nullableInteger(data.messenger.retriesToday)} />
          </dl>
          <div className="mt-5 flex items-start justify-between gap-4">
            <div><p className="text-xs text-console-muted">Worker</p><p className="mt-1 text-sm text-console-text">{formatHeartbeat(data.services.worker)}</p></div>
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
      onSuccess: () => toast.success(`${job.label} terminé.`),
      onError: () => toast.error(`Impossible d’exécuter « ${job.label} ».`),
    });
  }

  if (loading) return <PanelLoading label="Chargement des jobs opérationnels" />;
  if (error || !data) return <PanelError label="Les jobs opérationnels sont indisponibles." retry={retry} />;

  return (
    <section aria-labelledby="jobs-heading" className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">Exploitation</p>
          <h2 id="jobs-heading" className="mt-2 text-xl font-semibold text-white">Jobs opérationnels</h2>
        </div>
        <p className="text-xs text-console-muted">Cadence, prochain passage et dernière exécution connue</p>
      </div>
      {data.items.length === 0 ? (
        <EmptyBlock variant="console">Aucun job opérationnel configuré.</EmptyBlock>
      ) : (
        <div className="overflow-hidden rounded-xl border border-white/10 bg-white/[0.03]">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[1180px] text-left text-sm">
              <caption className="sr-only">État des jobs opérationnels allowlistés</caption>
              <thead className="border-b border-white/10 bg-white/[0.03] text-xs uppercase tracking-wider text-console-muted">
                <tr><th className="px-5 py-4 font-medium">Job</th><th className="px-4 py-4 font-medium">Cadence</th><th className="px-4 py-4 font-medium">Prochain passage</th><th className="px-4 py-4 font-medium">Dernière exécution</th><th className="px-4 py-4 font-medium">Durée</th><th className="px-4 py-4 font-medium">Résultat</th><th className="px-4 py-4 font-medium">Action</th></tr>
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
        title="Relancer cet import ?"
        description={jobToRun ? `« ${jobToRun.label} » va interroger la source officielle et mettre à jour la référence globale. L’opération est idempotente.` : undefined}
        confirmLabel="Relancer l’import"
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
      <td className="px-4 py-5">{job.latestRun ? <><p>{formatDateTime(job.latestRun.startedAt)}</p><p className="mt-1 text-xs text-console-text-faint">{formatJobSource(job.latestRun.source)}</p></> : <span className="text-console-muted">Jamais exécuté</span>}</td>
      <td className="px-4 py-5 tabular-nums">{job.latestRun ? formatDuration(job.latestRun.durationMs) : "—"}</td>
      <td className="px-4 py-5"><JobStatus status={job.latestRun?.status ?? null} />{job.latestRun?.exitCode !== null && job.latestRun?.exitCode !== undefined ? <p className="mt-1 text-xs text-console-text-faint">Code {job.latestRun.exitCode}</p> : null}</td>
      <td className="px-4 py-5">{job.manualTriggerAllowed ? <Button type="button" size="sm" variant="outline" className="border-white/15 text-console-text-bright hover:bg-white/10" disabled={running} onClick={onRun}>{running ? <Spinner className="size-3.5" /> : <RotateCw className="size-3.5" aria-hidden="true" />} {running ? "Exécution…" : "Relancer"}</Button> : <span className="text-xs text-console-text-faint">Supervision seule</span>}</td>
    </tr>
  );
}

function NextRun({ value }: { value: string }) {
  return <p className="text-console-text">{formatDateTime(value)}</p>;
}

function JobStatus({ status }: { status: AdminJobStatus | null }) {
  const labels: Record<AdminJobStatus, string> = { running: "En cours", succeeded: "Réussi", failed: "Échec", interrupted: "Interrompu" };
  if (status === null) return <span className="text-xs font-medium text-console-muted">Sans historique</span>;

  const successful = status === "succeeded";
  const running = status === "running";
  const Icon = successful ? CheckCircle2 : status === "failed" ? AlertTriangle : History;
  return <span className={cn("inline-flex items-center gap-1.5 text-xs font-medium", successful ? "text-console-success" : running ? "text-console-accent" : status === "failed" ? "text-console-warning" : "text-console-text-dim")}><Icon className="size-3.5" aria-hidden="true" />{labels[status]}</span>;
}

/**
 * Data-freshness board : « mes données de référence sont-elles à jour ? ». Rend
 * VISIBLE l'import automatique mort en silence — un référentiel jamais importé ou
 * trop vieux s'affiche « Périmé » (le job d'alerte emaile en parallèle).
 */
function FreshnessSection({ data, loading, error, retry }: DataSectionProps<AdminFreshnessResponse>) {
  if (loading) return <PanelLoading label="Chargement de la fraîcheur des données" />;
  if (error || !data) return <PanelError label="La fraîcheur des données est indisponible." retry={retry} />;

  return (
    <section aria-labelledby="freshness-heading" className="space-y-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">Données de référence</p>
        <h2 id="freshness-heading" className="mt-2 text-xl font-semibold text-white">Fraîcheur des données</h2>
      </div>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {data.items.map((item) => (
          <article key={item.key} className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
            <div className="flex items-start justify-between gap-3">
              <p className="text-sm font-medium text-white">{item.label}</p>
              <span className={cn("shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold", item.stale ? "bg-console-warning-tint/15 text-console-warning" : "bg-console-success-tint/15 text-console-success")}>
                {item.stale ? "Périmé" : "À jour"}
              </span>
            </div>
            <p className="mt-3 text-xs text-console-muted">
              {item.lastUpdatedAt ? `Dernière mise à jour : ${formatDate(item.lastUpdatedAt)}` : "Jamais importé"}
            </p>
            <p className="mt-1 text-xs text-console-text-faint">Seuil : {integerFormatter.format(item.staleAfterDays)} jours</p>
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
    return <EmptyBlock variant="console">{query ? `Aucun club ne correspond à « ${query} ».` : "Aucun club à afficher."}</EmptyBlock>;
  }

  return (
    <div className={cn("overflow-hidden rounded-xl border border-white/10 bg-white/[0.03]", loading && "opacity-70")}>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[1120px] text-left text-sm">
          <caption className="sr-only">Liste des comptes clubs et de leurs métriques</caption>
          <thead className="border-b border-white/10 bg-white/[0.03] text-xs uppercase tracking-wider text-console-muted">
            <tr><th className="px-5 py-4 font-medium">Club</th><th className="px-4 py-4 font-medium">Activité</th><th className="px-4 py-4 font-medium">Offre</th><th className="px-4 py-4 font-medium">Saison / volume</th><th className="px-4 py-4 font-medium">Solveur · 30 j</th><th className="px-4 py-4 font-medium">Support</th></tr>
          </thead>
          <tbody className="divide-y divide-white/10">
            {clubs.map((club) => <ClubRow key={club.id} club={club} onActions={() => setActionClub(club)} />)}
          </tbody>
        </table>
      </div>
      {actionClub ? <ClubActionsDialog club={actionClub} onClose={() => setActionClub(null)} /> : null}
      <div className="flex items-center justify-between gap-4 border-t border-white/10 px-5 py-4">
        <p className="text-xs text-console-muted">{integerFormatter.format(total)} compte{total > 1 ? "s" : ""} · page {page} sur {Math.max(pages, 1)}</p>
        <div className="flex gap-2">
          <Button type="button" size="sm" variant="ghost" className="text-console-text hover:bg-white/10" aria-label="Page précédente" disabled={page <= 1 || loading} onClick={() => onPageChange(page - 1)}><ChevronLeft aria-hidden="true" /></Button>
          <Button type="button" size="sm" variant="ghost" className="text-console-text hover:bg-white/10" aria-label="Page suivante" disabled={page >= pages || loading} onClick={() => onPageChange(page + 1)}><ChevronRight aria-hidden="true" /></Button>
        </div>
      </div>
    </div>
  );
}

function ClubRow({ club, onActions }: { club: AdminClub; onActions: () => void }) {
  return (
    <tr className="align-top text-console-text hover:bg-white/[0.025]">
      <td className="px-5 py-5"><div className="flex items-start gap-3"><div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md bg-white/[0.06] text-console-muted"><Building2 className="size-4" aria-hidden="true" /></div><div><p className="font-medium text-white">{club.name}</p><p className="mt-1 text-xs text-console-text-faint">{club.ffbbClubCode ?? club.slug}</p>{club.isDemo ? <span className="mt-2 mr-2 inline-block rounded bg-console-demo-surface/20 px-1.5 py-0.5 text-xs font-medium text-console-demo">Démo</span> : null}{club.unsubscribed ? <span className="mt-2 inline-block text-xs font-medium text-console-warning">Désabonné</span> : null}</div></div></td>
      <td className="px-4 py-5"><p>{club.lastActivityAt ? formatDate(club.lastActivityAt) : "Jamais"}</p><p className="mt-1 text-xs text-console-text-faint">Créé le {formatDate(club.createdAt)}</p></td>
      <td className="px-4 py-5"><p className="text-white">{club.effectivePlan.name}</p>{club.plan && club.plan.code !== club.effectivePlan.code ? <p className="mt-1 text-xs text-console-warning">{club.plan.name} posée — saison non réglée</p> : null}<p className="mt-1 text-xs text-console-text-faint">{club.generationCountSeason} génération{club.generationCountSeason > 1 ? "s" : ""}{club.billingCycle ? ` · ${club.billingCycle}` : ""}</p></td>
      <td className="px-4 py-5"><p>{club.currentSeason?.name ?? "Aucune saison"}</p><p className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-console-text-faint"><span className="flex items-center gap-1"><Users className="size-3" aria-hidden="true" />{club.volumes.teams} équipes · {club.volumes.coaches} coachs</span><span className="flex items-center gap-1"><MapPin className="size-3" aria-hidden="true" />{club.volumes.venues} salles</span><span>{club.volumes.constraints} contraintes</span></p></td>
      <td className="px-4 py-5"><p><span className="font-medium text-white">{club.solver.generations}</span> générations · {formatRate(club.solver.infeasibleRate)} inf.</p><p className="mt-1 text-xs text-console-text-faint">P50 {formatDuration(club.solver.p50WallTimeMs)} · P95 {formatDuration(club.solver.p95WallTimeMs)}</p>{club.solver.latestStatus ? <p className="mt-2 text-xs text-console-muted">Dernière : {club.solver.latestStatus}</p> : null}</td>
      <td className="px-4 py-5">
        <Button type="button" size="sm" variant="outline" className="border-white/15 text-console-text-bright hover:bg-white/10" onClick={onActions}>
          Actions
        </Button>
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
          toast.success(`${action.label} — terminé pour ${club.name}.`);
          onClose();
        },
        onError: () => toast.error(`Impossible d’exécuter « ${action.label} » sur ${club.name}.`),
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
      label={`Actions support — ${club.name}`}
      title={`Actions support — ${club.name}`}
      onClose={onClose}
      size="xl"
      // Pied ÉPINGLÉ seulement quand une action est SÉLECTIONNÉE — le catalogue (selected===null)
      // défile sans pied, ses entrées sont elles-mêmes les cibles cliquables.
      footer={
        selected ? (
          <>
            <Button type="button" variant="ghost" onClick={reset} disabled={run.isPending}>
              Retour
            </Button>
            <Button type="button" variant={selected.dangerous ? "destructive" : "default"} disabled={primaryBlocked} onClick={onPrimary}>
              {run.isPending ? <Spinner className="size-4" /> : null}
              Exécuter
            </Button>
          </>
        ) : undefined
      }
    >
      <div className="mt-4 space-y-4">
        {actions.isPending ? <p className="text-sm text-muted-foreground">Chargement du catalogue…</p> : null}
        {actions.isError ? <p className="text-sm text-destructive">Catalogue d’actions indisponible.</p> : null}

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
                    {action.dangerous ? <span className="shrink-0 text-xs font-semibold uppercase text-destructive">Destructif</span> : null}
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
                    className="h-10 w-full rounded-md border border-border bg-transparent px-3 text-sm outline-none focus:ring-2 focus:ring-primary/40"
                  >
                    <option value="">Choisir…</option>
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
                  Action destructive — tapez le nom exact du club (« {club.name} ») pour confirmer :
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
        title={selected ? `Confirmer : ${selected.label}` : "Confirmer"}
        description={
          <span className="block space-y-1">
            <span className="block">Pour {club.name} :</span>
            {chosenSummary.map((line) => (
              <span key={line.label} className="block font-medium">
                {line.label} : {line.value}
              </span>
            ))}
          </span>
        }
        confirmLabel="Confirmer l’attribution"
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
  const labels = { up: "Opérationnel", down: "Indisponible", unknown: "Inconnu", degraded: "Dégradé" };
  const healthy = status === "up";
  const Icon = healthy ? CheckCircle2 : AlertTriangle;
  return <span className={cn("inline-flex items-center gap-1.5 text-xs font-medium", healthy ? "text-console-success" : status === "unknown" ? "text-console-text-dim" : "text-console-warning")}><Icon className="size-3.5" aria-hidden="true" />{labels[status]}</span>;
}

function PanelLoading({ label }: { label: string }) {
  return <div className="flex min-h-40 items-center justify-center rounded-xl border border-white/10 bg-white/[0.03]" role="status"><Spinner className="text-console-accent" /><span className="sr-only">{label}</span></div>;
}

function PanelError({ label, retry }: { label: string; retry: () => void }) {
  return <div className="flex flex-col items-start gap-4 rounded-xl border border-console-warning/20 bg-console-warning/[0.05] p-5" role="alert"><p className="text-sm text-console-warning-bright">{label}</p><Button type="button" size="sm" variant="outline" className="border-console-warning/20 text-console-warning-bright hover:bg-console-warning/10" onClick={retry}>Réessayer</Button></div>;
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
  if (worker.ageSeconds !== null) return `Heartbeat il y a ${worker.ageSeconds} s`;
  if (worker.lastHeartbeatAt) return `Heartbeat ${formatDateTime(worker.lastHeartbeatAt)}`;
  return "Aucun heartbeat reçu";
}

function formatCadence(cadence: AdminJob["cadence"]): string {
  return {
    every_10_minutes: "Toutes les 10 minutes",
    daily: "Quotidien",
    quarterly: "Trimestriel",
  }[cadence];
}

function formatJobSource(source: NonNullable<AdminJob["latestRun"]>["source"]): string {
  return { scheduled: "Planifié", cli: "CLI", superadmin: "Superadmin" }[source];
}
