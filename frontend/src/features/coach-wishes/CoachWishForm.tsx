import { type FormEvent, useState } from "react";

import type { Coach, PriorityTier, Team, TeamCoach } from "@/features/wizard/api";
import { Button } from "@/shared/components/ui/button";
import { DayMultiPicker } from "@/shared/components/ui/day-multi-picker";
import { FIELD_CLASS } from "@/shared/components/ui/field";
import { Select } from "@/shared/components/ui/select";
import { TeamSelect } from "@/shared/components/ui/team-select";
import { DAYS } from "@/shared/lib/days";
import { cn } from "@/shared/lib/utils";

import type { CoachWish, CoachWishPayload } from "./api";
import type { WeekWindow } from "@/features/cockpit/lib/date";

/** Les sept jours ISO (foyer `shared/lib/days`), pour calculer le complément « disponibles ». */
const ALL_DAYS: number[] = DAYS.map((d) => d.n);

/**
 * Saisie « au nom d'un coach » d'une doléance (feature #10, lot C1) — ajout ou édition.
 * Le gestionnaire recueille les souhaits par WhatsApp/téléphone et les consigne ici.
 *
 * La semaine est FIGÉE quand la modale est filtrée sur une semaine (vue wizard d'un plan
 * de semaine) : on ne saisit alors une doléance que pour cette semaine-là.
 */
export function CoachWishForm({
  calendarEntryId,
  weeks,
  lockedWeek,
  teams,
  tiers,
  coaches,
  teamCoaches,
  editing,
  onSubmit,
  onCancel,
  pending,
}: {
  calendarEntryId: string;
  weeks: WeekWindow[];
  lockedWeek: string | null;
  teams: Team[];
  tiers: PriorityTier[];
  coaches: Coach[];
  teamCoaches: TeamCoach[];
  editing: CoachWish | null;
  onSubmit: (payload: CoachWishPayload) => void;
  onCancel: () => void;
  pending: boolean;
}) {
  const [teamId, setTeamId] = useState(editing?.teamId ?? teams[0]?.id ?? "");
  const [weekStart, setWeekStart] = useState(editing?.weekStart ?? lockedWeek ?? weeks[0]?.monday ?? "");
  const [coachId, setCoachId] = useState(editing?.coachId ?? "");
  const [slotsWanted, setSlotsWanted] = useState(editing?.slotsWanted ?? 1);
  const [days, setDays] = useState<number[]>(editing?.unavailableDays ?? []);
  const [wishedDays, setWishedDays] = useState<number[]>(editing?.wishedDays ?? []);
  const [comment, setComment] = useState(editing?.comment ?? "");

  // Exclusion souhaité ∩ indisponible : cocher un jour d'un côté le retire de l'autre
  // (dernier geste gagne) — même règle que la garde serveur (422), ici silencieuse.
  const changeDays = (next: number[]): void => {
    setDays(next);
    setWishedDays((w) => w.filter((d) => !next.includes(d)));
  };
  const changeWishedDays = (next: number[]): void => {
    setWishedDays(next);
    setDays((d) => d.filter((x) => !next.includes(x)));
  };
  // P2-63 A — le picker montre les jours DISPONIBLES (complément des indisponibilités), tous
  // pressés par défaut. La donnée/le payload restent `unavailableDays` : dépresser un jour
  // disponible revient à l'inscrire en indisponible.
  const availableDays = ALL_DAYS.filter((d) => !days.includes(d));
  const changeAvailableDays = (nextAvailable: number[]): void => changeDays(ALL_DAYS.filter((d) => !nextAvailable.includes(d)));

  const isEdit = null !== editing;
  // Une doléance DÉ-ATTRIBUÉE (coach supprimé) : seule elle peut rester sans coach. On ne
  // dé-attribue JAMAIS par l'édition une doléance attribuée (revue #10 C1 round 2) — c'est
  // réservé à la suppression d'un coach.
  const wasDetached = isEdit && null === editing?.coachId;
  // Défaut coach = le coach MAIN de l'équipe, mais À LA CRÉATION seulement. En édition on
  // garde ce qui est là — y compris "" pour une doléance déjà dé-attribuée : retomber sur le
  // MAIN actuel la ré-attribuerait en silence à un autre auteur.
  const mainCoachIds = (t: string): string[] => teamCoaches.filter((tc) => tc.teamId === t && "MAIN" === tc.role).map((tc) => tc.coachId);
  const mainCoachId = (t: string): string => mainCoachIds(t)[0] ?? "";
  const resolvedCoachId = "" !== coachId ? coachId : isEdit ? "" : mainCoachId(teamId);

  // P3-14 (décision fondateur 2026-08-01 : « je veux que les MAIN coach ») — le select
  // n'offre que les coachs PRINCIPAUX de l'équipe choisie. Il listait tout le club, alors
  // même que le défaut pré-sélectionne le MAIN : on pouvait enregistrer « U18F1 — Emerick »
  // quand Emerick n'encadre que SF1 et U15F1. Rien ne l'attrapait ensuite.
  //
  // ⚠ CHOISIR n'est pas NOMMER (leçon #342) : la valeur COURANTE reste offerte même si son
  // lien MAIN a disparu depuis (coach passé assistant, lien retiré). La filtrer viderait le
  // select sur une doléance qui nomme pourtant un coach — et « combler le trou » la
  // réattribuerait en silence à quelqu'un d'autre. Elle est gardée, et marquée.
  const offerableCoachIds = new Set(mainCoachIds(teamId));
  const offeredCoaches = coaches.filter((c) => offerableCoachIds.has(c.id) || c.id === resolvedCoachId);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    // Un coach est requis SAUF sur une doléance déjà dé-attribuée : ni la création ni
    // l'édition d'une doléance attribuée ne peuvent la laisser sans coach.
    if ("" === teamId || "" === weekStart || ("" === resolvedCoachId && !wasDetached)) {
      return;
    }
    onSubmit({
      calendarEntryId,
      weekStart,
      teamId,
      coachId: "" === resolvedCoachId ? null : resolvedCoachId,
      slotsWanted,
      unavailableDays: days,
      wishedDays,
      comment: "" === comment.trim() ? null : comment.trim(),
      done: editing?.done ?? false,
    });
  };

  return (
    <form onSubmit={submit} className="space-y-2 rounded-md border border-border bg-surface-muted p-2">
      <div className="flex flex-wrap items-end gap-2">
        {/* P2-63 C — sélecteur d'équipe PARTAGÉ (TeamSelect : groupes par rang, pastille couleur,
            recherche au-delà de 8) : visible « Équipe » en caption, nom accessible via `aria-label`
            (TeamSelect est un listbox bouton, pas un `<select>` labellable). */}
        <div className="text-xs text-muted-foreground">
          Équipe
          <TeamSelect
            aria-label="Équipe"
            wrapperClassName="mt-0.5 w-40"
            teams={teams}
            tiers={tiers}
            value={teamId}
            disabled={null !== editing}
            onValueChange={(v) => {
              setTeamId(v);
              setCoachId(""); // recalcule le défaut coach sur la nouvelle équipe
            }}
          />
        </div>
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
          Semaine
          <Select
            aria-label="Semaine"
            wrapperClassName="mt-0.5 w-40"
            value={weekStart}
            disabled={null !== lockedWeek || null !== editing}
            onChange={(e) => setWeekStart(e.target.value)}
          >
            {weeks.map((w) => (
              <option key={w.monday} value={w.monday}>
                Semaine du {w.startDate}
              </option>
            ))}
          </Select>
        </label>
        <label className="text-xs text-muted-foreground">
          Créneaux souhaités
          <Select aria-label="Créneaux souhaités" wrapperClassName="mt-0.5 w-24" value={slotsWanted} onChange={(e) => setSlotsWanted(Number(e.target.value))}>
            {[0, 1, 2, 3, 4, 5, 6, 7].map((n) => (
              <option key={n} value={n}>
                {n}
              </option>
            ))}
          </Select>
        </label>
      </div>

      <DayMultiPicker legend="Jours souhaités" legendVisible tone="accent" value={wishedDays} onChange={changeWishedDays} />

      {/* Pressé = DISPONIBLE (positif) : `tone="accent"`, jamais `destructive` (« bloqué »). */}
      <DayMultiPicker legend="Jours disponibles" legendVisible tone="accent" value={availableDays} onChange={changeAvailableDays} />

      <textarea
        aria-label="Commentaire"
        placeholder="Commentaire (mutualisation, contexte…)"
        className={cn("min-h-16", FIELD_CLASS)}
        maxLength={1000}
        value={comment}
        onChange={(e) => setComment(e.target.value)}
      />

      <div className="flex justify-end gap-2">
        <Button type="button" size="sm" variant="ghost" onClick={onCancel}>
          Annuler
        </Button>
        <Button type="submit" size="sm" disabled={pending || ("" === resolvedCoachId && !wasDetached)}>
          {null !== editing ? "Enregistrer" : "Ajouter la doléance"}
        </Button>
      </div>
    </form>
  );
}
