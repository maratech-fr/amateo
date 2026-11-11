import { CheckCheck, Inbox } from "lucide-react";
import { useMemo, useState } from "react";
import { useNavigate, useSearchParams } from "react-router";

import { AccordionSection } from "@/shared/components/ui/accordion";
import { Button } from "@/shared/components/ui/button";
import { CtcMark } from "@/shared/components/ui/ctc-mark";
import { EmptyState } from "@/shared/components/ui/empty-hint";
import { FilterToggle } from "@/shared/components/ui/filter-toggle";
import { frDateWeekdayNoYear } from "@/shared/lib/date";
import { compareTeamsByRank } from "@/shared/lib/teamTiers";
import { useCanManage } from "@/shared/session/queries";
import { toast } from "@/shared/stores/toastStore";

import type { AttachVenueLabelInput, Competition, Fixture, ResolveDeviationInput, Team, Venue } from "./api";
import { DEFAULT_KINDS, normalizeKinds, revealPlan } from "./lib/consultFilter";
import { applyConsultToParams } from "./lib/urlState";
import { useAttachVenueLabel, useCompetitions, useResolveFixtureDeviation, useReviewFixtures } from "./queries";
import { buildReviewQueue, byMatchDateAsc, isOpenReview } from "./lib/reviewQueue";
import { MATCHES_TEXTS } from "./texts";
import { ReviewQueueRow } from "./ReviewQueueRow";
import { useMatchesStore } from "./store";
import { weekendKeyOf } from "./lib/weekendGrid";

interface ReviewQueueProps {
  fixtures: Fixture[];
  teams: Team[];
  /** Gymnases actifs — le geste « Rattacher » d'une ligne domicile sans salle en a besoin. */
  venues: Venue[];
}

/**
 * PR-3b — la file de traitement des rencontres, par équipe. Chaque équipe est un
 * accordéon (ouvert = présent dans l'URL `?equipe=`, deep-linkable) : en-tête « SM1
 * · 5 nouveautés à voir · 2 écarts à trancher », « Tout marquer comme vu » en tête de
 * corps (un bouton imbriqué dans l'en-tête accordéon serait un bouton dans un bouton —
 * invalide), puis une ligne par rencontre. L'ordre des équipes suit `compareTeamsByRank`
 * (le même que `TeamSelect`). Un interrupteur « Afficher les traitées » révèle les
 * rencontres déjà traitées (masquées par défaut).
 *
 * M3 (2026-10-27) — la file n'est pas une « validation » (« ça fait quoi,
 * ça mène où ? ») : c'est un ACQUITTEMENT. L'import a déjà mis à jour les matchs ; marquer « vu »
 * ne place rien, ne change ni date ni gymnase — ça vide la liste. Les écarts, eux, s'arbitrent.
 * D'où le vocabulaire : « nouveautés à voir » / « écarts à trancher » / « Tout marquer comme vu ».
 *
 * Un bouton GLOBAL « Tout marquer comme vu — extérieurs » (rangée des interrupteurs, gestionnaires
 * seulement) acquitte d'un clic les extérieurs ouverts ; ceux qui portent un écart à trancher sont
 * laissés de côté par le serveur et nommés dans le toast. Acquitter efface les anciennes valeurs.
 */
export function ReviewQueue({ fixtures, teams, venues }: ReviewQueueProps) {
  // Un Membre lit la file : aucun « marquer comme vu », aucun arbitrage.
  const canManage = useCanManage();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const setFilterMode = useMatchesStore((s) => s.setFilterMode);
  const toggleFilterId = useMatchesStore((s) => s.toggleFilterId);
  const setSelectedWeekend = useMatchesStore((s) => s.setSelectedWeekend);
  const setSelectedFixtureId = useMatchesStore((s) => s.setSelectedFixtureId);
  const consultKinds = useMatchesStore((s) => s.consultKinds);
  const consultFamilies = useMatchesStore((s) => s.consultFamilies);
  const consultTypicalWeek = useMatchesStore((s) => s.consultTypicalWeek);
  const consultAway = useMatchesStore((s) => s.consultAway);
  const competitions = useCompetitions();
  const competitionsById = useMemo<Map<string, Competition>>(() => new Map((competitions.data ?? []).map((c) => [c.id, c])), [competitions.data]);
  const reviewFixtures = useReviewFixtures();
  const resolveDeviation = useResolveFixtureDeviation();
  const attachVenueLabel = useAttachVenueLabel();

  // L'interrupteur vit en état LOCAL, miroir de `?traitees=` : React Router 7 pose
  // l'URL dans une transition différée, et une case contrôlée par l'URL seule
  // revient « décochée » le temps de la transition (vu en e2e et à l'œil).
  const [showTreated, setShowTreated] = useState<boolean>(() => "1" === searchParams.get("traitees"));
  // Même régime pour « Masquer les extérieurs », miroir de `?exterieurs=masques`.
  const [hideAway, setHideAway] = useState<boolean>(() => "masques" === searchParams.get("exterieurs"));
  const openTeamId = searchParams.get("equipe");
  const busy = reviewFixtures.isPending || resolveDeviation.isPending || attachVenueLabel.isPending;

  const teamName = useMemo(() => {
    const byId = new Map(teams.map((t) => [t.id, t]));
    return (id: string): string => byId.get(id)?.name ?? "Équipe ?";
  }, [teams]);

  const venueName = useMemo(() => {
    const byId = new Map(venues.map((v) => [v.id, v]));
    return (id: string): string => byId.get(id)?.name ?? "gymnase";
  }, [venues]);

  const fixtureById = useMemo(() => new Map(fixtures.map((f) => [f.id, f])), [fixtures]);

  const teamOrder = useMemo(() => [...teams].sort(compareTeamsByRank).map((t) => t.id), [teams]);
  // « Masquer les extérieurs » filtre AVANT la construction de la file : en-têtes
  // recomptés, équipes 100 % extérieur et état vide suivent naturellement.
  const queues = useMemo(() => {
    const shown = hideAway ? fixtures.filter((f) => "AWAY" !== f.homeAway) : fixtures;
    return buildReviewQueue(shown, teamOrder);
  }, [fixtures, teamOrder, hideAway]);

  // Sans « afficher les traitées », on ne montre que les équipes à rencontres ouvertes.
  const visibleQueues = showTreated ? queues : queues.filter((q) => q.open.length > 0);
  const hasTreated = queues.some((q) => q.treated.length > 0);
  // Un extérieur ACQUITTABLE d'un geste : ouvert et sans écart à trancher (toutes ses entrées
  // appliquées d'office). Lu sur TOUTES les rencontres, « Masquer les extérieurs » n'y change rien.
  const hasAcquittableAway = useMemo(() => fixtures.some((f) => "AWAY" === f.homeAway && isOpenReview(f) && !f.pendingDeviations.some((d) => !d.autoApplied)), [fixtures]);

  const setParam = (key: string, value: string | null): void => {
    const next = new URLSearchParams(searchParams);
    if (null === value) {
      next.delete(key);
    } else {
      next.set(key, value);
    }
    setSearchParams(next, { replace: true });
  };

  const toggleTreated = (next: boolean): void => {
    setShowTreated(next);
    setParam("traitees", next ? "1" : null);
  };

  const toggleHideAway = (next: boolean): void => {
    setHideAway(next);
    setParam("exterieurs", next ? "masques" : null);
  };

  const onPlace = (fixture: Fixture): void => {
    // PR 3b — le Calendrier porte un panneau de placement PERMANENT (plus de rail) :
    // on pose le filtre, la semaine et la rencontre pointée, et le panneau du match
    // s'ouvre directement à l'arrivée sur le Calendrier.
    setFilterMode("equipe");
    toggleFilterId(fixture.teamId);
    // A8 — les masques (extérieur / type hors sélection) doivent voyager DANS L'URL : le seed de
    // `CalendarPage` REDÉFINIT l'état Consulter depuis l'URL, donc poser le store serait écrasé.
    // Le filtre PR-1 (équipe), la semaine et la rencontre pointée survivent, eux, par le store
    // (le seed protège le filtre « store vierge », et ne touche ni semaine ni fixture pointée).
    const effectiveKinds = consultKinds ?? DEFAULT_KINDS;
    const plan = revealPlan([fixture], effectiveKinds, competitionsById);
    const kinds = plan.kinds.length > 0 ? normalizeKinds([...effectiveKinds, ...plan.kinds]) : consultKinds;
    const params = applyConsultToParams(new URLSearchParams(), {
      kinds,
      families: consultFamilies,
      typicalWeek: consultTypicalWeek,
      away: plan.away || consultAway,
      temps: "semaine",
      month: null,
      phaseId: null,
    });
    setSelectedWeekend(weekendKeyOf(fixture.matchDate));
    setSelectedFixtureId(fixture.id);
    void navigate({ pathname: "/matchs", search: params.toString() });
  };

  const onValidateLine = (fixtureId: string): void => {
    reviewFixtures.mutate({ fixtureIds: [fixtureId] });
  };

  const onResolve = (input: ResolveDeviationInput): void => {
    resolveDeviation.mutate(input);
  };

  const onAttach = (input: AttachVenueLabelInput): void => {
    attachVenueLabel.mutate(input, {
      onSuccess: (r) => {
        if (r.attached > 0) {
          toast.success(`${r.attached} domicile${r.attached > 1 ? "s" : ""} rattaché${r.attached > 1 ? "s" : ""} à ${venueName(r.venueId)} (toutes équipes)`);
        } else {
          toast.info("Libellé confirmé — aucun nouveau domicile à rattacher");
        }
      },
    });
  };

  const onValidateTeam = (teamId: string): void => {
    reviewFixtures.mutate(
      { teamId },
      {
        onSuccess: (r) => {
          if (r.skipped.length > 0) {
            // Nommer équipe + dates, JAMAIS l'id : les rencontres à écart ne se
            // tranchent pas en masse, elles restent à arbitrer une par une.
            const dates = r.skipped
              .map((s) => fixtureById.get(s.fixtureId))
              .filter((f): f is Fixture => undefined !== f)
              .map((f) => frDateWeekdayNoYear(f.matchDate));
            toast.info(MATCHES_TEXTS.reviewSkippedToast(teamName(teamId), r.skipped.length, dates));
          }
        },
      },
    );
  };

  const onValidateAway = (): void => {
    reviewFixtures.mutate(
      { scope: "away" },
      {
        onSuccess: (r) => {
          // Nommer équipe + date, JAMAIS l'id. Une rencontre absente de la liste chargée est
          // comptée sans nom (ni équipe ni date connues).
          const skipped = r.skipped.map((s) => {
            const f = fixtureById.get(s.fixtureId);
            return undefined === f ? { team: "", date: "" } : { team: teamName(f.teamId), date: frDateWeekdayNoYear(f.matchDate) };
          });
          const message = MATCHES_TEXTS.reviewAwaySeenToast(r.reviewed, skipped);
          if (skipped.length > 0) {
            toast.info(message);
          } else {
            toast.success(message);
          }
        },
      },
    );
  };

  // Geste global « extérieurs » : réservé aux gestionnaires, désactivé (raison annoncée) quand
  // aucun extérieur n'est acquittable. Présent aussi dans l'état vide (extérieurs masqués).
  const awayButton = canManage ? (
    <Button
      variant="outline"
      size="sm"
      disabled={busy || !hasAcquittableAway}
      disabledReason={hasAcquittableAway ? undefined : MATCHES_TEXTS.reviewMarkAwaySeenDisabled}
      onClick={onValidateAway}
    >
      <CheckCheck className="size-4" />
      {MATCHES_TEXTS.reviewMarkAwaySeen}
    </Button>
  ) : null;

  if (0 === visibleQueues.length) {
    return (
      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
          {hasTreated ? (
            <FilterToggle checked={showTreated} onChange={toggleTreated}>
              {MATCHES_TEXTS.reviewShowTreated}
            </FilterToggle>
          ) : null}
          <FilterToggle checked={hideAway} onChange={toggleHideAway}>
            {MATCHES_TEXTS.reviewHideAway}
          </FilterToggle>
          {awayButton}
        </div>
        <EmptyState icon={Inbox} title={MATCHES_TEXTS.reviewNothingToTreatTitle} description={MATCHES_TEXTS.reviewNothingToTreatDescription} />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-3">
      {/* M3 — la file est un ACQUITTEMENT : l'import a déjà appliqué les mises à jour, « vu » vide la liste. */}
      <p className="text-sm text-muted-foreground">{MATCHES_TEXTS.reviewHelp}</p>
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        <FilterToggle checked={showTreated} onChange={toggleTreated}>
          {MATCHES_TEXTS.reviewShowTreated}
        </FilterToggle>
        <FilterToggle checked={hideAway} onChange={toggleHideAway}>
          {MATCHES_TEXTS.reviewHideAway}
        </FilterToggle>
        {awayButton}
      </div>
      {visibleQueues.map((queue) => {
        // Traitées affichées = UNE liste par équipe, toujours triée par date (jamais les
        // traitées « à la suite », retour fondateur 2026-09-14).
        const rows = showTreated ? [...queue.open, ...queue.treated].sort(byMatchDateAsc) : queue.open;
        const headerParts = [teamName(queue.teamId), `${queue.toValidate} nouveauté${queue.toValidate > 1 ? "s" : ""} à voir`];
        if (queue.deviationCount > 0) {
          headerParts.push(`${queue.deviationCount} écart${queue.deviationCount > 1 ? "s" : ""} à trancher`);
        }
        if (queue.unattachedCount > 0) {
          headerParts.push(`${queue.unattachedCount} sans gymnase`);
        }
        // Repère d'entente (CTC) devant l'en-tête d'équipe de l'accordéon (`title` est un ReactNode).
        const team = teams.find((t) => t.id === queue.teamId);
        return (
          <AccordionSection
            key={queue.teamId}
            title={
              <span className="inline-flex items-center">
                {true === team?.isCtc ? <CtcMark partner={team.ctcPartner} /> : null}
                {headerParts.join(" · ")}
              </span>
            }
            open={openTeamId === queue.teamId}
            onToggle={(next) => setParam("equipe", next ? queue.teamId : null)}
          >
            <div className="flex flex-col gap-3">
              {queue.open.length > 0 && canManage ? (
                <Button variant="outline" size="sm" className="self-start" disabled={busy} onClick={() => onValidateTeam(queue.teamId)}>
                  <CheckCheck className="size-4" />
                  {MATCHES_TEXTS.reviewMarkAllSeen}
                </Button>
              ) : null}
              <ul className="flex flex-col gap-2">
                {rows.map((fixture) => (
                  <ReviewQueueRow key={fixture.id} fixture={fixture} venues={venues} onValidateLine={onValidateLine} onResolve={onResolve} onPlace={onPlace} onAttach={onAttach} busy={busy} />
                ))}
              </ul>
            </div>
          </AccordionSection>
        );
      })}
    </div>
  );
}

