import type { ReactNode } from "react";

import { cn } from "@/shared/lib/utils";

/**
 * L'encart d'AVERTISSEMENT — « voici un fait qui limite ce que tu peux faire ici ».
 *
 * P4-127 (b) : la même nature d'information était rendue à TROIS endroits avec deux boîtes
 * différentes — `border-warning/50` sans icône (bloc vacances du cockpit, exclusions de semaines)
 * vs `border-warning/60` avec icône (`WindowAlreadyPlannedNotice`) — dont deux CÔTE À CÔTE dans la
 * même modale. La passe `ui-ux-pro-max` du 2026-08-22 l'a relevé : deux bordures, deux traitements
 * de l'icône, un seul sens.
 *
 * La boîte vit donc ici, une fois. Ce qui VARIE reste aux appelants : l'icône (décorative, le texte
 * porte toujours le sens — contrainte a11y « jamais la couleur ni l'icône seules ») et le contenu.
 *
 * ⚑ `message` et `children` sont SÉPARÉS pour une raison de correction, pas de goût : l'icône ne
 * peut décorer que la ligne de TEXTE. Mettre une action dans le même `<p>` produirait un `<div>`
 * dans un `<p>` — HTML invalide, que le navigateur « répare » en cassant la mise en page. Le premier
 * jet de cette primitive faisait exactement ça.
 *
 * ⚠ Ce n'est pas une alerte d'ERREUR : pas de `role="alert"`, pas de `aria-live` ici. Ces encarts
 * décrivent un état stable de l'écran, pas un événement — et quand ils arrivent de façon asynchrone,
 * c'est le CONTENEUR de l'appelant qui porte la région live (cf. `WeekPickerDialog`), pour annoncer
 * l'arrivée une fois plutôt qu'une fois par encart.
 */
export function WarningPanel({ icon, message, children, className }: { icon?: ReactNode; message: ReactNode; children?: ReactNode; className?: string }) {
  return (
    <div className={cn("space-y-2 rounded-md border border-warning/60 bg-warning/10 px-3 py-2 text-sm text-foreground", className)}>
      <p className={undefined === icon ? undefined : "flex items-start gap-2"}>
        {undefined === icon ? (
          message
        ) : (
          <>
            <span aria-hidden className="mt-0.5 shrink-0 leading-none">
              {icon}
            </span>
            <span>{message}</span>
          </>
        )}
      </p>
      {children}
    </div>
  );
}
