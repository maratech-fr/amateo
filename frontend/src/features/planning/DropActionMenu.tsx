import { ArrowRight, ArrowRightLeft, Users } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";

/**
 * Lot 2 — le MENU À 3 CHOIX qui s'interpose quand on DÉPOSE une séance sur une case OCCUPÉE
 * (chemin DÉPLACEMENT seulement, décision fondateur D1/D9). Il remplace l'ouverture automatique de
 * la modale d'éviction : le gestionnaire choisit explicitement ce qu'il veut faire de l'occupant.
 *
 *  - « Prendre sa place » (ÉVICTION, PR 4) : l'occupant part dans les séances à replacer ;
 *  - « Échanger » (PR 5) : les deux séances troquent leurs créneaux, sans perte ;
 *  - « Mutualiser » (rejoindre / fusion) : les deux équipes s'entraînent ENSEMBLE, en une séance commune.
 *
 * Un choix IMPOSSIBLE est DÉSACTIVÉ AVEC SON MOTIF (A11Y-30, prop `disabledReason` du `Button` —
 * focalisable, `title` souris + `aria-describedby` clavier/AT) : le serveur reste seul juge, le motif
 * n'est qu'une affordance pour les impossibilités d'IDENTITÉ que le front connaît (verrou
 * gestionnaire, deux groupes pour « Prendre sa place », équipe des deux côtés / > 10 pour mutualiser).
 * Les refus que seul le serveur calcule (capacité, Σ…) laissent le choix ACTIF et remontent un 422
 * NOMMÉ dans la modale qui suit.
 */
interface DropActionMenuProps {
  open: boolean;
  /** Nom lisible de l'occupant (une équipe, ou « le groupe (N équipes) ») — déjà résolu par la page. */
  occupantName: string;
  /** Motif de désactivation par choix (`undefined` = choix actif). */
  disabled: { takePlace?: string; swap?: string; mutualize?: string };
  onTakePlace: () => void;
  onSwap: () => void;
  onMutualize: () => void;
  onClose: () => void;
}

export function DropActionMenu({ open, occupantName, disabled, onTakePlace, onSwap, onMutualize, onClose }: DropActionMenuProps) {
  if (!open) {
    return null;
  }

  const choices: { label: string; description: string; icon: typeof ArrowRight; reason?: string; onClick: () => void }[] = [
    {
      label: "Prendre sa place",
      description: "L'occupant part dans les séances à replacer.",
      icon: ArrowRight,
      reason: disabled.takePlace,
      onClick: onTakePlace,
    },
    {
      label: "Échanger",
      description: "Les deux séances troquent leurs créneaux, sans perte.",
      icon: ArrowRightLeft,
      reason: disabled.swap,
      onClick: onSwap,
    },
    {
      label: "Mutualiser",
      description: "Les deux équipes s'entraînent ensemble, en une séance commune.",
      icon: Users,
      reason: disabled.mutualize,
      onClick: onMutualize,
    },
  ];

  return (
    <Modal
      label="Que faire de la case occupée ?"
      title="Case occupée"
      size="sm"
      onClose={onClose}
      footer={
        <div className="flex justify-end">
          <Button variant="ghost" onClick={onClose}>
            Annuler
          </Button>
        </div>
      }
    >
      <p className="text-sm text-muted-foreground">
        Ce créneau est occupé par <span className="font-medium text-foreground">{occupantName}</span>. Que souhaitez-vous faire&nbsp;?
      </p>
      <div className="mt-4 flex flex-col gap-3">
        {choices.map(({ label, description, icon: Icon, reason, onClick }) => (
          <div key={label} className="flex flex-col gap-1">
            <Button variant="outline" className="justify-start" disabled={undefined !== reason} disabledReason={reason} onClick={onClick}>
              <Icon className="size-4" aria-hidden="true" />
              {label}
            </Button>
            <p className="pl-1 text-xs text-muted-foreground">{reason ?? description}</p>
          </div>
        ))}
      </div>
    </Modal>
  );
}
