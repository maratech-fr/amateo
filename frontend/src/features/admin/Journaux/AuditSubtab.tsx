import { ChevronLeft, ChevronRight } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { EmptyBlock } from "@/shared/components/ui/empty-hint";
import { Spinner } from "@/shared/components/ui/spinner";
import { cn } from "@/shared/lib/utils";

import type { AdminAuditLogItem } from "../api";
import { useAdminAuditLog } from "../queries";

const AUDIT_PER_PAGE = 50;

const integerFormatter = new Intl.NumberFormat("fr-FR");
const dateTimeFormatter = new Intl.DateTimeFormat("fr-FR", {
  day: "2-digit",
  month: "short",
  year: "numeric",
  hour: "2-digit",
  minute: "2-digit",
});

type AuditStatus = "success" | "error" | "unknown";

const STATUS_LABELS: Record<AuditStatus, string> = {
  success: "Succès",
  error: "Erreur",
  unknown: "—",
};

const STATUS_STYLES: Record<AuditStatus, string> = {
  success: "bg-console-success-surface/15 text-console-success",
  error: "bg-console-danger-surface/15 text-console-danger",
  unknown: "bg-white/10 text-console-text-dim",
};

/** Le statut vient du CODE HTTP (status_code) : <400 = succès, ≥400 = erreur, null = inconnu. */
function statusOf(code: number | null): AuditStatus {
  if (null === code) {
    return "unknown";
  }
  return code >= 400 ? "error" : "success";
}

export function AuditSubtab() {
  const [page, setPage] = useState(1);
  const audit = useAdminAuditLog(page, AUDIT_PER_PAGE);

  if (audit.isPending) {
    return (
      <div className="flex min-h-40 items-center justify-center rounded-xl border border-white/10 bg-white/[0.04]" role="status">
        <Spinner className="text-console-accent" />
        <span className="sr-only">Chargement du journal d’audit</span>
      </div>
    );
  }

  if (audit.isError) {
    return (
      <div className="flex flex-col items-start gap-4 rounded-xl border border-console-warning/20 bg-console-warning/[0.05] p-5" role="alert">
        <p className="text-sm text-console-warning-bright">Le journal d’audit est indisponible.</p>
        <Button
          type="button"
          size="sm"
          variant="outline"
          className="border-console-warning/20 text-console-warning-bright hover:bg-console-warning/10"
          onClick={() => void audit.refetch()}
        >
          Réessayer
        </Button>
      </div>
    );
  }

  const data = audit.data;
  if (!data || data.items.length === 0) {
    return (
      <EmptyBlock variant="console">Aucun SuperAdmin audité pour le moment</EmptyBlock>
    );
  }

  const { pagination } = data;
  const pages = Math.max(pagination.pages, 1);
  const loading = audit.isFetching;

  return (
    <div className={cn("overflow-hidden rounded-xl border border-white/10 bg-white/[0.04]", loading && "opacity-70")}>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[760px] text-left text-sm">
          <caption className="sr-only">Journal d’audit super-admin</caption>
          <thead className="border-b border-white/10 bg-white/[0.03] text-xs uppercase tracking-wider text-console-muted">
            <tr>
              <th className="px-5 py-4 font-medium">Acteur</th>
              <th className="px-4 py-4 font-medium">Route</th>
              <th className="px-4 py-4 font-medium">Statut</th>
              <th className="px-4 py-4 font-medium">Date</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-white/10">
            {data.items.map((item, index) => (
              <AuditRow key={`${item.id}-${index}`} item={item} />
            ))}
          </tbody>
        </table>
      </div>
      <div className="flex items-center justify-between gap-4 border-t border-white/10 px-5 py-4">
        <p className="text-xs text-console-muted">
          {integerFormatter.format(pagination.total)} entrée{pagination.total > 1 ? "s" : ""} · page {pagination.page} sur {pages}
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

function AuditRow({ item }: { item: AdminAuditLogItem }) {
  const status = statusOf(item.status);
  return (
    <tr className="align-top text-console-text hover:bg-white/[0.025]">
      <td className="px-5 py-5">
        <p className="text-console-text-bright">{item.actorEmail ?? "—"}</p>
      </td>
      <td className="px-4 py-5">
        <p className="font-mono text-xs text-console-text-dim">{item.route ?? "—"}</p>
      </td>
      <td className="px-4 py-5">
        <span className={cn("inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold", STATUS_STYLES[status])}>
          {STATUS_LABELS[status]}
          {null !== item.status ? <span className="tabular-nums opacity-70">{item.status}</span> : null}
        </span>
      </td>
      <td className="px-4 py-5 tabular-nums text-console-text-dim">{dateTimeFormatter.format(new Date(item.createdAt))}</td>
    </tr>
  );
}