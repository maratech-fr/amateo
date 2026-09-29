import { Check, Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Select } from "@/shared/components/ui/select";
import { VenueSelect } from "@/shared/components/ui/venue-select";
import { compareTeamsByRank, type TeamLike } from "@/shared/lib/teamTiers";

import type { MatchWeek, TeamMatchHabit, Venue } from "./api";
import { useCreateTeamMatchHabit, useDeleteTeamMatchHabit, useTeamMatchHabits, useUpdateTeamMatchHabit } from "./queries";

const DAY_LABELS = ["", "Lundi", "Mardi", "Mercredi", "Jeudi", "Vendredi", "Samedi", "Dimanche"];
const WEEK_OPTIONS: { value: MatchWeek; label: string }[] = [
  { value: "ALL", label: "Toutes" },
  { value: "A", label: "Semaine A" },
  { value: "B", label: "Semaine B" },
];

/**
 * P4-271 — l'éditeur « Créneaux idéaux » du SET-UP (`/matchs/semaine-type`), qui remplace
 * l'éditeur de rotations : UNE ligne par équipe, tous les champs du créneau idéal éditables EN
 * PLACE (semaine A/B/toutes · jour · heure · gymnase optionnel), création / mise à jour /
 * suppression. Une équipe = un seul créneau idéal (la possibilité d'en déclarer deux est fermée).
 *
 * Le créneau idéal est une AIDE VISUELLE — le modèle du gestionnaire, attiré comme un BONUS par le
 * solveur, jamais une obligation. Primitives partagées seulement (`Select`, `VenueSelect`, `Button`).
 * Générique sur `TeamLike` (id + nom + rang), comme `TeamLinksSection`.
 */
export function IdealSlotsEditor<T extends TeamLike>({ teams, venues }: { teams: T[]; venues: Venue[] }) {
  const habitsQuery = useTeamMatchHabits();
  const habits = habitsQuery.data ?? [];
  const habitByTeam = new Map(habits.map((h) => [h.teamId, h]));
  const orderedTeams = [...teams].sort(compareTeamsByRank);

  return (
    <section className="flex flex-col gap-3">
      <div>
        <h3 className="text-sm font-semibold">Créneaux idéaux</h3>
        <p className="text-xs text-muted-foreground">
          Le créneau que chaque équipe recevrait « si elle avait le choix » — jour, heure, gymnase, et la semaine A/B
          quand le club alterne. Une aide visuelle : le placement s'en approche, la fédération décide qui reçoit.
        </p>
      </div>

      {habitsQuery.isError ? <p className="text-sm text-destructive">Les créneaux idéaux n’ont pas pu être chargés.</p> : null}

      {0 === orderedTeams.length ? (
        <EmptyHint>Aucune équipe — les équipes se déclarent dans l’assistant de saisie.</EmptyHint>
      ) : (
        <ul className="flex flex-col gap-1">
          {orderedTeams.map((team) => (
            <IdealSlotRow key={`${team.id}:${habitByTeam.get(team.id)?.id ?? "none"}`} team={team} habit={habitByTeam.get(team.id) ?? null} venues={venues} />
          ))}
        </ul>
      )}
    </section>
  );
}

function IdealSlotRow<T extends TeamLike>({ team, habit, venues }: { team: T; habit: TeamMatchHabit | null; venues: Venue[] }) {
  const create = useCreateTeamMatchHabit();
  const update = useUpdateTeamMatchHabit();
  const remove = useDeleteTeamMatchHabit();

  const [week, setWeek] = useState<MatchWeek>(habit?.week ?? "ALL");
  const [day, setDay] = useState(habit?.dayOfWeek ?? 6);
  const [time, setTime] = useState(habit?.kickoffTime ?? "");
  const [venueId, setVenueId] = useState(habit?.venueId ?? "");

  const saving = create.isPending || update.isPending;
  const dirty =
    null === habit ? "" !== time : week !== habit.week || day !== habit.dayOfWeek || time !== habit.kickoffTime || venueId !== (habit.venueId ?? "");
  const canSave = "" !== time && dirty && !saving;

  const save = (): void => {
    if (!canSave) {
      return;
    }
    // Le gymnase est optionnel : `null` explicite quand aucun n'est choisi — sur un PUT il RETIRE
    // le gymnase du créneau idéal (full-replace, P4-271), pas seulement le change.
    const input = { teamId: team.id, dayOfWeek: day, kickoffTime: time, week, venueId: "" !== venueId ? venueId : null };
    if (null === habit) {
      create.mutate(input);
    } else {
      update.mutate({ id: habit.id, input });
    }
  };

  return (
    <li className="flex flex-wrap items-end gap-2 rounded-md border border-border bg-card px-2 py-1.5">
      <span className="min-w-32 flex-1 truncate self-center text-sm font-medium" title={team.name}>
        {team.name}
      </span>

      <label className="flex flex-col gap-0.5 text-[10px] text-muted-foreground">
        Semaine
        <Select aria-label={`Semaine du créneau idéal de ${team.name}`} className="h-8 w-28" value={week} onChange={(e) => setWeek(e.target.value as MatchWeek)}>
          {WEEK_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
      </label>

      <label className="flex flex-col gap-0.5 text-[10px] text-muted-foreground">
        Jour
        <Select aria-label={`Jour du créneau idéal de ${team.name}`} className="h-8 w-28" value={day} onChange={(e) => setDay(Number(e.target.value))}>
          {[1, 2, 3, 4, 5, 6, 7].map((d) => (
            <option key={d} value={d}>
              {DAY_LABELS[d]}
            </option>
          ))}
        </Select>
      </label>

      <label className="flex flex-col gap-0.5 text-[10px] text-muted-foreground">
        Heure
        <input
          aria-label={`Heure du créneau idéal de ${team.name}`}
          type="time"
          className="h-8 rounded-md border border-border bg-background px-2 text-sm"
          value={time}
          onChange={(e) => setTime(e.target.value)}
        />
      </label>

      <div className="flex flex-col gap-0.5 text-[10px] text-muted-foreground">
        Gymnase (optionnel)
        <VenueSelect
          aria-label={`Gymnase du créneau idéal de ${team.name}`}
          className="h-8"
          wrapperClassName="w-36"
          placeholder="—"
          venues={venues.map((v) => ({ id: v.id, name: v.name, color: v.color }))}
          value={venueId}
          onValueChange={setVenueId}
        />
      </div>

      <Button size="icon" className="size-8" aria-label={`Enregistrer le créneau idéal de ${team.name}`} title="Enregistrer" disabled={!canSave} onClick={save}>
        <Check className="size-4" />
      </Button>
      {null !== habit ? (
        <Button
          variant="ghost"
          size="icon"
          className="size-8 text-destructive"
          aria-label={`Supprimer le créneau idéal de ${team.name}`}
          title="Supprimer"
          disabled={remove.isPending}
          onClick={() => remove.mutate(habit.id)}
        >
          <Trash2 className="size-4" />
        </Button>
      ) : null}
    </li>
  );
}
