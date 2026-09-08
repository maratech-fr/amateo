import { DoorOpen, Repeat } from "lucide-react";
import { useMemo, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Modal } from "@/shared/components/ui/modal";
import { VenueSelect } from "@/shared/components/ui/venue-select";

import type { Team, Venue } from "./api";
import { HabitsLinksDialog } from "./HabitsLinksDialog";
import { EntryDeadlinesEditor } from "./EntryDeadlinesEditor";
import { MatchDurationsEditor } from "./MatchDurationsEditor";
import { MatchSlotRotationsEditor } from "./MatchSlotRotationsEditor";
import { OpponentTravelCard } from "./OpponentTravelCard";
import { MatchWindowsEditor } from "./MatchWindowsEditor";
import { useCompetitions, useFixtures, useMatchSlotRotations, usePriorityTiers, useSportCategoryDurations, useTeamMatchHabits, useTeams, useVenues } from "./queries";
import { TypicalWeekendGrid } from "./TypicalWeekendGrid";

function byId<T extends { id: string }>(rows: T[] | undefined): Map<string, T> {
  return new Map((rows ?? []).map((row) => [row.id, row]));
}

/**
 * RMM-1 PR2 — l'espace SET-UP (rare, cadrage §3.1 / §6ter). Ce qui ne sert PAS
 * chaque semaine vit ici, hors de la boucle : l'image A/B en VEDETTE (le gabarit
 * idéal), les créneaux partagés, les échéances, la durée des matchs, les
 * adversaires à localiser, et les deux réglages rares (accès match, habitudes &
 * passerelles). Depuis PR-3b (P4-186), tout ce qui touche les DONNÉES FBI/FFBB
 * (dépôt saisonnier, canal API, engagements) a déménagé dans l'onglet Importer :
 * la Configuration ne porte plus que des RÉGLAGES.
 */
export function ConfigurationPage() {
  const teams = useTeams();
  const tiers = usePriorityTiers();
  const venues = useVenues();
  const fixtures = useFixtures();
  const competitions = useCompetitions();
  const habitsQuery = useTeamMatchHabits();
  const rotationsQuery = useMatchSlotRotations();
  const categoryDurations = useSportCategoryDurations();

  const [habitsDialogOpen, setHabitsDialogOpen] = useState(false);
  const [accessDialogOpen, setAccessDialogOpen] = useState(false);
  const [accessVenueId, setAccessVenueId] = useState("");

  const teamsMap = useMemo<Map<string, Team>>(() => byId(teams.data), [teams.data]);
  const venuesMap = useMemo<Map<string, Venue>>(() => byId(venues.data), [venues.data]);

  return (
    <div className="flex flex-col gap-6">
      {/* 1. L'image A/B en vedette — la référence vers laquelle tout est réglé. */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Le gabarit idéal — la semaine type</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="mb-3 text-sm text-muted-foreground">
            Les habitudes de toutes les équipes, sans dates — le modèle de référence que le
            placement respecte au maximum. Il se dessine dans « Habitudes &amp; passerelles ».
          </p>
          <div className="h-[32rem]">
            <TypicalWeekendGrid habits={habitsQuery.data ?? []} rotations={rotationsQuery.data ?? []} venues={venuesMap} teams={teamsMap} />
          </div>
        </CardContent>
      </Card>

      {/* 1bis. Les créneaux partagés (alternance A/B) — la pénurie de créneaux dessinée. */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Créneaux partagés (alternance)</CardTitle>
        </CardHeader>
        <CardContent>
          <MatchSlotRotationsEditor teams={teams.data ?? []} tiers={tiers.data ?? []} venues={venues.data ?? []} />
        </CardContent>
      </Card>

      {/* 1ter. Les échéances de saisie ligue/comité — une par compétition. */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Échéances de saisie</CardTitle>
        </CardHeader>
        <CardContent>
          <EntryDeadlinesEditor competitions={competitions.data ?? []} teams={teams.data ?? []} />
        </CardContent>
      </Card>

      {/* 1quater. La durée des matchs — un réglage par catégorie (P2-54 RMM-9). */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Durée des matchs</CardTitle>
        </CardHeader>
        <CardContent>
          <MatchDurationsEditor categories={categoryDurations.data ?? []} />
        </CardContent>
      </Card>

      {/* 1quinquies. Le trajet adverse — le radar de conflits devient SPATIAL (P2-54 PR-3). */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Adversaires à localiser</CardTitle>
        </CardHeader>
        <CardContent>
          <OpponentTravelCard />
        </CardContent>
      </Card>

      {/* 2. Les réglages rares, groupés. */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Réglages de saison</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="mb-3 text-sm text-muted-foreground">
            Ce qui se règle en début de saison — rarement retouché ensuite.
          </p>
          <div className="flex flex-wrap gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setAccessVenueId(venues.data?.[0]?.id ?? "");
                setAccessDialogOpen(true);
              }}
            >
              <DoorOpen className="size-4" />
              Accès match
            </Button>
            <Button variant="outline" size="sm" onClick={() => setHabitsDialogOpen(true)}>
              <Repeat className="size-4" />
              Habitudes &amp; passerelles
            </Button>
          </div>
        </CardContent>
      </Card>

      {habitsDialogOpen ? (
        <HabitsLinksDialog teams={teams.data ?? []} tiers={tiers.data ?? []} venues={venues.data ?? []} fixtures={fixtures.data ?? []} onClose={() => setHabitsDialogOpen(false)} />
      ) : null}
      {accessDialogOpen ? (
        <Modal
          label="Accès match"
          title="Accès match des gymnases"
          onClose={() => setAccessDialogOpen(false)}
          size="lg"
          footer={
            <Button variant="outline" size="sm" onClick={() => setAccessDialogOpen(false)}>
              Fermer
            </Button>
          }
        >
          <div className="flex flex-col gap-3">
            <p className="text-xs text-muted-foreground">
              Les créneaux accordés les jours de match — un gymnase sans fenêtre n'accueille pas de
              matchs. Même éditeur que l'étape Gymnases du wizard.
            </p>
            <VenueSelect
              aria-label="Gymnase des accès match"
              venues={(venues.data ?? []).map((v) => ({ id: v.id, name: v.name, color: v.color }))}
              value={accessVenueId}
              onValueChange={setAccessVenueId}
            />
            {"" !== accessVenueId ? <MatchWindowsEditor venueId={accessVenueId} /> : null}
          </div>
        </Modal>
      ) : null}
    </div>
  );
}
