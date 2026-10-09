import React from "react";
import { COLORS } from "./brand";

// Les trois arcs du logo — geometrie DEFINITIVE du handoff marque, recopiee de
// landing/assets/brand/mark.svg (zero import depuis landing/, meme convention d'isolement).
// Sert UNIQUEMENT de repere d'emplacement reserve dans les plans logo (3 et 12) de ce squelette ;
// le logo anime final vient des images exportees de public/logo/ (npm run logo) au montage.
// Le logo n'est JAMAIS redessine (regle 4).
export const BrandMark: React.FC<{ size?: number }> = ({ size = 420 }) => (
  <svg width={size} height={size} viewBox="0 0 1000 1000" role="img" aria-label="Logo (arcs)">
    <circle
      cx="500"
      cy="500"
      r="313"
      fill="none"
      stroke={COLORS.magenta}
      strokeWidth="83"
      strokeLinecap="round"
      pathLength={360}
      strokeDasharray="133 400"
      transform="rotate(98 500 500)"
    />
    <circle
      cx="500"
      cy="500"
      r="325"
      fill="none"
      stroke={COLORS.orange}
      strokeWidth="66"
      strokeLinecap="round"
      pathLength={360}
      strokeDasharray="69 400"
      transform="rotate(252 500 500)"
    />
    <circle
      cx="500"
      cy="500"
      r="332"
      fill="none"
      stroke={COLORS.teal}
      strokeWidth="50"
      strokeLinecap="round"
      pathLength={360}
      strokeDasharray="89 400"
      transform="rotate(334.5 500 500)"
    />
  </svg>
);
