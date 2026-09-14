import { CalendarDays, ShieldCheck } from "lucide-react";
import { type ReactNode, useEffect, useMemo, useRef, useState } from "react";
import { useNavigate, useSearchParams } from "react-router";

import { AccordionSection } from "@/shared/components/ui/accordion";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint, EmptyState } from "@/shared/components/ui/empty-hint";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { coachFullName } from "@/shared/lib/coachName";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { cn } from "@/shared/lib/utils";

import type { Coach, Conflict, Team, Venue } from "./api";
import { ConflictSeverityGroups } from "./ConflictLine";
import { CONFLICT_FAMILIES, CONFLICT_FAMILY_LABEL } from "./lib/conflictLabels";
import { type ConflictPivotAxis, type ConflictPivotEntry, PIVOT_AXES, pivotConflicts } from "./lib/conflictPivot";
import { applyFamilyFilter, countByFamily, dateOf } from "./lib/consultFilter";
import { applyConflictsToParams, decodeConflictsParams } from "./lib/urlState";
import { weekendKeyOf, weekendShortLabel } from "./lib/weekendGrid";
import { useCoaches, useConflicts, useFixtures, useModuleVisit, useTeams, useVenues } from "./queries";
import { useMatchesStore } from "./store";

function byId<T extends { id: string }>(rows: T[] | undefined): Map<string, T> {
  return new Map((rows ?? []).map((row) => [row.id, row]));
}

const PIVOT_LABEL: Record<ConflictPivotAxis, string> = {
  coach: "Coach",
  equipe: "Équipe",
  gymnase: "Gymnase",
  journee: "Journée",
};

const PIVOT_LIVE_LABEL: Record<ConflictPivotAxis, string> = {
  coach: "coach",
  equipe: "équipe",
  gymnase: "gymnase",
  journee: "journée",
};

/**
 * PR A — l'onglet « Conflits » du module matchs : TOUS les conflits de la saison (même
 * flux `useConflicts` que Consulter), pivotés par coach / équipe / gymnase / journée,
 * en LECTURE SEULE. Chaque entrée est un accordéon (une seule ouverte, `?ouvert`),
 * chaque conflit garde sa ligne (gravité, phrase, chip « Nouveau ») + un bouton « Voir
 * la semaine » vers Placer. PRÉSENTATION pure : aucun endpoint ni calcul serveur, aucune
 * règle métier — on RÉPARTIT des conflits déjà servis (🔴 `.claude/rules/frontend.md`).
 *
 * PAS de `MatchesFilterBar` ici (décision fondateur) : le store `filterMode/filterIds`
 * est partagé avec la boucle et fausserait le compte SAISON. Les filtres propres
 * (pivot, familles) vivent dans `conflictsPivot`/`conflictsFamilies`, séparés de Consulter.
 */
export function ConflictsPage() {
  const conflicts = useConflicts();
  const teams = useTeams();
  const venues = useVenues();
  const coaches = useCoaches();
  const fixtures = useFixtures();
  // RMM-3 — le « gardien » : on LIT le cache partagé (posé par le layout), aucun re-POST.
  const moduleVisit = useModuleVisit();

  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();

  const { conflictsPivot, conflictsFamilies, setConflictsPivot, setConflictsFamilies, setSelectedWeekend } = useMatchesStore();

  const teamsMap = useMemo<Map<string, Team>>(() => byId(teams.data), [teams.data]);
  const venuesMap = useMemo<Map<string, Venue>>(() => byId(venues.data), [venues.data]);
  const coachesMap = useMemo<Map<string, Coach>>(() => byId(coaches.data), [coaches.data]);
  const fixturesById = useMemo(() => byId(fixtures.data), [fixtures.data]);

  const allConflicts = useMemo<Conflict[]>(() => conflicts.data?.conflicts ?? [], [conflicts.data]);
  const newFingerprints = useMemo<Set<string>>(() => new Set(moduleVisit.data?.newConflictFingerprints ?? []), [moduleVisit.data]);

  // Compteurs par famille : SAISON (avant le filtre familles), comme le veut la décision.
  const familyCounts = useMemo(() => countByFamily(allConflicts), [allConflicts]);
  const effectiveFamilies = conflictsFamilies ?? CONFLICT_FAMILIES;
  const filtered = useMemo(() => applyFamilyFilter(allConflicts, effectiveFamilies), [allConflicts, effectiveFamilies]);
  const rawEntries = useMemo(() => pivotConflicts(filtered, conflictsPivot, fixturesById), [filtered, conflictsPivot, fixturesById]);

  const entryLabel = useMemo(() => {
    return (entry: ConflictPivotEntry): string => {
      if ("exterieur" === entry.kind) {
        return "Extérieur";
      }
      if ("sansDate" === entry.kind) {
        return "Sans date";
      }
      if ("sansCoach" === entry.kind) {
        return "Autres conflits";
      }
      if ("weekend" === entry.kind) {
        return weekendShortLabel(entry.key);
      }
      if ("coach" === conflictsPivot) {
        return coachFullName(coachesMap.get(entry.key));
      }
      if ("equipe" === conflictsPivot) {
        return teamsMap.get(entry.key)?.name ?? "Équipe ?";
      }
      return venuesMap.get(entry.key)?.name ?? "Gymnase ?";
    };
  }, [conflictsPivot, coachesMap, teamsMap, venuesMap]);

  // Tri : ressources/week-ends d'abord, sentinelles TOUJOURS en dernier. Journée =
  // chronologique (clés = samedis ISO) ; les autres = compte décroissant, départage
  // localeCompare("fr") sur le libellé.
  const entries = useMemo(() => {
    const isSentinel = (entry: ConflictPivotEntry): boolean => "resource" !== entry.kind && "weekend" !== entry.kind;
    return [...rawEntries].sort((a, b) => {
      if (isSentinel(a) !== isSentinel(b)) {
        return isSentinel(a) ? 1 : -1;
      }
      if ("journee" === conflictsPivot) {
        return a.key.localeCompare(b.key);
      }
      if (b.conflicts.length !== a.conflicts.length) {
        return b.conflicts.length - a.conflicts.length;
      }
      return entryLabel(a).localeCompare(entryLabel(b), "fr");
    });
  }, [rawEntries, conflictsPivot, entryLabel]);

  const entryKeys = useMemo(() => new Set(entries.map((e) => e.key)), [entries]);

  // ── Deep-link : pivot + familles dans le store (seed une fois, écrit à chaque
  //    changement) ; la section ouverte (`?ouvert`) reste directe (patron ReviewQueue).
  const seededRef = useRef(false);
  useEffect(() => {
    if (seededRef.current) {
      return;
    }
    seededRef.current = true;
    const decoded = decodeConflictsParams(searchParams);
    setConflictsPivot(decoded.pivot);
    setConflictsFamilies(decoded.families);
  }, [searchParams, setConflictsPivot, setConflictsFamilies]);
  useEffect(() => {
    if (!seededRef.current) {
      return;
    }
    const next = applyConflictsToParams(searchParams, { pivot: conflictsPivot, families: conflictsFamilies });
    // Changer de pivot replie tout : un `?ouvert` dont la clé n'existe plus est nettoyé.
    const open = next.get("ouvert");
    if (null !== open && !entryKeys.has(open)) {
      next.delete("ouvert");
    }
    if (next.toString() !== searchParams.toString()) {
      setSearchParams(next, { replace: true });
    }
  }, [conflictsPivot, conflictsFamilies, entryKeys, searchParams, setSearchParams]);

  // Section ouverte : `?ouvert=<clé>` ; une seule entrée ⇒ ouverte d'office.
  const openParam = searchParams.get("ouvert");
  const openKey = 1 === entries.length ? entries[0].key : openParam;
  const setOuvert = (key: string | null): void => {
    const next = new URLSearchParams(searchParams);
    if (null === key) {
      next.delete("ouvert");
    } else {
      next.set("ouvert", key);
    }
    setSearchParams(next, { replace: true });
  };

  // Phrase sr-only aria-live : vide au premier rendu, remplie APRÈS interaction
  // seulement (jamais au refetch — l'effet ne dépend que du pivot/familles, pas des données).
  const interactedRef = useRef(false);
  const [liveMsg, setLiveMsg] = useState("");
  useEffect(() => {
    if (!interactedRef.current) {
      return;
    }
    const plural = (n: number): string => (n > 1 ? "s" : "");
    setLiveMsg(`Regroupé par ${PIVOT_LIVE_LABEL[conflictsPivot]} — ${entries.length} entrée${plural(entries.length)}, ${filtered.length} conflit${plural(filtered.length)}`);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- la phrase ne se rejoue QUE sur une interaction (pivot/familles), jamais sur un refetch (entries/filtered exclus des deps).
  }, [conflictsPivot, conflictsFamilies]);

  // Interactions.
  const onPivot = (axis: ConflictPivotAxis): void => {
    interactedRef.current = true;
    setConflictsPivot(axis);
  };
  const isFamilyChecked = (family: (typeof CONFLICT_FAMILIES)[number]): boolean => null === conflictsFamilies || conflictsFamilies.includes(family);
  const toggleFamily = (family: (typeof CONFLICT_FAMILIES)[number]): void => {
    interactedRef.current = true;
    const active = new Set(conflictsFamilies ?? CONFLICT_FAMILIES);
    if (active.has(family)) {
      active.delete(family);
    } else {
      active.add(family);
    }
    const nextFamilies = CONFLICT_FAMILIES.filter((f) => active.has(f));
    setConflictsFamilies(nextFamilies.length === CONFLICT_FAMILIES.length ? null : nextFamilies);
  };

  // Un conflit daté offre « Voir la semaine » (vers Placer) ; sans date, aucun bouton.
  const renderTrailing = (conflict: Conflict): ReactNode => {
    const date = dateOf(conflict);
    if (null === date) {
      return null;
    }
    const saturday = weekendKeyOf(date);
    return (
      <Button
        variant="outline"
        size="sm"
        title={`Voir la semaine du ${weekendShortLabel(saturday)} dans Placer`}
        onClick={() => {
          setSelectedWeekend(saturday);
          navigate("/matchs");
        }}
      >
        <CalendarDays className="size-4" aria-hidden="true" />
        Voir la semaine
      </Button>
    );
  };

  // Lectures fondatrices (doctrine `readState`, comme `ConsultPage`).
  if (readLoading(conflicts) || readLoading(teams) || readLoading(venues)) {
    return <FullPageSpinner />;
  }
  if (readFailed(conflicts) || readFailed(teams) || readFailed(venues)) {
    return (
      <div className="flex flex-col gap-4">
        <LoadErrorHint
          onRetry={() => {
            void conflicts.refetch();
            void teams.refetch();
            void venues.refetch();
          }}
        />
      </div>
    );
  }

  if (0 === allConflicts.length) {
    return (
      <EmptyState
        icon={ShieldCheck}
        title="Aucun conflit sur la saison"
        description="Tous les matchs placés respectent les gymnases, les coachs et les fenêtres ligue."
      />
    );
  }

  // Chips familles : celles PRÉSENTES sur la saison (compteur > 0). Décocher n'enlève
  // pas la chip (le compteur est SAISON, pas filtré).
  const familyChips = CONFLICT_FAMILIES.filter((family) => (familyCounts.get(family) ?? 0) > 0);

  return (
    <div className="flex flex-col gap-4">
      {/* Contrôle du pivot (patron temporalité de Consulter). */}
      <div className="flex flex-wrap items-center gap-2">
        <div role="group" aria-label="Regrouper par" className="flex flex-wrap items-center gap-1 rounded-md border border-border p-0.5">
          {PIVOT_AXES.map((axis) => (
            <Button
              key={axis}
              type="button"
              size="sm"
              aria-pressed={axis === conflictsPivot}
              variant={axis === conflictsPivot ? "default" : "ghost"}
              className={cn("h-7", axis === conflictsPivot ? "" : "text-muted-foreground")}
              onClick={() => onPivot(axis)}
            >
              {PIVOT_LABEL[axis]}
            </Button>
          ))}
        </div>
      </div>

      {/* Chips familles de conflits (présentes) avec compteur SAISON. */}
      {familyChips.length > 0 ? (
        <div role="group" aria-label="Familles de conflits" className="flex flex-wrap items-center gap-1.5">
          {familyChips.map((family) => (
            <Button
              key={family}
              type="button"
              size="sm"
              aria-pressed={isFamilyChecked(family)}
              variant={isFamilyChecked(family) ? "default" : "ghost"}
              className={cn("h-7 gap-1.5 border border-border", isFamilyChecked(family) ? "" : "text-muted-foreground")}
              onClick={() => toggleFamily(family)}
            >
              {CONFLICT_FAMILY_LABEL[family]}
              <span className="tabular-nums text-xs">{familyCounts.get(family) ?? 0}</span>
            </Button>
          ))}
        </div>
      ) : null}

      {/* Annonce sr-only : montée vide, remplie après interaction (jamais au refetch). */}
      <p className="sr-only" aria-live="polite">
        {liveMsg}
      </p>

      {/* Entrées pivotées, ou l'état vide de filtre. */}
      {0 === entries.length ? (
        <EmptyHint>Aucun conflit pour les familles cochées.</EmptyHint>
      ) : (
        <div className="flex flex-col gap-3">
          {entries.map((entry) => (
            <AccordionSection
              key={entry.key}
              title={
                <span className="tabular-nums">
                  {entryLabel(entry)} · {entry.conflicts.length}
                </span>
              }
              open={openKey === entry.key}
              onToggle={(next) => setOuvert(next ? entry.key : null)}
            >
              <ConflictSeverityGroups conflicts={entry.conflicts} teams={teamsMap} coaches={coachesMap} newFingerprints={newFingerprints} renderTrailing={renderTrailing} />
            </AccordionSection>
          ))}
        </div>
      )}
    </div>
  );
}
