import { Info } from "lucide-react";
import { type ReactNode, useState } from "react";

import { Button } from "./button";
import { Modal, type ModalSize } from "./modal";
import { cn } from "@/shared/lib/utils";

/**
 * Un bouton (i) « aide contextuelle » : une icône info qui ouvre une modale d'explication. Maison
 * UNIQUE (partagée entre l'assistant de saisie et le module Matchs) — un seul patron d'aide, un seul
 * a11y. Le déclencheur est un bouton nu (transparent, sur une surface déjà opaque) ; la modale porte
 * le contenu.
 *
 * `label` = l'intitulé de la modale (« À quoi sert cette étape ? » / « … cet écran ? ») ;
 * `triggerLabel` = l'aria-label du bouton (« Comprendre cette étape » / « … cet écran »). Le
 * contenu (`children`) est du texte libre : paragraphes + puces, fourni par l'appelant.
 */
export function HelpButton({
  label,
  triggerLabel,
  size = "md",
  className,
  children,
}: {
  label: string;
  triggerLabel: string;
  size?: ModalSize;
  className?: string;
  children: ReactNode;
}) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button
        type="button"
        aria-label={triggerLabel}
        onClick={() => setOpen(true)}
        className={cn(
          "inline-flex size-6 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
          className,
        )}
      >
        <Info className="size-4" aria-hidden="true" />
      </button>
      {open ? (
        <Modal label={triggerLabel} title={label} onClose={() => setOpen(false)} size={size} footer={<Button onClick={() => setOpen(false)}>Fermer</Button>}>
          <div className="flex flex-col gap-3 text-sm text-foreground">{children}</div>
        </Modal>
      ) : null}
    </>
  );
}
