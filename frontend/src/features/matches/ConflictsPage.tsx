import { CalendarDays, CircleAlert, type LucideIcon, RotateCcw, ShieldCheck, SlidersHorizontal } from "lucide-react";
import { type ReactNode, useEffect, useMemo, useRef, useState } from "react";
import { useNavigate, useSearchParams } from "react-router";

import { AccordionSection } from "@/shared/components/ui/accordion";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint, EmptyState } from "@/shared/components/ui/empty-hint";
import { FilterToggle } from "@/shared/components/ui/filter-toggle";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { coachFullName } from "@/shared/lib/coachName";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { isManagementRole } from "@/shared/lib/roles";
import { cn } from "@/shared/lib/utils";
import { useMe } from "@/shared/session/queries";

import type { Coach, Competition, Conflict, Team, Venue } from "./api";
import { ConflictResolutionControl } from "./ConflictResolutionControl";
import { ConflictSeverityGroups } from "./ConflictLine";
import { CONFLICT_FAMILIES, CONFLICT_FAMILY_LABEL } from "./lib/conflictLabels";
import { type ConflictPivotAxis, type ConflictPivotEntry, PIVOT_AXES, pivotConflicts } from "./lib/conflictPivot";
import { countByTreatment, isOpenConflict, openConflictCount, RESOLUTION_LABEL, TREATMENT_KEYS, type TreatmentKey, treatmentOf } from "./lib/conflictResolution";
import { applyFamilyFilter, countByFamily, DEFAULT_KINDS, dateOf, familiesPresent, hasHomeSide, normalizeKinds, revealPlan } from "./lib/consultFilter";
import { applyConflictsToParams, applyConsultToParams, applyWeekendToParams, decodeConflictsParams } from "./lib/urlState";
import { weekendKeyOf, weekendShortLabel } from "./lib/weekendGrid";
import { useCoaches, useCompetitions, useConflicts, useFixtures, useModuleVisit, useTeams, useVenues } from "./queries";
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
 * B1 — la puce « Traitement » par clé : libellé + glyphe. « À traiter » = l'absence de
 * résolution (`CircleAlert`) ; les trois statuts empruntent leur libellé/glyphe à la maison
 * unique `RESOLUTION_LABEL`. TABLE exhaustive (TS exige les 4), jamais un `switch`. L'icône
 * hérite de `currentColor` (jamais la teinte du statut) via le variant du bouton.
 */
const TREATMENT_META: Record<TreatmentKey, { label: string; icon: LucideIcon }> = {
  a_traiter: { label: "À traiter", icon: CircleAlert },
  DEROGATION_REQUESTED: RESOLUTION_LABEL.DEROGATION_REQUESTED,
  RESOLVED_INTERNALLY: RESOLUTION_LABEL.RESOLVED_INTERNALLY,
  NO_SOLUTION_YET: RESOLUTION_LABEL.NO_SOLUTION_YET,
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
  const competitions = useCompetitions();
  // RMM-3 — le « gardien » : on LIT le cache partagé (posé par le layout), aucun re-POST.
  const moduleVisit = useModuleVisit();

  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const { data: me } = useMe();
  const canManage = isManagementRole(me?.role);

  const { conflictsPivot, conflictsFamilies, setConflictsPivot, setConflictsFamilies, setSelectedWeekend, consultKinds, consultFamilies, consultTypicalWeek, consultAway } = useMatchesStore();

  // « Traitement » (4 puces) et « domicile » vivent en état LOCAL, miroirs de
  // `?traitement=`/`?domicile=1` (patron `ReviewQueue` : l'URL différée revient « décochée »
  // un instant si la case dépend d'elle seule). Ils filtrent l'AFFICHAGE, jamais les compteurs.
  const [treatments, setTreatments] = useState<TreatmentKey[] | null>(null);
  const [homeOnly, setHomeOnly] = useState(false);
  // Mobile (< sm) : les filtres autres que le pivot se replient derrière « Filtres ».
  const [filtersOpen, setFiltersOpen] = useState(false);

  const teamsMap = useMemo<Map<string, Team>>(() => byId(teams.data), [teams.data]);
  const venuesMap = useMemo<Map<string, Venue>>(() => byId(venues.data), [venues.data]);
  const coachesMap = useMemo<Map<string, Coach>>(() => byId(coaches.data), [coaches.data]);
  const competitionsMap = useMemo<Map<string, Competition>>(() => byId(competitions.data), [competitions.data]);
  const fixturesById = useMemo(() => byId(fixtures.data), [fixtures.data]);

  const allConflicts = useMemo<Conflict[]>(() => conflicts.data?.conflicts ?? [], [conflicts.data]);
  const newFingerprints = useMemo<Set<string>>(() => new Set(moduleVisit.data?.newConflictFingerprints ?? []), [moduleVisit.data]);

  // Compteurs par famille : SAISON, À TRAITER seulement (countByFamily filtre l'annoté).
  const familyCounts = useMemo(() => countByFamily(allConflicts), [allConflicts]);
  // Compteurs par traitement : FIXES sur la saison (total par état sur allConflicts).
  const treatmentCounts = useMemo(() => countByTreatment(allConflicts), [allConflicts]);
  const effectiveFamilies = conflictsFamilies ?? CONFLICT_FAMILIES;
  // B3 — ordre : familles → traitement (treatmentOf) → domicile (hasHomeSide) → pivot ;
  // les compteurs (au-dessus) n'en bougent pas.
  const filtered = useMemo(() => {
    let out = applyFamilyFilter(allConflicts, effectiveFamilies);
    if (null !== treatments) {
      const active = new Set(treatments);
      out = out.filter((c) => active.has(treatmentOf(c)));
    }
    if (homeOnly) {
      out = out.filter(hasHomeSide);
    }
    return out;
  }, [allConflicts, effectiveFamilies, treatments, homeOnly]);
  const rawEntries = useMemo(() => pivotConflicts(filtered, conflictsPivot, fixturesById), [filtered, conflictsPivot, fixturesById]);
  // Il reste des conflits traités ? (pilote l'affichage du groupe « Traitement ».)
  const hasTreated = useMemo(() => allConflicts.some((c) => !isOpenConflict(c)), [allConflicts]);

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
      // Tri par compte À TRAITER décroissant (P4-207 : une entrée 100 % traitée pèse 0).
      const openA = openConflictCount(a.conflicts);
      const openB = openConflictCount(b.conflicts);
      if (openB !== openA) {
        return openB - openA;
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
    setTreatments(decoded.treatments);
    setHomeOnly(decoded.homeOnly);
  }, [searchParams, setConflictsPivot, setConflictsFamilies]);
  useEffect(() => {
    if (!seededRef.current) {
      return;
    }
    const next = applyConflictsToParams(searchParams, { pivot: conflictsPivot, families: conflictsFamilies, treatments, homeOnly });
    // Changer de pivot replie tout : un `?ouvert` dont la clé n'existe plus est nettoyé.
    const open = next.get("ouvert");
    if (null !== open && !entryKeys.has(open)) {
      next.delete("ouvert");
    }
    if (next.toString() !== searchParams.toString()) {
      setSearchParams(next, { replace: true });
    }
  }, [conflictsPivot, conflictsFamilies, treatments, homeOnly, entryKeys, searchParams, setSearchParams]);

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
    // eslint-disable-next-line react-hooks/exhaustive-deps -- la phrase ne se rejoue QUE sur une interaction (pivot/familles/traitement/domicile), jamais sur un refetch (entries/filtered exclus des deps).
  }, [conflictsPivot, conflictsFamilies, treatments, homeOnly]);

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

  const isTreatmentChecked = (key: TreatmentKey): boolean => null === treatments || treatments.includes(key);
  const toggleTreatment = (key: TreatmentKey): void => {
    interactedRef.current = true;
    const active = new Set(treatments ?? TREATMENT_KEYS);
    if (active.has(key)) {
      active.delete(key);
    } else {
      active.add(key);
    }
    const next = TREATMENT_KEYS.filter((k) => active.has(k));
    setTreatments(next.length === TREATMENT_KEYS.length ? null : next);
  };
  const onHomeOnly = (next: boolean): void => {
    interactedRef.current = true;
    setHomeOnly(next);
  };

  // Le bloc de filtres diffère-t-il des défauts ? (« Filtres · N » mobile + « Réinitialiser ».)
  const familiesDirty = null !== conflictsFamilies;
  const treatmentsDirty = null !== treatments;
  const filtersDirtyCount = (familiesDirty ? 1 : 0) + (treatmentsDirty ? 1 : 0) + (homeOnly ? 1 : 0);
  const resetFilters = (): void => {
    interactedRef.current = true;
    setConflictsFamilies(null);
    setTreatments(null);
    setHomeOnly(false);
    requestAnimationFrame(() => document.querySelector<HTMLElement>('[data-conflicts-entries] button')?.focus());
  };

  // A8 — « Voir la semaine » navigue vers le Calendrier avec les masques levés DANS L'URL :
  // le seed de `CalendarPage` REDÉFINIT l'état Consulter depuis l'URL (l'URL fait foi), donc
  // poser le store ne suffirait pas — il serait écrasé. On PRÉSERVE l'état Consulter courant
  // (kinds/familles/semaine type) et on n'ÉTEINT jamais un masque déjà levé.
  const revealSearch = (conflict: Conflict, weekendKey: string): string => {
    const targets = [conflict.left?.fixtureId, conflict.right?.fixtureId, conflict.fixture?.fixtureId]
      .filter((id): id is string => undefined !== id)
      .map((id) => fixturesById.get(id))
      .filter((fx): fx is NonNullable<typeof fx> => undefined !== fx);
    const effectiveKinds = consultKinds ?? DEFAULT_KINDS;
    const plan = revealPlan(targets, effectiveKinds, competitionsMap);
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
    return applyWeekendToParams(params, weekendKey).toString();
  };

  // Un conflit daté offre « Voir la semaine » (vers Calendrier) ; sans date, aucun bouton.
  const renderVoirSemaine = (conflict: Conflict): ReactNode => {
    const date = dateOf(conflict);
    if (null === date) {
      return null;
    }
    const saturday = weekendKeyOf(date);
    return (
      <Button
        variant="outline"
        size="sm"
        title={`Voir la semaine du ${weekendShortLabel(saturday)} dans le Calendrier`}
        onClick={() => {
          const search = revealSearch(conflict, saturday);
          setSelectedWeekend(saturday);
          navigate({ pathname: "/matchs", search });
        }}
      >
        <CalendarDays className="size-4" aria-hidden="true" />
        Voir la semaine
      </Button>
    );
  };

  // P4-207 — chaque conflit porte sa pastille/éditeur de traitement (avant « Voir la
  // semaine ») + sa note ; le contrôle compose la `ConflictLine`.
  const renderConflict = (conflict: Conflict, meta: { tone: "destructive" | "warning" | "muted"; isNew: boolean }): ReactNode => (
    <ConflictResolutionControl
      conflict={conflict}
      teams={teamsMap}
      coaches={coachesMap}
      venues={venuesMap}
      tone={meta.tone}
      isNew={meta.isNew}
      canManage={canManage}
      extraTrailing={renderVoirSemaine(conflict)}
    />
  );

  // Lectures fondatrices (doctrine `readState`, comme `CalendarPage`).
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

  // Chips familles : celles PRÉSENTES sur la saison (au moins un conflit, traité ou non).
  // Une famille toute traitée garde sa chip « · 0 » ; le compteur affiché = à traiter.
  const present = familiesPresent(allConflicts);
  const familyChips = CONFLICT_FAMILIES.filter((family) => present.has(family));

  const homeToggle = (
    <div className="ml-auto">
      <FilterToggle checked={homeOnly} onChange={onHomeOnly}>
        Seulement avec un match à domicile
      </FilterToggle>
    </div>
  );
  const allFamiliesOn = familyChips.every(isFamilyChecked);
  const allTreatmentsOn = TREATMENT_KEYS.every(isTreatmentChecked);

  return (
    <div className="flex flex-col gap-4">
      {/* Contrôle du pivot (« Regrouper par ») — visible même en mobile. */}
      <div role="group" aria-labelledby="conflicts-pivot-label" className="flex flex-wrap items-center gap-2">
        <span id="conflicts-pivot-label" className="w-full shrink-0 text-xs font-medium text-muted-foreground sm:w-24">
          Regrouper par
        </span>
        <div className="flex flex-wrap items-center gap-1 rounded-md border border-border p-0.5">
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

      {/* Mobile (< sm) : « Filtres » replie Familles + Traitement + domicile (même DOM). */}
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="self-start sm:hidden"
        aria-expanded={filtersOpen}
        aria-controls="conflicts-filters-panel"
        onClick={() => setFiltersOpen((open) => !open)}
      >
        <SlidersHorizontal className="size-4" aria-hidden="true" />
        {filtersDirtyCount > 0 ? `Filtres · ${filtersDirtyCount}` : "Filtres"}
      </Button>

      <div id="conflicts-filters-panel" className={cn(filtersOpen ? "flex" : "hidden", "flex-col gap-2 sm:flex")}>
        {/* Familles de conflits (présentes) avec compteur À TRAITER. */}
        <div role="group" aria-labelledby="conflicts-familles-label" className="flex flex-wrap items-center gap-1.5">
          <span id="conflicts-familles-label" className="w-full shrink-0 text-xs font-medium text-muted-foreground sm:w-24">
            Familles
          </span>
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
              <span className={cn("tabular-nums text-xs", 0 === (familyCounts.get(family) ?? 0) ? "text-muted-foreground" : undefined)}>{familyCounts.get(family) ?? 0}</span>
            </Button>
          ))}
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-7"
            onClick={() => {
              interactedRef.current = true;
              setConflictsFamilies(allFamiliesOn ? [] : null);
            }}
          >
            {allFamiliesOn ? "Tout décocher" : "Tout cocher"}
          </Button>
          {/* domicile en fin de rangée Familles quand il n'y a PAS de groupe Traitement. */}
          {hasTreated ? null : homeToggle}
        </div>

        {/* Traitement (4 puces, présentes seulement si des conflits sont traités). */}
        {hasTreated ? (
          <div role="group" aria-labelledby="conflicts-traitement-label" className="flex flex-wrap items-center gap-1.5">
            <span id="conflicts-traitement-label" className="w-full shrink-0 text-xs font-medium text-muted-foreground sm:w-24">
              Traitement
            </span>
            {TREATMENT_KEYS.map((key) => {
              const meta = TREATMENT_META[key];
              const Icon = meta.icon;
              const count = treatmentCounts.get(key) ?? 0;
              return (
                <Button
                  key={key}
                  type="button"
                  size="sm"
                  aria-pressed={isTreatmentChecked(key)}
                  variant={isTreatmentChecked(key) ? "default" : "ghost"}
                  className={cn("h-7 gap-1.5 border border-border", isTreatmentChecked(key) ? "" : "text-muted-foreground")}
                  onClick={() => toggleTreatment(key)}
                >
                  <Icon className="size-3.5" aria-hidden="true" />
                  {meta.label}
                  <span className={cn("tabular-nums text-xs", 0 === count ? "text-muted-foreground" : undefined)}>{count}</span>
                </Button>
              );
            })}
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-7"
              onClick={() => {
                interactedRef.current = true;
                setTreatments(allTreatmentsOn ? [] : null);
              }}
            >
              {allTreatmentsOn ? "Tout décocher" : "Tout cocher"}
            </Button>
            {homeToggle}
          </div>
        ) : null}

        {filtersDirtyCount > 0 ? (
          <Button type="button" variant="ghost" size="sm" className="h-7 gap-1.5 self-start" onClick={resetFilters}>
            <RotateCcw className="size-3.5" aria-hidden="true" />
            Réinitialiser
          </Button>
        ) : null}
      </div>

      {/* Annonce sr-only : montée vide, remplie après interaction (jamais au refetch). */}
      <p className="sr-only" aria-live="polite">
        {liveMsg}
      </p>

      {/* Entrées pivotées, ou l'état vide de filtre. */}
      {0 === entries.length ? (
        <EmptyHint>
          <span>
            Aucun conflit ne correspond aux filtres — {allConflicts.length} conflit{allConflicts.length > 1 ? "s" : ""} sur la saison.
          </span>
          <span className="mt-2 block">
            <Button type="button" variant="outline" size="sm" onClick={resetFilters}>
              <RotateCcw className="size-4" aria-hidden="true" />
              Réinitialiser les filtres
            </Button>
          </span>
        </EmptyHint>
      ) : (
        <div data-conflicts-entries className="flex flex-col gap-3">
          {entries.map((entry) => (
            <AccordionSection
              key={entry.key}
              title={
                <span className="tabular-nums">
                  {entryLabel(entry)}{" "}
                  <span className={0 === openConflictCount(entry.conflicts) ? "text-muted-foreground" : undefined}>· {openConflictCount(entry.conflicts)}</span>
                </span>
              }
              open={openKey === entry.key}
              onToggle={(next) => setOuvert(next ? entry.key : null)}
            >
              <ConflictSeverityGroups conflicts={entry.conflicts} teams={teamsMap} coaches={coachesMap} venues={venuesMap} newFingerprints={newFingerprints} renderConflict={renderConflict} />
            </AccordionSection>
          ))}
        </div>
      )}
    </div>
  );
}
