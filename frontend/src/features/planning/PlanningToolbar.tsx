import { CheckCircle2, History, Lock, LockOpen, RefreshCw, Trash2 } from "lucide-react";
import { type ReactNode, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { DeleteConfirm } from "@/shared/components/ui/delete-confirm";
import { Select } from "@/shared/components/ui/select";
import { cn } from "@/shared/lib/utils";

import { STATUS_LABELS, type Schedule } from "./api";
import { isSeasonPlanType, liveContextScheduleId, overlayVersionLabels, versionLabels, visibleOverlayVersions, visibleSeasonPlans } from "./lib/versions";
import type { ViewMode } from "./store";

const VIEWS: { key: ViewMode; label: string }[] = [
  { key: "gymnase", label: "Par gymnase" },
  { key: "coach", label: "Par coach" },
  { key: "equipe", label: "Par équipe" },
  // P2-33 : 4e vue — tous les gymnases, filtrés sur le/les jour(s) de la semaine.
  { key: "jour", label: "Par jour" },
  // P3-20 : 5e vue — la matrice équipes × jours, jusque-là réservée aux exports PDF/XLS.
  { key: "club", label: "Par club" },
];

interface PlanningToolbarProps {
  schedules: Schedule[];
  selectedScheduleId: string | null;
  onSelectSchedule: (id: string) => void;
  viewMode: ViewMode;
  onViewMode: (mode: ViewMode) => void;
  onRegenerate: () => void;
  onValidate: () => void;
  onReopen: () => void;
  onDelete: () => void;
  onRegenerateFrom: () => void;
  disableRegenerate?: boolean;
  /** P1-3 §4bis pt 2 — solde de crédits sur « Régénérer » (Découverte bridée) ;
   *  null = offre non bridée (payant/bêta/démo) : ni suffixe, ni blocage. La page
   *  le calcule (`useCredits`) pour garder ce composant pur (test sans provider). */
  outputCredits?: { count: number; blocked: boolean } | null;
  isGenerating: boolean;
  actionBusy: boolean;
  /** Export, rendered right-aligned on the actions row (owned by the page). */
  rightSlot?: ReactNode;
  /** Resource filter, rendered next to the view switcher on row 1 — its label mirrors the
   *  current view mode, so the two belong together (P4-43). Owned by the page. */
  filterSlot?: ReactNode;
  /** Wizard-embedded (generation step) vs standalone /planning. Standalone /planning is
   *  the screen of the version IN FORCE: it carries the status badge and « Rouvrir »
   *  (which returns to the wizard's generation step). The work gestures — version
   *  selector, Valider, Régénérer, Supprimer — live only in the embedded wizard toolbar;
   *  Valider is that workspace's exit and lands on /planning. */
  embedded?: boolean;
  /** Portée d'affichage (bug fondateur 2026-08-19) : non-null ⇒ le sélecteur ne liste QUE
   *  les versions de ce plan de période (jamais le socle), et l'étoile suit sa lignée. */
  scopePlanId?: string | null;
}

/**
 * planning-versions: the selector lists the WORK VERSIONS of the season plan
 * ("V3 — 10 juil. 14:32", newest last), never named schedules — the plan's
 * NAME lives in the page header (on the plan itself). Versions are not
 * renamable; a version can be deleted (workspace) behind a DeleteConfirm.
 *
 * « En vigueur » ne se décide pas ici : c'est le plan qui POINTE une version, et
 * seul « Valider » déplace ce pointeur — il n'y a pas d'action « définir principal »
 * (rien ne se pointe automatiquement non plus). Deux lignes : (1) version + état +
 * mode d'affichage + filtre de ressources, (2) actions de génération + export.
 */
export function PlanningToolbar({
  schedules,
  selectedScheduleId,
  onSelectSchedule,
  viewMode,
  onViewMode,
  onRegenerate,
  onValidate,
  onReopen,
  onDelete,
  onRegenerateFrom,
  disableRegenerate = false,
  outputCredits = null,
  isGenerating,
  actionBusy,
  rightSlot,
  filterSlot,
  embedded = false,
  scopePlanId = null,
}: PlanningToolbarProps) {
  const selected = schedules.find((s) => s.id === selectedScheduleId) ?? null;
  // ADR-0002 : « en vigueur » = le plan de CETTE version la pointe — vrai pour le
  // calendrier de la saison comme pour l'overlay d'une période, dont le pointeur vit
  // sur le plan de sa période. Une seule question, une seule réponse : rejouer la
  // comparaison contre le pointeur de /api/me remettrait deux vérités en présence.
  const isChosen = true === selected?.isChosen;
  const isOverlay = null !== selected && !isSeasonPlanType(selected.planType);
  // Portée imposée (écran embarqué scopé au plan de période) : le sélecteur ne liste
  // QUE ses versions, et non le socle (bug fondateur 2026-08-19). Sans portée, l'ancien
  // comportement — socle + les versions de la période sélectionnée si on en regarde une.
  const scoped = null !== scopePlanId;
  const overlayId = scoped ? scopePlanId : isOverlay && null !== selected?.schedulePlanId ? selected.schedulePlanId : null;
  // ★ = the version whose structure is the currently LOADED context. Season plans:
  // the server pointer (seasonLiveContextId, with a latest-visible fallback for a
  // NULL/stale pointer). Overlays: the latest version of the period (derived).
  // Exactly ONE ★: in overlay context only the period's overlay is starred (never
  // a season plan too), else only the season live-context plan.
  const seasonLiveId = liveContextScheduleId(schedules, null);
  const overlayLiveId = null !== overlayId ? liveContextScheduleId(schedules, overlayId) : null;
  const isStarred = (schedule: Schedule): boolean =>
    scoped
      ? schedule.id === overlayLiveId
      : isOverlay
        ? !isSeasonPlanType(schedule.planType) && schedule.id === overlayLiveId
        : isSeasonPlanType(schedule.planType) && schedule.id === seasonLiveId;
  const [confirmDelete, setConfirmDelete] = useState(false);

  const labels = versionLabels(schedules);
  // The period's versions get their own V{n} labels (scope, or a selected overlay).
  const overlayLabels = null !== overlayId ? overlayVersionLabels(schedules, overlayId) : null;
  const labelOf = (schedule: Schedule): string => overlayLabels?.get(schedule.id) ?? labels.get(schedule.id) ?? schedule.name;
  // En portée : SEULES les versions de la période ; sinon socle + versions de l'overlay regardé.
  const versionOptions: Schedule[] = scoped
    ? visibleOverlayVersions(schedules, scopePlanId)
    : [...visibleSeasonPlans(schedules), ...(null !== overlayId ? visibleOverlayVersions(schedules, overlayId) : [])];
  // P2-8 : les permissions viennent du SERVEUR (`capabilities`, même code que les
  // gardes d'écriture) — le front ne re-dérive plus « supprimable / validable /
  // rechargeable ». Fail-closed : un geste ne s'offre que sur `=== true` (cache
  // périmé ou réponse d'écriture → capabilities absent → geste NON offert).
  const canDelete = true === selected?.capabilities?.canDelete;
  // `canValidate` porte à lui seul « terminée ET aucune sœur en vol » (le serveur
  // refuse la validation entière tant qu'une sœur solve). `isChosen` reste un choix
  // d'UI (la version en vigueur montre « Rouvrir », pas « Valider » — geste no-op).
  const canValidate = true === selected?.capabilities?.canValidate;
  const canRegenerateFrom = true === selected?.capabilities?.canRegenerateFrom;
  // Reloading the version that IS the live context (★) is a no-op when its
  // snapshot already matches the current club structure — keep the button
  // visible but greyed with a reason, so the state reads as deliberate. The
  // page computes the actual comparison (snapshot hash vs current structure hash).
  const isLiveContext = null !== selected && isSeasonPlanType(selected.planType) && selected.id === seasonLiveId;

  return (
    <div className="flex w-full flex-col gap-2">
      {/* Row 1 — which version, its state, and how to view it. Standalone /planning is
          the screen of the version in force: it hides the version SELECTOR (a work
          gesture, embedded-only) but keeps the status badge. Version management —
          selecting, validating, regenerating, deleting — lives in the wizard's
          generation step (embedded). */}
      <div className="flex flex-wrap items-center gap-2">
        {embedded ? (
          <Select
            aria-label="Version du planning"
            value={selectedScheduleId ?? ""}
            onChange={(event) => onSelectSchedule(event.target.value)}
            className="h-8"
          >
            {/* Season versions, plus — when an overlay is selected — that period's own
                overlay versions (V1, V2…). The ★ marks the LOADED context (the version
                whose structure is live), NOT the one being viewed: it stays put when you
                consult an older version, and "Charger cette version" moves it. No
                "principal" here: the main plan is a fact carried by the title badge. */}
            {versionOptions.map((schedule) => (
              <option key={schedule.id} value={schedule.id}>
                {labelOf(schedule)}
                {isStarred(schedule) ? " ★" : ""}
                {true === schedule.isChosen ? " · en vigueur" : ""}
                {/* Hors portée seulement : en portée, tout est « période », le préciser sur
                    chaque ligne serait du bruit (le titre nomme déjà la période). */}
                {!scoped && !isSeasonPlanType(schedule.planType) ? " · période" : ""}
              </option>
            ))}
          </Select>
        ) : null}
        {embedded && canDelete ? (
          <Button size="sm" variant="ghost" className="h-8 px-2 text-destructive" disabled={actionBusy} onClick={() => setConfirmDelete(true)} aria-label="Supprimer cette version" title="Supprimer cette version">
            <Trash2 className="size-4" />
          </Button>
        ) : null}
        {null !== selected ? (
          <span className="flex items-center gap-2 text-xs text-muted-foreground">
            <StatusPill icon={isChosen ? <Lock className="size-3" /> : undefined}>{STATUS_LABELS[selected.status]}</StatusPill>
            {!isSeasonPlanType(selected.planType) ? <StatusPill variant="accent">Période</StatusPill> : null}
          </span>
        ) : null}
        {embedded && canValidate && !isChosen ? (
          // Choosing a version the plan ALREADY points at is a no-op: the status
          // used to hide this (le statut « validé » n'était pas COMPLETED) ; seul le
          // pointeur dit « en vigueur » désormais, donc on le lui demande directement.
          // Borné à `embedded` : Valider est un geste de TRAVAIL et la SORTIE de l'espace
          // de travail — il vit au wizard (où l'on choisit la version), et son succès
          // atterrit sur /planning. Sur /planning autonome (la version en vigueur), c'est
          // « Rouvrir » qui prend le relais, pas Valider.
          <Button size="sm" variant="outline" className="h-8" disabled={actionBusy} onClick={onValidate}>
            <CheckCircle2 className="size-4" />
            Valider
          </Button>
        ) : null}
        {/* Rouvrir est le geste de l'écran /planning (la version EN VIGUEUR) : borné à
            `!embedded`. Valider (embedded) et Rouvrir (standalone) sont les deux sorties
            symétriques — Valider atterrit sur /planning, Rouvrir ramène au wizard étape
            Génération. Dans le wizard, une version en vigueur ne montre donc plus Rouvrir. */}
        {!embedded && isChosen ? (
          <Button size="sm" variant="outline" className="h-8" disabled={actionBusy} onClick={onReopen}>
            <LockOpen className="size-4" />
            Rouvrir
          </Button>
        ) : null}
        {/* Vue et filtre, accolés (P4-43). Le filtre vivait en ligne 2 derrière l'export,
            alors que son libellé SUIT le mode de vue courant (« Par gymnase » → « Gymnases :
            … ») : deux contrôles sur les mêmes trois ressources, à deux endroits éloignés,
            dont le second passait inaperçu. Côte à côte, ils se lisent comme un couple —
            quelle vue, puis quoi dedans. */}
        <div className="ml-auto flex flex-wrap items-center gap-2">
        <div className="flex items-center gap-1 rounded-md border border-border bg-card p-0.5">
          {VIEWS.map((view) => (
            <Button
              key={view.key}
              size="sm"
              variant={view.key === viewMode ? "default" : "ghost"}
              className={cn("h-7", view.key === viewMode ? "" : "text-muted-foreground")}
              onClick={() => onViewMode(view.key)}
            >
              {view.label}
            </Button>
          ))}
        </div>
        {filterSlot}
        </div>
      </div>

      {/* Row 2 — generation actions, with export right-aligned. */}
      <div className="flex flex-wrap items-center gap-2">
        {/* Régénérer est un geste de TRAVAIL : borné à `embedded` — /planning autonome est
            l'écran de la version en vigueur, il ne régénère pas (la génération vit dans
            l'étape Génération du wizard). */}
        {!embedded || isChosen ? null : (
          // Disabled during a "Charger" restore too (actionBusy) — but the busy
          // LABEL/spinner keys only on isGenerating, so a restore (no solve) does
          // not show a misleading "Génération…".
          <>
            <Button
              size="sm"
              variant="default"
              className="h-8"
              disabled={isGenerating || actionBusy || disableRegenerate || null === selectedScheduleId || (null !== outputCredits && outputCredits.blocked)}
              onClick={onRegenerate}
            >
              <RefreshCw className={cn("size-4", isGenerating ? "animate-spin" : "")} />
              {isGenerating ? "Génération…" : `Régénérer${null !== outputCredits ? ` (${outputCredits.count})` : ""}`}
            </Button>
            {/* La garantie ne vivait qu'en commentaire de code (api.ts) : on la DIT ici, contre le
                bouton qui la déclenche, sans modale ni ligne supplémentaire dans la toolbar. */}
            <span className="text-xs text-muted-foreground">Vos créneaux verrouillés sont conservés à la régénération.</span>
          </>
        )}
        {canRegenerateFrom ? (
          <Button
            size="sm"
            variant="ghost"
            className="h-8"
            disabled={actionBusy || isGenerating || isLiveContext}
            onClick={onRegenerateFrom}
            title={isLiveContext ? "Déjà le contexte courant — rien à recharger (utilisez « Régénérer »)" : "Recharge la structure de cette version (sans régénérer) et affiche son planning"}
          >
            <History className="size-4" />
            Charger cette version
          </Button>
        ) : null}
        {rightSlot ? <div className="ml-auto flex items-center gap-2">{rightSlot}</div> : null}
      </div>

      <DeleteConfirm
        open={confirmDelete}
        entityName={selected ? labelOf(selected) : ""}
        onConfirm={() => {
          onDelete();
          setConfirmDelete(false);
        }}
        onCancel={() => setConfirmDelete(false)}
      />
    </div>
  );
}
