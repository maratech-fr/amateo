import { PRODUCT_NAME } from "@/shared/lib/product";
import { cn } from "@/shared/lib/utils";

import { BrandIcon } from "./brand-icon";

/**
 * Logo COMPLET du produit = `BrandIcon` + le mot, la maison unique du logotype.
 * Se pose partout où le nom produit tenait AVANT en texte nu (login, écrans système,
 * console superadmin) — « là où c'est écrit, on met le logo » (décision fondateur).
 *
 * Deux tons, comme le logo officiel : la tête du mot suit la COULEUR DE TEXTE de l'hôte
 * (`currentColor` → `text-foreground` en clair, blanc en sombre / sur la console), les deux
 * derniers caractères portent le teal du mark. Fidèle en STRUCTURE et en COULEURS, pas au
 * pixel : la police est celle de l'app (un sous-ensemble Poppins serait une seconde police à
 * charger — décision transmise au fondateur).
 *
 * Le teal est EN DUR ici, comme dans `BrandIcon` : c'est le logo, pas une surface themable —
 * la règle « jamais un #hex » vise les jetons d'interface, pas le mark (cf. `.claude/rules/frontend.md`).
 *
 * Accessibilité : LOGOTYPE. Le nom accessible (= `PRODUCT_NAME`) vient de l'`aria-label` du
 * conteneur `role="img"` ; le visuel deux-tons est décoratif (`aria-hidden`). Un seul énoncé
 * pour un lecteur d'écran, ET le mot n'est pas jugé comme du texte de contenu — exception
 * logotype WCAG 1.4.3 (le teal, décoratif sur fond clair, n'a pas à tenir 4,5:1 comme du texte).
 */
const MARK_TEAL = "#46AFAC";

type BrandMarkSize = "sm" | "md" | "lg";

const SIZES: Record<BrandMarkSize, { icon: number; text: string; gap: string }> = {
  sm: { icon: 16, text: "text-sm", gap: "gap-1.5" },
  md: { icon: 24, text: "text-lg", gap: "gap-2" },
  lg: { icon: 32, text: "text-2xl", gap: "gap-2.5" },
};

export function BrandMark({ size = "md", className }: { size?: BrandMarkSize; className?: string }) {
  const s = SIZES[size];
  // Le mot est DÉRIVÉ de la marque (variable, jamais un littéral) : minuscules comme le logo,
  // et on isole les DEUX derniers caractères pour le ton teal — c'est la coupe deux-tons du
  // logo officiel. Aucun mot codé en dur ici : un renommage se propage par `PRODUCT_NAME`.
  const word = PRODUCT_NAME.toLowerCase();
  const head = word.slice(0, -2);
  const tail = word.slice(-2);

  return (
    <span role="img" aria-label={PRODUCT_NAME} className={cn("inline-flex items-center", s.gap, className)}>
      <BrandIcon size={s.icon} className="shrink-0" />
      <span aria-hidden className={cn("font-semibold tracking-tight", s.text)}>
        {head}
        <span style={{ color: MARK_TEAL }}>{tail}</span>
      </span>
    </span>
  );
}
