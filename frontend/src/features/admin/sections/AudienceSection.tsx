import type { ReactNode } from "react";

import { EmptyBlock } from "@/shared/components/ui/empty-hint";
import { Spinner } from "@/shared/components/ui/spinner";
import { cn } from "@/shared/lib/utils";

import type { AdminAudienceMetric, AdminAudienceWindow } from "../api";
import { useAdminAudience } from "../queries";
import { ADMIN_SECTIONS_TEXTS } from "../texts/sections";

const integerFormatter = new Intl.NumberFormat("fr-FR");

/**
 * Section « Mesure d'audience » (P4-307) : ce qu'Umami dit de la fréquentation de la vitrine —
 * deux fenêtres (7 j / 30 j) avec leur tendance, le top des pages et le top des sources.
 * Alimentée par `useAdminAudience()` (staleTime 5 min, pas de refetch auto). Lecture seule.
 * Trois états servis par la route (toujours 200) : non configurée · indisponible · données.
 */
export function AudienceSection() {
  const audience = useAdminAudience();

  if (audience.isPending) return <PanelLoading label={ADMIN_SECTIONS_TEXTS.audienceLoading} />;
  if (audience.isError || !audience.data) {
    return <PanelError label={ADMIN_SECTIONS_TEXTS.audienceError} retry={() => void audience.refetch()} />;
  }

  const data = audience.data;

  if (!data.configured) {
    return (
      <AudienceShell>
        <EmptyBlock variant="console">{ADMIN_SECTIONS_TEXTS.audienceNotConfigured}</EmptyBlock>
      </AudienceShell>
    );
  }

  if (!data.available) {
    return (
      <AudienceShell>
        <EmptyBlock variant="console">{ADMIN_SECTIONS_TEXTS.audienceUnavailable}</EmptyBlock>
      </AudienceShell>
    );
  }

  return (
    <AudienceShell>
      <div className="grid gap-4 lg:grid-cols-2">
        <WindowPanel label={ADMIN_SECTIONS_TEXTS.audienceWindow7d} window={data.windows.d7} />
        <WindowPanel label={ADMIN_SECTIONS_TEXTS.audienceWindow30d} window={data.windows.d30} />
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <MetricsPanel title={ADMIN_SECTIONS_TEXTS.audienceTopPages} unit={ADMIN_SECTIONS_TEXTS.audienceUnitPageviews} metrics={data.topPages} />
        <MetricsPanel title={ADMIN_SECTIONS_TEXTS.audienceTopSources} unit={ADMIN_SECTIONS_TEXTS.audienceUnitVisitors} metrics={data.topSources} />
      </div>
    </AudienceShell>
  );
}

function AudienceShell({ children }: { children: ReactNode }) {
  return (
    <section aria-labelledby="audience-heading" className="space-y-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">{ADMIN_SECTIONS_TEXTS.audienceEyebrow}</p>
        <h2 id="audience-heading" className="mt-2 text-xl font-semibold text-white">
          {ADMIN_SECTIONS_TEXTS.audienceHeading}
        </h2>
      </div>
      {children}
    </section>
  );
}

function WindowPanel({ label, window }: { label: string; window: AdminAudienceWindow }) {
  return (
    <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
      <div className="flex items-baseline justify-between gap-3">
        <p className="text-sm font-medium text-white">{label}</p>
        <TrendLine trend={window.trend} />
      </div>
      <dl className="mt-5 grid grid-cols-3 gap-3">
        <BigMetric label={ADMIN_SECTIONS_TEXTS.audienceMetricVisitors} value={window.visitors} />
        <BigMetric label={ADMIN_SECTIONS_TEXTS.audienceMetricVisits} value={window.visits} />
        <BigMetric label={ADMIN_SECTIONS_TEXTS.audienceMetricPageviews} value={window.pageviews} />
      </dl>
    </article>
  );
}

function MetricsPanel({ title, unit, metrics }: { title: string; unit: string; metrics: AdminAudienceMetric[] }) {
  return (
    <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
      <p className="text-sm font-medium text-white">{title}</p>
      {metrics.length === 0 ? (
        <EmptyBlock variant="console" className="mt-4">
          {ADMIN_SECTIONS_TEXTS.audienceNoData}
        </EmptyBlock>
      ) : (
        <ul className="mt-4 space-y-2">
          {metrics.map((metric) => (
            <li key={metric.value} className="flex items-baseline justify-between gap-3 text-sm">
              <span className="truncate text-console-text">{metric.value}</span>
              <span className="shrink-0 tabular-nums text-console-muted">
                {integerFormatter.format(metric.count)} {unit}
              </span>
            </li>
          ))}
        </ul>
      )}
    </article>
  );
}

/** Tendance discrète (pas de pastille) : « + N % » / « − N % » / « stable ». */
function TrendLine({ trend }: { trend: number }) {
  if (trend === 0) return <span className="text-xs text-console-muted">{ADMIN_SECTIONS_TEXTS.audienceTrendStable}</span>;
  const rising = trend > 0;
  return (
    <span className={cn("text-xs tabular-nums", rising ? "text-console-accent" : "text-console-muted")}>
      {ADMIN_SECTIONS_TEXTS.audienceTrend(`${rising ? "+" : "−"}${integerFormatter.format(Math.abs(trend))}`)}
    </span>
  );
}

function BigMetric({ label, value }: { label: string; value: number }) {
  return (
    <div>
      <dt className="text-xs text-console-muted">{label}</dt>
      <dd className="mt-1 text-lg font-semibold tabular-nums text-white">{integerFormatter.format(value)}</dd>
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
