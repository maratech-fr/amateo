import { Link2 } from "lucide-react";
import { useMemo, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { readFailed } from "@/shared/lib/readState";

import type { Team, Venue } from "./api";
import { HabitsLinksDialog } from "./HabitsLinksDialog";
import { IdealSlotsEditor } from "./IdealSlotsEditor";
import { TypicalWeekendGrid } from "./TypicalWeekendGrid";
import { matchMinutesByCategory } from "./lib/weekendGrid";
import { usePriorityTiers, useSportCategoryDurations, useTeamMatchHabits, useTeams, useVenues } from "./queries";

function byId<T extends { id: string }>(rows: T[] | undefined): Map<string, T> {
  return new Map((rows ?? []).map((row) => [row.id, row]));
}

/**
 * PR 2a « Configuration & navigation » — la « Semaine type », page sœur de la Configuration
 * (route `/matchs/semaine-type`, deep-linkable). Elle porte le gabarit idéal (l'image A/B en
 * vedette) et l'éditeur des créneaux idéaux : le MODÈLE que le placement respecte au maximum, sans
 * dates. Les « Passerelles » (liens entre équipes) s'ouvrent d'ici ; les créneaux idéaux se
 * saisissent en place dans `IdealSlotsEditor` (P4-271).
 */
export function TypicalWeekPage() {
  const teams = useTeams();
  const tiers = usePriorityTiers();
  const venues = useVenues();
  const habitsQuery = useTeamMatchHabits();
  const categoryDurations = useSportCategoryDurations();

  const [linksDialogOpen, setLinksDialogOpen] = useState(false);

  const teamsMap = useMemo<Map<string, Team>>(() => byId(teams.data), [teams.data]);
  const venuesMap = useMemo<Map<string, Venue>>(() => byId(venues.data), [venues.data]);
  // P4-206 — même source de durées que le Calendrier (`CalendarPage`) : la durée réelle par
  // catégorie, déjà résolue par le serveur. On SÉLECTIONNE, on ne redérive jamais.
  const matchDurations = useMemo(() => matchMinutesByCategory(categoryDurations.data ?? []), [categoryDurations.data]);

  // UXS-08 — page monolithique : elle est gatée sur SES CINQ lectures (une seule vue, pas de
  // sections indépendantes). Un échec cède à une alerte avec réessai groupé ; un chargement à un
  // spinner de page — jamais un « Aucun créneau idéal » (vide crédible) sur une lecture en vol.
  // Les durées de catégorie SONT gatées avec le reste : pas de repli silencieux `?? 105`, la grille
  // ne se dessine qu'une fois la durée réelle par équipe connue.
  if (readFailed(teams) || readFailed(tiers) || readFailed(venues) || readFailed(habitsQuery) || readFailed(categoryDurations)) {
    return (
      <div className="flex flex-col gap-4">
        <LoadErrorHint
          onRetry={() => {
            for (const q of [teams, tiers, venues, habitsQuery, categoryDurations]) {
              void q.refetch();
            }
          }}
        />
      </div>
    );
  }
  if (undefined === teams.data || undefined === tiers.data || undefined === venues.data || undefined === habitsQuery.data || undefined === categoryDurations.data) {
    return <FullPageSpinner />;
  }
  const teamsData = teams.data;
  const tiersData = tiers.data;
  const venuesData = venues.data;
  const habitsData = habitsQuery.data;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-start justify-between gap-2">
        <div>
          <h2 className="text-base font-semibold">Semaine type</h2>
          <p className="text-sm text-muted-foreground">
            Les créneaux idéaux de toutes les équipes, sans dates — le modèle que le placement respecte au maximum.
          </p>
        </div>
        <Button variant="outline" size="sm" className="shrink-0" onClick={() => setLinksDialogOpen(true)}>
          <Link2 className="size-4" aria-hidden="true" />
          Passerelles
        </Button>
      </div>

      <div className="h-[32rem] lg:h-[40rem]">
        <TypicalWeekendGrid habits={habitsData} venues={venuesMap} teams={teamsMap} durations={matchDurations} />
      </div>

      {/* Les créneaux idéaux (jour · heure · gymnase · semaine A/B) — l'éditeur porte son propre `<h3>`. */}
      <div id="creneaux" className="border-t border-border pt-4">
        <IdealSlotsEditor teams={teamsData} venues={venuesData} />
      </div>

      {linksDialogOpen ? <HabitsLinksDialog teams={teamsData} tiers={tiersData} onClose={() => setLinksDialogOpen(false)} /> : null}
    </div>
  );
}
