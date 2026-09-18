/** Small colour helpers for the per-club accent (hex in → CSS values out). */

type Rgb = { r: number; g: number; b: number };

const HEX = /^#([0-9a-fA-F]{6})$/;

export function parseHex(hex: string): Rgb | null {
  const m = HEX.exec(hex.trim());
  if (null === m) {
    return null;
  }
  const n = Number.parseInt(m[1], 16);
  return { r: (n >> 16) & 0xff, g: (n >> 8) & 0xff, b: n & 0xff };
}

function toHex({ r, g, b }: Rgb): string {
  const c = (v: number) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, "0");
  return `#${c(r)}${c(g)}${c(b)}`;
}

/** WCAG relative luminance (0 = black, 1 = white). */
export function luminance(hex: string): number {
  const rgb = parseHex(hex);
  if (null === rgb) {
    return 0;
  }
  const lin = (v: number) => {
    const c = v / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * lin(rgb.r) + 0.7152 * lin(rgb.g) + 0.0722 * lin(rgb.b);
}

/** AA-ish foreground (near-black or white) to place text on top of `hex`. */
export function readableForeground(hex: string): string {
  // Pick the foreground that clears WCAG AA (4.5:1) on `hex`. Pure black and
  // white cross over at luminance ≈ 0.179 (both ≥ 4.58:1 there), so a 0.18 split
  // keeps EVERY accent AA. The old 0.42 threshold handed white to mid-tone
  // accents (lum ~0.24) at only ~3.5:1 — the club-accent form of A11Y-06.
  return luminance(hex) > 0.18 ? "#000000" : "#ffffff";
}

/** Mix a colour toward white (amount 0..1) — used to lift a dark accent in dark mode. */
export function lighten(hex: string, amount: number): string {
  const rgb = parseHex(hex);
  if (null === rgb) {
    return hex;
  }
  return toHex({
    r: rgb.r + (255 - rgb.r) * amount,
    g: rgb.g + (255 - rgb.g) * amount,
    b: rgb.b + (255 - rgb.b) * amount,
  });
}

/** Mix a colour toward black (amount 0..1) — used to darken a light accent in light mode. */
function darken(hex: string, amount: number): string {
  const rgb = parseHex(hex);
  if (null === rgb) {
    return hex;
  }
  return toHex({ r: rgb.r * (1 - amount), g: rgb.g * (1 - amount), b: rgb.b * (1 - amount) });
}

/** WCAG contrast ratio between two colours (1..21). */
function contrastRatio(a: string, b: string): number {
  const la = luminance(a);
  const lb = luminance(b);
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/**
 * Surfaces du thème, en sRGB, converties des oklch de `src/index.css` (bloc `:root` clair, bloc
 * `.dark`) — à garder synchronisées avec ce fichier :
 *   clair  : --background oklch(0.99 0 0) → #fcfcfc ; --card oklch(1 0 0) → #ffffff
 *   sombre : --background oklch(0.19 0.01 260) → #111418 ; --card oklch(0.23 0.01 260) → #1a1d22
 */
const SURFACES: Record<"dark" | "light", { bg: string; card: string }> = {
  light: { bg: "#fcfcfc", card: "#ffffff" },
  dark: { bg: "#111418", card: "#1a1d22" },
};

const AA = 4.5; // WCAG 1.4.3 — texte normal
const STEP = 0.04; // ~4 % par pas (mix vers noir en clair, vers blanc en sombre)

/**
 * Accent d'un club ajusté pour un mode, DÉRIVÉ PAR LE CONTRASTE (A11Y-22, décision fondateur 5).
 * Un accent sert de TEXTE (`text-accent` sur `--background`/`--card`) : il doit clearer AA (4,5:1)
 * sur LES DEUX surfaces de son mode, jamais rester lisible sur la seule plus foncée. En clair on
 * assombrit par pas (mix vers `#000000`), en sombre on éclaircit (mix vers `#ffffff`), jusqu'à
 * passer sur bg ET card. Une couleur déjà conforme est rendue TELLE QUELLE ; une non-hex aussi.
 */
export function accentForMode(hex: string, mode: "dark" | "light"): string {
  if (null === parseHex(hex)) {
    return hex;
  }
  const { bg, card } = SURFACES[mode];
  const conforms = (c: string): boolean => contrastRatio(c, bg) >= AA && contrastRatio(c, card) >= AA;
  let current = hex;
  // Borne de sûreté : la suite converge vers noir (clair) / blanc (sombre), qui clearent AA.
  for (let guard = 0; guard < 64 && !conforms(current); guard++) {
    current = "light" === mode ? darken(current, STEP) : lighten(current, STEP);
  }
  return current;
}

/**
 * Teinte de SURVOL d'un bouton accent (jeton `--accent-hover`), DÉRIVÉE PAR LE CONTRASTE.
 * Le survol NE change PAS le texte du bouton : il garde le MÊME `readableForeground(accent)` que
 * le repos (jamais recalculé, sinon le libellé changerait de couleur au survol). L'ancien
 * `hover:opacity-90` compositait l'accent OPAQUE vers la surface — sur fond clair il ÉCLAIRCISSAIT
 * le fond pendant que le texte blanc restait blanc, faisant chuter le contraste (blanc/accent :
 * 4,85 → 4,26, < AA). On s'ÉLOIGNE donc de la surface : assombrir en clair, éclaircir en sombre —
 * le sens qui MONTE le contraste avec le texte du repos. Au moins UN pas (survol visiblement
 * distinct du repos — retour visuel), puis on continue jusqu'à AA (garanti au 1er pas ici, la
 * boucle est un filet). Entrée non-hex rendue telle quelle.
 */
export function accentHoverForMode(accent: string, mode: "dark" | "light"): string {
  if (null === parseHex(accent)) {
    return accent;
  }
  const fg = readableForeground(accent);
  const ok = (c: string): boolean => contrastRatio(fg, c) >= AA;
  // Toujours au moins un pas : le survol DOIT différer du repos (affordance).
  let current = "light" === mode ? darken(accent, STEP) : lighten(accent, STEP);
  for (let guard = 0; guard < 64 && !ok(current); guard++) {
    current = "light" === mode ? darken(current, STEP) : lighten(current, STEP);
  }
  return current;
}

/**
 * Distinguishable venue colours ordered as a rainbow (no black/white/grey) so a
 * freshly created gym gets a vivid default instead of a flat grey. Vivid enough
 * to tell two gyms apart on the planning grid, none so dark it needs lifting.
 */
export const VENUE_PALETTE = [
  "#E6194B", // red
  "#F58231", // orange
  "#FFD21E", // yellow
  "#BFEF45", // lime
  "#3CB44B", // green
  "#469990", // teal
  "#42D4F4", // cyan
  "#4363D8", // blue
  "#911EB4", // purple
  "#F032E6", // magenta
  "#FA5A8C", // pink
  "#9A6324", // brown
] as const;

/**
 * Next default colour for a new venue: the first palette hue not already used,
 * so gyms stay visually distinct; once the palette is exhausted it cycles by
 * count. Comparison is case-insensitive on the hex string.
 */
export function nextVenueColor(usedColors: readonly (string | null)[]): string {
  const used = new Set(usedColors.filter((c): c is string => null !== c).map((c) => c.toLowerCase()));
  const free = VENUE_PALETTE.find((c) => !used.has(c.toLowerCase()));
  return free ?? VENUE_PALETTE[used.size % VENUE_PALETTE.length];
}

/**
 * A very light background wash of a venue's hex colour (~13% alpha) for grid
 * cells. Non-hex / null colours return undefined so the caller falls back to a
 * neutral token. Single home shared by the planning + matches grids (was
 * duplicated verbatim in WeekGrid/WeekendGrid).
 */
export function tint(color: string | null): string | undefined {
  // Reuse the module's canonical hex validation (parseHex/HEX) rather than a
  // third inline regex — one source of truth for "what counts as a hex colour".
  if (null !== color && null !== parseHex(color)) {
    return `${color}22`;
  }
  return undefined;
}
