import { useId } from "react";

import { DAYS, dayLabelLong, dayLabelShort } from "@/shared/lib/days";
import { cn } from "@/shared/lib/utils";

/** La couleur de l'état PRESSÉ — `accent` (sélection neutre) ou `destructive` (polarité « bloqué »). */
export type DayPickerTone = "accent" | "destructive";

export interface DayMultiPickerProps {
  /** Jours ISO sélectionnés (1 = lundi … 7 = dimanche). */
  value: number[];
  /** Appelé avec la nouvelle sélection, TRIÉE croissante. */
  onChange: (days: number[]) => void;
  /** Nom accessible du groupe (porté par `<legend>`). Requis. */
  legend: string;
  /** Rendre la legend VISIBLE à l'écran (sinon `sr-only`, nom accessible seulement). */
  legendVisible?: boolean;
  /** Jours ISO à proposer (défaut : les sept). */
  days?: number[];
  /** La teinte de l'état pressé — `accent` par défaut. */
  tone?: DayPickerTone;
  /** Désactive tout le groupe (fieldset natif). */
  disabled?: boolean;
  /**
   * Jours ISO présents mais INERTES (visibles, grisés, non basculables) — p. ex. un jour
   * souhaité qui n'est plus disponible. `aria-disabled` (le bouton reste focalisable et sa
   * raison reste découvrable, A11Y-30), jamais un `disabled` natif qui sort du focus.
   */
  disabledDays?: number[];
  /** Motif commun aux jours désactivés, annoncé au clavier/lecteur d'écran (`aria-describedby` + `title`). */
  disabledReason?: string;
}

/** Surface PLEINE au repos pressé (jamais une teinte `/NN`, P4-265) ; texte `foreground` (AA sur teinte). */
const PRESSED: Record<DayPickerTone, string> = {
  accent: "border-accent bg-accent text-accent-foreground",
  destructive: "border-destructive bg-surface-destructive text-foreground",
};

const ALL_DAYS: number[] = DAYS.map((d) => d.n);

/**
 * Le sélecteur multi-jours UNIQUE (série « uniformité des écrans », PR 6/7, 2026-10-01) — la
 * maison des « boutons Lun…Dim » qui vivait, recopiée, dans le wizard (contraintes), les
 * contraintes de match, et les deux saisies de doléances coach (publique + modale).
 *
 * Patron APG **toggle button** : chaque jour est un `<button aria-pressed>` dans un `<fieldset>`
 * dont la `<legend>` nomme le groupe (l'appelant la fournit, visible ou `sr-only`). Le libellé
 * COURT est visible (« Lun »), le nom accessible est COMPLET (« lundi ») — un lecteur d'écran
 * n'entend jamais « Lun, Mar ». Clavier Espace/Entrée (natif bouton), cible ≥ 24 px, focus visible.
 *
 * ⚠ La COULEUR ne porte pas de sens à elle seule (WCAG 1.4.1) : l'état pressé se lit au FOND plein
 * + la bordure + `aria-pressed`, pas à la seule teinte. `tone` encode une POLARITÉ (accent =
 * sélection neutre — le sens vit dans un `Select`/une legend voisins ; destructive = « jour
 * indisponible/bloqué »), pas un simple habillage. La forme reste identique dans les deux tons.
 */
export function DayMultiPicker({ value, onChange, legend, legendVisible = false, days = ALL_DAYS, tone = "accent", disabled = false, disabledDays = [], disabledReason }: DayMultiPickerProps) {
  const reasonId = useId();
  const hasReason = disabledReason !== undefined && disabledDays.length > 0;
  const toggle = (n: number): void => {
    const next = value.includes(n) ? value.filter((d) => d !== n) : [...value, n];
    onChange(next.sort((a, b) => a - b));
  };

  return (
    <fieldset disabled={disabled} className="min-w-0 border-0 p-0">
      <legend className={legendVisible ? "mb-1 text-xs text-muted-foreground" : "sr-only"}>{legend}</legend>
      {hasReason ? (
        <span id={reasonId} className="sr-only">
          {disabledReason}
        </span>
      ) : null}
      <div className="flex flex-wrap gap-1">
        {days.map((n) => {
          const isDisabled = disabledDays.includes(n);
          const on = value.includes(n) && !isDisabled;
          return (
            <button
              key={n}
              type="button"
              aria-pressed={on}
              aria-disabled={isDisabled || undefined}
              aria-label={dayLabelLong(n)}
              aria-describedby={isDisabled && hasReason ? reasonId : undefined}
              title={isDisabled ? disabledReason : undefined}
              // Jour inerte : le clic est neutralisé ICI (pas un `disabled` natif) pour que le
              // bouton reste focalisable et son motif annoncé (A11Y-30).
              onClick={() => {
                if (!isDisabled) {
                  toggle(n);
                }
              }}
              className={cn(
                "inline-flex min-h-6 min-w-9 items-center justify-center rounded-md border px-2 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50",
                isDisabled ? "cursor-not-allowed border-border text-muted-foreground opacity-50" : on ? PRESSED[tone] : "border-border text-muted-foreground",
              )}
            >
              {dayLabelShort(n)}
            </button>
          );
        })}
      </div>
    </fieldset>
  );
}
