import type { SelectHTMLAttributes } from "react";

import { groupTeamsByTier, type TeamLike, tierGroupLabel, type TierLike } from "@/shared/lib/teamTiers";

import { Select } from "./select";

interface TeamSelectProps<T extends TeamLike> extends Omit<SelectHTMLAttributes<HTMLSelectElement>, "children"> {
  teams: T[];
  tiers: TierLike[];
  /** Optional leading option (e.g. a placeholder) rendered before the groups. */
  placeholder?: string;
  /**
   * OPT-IN (P2-46 PR-3) — un sous-groupe d'options « Entraînements mutualisés » rendu EN TÊTE,
   * avant les paliers de rang. Les valeurs sont DÉJÀ encodées par l'appelant (ex. `group:{id}`) :
   * TeamSelect ne les interprète pas, c'est l'`onChange` du panneau Réserver qui les décode. Les
   * autres consommateurs (contraintes, coachs, matchs, import FBI) n'en passent pas — leur rendu
   * reste STRICTEMENT inchangé.
   */
  mutualisationGroups?: { value: string; label: string }[];
}

/**
 * A team picker whose options are grouped by priority tier (S/A/B/C/D), the
 * same découpage as the teams step — so the selector order mirrors the manager's
 * ranking. Falls back to a flat list when the tiers are not loaded yet.
 */
export function TeamSelect<T extends TeamLike>({ teams, tiers, placeholder, mutualisationGroups, ...props }: TeamSelectProps<T>) {
  const groups = groupTeamsByTier(teams, tiers);
  // Group only once real tiers are loaded; until then (or if none match) show a
  // flat list rather than a single "Autres" optgroup. Either way NO team is
  // dropped — groupTeamsByTier keeps orphans in a trailing bucket.
  const grouped = groups.some((group) => null !== group.tier);
  return (
    <Select {...props}>
      {placeholder !== undefined ? <option value="">{placeholder}</option> : null}
      {mutualisationGroups !== undefined && mutualisationGroups.length > 0 ? (
        <optgroup label="Entraînements mutualisés">
          {mutualisationGroups.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </optgroup>
      ) : null}
      {grouped
        ? groups.map((group) => (
            <optgroup key={group.tier?.id ?? "orphan"} label={tierGroupLabel(group.tier)}>
              {group.teams.map((team) => (
                <option key={team.id} value={team.id}>
                  {team.name}
                </option>
              ))}
            </optgroup>
          ))
        : teams.map((team) => (
            <option key={team.id} value={team.id}>
              {team.name}
            </option>
          ))}
    </Select>
  );
}
