import { AlertTriangle, Check, Pencil, Plus, Trash2, X } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Select } from "@/shared/components/ui/select";
import { TeamSelect } from "@/shared/components/ui/team-select";
import { VenueSelect } from "@/shared/components/ui/venue-select";
import { compareTeamsByRank, type TeamLike, type TierLike } from "@/shared/lib/teamTiers";

import type { ClubRuleCoherenceRuleRef, MatchWeek, TeamMatchHabit, Venue } from "./api";
import { clubRuleLabel } from "./lib/clubRuleLabel";
import { useCreateTeamMatchHabit, useDeleteTeamMatchHabit, useMatchConstraintCoherence, useTeamMatchHabits, useUpdateTeamMatchHabit } from "./queries";

const DAY_LABELS = ["", "Lundi", "Mardi", "Mercredi", "Jeudi", "Vendredi", "Samedi", "Dimanche"];
const WEEK_LABELS: Record<MatchWeek, string> = { A: "Semaine A", B: "Semaine B" };

/** Le résumé d'un créneau au repos : « Semaine A · Samedi 13:00 · Matéo » (semaine omise si le club n'alterne pas). */
function slotSummary(habit: TeamMatchHabit, venuesById: Map<string, Venue>, weekendAlternates: boolean): string {
  const parts: string[] = [];
  if (weekendAlternates) {
    parts.push(WEEK_LABELS[habit.week]);
  }
  parts.push(`${DAY_LABELS[habit.dayOfWeek]} ${habit.kickoffTime}`);
  if (null !== habit.venueId) {
    const venue = venuesById.get(habit.venueId);
    if (undefined !== venue) {
      parts.push(venue.name);
    }
  }
  return parts.join(" · ");
}

/**
 * P4-271 — l'éditeur « Créneaux idéaux » du SET-UP (`/matchs/semaine-type`) : UNE ligne par équipe
 * AYANT un créneau idéal, en LIGNE COMPACTE au repos (résumé texte + ✎ + 🗑). ✎ déplie la ligne en
 * champs (semaine A/B si le club alterne · jour · heure · gymnase optionnel) ; Enregistrer/Annuler
 * la replie. Un bouton « Ajouter » ouvre une ligne pour une équipe SANS créneau (TeamSelect des
 * seules équipes qui n'en ont pas encore). Une équipe = un seul créneau idéal.
 *
 * Le créneau idéal est une AIDE VISUELLE — le modèle du gestionnaire, attiré comme un BONUS par le
 * solveur, jamais une obligation. Le champ Semaine n'apparaît que si le club déclare alterner
 * (`weekendAlternates`, vérité serveur) ; sinon les créneaux restent en semaine A. Primitives
 * partagées seulement (`Select`, `VenueSelect`, `TeamSelect`, `Button`). Générique sur `TeamLike`.
 */
export function IdealSlotsEditor<T extends TeamLike>({
  teams,
  venues,
  weekendAlternates,
  tiers = [],
}: {
  teams: T[];
  venues: Venue[];
  weekendAlternates: boolean;
  tiers?: (TierLike & { color?: string | null })[];
}) {
  const habitsQuery = useTeamMatchHabits();
  const habits = habitsQuery.data ?? [];
  const habitByTeam = new Map(habits.map((h) => [h.teamId, h]));
  const venuesById = new Map(venues.map((v) => [v.id, v]));
  const orderedTeams = [...teams].sort(compareTeamsByRank);
  // Section 6 : au repos, seules les équipes AYANT un créneau sont listées.
  const withSlot = orderedTeams.filter((team) => habitByTeam.has(team.id));
  const withoutSlot = orderedTeams.filter((team) => !habitByTeam.has(team.id));

  const [adding, setAdding] = useState(false);

  // P4-272 ③ — l'alerte de cohérence est CALCULÉE côté serveur (`/coherence`) ; on
  // AFFICHE, sous le créneau idéal concerné, les règles du club qu'il heurte.
  const coherence = useMatchConstraintCoherence();
  const rulesByHabit = new Map((coherence.data?.byHabit ?? []).map((h) => [h.habitId, h.rules]));

  return (
    <section className="flex flex-col gap-3">
      <div>
        <h3 className="text-sm font-semibold">Créneaux idéaux</h3>
        <p className="text-xs text-muted-foreground">
          Le créneau que chaque équipe recevrait « si elle avait le choix » — jour, heure, gymnase{weekendAlternates ? ", et la semaine A/B" : ""}.
          Une aide visuelle : le placement s'en approche, la fédération décide qui reçoit.
        </p>
      </div>

      {habitsQuery.isError ? <p className="text-sm text-destructive">Les créneaux idéaux n’ont pas pu être chargés.</p> : null}

      {0 === orderedTeams.length ? (
        <EmptyHint>Aucune équipe — les équipes se déclarent dans l’assistant de saisie.</EmptyHint>
      ) : (
        <>
          {0 === withSlot.length && !adding ? (
            <EmptyHint>Aucune équipe n’a encore de créneau idéal — ajoutez-en un.</EmptyHint>
          ) : (
            <ul className="flex flex-col gap-1">
              {withSlot.map((team) => {
                const habit = habitByTeam.get(team.id) ?? null;
                return (
                  <IdealSlotRow
                    key={team.id}
                    team={team}
                    habit={habit}
                    venues={venues}
                    venuesById={venuesById}
                    weekendAlternates={weekendAlternates}
                    clubRuleAlerts={null !== habit ? (rulesByHabit.get(habit.id) ?? []) : []}
                  />
                );
              })}
            </ul>
          )}

          {adding ? (
            <IdealSlotAddForm
              teams={withoutSlot}
              tiers={tiers}
              venues={venues}
              weekendAlternates={weekendAlternates}
              onDone={() => setAdding(false)}
            />
          ) : (
            <Button
              variant="outline"
              size="sm"
              className="w-fit"
              disabled={0 === withoutSlot.length}
              title={0 === withoutSlot.length ? "Toutes les équipes ont un créneau idéal" : undefined}
              onClick={() => setAdding(true)}
            >
              <Plus className="size-4" aria-hidden="true" />
              Ajouter un créneau idéal
            </Button>
          )}
        </>
      )}
    </section>
  );
}

/** Les champs éditables d'un créneau (semaine si le club alterne · jour · heure · gymnase). Présentation pure. */
function SlotFields({
  teamName,
  week,
  setWeek,
  day,
  setDay,
  time,
  setTime,
  venueId,
  setVenueId,
  venues,
  weekendAlternates,
}: {
  teamName: string;
  week: MatchWeek;
  setWeek: (w: MatchWeek) => void;
  day: number;
  setDay: (d: number) => void;
  time: string;
  setTime: (t: string) => void;
  venueId: string;
  setVenueId: (v: string) => void;
  venues: Venue[];
  weekendAlternates: boolean;
}) {
  return (
    <>
      {weekendAlternates ? (
        <label className="flex flex-col gap-0.5 text-[10px] text-muted-foreground">
          Semaine
          <Select aria-label={`Semaine du créneau idéal de ${teamName}`} className="h-8 w-28" value={week} onChange={(e) => setWeek(e.target.value as MatchWeek)}>
            <option value="A">{WEEK_LABELS.A}</option>
            <option value="B">{WEEK_LABELS.B}</option>
          </Select>
        </label>
      ) : null}

      <label className="flex flex-col gap-0.5 text-[10px] text-muted-foreground">
        Jour
        <Select aria-label={`Jour du créneau idéal de ${teamName}`} className="h-8 w-28" value={day} onChange={(e) => setDay(Number(e.target.value))}>
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
          aria-label={`Heure du créneau idéal de ${teamName}`}
          type="time"
          className="h-8 rounded-md border border-border bg-background px-2 text-sm"
          value={time}
          onChange={(e) => setTime(e.target.value)}
        />
      </label>

      <div className="flex flex-col gap-0.5 text-[10px] text-muted-foreground">
        Gymnase (optionnel)
        <VenueSelect
          aria-label={`Gymnase du créneau idéal de ${teamName}`}
          className="h-8"
          wrapperClassName="w-36"
          placeholder="—"
          venues={venues.map((v) => ({ id: v.id, name: v.name, color: v.color }))}
          value={venueId}
          onValueChange={setVenueId}
        />
      </div>
    </>
  );
}

function IdealSlotRow<T extends TeamLike>({
  team,
  habit,
  venues,
  venuesById,
  weekendAlternates,
  clubRuleAlerts,
}: {
  team: T;
  habit: TeamMatchHabit | null;
  venues: Venue[];
  venuesById: Map<string, Venue>;
  weekendAlternates: boolean;
  clubRuleAlerts: ClubRuleCoherenceRuleRef[];
}) {
  const update = useUpdateTeamMatchHabit();
  const remove = useDeleteTeamMatchHabit();

  const [editing, setEditing] = useState(false);
  const [week, setWeek] = useState<MatchWeek>(habit?.week ?? "A");
  const [day, setDay] = useState(habit?.dayOfWeek ?? 6);
  const [time, setTime] = useState(habit?.kickoffTime ?? "");
  const [venueId, setVenueId] = useState(habit?.venueId ?? "");

  const openEdit = (): void => {
    setWeek(habit?.week ?? "A");
    setDay(habit?.dayOfWeek ?? 6);
    setTime(habit?.kickoffTime ?? "");
    setVenueId(habit?.venueId ?? "");
    setEditing(true);
  };

  const saving = update.isPending;
  const dirty = null === habit ? false : week !== habit.week || day !== habit.dayOfWeek || time !== habit.kickoffTime || venueId !== (habit.venueId ?? "");
  const canSave = "" !== time && dirty && !saving && null !== habit;

  const save = (): void => {
    if (!canSave || null === habit) {
      return;
    }
    // Full-replace (P4-271) : gymnase `null` explicite le retire. Le club sans alternance
    // garde la semaine A (le champ est masqué mais l'état vaut A par défaut).
    const input = { teamId: team.id, dayOfWeek: day, kickoffTime: time, week, venueId: "" !== venueId ? venueId : null };
    update.mutate({ id: habit.id, input }, { onSuccess: () => setEditing(false) });
  };

  return (
    <li className="rounded-md border border-border bg-card px-2 py-1.5">
      {editing && null !== habit ? (
        <div className="flex flex-wrap items-end gap-2">
          <span className="min-w-32 flex-1 truncate self-center text-sm font-medium" title={team.name}>
            {team.name}
          </span>
          <SlotFields
            teamName={team.name}
            week={week}
            setWeek={setWeek}
            day={day}
            setDay={setDay}
            time={time}
            setTime={setTime}
            venueId={venueId}
            setVenueId={setVenueId}
            venues={venues}
            weekendAlternates={weekendAlternates}
          />
          <Button size="icon" className="size-8" aria-label={`Enregistrer le créneau idéal de ${team.name}`} title="Enregistrer" disabled={!canSave} onClick={save}>
            <Check className="size-4" />
          </Button>
          <Button variant="ghost" size="icon" className="size-8" aria-label={`Annuler la modification du créneau idéal de ${team.name}`} title="Annuler" onClick={() => setEditing(false)}>
            <X className="size-4" />
          </Button>
        </div>
      ) : (
        <div className="flex items-center gap-2">
          <span className="min-w-32 flex-1 truncate text-sm font-medium" title={team.name}>
            {team.name}
          </span>
          {null !== habit ? <span className="truncate text-sm text-muted-foreground">{slotSummary(habit, venuesById, weekendAlternates)}</span> : null}
          <Button variant="ghost" size="icon" className="ml-auto size-8" aria-label={`Modifier le créneau idéal de ${team.name}`} title="Modifier" onClick={openEdit}>
            <Pencil className="size-4" />
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
        </div>
      )}

      {clubRuleAlerts.length > 0 ? (
        <div className="mt-1 flex w-full flex-col gap-1 rounded-md border border-warning/40 bg-surface-warning px-3 py-1.5 text-sm text-foreground" role="status">
          {clubRuleAlerts.map((rule) => (
            <p key={rule.ruleId} className="flex items-start gap-1.5">
              <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
              <span>Heurte la règle du club « {clubRuleLabel(rule)} ».</span>
            </p>
          ))}
        </div>
      ) : null}
    </li>
  );
}

/** Section 6 — la ligne « Ajouter » : choix de l'équipe (parmi celles SANS créneau) + champs compacts. */
function IdealSlotAddForm<T extends TeamLike>({
  teams,
  tiers,
  venues,
  weekendAlternates,
  onDone,
}: {
  teams: T[];
  tiers: (TierLike & { color?: string | null })[];
  venues: Venue[];
  weekendAlternates: boolean;
  onDone: () => void;
}) {
  const create = useCreateTeamMatchHabit();

  const [teamId, setTeamId] = useState("");
  const [week, setWeek] = useState<MatchWeek>("A");
  const [day, setDay] = useState(6);
  const [time, setTime] = useState("");
  const [venueId, setVenueId] = useState("");

  const canSave = "" !== teamId && "" !== time && !create.isPending;
  const selectedName = teams.find((t) => t.id === teamId)?.name ?? "l’équipe";

  const save = (): void => {
    if (!canSave) {
      return;
    }
    const input = { teamId, dayOfWeek: day, kickoffTime: time, week, venueId: "" !== venueId ? venueId : null };
    create.mutate(input, { onSuccess: () => onDone() });
  };

  return (
    <div className="flex flex-wrap items-end gap-2 rounded-md border border-dashed border-border bg-card px-2 py-1.5">
      <div className="flex min-w-32 flex-1 flex-col gap-0.5 text-[10px] text-muted-foreground">
        Équipe
        <TeamSelect aria-label="Équipe du nouveau créneau idéal" className="h-8" wrapperClassName="w-44" teams={teams} tiers={tiers} placeholder="Choisir une équipe…" value={teamId} onValueChange={setTeamId} />
      </div>
      <SlotFields
        teamName={selectedName}
        week={week}
        setWeek={setWeek}
        day={day}
        setDay={setDay}
        time={time}
        setTime={setTime}
        venueId={venueId}
        setVenueId={setVenueId}
        venues={venues}
        weekendAlternates={weekendAlternates}
      />
      <Button size="icon" className="size-8" aria-label="Enregistrer le nouveau créneau idéal" title="Enregistrer" disabled={!canSave} onClick={save}>
        <Check className="size-4" />
      </Button>
      <Button variant="ghost" size="icon" className="size-8" aria-label="Annuler l’ajout d’un créneau idéal" title="Annuler" onClick={onDone}>
        <X className="size-4" />
      </Button>
    </div>
  );
}
