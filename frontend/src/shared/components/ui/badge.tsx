import type { ReactNode } from "react";

import { cn } from "@/shared/lib/utils";

/**
 * StatusPill — la pastille d'état PARTAGÉE : bordure + fond teinté + icône + texte, sur le
 * modèle de `CreditBadge`. Maison unique d'une pastille inline (« même chose, au même endroit,
 * de la même façon ») : une nouvelle pastille passe par ici plutôt que de recoder un `<span>`
 * arrondi à la main.
 *
 * Trois variantes de jeton (jamais de `#hex`) :
 *  - `warning` : `border-warning/50 bg-warning/10` + TEXTE `text-foreground` — un état qui appelle
 *    une action. Le texte est en `text-foreground` (et non `text-warning`) car `text-warning` sur
 *    `bg-warning/10` mesure 4,30:1 en thème clair (< 4,5:1 AA) — même repli que la bannière
 *    `/planning`. L'ICÔNE, elle, reste `text-warning` (élément graphique, seuil 1.4.11 ≥ 3:1) :
 *    c'est l'appelant qui la colore. Paires verrouillées dans `tests/e2e/a11y-contrast.spec.ts`.
 *  - `accent` : `border-accent/50 bg-accent/10` + TEXTE `text-foreground` — un état de la couleur
 *    du club (gain, source MANUEL). Même repli AA que `warning` : `text-accent` sur un fond teinté
 *    accent tombe sous 4,5:1 (cf. `AGENTS.md` gotcha #11) ; c'est l'ICÔNE, passée par l'appelant,
 *    qui porte `text-accent` (graphique, ≥ 3:1). Paires verrouillées elles aussi (P4-177).
 *  - `neutral` : `border-border bg-muted text-muted-foreground` — un état informatif.
 *
 * L'icône est passée par l'appelant (aria-hidden — le texte visible EST l'annonce). La pastille
 * ENVELOPPE son texte (`whitespace-normal`), elle ne le tronque jamais — à placer dans un frère en
 * `flex-wrap`, hors d'un nœud `truncate`. `title`/`aria-label` sont optionnels : le défaut reste
 * « le texte visible EST l'annonce » (pas de role live : c'est un ÉTAT stable), mais un appelant
 * dont l'annonce enrichit le texte visible (ex. `CreditBadge`, « Crédits gratuits restants… ») les
 * transmet.
 */
export function StatusPill({
  icon,
  children,
  variant = "neutral",
  className,
  title,
  "aria-label": ariaLabel,
}: {
  icon?: ReactNode;
  children: ReactNode;
  variant?: "warning" | "accent" | "neutral";
  className?: string;
  title?: string;
  "aria-label"?: string;
}) {
  const tone =
    "warning" === variant
      ? "border-warning/50 bg-warning/10 text-foreground"
      : "accent" === variant
        ? "border-accent/50 bg-accent/10 text-foreground"
        : "border-border bg-muted text-muted-foreground";
  return (
    <span
      title={title}
      aria-label={ariaLabel}
      className={cn(
        "inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-normal",
        tone,
        className,
      )}
    >
      {icon}
      {children}
    </span>
  );
}
