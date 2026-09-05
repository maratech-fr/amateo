import { Download, Eye, Loader2, Pencil, Star } from "lucide-react";
import { useState } from "react";
import { useNavigate } from "react-router";

import { useMe, useWorkingSeason } from "@/shared/session/queries";
import { STATUS_LABELS, type Schedule } from "@/features/planning/api";
import { type ExportFormat, useScheduleExport } from "@/features/planning/queries";
import { usePlanningStore } from "@/features/planning/store";
import { useWizardStore } from "@/features/wizard/store";
import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { frDateNumeric } from "./lib/date";
import { useSchedulePlans } from "./queries";
import { StalenessPill } from "./StalenessPill";
import type { CalendarEntry } from "./api";
import { DeletePlanningButton } from "./DeletePlanningButton";
import { type PlanningRow, seasonPlannings } from "./seasonPlannings";

interface SeasonSchedulesModalProps {
  schedules: Schedule[];
  /** Entrées de calendrier — pour exclure une mère découpée des lignes 0 version (B1 F3). */
  entries?: CalendarEntry[];
  /** Schedules résolus ? fail-closed sur les lignes 0 version (B1 F4). */
  schedulesResolved?: boolean;
  onClose: () => void;
}

const EXPORT_FORMATS: { key: ExportFormat; label: string }[] = [
  { key: "pdf", label: "PDF" },
  { key: "xlsx", label: "Excel" },
];

/**
 * Compact export: an icon-only trigger that expands an INLINE format row (no
 * absolute dropdown — it would be clipped by the modal's scroll container). The
 * export always covers every gym (venue scope lives on the planning page).
 */
function CompactExport({ scheduleId, label }: { scheduleId: string; label: string }) {
  const [open, setOpen] = useState(false);
  const { run, busy } = useScheduleExport(scheduleId, label);

  return (
    <div className="flex items-center gap-1">
      <Button variant="ghost" size="icon" className="size-8" aria-expanded={open} aria-label={`Exporter ${label}`} title="Exporter" onClick={() => setOpen((o) => !o)}>
        <Download className="size-4" />
      </Button>
      {open ? (
        // AUD-FRT-23 (2e site, trouvé au grep — l'audit n'avait vu qu'ExportMenu) : une rangée
        // de boutons de format, sans navigation aux flèches, n'est pas un menu ARIA.
        <div role="group" aria-label={`Formats d'export de ${label}`} className="flex items-center gap-1">
          {EXPORT_FORMATS.map(({ key, label: fmt }) => (
            <button
              key={key}
              type="button"
              disabled={null !== busy}
              onClick={() => void run(key, null)}
              className="flex items-center gap-1 rounded-md border border-border px-2 py-1 text-xs font-medium hover:bg-muted disabled:opacity-50"
            >
              {busy === key ? <Loader2 className="size-3 animate-spin" /> : null}
              {fmt}
            </button>
          ))}
        </div>
      ) : null}
    </div>
  );
}

/**
 * Lists the season's distinct PLANNINGS (principal + period overlays), NOT the
 * versions of a plan. Each row consults (eye) or exports the planning directly.
 * The main plan is a fact (★) — no "set as main" here.
 * OPEN plannings (no finished version) are listed too — the manager has work to
 * finish there; their export is hidden (nothing exportable) and the action is
 * « Reprendre » (founder feedback 2026-07-18).
 */
export function SeasonSchedulesModal({ schedules, entries = [], schedulesResolved = true, onClose }: SeasonSchedulesModalProps) {
  const navigate = useNavigate();
  const { data: me } = useMe();
  const setSelectedScheduleId = usePlanningStore((s) => s.setSelectedScheduleId);
  // calendarEntryId par plan : nécessaire pour « Reprendre » un overlay ouvert
  // (le wizard mode période s'ancre sur l'ENTRÉE, pas sur le plan).
  const { data: plans } = useSchedulePlans();
  const entryByPlan = new Map((plans ?? []).filter((p) => null !== p.calendarEntryId).map((p) => [p.id, p.calendarEntryId as string]));
  // P4-173 — péremption servie PAR PLAN (le backend a déjà écarté le passé et le non-pointé).
  const stalenessByPlan = new Map((plans ?? []).map((p) => [p.id, p.staleness]));
  const rows = seasonPlannings(schedules, me?.seasonPlan?.name ?? null, plans ?? [], entries, schedulesResolved);
  // Suppression d'un planning SECONDAIRE : jamais le socle, jamais en saison archivée
  // (409 SeasonReadonly — revue B2 F2), jamais une version en vol (la cascade
  // emporterait le solve en cours). Entrée de calendrier de son plan comme cible.
  const workingSeason = useWorkingSeason();
  const isReadonly = true === workingSeason?.isReadonly;
  // Période d'un planning : le principal couvre TOUTE la saison ; un overlay couvre
  // les dates de son entrée calendrier (plan → calendarEntryId → entrée). null quand
  // l'info n'est pas résolue (saison non chargée / entrée absente) → ligne masquée.
  const periodLabel = (row: PlanningRow): string | null => {
    const range = (a: string, b: string): string => `${frDateNumeric(a)} → ${frDateNumeric(b)}`;
    if (!row.isOverlay) {
      return workingSeason ? range(workingSeason.startDate, workingSeason.endDate) : null;
    }
    const entryId = null !== row.schedulePlanId ? (entryByPlan.get(row.schedulePlanId) ?? null) : null;
    const entry = null !== entryId ? entries.find((e) => e.id === entryId) : undefined;
    return entry ? range(entry.startDate, entry.endDate) : null;
  };
  // ADR-0002 : une LIGNE de cette liste EST un PLAN. L'état affiché est donc celui du
  // PLAN — pointe-t-il une version (validé/en vigueur) ? —, pas le statut BRUT de la
  // version : sans quoi un plan validé et un plan terminé-non-validé, tous deux
  // COMPLETED, se lisaient à l'identique (« Terminé »). Vocabulaire aligné sur la
  // toolbar (« validé » / « en vigueur »).
  const stateLabel = (row: PlanningRow): string => {
    if (row.isChosen) {
      return "Validé";
    }
    if (row.isOpen) {
      return `${STATUS_LABELS[row.status]} · en cours`;
    }
    return "COMPLETED" === row.status ? "Terminé · à valider" : STATUS_LABELS[row.status];
  };
  const deletableEntryId = (row: PlanningRow): string | null => {
    if (!row.isOverlay || isReadonly || "PENDING" === row.status || "GENERATING" === row.status || null === row.schedulePlanId) {
      return null;
    }
    return entryByPlan.get(row.schedulePlanId) ?? null;
  };

  // ADR-0002 — UNE règle de routage, alignée sur l'état du PLAN (pointe-t-il une
  // version ?) et non sur `isOverlay || isChosen` (deux vérités mêlées) :
  //  - plan POINTÉ (en vigueur) → /planning, consultation lecture seule ;
  //  - plan NON pointé → wizard, mode DÉCLARÉ (jamais l'ambiant du localStorage) :
  //    un overlay ancre le mode période sur SON entrée, le socle repasse en mode
  //    saison ; les deux atterrissent sur l'étape Génération pour finir/valider.
  // Le mode est PERSISTÉ (localStorage) : sans le déclarer, « reprendre » ouvrirait
  // le wizard sur le mode laissé par un autre écran — la saison à la place de
  // l'adaptation, ou la mauvaise période (revue #260 round 1, bug fondateur 2026-08-19).
  // On purge AUSSI la sélection planning : une sélection laissée ailleurs ne doit pas
  // s'afficher dans l'étape Génération. En portée période, PlanningPage atterrit sur la
  // période (`scopePlanId`) et ne retombe jamais sur la saison.
  const consult = (row: PlanningRow) => {
    if (row.isChosen) {
      setSelectedScheduleId(row.id);
      navigate("/planning");
      return;
    }
    setSelectedScheduleId(null);
    if (row.isOverlay) {
      const entryId = null !== row.schedulePlanId ? (entryByPlan.get(row.schedulePlanId) ?? null) : null;
      if (null === entryId) {
        return; // plans pas encore chargés — le bouton est désactivé dans ce cas
      }
      useWizardStore.getState().startPeriodMode(entryId);
    } else {
      useWizardStore.getState().exitPeriodMode();
    }
    useWizardStore.getState().jumpTo("generate");
    onClose();
    navigate("/wizard");
  };

  return (
    <Modal label="Plannings de la saison" title="Plannings de la saison" onClose={onClose} size="lg">
      <ul className="mt-4 space-y-2">
        {rows.map((row) => {
          const resumeBlocked = row.isOverlay && row.isOpen && (null === row.schedulePlanId || !entryByPlan.has(row.schedulePlanId));
          const period = periodLabel(row);
          return (
            <li key={row.id} className="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2">
              <div className="min-w-0">
                <p className="flex items-center gap-1.5 truncate text-sm font-medium">
                  {/* ★ = LE planning principal (le plan de la saison), par opposition aux
                      plannings secondaires de période. Ce n'est pas « la version choisie ». */}
                  {row.isOverlay ? null : <Star className="size-3.5 fill-accent text-accent" />}
                  {row.label}
                </p>
                {period ? <p className="truncate text-xs text-muted-foreground">{period}</p> : null}
                <div className="flex flex-wrap items-center gap-1.5">
                  <span className="text-xs text-muted-foreground">{stateLabel(row)}</span>
                  <StalenessPill staleness={null !== row.schedulePlanId ? (stalenessByPlan.get(row.schedulePlanId) ?? null) : null} />
                </div>
              </div>
              <div className="flex shrink-0 items-center gap-1">
                {row.isOpen ? (
                  <Button variant="ghost" size="icon" className="size-8" aria-label={`Reprendre ${row.label}`} title="Reprendre" disabled={resumeBlocked} onClick={() => consult(row)}>
                    <Pencil className="size-4" />
                  </Button>
                ) : (
                  <>
                    <Button variant="ghost" size="icon" className="size-8" aria-label={`Consulter ${row.label}`} title="Consulter" onClick={() => consult(row)}>
                      <Eye className="size-4" />
                    </Button>
                    <CompactExport scheduleId={row.id} label={row.label} />
                  </>
                )}
                {/* Suppression : plannings SECONDAIRES uniquement (jamais le socle). */}
                {deletableEntryId(row) ? <DeletePlanningButton calendarEntryId={deletableEntryId(row) as string} schedulePlanId={row.schedulePlanId} title={row.label} iconOnly /> : null}
              </div>
            </li>
          );
        })}
      </ul>
    </Modal>
  );
}
