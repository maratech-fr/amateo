import { CheckCheck, Inbox } from "lucide-react";
import { useMemo, useState } from "react";
import { useNavigate, useSearchParams } from "react-router";

import { AccordionSection } from "@/shared/components/ui/accordion";
import { Button } from "@/shared/components/ui/button";
import { EmptyState } from "@/shared/components/ui/empty-hint";
import { frDateWeekdayNoYear } from "@/shared/lib/date";
import { compareTeamsByRank } from "@/shared/lib/teamTiers";
import { toast } from "@/shared/stores/toastStore";

import type { AttachVenueLabelInput, Fixture, ResolveDeviationInput, Team, Venue } from "./api";
import { useAttachVenueLabel, useResolveFixtureDeviation, useReviewFixtures } from "./queries";
import { buildReviewQueue } from "./lib/reviewQueue";
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
 * · 5 à valider · 2 écarts », « Tout valider » en tête de corps (un bouton
 * imbriqué dans l'en-tête accordéon serait un bouton dans un bouton — invalide),
 * puis une ligne par rencontre. L'ordre des équipes suit `compareTeamsByRank` (le
 * même que `TeamSelect`). Un interrupteur « Afficher les traitées » révèle les
 * rencontres déjà traitées (masquées par défaut).
 */
export function ReviewQueue({ fixtures, teams, venues }: ReviewQueueProps) {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const setFilterMode = useMatchesStore((s) => s.setFilterMode);
  const toggleFilterId = useMatchesStore((s) => s.toggleFilterId);
  const setSelectedWeekend = useMatchesStore((s) => s.setSelectedWeekend);
  const reviewFixtures = useReviewFixtures();
  const resolveDeviation = useResolveFixtureDeviation();
  const attachVenueLabel = useAttachVenueLabel();

  // L'interrupteur vit en état LOCAL, miroir de `?traitees=` : React Router 7 pose
  // l'URL dans une transition différée, et une case contrôlée par l'URL seule
  // revient « décochée » le temps de la transition (vu en e2e et à l'œil).
  const [showTreated, setShowTreated] = useState<boolean>(() => "1" === searchParams.get("traitees"));
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
  const queues = useMemo(() => buildReviewQueue(fixtures, teamOrder), [fixtures, teamOrder]);

  // Sans « afficher les traitées », on ne montre que les équipes à rencontres ouvertes.
  const visibleQueues = showTreated ? queues : queues.filter((q) => q.open.length > 0);
  const hasTreated = queues.some((q) => q.treated.length > 0);

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

  const onPlace = (fixture: Fixture): void => {
    setFilterMode("equipe");
    toggleFilterId(fixture.teamId);
    setSelectedWeekend(weekendKeyOf(fixture.matchDate));
    void navigate("/matchs");
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
            toast.info(
              `${teamName(teamId)} : ${r.skipped.length} rencontre${r.skipped.length > 1 ? "s" : ""} à écart non validée${r.skipped.length > 1 ? "s" : ""}${dates.length > 0 ? ` (${dates.join(", ")})` : ""} — arbitrez leurs écarts.`,
            );
          }
        },
      },
    );
  };

  if (0 === visibleQueues.length) {
    return (
      <div className="flex flex-col gap-3">
        {hasTreated ? <TreatedToggle showTreated={showTreated} onToggle={toggleTreated} /> : null}
        <EmptyState icon={Inbox} title="Rien à traiter" description="Toutes les rencontres importées sont à jour. Déposez un export FBI ou vérifiez via l'API FFBB pour en apporter de nouvelles." />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-3">
      <TreatedToggle showTreated={showTreated} onToggle={toggleTreated} />
      {visibleQueues.map((queue) => {
        const rows = [...queue.open, ...(showTreated ? queue.treated : [])];
        const headerParts = [teamName(queue.teamId), `${queue.toValidate} à valider`];
        if (queue.deviationCount > 0) {
          headerParts.push(`${queue.deviationCount} écart${queue.deviationCount > 1 ? "s" : ""}`);
        }
        if (queue.unattachedCount > 0) {
          headerParts.push(`${queue.unattachedCount} sans gymnase`);
        }
        return (
          <AccordionSection
            key={queue.teamId}
            title={headerParts.join(" · ")}
            open={openTeamId === queue.teamId}
            onToggle={(next) => setParam("equipe", next ? queue.teamId : null)}
          >
            <div className="flex flex-col gap-3">
              {queue.open.length > 0 ? (
                <Button variant="outline" size="sm" className="self-start" disabled={busy} onClick={() => onValidateTeam(queue.teamId)}>
                  <CheckCheck className="size-4" />
                  Tout valider
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

function TreatedToggle({ showTreated, onToggle }: { showTreated: boolean; onToggle: (next: boolean) => void }) {
  return (
    <label className="flex w-fit items-center gap-2 text-sm text-muted-foreground">
      <input type="checkbox" checked={showTreated} onChange={(e) => onToggle(e.target.checked)} className="size-4" />
      Afficher les traitées
    </label>
  );
}
