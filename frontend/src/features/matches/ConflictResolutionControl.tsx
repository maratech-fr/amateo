import { ChevronDown, MessageSquareText, RotateCcw } from "lucide-react";
import { type ReactNode, useEffect, useId, useRef, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Label } from "@/shared/components/ui/label";
import { Menu, MenuItem } from "@/shared/components/ui/menu";
import { Spinner } from "@/shared/components/ui/spinner";
import { frDateShortNoYear } from "@/shared/lib/date";
import { cn } from "@/shared/lib/utils";

import type { Coach, Conflict, ConflictResolutionStatus, Team, Venue } from "./api";
import { ConflictLine } from "./ConflictLine";
import type { DiagnosticGroup } from "./lib/diagnostic";
import { RESOLUTION_LABEL, resolutionChoicesFor } from "./lib/conflictResolution";
import { useClearConflictResolution, useSetConflictResolution } from "./queries";

/**
 * P4-207 — le CONTRÔLE de traitement d'un conflit, greffé sur `ConflictLine`. Il
 * compose la ligne (le radar ET l'onglet Conflits le CONSOMMENT via `renderConflict`)
 * en posant, dans le slot `trailing`, la pastille d'état / l'éditeur, et, dans le slot
 * `below`, la note ; il porte `aria-busy` sur le `<li>` pendant une écriture.
 *
 * Trois régimes :
 *  - **membre** (`canManage` false) : pastille en LECTURE (aucun menu) + note lisible ;
 *    un conflit « à traiter » n'affiche RIEN (pas de pastille).
 *  - **gestionnaire, empreinte présente** : la pastille EST le déclencheur d'un menu APG
 *    (`Menu`) ; « à traiter » montre un bouton « Traiter » (menu des 3 statuts). Choisir
 *    un statut écrit immédiatement (PUT), jamais de modale. La note s'édite depuis le
 *    menu (« Modifier la note… ») ou le bouton « Note ».
 *  - **gestionnaire sans empreinte** : aucune écriture possible (l'empreinte EST la clé)
 *    → dégradé en lecture, comme un membre.
 *
 * PRÉSENTATION + geste : le backend reste souverain (403 côté serveur) ; `canManage` ne
 * fait que MASQUER un geste voué au refus (🔴 `.claude/rules/frontend.md`).
 */
interface ConflictResolutionControlProps {
  conflict: Conflict;
  teams: Map<string, Team>;
  coaches: Map<string, Coach>;
  /** Les gymnases — transmis à `ConflictLine` pour le détail par côté (lieu d'un entraînement). */
  venues: Map<string, Venue>;
  tone: DiagnosticGroup["tone"];
  isNew: boolean;
  /** Le membre peut-il écrire ? (affichage seul — le serveur reste juge.) */
  canManage: boolean;
  /** Contenu additionnel APRÈS la pastille/éditeur (« Voir la semaine » de l'onglet Conflits). */
  extraTrailing?: ReactNode;
}

const ICON_TONE: Record<"warning" | "accent" | "neutral", string> = {
  warning: "text-warning",
  accent: "text-accent",
  neutral: "text-muted-foreground",
};

export function ConflictResolutionControl({ conflict, teams, coaches, venues, tone, isNew, canManage, extraTrailing }: ConflictResolutionControlProps) {
  const setResolution = useSetConflictResolution();
  const clearResolution = useClearConflictResolution();
  const busy = setResolution.isPending || clearResolution.isPending;

  // Le backend sert toujours le champ ; on tolère l'absence (undefined) en la ramenant
  // à `null` pour ne jamais lire `.status` sur un « à traiter ».
  const resolution = conflict.resolution ?? null;
  const fingerprint = conflict.fingerprint;
  const editable = canManage && undefined !== fingerprint;
  // Les statuts proposés : les 3 de base + les 2 « joue/coache » quand un côté servi porte PLAYER.
  const choices = resolutionChoicesFor(conflict);

  const noteFieldId = useId();
  const noteButtonRef = useRef<HTMLButtonElement>(null);
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const traiteTriggerRef = useRef<HTMLButtonElement>(null);

  const [noteOpen, setNoteOpen] = useState(false);
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState("");
  const [confirmOpen, setConfirmOpen] = useState(false);
  // Drapeau NON réactif (ref) : « une remise à traiter vient de réussir, rends le focus
  // au « Traiter » qui remplacera la pastille au prochain rendu ». Un état déclencherait
  // un setState-dans-effet (interdit) sans rien changer à l'écran.
  const justClearedRef = useRef(false);

  // L'éditeur de note s'ouvre → focus le champ (WCAG 2.4.3, patron modale).
  useEffect(() => {
    if (editing) {
      textareaRef.current?.focus();
    }
  }, [editing]);

  // « Remettre à traiter » : quand le refetch ramène le conflit à `null`, la pastille
  // devient le bouton « Traiter » AU MÊME ENDROIT — on lui rend le focus (le déclencheur
  // précédent a été démonté, donc le refocus du Menu ne suffit pas). WCAG 2.4.3.
  useEffect(() => {
    if (justClearedRef.current && null === resolution) {
      justClearedRef.current = false;
      traiteTriggerRef.current?.focus();
    }
  }, [resolution]);

  const hasNote = null != resolution?.note && "" !== resolution.note;
  const noteText = resolution?.note ?? "";

  const chooseStatus = (status: ConflictResolutionStatus): void => {
    if (undefined === fingerprint) {
      return;
    }
    // PUT REMPLACE la note : en ne changeant QUE le statut, on resservit la note existante.
    setResolution.mutate({ fingerprint, status, note: resolution?.note ?? undefined });
  };

  const openNoteEditor = (): void => {
    setDraft(noteText);
    setNoteOpen(true);
    setEditing(true);
  };

  const saveNote = (): void => {
    if (undefined === fingerprint || null === resolution) {
      return;
    }
    // Statut inchangé, seule la note bouge.
    setResolution.mutate({ fingerprint, status: resolution.status, note: draft }, { onSuccess: () => setEditing(false) });
  };

  const cancelEdit = (): void => {
    setEditing(false);
    noteButtonRef.current?.focus();
  };

  const onEditorKeyDown = (event: React.KeyboardEvent<HTMLTextAreaElement>): void => {
    if ("Enter" === event.key && (event.ctrlKey || event.metaKey)) {
      event.preventDefault();
      saveNote();
    } else if ("Escape" === event.key) {
      event.preventDefault();
      cancelEdit();
    }
  };

  const resetToTreat = (): void => {
    if (undefined === fingerprint) {
      return;
    }
    if (null != resolution?.note && "" !== resolution.note.trim()) {
      setConfirmOpen(true);
    } else {
      clearResolution.mutate(fingerprint, { onSuccess: () => { justClearedRef.current = true; } });
    }
  };

  const confirmReset = (): void => {
    setConfirmOpen(false);
    if (undefined !== fingerprint) {
      clearResolution.mutate(fingerprint, { onSuccess: () => { justClearedRef.current = true; } });
    }
  };

  // ── La pastille (partagée : déclencheur de menu, état occupé, lecture membre) ──────
  const shortDate = null === resolution ? "" : frDateShortNoYear(resolution.updatedAt.slice(0, 10));
  const pill = (opts: { showSpinner?: boolean } = {}): ReactNode => {
    if (null === resolution) {
      return null;
    }
    const meta = RESOLUTION_LABEL[resolution.status];
    const Icon = meta.icon;
    return (
      <StatusPill
        variant={meta.variant}
        icon={true === opts.showSpinner ? <Spinner className="size-3" /> : <Icon className={cn("size-3", ICON_TONE[meta.variant])} aria-hidden="true" />}
      >
        {meta.label} <span className="text-muted-foreground tabular-nums">· {shortDate}</span>
      </StatusPill>
    );
  };

  const triggerAccessibleName =
    null === resolution ? "Traiter le conflit" : `Statut de traitement : ${RESOLUTION_LABEL[resolution.status].label} · ${shortDate}, modifier`;

  // ── Le déclencheur / la pastille (slot trailing avant « Voir la semaine ») ────────
  let chip: ReactNode = null;
  if (null !== resolution) {
    if (!editable || busy) {
      // Lecture (membre / sans empreinte), ou état occupé : pastille figée + éventuel spinner.
      chip = pill({ showSpinner: busy });
    } else {
      chip = (
        <Menu label={triggerAccessibleName} triggerClassName="rounded-full p-0.5" trigger={pill()}>
          {choices.map((status) => {
            const meta = RESOLUTION_LABEL[status];
            const current = status === resolution.status;
            const ItemIcon = meta.icon;
            return (
              <MenuItem key={status} disabled={current} icon={<ItemIcon />} onSelect={() => chooseStatus(status)}>
                {meta.label}
              </MenuItem>
            );
          })}
          <div role="separator" className="my-1 border-t border-border" />
          <MenuItem icon={<MessageSquareText />} onSelect={openNoteEditor}>
            Modifier la note…
          </MenuItem>
          <MenuItem icon={<RotateCcw />} onSelect={resetToTreat}>
            Remettre à traiter
          </MenuItem>
        </Menu>
      );
    }
  } else if (editable) {
    // « À traiter » (gestionnaire) : bouton « Traiter » ouvrant les 3 statuts.
    chip = busy ? (
      <Button variant="outline" size="sm" disabled>
        <Spinner className="size-4" />
        Traiter
      </Button>
    ) : (
      <Menu
        label="Traiter le conflit"
        triggerRef={traiteTriggerRef}
        triggerClassName="h-9 gap-1 border border-border px-3 text-sm text-foreground"
        trigger={
          <span className="flex items-center gap-1">
            Traiter
            <ChevronDown className="size-4" aria-hidden="true" />
          </span>
        }
      >
        {choices.map((status) => {
          const meta = RESOLUTION_LABEL[status];
          const ItemIcon = meta.icon;
          return (
            <MenuItem key={status} icon={<ItemIcon />} onSelect={() => chooseStatus(status)}>
              {meta.label}
            </MenuItem>
          );
        })}
      </Menu>
    );
  }

  const hasTrailing = null !== chip || undefined !== extraTrailing;
  // Décision fondateur 2026-09-17 : les deux actions s'EMPILENT toujours — « Traiter »
  // (ou la pastille) AU-DESSUS, « Voir la semaine » EN DESSOUS — alignées à droite et de
  // même largeur, quelle que soit la longueur de la ligne (l'ancien `flex-wrap` les
  // empilait de façon incohérente selon la place restante).
  const trailing = hasTrailing ? (
    <div className="flex flex-col items-stretch gap-2">
      {chip}
      {extraTrailing}
    </div>
  ) : undefined;

  // ── La note (slot below : pleine largeur sous la ligne) ──────────────────────────
  const showNoteButton = hasNote || editing;
  const expanded = editing || noteOpen;
  const toggleNote = (): void => {
    if (expanded) {
      setNoteOpen(false);
      setEditing(false);
    } else {
      setNoteOpen(true);
    }
  };
  const below = showNoteButton ? (
    <div className="flex flex-col gap-2">
      <button
        ref={noteButtonRef}
        type="button"
        aria-expanded={expanded}
        onClick={toggleNote}
        className="inline-flex w-fit items-center gap-1.5 rounded-md px-1 py-0.5 text-sm text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring [&_svg]:size-4"
      >
        <MessageSquareText aria-hidden="true" />
        Note
      </button>
      {editing ? (
        <div className="flex flex-col gap-1">
          <Label htmlFor={noteFieldId}>Note (facultative)</Label>
          <textarea
            id={noteFieldId}
            ref={textareaRef}
            rows={3}
            maxLength={500}
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            onKeyDown={onEditorKeyDown}
            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
          />
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs text-muted-foreground tabular-nums">{draft.length}/500</span>
            <div className="flex gap-2">
              <Button variant="ghost" size="sm" onClick={cancelEdit}>
                Annuler
              </Button>
              <Button variant="default" size="sm" disabled={busy} onClick={saveNote}>
                Enregistrer
              </Button>
            </div>
          </div>
        </div>
      ) : expanded && hasNote ? (
        <p className="whitespace-pre-wrap text-sm text-muted-foreground">{noteText}</p>
      ) : null}
    </div>
  ) : undefined;

  return (
    <>
      <ConflictLine conflict={conflict} teams={teams} coaches={coaches} venues={venues} tone={tone} isNew={isNew} trailing={trailing} below={below} ariaBusy={busy} />
      <ConfirmDialog
        open={confirmOpen}
        title="Remettre à traiter ?"
        description="Remettre à traiter effacera la note."
        confirmLabel="Remettre à traiter"
        destructive
        onConfirm={confirmReset}
        onCancel={() => setConfirmOpen(false)}
      />
    </>
  );
}
