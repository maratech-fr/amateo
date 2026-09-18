import { Repeat } from "lucide-react";
import { useMemo, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { readFailed } from "@/shared/lib/readState";

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

  // UXS-08 — page monolithique : elle est gatée sur SES SIX lectures (une seule vue, pas de
  // sections indépendantes). Un échec cède à une alerte avec réessai groupé ; un chargement à un
  // spinner de page — jamais un « Aucune habitude déclarée » (vide crédible) sur une lecture en vol.
  if (readFailed(teams) || readFailed(tiers) || readFailed(venues) || readFailed(fixtures) || readFailed(habitsQuery) || readFailed(rotationsQuery)) {
    return (
      <div className="flex flex-col gap-4">
        <LoadErrorHint
          onRetry={() => {
            for (const q of [teams, tiers, venues, fixtures, habitsQuery, rotationsQuery]) {
              void q.refetch();
            }
          }}
        />
      </div>
    );
  }
  if (
    undefined === teams.data ||
    undefined === tiers.data ||
    undefined === venues.data ||
    undefined === fixtures.data ||
    undefined === habitsQuery.data ||
    undefined === rotationsQuery.data
  ) {
    return <FullPageSpinner />;
  }
  const teamsData = teams.data;
  const tiersData = tiers.data;
  const venuesData = venues.data;
  const fixturesData = fixtures.data;
  const habitsData = habitsQuery.data;
  const rotationsData = rotationsQuery.data;

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
        <TypicalWeekendGrid habits={habitsData} rotations={rotationsData} venues={venuesMap} teams={teamsMap} />
      </div>

      {/* Les créneaux partagés (alternance A/B) — l'éditeur porte déjà son propre `<h3>`. */}
      <div id="creneaux" className="border-t border-border pt-4">
        <MatchSlotRotationsEditor teams={teamsData} tiers={tiersData} venues={venuesData} />
      </div>

      {habitsDialogOpen ? (
        <HabitsLinksDialog teams={teamsData} tiers={tiersData} venues={venuesData} fixtures={fixturesData} onClose={() => setHabitsDialogOpen(false)} />
      ) : null}
    </div>
  );
}
