/**
 * Formules et constantes PURES du splash « Signature » (P4-252) — recopiées du handoff marque
 * HAUTE FIDÉLITÉ (`business/7-marque/design_handoff_logo_loaders/README.md` +
 * `reference/amateo-loaders.jsx`, fonctions `glisse`/`signature`/`orbiteSig`). `business/` est
 * gitignoré : les constantes vivent DONC ici, en dur, zéro dépendance au dossier de design.
 *
 * Séparé du composant (`brand-splash.tsx`) pour que ce dernier n'exporte QUE le composant (HMR)
 * et pour tester la géométrie/les timings sans rendu.
 *
 * Phase d'APPARITION identifiée dans le prototype = le « glissé » (`glisse`) : l'icône se décale à
 * gauche et rétrécit tandis que le mot SORT du trait teal, masqué par un clip posé sur ce trait.
 * Phase de DISPARITION = la moitié « retour » du même prototype, soit l'apparition EN MIROIR (même
 * `easeInOutCubic`), suivie d'un fondu de l'overlay.
 */

// ── Géométrie du handoff (scène de référence 1920×1080, boîte icône 560, viewBox 0 0 1000 1000) ──
export const SCENE_W = 1920;
export const SCENE_H = 1080;
export const ICON_BOX = 560;
export const LOGO = { scale: 0.625, dx: -425, wordLeft: 705, top: 419, fontSize: 210, wordW: 806 } as const;
// Bord EXTÉRIEUR du trait teal (R + demi-épaisseur) rapporté à la boîte icône : le mot en sort.
export const TEAL_EDGE = ((332 + 25) / 1000) * ICON_BOX;
// Teal du mark, EN DUR (comme `brand-icon.tsx:33`) : c'est une teinte de marque, exception
// documentée à « jamais un #hex » (`.claude/rules/frontend.md`).
export const TEAL = "#46AFAC";

export interface Arc {
  color: string;
  r: number;
  w: number;
  start: number;
  len: number;
}
// Les 3 arcs concentriques du mark, géométrie DÉFINITIVE (identique à `brand-icon.tsx:31-33`).
export const LOGO_ARCS: readonly Arc[] = [
  { color: "#B51C8A", r: 313, w: 83, start: 98, len: 133 },
  { color: "#D47800", r: 325, w: 66, start: 252, len: 69 },
  { color: "#46AFAC", r: 332, w: 50, start: 334.5, len: 89 },
];

// ── Durées (apparition + fin ≤ 3 s : 1400 + 900 + 300 = 2600 ms) ──
export const TIMINGS = {
  introMs: 1400,
  outroMs: 900,
  outroFadeMs: 300,
  breathePeriodMs: 1600,
  cancelFadeMs: 250,
  reducedFadeMs: 220,
} as const;

// ── Utilitaires (recopiés du handoff) ──
export function clamp(x: number, a: number, b: number): number {
  return Math.min(b, Math.max(a, x));
}
export function easeInOutCubic(t: number): number {
  return t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2;
}
export function easeOutCubic(t: number): number {
  return 1 - (1 - t) ** 3;
}

export interface SplashLetter {
  ch: string;
  teal: boolean;
}
/** Le mot du logo, DÉRIVÉ de la variable produit : minuscules, les 2 dernières lettres en teal. */
export function splashWord(name: string): SplashLetter[] {
  const chars = name.toLowerCase().split("");
  const tealFrom = Math.max(0, chars.length - 2);
  return chars.map((ch, i) => ({ ch, teal: i >= tealFrom }));
}

export interface SignatureLayout {
  iconX: number;
  iconScale: number;
  wordDx: number;
  clipLeft: number;
}
/**
 * L'état géométrique pour un avancement de glissé `p` (0 = icône centrée → 1 = position logo) et
 * une émergence du mot `w` (0 = mot rentré dans le trait teal → 1 = mot sorti). Formules `glisse`.
 */
export function signatureLayout(p: number, w: number): SignatureLayout {
  const iconX = SCENE_W / 2 + LOGO.dx * p;
  const iconScale = 1 + (LOGO.scale - 1) * p;
  const finalEdge = SCENE_W / 2 + LOGO.dx + TEAL_EDGE * LOGO.scale;
  const hide = LOGO.wordLeft + LOGO.wordW + 20 - finalEdge;
  const wordDx = -hide * (1 - w);
  const clipLeft = clamp(iconX + TEAL_EDGE * iconScale - (LOGO.wordLeft + wordDx), 0, LOGO.wordW + 80);
  return { iconX, iconScale, wordDx, clipLeft };
}

export const FULL_LAYOUT = signatureLayout(1, 1);

/** Opacité de la respiration : 1 → ~0,55 → 1 sur une période, cosinus (easeInOut naturel). */
export function breatheOpacity(elapsed: number): number {
  const AMP = 0.45;
  const phase = (elapsed % TIMINGS.breathePeriodMs) / TIMINGS.breathePeriodMs;
  return 1 - AMP * 0.5 * (1 - Math.cos(2 * Math.PI * phase));
}
/** Fin du cycle d'opacité à partir de l'instant où l'app est prête : la prochaine borne (retour à 1). */
export function breathingSettleTime(readyElapsed: number): number {
  return Math.ceil(readyElapsed / TIMINGS.breathePeriodMs) * TIMINGS.breathePeriodMs;
}

export interface RenderFrame {
  layout: SignatureLayout;
  logoOpacity: number;
  overlayOpacity: number;
}

/** Image de l'INTRO à `elapsed` ms. `done` quand l'apparition est terminée (logo complet). */
export function introFrame(elapsed: number, reduced: boolean): RenderFrame & { done: boolean } {
  if (reduced) {
    const t = clamp(elapsed / TIMINGS.reducedFadeMs, 0, 1);
    return { layout: FULL_LAYOUT, logoOpacity: 1, overlayOpacity: t, done: elapsed >= TIMINGS.reducedFadeMs };
  }
  const p = easeInOutCubic(clamp(elapsed / TIMINGS.introMs, 0, 1));
  const wordDelay = TIMINGS.introMs * 0.2;
  const w = easeOutCubic(clamp((elapsed - wordDelay) / (TIMINGS.introMs - wordDelay), 0, 1));
  return { layout: signatureLayout(p, w), logoOpacity: 1, overlayOpacity: 1, done: elapsed >= TIMINGS.introMs };
}

/** Image de l'OUTRO à `elapsed` ms : retour EN MIROIR de l'intro, puis fondu de l'overlay. */
export function outroFrame(elapsed: number, reduced: boolean): RenderFrame & { done: boolean } {
  if (reduced) {
    const t = clamp(elapsed / TIMINGS.reducedFadeMs, 0, 1);
    return { layout: FULL_LAYOUT, logoOpacity: 1, overlayOpacity: 1 - t, done: elapsed >= TIMINGS.reducedFadeMs };
  }
  if (elapsed < TIMINGS.outroMs) {
    const back = easeInOutCubic(clamp(elapsed / TIMINGS.outroMs, 0, 1));
    const inv = 1 - back;
    return { layout: signatureLayout(inv, inv), logoOpacity: 1, overlayOpacity: 1, done: false };
  }
  const fade = clamp((elapsed - TIMINGS.outroMs) / TIMINGS.outroFadeMs, 0, 1);
  return { layout: signatureLayout(0, 0), logoOpacity: 1, overlayOpacity: 1 - fade, done: elapsed >= TIMINGS.outroMs + TIMINGS.outroFadeMs };
}

/** Vrai si l'utilisateur demande une animation réduite (mocké dans les tests via `matchMedia`). */
export function prefersReducedMotion(): boolean {
  return (
    "undefined" !== typeof window &&
    "function" === typeof window.matchMedia &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches
  );
}

export function computeFit(): number {
  if ("undefined" === typeof window) {
    return 0.4;
  }
  const w = window.innerWidth || SCENE_W;
  const h = window.innerHeight || SCENE_H;
  return clamp(Math.min((w * 0.9) / SCENE_W, (h * 0.9) / SCENE_H), 0.12, 0.5);
}
