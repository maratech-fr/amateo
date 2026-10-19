import { EmptyBlock, EmptyHint } from "@/shared/components/ui/empty-hint";
import { Spinner } from "@/shared/components/ui/spinner";
import { formatMinutes } from "@/shared/lib/time";
import { cn } from "@/shared/lib/utils";

import type { AdminCapacityResponse } from "../api";
import { useAdminCapacity } from "../queries";
import { ADMIN_SECTIONS_TEXTS } from "../texts/sections";

const integerFormatter = new Intl.NumberFormat("fr-FR");
const dateFormatter = new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "short", year: "numeric" });

/** Limite mémoire du worker engine en prod (libellé statique — le repère, pas une mesure). */
const PROD_MEMORY_LIMIT_MIB = 512;

/**
 * Section « Capacité » (P5-10) : ce que la télémétrie `solver_metrics` dit de la
 * tenue du système sous charge sur 90 jours — volume, attente en file, durées par
 * taille de problème, mémoire de l'engine, issues. Alimentée par `useAdminCapacity()`
 * (refetch 5 min). Lecture seule. Toute valeur nulle (historique pré-2.6, échec) → « — ».
 */
export function CapacitySection() {
  const capacity = useAdminCapacity();

  if (capacity.isPending) return <PanelLoading label={ADMIN_SECTIONS_TEXTS.capacityLoading} />;
  if (capacity.isError || !capacity.data) {
    return <PanelError label={ADMIN_SECTIONS_TEXTS.capacityError} retry={() => void capacity.refetch()} />;
  }

  const data = capacity.data;

  return (
    <section aria-labelledby="capacity-heading" className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">{ADMIN_SECTIONS_TEXTS.capacityEyebrow}</p>
          <h2 id="capacity-heading" className="mt-2 text-xl font-semibold text-white">
            {ADMIN_SECTIONS_TEXTS.capacityHeading(data.windowDays)}
          </h2>
        </div>
        <div className="text-right">
          <p className="text-2xl font-semibold tabular-nums text-white">{integerFormatter.format(data.totalSolves)}</p>
          <p className="text-xs text-console-muted">{ADMIN_SECTIONS_TEXTS.capacitySolvesWindow(data.totalSolves)}</p>
        </div>
      </div>

      {data.totalSolves === 0 ? (
        <EmptyBlock variant="console">{ADMIN_SECTIONS_TEXTS.capacityEmpty(data.windowDays)}</EmptyBlock>
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
      <p className="text-sm font-medium text-white">{ADMIN_SECTIONS_TEXTS.capacityVolume}</p>
      <dl className="mt-5 grid grid-cols-2 gap-x-5 gap-y-6">
        <SmallMetric label={ADMIN_SECTIONS_TEXTS.capacitySolvesPerDayMedian} value={nullableInteger(volume.perDay.p50)} />
        <SmallMetric
          label={ADMIN_SECTIONS_TEXTS.capacitySolvesPerDayPeak}
          value={nullableInteger(volume.perDay.max)}
          detail={volume.perDay.maxDate ? ADMIN_SECTIONS_TEXTS.capacityPeakOn(formatDate(volume.perDay.maxDate)) : undefined}
        />
      </dl>
      <div className="mt-6 border-t border-white/10 pt-4">
        <p className="text-xs font-medium text-console-text-dim">{ADMIN_SECTIONS_TEXTS.capacityHourlyProfile}</p>
        {volume.hourly.length === 0 ? (
          <EmptyHint variant="console" className="mt-2">{ADMIN_SECTIONS_TEXTS.capacityNoHour}</EmptyHint>
        ) : (
          <div className="mt-3 overflow-x-auto">
            <table className="w-full min-w-[16rem] text-left text-sm">
              <caption className="sr-only">{ADMIN_SECTIONS_TEXTS.capacityHourlyCaption}</caption>
              <thead>
                <tr className="text-xs uppercase tracking-wide text-console-muted">
                  <th className="py-2 pr-4 font-medium">{ADMIN_SECTIONS_TEXTS.capacityColHour}</th>
                  <th className="py-2 font-medium">{ADMIN_SECTIONS_TEXTS.capacityColSolves}</th>
                </tr>
              </thead>
              <tbody>
                {volume.hourly.map((row) => (
                  <tr key={row.hour} className="border-t border-white/10 text-console-text">
                    <td className="py-2 pr-4 tabular-nums">{formatMinutes(row.hour * 60)}</td>
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
      <p className="text-sm font-medium text-white">{ADMIN_SECTIONS_TEXTS.capacityWait}</p>
      <p className="mt-1 text-xs text-console-muted">{ADMIN_SECTIONS_TEXTS.capacityWaitHint}</p>
      <dl className="mt-5 grid grid-cols-3 gap-3">
        <SmallMetric label={ADMIN_SECTIONS_TEXTS.capacityMedian} value={formatDuration(wait.queueP50Ms)} />
        <SmallMetric label={ADMIN_SECTIONS_TEXTS.capacityP95} value={formatDuration(wait.queueP95Ms)} />
        <SmallMetric label={ADMIN_SECTIONS_TEXTS.capacityMax} value={formatDuration(wait.queueMaxMs)} />
      </dl>
      <div className="mt-6 border-t border-white/10 pt-4 text-xs text-console-muted">
        {ADMIN_SECTIONS_TEXTS.capacityEngineWaitLabel} <span className="ml-1 font-medium text-console-text">{formatDuration(wait.engineWaitP95Ms)}</span>
      </div>
    </article>
  );
}

function SizePanel({ bySize }: { bySize: AdminCapacityResponse["bySize"] }) {
  return (
    <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
      <p className="text-sm font-medium text-white">{ADMIN_SECTIONS_TEXTS.capacitySize}</p>
      <p className="mt-1 text-xs text-console-muted">{ADMIN_SECTIONS_TEXTS.capacitySizeHint}</p>
      {bySize.length === 0 ? (
        <EmptyHint variant="console" className="mt-4">{ADMIN_SECTIONS_TEXTS.capacityNoSize}</EmptyHint>
      ) : (
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[40rem] text-left text-sm">
            <caption className="sr-only">{ADMIN_SECTIONS_TEXTS.capacitySizeCaption}</caption>
            <thead>
              <tr className="text-xs uppercase tracking-wide text-console-muted">
                <th className="py-2 pr-4 font-medium">{ADMIN_SECTIONS_TEXTS.capacityColBucket}</th>
                <th className="py-2 pr-4 font-medium">{ADMIN_SECTIONS_TEXTS.capacityColSolves}</th>
                <th className="py-2 pr-4 font-medium">{ADMIN_SECTIONS_TEXTS.capacityColP50}</th>
                <th className="py-2 pr-4 font-medium">{ADMIN_SECTIONS_TEXTS.capacityColP95Duration}</th>
                <th className="py-2 pr-4 font-medium">{ADMIN_SECTIONS_TEXTS.capacityColBudget}</th>
                <th className="py-2 font-medium">{ADMIN_SECTIONS_TEXTS.capacityColUnclosed}</th>
              </tr>
            </thead>
            <tbody>
              {bySize.map((row) => (
                <tr key={row.bucket} className="border-t border-white/10 text-console-text">
                  <td className="py-2 pr-4 font-medium text-white">{ADMIN_SECTIONS_TEXTS.capacityBucketLabel(row.bucket)}</td>
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
        {ADMIN_SECTIONS_TEXTS.capacitySizeFootnote}
      </p>
    </article>
  );
}

function MemoryPanel({ memory }: { memory: AdminCapacityResponse["memory"] }) {
  return (
    <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
      <p className="text-sm font-medium text-white">{ADMIN_SECTIONS_TEXTS.capacityMemory}</p>
      <p className="mt-1 text-xs text-console-muted">{ADMIN_SECTIONS_TEXTS.capacityMemoryHint(PROD_MEMORY_LIMIT_MIB)}</p>
      <dl className="mt-5 grid grid-cols-3 gap-3">
        <SmallMetric label={ADMIN_SECTIONS_TEXTS.capacityMedian} value={formatMib(memory.peakP50Mb)} />
        <SmallMetric label={ADMIN_SECTIONS_TEXTS.capacityP95} value={formatMib(memory.peakP95Mb)} />
        <SmallMetric
          label={ADMIN_SECTIONS_TEXTS.capacityMax}
          value={formatMib(memory.peakMaxMb)}
          tone={memory.peakMaxMb !== null && memory.peakMaxMb > PROD_MEMORY_LIMIT_MIB ? "danger" : undefined}
        />
      </dl>
      <div className="mt-6 border-t border-white/10 pt-4 text-xs text-console-muted">
        {ADMIN_SECTIONS_TEXTS.capacityBaselineLabel} <span className="ml-1 font-medium text-console-text">{formatMib(memory.lastBaselineMb)}</span>
      </div>
    </article>
  );
}

function IssuesPanel({ issues, totalSolves }: { issues: AdminCapacityResponse["issues"]; totalSolves: number }) {
  return (
    <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
      <p className="text-sm font-medium text-white">{ADMIN_SECTIONS_TEXTS.capacityIssues}</p>
      <dl className="mt-5 space-y-4">
        {issues.byStatus.length === 0 ? <EmptyHint variant="console">{ADMIN_SECTIONS_TEXTS.capacityNoIssue}</EmptyHint> : null}
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
        {ADMIN_SECTIONS_TEXTS.capacityPayloadLabel} <span className="ml-1 font-medium text-console-text">{formatBytes(issues.payloadP95Bytes)}</span>
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
        {ADMIN_SECTIONS_TEXTS.retry}
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

function formatDate(value: string): string {
  return dateFormatter.format(new Date(value));
}
