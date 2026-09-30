import { create } from "zustand";

/**
 * Le SPLASH DE CONNEXION (P4-252) — la machine à phases du logo « Signature » joué pendant
 * la connexion (PAS à l'arrivée sur /login). L'overlay lui-même vit dans `app/LoginSplash.tsx`
 * (orchestrateur monté dans `RootShell`, il survit au `navigate("/")` du login) et le dessin
 * animé dans `shared/components/ui/brand-splash.tsx`.
 *
 * Enchaînement (décisions fondateur FIGÉES) :
 *   idle → (submit) intro → { prêt à la fin de l'intro ? outro : breathing }
 *   breathing → (prêt, cycle d'opacité revenu à 1) outro
 *   outro → (fondu de l'overlay terminé) idle
 *   intro | breathing → (identifiants refusés) cancelling → idle
 *
 * Le store ne porte QUE la phase + les transitions LÉGALES ; le « quand » (fin d'intro, cycle
 * d'opacité, fin de fondu) est mesuré par `BrandSplash` (rAF) et la DÉCISION intro→outro/breathing
 * (« l'app est-elle prête ? ») est prise par l'orchestrateur. Ici : rien que la grammaire.
 */
export type LoginSplashPhase = "idle" | "intro" | "breathing" | "outro" | "cancelling";

interface LoginSplashState {
  phase: LoginSplashPhase;
  /** Submit du login : lance l'animation de début. No-op si une session est déjà en cours. */
  start: () => void;
  /** Fin de l'intro alors que l'app n'est PAS prête → la respiration en opacité. */
  beginBreathing: () => void;
  /** App prête → l'animation de fin (depuis l'intro OU la respiration). */
  beginOutro: () => void;
  /** Identifiants refusés → effacement en douceur (jamais une fois l'outro engagée : à ce
   *  stade l'app est prête, il n'y a plus d'échec possible). */
  cancel: () => void;
  /** Fin d'un cycle (outro rendue, ou fondu d'annulation terminé) → retour au repos. */
  reset: () => void;
}

export const useLoginSplashStore = create<LoginSplashState>((set, get) => ({
  phase: "idle",
  start: () => {
    if ("idle" === get().phase) {
      set({ phase: "intro" });
    }
  },
  beginBreathing: () => {
    if ("intro" === get().phase) {
      set({ phase: "breathing" });
    }
  },
  beginOutro: () => {
    const phase = get().phase;
    if ("intro" === phase || "breathing" === phase) {
      set({ phase: "outro" });
    }
  },
  cancel: () => {
    const phase = get().phase;
    if ("intro" === phase || "breathing" === phase) {
      set({ phase: "cancelling" });
    }
  },
  reset: () => set({ phase: "idle" }),
}));
