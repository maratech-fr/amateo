import { Spinner } from "@/shared/components/ui/spinner";
import { cn } from "@/shared/lib/utils";

import type { AdminCapacityResponse } from "../api";
import { useAdminCapacity } from "../queries";

const integerFormatter = new Intl.NumberFormat("fr-FR");
const dateFormatter = new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "short", year: "numeric" });

/** Limite mémoire du worker engine en prod (libellé statique — le repère, pas une mesure). */
const PROD_MEMORY_LIMIT_MIB = 512;

/** Libellés des tranches de taille (nb_teams × nb_venues), alignées aux tiers moteur. */
const BUCKET_LABELS: Record<string, string> = { small: "≤ 50", medium: "51–200", large: "> 200" };
const bucketLabel = (bucket: string): string => BUCKET_LABELS[bucket] ?? bucket;

/**
 * Section « Capacité » (P5-10) : ce que la télémétrie `solver_metrics` dit de la
 * tenue du système sous charge sur 90 jours — volume, attente en file, durées par
 * taille de problème, mémoire de l'engine, issues. Alimentée par `useAdminCapacity()`
 * (refetch 5 min). Lecture seule. Toute valeur nulle (historique pré-2.6, échec) → « — ».
 */
export function CapacitySection() {
  const capacity = useAdminCapacity();

  if (capacity.isPending) return <PanelLoading label="Chargement de la capacité" />;
  if (capacity.isError || !capacity.data) {
    return <PanelError label="Les indicateurs de capacité sont indisponibles." retry={() => void capacity.refetch()} />;
  }

  const data = capacity.data;

  return (
    <section aria-labelledby="capacity-heading" className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">Charge &amp; capacité</p>
          <h2 id="capacity-heading" className="mt-2 text-xl font-semibold text-white">
            Capacité — {integerFormatter.format(data.windowDays)} derniers jours
          </h2>
        </div>
        <div className="text-right">
          <p className="text-2xl font-semibold tabular-nums text-white">{integerFormatter.format(data.totalSolves)}</p>
          <p className="text-xs text-console-muted">solve{data.totalSolves > 1 ? "s" : ""} sur la fenêtre</p>
        </div>
      </div>

      {data.totalSolves === 0 ? (
        <div className="rounded-xl border border-dashed border-white/15 px-6 py-12 text-center text-sm text-console-muted">
          Aucun solve sur la fenêtre de {integerFormatter.format(data.windowDays)} jours — rien à mesurer pour l’instant.
        </div>
      ) : (
        <>
          <div className="grid gap-4 lg:grid-cols-2">
            <VolumePanel volume={data.volume} />
            <WaitPanel wait={data.wait} />
          </div>
          <SizePanel bySize={data.bySize} />
          <div className="grid gap-4 lg:grid-cols-2">
            <MemoryPanel memory={data.memory} />
            <IssuesPanel issues={data.issues} totalSolves={data.totalSolves} />
          </div>
        </>
      )}
    </section>
  );
}

function VolumePanel({ volume }: { volume: AdminCapacityResponse["volume"] }) {
  return (
    <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
      <p className="text-sm font-medium text-white">Volume</p>
      <dl className="mt-5 grid grid-cols-2 gap-x-5 gap-y-6">
        <SmallMetric label="Solves/jour · médiane" value={nullableInteger(volume.perDay.p50)} />
        <SmallMetric
          label="Solves/jour · pic"
          value={nullableInteger(volume.perDay.max)}
          detail={volume.perDay.maxDate ? `le ${formatDate(volume.perDay.maxDate)}` : undefined}
        />
      </dl>
      <div className="mt-6 border-t border-white/10 pt-4">
        <p className="text-xs font-medium text-console-text-dim">Profil horaire (heure locale)</p>
        {volume.hourly.length === 0 ? (
          <p className="mt-2 text-sm text-console-muted">Aucune heure de mise en file connue.</p>
        ) : (
          <div className="mt-3 overflow-x-auto">
            <table className="w-full min-w-[16rem] text-left text-sm">
              <caption className="sr-only">Nombre de solves par heure de mise en file</caption>
              <thead>
                <tr className="text-xs uppercase tracking-wide text-console-muted">
                  <th className="py-2 pr-4 font-medium">Heure</th>
                  <th className="py-2 font-medium">Solves</th>
                </tr>
              </thead>
              <tbody>
                {volume.hourly.map((row) => (
                  <tr key={row.hour} className="border-t border-white/10 text-console-text">
                    <td className="py-2 pr-4 tabular-nums">{formatHour(row.hour)}</td>
                    <td className="py-2 tabular-nums text-white">{integerFormatter.format(row.solves)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </article>
  );
}

function WaitPanel({ wait }: { wait: AdminCapacityResponse["wait"] }) {
  return (
    <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
      <p className="text-sm font-medium text-white">Attente en file</p>
      <p className="mt-1 text-xs text-console-muted">De la mise en file au démarrage du solve</p>
      <dl className="mt-5 grid grid-cols-3 gap-3">
        <SmallMetric label="Médiane" value={formatDuration(wait.queueP50Ms)} />
        <SmallMetric label="P95" value={formatDuration(wait.queueP95Ms)} />
        <SmallMetric label="Max" value={formatDuration(wait.queueMaxMs)} />
      </dl>
      <div className="mt-6 border-t border-white/10 pt-4 text-xs text-console-muted">
        Côté engine (sémaphore) · P95 <span className="ml-1 font-medium text-console-text">{formatDuration(wait.engineWaitP95Ms)}</span>
      </div>
    </article>
  );
}

function SizePanel({ bySize }: { bySize: AdminCapacityResponse["bySize"] }) {
  return (
    <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
      <p className="text-sm font-medium text-white">Durée par tranche</p>
      <p className="mt-1 text-xs text-console-muted">Tranches de taille = équipes × gymnases (tiers du moteur)</p>
      {bySize.length === 0 ? (
        <p className="mt-4 text-sm text-console-muted">Aucune tentative dimensionnée sur la fenêtre.</p>
      ) : (
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[40rem] text-left text-sm">
            <caption className="sr-only">Durée par tranche de taille de problème</caption>
            <thead>
              <tr className="text-xs uppercase tracking-wide text-console-muted">
                <th className="py-2 pr-4 font-medium">Tranche</th>
                <th className="py-2 pr-4 font-medium">Solves</th>
                <th className="py-2 pr-4 font-medium">P50 durée</th>
                <th className="py-2 pr-4 font-medium">P95 durée</th>
                <th className="py-2 pr-4 font-medium">P95 % budget</th>
                <th className="py-2 font-medium">Preuve non fermée</th>
              </tr>
            </thead>
            <tbody>
              {bySize.map((row) => (
                <tr key={row.bucket} className="border-t border-white/10 text-console-text">
                  <td className="py-2 pr-4 font-medium text-white">{bucketLabel(row.bucket)}</td>
                  <td className="py-2 pr-4 tabular-nums">{integerFormatter.format(row.solves)}</td>
                  <td className="py-2 pr-4 tabular-nums">{formatDuration(row.p50WallTimeMs)}</td>
                  <td className="py-2 pr-4 tabular-nums">{formatDuration(row.p95WallTimeMs)}</td>
                  <td className="py-2 pr-4 tabular-nums">{formatRate(row.p95BudgetRatio)}</td>
                  <td className="py-2 tabular-nums">{formatRate(row.unclosedProofRate)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <p className="mt-4 text-xs text-console-text-faint">
        Taille = celle du club (saison entière) ; un overlay ne résout qu’un sous-ensemble mais porte le compte saison.
      </p>
    </article>
  );
}

function MemoryPanel({ memory }: { memory: AdminCapacityResponse["memory"] }) {
  return (
    <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
      <p className="text-sm font-medium text-white">Mémoire engine</p>
      <p className="mt-1 text-xs text-console-muted">Pic RSS du solve · limite prod {integerFormatter.format(PROD_MEMORY_LIMIT_MIB)} MiB</p>
      <dl className="mt-5 grid grid-cols-3 gap-3">
        <SmallMetric label="Médiane" value={formatMib(memory.peakP50Mb)} />
        <SmallMetric label="P95" value={formatMib(memory.peakP95Mb)} />
        <SmallMetric
          label="Max"
          value={formatMib(memory.peakMaxMb)}
          tone={memory.peakMaxMb !== null && memory.peakMaxMb > PROD_MEMORY_LIMIT_MIB ? "danger" : undefined}
        />
      </dl>
      <div className="mt-6 border-t border-white/10 pt-4 text-xs text-console-muted">
        Baseline du process (avant solve) <span className="ml-1 font-medium text-console-text">{formatMib(memory.lastBaselineMb)}</span>
      </div>
    </article>
  );
}

function IssuesPanel({ issues, totalSolves }: { issues: AdminCapacityResponse["issues"]; totalSolves: number }) {
  return (
    <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
      <p className="text-sm font-medium text-white">Issues</p>
      <dl className="mt-5 space-y-4">
        {issues.byStatus.length === 0 ? <p className="text-sm text-console-muted">Aucune issue enregistrée.</p> : null}
        {issues.byStatus.map((row) => (
          <div key={row.status} className="flex items-baseline justify-between gap-3">
            <dt className="text-sm text-console-text-dim">{row.status}</dt>
            <dd className="text-sm text-white">
              {integerFormatter.format(row.solves)}
              <span className="ml-2 text-xs text-console-muted">{formatRate(totalSolves === 0 ? 0 : row.solves / totalSolves)}</span>
            </dd>
          </div>
        ))}
      </dl>
      <div className="mt-6 border-t border-white/10 pt-4 text-xs text-console-muted">
        Payload engine · P95 <span className="ml-1 font-medium text-console-text">{formatBytes(issues.payloadP95Bytes)}</span>
      </div>
    </article>
  );
}

function SmallMetric({ label, value, detail, tone }: { label: string; value: number | string; detail?: string; tone?: "danger" }) {
  return (
    <div>
      <dt className="text-xs text-console-muted">{label}</dt>
      <dd className={cn("mt-1 text-lg font-semibold tabular-nums text-white", tone === "danger" && "text-console-warning")}>
        {typeof value === "number" ? integerFormatter.format(value) : value}
      </dd>
      {detail ? <p className="mt-0.5 text-xs text-console-text-faint">{detail}</p> : null}
    </div>
  );
}

function PanelLoading({ label }: { label: string }) {
  return (
    <div className="flex min-h-40 items-center justify-center rounded-xl border border-white/10 bg-white/[0.03]" role="status">
      <Spinner className="text-console-accent" />
      <span className="sr-only">{label}</span>
    </div>
  );
}

function PanelError({ label, retry }: { label: string; retry: () => void }) {
  return (
    <div className="flex flex-col items-start gap-4 rounded-xl border border-console-warning/20 bg-console-warning/[0.05] p-5" role="alert">
      <p className="text-sm text-console-warning-bright">{label}</p>
      <button
        type="button"
        className="rounded-md border border-console-warning/20 px-3 py-1.5 text-sm text-console-warning-bright hover:bg-console-warning/10"
        onClick={retry}
      >
        Réessayer
      </button>
    </div>
  );
}

function nullableInteger(value: number | null): string {
  return value === null ? "—" : integerFormatter.format(value);
}

function formatDuration(milliseconds: number | null): string {
  if (milliseconds === null) return "—";
  if (milliseconds < 1000) return `${milliseconds} ms`;
  return `${new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 1 }).format(milliseconds / 1000)} s`;
}

function formatRate(value: number | null): string {
  if (value === null) return "—";
  return new Intl.NumberFormat("fr-FR", { style: "percent", maximumFractionDigits: 1 }).format(value);
}

function formatMib(value: number | null): string {
  if (value === null) return "—";
  return `${new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 1 }).format(value)} MiB`;
}

function formatBytes(value: number | null): string {
  if (value === null) return "—";
  if (value < 1024) return `${integerFormatter.format(value)} o`;
  if (value < 1024 * 1024) return `${new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 1 }).format(value / 1024)} Kio`;
  return `${new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 1 }).format(value / 1024 / 1024)} Mio`;
}

function formatHour(hour: number): string {
  return `${String(hour).padStart(2, "0")} h`;
}

function formatDate(value: string): string {
  return dateFormatter.format(new Date(value));
}
