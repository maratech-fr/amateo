// Tempo et grille temporelle du film. Regle 5 du prompt maitre : 60 i/s, 120 BPM, une mesure
// (4 temps, 2 s) = exactement 120 images. Duree totale : 60,0 s = 3600 images.
//
// REGLE D'OR : aucun numero d'image n'est jamais ecrit en dur ailleurs. Toute frontiere de plan
// se CALCULE a partir de ces constantes (par mesure) ; les sous-titres se calent sur des secondes
// reprises du storyboard. Si le BPM ou le FPS change, tout le film se recale tout seul.

export const FPS = 60;
export const BPM = 120;

// 120 BPM, mesure a 4 temps : 1 temps = 0,5 s = 30 images ; 1 mesure = 2 s = 120 images.
export const FRAMES_PER_BEAT: number = Math.round((FPS * 60) / BPM); // 30
export const FRAMES_PER_MEASURE: number = FRAMES_PER_BEAT * 4; // 120

// Le film fait 30 mesures (storyboard v2 §4bis).
export const TOTAL_MEASURES = 30;
export const TOTAL_FRAMES: number = TOTAL_MEASURES * FRAMES_PER_MEASURE; // 3600 = 60,0 s

// --- Conversions ----------------------------------------------------------------------------
export const secToFrames = (s: number): number => Math.round(s * FPS);
export const measuresToFrames = (m: number): number => Math.round(m * FRAMES_PER_MEASURE);

// Frontiere de la mesure n, 1-indexee (comme le storyboard : mesures 1 a 30). Le debut de la
// mesure n est a (n - 1) mesures du debut du film.
export const measureStart = (measure1Indexed: number): number =>
  (measure1Indexed - 1) * FRAMES_PER_MEASURE;

// Geometrie d'un plan decrit par ses mesures de debut/fin 1-indexees et INCLUSIVES
// (ex. « mesures 1-3 » = 3 mesures = 6 s). Retourne de quoi nourrir un <Sequence from=... >.
export const planFrames = (
  startMeasure: number,
  endMeasure: number,
): { from: number; durationInFrames: number } => ({
  from: measureStart(startMeasure),
  durationInFrames: (endMeasure - startMeasure + 1) * FRAMES_PER_MEASURE,
});
