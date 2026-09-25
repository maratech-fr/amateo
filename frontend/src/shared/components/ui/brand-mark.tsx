import { PRODUCT_NAME } from "@/shared/lib/product";
import { cn } from "@/shared/lib/utils";

import { BrandIcon } from "./brand-icon";

/**
 * Logo COMPLET du produit = `BrandIcon` + le mot, la maison unique du logotype.
 * Se pose partout où le nom produit tenait AVANT en texte nu (login, écrans système,
 * console superadmin) — « là où c'est écrit, on met le logo » (décision fondateur).
 *
 * UN SEUL ton : le mot hérite la COULEUR DE TEXTE de l'hôte (`currentColor`) dans TOUS
 * les thèmes — une marque n'a pas deux visages. `BrandMark` ne porte donc AUCUNE couleur
 * en dur : le mark coloré, ce sont les trois arcs de `BrandIcon` seul. (Un second ton teal
 * a été essayé puis retiré : le teal du mark ne tient que ~2,5:1 sur le fond papier clair,
 * sous la barre de contraste — et `aria-hidden` n'exempte PAS le texte rendu de la règle
 * color-contrast d'axe, donc la piste « logotype exempté » ne passait pas le filet a11y.)
 *
 * Fidèle en STRUCTURE, pas au pixel : la police est celle de l'app (un sous-ensemble
 * Poppins serait une seconde police à charger — décision transmise au fondateur).
 *
 * Accessibilité : LOGOTYPE. Le nom accessible (= `PRODUCT_NAME`) vient de l'`aria-label`
 * du conteneur `role="img"` ; le mot est le visuel (un seul énoncé pour un lecteur d'écran).
 */
type BrandMarkSize = "sm" | "md" | "lg";

const SIZES: Record<BrandMarkSize, { icon: number; text: string; gap: string }> = {
  sm: { icon: 16, text: "text-sm", gap: "gap-1.5" },
  md: { icon: 24, text: "text-lg", gap: "gap-2" },
  lg: { icon: 32, text: "text-2xl", gap: "gap-2.5" },
};

export function BrandMark({ size = "md", className }: { size?: BrandMarkSize; className?: string }) {
  const s = SIZES[size];
  // Le mot est DÉRIVÉ de la marque (variable, jamais un littéral), en minuscules comme le logo.
  const word = PRODUCT_NAME.toLowerCase();

  return (
    <span role="img" aria-label={PRODUCT_NAME} className={cn("inline-flex items-center", s.gap, className)}>
      <BrandIcon size={s.icon} className="shrink-0" />
      <span aria-hidden className={cn("font-semibold tracking-tight", s.text)}>
        {word}
      </span>
    </span>
  );
}
