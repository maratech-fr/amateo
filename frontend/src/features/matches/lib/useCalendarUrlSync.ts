import { useEffect, useRef } from "react";
import { useSearchParams } from "react-router";

import type { Coach, Competition, Fixture, Team, Venue } from "../api";
import { useMatchesStore } from "../store";
import { DEFAULT_KINDS, normalizeKinds, revealPlan } from "./consultFilter";
import {
  applyConflictFocusToParams,
  applyConsultToParams,
  applyFilterToParams,
  applyMatchToParams,
  applyWeekendToParams,
  decodeConflictFocusParam,
  decodeConsultParams,
  decodeFilterParams,
  decodeMatchParam,
  decodeWeekendParam,
  hasConsultParams,
} from "./urlState";
import { weekendKeyOf } from "./weekendGrid";

/**
 * Deep-link fusionné : filtre PR-1 + filtres Consulter + semaine.
 *
 * ⚠ Décision de conception (validée) : ce hook appelle `useMatchesStore()` et `useSearchParams()`
 * LUI-MÊME (sinon il faudrait lui passer ~15 setters). Les setters zustand sont stables, la double
 * souscription vit dans le même arbre de rendu → zéro rendu supplémentaire, et c'est ce qui permet
 * le déplacement VERBATIM de l'effet, y compris ses `useMatchesStore.getState()` internes. La page
 * GARDE son propre `useSearchParams` pour les gestes `fbi=`. Il ne prend en paramètres que les 4
 * `data` de disponibilité, la carte des compétitions et `focusFixtureCell`, et ne rend rien.
 *
 * UN seul effet, deux temps : (1) au premier passage utile (données prêtes), SEED depuis l'URL —
 * filtre PR-1 sur store vierge, filtres Consulter SEULEMENT si l'URL porte une clé Consulter (sinon
 * le store, mémoire de session non persistée, est GARDÉ), semaine ; (2) à CHAQUE passage,
 * re-SYNCHRONISE l'URL depuis le store. Fusionnés (au lieu d'un effet d'écriture séparé gardé par un
 * ref) pour que la re-synchro parte DÈS la passe de seed même quand aucune valeur n'a changé — cas
 * « URL nue, store gardé » : un ref ne redéclencherait aucun effet et l'adresse ne se
 * re-synchroniserait qu'à la prochaine interaction.
 */
export function useCalendarUrlSync(
  teamsData: Team[] | undefined,
  coachesData: Coach[] | undefined,
  venuesData: Venue[] | undefined,
  fixturesData: Fixture[] | undefined,
  competitionsMap: Map<string, Competition>,
  focusFixtureCell: (fixtureId: string) => void,
): void {
  const [searchParams, setSearchParams] = useSearchParams();
  const {
    selectedWeekend,
    setSelectedWeekend,
    setSelectedFixtureId,
    filterMode,
    filterIds,
    setFilterMode,
    toggleFilterId,
    highlightedFixtureIds,
    setHighlightedFixtureIds,
    consultKinds,
    consultFamilies,
    consultTypicalWeek,
    consultAway,
    consultTemporality,
    consultMonth,
    consultPhaseId,
    setConsultKinds,
    setConsultFamilies,
    setConsultTypicalWeek,
    setConsultAway,
    setConsultTemporality,
    setConsultMonth,
    setConsultPhaseId,
  } = useMatchesStore();
  const seededRef = useRef(false);
  useEffect(() => {
    // `match=` (deep-link vers une rencontre) exige les fixtures chargées : on attend les
    // quatre lectures pour que le seed ne rate jamais la mise en évidence.
    if (undefined === teamsData || undefined === coachesData || undefined === venuesData || undefined === fixturesData) {
      return;
    }
    // `touchedStore` : le seed a-t-il écrit dans le store CETTE passe ? Si oui, on NE
    // synchronise PAS l'URL maintenant (les valeurs lues plus bas sont encore celles d'AVANT
    // le seed → on clobberait le deep-link) ; l'écriture du store redéclenche l'effet et la
    // passe suivante synchronise avec les valeurs seedées. Si non (URL nue, store gardé), les
    // valeurs lues SONT à jour → on synchronise dès cette passe.
    let touchedStore = false;
    if (!seededRef.current) {
      seededRef.current = true;
      // Filtre PR-1 : l'URL FAIT FOI dès qu'elle porte `vue`/`filtre` (correctif 2 — une
      // navigation « Voir la semaine » depuis un conflit de coach porte `vue=coach&filtre=<id>`,
      // et ce filtre doit s'imposer même si le store portait déjà une session). Sans ces clés, on
      // NE TOUCHE PAS le filtre (la session du store est gardée).
      if (searchParams.has("vue") || searchParams.has("filtre")) {
        const { mode, ids } = decodeFilterParams(searchParams);
        const known = new Set(("coach" === mode ? coachesData : "gymnase" === mode ? venuesData : teamsData).map((r) => r.id));
        const kept = ids.filter((id) => known.has(id));
        setFilterMode(mode);
        kept.forEach(toggleFilterId);
        touchedStore = true;
      }
      // Focus d'un conflit (`conflit=<a>,<b>`) : surligne les rencontres SANS ouvrir le panneau
      // (distinct de `match=`). L'URL fait foi ; absente, le store (mémoire de session) est gardé.
      const focusIds = decodeConflictFocusParam(searchParams);
      if (focusIds.length > 0) {
        setHighlightedFixtureIds(focusIds);
        touchedStore = true;
      }
      // Mémoire de session : l'URL FAIT FOI dès qu'elle porte au moins une clé Consulter
      // (seed complet, clé absente = son défaut — un lien partagé dit vrai) ; sinon (URL nue,
      // ex. retour par l'onglet « Calendrier » vers `/matchs`) on NE TOUCHE PAS l'état
      // Consulter du store — la re-synchro ci-dessous repoussera le store dans l'adresse.
      if (hasConsultParams(searchParams)) {
        const consult = decodeConsultParams(searchParams);
        setConsultKinds(consult.kinds);
        setConsultFamilies(consult.families);
        setConsultTypicalWeek(consult.typicalWeek);
        setConsultAway(consult.away);
        setConsultTemporality(consult.temps);
        setConsultMonth(consult.month);
        setConsultPhaseId(consult.phaseId);
        touchedStore = true;
      }
      // Semaine : ne seede QUE si l'URL la porte ET que le store est à l'auto (jamais
      // clobber une semaine posée par une navigation « Voir la semaine »).
      const weekend = decodeWeekendParam(searchParams);
      if (null !== weekend && null === useMatchesStore.getState().selectedWeekend) {
        setSelectedWeekend(weekend);
        touchedStore = true;
      }
      // Deep-link `match=` : met EN ÉVIDENCE une rencontre précise (« Voir la semaine » depuis
      // Conflits). One-shot — le param est retiré à la re-synchro (`applyMatchToParams(_, null)`
      // ci-dessous) ; la mise en évidence vit ensuite dans le store (`selectedFixtureId`).
      const matchId = decodeMatchParam(searchParams);
      const matchFixture = null === matchId ? undefined : fixturesData.find((f) => f.id === matchId);
      if (undefined !== matchFixture) {
        setConsultTemporality("semaine");
        // Semaine : seulement si l'URL ne la porte pas déjà (un lien « Voir la semaine » porte
        // les deux — `semaine` fait foi) et que le store est encore à l'auto.
        if (null === weekend && null === useMatchesStore.getState().selectedWeekend) {
          setSelectedWeekend(weekendKeyOf(matchFixture.matchDate));
        }
        setSelectedFixtureId(matchFixture.id);
        // Lève le masque qui cacherait ce match (type de compétition décoché, extérieur masqué)
        // — sinon la cellule visée n'existe pas. Redondant quand l'URL portait déjà la levée
        // (le lien de Conflits l'inclut), inoffensif (`normalizeKinds` dédoublonne).
        const plan = revealPlan([matchFixture], consultKinds ?? DEFAULT_KINDS, competitionsMap);
        if (plan.away) {
          setConsultAway(true);
        }
        if (plan.kinds.length > 0) {
          setConsultKinds(normalizeKinds([...(consultKinds ?? DEFAULT_KINDS), ...plan.kinds]));
        }
        focusFixtureCell(matchFixture.id);
        touchedStore = true;
      }
      // Correctif 2 (P4-267) — à l'ARRIVÉE depuis un conflit (`conflit=`) ou un `match=`, CENTRER la
      // grille sur la première rencontre en focus une fois rendue, pour que le litige soit visible
      // sans défiler (le filtre posé par l'URL reste actif). On vise le premier id qui porte une
      // cellule (un côté à l'extérieur ou non placé n'en a pas). `scrollIntoView` gardé — jsdom n'a
      // aucun moteur de layout ; `requestAnimationFrame` : la cellule n'existe qu'après le rendu seedé.
      const centerTargets = focusIds.length > 0 ? focusIds : (undefined !== matchFixture ? [matchFixture.id] : []);
      if (centerTargets.length > 0) {
        requestAnimationFrame(() => {
          for (const targetId of centerTargets) {
            const cell = document.querySelector<HTMLElement>(`[data-fixture-id="${targetId}"]`);
            if (null !== cell) {
              cell.scrollIntoView?.({ block: "center" });

              return;
            }
          }
        });
      }
    }
    if (touchedStore) {
      return;
    }
    // Re-synchro : pousse le store (valeurs à jour) dans l'URL (`replace`), autres params
    // préservés. No-op quand l'adresse reflète déjà le store.
    const withFilter = applyFilterToParams(searchParams, filterMode, filterIds);
    const withConsult = applyConsultToParams(withFilter, {
      kinds: consultKinds,
      families: consultFamilies,
      typicalWeek: consultTypicalWeek,
      away: consultAway,
      temps: consultTemporality,
      month: consultMonth,
      phaseId: consultPhaseId,
    });
    const withWeekend = applyWeekendToParams(withConsult, selectedWeekend);
    // `conflit=` reflète le focus courant (porté par l'adresse : le bandeau + les anneaux
    // persistent, le lien est partageable). Vide quand aucun conflit n'est focalisé.
    const withFocus = applyConflictFocusToParams(withWeekend, highlightedFixtureIds);
    // `match=` est TOUJOURS retiré à la re-synchro : c'est un deep-link one-shot consommé au
    // seed (« retiré en replace après sélection »), jamais un état porté par l'adresse.
    const next = applyMatchToParams(withFocus, null);
    if (next.toString() !== searchParams.toString()) {
      setSearchParams(next, { replace: true });
    }
  }, [teamsData, coachesData, venuesData, fixturesData, searchParams, filterMode, filterIds, highlightedFixtureIds, setHighlightedFixtureIds, consultKinds, consultFamilies, consultTypicalWeek, consultAway, consultTemporality, consultMonth, consultPhaseId, selectedWeekend, setFilterMode, toggleFilterId, setConsultKinds, setConsultFamilies, setConsultTypicalWeek, setConsultAway, setConsultTemporality, setConsultMonth, setConsultPhaseId, setSelectedWeekend, setSelectedFixtureId, focusFixtureCell, competitionsMap, setSearchParams]);
}
