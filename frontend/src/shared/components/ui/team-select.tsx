import { Users } from "lucide-react";
import type { ReactNode } from "react";

import { Listbox, type ListboxGroup, type ListboxOption } from "@/shared/components/ui/listbox";
import { groupTeamsByTier, type TeamLike, TIER_MEANING, tierGroupLabel, type TierLike } from "@/shared/lib/teamTiers";

/** A priority tier, optionally carrying its colour (PriorityTier does; TierLike alone does not). */
type TierWithColor = TierLike & { color?: string | null };

/** Extra per-team option metadata (P2-60 → P4-164): count, reason line, disabled state, tooltip. */
export interface TeamOptionMeta {
  /** Right-aligned count, e.g. "reste 2 créneaux". */
  count?: string;
  /** Second line: a precision ("hors groupe") or a disabled reason. */
  sub?: string;
  /** Reachable by keyboard but inert (residu 0…). */
  disabled?: boolean;
  title?: string;
}

interface TeamSelectProps<T extends TeamLike> {
  teams: T[];
  tiers: TierWithColor[];
  value: string;
  onValueChange: (value: string) => void;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
  id?: string;
  title?: string;
  autoFocus?: boolean;
  "aria-label"?: string;
  "aria-labelledby"?: string;
  "aria-describedby"?: string;
  /**
   * OPT-IN (P2-46 PR-3) — un sous-groupe d'options « Entraînements mutualisés » rendu EN TÊTE,
   * avant les paliers de rang. Les valeurs sont DÉJÀ encodées par l'appelant (ex. `block:{id}`) :
   * TeamSelect ne les interprète pas, c'est l'`onValueChange` du panneau Réserver qui les décode.
   */
  mutualisationGroups?: { value: string; label: string }[];
  /**
   * OPT-IN (P4-164, remplace `optionLabel`) — métadonnées d'option par équipe (résidu, motif,
   * désactivation). La VALEUR reste `team.id` et le LIBELLÉ reste `team.name` ; seules la ligne de
   * droite (`count`), la sous-ligne (`sub`) et l'état désactivé changent. Les autres consommateurs
   * (contraintes, coachs, matchs, import FBI) n'en passent pas — options nues (`team.name`).
   */
  optionMeta?: (team: T) => TeamOptionMeta;
}

/**
 * A team picker whose options are grouped by priority tier (S/A/B/C/D) — the same découpage as
 * the teams step, so the selector order mirrors the manager's ranking. The tier colour is the
 * option's swatch (a team's colour IS its tier's colour — founder decision). Falls back to a flat
 * list when the tiers are not loaded yet. Built on the shared `Listbox` primitive (P4-164) so it
 * can carry a colour dot, a residu count and a keyboard-reachable disabled option — things a native
 * `<select>` cannot. No team is ever dropped (orphans land in "Autres").
 */
export function TeamSelect<T extends TeamLike>({ teams, tiers, mutualisationGroups, optionMeta, ...listbox }: TeamSelectProps<T>) {
  const tierGroups = groupTeamsByTier(teams, tiers);
  const hasRealTiers = tierGroups.some((group) => null !== group.tier);
  const hasMutualisation = mutualisationGroups !== undefined && mutualisationGroups.length > 0;

  const teamOption = (team: T, swatch: string | null): ListboxOption => {
    const meta = optionMeta?.(team) ?? {};
    return { value: team.id, label: team.name, swatch, count: meta.count, sub: meta.sub, disabled: meta.disabled, title: meta.title };
  };

  // Flat fallback: no tiers loaded AND no mutualisation section → a plain list (no group headers).
  if (!hasRealTiers && !hasMutualisation) {
    return <Listbox {...listbox} options={teams.map((team) => teamOption(team, null))} />;
  }

  const groups: ListboxGroup[] = [];
  if (hasMutualisation) {
    groups.push({ id: "mutualisation", label: "Entraînements mutualisés", icon: (<Users />) as ReactNode, options: (mutualisationGroups ?? []).map((o) => ({ value: o.value, label: o.label })) });
  }
  for (const group of tierGroups) {
    const tier = group.tier as TierWithColor | null;
    const color = tier?.color ?? null;
    groups.push({
      id: tier?.id ?? "orphan",
      label: null === tier ? tierGroupLabel(null) : (TIER_MEANING[tier.label] ?? tier.name),
      badge: tier?.label,
      swatch: null === tier ? undefined : color,
      options: group.teams.map((team) => teamOption(team, color)),
    });
  }

  return <Listbox {...listbox} groups={groups} />;
}
