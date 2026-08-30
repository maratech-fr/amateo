import { ChevronLeft, ChevronRight } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { EmptyBlock } from "@/shared/components/ui/empty-hint";
import { Spinner } from "@/shared/components/ui/spinner";
import { cn } from "@/shared/lib/utils";

import type { AdminSystemErrorItem } from "../api";
import { useAdminSystemErrors } from "../queries";

const ERRORS_PER_PAGE = 50;

const integerFormatter = new Intl.NumberFormat("fr-FR");
const dateTimeFormatter = new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short" });

type Severity = "error" | "warning" | "info";

const SEVERITY_LABELS: Record<Severity, string> = {
  error: "Erreur",
  warning: "Avertissement",
  info: "Information",
};

const SEVERITY_STYLES: Record<Severity, string> = {
  error: "bg-console-danger-surface/15 text-console-danger",
  warning: "bg-console-warning-tint/15 text-console-warning",
  info: "bg-console-info-surface/15 text-console-info",
};

function isSeverity(value: string): value is Severity {
  return value === "error" || value === "warning" || value === "info";
}

function severityOf(value: string): Severity {
  return isSeverity(value) ? value : "info";
}

export function SystemErrorsSubtab() {
  const [page, setPage] = useState(1);
  const errors = useAdminSystemErrors(page, ERRORS_PER_PAGE);

  if (errors.isPending) {
    return (
      <div className="flex min-h-40 items-center justify-center rounded-xl border border-white/10 bg-white/[0.03]" role="status">
        <Spinner className="text-console-accent" />
        <span className="sr-only">Chargement des erreurs système</span>
      </div>
    );
  }

  if (errors.isError) {
    return (
      <div className="flex flex-col items-start gap-4 rounded-xl border border-console-warning/20 bg-console-warning/[0.05] p-5" role="alert">
        <p className="text-sm text-console-warning-bright">Les erreurs système sont indisponibles.</p>
        <Button
          type="button"
          size="sm"
          variant="outline"
          className="border-console-warning/20 text-console-warning-bright hover:bg-console-warning/10"
          onClick={() => void errors.refetch()}
        >
          Réessayer
        </Button>
      </div>
    );
  }

  const data = errors.data;
  if (!data || data.items.length === 0) {
    return (
      <EmptyBlock variant="console">Aucune erreur système enregistrée</EmptyBlock>
    );
  }

  const { pagination } = data;
  const pages = Math.max(pagination.pages, 1);
  const loading = errors.isFetching;

  return (
    <div className={cn("overflow-hidden rounded-xl border border-white/10 bg-white/[0.03]", loading && "opacity-70")}>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[860px] text-left text-sm">
          <caption className="sr-only">Erreurs système agrégées par source</caption>
          <thead className="border-b border-white/10 bg-white/[0.03] text-xs uppercase tracking-wider text-console-muted">
            <tr>
              <th className="px-5 py-4 font-medium">Source</th>
              <th className="px-4 py-4 font-medium">Message</th>
              <th className="px-4 py-4 font-medium">Sévérité</th>
              <th className="px-4 py-4 font-medium">Date</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-white/10">
            {data.items.map((item, index) => (
              <SystemErrorRow key={`${item.source}-${index}`} item={item} />
            ))}
          </tbody>
        </table>
      </div>
      <div className="flex items-center justify-between gap-4 border-t border-white/10 px-5 py-4">
        <p className="text-xs text-console-muted">
          {integerFormatter.format(pagination.total)} erreur{pagination.total > 1 ? "s" : ""} · page {pagination.page} sur {pages}
        </p>
        <div className="flex gap-2">
          <Button
            type="button"
            size="sm"
            variant="ghost"
            className="text-console-text hover:bg-white/10"
            aria-label="Page précédente"
            disabled={pagination.page <= 1 || loading}
            onClick={() => setPage(pagination.page - 1)}
          >
            <ChevronLeft aria-hidden="true" />
          </Button>
          <Button
            type="button"
            size="sm"
            variant="ghost"
            className="text-console-text hover:bg-white/10"
            aria-label="Page suivante"
            disabled={pagination.page >= pagination.pages || loading}
            onClick={() => setPage(pagination.page + 1)}
          >
            <ChevronRight aria-hidden="true" />
          </Button>
        </div>
      </div>
    </div>
  );
}

function SystemErrorRow({ item }: { item: AdminSystemErrorItem }) {
  const sev = severityOf(item.severity);
  return (
    <tr className="align-top text-console-text hover:bg-white/[0.025]">
      <td className="px-5 py-5">
        <p className="font-mono text-xs text-console-text-dim">{item.source}</p>
      </td>
      <td className="px-4 py-5">
        <p className="text-console-text-bright">{item.message}</p>
      </td>
      <td className="px-4 py-5">
        <span className={cn("inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold", SEVERITY_STYLES[sev])}>
          {SEVERITY_LABELS[sev]}
        </span>
      </td>
      <td className="px-4 py-5 tabular-nums text-console-text-dim">{dateTimeFormatter.format(new Date(item.createdAt))}</td>
    </tr>
  );
}