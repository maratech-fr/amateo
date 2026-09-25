/**
 * Icône PRODUIT Amateo — la marque elle-même, PAS un jeton de thème.
 *
 * Trois arcs concentriques, géométrie DÉFINITIVE du handoff marque
 * (`business/7-marque/design_handoff_logo_loaders/README.md`). Même géométrie que
 * `landing/assets/brand/icon.svg`, recopiée EXPRÈS : par convention, zéro import
 * depuis `landing/` — les deux zones sont indépendantes par construction (`CLAUDE.md` §2).
 *
 * Les couleurs sont EN DUR ici — et SEULEMENT ici. C'est le mark de marque, ses teintes
 * sont fixes par définition. La règle « jamais un `#hex` » (`.claude/rules/frontend.md`)
 * vise les surfaces d'interface themables (fonds, texte, bordures), pas un logo.
 *
 * Décoratif par défaut (`aria-hidden`) : le sens est porté par le nom écrit à côté
 * (nom du club ou `PRODUCT_NAME`). `title` optionnel bascule sur un `role="img"` nommé
 * pour les usages isolés. Pas de disque blanc ici — l'en-tête pose l'icône sur une
 * surface, ce n'est pas une vignette d'onglet (le disque blanc vit dans le favicon).
 */
export function BrandIcon({ className, size = 24, title }: { className?: string; size?: number; title?: string }) {
  const decorative = undefined === title;
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 1000 1000"
      width={size}
      height={size}
      className={className}
      role={decorative ? undefined : "img"}
      aria-hidden={decorative ? true : undefined}
    >
      {decorative ? null : <title>{title}</title>}
      <circle cx={500} cy={500} r={313} fill="none" stroke="#B51C8A" strokeWidth={83} strokeLinecap="round" pathLength={360} strokeDasharray="133 400" transform="rotate(98 500 500)" />
      <circle cx={500} cy={500} r={325} fill="none" stroke="#D47800" strokeWidth={66} strokeLinecap="round" pathLength={360} strokeDasharray="69 400" transform="rotate(252 500 500)" />
      <circle cx={500} cy={500} r={332} fill="none" stroke="#46AFAC" strokeWidth={50} strokeLinecap="round" pathLength={360} strokeDasharray="89 400" transform="rotate(334.5 500 500)" />
    </svg>
  );
}
