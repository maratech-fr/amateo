// Choix et geometrie de l'animation du logo pour les plans 3 (ouverture) et 12 (fermeture).
//
// Le logo n'est JAMAIS redessine (regle 4) : on EXPORTE image par image une animation existante du
// graphiste (capture/capture-logo.cjs), puis on la rejoue en sequence d'images dans Remotion.
//
// DECISION FONDATEUR 2026-10-09 (revision) : les plans 3 et 12 utilisent « 01 · Orbite » (fond NUIT,
// lumiere/cometes, source business/7-marque/motion-design/amateo-motion-01-orbite.html, copiee dans
// public/logo/). L'ancienne « 02 Creneaux » (fond papier) reste derriere la MEME constante pour que
// le fondateur tranche entre les deux : `LOGO_ANIMATION` ci-dessous, defaut « orbite ».
export const LOGO_ANIMATION: "orbite" | "creneaux" = "orbite";

// Fond de l'animation choisie : « orbite » = NUIT (#08070a, cf. le HTML), « creneaux » = papier.
// Sert au plan 12 pour choisir la couleur de l'appel final (contraste >= 4,5:1 sur ce fond).
export const LOGO_IS_NIGHT: boolean = LOGO_ANIMATION === "orbite";

// Nombre d'images d'intro exportees = round(T.intro * FPS) + 1 = round(1,5 * 60) + 1 = 91
// (indices 0..90, cf. capture/capture-logo.cjs). La DERNIERE (90) = logo complet = le poster tenu.
export const LOGO_INTRO_COUNT = 91;
export const LOGO_LAST_INTRO_INDEX = LOGO_INTRO_COUNT - 1; // 90
