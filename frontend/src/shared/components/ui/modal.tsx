import { X } from "lucide-react";
import { type ReactNode, useRef } from "react";
import { createPortal } from "react-dom";

import { MODAL_WIDTH } from "@/shared/components/ui/modal-width";
import { useModalA11y } from "@/shared/lib/useModalA11y";
import { cn } from "@/shared/lib/utils";

/** Les quatre paliers de largeur d'une modale — voir `MODAL_WIDTH`. */
export type ModalSize = "sm" | "md" | "lg" | "xl";

interface ModalProps {
  label: string;
  title: ReactNode;
  onClose: () => void;
  children: ReactNode;
  /** Palier de largeur — `md` par défaut. Voir `MODAL_WIDTH` pour le choix du palier. */
  size?: ModalSize;
  /**
   * Rangée d'actions ÉPINGLÉE, rendue HORS de la zone défilante (P4-127 d).
   *
   * ⚑ Le pourquoi : jusqu'ici les rangées d'actions vivaient DANS `overflow-y-auto` et
   * défilaient avec le contenu — sur une modale longue, « Enregistrer » / « Valider »
   * sortait du champ, il fallait faire défiler jusqu'en bas pour agir. Le pied résout ça
   * une fois pour toutes : il reste visible pendant que le corps défile. Il n'est rendu que
   * si le slot est fourni ; l'espacement (`mt-4`, la bordure `border-t`, le `pt-4`) vit ICI,
   * une seule fois — les appelants ne portent plus leur `mt-6 flex justify-end gap-2`.
   *
   * ⚑ Règle de migration (mécanique) : le slot reçoit **les actions et le microcopy qui
   * QUALIFIE une action** (raison de désactivation d'un bouton, spinner d'attente) ; tout ce
   * qui décrit la **CONSÉQUENCE** du geste reste dans le corps, défilant. Garde-fou : jamais
   * de bloc de prose dans le pied — il est `shrink-0`, il mangerait le viewport du contenu.
   *
   * ⚠ Risque clavier CRÉÉ par la sortie des actions du flux défilant : une modale longue peut
   * n'avoir plus AUCUN focusable dans la zone défilante, la rendant indéfilable au clavier sur
   * les navigateurs qui ne rendent pas les conteneurs scrollables focusables. On s'appuie sur
   * le comportement moderne (Chrome ≥127 / Firefox rendent ces conteneurs focusables) et on le
   * PROUVE par un témoin e2e (`tests/e2e/modal-reachability.spec.ts` — « atteignable au
   * clavier »). Si ce témoin échoue un jour, repli : `tabIndex={0}` + `aria-label` sur la zone
   * de scroll.
   */
  footer?: ReactNode;
}

/**
 * Minimal portal modal: overlay + Escape/overlay-click close + a titled panel. Shared by the cockpit dialogs.
 *
 * ⚠ **Le panneau est BORNÉ en hauteur et son contenu défile — ne retirez pas ces classes.**
 * Sans elles, un panneau plus haut que l'écran débordait **en haut ET en bas** (le conteneur
 * centre en `items-center`) et rien ne permettait d'atteindre le contenu coupé : le seul
 * recours était de dézoomer le navigateur. Constaté sur la modale d'actions superadmin, sur
 * PC (retour fondateur 2026-08-11). C'est aussi un manquement WCAG 1.4.10 (reflow) — un
 * contenu hors viewport sans moyen de le faire défiler n'est pas atteignable.
 *
 * ⚑ Le défaut était visible avant d'être signalé : trois écrans s'étaient bricolé leur
 * propre rustine (`max-h-[60vh]`, `max-h-[24rem]`, `max-h-[55vh]` — trois valeurs
 * arbitraires). Quand chaque appelant rafistole dans son coin, c'est que le comportement
 * manque en amont ; elles ont été retirées avec ce correctif.
 *
 * `dvh` et non `vh` : sur mobile `vh` ignore la barre d'adresse et reproduit le débordement.
 *
 * L'en-tête reste visible (`shrink-0`), seul le contenu défile. `min-h-0` est obligatoire —
 * un enfant flex refuse de rétrécir sous sa taille de contenu sans lui, et `overflow-y-auto`
 * n'aurait alors rien à faire défiler.
 */
export function Modal({ label, title, onClose, children, size = "md", footer }: ModalProps) {
  const panelRef = useRef<HTMLDivElement>(null);
  // Focus-trap + initial focus + focus restoration + Escape (WCAG 2.1.2 / 2.4.3).
  useModalA11y({ ref: panelRef, onClose });

  return createPortal(
    <div className="fixed inset-0 z-[90] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50" aria-hidden="true" onClick={onClose} />
      <div
        ref={panelRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={label}
        className={cn("relative flex max-h-[calc(100dvh-2rem)] w-full flex-col rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xl", MODAL_WIDTH[size])}
      >
        <div className="flex shrink-0 items-center justify-between">
          <h2 className="text-base font-semibold">{title}</h2>
          <button type="button" aria-label="Fermer" className="rounded p-1 text-muted-foreground hover:text-foreground" onClick={onClose}>
            <X className="size-4" />
          </button>
        </div>
        <div className="min-h-0 overflow-y-auto">{children}</div>
        {footer ? <footer className="mt-4 flex shrink-0 flex-wrap justify-end gap-2 border-t border-border pt-4">{footer}</footer> : null}
      </div>
    </div>,
    document.body,
  );
}
