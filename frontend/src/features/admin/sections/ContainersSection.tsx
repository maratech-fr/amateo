import { AlertTriangle, CheckCircle2 } from "lucide-react";
import type { ReactNode } from "react";

import { EmptyBlock } from "@/shared/components/ui/empty-hint";
import { Spinner } from "@/shared/components/ui/spinner";
import { cn } from "@/shared/lib/utils";

import type { AdminHealthContainer } from "../api";
import { useAdminHealth } from "../queries";
import { ADMIN_SECTIONS_TEXTS } from "../texts/sections";

/**
 * Section « Conteneurs » : table des services Docker monitorés par le backend
 * (worker, cron-runner, pdf-worker…). Alimentée par `useAdminHealth()` (refetch 30 s).
 * Lecture seule — le redémarrage est différé (orchestrateur prod à définir, voir roadmap).
 */
export function ContainersSection() {
  const health = useAdminHealth();

  if (health.isPending) return <PanelLoading label={ADMIN_SECTIONS_TEXTS.containersLoading} />;
  if (health.isError || !health.data) return <PanelError label={ADMIN_SECTIONS_TEXTS.containersError} retry={() => void health.refetch()} />;

  const containers = health.data.containers;

  return (
    <section aria-labelledby="containers-heading" className="space-y-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">{ADMIN_SECTIONS_TEXTS.containersEyebrow}</p>
        <h2 id="containers-heading" className="mt-2 text-xl font-semibold text-white">{ADMIN_SECTIONS_TEXTS.containersHeading}</h2>
      </div>
      {containers.length === 0 ? (
        <EmptyBlock variant="console">{ADMIN_SECTIONS_TEXTS.containersEmpty}</EmptyBlock>
      ) : (
        <div className="overflow-hidden rounded-xl border border-white/10 bg-white/[0.04] p-5">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[36rem] text-left text-sm">
              <caption className="sr-only">{ADMIN_SECTIONS_TEXTS.containersCaption}</caption>
              <thead className="border-b border-white/10 text-xs uppercase tracking-wider text-console-muted">
                <tr>
                  <th className="py-3 pr-4 font-medium">{ADMIN_SECTIONS_TEXTS.colService}</th>
                  <th className="py-3 pr-4 font-medium">{ADMIN_SECTIONS_TEXTS.colStatus}</th>
                  <th className="py-3 font-medium">{ADMIN_SECTIONS_TEXTS.containersColSignal}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-white/10">
                {containers.map((container) => (
                  <ContainerRow key={container.key} container={container} />
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </section>
  );
}

function ContainerRow({ container }: { container: AdminHealthContainer }) {
  return (
    <tr className="text-console-text hover:bg-white/[0.025]">
      <td className="py-3 pr-4 font-medium text-white">{container.name}</td>
      <td className="py-3 pr-4"><StatusChip status={container.status} /></td>
      <td className="py-3 text-console-text-dim">{formatSignal(container)}</td>
    </tr>
  );
}

function StatusChip({ status }: { status: "up" | "down" | "unknown" }) {
  const healthy = status === "up";
  const Icon = healthy ? CheckCircle2 : AlertTriangle;
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 text-xs font-medium",
        healthy ? "text-console-success" : status === "unknown" ? "text-console-text-dim" : "text-console-danger",
      )}
    >
      <Icon className="size-3.5" aria-hidden="true" />
      {ADMIN_SECTIONS_TEXTS.containerStatusLabel(status)}
    </span>
  );
}

function formatSignal(container: AdminHealthContainer): ReactNode {
  if (container.ageSeconds !== undefined && container.ageSeconds !== null) {
    return ADMIN_SECTIONS_TEXTS.containerSignalAge(container.ageSeconds);
  }
  if (container.lastHeartbeatAt) {
    return new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short" }).format(new Date(container.lastHeartbeatAt));
  }
  return ADMIN_SECTIONS_TEXTS.containersNoSignal;
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