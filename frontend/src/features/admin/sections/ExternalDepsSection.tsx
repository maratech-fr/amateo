import { AlertTriangle, CheckCircle2, Globe } from "lucide-react";

import { EmptyBlock } from "@/shared/components/ui/empty-hint";
import { Spinner } from "@/shared/components/ui/spinner";
import { cn } from "@/shared/lib/utils";

import type { AdminHealthExternalDependency } from "../api";
import { useAdminHealth } from "../queries";

/**
 * Dépendances externes (API FFBB, ODS Éducation, Etalab…) — sous-section de
 * l'onglet Infrastructure. Table en lecture seule : service, statut, latence.
 * Le polling React Query (30 s) est porté par `useAdminHealth`.
 */
export function ExternalDepsSection() {
  const health = useAdminHealth();

  if (health.isPending) {
    return (
      <div className="flex min-h-40 items-center justify-center rounded-xl border border-white/10 bg-white/[0.03]" role="status">
        <Spinner className="text-console-accent" />
        <span className="sr-only">Chargement des dépendances externes</span>
      </div>
    );
  }

  if (health.isError || !health.data) {
    return (
      <div className="rounded-xl border border-console-warning/20 bg-console-warning/[0.05] p-5" role="alert">
        <p className="text-sm text-console-warning-bright">Les dépendances externes sont indisponibles.</p>
      </div>
    );
  }

  const deps = health.data.externalDependencies;

  return (
    <section aria-labelledby="external-deps-heading" className="space-y-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">Dépendances externes</p>
        <h2 id="external-deps-heading" className="mt-2 text-xl font-semibold text-white">Services externes</h2>
      </div>
      {deps.length === 0 ? (
        <EmptyBlock variant="console">Aucune dépendance externe configurée.</EmptyBlock>
      ) : (
        <div className="overflow-hidden rounded-xl border border-white/10 bg-white/[0.03]">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-left text-sm">
              <caption className="sr-only">État des dépendances externes</caption>
              <thead className="border-b border-white/10 bg-white/[0.03] text-xs uppercase tracking-wider text-console-muted">
                <tr>
                  <th className="px-5 py-4 font-medium">Service</th>
                  <th className="px-4 py-4 font-medium">Statut</th>
                  <th className="px-4 py-4 font-medium">Latence</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-white/10">
                {deps.map((dep) => (
                  <ExternalDepRow key={dep.key} dep={dep} />
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </section>
  );
}

function ExternalDepRow({ dep }: { dep: AdminHealthExternalDependency }) {
  const isUp = dep.status === "up";
  const Icon = isUp ? CheckCircle2 : AlertTriangle;

  return (
    <tr className="align-top text-console-text hover:bg-white/[0.025]">
      <td className="px-5 py-5">
        <div className="flex items-start gap-3">
          <div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md bg-white/[0.06] text-console-muted">
            <Globe className="size-4" aria-hidden="true" />
          </div>
          <p className="font-medium text-white">{dep.name}</p>
        </div>
      </td>
      <td className="px-4 py-5">
        <span
          className={cn(
            "inline-flex items-center gap-1.5 text-xs font-medium",
            isUp ? "text-console-success" : "text-console-danger-deep",
          )}
        >
          <Icon className="size-3.5" aria-hidden="true" />
          {isUp ? "Opérationnel" : "Indisponible"}
        </span>
      </td>
      <td className="px-4 py-5 tabular-nums">
        {dep.latencyMs === undefined ? "—" : `${dep.latencyMs} ms`}
      </td>
    </tr>
  );
}