import { useEffect, useId, useRef, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { accentForMode, readableForeground } from "@/shared/lib/color";
import { PRODUCT_ACCENT, PRODUCT_NAME } from "@/shared/lib/product";
import { useMe } from "@/shared/session/queries";
import { useThemeStore } from "@/shared/stores/themeStore";

/**
 * Pastille « BÊTA » de l'en-tête — reflète l'OFFRE bêta du club (`entitlements.planCode`,
 * lu du serveur, jamais recalculé : rien hors bêta). C'est un bouton-disclosure qui ouvre un
 * popover NON MODAL expliquant l'état bêta et invitant au signalement (`onReport`, câblé sur le
 * canal feedback libre par `AppLayout`).
 *
 * Couleur = teal PRODUIT (`PRODUCT_ACCENT` passé par la MÊME dérivation de contraste que n'importe
 * quel accent, `accentForMode`), JAMAIS `--accent` (couleur du CLUB) : cette pastille parle du
 * produit, pas du club. La recette suit `StatusPill` (bordure + fond teinté + `text-foreground`,
 * repli AA : le texte ne porte pas la teinte, il reste lisible sur la teinte à 10 % composée sur
 * l'en-tête OPAQUE). Le teal vit en style inline car ce n'est ni `--accent` ni un jeton de thème —
 * `PRODUCT_ACCENT` reste la maison unique de l'hex, on ne le réécrit pas ici.
 *
 * Popover non modal (pas de piège de focus) : Échap ferme et REND le focus à la pastille ; un clic
 * extérieur ferme ; `aria-expanded`/`aria-controls` relient la pastille au panneau. Le CTA rend la
 * main au canal feedback (qui prend alors le focus, d'où l'absence de restauration à ce moment).
 *
 * Le CTA « Signaler un problème » est LUI AUSSI en teal PRODUIT (décision fondateur 2026-09-29),
 * jamais la couleur du CLUB : on réutilise la primitive `Button` et on surcharge en STYLE INLINE
 * son fond (`accentForMode(PRODUCT_ACCENT, mode)`) et son texte (`readableForeground` du fond) — la
 * MÊME dérivation que le thème pour n'importe quel accent, appliquée à `PRODUCT_ACCENT`. Le style
 * inline l'emporte sur les classes `bg-accent`/`hover:…bg-accent-hover` de la variante par défaut
 * (le survol ne rebascule donc jamais vers `--accent`). Un club à accent custom garde le CTA teal.
 */
export function BetaBadge({ onReport }: { onReport: () => void }) {
  const { data } = useMe();
  const mode = useThemeStore((state) => state.mode);
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const pillRef = useRef<HTMLButtonElement>(null);
  const panelId = useId();
  const titleId = useId();

  const isBeta = "beta" === data?.club?.entitlements?.planCode;

  // Échap ferme + rend le focus (non modal, mais on ramène l'utilisateur d'où il vient) ;
  // clic extérieur ferme sans forcer le focus. Effet inerte tant que fermé.
  useEffect(() => {
    if (!open) {
      return;
    }
    const onKey = (e: KeyboardEvent) => {
      if ("Escape" === e.key) {
        e.preventDefault();
        setOpen(false);
        pillRef.current?.focus();
      }
    };
    const onPointer = (e: MouseEvent) => {
      if (null !== rootRef.current && !rootRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener("keydown", onKey);
    document.addEventListener("mousedown", onPointer);
    return () => {
      document.removeEventListener("keydown", onKey);
      document.removeEventListener("mousedown", onPointer);
    };
  }, [open]);

  if (!isBeta) {
    return null;
  }

  const teal = accentForMode(PRODUCT_ACCENT, mode);

  return (
    <div ref={rootRef} className="relative shrink-0">
      <button
        ref={pillRef}
        type="button"
        aria-expanded={open}
        aria-controls={open ? panelId : undefined}
        onClick={() => setOpen((v) => !v)}
        // Teinte teal PRODUIT (bordure ~50 %, fond ~10 %) posée sur l'en-tête OPAQUE — le texte reste
        // `text-foreground` (repli AA), la teinte ne le porte jamais.
        style={{ borderColor: `${teal}80`, backgroundColor: `${teal}1a` }}
        className="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium text-foreground transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
      >
        BÊTA
      </button>
      {open ? (
        <div
          id={panelId}
          role="dialog"
          aria-modal="false"
          aria-labelledby={titleId}
          className="absolute left-0 z-50 mt-2 w-64 rounded-md border border-border bg-card p-3 text-left shadow-lg"
        >
          <p id={titleId} className="text-sm font-semibold text-foreground">
            {PRODUCT_NAME} est en bêta
          </p>
          <p className="mt-1 text-sm text-muted-foreground">
            L'application évolue chaque semaine. Un souci, une idée ? Vos retours comptent.
          </p>
          <Button
            size="sm"
            className="mt-3 w-full"
            // Teal PRODUIT (fond + texte lisible dérivé), jamais la couleur du club — le style inline
            // prime sur `bg-accent`/`text-accent-foreground`/`hover:…-hover` de la variante défaut.
            style={{ backgroundColor: teal, color: readableForeground(teal) }}
            onClick={() => {
              // Le canal feedback prend le focus à son ouverture : on ferme sans le ramener à la pastille.
              setOpen(false);
              onReport();
            }}
          >
            Signaler un problème
          </Button>
        </div>
      ) : null}
    </div>
  );
}
