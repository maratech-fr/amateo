import { create } from "zustand";
import { persist } from "zustand/middleware";

import type { DeepLinkOrigin } from "./lib/deepLink";
import { WIZARD_STEPS, type WizardStepId } from "./lib/steps";

/** "season" = base plan (onboarding/libre); "period" = overlay of a CalendarEntry (palier B). */
export type WizardMode = "season" | "period";

// Reservations moved to server-backed queries (Reservation entity, base/overlay) —
// they are NO LONGER client state. See useReservations / ReservationPanel.

interface WizardState {
  stepId: WizardStepId;
  /** Furthest step index reached via "Suivant" — gates forward nav in guided mode. */
  maxIndex: number;
  mode: WizardMode;
  /** The period being adapted in "period" mode; null in "season" mode. */
  calendarEntryId: string | null;
  setStep: (id: WizardStepId) => void;
  /** Go to a step and unlock everything up to it (resume-to-first-gap). */
  jumpTo: (id: WizardStepId) => void;
  next: () => void;
  prev: () => void;
  /** Enter period mode for a calendar entry — lands on Contraintes (structure is inherited). */
  startPeriodMode: (calendarEntryId: string) => void;
  /** Back to base-plan mode. */
  exitPeriodMode: () => void;
  /**
   * P2-25 — origine du RETOUR NOMMÉ quand on est arrivé par un deep-link (règle C fondateur) :
   * ÉPHÉMÈRE (jamais persisté, cf. partialize) et effacé dès qu'on agit ou qu'on repart, sinon
   * il mentirait sur la provenance ou traînerait un contexte périmé.
   */
  deepLinkOrigin: DeepLinkOrigin | null;
  setDeepLinkOrigin: (origin: DeepLinkOrigin | null) => void;
  clearDeepLinkOrigin: () => void;
  /**
   * Lot B — le planning AFFICHÉ par l'écran de génération embarqué (`PlanningPage` dérive la
   * version la plus récente en portée). ÉPHÉMÈRE (jamais persisté, cf. partialize — même patron que
   * `deepLinkOrigin`) : il alimente le « Signaler » de l'en-tête du wizard à l'étape Génération, pour
   * que le signalement y joigne le planning regardé. Hors de cette étape il n'est pas lu.
   */
  displayedScheduleId: string | null;
  setDisplayedScheduleId: (id: string | null) => void;
}

const indexOf = (id: WizardStepId): number => WIZARD_STEPS.findIndex((s) => s.id === id);

export const useWizardStore = create<WizardState>()(
  persist(
    (set) => ({
      stepId: "teams",
      maxIndex: 0,
      mode: "season",
      calendarEntryId: null,
      // ⚠ Actions NEUTRES : elles n'arment PAS le voile. L'armement (`armNavTransition`) appartient
      // au GESTE — le clic de navigation, dans les handlers de `WizardLayout` (rail, Suivant,
      // Précédent) — jamais à l'action de store, qui sert aussi au guidage AUTOMATIQUE au montage
      // (`WizardLayout` appelle `jumpTo` tout seul : deep-link, repli sur le premier trou, recap).
      // Armer ici gelait le formulaire à l'ARRIVÉE — le bug d'origine revenu par la porte de service.
      setStep: (stepId) => set({ stepId }),
      jumpTo: (stepId) => set((state) => ({ stepId, maxIndex: Math.max(state.maxIndex, indexOf(stepId)) })),
      next: () =>
        set((state) => {
          const ni = Math.min(indexOf(state.stepId) + 1, WIZARD_STEPS.length - 1);
          return { stepId: WIZARD_STEPS[ni].id, maxIndex: Math.max(state.maxIndex, ni) };
        }),
      prev: () =>
        set((state) => {
          const i = indexOf(state.stepId);
          return { stepId: WIZARD_STEPS[Math.max(i - 1, 0)].id };
        }),
      startPeriodMode: (calendarEntryId) => set({ mode: "period", calendarEntryId, stepId: "constraints", maxIndex: WIZARD_STEPS.length - 1 }),
      exitPeriodMode: () => set({ mode: "season", calendarEntryId: null, stepId: "teams" }),
      deepLinkOrigin: null,
      setDeepLinkOrigin: (deepLinkOrigin) => set({ deepLinkOrigin }),
      clearDeepLinkOrigin: () => set({ deepLinkOrigin: null }),
      displayedScheduleId: null,
      setDisplayedScheduleId: (displayedScheduleId) => set({ displayedScheduleId }),
    }),
    {
      name: "cs-wizard",
      version: 4,
      // Le retour nommé est un état de session, jamais du localStorage : le rehydrater
      // ferait réapparaître « ← Retour à … » sur une simple ouverture du wizard, sans lien.
      partialize: (state) => ({ stepId: state.stepId, maxIndex: state.maxIndex, mode: state.mode, calendarEntryId: state.calendarEntryId }),
      migrate: (persistedState) => {
        // v4 dropped the client `reservations` slice (moved server-side).
        const prev = (null !== persistedState && "object" === typeof persistedState ? persistedState : {}) as Partial<WizardState>;
        return {
          stepId: prev.stepId ?? "teams",
          maxIndex: prev.maxIndex ?? 0,
          mode: prev.mode ?? "season",
          calendarEntryId: prev.calendarEntryId ?? null,
        } as WizardState;
      },
    },
  ),
);
