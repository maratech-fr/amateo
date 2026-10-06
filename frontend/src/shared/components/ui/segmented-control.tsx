import type { ReactNode } from "react";

import { Button } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/utils";

export interface SegmentOption<K extends string> {
  key: K;
  /** Libellé VISIBLE du segment. */
  label: ReactNode;
  /** Icône rendue en tête (l'appelant en fixe les classes : `size-3.5`, `aria-hidden`). */
  icon?: ReactNode;
  /** Compteur rendu en fin de segment (nu, `aria-hidden`) ; le nom accessible le reprend via `ariaLabel`. */
  count?: number;
  /** Nom accessible COMPLET du segment (défaut : le `label` visible). À fournir dès qu'un `count` ou une icône change le sens lu. */
  ariaLabel?: string;
}

interface CommonProps<K extends string> {
  options: SegmentOption<K>[];
  /** Nom accessible du groupe (`aria-label`). Fournir `label` OU `labelledBy`. */
  label?: string;
  /** `id` d'un libellé visible qui nomme le groupe (`aria-labelledby`). */
  labelledBy?: string;
  className?: string;
}

/**
 * Props — union discriminée par `mode` (patron Radix ToggleGroup) :
 * - `single` (défaut) : choix EXCLUSIF, exactement un segment pressé.
 * - `multiple` : sous-ensemble libre, chaque segment bascule indépendamment.
 */
export type SegmentedControlProps<K extends string> = CommonProps<K> &
  ({ mode?: "single"; value: K; onValueChange: (key: K) => void } | { mode: "multiple"; values: K[]; onToggle: (key: K) => void });

/**
 * SegmentedControl — le contrôle SEGMENTÉ partagé : une rangée de boutons nus dans UN conteneur
 * bordé (`rounded-md border border-border bg-card p-0.5`), chaque segment à la hauteur de ligne
 * 36 px (= `size="sm"`). Maison unique du motif recopié six fois (vues planning, axe équipe/coach/
 * gymnase, « Période » semaine·mois·phase, pivot « Regrouper par », filtre adversaires, « Types »
 * de compétition) avec des hauteurs et des contrats a11y divergents.
 *
 * Patron APG **toggle button** : chaque segment est un `<button aria-pressed>`, cohérent avec
 * `FilterChip`/`DayMultiPicker` (clavier Tab + Espace/Entrée natif, focus visible). `mode="single"`
 * garde exactement un segment pressé (choix exclusif) ; `mode="multiple"` bascule chacun à part.
 * Le conteneur porte `role="group"` nommé par `label`/`labelledBy` — l'appelant fournit toujours
 * l'un des deux. ⚠ Sœur de `FilterChip` (puce bordée INDIVIDUELLE à compteur) : lui garde le
 * conteneur bordé + segments nus, sans compteur par défaut ; `FilterChip` reste la maison des puces
 * de filtre isolées. Un compteur par segment reste possible (`count` + `ariaLabel`, patron
 * adversaires : nombre nu visible `aria-hidden`, le nom accessible le reprend).
 */
export function SegmentedControl<K extends string>(props: SegmentedControlProps<K>) {
  const { options, label, labelledBy, className } = props;
  const isPressed = (key: K): boolean => ("multiple" === props.mode ? props.values.includes(key) : props.value === key);
  const activate = (key: K): void => {
    if ("multiple" === props.mode) {
      props.onToggle(key);
    } else {
      props.onValueChange(key);
    }
  };

  return (
    <div role="group" aria-label={label} aria-labelledby={labelledBy} className={cn("flex flex-wrap items-center gap-1 rounded-md border border-border bg-card p-0.5", className)}>
      {options.map((opt) => {
        const pressed = isPressed(opt.key);
        return (
          <Button
            key={opt.key}
            type="button"
            size="sm"
            aria-pressed={pressed}
            aria-label={opt.ariaLabel}
            variant={pressed ? "default" : "ghost"}
            className={pressed ? "gap-1.5" : "gap-1.5 text-muted-foreground"}
            onClick={() => activate(opt.key)}
          >
            {opt.icon}
            {opt.label}
            {undefined !== opt.count ? (
              <span className="tabular-nums" aria-hidden="true">
                ({opt.count})
              </span>
            ) : null}
          </Button>
        );
      })}
    </div>
  );
}
