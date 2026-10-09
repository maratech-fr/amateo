// Marque en VARIABLES (regle 3 du prompt maitre, business/video_promo/03-prompts.md §1).
//
// Miroir PAR CONVENTION de landing/config.js — jamais importe depuis landing/ : video/ est une
// zone isolee a la racine du depot, independante de frontend/ ET de landing/ (meme logique que
// landing/ l'est de frontend/, cf. .claude/rules/landing.md). On DUPLIQUE les valeurs ici, on ne
// partage aucune brique. Changer le nom/l'URL/le contact = changer UNE ligne ici, et seulement ici.
//
// Aucun litteral de marque (« Amateo », « amateo.app », l'adresse de contact) n'apparait AILLEURS
// dans video/ (script, composants, README compris) : tout passe par ces constantes.
export const BRAND = {
  name: "Amateo",
  // URL de la vitrine (domaine nu, sans slash final) — meme convention que landing/config.js.
  siteUrl: "https://amateo.app",
  contactEmail: "contact@amateo.app",
  editor: "Maratech",
} as const;

// Hote nu affichable (ex. « amateo.app ») derive de siteUrl — jamais tape en dur a l'ecran.
export const brandHost: string = new URL(BRAND.siteUrl).host;

// Palette — regle 3 : les trois arcs du logo + les jetons de la vitrine (miroir des valeurs
// :root de landing/index.html). Le teal signature (`teal`) est DECORATIF ; le texte en teal
// utilise `tealInk` (>= 4.5:1 sur `paper`), jamais le teal signature (regle landing palette).
export const COLORS = {
  magenta: "#B51C8A", // arc 1 du logo
  orange: "#D47800", // arc 2 du logo
  teal: "#46AFAC", // arc 3 du logo — teal signature, decoratif seulement
  wordGray: "#464646", // gris du mot « amateo »
  paper: "#FAF9F7", // fond papier
  ink: "#191714", // encre (texte principal)
  tealInk: "#2E7876", // teal porteur de texte (contraste >= 4.5:1 sur paper)
  line: "#E7E3DC", // filets / separations
  // Aplats sombres pour un sous-titre pose sur une capture sombre, ou le fond de respiration
  // du plan 8 (la vraie bascule clair->sombre vient de la CAPTURE ; ceci n'est qu'un repli
  // d'emplacement reserve). Derives par convention de la base grise chaude de l'app/vitrine.
  darkPaper: "#201d1a",
  darkInk: "#f3f1ec",
} as const;

export const FONTS = {
  // Police d'affichage : Bricolage Grotesque (700-800). Fichier LOCAL copie de
  // landing/assets/fonts/bricolage-latin.woff2 vers public/fonts/ (voir README, licence OFL).
  // Chargee par src/fonts.ts (aucun reseau au rendu). Poppins est reservee au LOGO : interdite ici.
  display: "Bricolage Grotesque",
  // Texte courant : sans-serif systeme lisible (regle 3).
  body: 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
} as const;
