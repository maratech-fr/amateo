import { create } from "zustand";

import type { ConflictType, RencontreCreatable } from "./api";
import type { Kind } from "./lib/consultFilter";
import type { LoopStepId } from "./lib/loopSteps";
import type { MatchFilterMode } from "./lib/matchFilter";

/**
 * PR-3a/PR-3b — le payload porté EN MÉMOIRE vers la vue de réconciliation
 * (`/matchs/reconciliation`), désormais RÉDUIT au seul canal API FFBB pour ses
 * rencontres À CRÉER (`creatable`). Les écarts, eux, ne transitent plus par cette
 * vue : ils sont PERSISTÉS sur les rencontres (`Fixture.pendingDeviations`) et se
 * tranchent dans la file de l'onglet Importer. `null` = rien à intégrer : arriver
 * sur la vue (accès direct/refresh) sans payload est un « renvoi propre ».
 */
export type ReconciliationPayload = { channel: "api"; creatable: RencontreCreatable[]; fetchedAt: string };

interface MatchesState {
  /** Saturday key of the weekend shown on the grid; null = auto (first available). */
  selectedWeekend: string | null;
  /**
   * RMM-1 PR3 — la VUE de la boucle sélectionnée (rail⇄vue). `null` = auto = le
   * premier trou (première étape non-done de la semaine affichée). Un clic sur le
   * rail la POSE ; changer de semaine la remet à `null` pour que l'auto recalcule.
   */
  railStep: LoopStepId | null;
  /** Fixture being placed (opens the placement panel); null = none. */
  selectedFixtureId: string | null;
  /**
   * RMM-1 PR4 (L6) — raisons de non-placement du DERNIER auto-placement, par
   * matchId. Attachées à la SEMAINE affichée : elles persistent tant qu'on ne
   * change pas de semaine (un re-render, un autre geste ne les efface pas), et
   * `setSelectedWeekend` les PURGE (une raison d'une autre semaine ne fuit pas).
   */
  unplacedReasons: Map<string, string>;
  /** P1-4 PR E1 — swap mode: the placed fixture waiting for its exchange partner. */
  swapSourceId: string | null;
  /** Manual fixture-entry dialog open. */
  fixtureFormOpen: boolean;
  /** FBI import dialog open. */
  importDialogOpen: boolean;
  /** RMM-4 — analysis payload carried in memory to the reconciliation view. */
  reconciliation: ReconciliationPayload | null;
  /**
   * PR-1 — filtre de la vue Semaine. `filterMode` = l'axe (équipe/coach/gymnase,
   * défaut « equipe ») ; `filterIds` = les ressources cochées de cet axe. Changer
   * d'axe VIDE la sélection. Non persisté (l'URL porte le deep-link). Changer de
   * semaine ne le purge PAS (c'est un filtre transversal, pas un état de semaine).
   */
  filterMode: MatchFilterMode;
  filterIds: string[];
  /**
   * PR-2a — filtres de l'onglet Consulter. `null` = tout coché (le défaut, jamais
   * sérialisé) ; un tableau = la sélection explicite (éventuellement vide). Non
   * persisté (l'URL `?type=&conflits=&type_semaine=` porte le deep-link). Séparés
   * du filtre PR-1 (`filterMode`/`filterIds`), qui vaut sur les DEUX routes.
   */
  consultKinds: Kind[] | null;
  consultFamilies: ConflictType[] | null;
  /** Semaine type = les ghosts d'habitude sur la grille ; affichée par défaut. */
  consultTypicalWeek: boolean;
  /**
   * PR-2b — la temporalité de l'onglet Consulter : Semaine (défaut, byte-identique
   * PR-2a) · Mois · Phase. `consultMonth` = le mois `YYYY-MM` affiché (Mois),
   * `consultPhaseId` = la compétition appariée affichée (Phase) ; `null` = auto
   * (premier ≥ courant / première phase). Non persisté (l'URL `?temps=&mois=&phase=`
   * porte le deep-link).
   */
  consultTemporality: ConsultTemporality;
  consultMonth: string | null;
  consultPhaseId: string | null;
  setSelectedWeekend: (key: string | null) => void;
  setRailStep: (step: LoopStepId | null) => void;
  setUnplacedReasons: (reasons: Map<string, string>) => void;
  setSelectedFixtureId: (id: string | null) => void;
  setSwapSourceId: (id: string | null) => void;
  setFixtureFormOpen: (open: boolean) => void;
  setImportDialogOpen: (open: boolean) => void;
  setReconciliation: (payload: ReconciliationPayload | null) => void;
  setFilterMode: (mode: MatchFilterMode) => void;
  toggleFilterId: (id: string) => void;
  clearFilter: () => void;
  setConsultKinds: (kinds: Kind[] | null) => void;
  setConsultFamilies: (families: ConflictType[] | null) => void;
  setConsultTypicalWeek: (typicalWeek: boolean) => void;
  setConsultTemporality: (temporality: ConsultTemporality) => void;
  setConsultMonth: (month: string | null) => void;
  setConsultPhaseId: (phaseId: string | null) => void;
}

/** PR-2b — les trois temporalités de l'onglet Consulter. */
export type ConsultTemporality = "semaine" | "mois" | "phase";

/** Per-session UI state — nothing worth persisting (selections are ephemeral). */
export const useMatchesStore = create<MatchesState>((set) => ({
  selectedWeekend: null,
  railStep: null,
  selectedFixtureId: null,
  unplacedReasons: new Map(),
  swapSourceId: null,
  fixtureFormOpen: false,
  importDialogOpen: false,
  reconciliation: null,
  filterMode: "equipe",
  filterIds: [],
  consultKinds: null,
  consultFamilies: null,
  consultTypicalWeek: true,
  consultTemporality: "semaine",
  consultMonth: null,
  consultPhaseId: null,
  // Changer de semaine remet la vue à l'auto (le premier trou de la NOUVELLE
  // semaine) — le rail ne « saute » jamais SOUS l'utilisateur, mais une autre
  // semaine est un autre contexte : on repart de son premier trou. Les raisons
  // de non-placement sont attachées à la semaine affichée : on les PURGE aussi
  // (une raison d'une autre semaine ne doit pas rester à l'écran).
  setSelectedWeekend: (selectedWeekend) => set({ selectedWeekend, railStep: null, unplacedReasons: new Map() }),
  setRailStep: (railStep) => set({ railStep }),
  setUnplacedReasons: (unplacedReasons) => set({ unplacedReasons }),
  setSelectedFixtureId: (selectedFixtureId) => set({ selectedFixtureId }),
  setSwapSourceId: (swapSourceId) => set({ swapSourceId }),
  setFixtureFormOpen: (fixtureFormOpen) => set({ fixtureFormOpen }),
  setImportDialogOpen: (importDialogOpen) => set({ importDialogOpen }),
  setReconciliation: (reconciliation) => set({ reconciliation }),
  // Changer d'axe VIDE la sélection (les ids d'un axe n'ont pas de sens sur un
  // autre) et remet la vue du rail à l'auto (le filtre recadre la semaine).
  setFilterMode: (filterMode) => set({ filterMode, filterIds: [], railStep: null }),
  toggleFilterId: (id) =>
    set((state) => ({ filterIds: state.filterIds.includes(id) ? state.filterIds.filter((x) => x !== id) : [...state.filterIds, id], railStep: null })),
  clearFilter: () => set({ filterIds: [] }),
  setConsultKinds: (consultKinds) => set({ consultKinds }),
  setConsultFamilies: (consultFamilies) => set({ consultFamilies }),
  setConsultTypicalWeek: (consultTypicalWeek) => set({ consultTypicalWeek }),
  setConsultTemporality: (consultTemporality) => set({ consultTemporality }),
  setConsultMonth: (consultMonth) => set({ consultMonth }),
  setConsultPhaseId: (consultPhaseId) => set({ consultPhaseId }),
}));
