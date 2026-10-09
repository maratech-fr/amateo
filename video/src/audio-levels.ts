// Niveaux audio et REPERES de bruitages (SFX) — regle 7ter du prompt maitre.
//
// ETAT (brouillon monte, etape 3) : AUCUN fichier audio licencie n'est telecharge dans ce run.
// La bande-son du brouillon est une PISTE DE CLICS au tempo (120 BPM), generee par script
// (scripts/make-click-track.cjs -> out/audio/click-120.wav), coupee net 0:36 -> 0:38 (respiration
// du plan 8), pour que le fondateur ENTENDE le rythme et juge le calage des plans.
//
// L'etape 4 posera la musique (public/music.wav) et les SFX licencies (public/sfx/) : ce fichier
// porte DEJA, en donnees, les gains vises et le mapping SFX par plan (table §4bis), prets a cabler.
import { BPM, FPS, measureStart, secToFrames } from "./timing";

// ── Gains (0..1). Les SFX restent SOUS la musique, sauf le clic du plan 8, seul dans le silence. ──
export const MUSIC_GAIN = 0.8; // etape 4 (public/music.wav), fondu de sortie sur la derniere seconde
export const SFX_GAIN = 0.5; // etape 4 (public/sfx/*), jamais > MUSIC_GAIN hors plan 8
export const CLICK_GAIN = 0.28; // ce brouillon : guide de tempo, volontairement discret

// ── Piste de clics du brouillon (generee, pas telechargee) ──
export const CLICK_TRACK = {
  file: "audio/click-120.wav", // out/audio/… via le pont public/media
  bpm: BPM, // 120 -> un clic toutes les 0,5 s
  // Respiration du plan 8 : silence NET de la fin de la mesure 18 (0:36,0) au debut de la mesure 20
  // (0:38,0) — cf. regle 7bis. Bornes CALCULEES depuis les mesures (jamais en secondes en dur).
  silenceFromFrame: measureStart(19), // debut mesure 19 = 36,0 s
  silenceToFrame: measureStart(20), // debut mesure 20 = 38,0 s
} as const;

export type SfxKind = "pop" | "whoosh" | "impact" | "click" | "tick" | "ding" | "rise";

export interface SfxCue {
  /** plan concerne (« P01 »..« P12 ») */
  readonly plan: string;
  /** instant absolu dans le film, en images (calcule depuis les secondes/mesures du storyboard) */
  readonly atFrame: number;
  readonly kind: SfxKind;
  /** ce que le son accompagne (langage club, zero jargon) */
  readonly note: string;
}

// Mapping SFX par plan — recopie de 03-prompts.md §4bis « Son (SFX) par plan ». DONNEES seulement :
// aucun fichier n'est joue dans ce brouillon (le son du run = la piste de clics ci-dessus).
export const SFX_CUES: readonly SfxCue[] = [
  { plan: "P01", atFrame: secToFrames(0.6), kind: "pop", note: "apparition de chaque post-it (repete sur les temps)" },
  { plan: "P02", atFrame: secToFrames(6.2), kind: "whoosh", note: "chute du post-it + cascade de ratures" },
  { plan: "P03", atFrame: secToFrames(10.0), kind: "impact", note: "apparition du logo (impact, la musique s'ouvre)" },
  { plan: "P04", atFrame: secToFrames(15.0), kind: "click", note: "clic sur l'enregistrement de la contrainte" },
  { plan: "P04", atFrame: secToFrames(15.4), kind: "pop", note: "leger pop sur l'encadre teal" },
  { plan: "P05", atFrame: secToFrames(23.5), kind: "ding", note: "arrivee de la doleance chez le gestionnaire" },
  { plan: "P05", atFrame: secToFrames(18.3), kind: "whoosh", note: "3 cadrages enchaines e-mail -> coach -> todo" },
  { plan: "P06", atFrame: secToFrames(26.5), kind: "rise", note: "swoosh/rise sous la grille qui se remplit" },
  { plan: "P07", atFrame: secToFrames(32.6), kind: "click", note: "clic du deplacement (selection + Placer ici)" },
  { plan: "P07", atFrame: secToFrames(34.4), kind: "tick", note: "tick au verrouillage" },
  // Plan 8 : AUCUN SFX d'ambiance — seul son = le clic de la bascule, dans le silence (regle 7bis).
  { plan: "P08", atFrame: secToFrames(36.8), kind: "click", note: "clic de la bascule de theme (SEUL son, silence autour)" },
  { plan: "P09", atFrame: secToFrames(42.0), kind: "whoosh", note: "glisse calendrier -> conflits" },
  { plan: "P09", atFrame: secToFrames(42.4), kind: "pop", note: "pop sur l'encadre du chevauchement" },
  { plan: "P10", atFrame: secToFrames(46.4), kind: "tick", note: "tick doux a l'apparition de l'encadre « lecture »" },
  // Plan 11 : aucun SFX marque (plan calme).
  { plan: "P12", atFrame: secToFrames(54.0), kind: "impact", note: "impact sourd sur l'appel final" },
] as const;

// Garde-fou : la respiration du plan 8 n'autorise que son clic entre 0:36 et 0:38.
export const silenceWindowSec: readonly [number, number] = [
  CLICK_TRACK.silenceFromFrame / FPS,
  CLICK_TRACK.silenceToFrame / FPS,
];
