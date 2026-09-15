import { Repeat } from "lucide-react";
import { useMemo, useState } from "react";

import { Button } from "@/shared/components/ui/button";

import type { Team, Venue } from "./api";
import { HabitsLinksDialog } from "./HabitsLinksDialog";
import { MatchSlotRotationsEditor } from "./MatchSlotRotationsEditor";
import { TypicalWeekendGrid } from "./TypicalWeekendGrid";
import { useFixtures, useMatchSlotRotations, usePriorityTiers, useTeamMatchHabits, useTeams, useVenues } from "./queries";

function byId<T extends { id: string }>(rows: T[] | undefined): Map<string, T> {
  return new Map((rows ?? []).map((row) => [row.id, row]));
}

/**
 * PR 2a « Configuration & navigation » — la « Semaine type », page sœur de la Configuration
 * (route `/matchs/semaine-type`, deep-linkable). Elle porte ce qui était le gabarit idéal (l'image
 * A/B en vedette) et les créneaux partagés (alternance) : le MODÈLE que le placement respecte au
 * maximum, sans dates. Les « Habitudes & passerelles » — où se dessine ce modèle — s'ouvrent d'ici.
 */
export function TypicalWeekPage() {
  const teams = useTeams();
  const tiers = usePriorityTiers();
  const venues = useVenues();
  const fixtures = useFixtures();
  const habitsQuery = useTeamMatchHabits();
  const rotationsQuery = useMatchSlotRotations();

  const [habitsDialogOpen, setHabitsDialogOpen] = useState(false);

  const teamsMap = useMemo<Map<string, Team>>(() => byId(teams.data), [teams.data]);
  const venuesMap = useMemo<Map<string, Venue>>(() => byId(venues.data), [venues.data]);

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start justify-between gap-2">
        <div>
          <h2 className="text-base font-semibold">Semaine type</h2>
          <p className="text-sm text-muted-foreground">
            Les habitudes de toutes les équipes, sans dates — le modèle que le placement respecte au maximum.
          </p>
        </div>
        <Button variant="outline" size="sm" className="shrink-0" onClick={() => setHabitsDialogOpen(true)}>
          <Repeat className="size-4" aria-hidden="true" />
          Habitudes &amp; passerelles
        </Button>
      </div>

      <div className="h-[32rem] lg:h-[40rem]">
        <TypicalWeekendGrid habits={habitsQuery.data ?? []} rotations={rotationsQuery.data ?? []} venues={venuesMap} teams={teamsMap} />
      </div>

      {/* Les créneaux partagés (alternance A/B) — l'éditeur porte déjà son propre `<h3>`. */}
      <div id="creneaux" className="border-t border-border pt-4">
        <MatchSlotRotationsEditor teams={teams.data ?? []} tiers={tiers.data ?? []} venues={venues.data ?? []} />
      </div>

      {habitsDialogOpen ? (
        <HabitsLinksDialog teams={teams.data ?? []} tiers={tiers.data ?? []} venues={venues.data ?? []} fixtures={fixtures.data ?? []} onClose={() => setHabitsDialogOpen(false)} />
      ) : null}
    </div>
  );
}
