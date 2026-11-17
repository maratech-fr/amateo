import { useId } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { ReadOnlyFieldset } from "@/shared/components/ui/read-only-fieldset";
import { Select } from "@/shared/components/ui/select";
import { useCanManage } from "@/shared/session/queries";

import type { Team } from "../api";
import { effectiveMaxPerDay, periodOverrideBody } from "../lib/periodOverride";
import { payload } from "../lib/teamPayload";
import { useCreateTeamPeriodOverride, useDeleteTeamPeriodOverride, useTeamPeriodOverrides, useUpdateTeam, useUpdateTeamPeriodOverride } from "../queries";
import { TEAMS_TEXTS } from "../texts/teams";

const DAILY_CAP_VALUES = [1, 2, 3, 4] as const;

/**
 * Onglet « Séances par jour » de la modale Liens : séances max le même jour pour l'équipe (1 à 4).
 *  - SAISON (`schedulePlanId` null) : PUT complet de l'équipe ; en lecture seule sous planning de
 *    saison en vigueur (`readOnly`, le serveur refuse aussi le changement en 422).
 *  - PÉRIODE : la surcharge du plan (`periodOverrideBody`), jamais l'équipe ; modifiable sous socle.
 *  - Membre : lecture seule (le serveur refuse en 403).
 * La valeur s'enregistre au changement de la liste.
 */
export function TeamDailyCapPanel({ team, schedulePlanId, readOnly }: { team: Team; schedulePlanId: string | null; readOnly: boolean }) {
  const fieldId = useId();
  const canManage = useCanManage();
  const updateTeam = useUpdateTeam();
  const { data: overrides = [] } = useTeamPeriodOverrides(schedulePlanId);
  const createOverride = useCreateTeamPeriodOverride(schedulePlanId);
  const updateOverride = useUpdateTeamPeriodOverride(schedulePlanId);
  const deleteOverride = useDeleteTeamPeriodOverride(schedulePlanId);

  const existing = null === schedulePlanId ? undefined : overrides.find((o) => o.teamId === team.id);
  const value = null === schedulePlanId ? (team.maxSessionsPerDay ?? 1) : effectiveMaxPerDay(team, existing);

  const save = (next: number) => {
    if (!Number.isInteger(next) || next < 1 || next > 4 || next === value) {
      return;
    }
    if (null === schedulePlanId) {
      updateTeam.mutate({ id: team.id, body: payload(team, { maxSessionsPerDay: next }) });
      return;
    }
    const body = periodOverrideBody(schedulePlanId, team, existing, { maxSessionsPerDay: next });
    if (null === body) {
      if (existing) {
        deleteOverride.mutate(existing.id);
      }
      return;
    }
    if (existing) {
      updateOverride.mutate({ id: existing.id, body });
    } else {
      createOverride.mutate(body);
    }
  };

  return (
    <ReadOnlyFieldset readOnly={!canManage} className="flex flex-col gap-3">
      <p id={`${fieldId}-help`} className="text-xs text-muted-foreground">
        {TEAMS_TEXTS.dailyCapHelp}
      </p>
      <div className="flex max-w-sm flex-col gap-1 text-sm text-foreground">
        <label htmlFor={fieldId}>{TEAMS_TEXTS.dailyCapLabel}</label>
        <Select id={fieldId} wrapperClassName="w-24" value={String(value)} disabled={readOnly} aria-describedby={`${fieldId}-help`} onChange={(e) => save(Number(e.target.value))}>
          {DAILY_CAP_VALUES.map((n) => (
            <option key={n} value={n}>
              {n}
            </option>
          ))}
        </Select>
        {null !== schedulePlanId ? <p className="text-xs text-muted-foreground">{TEAMS_TEXTS.dailyCapPeriodScope(team.maxSessionsPerDay ?? 1)}</p> : null}
        {readOnly ? <p className="text-xs text-muted-foreground">{TEAMS_TEXTS.sessionsLockHint}</p> : null}
      </div>
    </ReadOnlyFieldset>
  );
}

/** Repère de ligne « N/jour » d'une équipe autorisée à plus d'une séance par jour (rien à 1). */
export function DailyCapMarker({ max }: { max: number }) {
  if (max <= 1) {
    return null;
  }
  return (
    <StatusPill variant="neutral" aria-label={TEAMS_TEXTS.dailyCapMarkerAria(max)} title={TEAMS_TEXTS.dailyCapMarkerAria(max)}>
      {TEAMS_TEXTS.dailyCapMarker(max)}
    </StatusPill>
  );
}
