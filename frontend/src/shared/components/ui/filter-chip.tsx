import type { ReactNode } from "react";

import { Button } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/utils";

/**
 * FilterChip — la PUCE de filtre partagée : un bouton bordé, à état pressé (`aria-pressed`),
 * qui porte un libellé, un compteur optionnel (en sourdine à 0) et une icône optionnelle en
 * tête. Maison unique du motif recopié à l'identique dans les chips « Familles » du Calendrier
 * (`CalendarControls`) et de l'onglet Conflits, et les chips « Traitement » (avec icône) de
 * Conflits. Sœur de `FilterToggle` (la CASE d'un filtre) : elle n'absorbe QUE le contrat EXACT
 * de la puce `aria-pressed` bordée à compteur — jamais les interrupteurs `role="switch"`
 * (`aria-checked`, sémantique d'accessibilité différente) ni les contrôles segmentés (puce nue
 * dans un conteneur bordé, sans compteur). L'état vit chez l'appelant ; ceci n'est que la
 * présentation. ⚠ Rendu VOLONTAIREMENT byte-identique aux copies d'origine (mêmes rôle, nom
 * accessible, classes) — les tests d'écran existants en sont le témoin de non-régression.
 */
export function FilterChip({
  pressed,
  onPress,
  count,
  icon,
  children,
}: {
  pressed: boolean;
  onPress: () => void;
  /** Compteur affiché en fin de puce ; `undefined` = aucun compteur. `0` s'affiche en sourdine. */
  count?: number;
  /** Élément d'icône rendu en tête (l'appelant en fixe les classes : `size-3.5`, `aria-hidden`). */
  icon?: ReactNode;
  children: ReactNode;
}) {
  return (
    <Button
      type="button"
      size="sm"
      aria-pressed={pressed}
      variant={pressed ? "default" : "ghost"}
      className={cn("h-7 gap-1.5 border border-border", pressed ? "" : "text-muted-foreground")}
      onClick={onPress}
    >
      {icon}
      {children}
      {undefined !== count ? <span className={cn("tabular-nums text-xs", 0 === count ? "text-muted-foreground" : undefined)}>{count}</span> : null}
    </Button>
  );
}
