import { DoorOpen, Repeat } from "lucide-react";
import { type ReactNode, useMemo, useState } from "react";
import { useSearchParams } from "react-router";

import { AccordionSection } from "@/shared/components/ui/accordion";
import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { VenueSelect } from "@/shared/components/ui/venue-select";

import type { Team, Venue } from "./api";
import { type ConfigSection, applySectionToParams, decodeSectionParam } from "./lib/urlState";
import { deadlinesSummary, durationsSummary, labelsSummary, opponentsSummary, rotationsSummary } from "./lib/configSummaries";
import { VenueLabelsSection } from "./VenueLabelsSection";
import { HabitsLinksDialog } from "./HabitsLinksDialog";
import { EntryDeadlinesEditor } from "./EntryDeadlinesEditor";
import { MatchDurationsEditor } from "./MatchDurationsEditor";
import { MatchSlotRotationsEditor } from "./MatchSlotRotationsEditor";
import { OpponentTravelCard } from "./OpponentTravelCard";
import { MatchWindowsEditor } from "./MatchWindowsEditor";
import { useCompetitions, useFixtures, useMatchSlotRotations, useOpponentTravel, usePriorityTiers, useSportCategoryDurations, useTeamMatchHabits, useTeams, useVenues } from "./queries";
import { TypicalWeekendGrid } from "./TypicalWeekendGrid";

function byId<T extends { id: string }>(rows: T[] | undefined): Map<string, T> {
  return new Map((rows ?? []).map((row) => [row.id, row]));
}

/**
 * En-tête d'une section : le libellé (semibold, hérité du bouton d'accordéon) suivi,
 * s'il existe, d'un résumé discret — le NOM ACCESSIBLE du bouton devient « Créneaux
 * partagés (alternance) · 3 rotations ». `null` (chargement/échec) ⇒ pas de résumé.
 */
function sectionTitle(label: string, summary: string | null): ReactNode {
  return (
    <span>
      {label}
      {/* Le séparateur « · » vit dans un nœud texte du PARENT, pas dans le <span> du
          résumé : le calcul de nom accessible (dom-accessibility-api) rogne les espaces
          de bord d'un élément enfant, ce qui collerait « (alternance)· 2 rotations ».
          Un point médian encadré d'espaces au niveau parent survit → « … · 2 rotations ». */}
      {null !== summary ? (
        <>
          {" · "}
          <span className="text-xs font-normal text-muted-foreground">{summary}</span>
        </>
      ) : null}
    </span>
  );
}

/**
 * RMM-1 PR2 — l'espace SET-UP (rare, cadrage §3.1 / §6ter). Ce qui ne sert PAS
 * chaque semaine vit ici, hors de la boucle : l'image A/B en VEDETTE (le gabarit
 * idéal), les créneaux partagés, les échéances, la durée des matchs, les
 * adversaires à localiser, et les deux réglages rares (accès match, habitudes &
 * passerelles). Depuis PR-3b (P4-186), tout ce qui touche les DONNÉES FBI/FFBB
 * (dépôt saisonnier, canal API, engagements) a déménagé dans l'onglet Importer :
 * la Configuration ne porte plus que des RÉGLAGES.
 *
 * P4-185 — « une section = un écran » : les cartes sont des accordéons CONTRÔLÉS,
 * un seul ouvert à la fois, ancré `?section=` (deep-link, patron `ReviewQueue`).
 * Le gabarit est ouvert par défaut ; `section=aucune` = tout replié.
 *
 * P4-196 — 7ᵉ section « Libellés FFBB des gymnases » (dernière) : voir/retirer les
 * alias de salle FBI/FFBB confirmés (le pendant du geste « Rattacher » d'Importer).
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
  // Même clé de query que `OpponentTravelCard` (zéro requête nouvelle) — sert le
  // résumé d'en-tête ; la carte reste intacte à l'intérieur de la section.
  const opponentTravel = useOpponentTravel();

  const [habitsDialogOpen, setHabitsDialogOpen] = useState(false);
  const [accessDialogOpen, setAccessDialogOpen] = useState(false);
  const [accessVenueId, setAccessVenueId] = useState("");

  const [searchParams, setSearchParams] = useSearchParams();
  const openSection = decodeSectionParam(searchParams);
  const setOpenSection = (section: ConfigSection | null): void => {
    setSearchParams(applySectionToParams(searchParams, section), { replace: true });
  };
  const sectionProps = (key: ConfigSection) => ({
    open: openSection === key,
    onToggle: (next: boolean): void => setOpenSection(next ? key : null),
  });

  const teamsMap = useMemo<Map<string, Team>>(() => byId(teams.data), [teams.data]);
  const venuesMap = useMemo<Map<string, Venue>>(() => byId(venues.data), [venues.data]);

  return (
    <div className="flex flex-col gap-3">
      {/* 1. L'image A/B en vedette — la référence vers laquelle tout est réglé. */}
      <AccordionSection {...sectionProps("gabarit")} title={sectionTitle("Le gabarit idéal — la semaine type", null)}>
        <p className="mb-3 text-sm text-muted-foreground">
          Les habitudes de toutes les équipes, sans dates — le modèle de référence que le placement
          respecte au maximum. Il se dessine dans « Habitudes &amp; passerelles ».
        </p>
        <div className="h-[32rem]">
          <TypicalWeekendGrid habits={habitsQuery.data ?? []} rotations={rotationsQuery.data ?? []} venues={venuesMap} teams={teamsMap} />
        </div>
      </AccordionSection>

      {/* 1bis. Les créneaux partagés (alternance A/B) — la pénurie de créneaux dessinée. */}
      <AccordionSection {...sectionProps("creneaux")} title={sectionTitle("Créneaux partagés (alternance)", rotationsSummary(rotationsQuery.data))}>
        <MatchSlotRotationsEditor teams={teams.data ?? []} tiers={tiers.data ?? []} venues={venues.data ?? []} />
      </AccordionSection>

      {/* 1ter. Les échéances de saisie ligue/comité — une par compétition. */}
      <AccordionSection {...sectionProps("echeances")} title={sectionTitle("Échéances de saisie", deadlinesSummary(competitions.data))}>
        <EntryDeadlinesEditor competitions={competitions.data ?? []} teams={teams.data ?? []} />
      </AccordionSection>

      {/* 1quater. La durée des matchs — un réglage par catégorie (P2-54 RMM-9). */}
      <AccordionSection {...sectionProps("durees")} title={sectionTitle("Durée des matchs", durationsSummary(categoryDurations.data))}>
        <MatchDurationsEditor categories={categoryDurations.data ?? []} />
      </AccordionSection>

      {/* 1quinquies. Le trajet adverse — le radar de conflits devient SPATIAL (P2-54 PR-3). */}
      <AccordionSection {...sectionProps("adversaires")} title={sectionTitle("Adversaires à localiser", opponentsSummary(opponentTravel.data))}>
        <OpponentTravelCard />
      </AccordionSection>

      {/* 2. Les réglages rares, groupés. */}
      <AccordionSection {...sectionProps("reglages")} title={sectionTitle("Réglages de saison", null)}>
        <p className="mb-3 text-sm text-muted-foreground">Ce qui se règle en début de saison — rarement retouché ensuite.</p>
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
      </AccordionSection>

      {/* 3. Les libellés FFBB des gymnases — voir/retirer les alias de salle (P4-196). */}
      <AccordionSection {...sectionProps("libelles")} title={sectionTitle("Libellés FFBB des gymnases", labelsSummary(venues.data))}>
        <VenueLabelsSection venues={venues.data} />
      </AccordionSection>

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
