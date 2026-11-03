import { create } from "zustand";
import { persist } from "zustand/middleware";


/**
 * Which resource axis drives the grid sub-columns. Same data, different display.
 *
 * P2-33 : « jour » est une quatrième vue dont l'axe filtrable est le JOUR de la semaine,
 * mais dont les colonnes de grille RESTENT les gymnases — sur un club à 8 gymnases, filtrer
 * sur lundi ramène ~5 colonnes au lieu de ~40 (fin du scroll horizontal permanent). Le
 * `buildGrid` aiguille cet axe vers le layout gymnase (`columnView`), il ne le réécrit pas.
 *
 * P3-20 : « club » est une CINQUIÈME vue — la matrice équipes × jours que seuls les exports
 * PDF/XLS savaient produire. Elle a son propre rendu (`ClubViewTable` sur `lib/clubView.ts`),
 * pas de colonnes temporelles : `buildGrid` ne la connaît donc pas. Son axe FILTRABLE est
 * l'équipe, exactement comme la vue « équipe ».
 */
export type ViewMode = "gymnase" | "coach" | "equipe" | "jour" | "club";

interface PlanningState {
  viewMode: ViewMode;
  selectedScheduleId: string | null;
  selectedSlotId: string | null;
  /** Resource ids to show for the current view; empty = show all used resources. */
  resourceFilter: string[];
  /** La version de saison lancée par « Régénérer » dans CETTE session : son échec prime à l'atterrissage. Jamais persisté. */
  sessionLaunchedScheduleId: string | null;
  setSessionLaunchedScheduleId: (id: string | null) => void;
  setViewMode: (viewMode: ViewMode) => void;
  setSelectedScheduleId: (id: string | null) => void;
  setSelectedSlotId: (id: string | null) => void;
  toggleResource: (id: string) => void;
  clearResourceFilter: () => void;
}

export const usePlanningStore = create<PlanningState>()(
  persist(
    (set) => ({
      viewMode: "gymnase",
      selectedScheduleId: null,
      selectedSlotId: null,
      resourceFilter: [],
      sessionLaunchedScheduleId: null,
      setSessionLaunchedScheduleId: (sessionLaunchedScheduleId) => set({ sessionLaunchedScheduleId }),
      // ⚠ Actions NEUTRES : elles n'arment PAS le voile. L'armement (`armNavTransition`) appartient
      // au GESTE (clic sur la bascule de vue / le sélecteur de version, dans `PlanningPage`), jamais
      // à l'action de store : `setSelectedScheduleId` est aussi appelée PROGRAMMATIQUEMENT (atterrir
      // sur la version en vigueur, réconciliation après invalidation, onSuccess d'un solve) — armer
      // ici gelait le planning à l'ARRIVÉE. Switching view invalidates the resource selection.
      setViewMode: (viewMode) => set({ viewMode, resourceFilter: [], selectedSlotId: null }),
      setSelectedScheduleId: (selectedScheduleId) => set({ selectedScheduleId, selectedSlotId: null }),
      setSelectedSlotId: (selectedSlotId) => set({ selectedSlotId }),
      toggleResource: (id) =>
        set((state) => ({
          resourceFilter: state.resourceFilter.includes(id) ? state.resourceFilter.filter((r) => r !== id) : [...state.resourceFilter, id],
        })),
      clearResourceFilter: () => set({ resourceFilter: [] }),
    }),
    {
      name: "cs-planning",
      version: 1,
      // Only the view preference is worth persisting; selections are per-session.
      partialize: (state) => ({ viewMode: state.viewMode }) as PlanningState,
      migrate: (persistedState) => {
        if (persistedState === null || typeof persistedState !== "object") {
          return { viewMode: "gymnase" } as PlanningState;
        }
        return persistedState as PlanningState;
      },
    },
  ),
);
