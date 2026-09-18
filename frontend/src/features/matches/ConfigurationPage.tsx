import { ChevronDown, Pencil } from "lucide-react";
import { type ReactNode, useId, useMemo, useState } from "react";
import { Navigate, useSearchParams } from "react-router";

import { AccordionSection } from "@/shared/components/ui/accordion";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Modal } from "@/shared/components/ui/modal";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { VenueSwatch } from "@/shared/components/ui/venue-swatch";
import { readFailed } from "@/shared/lib/readState";
import { cn } from "@/shared/lib/utils";

import type { Venue, VenueMatchWindow } from "./api";
import { type ConfigSection, applySectionToParams, decodeSectionParam } from "./lib/urlState";
import { accessSummary, deadlinesSummary, durationsSummary, labelsSummary, opponentsSummary } from "./lib/configSummaries";
import { formatMatchAccessWindows } from "./lib/matchAccessSummary";
import { VenueLabelsSection } from "./VenueLabelsSection";
import { EntryDeadlinesEditor } from "./EntryDeadlinesEditor";
import { MatchDurationsEditor } from "./MatchDurationsEditor";
import { OpponentTravelCard } from "./OpponentTravelCard";
import { MatchWindowsEditor } from "./MatchWindowsEditor";
import { useCompetitions, useOpponentTravel, useSportCategoryDurations, useTeams, useVenueLabelInventory, useVenueMatchWindows, useVenues } from "./queries";

/**
 * En-tête d'une section : le libellé (semibold, hérité du bouton d'accordéon) suivi,
 * s'il existe, d'un résumé discret — le NOM ACCESSIBLE du bouton devient « Accès match · 4 gymnases ».
 * `null` (chargement/échec) ⇒ pas de résumé.
 */
function sectionTitle(label: string, summary: string | null): ReactNode {
  return (
    <span>
      {label}
      {/* Le séparateur « · » vit dans un nœud texte du PARENT, pas dans le <span> du
          résumé : le calcul de nom accessible (dom-accessibility-api) rogne les espaces
          de bord d'un élément enfant, ce qui collerait « … · 2 gymnases » de travers.
          Un point médian encadré d'espaces au niveau parent survit → « … · 2 gymnases ». */}
      {null !== summary ? (
        <>
          {" · "}
          <span className="text-xs font-normal text-muted-foreground">{summary}</span>
        </>
      ) : null}
    </span>
  );
}

/** Une lecture réduite à ce dont `readState` a besoin, plus le `refetch` du retry. */
type SectionQuery<T> = { data: T | undefined; isError: boolean; refetch: () => unknown };

/**
 * UXS-08 — le corps d'une section est gaté sur SA lecture (doctrine `readState`). Un vide fabriqué
 * (`?? []`) pendant le chargement ferait croire « aucune donnée » et pousserait à re-saisir
 * (doublons) ; un échec propose de réessayer ; sinon l'éditeur, avec une donnée RÉELLEMENT présente.
 */
function SectionBody<T>({ query, render }: { query: SectionQuery<T>; render: (data: T) => ReactNode }): ReactNode {
  if (readFailed(query)) {
    return <LoadErrorHint onRetry={() => void query.refetch()} />;
  }
  if (undefined === query.data) {
    return <EmptyHint>Chargement…</EmptyHint>;
  }
  return render(query.data);
}

/**
 * RMM-1 PR2 — l'espace SET-UP (rare, cadrage §3.1 / §6ter). Ce qui ne sert PAS chaque semaine
 * vit ici, hors de la boucle : les échéances, la durée des matchs, les adversaires à localiser,
 * l'accès match des gymnases et les libellés FFBB.
 *
 * PR 2a « Configuration & navigation » — le gabarit idéal et les créneaux partagés ont DÉMÉNAGÉ
 * vers `/matchs/semaine-type` (page sœur), avec le bouton « Habitudes & passerelles ». Les anciens
 * deep-links `?section=gabarit|creneaux` y redirigent. La section « Réglages de saison » devient
 * « Accès match » : la liste des gymnases et leurs créneaux de match, éditables un gymnase à la fois.
 *
 * P4-185 — « une section = un écran » : les cartes sont des accordéons CONTRÔLÉS, un seul ouvert à
 * la fois, ancré `?section=` (patron `ReviewQueue`). **Défaut = tout replié** (absent ⇒ rien d'ouvert).
 */
export function ConfigurationPage() {
  const teams = useTeams();
  const venues = useVenues();
  const competitions = useCompetitions();
  const categoryDurations = useSportCategoryDurations();
  const labelInventory = useVenueLabelInventory();
  const matchWindows = useVenueMatchWindows();
  // Même clé de query que `OpponentTravelCard` (zéro requête nouvelle) — sert le résumé d'en-tête.
  const opponentTravel = useOpponentTravel();

  const [searchParams, setSearchParams] = useSearchParams();

  // Anciens deep-links : le gabarit et les créneaux sont désormais la Semaine type.
  const rawSection = searchParams.get("section");
  const openSection = decodeSectionParam(searchParams);

  const setOpenSection = (section: ConfigSection | null): void => {
    setSearchParams(applySectionToParams(searchParams, section), { replace: true });
  };
  const sectionProps = (key: ConfigSection) => ({
    open: openSection === key,
    onToggle: (next: boolean): void => setOpenSection(next ? key : null),
  });

  if ("gabarit" === rawSection || "creneaux" === rawSection) {
    return <Navigate to="/matchs/semaine-type" replace />;
  }

  // UXS-08 — la PAGE est gatée sur ses deux lectures FONDATRICES (équipes + gymnases : elles
  // alimentent plusieurs sections). Un échec cède à une alerte avec réessai groupé, jamais un écran
  // vide crédible ; par section, chaque corps a sa propre garde (SectionBody). Patron `CalendarPage`.
  if (readFailed(teams) || readFailed(venues)) {
    return (
      <div className="flex flex-col gap-3">
        <LoadErrorHint
          onRetry={() => {
            void teams.refetch();
            void venues.refetch();
          }}
        />
      </div>
    );
  }
  if (undefined === teams.data || undefined === venues.data) {
    return <FullPageSpinner />;
  }
  const teamsData = teams.data;
  const venuesData = venues.data;

  return (
    <div className="flex flex-col gap-3">
      {/* 1. Les échéances de saisie ligue/comité — une par compétition. */}
      <AccordionSection {...sectionProps("echeances")} title={sectionTitle("Échéances de saisie", deadlinesSummary(competitions.data))}>
        <SectionBody query={competitions} render={(data) => <EntryDeadlinesEditor competitions={data} teams={teamsData} />} />
      </AccordionSection>

      {/* 2. La durée des matchs — un réglage par catégorie (P2-54 RMM-9). */}
      <AccordionSection {...sectionProps("durees")} title={sectionTitle("Durée des matchs", durationsSummary(categoryDurations.data))}>
        <SectionBody query={categoryDurations} render={(data) => <MatchDurationsEditor categories={data} />} />
      </AccordionSection>

      {/* 3. Le trajet adverse — le radar de conflits devient SPATIAL (P2-54 PR-3).
          La carte gère elle-même sa lecture (résumé null-safe via configSummaries). */}
      <AccordionSection {...sectionProps("adversaires")} title={sectionTitle("Adversaires à localiser", opponentsSummary(opponentTravel.data))}>
        <OpponentTravelCard />
      </AccordionSection>

      {/* 4. L'accès match des gymnases — clé URL `reglages` conservée. */}
      <AccordionSection {...sectionProps("reglages")} title={sectionTitle("Accès match", accessSummary(matchWindows.data, venuesData))}>
        <SectionBody query={matchWindows} render={(windows) => <MatchAccessSection venues={venuesData} windows={windows} />} />
      </AccordionSection>

      {/* 5. Les libellés FFBB des gymnases — voir/retirer les alias de salle (P4-196). */}
      <AccordionSection {...sectionProps("libelles")} title={sectionTitle("Libellés FFBB des gymnases", labelsSummary(labelInventory.data))}>
        <SectionBody query={labelInventory} render={() => <VenueLabelsSection venues={venuesData} />} />
      </AccordionSection>
    </div>
  );
}

/**
 * PR 2a — la section « Accès match » : la liste des gymnases qui accueillent des matchs (avec
 * leurs créneaux mis en forme), puis, repliée, celle des gymnases sans accès. Chaque ligne édite
 * SON gymnase en modale (une modale = un gymnase, plus de sélecteur). À la fermeture, le focus
 * revient explicitement au bouton « Modifier » de la ligne — qui peut avoir changé de liste.
 */
function MatchAccessSection({ venues, windows }: { venues: Venue[]; windows: VenueMatchWindow[] }) {
  const [editingVenueId, setEditingVenueId] = useState<string | null>(null);
  const [showWithout, setShowWithout] = useState(false);
  const withoutListId = useId();

  const windowsByVenue = useMemo(() => {
    const map = new Map<string, VenueMatchWindow[]>();
    for (const window of windows) {
      const bucket = map.get(window.venueId) ?? [];
      bucket.push(window);
      map.set(window.venueId, bucket);
    }
    return map;
  }, [windows]);

  const sorted = useMemo(() => [...venues].sort((a, b) => a.name.localeCompare(b.name, "fr")), [venues]);
  const withAccess = sorted.filter((v) => (windowsByVenue.get(v.id)?.length ?? 0) > 0);
  const without = sorted.filter((v) => (windowsByVenue.get(v.id)?.length ?? 0) === 0);
  const hasAccess = withAccess.length > 0;

  const closeEdit = (): void => {
    const id = editingVenueId;
    setEditingVenueId(null);
    if (null !== id) {
      // La ligne a pu changer de liste (gagné/perdu un accès) — refocus après commit.
      requestAnimationFrame(() => document.getElementById(`access-edit-${id}`)?.focus());
    }
  };

  const editingVenue = null === editingVenueId ? null : (venues.find((v) => v.id === editingVenueId) ?? null);

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">
        Les créneaux que la mairie accorde les jours de match — un gymnase sans fenêtre n'accueille pas de matchs.
      </p>

      {!hasAccess ? <EmptyHint>Aucun gymnase n'accueille de matchs — ajoutez un accès.</EmptyHint> : null}

      {/* Les gymnases AVEC accès ; s'il n'y en a aucun, TOUS les gymnases dépliés (rien à replier). */}
      {(hasAccess ? withAccess : without).length > 0 ? (
        <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
          {(hasAccess ? withAccess : without).map((venue) => (
            <AccessRow key={venue.id} venue={venue} windows={windowsByVenue.get(venue.id) ?? []} onEdit={() => setEditingVenueId(venue.id)} />
          ))}
        </ul>
      ) : null}

      {/* Les gymnases SANS accès, repliés par défaut (uniquement s'il existe des gymnases AVEC accès). */}
      {hasAccess && without.length > 0 ? (
        <div className="flex flex-col gap-2">
          <Button variant="ghost" size="sm" className="self-start" aria-expanded={showWithout} aria-controls={showWithout ? withoutListId : undefined} onClick={() => setShowWithout((open) => !open)}>
            <ChevronDown className={cn("size-4", showWithout ? "rotate-180" : "")} aria-hidden="true" />
            {without.length} gymnase{without.length > 1 ? "s" : ""} sans accès match
          </Button>
          {showWithout ? (
            <ul id={withoutListId} className="flex flex-col divide-y divide-border rounded-md border border-border">
              {without.map((venue) => (
                <AccessRow key={venue.id} venue={venue} windows={[]} onEdit={() => setEditingVenueId(venue.id)} />
              ))}
            </ul>
          ) : null}
        </div>
      ) : null}

      {null !== editingVenue ? (
        <Modal
          label="Accès match"
          title={`Accès match · ${editingVenue.name}`}
          onClose={closeEdit}
          size="lg"
          footer={
            <Button variant="outline" size="sm" onClick={closeEdit}>
              Fermer
            </Button>
          }
        >
          <div className="flex flex-col gap-3">
            <p className="text-xs text-muted-foreground">
              Les créneaux accordés les jours de match — un gymnase sans fenêtre n'accueille pas de matchs. Même éditeur que
              l'étape Gymnases du wizard.
            </p>
            <MatchWindowsEditor venueId={editingVenue.id} />
          </div>
        </Modal>
      ) : null}
    </div>
  );
}

function AccessRow({ venue, windows, onEdit }: { venue: Venue; windows: VenueMatchWindow[]; onEdit: () => void }) {
  return (
    <li className="flex items-center justify-between gap-2 px-3 py-2">
      <span className="flex min-w-0 items-center gap-2">
        <VenueSwatch color={venue.color} />
        <span className="min-w-0">
          <span className="block truncate text-sm font-medium">{venue.name}</span>
          <span className="block text-xs text-muted-foreground">{formatMatchAccessWindows(windows)}</span>
        </span>
      </span>
      <Button
        variant="ghost"
        size="sm"
        id={`access-edit-${venue.id}`}
        className="shrink-0"
        aria-label={`Modifier les accès match de ${venue.name}`}
        onClick={onEdit}
      >
        <Pencil className="size-3.5" aria-hidden="true" />
        Modifier
      </Button>
    </li>
  );
}
