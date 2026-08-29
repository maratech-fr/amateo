import { type ModalSize } from "./modal";

/**
 * **La maison unique des largeurs de modale** (P4-107, 3ᵉ tranche). Chaque palier MONTE avec
 * l'écran, puis S'ARRÊTE sur un plafond fixe.
 *
 * Le plafond n'est pas une pudeur : la passe de design `ui-ux-pro-max` n'endosse que des
 * échelles qui terminent sur une largeur fixe (`DON'T Full-width text on large screens`) —
 * une modale qui suivrait indéfiniment le viewport rejouerait sur 1920 px l'anti-pattern
 * qu'on corrige ici sur 448. Tous les plafonds sont atteints dès `lg:` (viewport ≥ 1024 px) :
 * sur un portable comme sur l'écran de référence 1920×1080, c'est la même largeur.
 *
 * ⚠ **Il n'existe plus de prop `className`.** Six appelants s'étaient bricolé leur propre
 * `max-w-…` (quatre valeurs, dont une qui redisait le défaut) — c'est ce qui a produit la
 * dérive. Choisir un `size` est le SEUL geste offert, et `tsc` rougit sur toute récidive :
 * une échappatoire non gardée redeviendrait la dérive en quelques PR.
 *
 * L'échelle est spécifiée — et falsifiée dans les deux sens — par `modal-size.test.tsx`.
 */
export const MODAL_WIDTH: Record<ModalSize, string> = {
  // Confirmation / geste destructif : une phrase, deux boutons. 448 px, constant.
  sm: "max-w-md",
  // Défaut — formulaire de 6 champs au plus. Plafond 576 px : au-delà, une ligne de champs se délie.
  md: "max-w-md sm:max-w-lg lg:max-w-xl",
  // Formulaire long / liste. Plafond 768 px, sous la largeur des pages fiche (832 px).
  lg: "max-w-lg md:max-w-2xl lg:max-w-3xl",
  // Contenu tabulaire / comparaison de plannings. Plafond 1152 px (arbitrage fondateur 2026-08-21).
  xl: "max-w-2xl md:max-w-4xl lg:max-w-5xl xl:max-w-6xl",
};
