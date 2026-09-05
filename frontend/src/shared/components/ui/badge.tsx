import type { ReactNode } from "react";

import { cn } from "@/shared/lib/utils";

/**
 * StatusPill — la pastille d'état PARTAGÉE : bordure + fond teinté + icône + texte, sur le
 * modèle de `CreditBadge`. Maison unique d'une pastille inline (« même chose, au même endroit,
 * de la même façon ») : une nouvelle pastille passe par ici plutôt que de recoder un `<span>`
 * arrondi à la main.
 *
 * Deux variantes de jeton (jamais de `#hex`) :
 *  - `warning` : `border-warning/50 bg-warning/10` + TEXTE `text-foreground` — un état qui appelle
 *    une action. Le texte est en `text-foreground` (et non `text-warning`) car `text-warning` sur
 *    `bg-warning/10` mesure 4,30:1 en thème clair (< 4,5:1 AA) — même repli que la bannière
 *    `/planning`. L'ICÔNE, elle, reste `text-warning` (élément graphique, seuil 1.4.11 ≥ 3:1) :
 *    c'est l'appelant qui la colore. Paires verrouillées dans `tests/e2e/a11y-contrast.spec.ts`.
 *  - `neutral` : `border-border bg-muted text-muted-foreground` — un état informatif.
 *
 * L'icône est passée par l'appelant (aria-hidden — le texte visible EST l'annonce, pas d'aria-label
 * ni de role live : c'est un ÉTAT stable, pas un événement). La pastille ENVELOPPE son texte
 * (`whitespace-normal`), elle ne le tronque jamais — à placer dans un frère en `flex-wrap`, hors
 * d'un nœud `truncate`.
 */
export function StatusPill({
  icon,
  children,
  variant = "neutral",
  className,
}: {
  icon?: ReactNode;
  children: ReactNode;
  variant?: "warning" | "neutral";
  className?: string;
}) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-normal",
        "warning" === variant ? "border-warning/50 bg-warning/10 text-foreground" : "border-border bg-muted text-muted-foreground",
        className,
      )}
    >
      {icon}
      {children}
    </span>
  );
}
