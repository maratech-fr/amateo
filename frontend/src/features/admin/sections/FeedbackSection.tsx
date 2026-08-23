import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { Spinner } from "@/shared/components/ui/spinner";
import { errorMessage } from "@/shared/lib/errorMessage";
import { cn } from "@/shared/lib/utils";

import type { AdminFeedbackContext, AdminFeedbackItem, AdminFeedbackQos, AdminFeedbackStatus, AdminFeedbackTopic } from "../api";
import { useAdminFeedback, useAdminFeedbackDetail, useTreatAdminFeedback, useUntreatAdminFeedback } from "../queries";

const TOPIC_LABELS: Record<AdminFeedbackTopic, string> = {
  bug: "Bug",
  missing_constraint: "Contrainte manquante",
  idea: "Idée",
};

const STATUS_LABELS: Record<AdminFeedbackStatus, string> = {
  untreated: "Non traité",
  treated: "Traité",
};

const dateFormatter = new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });

const fmtDate = (iso: string): string => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : dateFormatter.format(d);
};

const fmtPct = (v: number): string => `${Math.round(v * 100)} %`;

const fmtHours = (v: number | null): string => {
  if (null === v) return "—";
  return Number.isInteger(v) ? `${v} h` : `${v.toFixed(1)} h`;
};

type StatusFilter = "all" | AdminFeedbackStatus;

const FILTERS: { id: StatusFilter; label: string }[] = [
  { id: "all", label: "Tous" },
  { id: "untreated", label: "Non traités" },
  { id: "treated", label: "Traités" },
];

/**
 * Onglet « Signalements » de la console SA (P5-6, D6) : le panneau qualité de service en
 * tête (§3ter), la liste filtrable par statut, et le détail (message + contexte) rendu en
 * TEXTE PUR — le snapshot n'est jamais déplié dans le DOM (métadonnées + copie JSON).
 */
export function FeedbackSection() {
  const [filter, setFilter] = useState<StatusFilter>("all");
  const [openItem, setOpenItem] = useState<AdminFeedbackItem | null>(null);
  const feedback = useAdminFeedback("all" === filter ? undefined : filter);
  const items = feedback.data?.items ?? [];

  return (
    <section aria-labelledby="feedback-heading" className="space-y-6">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Retours terrain</p>
        <h2 id="feedback-heading" className="mt-2 text-xl font-semibold text-white">Signalements</h2>
      </div>

      {feedback.data ? <QosPanel qos={feedback.data.qos} /> : null}

      <section aria-label="Liste des signalements" className="space-y-4">
        <div className="flex flex-wrap gap-2" role="group" aria-label="Filtrer par statut">
          {FILTERS.map((f) => (
            <Button
              key={f.id}
              type="button"
              size="sm"
              variant={filter === f.id ? "default" : "outline"}
              className={cn(filter === f.id && "bg-cyan-300 text-slate-950 hover:bg-cyan-200")}
              aria-pressed={filter === f.id}
              onClick={() => setFilter(f.id)}
            >
              {f.label}
            </Button>
          ))}
        </div>

        {feedback.isPending ? (
          <p className="flex items-center gap-2 text-sm text-slate-400"><Spinner className="size-4" /> Chargement…</p>
        ) : feedback.isError ? (
          <p className="text-sm text-rose-300">
            La liste n'a pas pu être lue.{" "}
            <button type="button" className="underline" onClick={() => void feedback.refetch()}>Réessayer</button>
          </p>
        ) : 0 === items.length ? (
          <p className="text-sm text-slate-400">Aucun signalement.</p>
        ) : (
          <ul className="divide-y divide-white/10 rounded-xl border border-white/10 bg-white/[0.03]">
            {items.map((item) => (
              <li key={item.id}>
                <button
                  type="button"
                  aria-label={`Voir le signalement de ${item.clubName ?? "club inconnu"}`}
                  onClick={() => setOpenItem(item)}
                  className="flex w-full flex-col gap-1 p-4 text-left transition-colors hover:bg-white/[0.04] md:flex-row md:items-center md:gap-4"
                >
                  <span className="shrink-0 text-xs text-slate-500 md:w-40">{fmtDate(item.createdAt)}</span>
                  <span className="shrink-0 font-medium text-white md:w-48 md:truncate">{item.clubName ?? "Club inconnu"}</span>
                  <span className="shrink-0 rounded-full bg-white/10 px-2 py-0.5 text-xs text-slate-200 md:w-40 md:text-center">{TOPIC_LABELS[item.topic]}</span>
                  <span className="min-w-0 flex-1 truncate text-sm text-slate-300">{item.message}</span>
                  <span className={cn("shrink-0 text-xs font-medium", "untreated" === item.status ? "text-amber-300" : "text-emerald-300")}>{STATUS_LABELS[item.status]}</span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>

      {null !== openItem ? <FeedbackDetailDialog item={openItem} onClose={() => setOpenItem(null)} /> : null}
    </section>
  );
}

function QosPanel({ qos }: { qos: AdminFeedbackQos }) {
  return (
    <article aria-labelledby="qos-heading" className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
      <h3 id="qos-heading" className="text-sm font-semibold text-white">Qualité de service</h3>
      <dl className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <SmallMetric label="Part traitée" value={fmtPct(qos.treatedShare)} />
        <SmallMetric label="Plus vieux non traité" value={fmtHours(qos.oldestUntreatedAgeHours)} tone={null !== qos.oldestUntreatedAgeHours && qos.oldestUntreatedAgeHours > 72 ? "danger" : undefined} />
      </dl>

      <div className="mt-5 grid gap-5 lg:grid-cols-2">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Délai dépôt → traité (par mois)</p>
          {0 === qos.treatDelayByMonth.length ? (
            <p className="mt-2 text-xs text-slate-500">—</p>
          ) : (
            <table className="mt-2 w-full text-left text-xs text-slate-300">
              <thead className="text-slate-500">
                <tr>
                  <th className="py-1 font-medium">Mois</th>
                  <th className="py-1 font-medium">Moyenne</th>
                  <th className="py-1 font-medium">p95</th>
                </tr>
              </thead>
              <tbody>
                {qos.treatDelayByMonth.map((row) => (
                  <tr key={row.month}>
                    <td className="py-1 tabular-nums">{row.month}</td>
                    <td className="py-1 tabular-nums">{fmtHours(row.avgHours)}</td>
                    <td className="py-1 tabular-nums">{fmtHours(row.p95Hours)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Volume par type (par mois)</p>
          {0 === qos.volumeByTopicMonth.length ? (
            <p className="mt-2 text-xs text-slate-500">—</p>
          ) : (
            <table className="mt-2 w-full text-left text-xs text-slate-300">
              <thead className="text-slate-500">
                <tr>
                  <th className="py-1 font-medium">Mois</th>
                  <th className="py-1 font-medium">Type</th>
                  <th className="py-1 font-medium">Volume</th>
                </tr>
              </thead>
              <tbody>
                {qos.volumeByTopicMonth.map((row) => (
                  <tr key={`${row.month}-${row.topic}`}>
                    <td className="py-1 tabular-nums">{row.month}</td>
                    <td className="py-1">{TOPIC_LABELS[row.topic]}</td>
                    <td className="py-1 tabular-nums">{row.count}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </article>
  );
}

function SmallMetric({ label, value, tone }: { label: string; value: string; tone?: "danger" }) {
  return (
    <div>
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd className={cn("mt-1 text-lg font-semibold tabular-nums text-white", "danger" === tone && "text-amber-300")}>{value}</dd>
    </div>
  );
}

function FeedbackDetailDialog({ item, onClose }: { item: AdminFeedbackItem; onClose: () => void }) {
  const detail = useAdminFeedbackDetail(item.id);
  const treat = useTreatAdminFeedback();
  const untreat = useUntreatAdminFeedback();
  const [confirming, setConfirming] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const message = detail.data?.message ?? item.message;
  const context = detail.data?.context ?? null;
  const status = detail.data?.status ?? item.status;
  const pending = treat.isPending || untreat.isPending;

  const runVerdict = () => {
    setActionError(null);
    const mutation = "untreated" === status ? treat : untreat;
    mutation.mutate(item.id, {
      onSuccess: () => {
        setConfirming(false);
        onClose();
      },
      onError: (err) => void errorMessage(err).then(setActionError),
    });
  };

  const copyJson = () => {
    if (null === context) return;
    const json = JSON.stringify(context, null, 2);
    void navigator.clipboard?.writeText(json).then(
      () => setCopied(true),
      () => setCopied(false),
    );
  };

  return (
    <Modal
      label="Détail du signalement"
      title="Signalement"
      onClose={onClose}
      size="xl"
      footer={
        confirming ? (
          <>
            <Button type="button" size="sm" variant="ghost" onClick={() => setConfirming(false)}>Annuler</Button>
            <Button type="button" size="sm" disabled={pending} onClick={runVerdict}>
              {pending ? <Spinner className="size-4" /> : null}
              {"untreated" === status ? "Confirmer le traitement" : "Confirmer la réouverture"}
            </Button>
          </>
        ) : (
          <Button type="button" size="sm" variant="outline" onClick={() => setConfirming(true)}>
            {"untreated" === status ? "Traiter" : "Rouvrir"}
          </Button>
        )
      }
    >
      <div className="mt-4 space-y-5 text-sm">
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
          <MetaRow label="Club" value={item.clubName ?? "Club inconnu"} />
          <MetaRow label="Type" value={TOPIC_LABELS[item.topic]} />
          <MetaRow label="Reçu le" value={fmtDate(item.createdAt)} />
          <MetaRow label="Statut" value={STATUS_LABELS[status]} />
        </dl>

        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Message</p>
          {/* TEXTE PUR : nœud texte React (échappé), retours à la ligne préservés. */}
          <p className="mt-1 whitespace-pre-line rounded-md border border-border bg-muted/40 p-3">{message}</p>
        </div>

        {detail.isPending ? (
          <p className="flex items-center gap-2 text-xs text-muted-foreground"><Spinner className="size-4" /> Chargement du contexte…</p>
        ) : detail.isError ? (
          <p className="text-xs text-destructive">Le contexte n'a pas pu être lu.</p>
        ) : null}

        {null !== context ? <ContextBlock context={context} onCopy={copyJson} copied={copied} /> : null}

        {null !== actionError ? (
          <p role="alert" className="text-sm text-destructive">{actionError}</p>
        ) : null}

      </div>
    </Modal>
  );
}

function ContextBlock({ context, onCopy, copied }: { context: AdminFeedbackContext; onCopy: () => void; copied: boolean }) {
  // Le snapshot est ÉNORME : on n'affiche que ses métadonnées (jamais son JSON dans le DOM).
  const snapshotBytes = undefined === context.snapshot ? 0 : JSON.stringify(context.snapshot).length;
  const diagnosticsCount = Array.isArray(context.diagnostics) ? context.diagnostics.length : 0;
  const hasHeavy = snapshotBytes > 0 || diagnosticsCount > 0;

  return (
    <div className="space-y-2">
      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Contexte</p>
      <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
        {context.screen ? <MetaRow label="Écran" value={context.screen} /> : null}
        {/* URL en TEXTE PUR — jamais un lien cliquable (consigne revue). */}
        {context.url ? <MetaRow label="URL" value={context.url} /> : null}
        {context.requestId ? <MetaRow label="Réf. incident" value={context.requestId} /> : null}
        {context.userAgent ? <MetaRow label="Navigateur" value={context.userAgent} /> : null}
        {context.seasonId ? <MetaRow label="Saison" value={context.seasonId} /> : null}
        {context.scheduleId ? <MetaRow label="Planning" value={context.scheduleId} /> : null}
        {context.scheduleStatus ? <MetaRow label="État planning" value={context.scheduleStatus} /> : null}
      </dl>
      {hasHeavy ? (
        <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
          <span>Snapshot : {snapshotBytes.toLocaleString("fr-FR")} caractères · {diagnosticsCount} diagnostic{diagnosticsCount > 1 ? "s" : ""}</span>
          <Button type="button" size="sm" variant="ghost" className="h-7 px-2" onClick={onCopy}>
            {copied ? "Copié" : "Copier le JSON"}
          </Button>
        </div>
      ) : null}
    </div>
  );
}

function MetaRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="break-words font-medium">{value}</dd>
    </div>
  );
}
