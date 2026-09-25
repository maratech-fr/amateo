import { useEffect } from "react";

import { useMe } from "@/shared/session/queries";
import { useThemeStore } from "@/shared/stores/themeStore";
import { accentForMode, accentHoverForMode, readableForeground } from "@/shared/lib/color";
import { PRODUCT_ACCENT } from "@/shared/lib/product";

/**
 * Applies the club's accent colour (from /me) to the theme by overriding the
 * `--accent` CSS variables on the document root. Source of truth is the server,
 * not persisted locally (a club's accent can change and clubs differ). Reacts to
 * the dark/light mode so a dark accent stays legible on dark surfaces.
 *
 * Un club SANS couleur ne retombe PLUS sur les jetons statiques d'`index.css` : il DÉRIVE
 * l'accent PRODUIT (`PRODUCT_ACCENT`, le teal signature) par la MÊME voie que n'importe quel
 * club. Il n'y a donc qu'UNE voie de dérivation, et les jetons statiques d'`index.css` ne sont
 * que la sortie de cette même dérivation sur `PRODUCT_ACCENT` (gardé par `accentTokenParity`).
 */
export function useApplyClubTheme(): void {
  const { data: me } = useMe();
  const mode = useThemeStore((s) => s.mode);
  const accentLight = me?.club?.accentColor ?? null;
  const accentDark = me?.club?.accentColorDark ?? null;
  const palette = me?.club?.accentPalette ?? null;

  useEffect(() => {
    const root = document.documentElement;
    // Per-mode base colour: dark mode prefers the dark accent, light mode the
    // light one, each falling back to the other so a club that set only one
    // still gets an accent in both modes. A club that set NEITHER falls back to
    // the PRODUCT accent (teal) — so there is a single derivation path and no
    // separate `--accent-default` token. accentForMode ALWAYS runs — it lifts a
    // too-dark colour for legibility on dark surfaces — so a raw, near-invisible
    // accent can never reach the UI (a raw bypass here made dark mode adopt the
    // light colour untouched, reading as a theme switch).
    const base = "dark" === mode ? (accentDark ?? accentLight ?? PRODUCT_ACCENT) : (accentLight ?? accentDark ?? PRODUCT_ACCENT);
    const c = accentForMode(base, mode);
    root.style.setProperty("--accent", c);
    root.style.setProperty("--accent-foreground", readableForeground(c));
    // Teinte de survol du bouton accent : contraste préservé (jamais `opacity-90`, qui
    // compositait l'accent vers la surface et cassait AA en clair). Texte inchangé au survol.
    root.style.setProperty("--accent-hover", accentHoverForMode(c, mode));
    // Secondary tint (from the logo palette) for signature surfaces later.
    const second = palette?.[1];
    if (undefined !== second) {
      root.style.setProperty("--accent-2", accentForMode(second, mode));
    } else {
      root.style.removeProperty("--accent-2");
    }
  }, [accentLight, accentDark, palette, mode]);
}
