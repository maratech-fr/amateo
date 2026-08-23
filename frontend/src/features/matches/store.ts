import { create } from "zustand";

import type { LoopStepId } from "./lib/loopSteps";

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
  /** P1-4 PR E1 — swap mode: the placed fixture waiting for its exchange partner. */
  swapSourceId: string | null;
  /** Manual fixture-entry dialog open. */
  fixtureFormOpen: boolean;
  /** FBI import dialog open. */
  importDialogOpen: boolean;
  setSelectedWeekend: (key: string | null) => void;
  setRailStep: (step: LoopStepId | null) => void;
  setSelectedFixtureId: (id: string | null) => void;
  setSwapSourceId: (id: string | null) => void;
  setFixtureFormOpen: (open: boolean) => void;
  setImportDialogOpen: (open: boolean) => void;
}

/** Per-session UI state — nothing worth persisting (selections are ephemeral). */
export const useMatchesStore = create<MatchesState>((set) => ({
  selectedWeekend: null,
  railStep: null,
  selectedFixtureId: null,
  swapSourceId: null,
  fixtureFormOpen: false,
  importDialogOpen: false,
  // Changer de semaine remet la vue à l'auto (le premier trou de la NOUVELLE
  // semaine) — le rail ne « saute » jamais SOUS l'utilisateur, mais une autre
  // semaine est un autre contexte : on repart de son premier trou.
  setSelectedWeekend: (selectedWeekend) => set({ selectedWeekend, railStep: null }),
  setRailStep: (railStep) => set({ railStep }),
  setSelectedFixtureId: (selectedFixtureId) => set({ selectedFixtureId }),
  setSwapSourceId: (swapSourceId) => set({ swapSourceId }),
  setFixtureFormOpen: (fixtureFormOpen) => set({ fixtureFormOpen }),
  setImportDialogOpen: (importDialogOpen) => set({ importDialogOpen }),
}));
