import { type FormEvent, useState } from "react";

import type { Coach, Team, TeamCoach } from "@/features/wizard/api";
import { Button } from "@/shared/components/ui/button";
import { DayMultiPicker } from "@/shared/components/ui/day-multi-picker";
import { Select } from "@/shared/components/ui/select";
import { cn } from "@/shared/lib/utils";

import type { CoachWish, CoachWishPayload } from "./api";
import type { WeekWindow } from "@/features/cockpit/lib/date";

const fieldClass = "h-8 rounded-md border border-input bg-background px-2 text-sm";

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
  const [comment, setComment] = useState(editing?.comment ?? "");

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
      comment: "" === comment.trim() ? null : comment.trim(),
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
            className={cn(fieldClass, "mt-0.5 block w-40")}
            value={teamId}
            disabled={null !== editing}
            onChange={(e) => {
              setTeamId(e.target.value);
              setCoachId(""); // recalcule le défaut coach sur la nouvelle équipe
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
          <Select aria-label="Coach" className={cn(fieldClass, "mt-0.5 block w-40")} value={resolvedCoachId} onChange={(e) => setCoachId(e.target.value)}>
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
            className={cn(fieldClass, "mt-0.5 block w-40")}
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
          <Select aria-label="Créneaux souhaités" className={cn(fieldClass, "mt-0.5 block w-24")} value={slotsWanted} onChange={(e) => setSlotsWanted(Number(e.target.value))}>
            {[0, 1, 2, 3, 4, 5, 6, 7].map((n) => (
              <option key={n} value={n}>
                {n}
              </option>
            ))}
          </Select>
        </label>
      </div>

      <DayMultiPicker legend="Jours indisponibles" legendVisible tone="destructive" value={days} onChange={setDays} />

      <textarea
        aria-label="Commentaire"
        placeholder="Commentaire (mutualisation, contexte…)"
        className="min-h-16 w-full rounded-md border border-input bg-background px-2 py-1 text-sm"
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
