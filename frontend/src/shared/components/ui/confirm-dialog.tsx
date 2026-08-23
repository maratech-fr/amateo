import { type ReactNode, useId, useRef, useState } from "react";
import { createPortal } from "react-dom";

import { Button } from "@/shared/components/ui/button";
import { MODAL_WIDTH } from "@/shared/components/ui/modal";
import { useModalA11y } from "@/shared/lib/useModalA11y";
import { cn } from "@/shared/lib/utils";

interface ConfirmDialogProps {
  open: boolean;
  title: string;
  description?: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  destructive?: boolean;
  confirmDisabled?: boolean;
  /**
   * Si fournie, le bouton de confirmation reste désactivé tant que l'utilisateur
   * n'a pas tapé cette phrase EXACTE (comparaison trim + insensible à la casse)
   * dans un champ dédié. Garde-fou pour un geste lourd et irréversible.
   */
  confirmPhrase?: string;
  onConfirm: () => void;
  onCancel: () => void;
}

/**
 * Minimal accessible confirmation modal (no dependency): portal + overlay,
 * role="dialog"/aria-modal, Escape + overlay-click cancel, focus the confirm
 * button on open, and a simple Tab focus-trap. Used for destructive actions
 * (delete venue/constraint, reset club).
 */
export function ConfirmDialog({
  open,
  title,
  description,
  confirmLabel = "Confirmer",
  cancelLabel = "Annuler",
  destructive = true,
  confirmDisabled = false,
  confirmPhrase,
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  const titleId = useId();
  const phraseInputId = useId();
  const panelRef = useRef<HTMLDivElement>(null);
  const [phraseInput, setPhraseInput] = useState("");
  const [prevOpen, setPrevOpen] = useState(open);
  // Shared focus-trap + initial focus + focus restoration + Escape (WCAG 2.1.2 / 2.4.3).
  useModalA11y({ ref: panelRef, onClose: onCancel, active: open });

  // Réinitialiser la saisie à chaque bascule ouvert/fermé : une réouverture repart d'un
  // champ vide, jamais de la phrase déjà validée d'une session précédente. Pattern React
  // « ajuster l'état pendant le rendu au changement de prop » (pas d'effet, pas de cascade).
  if (open !== prevOpen) {
    setPrevOpen(open);
    setPhraseInput("");
  }

  if (!open) {
    return null;
  }

  const phraseSatisfied =
    undefined === confirmPhrase || phraseInput.trim().toLowerCase() === confirmPhrase.trim().toLowerCase();
  const confirmBlocked = confirmDisabled || !phraseSatisfied;

  // ⚠ Hauteur bornée + contenu défilant : MÊME défaut, MÊME correctif que `modal.tsx`, dont
  // le docblock porte le pourquoi complet. Les deux panneaux sont deux copies du même markup
  // (motif « une vérité, deux endroits ») : toucher l'un sans l'autre laisserait la moitié
  // des modales cassée.
  // ⚠ La LARGEUR fait exception à cette duplication : elle est lue dans `MODAL_WIDTH`
  // (palier `sm`), maison unique de l'échelle — deux copies du markup, une seule échelle.
  // Ici les BOUTONS restent HORS de la zone défilante — une confirmation dont on ne voit
  // plus « Confirmer » est pire qu'une modale trop longue.
  return createPortal(
    <div className="fixed inset-0 z-[90] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50" aria-hidden="true" onClick={onCancel} />
      <div
        ref={panelRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className={cn("relative flex max-h-[calc(100dvh-2rem)] w-full flex-col rounded-lg border border-border bg-card p-6 text-card-foreground shadow-xl", MODAL_WIDTH.sm)}
      >
        <h2 id={titleId} className="shrink-0 text-lg font-semibold">
          {title}
        </h2>
        <div className="min-h-0 overflow-y-auto">
          {description ? <div className="mt-2 text-sm text-muted-foreground">{description}</div> : null}
          {undefined === confirmPhrase ? null : (
            <div className="mt-4">
              <label htmlFor={phraseInputId} className="block text-sm text-muted-foreground">
                Tapez «&nbsp;{confirmPhrase}&nbsp;» pour confirmer
              </label>
              <input
                id={phraseInputId}
                type="text"
                autoComplete="off"
                value={phraseInput}
                onChange={(event) => setPhraseInput(event.target.value)}
                className="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
              />
            </div>
          )}
        </div>
        {/* Même filet que le pied ÉPINGLÉ de `Modal` (P4-127 d) : bordure + `pt-4`, pour que les
            deux panneaux jumeaux restent jumeaux. Les boutons vivaient déjà HORS de la zone
            défilante ; seule la ligne de séparation est ajoutée. */}
        <div className="mt-4 flex shrink-0 flex-wrap justify-end gap-2 border-t border-border pt-4">
          <Button variant="ghost" onClick={onCancel}>
            {cancelLabel}
          </Button>
          <Button variant={destructive ? "destructive" : "default"} disabled={confirmBlocked} onClick={onConfirm}>
            {confirmLabel}
          </Button>
        </div>
      </div>
    </div>,
    document.body,
  );
}
