import { Plus, Sparkles, Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { Select } from "@/shared/components/ui/select";
import { TeamSelect } from "@/shared/components/ui/team-select";

import type { Fixture, PriorityTier, Team, Venue } from "./api";
import { inferHabits } from "./lib/habitInference";
import { useCreateTeamMatchHabit, useDeleteTeamMatchHabit, useTeamMatchHabits } from "./queries";
import { TeamLinksSection } from "./TeamLinksSection";

const DAY_LABELS = ["", "Lundi", "Mardi", "Mercredi", "Jeudi", "Vendredi", "Samedi", "Dimanche"];

interface HabitsLinksDialogProps {
  teams: Team[];
  tiers: PriorityTier[];
  venues: Venue[];
  fixtures: Fixture[];
  onClose: () => void;
}

/**
 * Habitudes & passerelles (P1-4 PR C) — the preferences layer of the module:
 * per-team habitual windows (declared, with inference SUGGESTIONS — accepted,
 * never imposed) and declared team bridges. Consumed today by the radar
 * estimation, the placement prefill and the grid ghosts; by the PR D solver
 * tomorrow.
 */
export function HabitsLinksDialog({ teams, tiers, venues, fixtures, onClose }: HabitsLinksDialogProps) {
  const habitsQuery = useTeamMatchHabits();
  const createHabit = useCreateTeamMatchHabit();
  const deleteHabit = useDeleteTeamMatchHabit();

  const [habitTeamId, setHabitTeamId] = useState(teams[0]?.id ?? "");
  const [habitDay, setHabitDay] = useState(6);
  const [habitTime, setHabitTime] = useState("15:30");
  const [habitVenueId, setHabitVenueId] = useState("");

  const habits = habitsQuery.data ?? [];
  const suggestions = inferHabits(fixtures, habits);

  const venueName = (id: string | null): string | null => (null === id ? null : (venues.find((v) => v.id === id)?.name ?? null));

  const teamHabits = habits.filter((h) => h.teamId === habitTeamId);
  const teamSuggestions = suggestions.filter((s) => s.teamId === habitTeamId);

  const addHabit = (): void => {
    if ("" === habitTeamId || "" === habitTime || createHabit.isPending) {
      return;
    }
    createHabit.mutate({ teamId: habitTeamId, dayOfWeek: habitDay, kickoffTime: habitTime, ...("" !== habitVenueId ? { venueId: habitVenueId } : {}) });
  };

  return (
    <Modal
      label="Habitudes et passerelles"
      title="Habitudes & passerelles"
      onClose={onClose}
      size="lg"
      footer={
        <Button variant="outline" size="sm" onClick={onClose}>
          Fermer
        </Button>
      }
    >
      <div className="flex flex-col gap-4">
        <section className="flex flex-col gap-2">
          <h3 className="text-sm font-semibold">Habitudes de match</h3>
          <p className="text-xs text-muted-foreground">
            « SF3 joue le dimanche à 17h30 » — l'habitude pré-remplit le placement, protège les week-ends dont le
            calendrier n'est pas encore sorti (bloc « fenêtre protégée » sur la grille), et donne une heure
            estimée aux matchs extérieur sans horaire.
          </p>

          <TeamSelect aria-label="Équipe de l'habitude" teams={teams} tiers={tiers} value={habitTeamId} onChange={(e) => setHabitTeamId(e.target.value)} />

          {habitsQuery.isError ? <p className="text-sm text-destructive">Les habitudes n’ont pas pu être chargées.</p> : null}

          <ul className="flex flex-col gap-1">
            {teamHabits.map((habit) => (
              <li key={habit.id} className="flex items-center justify-between gap-2 rounded-md border border-border px-2 py-1 text-sm">
                <span>
                  {DAY_LABELS[habit.dayOfWeek] ?? "?"} {habit.kickoffTime}
                  {null !== venueName(habit.venueId) ? ` · ${venueName(habit.venueId)}` : ""}
                </span>
                <Button
                  variant="ghost"
                  size="icon"
                  className="size-7"
                  aria-label={`Supprimer l'habitude ${DAY_LABELS[habit.dayOfWeek] ?? "?"} ${habit.kickoffTime}`}
                  disabled={deleteHabit.isPending}
                  onClick={() => deleteHabit.mutate(habit.id)}
                >
                  <Trash2 className="size-4" />
                </Button>
              </li>
            ))}
          </ul>

          {teamSuggestions.map((suggestion) => (
            <div key={`${suggestion.teamId}-${suggestion.dayOfWeek}`} className="flex items-center justify-between gap-2 rounded-md border border-dashed border-accent/50 bg-accent/5 px-2 py-1 text-sm">
              <span className="flex items-center gap-1">
                <Sparkles className="size-3.5 shrink-0 text-accent" />
                Suggestion : {DAY_LABELS[suggestion.dayOfWeek] ?? "?"} {suggestion.kickoffTime}
                {null !== venueName(suggestion.venueId) ? ` · ${venueName(suggestion.venueId)}` : ""} ({suggestion.count} matchs)
              </span>
              <Button
                variant="outline"
                size="sm"
                disabled={createHabit.isPending}
                onClick={() =>
                  createHabit.mutate({
                    teamId: suggestion.teamId,
                    dayOfWeek: suggestion.dayOfWeek,
                    kickoffTime: suggestion.kickoffTime,
                    ...(null !== suggestion.venueId ? { venueId: suggestion.venueId } : {}),
                  })
                }
              >
                Accepter
              </Button>
            </div>
          ))}

          <div className="flex items-end gap-2">
            <label className="flex flex-col gap-1 text-xs text-muted-foreground">
              Jour
              <Select aria-label="Jour de l'habitude" className="h-8 w-28" value={habitDay} onChange={(e) => setHabitDay(Number(e.target.value))}>
                {[1, 2, 3, 4, 5, 6, 7].map((day) => (
                  <option key={day} value={day}>
                    {DAY_LABELS[day]}
                  </option>
                ))}
              </Select>
            </label>
            <label className="flex flex-col gap-1 text-xs text-muted-foreground">
              Heure
              <input aria-label="Heure de l'habitude" type="time" className="h-8 rounded-md border border-border bg-background px-2 text-sm" value={habitTime} onChange={(e) => setHabitTime(e.target.value)} />
            </label>
            <label className="flex flex-col gap-1 text-xs text-muted-foreground">
              Gymnase (optionnel)
              <Select aria-label="Gymnase de l'habitude" className="h-8 w-36" value={habitVenueId} onChange={(e) => setHabitVenueId(e.target.value)}>
                <option value="">—</option>
                {venues.map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.name}
                  </option>
                ))}
              </Select>
            </label>
            <Button size="icon" className="size-8" aria-label="Ajouter l'habitude" title="Ajouter l'habitude" disabled={"" === habitTeamId || "" === habitTime || createHabit.isPending} onClick={addHabit}>
              <Plus className="size-4" />
            </Button>
          </div>
        </section>

        {/* Passerelles — extraites en `TeamLinksSection` (P2-45) pour être rejouées, en lecture
            seule, dans l'étape Équipes du wizard. Ici (module matchs), la section reste ÉDITABLE ;
            le séparateur qui la détachait des habitudes est porté par le wrapper. */}
        <div className="border-t border-border pt-3">
          <TeamLinksSection teams={teams} tiers={tiers} />
        </div>

      </div>
    </Modal>
  );
}
