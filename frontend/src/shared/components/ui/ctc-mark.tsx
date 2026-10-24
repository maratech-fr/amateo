import { Handshake } from "lucide-react";

import { cn } from "@/shared/lib/utils";

/**
 * Le repère VISUEL d'une équipe « en entente (CTC) », posé DEVANT son nom partout où il
 * s'affiche (grilles, listes, options de sélecteur) — « pour l'identifier d'un coup d'œil »
 * (retour Rillieux #6). Maison unique : les surfaces le rendent toutes via cette primitive,
 * un changement de contrat y est donc gardé une fois (ctc-mark.test.tsx).
 *
 * Accessibilité (patron `BrandMark`) : conteneur `role="img"` dont l'`aria-label` ET le `title`
 * portent « Entente (CTC) avec {partenaire} » (ou « Entente (CTC) » sans partenaire) — le
 * partenaire se découvre au lecteur d'écran ET au survol souris, jamais au seul survol. L'icône
 * lucide est `aria-hidden` (le nom accessible vit sur le conteneur). Le texte est RENDU LITTÉRAL :
 * React échappe le partenaire, un contenu hostile reste une chaîne, jamais du HTML.
 *
 * Jeton de thème `text-accent` (repère d'identité, contraste de GLYPHE ≥ 3:1) — jamais un `#hex`.
 * Dans une option de `Listbox`, la règle `[&_svg]:text-muted-foreground` du panneau le teinte en
 * sourdine, ce qui est voulu. Marge de tête `me-1` pour tomber mécaniquement avant un nom nu.
 */
export function CtcMark({ partner, className }: { partner?: string | null; className?: string }) {
  const hasPartner = null != partner && "" !== partner.trim();
  const label = hasPartner ? `Entente (CTC) avec ${partner}` : "Entente (CTC)";

  return (
    <span role="img" aria-label={label} title={label} className={cn("me-1 inline-flex size-3.5 shrink-0 items-center justify-center align-text-bottom text-accent", className)}>
      <Handshake aria-hidden className="size-full" />
    </span>
  );
}
