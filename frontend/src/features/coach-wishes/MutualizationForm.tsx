import { type FormEvent, useState } from "react";

import type { Coach, Team, TeamCoach } from "@/features/wizard/api";
import { Button } from "@/shared/components/ui/button";
import { Select } from "@/shared/components/ui/select";

import type { CoachWishMutualization, CoachWishMutualizationPayload } from "./mutualizationApi";

/**
 * Saisie « au nom d'un coach » d'une demande de mutualisation (feature #10, lot D2) — ajout ou
 * édition. Parité `CoachWishForm` : le gestionnaire recueille la demande (WhatsApp/téléphone) et
 * la consigne. Une par équipe (pour la période), avec ses équipes partenaires et un nombre de
 * séances à partager. Informatif, jamais une contrainte.
 */
export function MutualizationForm({
  calendarEntryId,
  teams,
  allTeams,
  coaches,
  teamCoaches,
  editing,
  onSubmit,
  onCancel,
  pending,
}: {
  calendarEntryId: string;
  /** Équipes pouvant être SUJET (avec coach principal). */
  teams: Team[];
  /** Équipes pouvant être PARTENAIRES (toutes). */
  allTeams: Team[];
  coaches: Coach[];
  teamCoaches: TeamCoach[];
  editing: CoachWishMutualization | null;
  onSubmit: (payload: CoachWishMutualizationPayload) => void;
  onCancel: () => void;
  pending: boolean;
}) {
  const [teamId, setTeamId] = useState(editing?.teamId ?? teams[0]?.id ?? "");
  const [coachId, setCoachId] = useState(editing?.coachId ?? "");
  const [partnerTeamIds, setPartnerTeamIds] = useState<Set<string>>(new Set(editing?.partnerTeamIds ?? []));
  const [sharedSlots, setSharedSlots] = useState(editing?.sharedSlots ?? 1);

  const isEdit = null !== editing;
  const wasDetached = isEdit && null === editing?.coachId;
  const mainCoachIds = (t: string): string[] => teamCoaches.filter((tc) => tc.teamId === t && "MAIN" === tc.role).map((tc) => tc.coachId);
  const mainCoachId = (t: string): string => mainCoachIds(t)[0] ?? "";
  const resolvedCoachId = "" !== coachId ? coachId : isEdit ? "" : mainCoachId(teamId);

  const offerableCoachIds = new Set(mainCoachIds(teamId));
  const offeredCoaches = coaches.filter((c) => offerableCoachIds.has(c.id) || c.id === resolvedCoachId);

  // Partenaires proposables = toutes les équipes sauf celle du sujet.
  const partnerCandidates = allTeams.filter((t) => t.id !== teamId);

  const togglePartner = (id: string): void =>
    setPartnerTeamIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    // Un coach est requis SAUF sur une demande déjà dé-attribuée ; au moins un partenaire.
    if ("" === teamId || 0 === partnerTeamIds.size || ("" === resolvedCoachId && !wasDetached)) {
      return;
    }
    onSubmit({
      calendarEntryId,
      teamId,
      coachId: "" === resolvedCoachId ? null : resolvedCoachId,
      partnerTeamIds: [...partnerTeamIds],
      sharedSlots,
      done: editing?.done ?? false,
    });
  };

  return (
    <form onSubmit={submit} className="space-y-2 rounded-md border border-border bg-surface-muted p-2">
      <div className="flex flex-wrap items-end gap-2">
        <label className="text-xs text-muted-foreground">
          Équipe
          <Select
            aria-label="Équipe"
            wrapperClassName="mt-0.5 w-40"
            value={teamId}
            disabled={isEdit}
            onChange={(e) => {
              setTeamId(e.target.value);
              setCoachId("");
              setPartnerTeamIds((prev) => {
                const next = new Set(prev);
                next.delete(e.target.value); // une équipe ne se mutualise pas avec elle-même
                return next;
              });
            }}
          >
            {teams.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </Select>
        </label>
        <label className="text-xs text-muted-foreground">
          Coach
          <Select aria-label="Coach" wrapperClassName="mt-0.5 w-40" value={resolvedCoachId} onChange={(e) => setCoachId(e.target.value)}>
            {!isEdit || wasDetached ? <option value="">Coach…</option> : null}
            {offeredCoaches.map((c) => (
              <option key={c.id} value={c.id}>
                {c.firstName} {c.lastName}
                {offerableCoachIds.has(c.id) ? "" : " (n'encadre plus cette équipe)"}
              </option>
            ))}
          </Select>
        </label>
        <label className="text-xs text-muted-foreground">
          Séances à mutualiser
          <Select aria-label="Séances à mutualiser" wrapperClassName="mt-0.5 w-24" value={sharedSlots} onChange={(e) => setSharedSlots(Number(e.target.value))}>
            {[1, 2, 3, 4, 5, 6, 7].map((n) => (
              <option key={n} value={n}>
                {n}
              </option>
            ))}
          </Select>
        </label>
      </div>

      <fieldset>
        <legend className="text-xs text-muted-foreground">Équipes partenaires</legend>
        <div className="mt-1 flex flex-wrap gap-2">
          {partnerCandidates.map((t) => (
            <label key={t.id} className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-2 py-1 text-sm">
              <input type="checkbox" className="size-4 accent-[var(--accent)]" checked={partnerTeamIds.has(t.id)} onChange={() => togglePartner(t.id)} aria-label={`Partenaire ${t.name}`} />
              {t.name}
            </label>
          ))}
        </div>
      </fieldset>

      <div className="flex justify-end gap-2">
        <Button type="button" size="sm" variant="ghost" onClick={onCancel}>
          Annuler
        </Button>
        <Button type="submit" size="sm" disabled={pending || 0 === partnerTeamIds.size || ("" === resolvedCoachId && !wasDetached)}>
          {isEdit ? "Enregistrer" : "Ajouter la mutualisation"}
        </Button>
      </div>
    </form>
  );
}
