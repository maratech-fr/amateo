import { DoorOpen, Link2, Repeat, Upload } from "lucide-react";
import { useMemo, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Modal } from "@/shared/components/ui/modal";
import { Select } from "@/shared/components/ui/select";

import type { Team, Venue } from "./api";
import { FfbbEngagementsDialog } from "./FfbbEngagementsDialog";
import { HabitsLinksDialog } from "./HabitsLinksDialog";
import { ImportFbiDialog } from "./ImportFbiDialog";
import { buildTypicalWeekend } from "./lib/typicalWeekend";
import { MatchWindowsEditor } from "./MatchWindowsEditor";
import { useFixtures, usePriorityTiers, useTeamMatchHabits, useTeams, useVenues } from "./queries";
import { TypicalWeekendGrid } from "./TypicalWeekendGrid";

function byId<T extends { id: string }>(rows: T[] | undefined): Map<string, T> {
  return new Map((rows ?? []).map((row) => [row.id, row]));
}

/**
 * RMM-1 PR2 — l'espace SET-UP (rare, cadrage §3.1 / §6ter). Ce qui ne sert PAS
 * chaque semaine vit ici, hors de la boucle : l'image A/B en VEDETTE (le gabarit
 * idéal, désormais un écran de plein droit et non plus derrière un toggle), les
 * trois réglages rares (engagements FFBB, accès match, habitudes & passerelles)
 * groupés comme un cluster « règles », et — bien séparé, car c'est une opération
 * de données périodique et non un réglage — la seconde entrée d'import FBI (le
 * dépôt saisonnier, même dialogue que l'étape 1 de la boucle).
 *
 * ⚠ Les dialogues NE CHANGENT PAS : ils déménagent d'ancrage, c'est tout.
 */
export function ConfigurationPage() {
  const teams = useTeams();
  const tiers = usePriorityTiers();
  const venues = useVenues();
  const fixtures = useFixtures();
  const habitsQuery = useTeamMatchHabits();

  const [ffbbDialogOpen, setFfbbDialogOpen] = useState(false);
  const [habitsDialogOpen, setHabitsDialogOpen] = useState(false);
  const [accessDialogOpen, setAccessDialogOpen] = useState(false);
  const [accessVenueId, setAccessVenueId] = useState("");
  const [importDialogOpen, setImportDialogOpen] = useState(false);

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
            <TypicalWeekendGrid model={buildTypicalWeekend(habitsQuery.data ?? [])} venues={venuesMap} teams={teamsMap} />
          </div>
        </CardContent>
      </Card>

      {/* 2. Les trois réglages rares, groupés. */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Réglages de saison</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="mb-3 text-sm text-muted-foreground">
            Ce qui se règle en début de saison — rarement retouché ensuite.
          </p>
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" size="sm" onClick={() => setFfbbDialogOpen(true)}>
              <Link2 className="size-4" />
              Engagements FFBB
            </Button>
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

      {/* 3. Le dépôt saisonnier FBI — opération de données, séparée des réglages. */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Dépôt saisonnier FBI</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="mb-3 text-sm text-muted-foreground">
            L'export FBI complet du club, en début de saison ou de phase — même dialogue que
            l'import de la boucle hebdomadaire.
          </p>
          <Button variant="outline" size="sm" onClick={() => setImportDialogOpen(true)}>
            <Upload className="size-4" />
            Importer FBI
          </Button>
        </CardContent>
      </Card>

      {ffbbDialogOpen ? <FfbbEngagementsDialog teams={teams.data ?? []} tiers={tiers.data ?? []} onClose={() => setFfbbDialogOpen(false)} /> : null}
      {habitsDialogOpen ? (
        <HabitsLinksDialog teams={teams.data ?? []} tiers={tiers.data ?? []} venues={venues.data ?? []} fixtures={fixtures.data ?? []} onClose={() => setHabitsDialogOpen(false)} />
      ) : null}
      {importDialogOpen ? <ImportFbiDialog teams={teams.data ?? []} tiers={tiers.data ?? []} onClose={() => setImportDialogOpen(false)} /> : null}
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
            <Select aria-label="Gymnase des accès match" value={accessVenueId} onChange={(e) => setAccessVenueId(e.target.value)}>
              {(venues.data ?? []).map((v) => (
                <option key={v.id} value={v.id}>
                  {v.name}
                </option>
              ))}
            </Select>
            {"" !== accessVenueId ? <MatchWindowsEditor venueId={accessVenueId} /> : null}
          </div>
        </Modal>
      ) : null}
    </div>
  );
}
