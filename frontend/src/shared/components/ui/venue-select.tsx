import { Listbox, type ListboxOption } from "@/shared/components/ui/listbox";

/** One venue row. `color` drives the pastille (null → neutral dot). */
export interface VenueLike {
  id: string;
  name: string;
  color: string | null;
  /** Second line under the name: an effective state ("désactivé", "fermé lundi…"). Name stays intact. */
  sub?: string;
  /** Reachable by keyboard but inert (Enter/click no-op, list stays open). */
  disabled?: boolean;
}

/** A head option rendered before the venues (after the placeholder), e.g. a suggestion to clear. */
export interface VenueLeadingOption {
  value: string;
  label: string;
  disabled?: boolean;
}

interface VenueSelectProps {
  venues: VenueLike[];
  value: string;
  onValueChange: (value: string) => void;
  /** Selectable leading option (value ""), like `TeamSelect` — shown in the trigger when nothing is picked. */
  placeholder?: string;
  /** Head options rendered before the venues (after the placeholder). Replaces the old `<option>` children. */
  leadingOptions?: VenueLeadingOption[];
  /** Trigger width/height overrides (twMerge over the Listbox defaults). */
  className?: string;
  /** Wraps the field — width usually lives here (the popover matches the wrapper width). */
  wrapperClassName?: string;
  disabled?: boolean;
  autoFocus?: boolean;
  title?: string;
  id?: string;
  "aria-label"?: string;
  "aria-labelledby"?: string;
  "aria-describedby"?: string;
}

/**
 * Sélecteur de gymnase partagé (demande fondateur 2026-08-05), reconstruit sur la primitive
 * `Listbox` (P4-164 PR-2). La pastille `Venue.color` s'affiche **sur chaque option ET sur le
 * trigger** (le `Listbox` peint la pastille de la valeur choisie) — l'identification d'un coup
 * d'œil, dans le champ comme dans la liste ouverte.
 *
 * ⚠ L'ancienne limite « la liste OUVERTE reste textuelle » (une `<option>` HTML ne porte que du
 * texte) N'EXISTE PLUS : le `Listbox` n'est pas un `<select>` natif. Une seule pastille désormais
 * (celle du trigger), plus de `VenueSwatch` externe accolée au champ.
 *
 * L'état effectif d'un gymnase (désactivé / fermé {jours} / indisponible) passe par `sub` — nom
 * intact, état en sous-ligne — au lieu d'être concaténé dans le libellé (`nom — état`).
 */
export function VenueSelect({ venues, leadingOptions, placeholder, className, wrapperClassName, ...listbox }: VenueSelectProps) {
  const options: ListboxOption[] = [
    ...(leadingOptions ?? []).map((o) => ({ value: o.value, label: o.label, disabled: o.disabled })),
    ...venues.map((v) => ({ value: v.id, label: v.name, swatch: v.color, sub: v.sub, disabled: v.disabled })),
  ];

  return (
    <div className={wrapperClassName}>
      <Listbox {...listbox} options={options} placeholder={placeholder} className={className} />
    </div>
  );
}
