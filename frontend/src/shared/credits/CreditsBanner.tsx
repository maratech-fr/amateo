import { AlertTriangle, X } from "lucide-react";
import { useReducer } from "react";
import { Link } from "react-router";

import { NoticeBanner } from "@/shared/components/ui/notice-banner";

import { useCredits } from "./useCredits";

// Clé de session : on y grave le solde AU MOMENT de la fermeture. Le bandeau
// d'urgence reste masqué tant que le solde ne bouge pas, réapparaît dès qu'il
// BAISSE encore (valeur différente) ou à la session suivante (sessionStorage
// vidé) — règle §7.2 : un bandeau qui crie à chaque navigation n'alerte plus.
const DISMISS_KEY = "credits-urgency-dismissed-at";

/**
 * P1-3 §4bis pts 3-4 — bandeau de conversion (Découverte bridée seulement) :
 *  - solde 0 : bandeau PERMANENT non fermable, ton juste (consulter/ajuster
 *    restent ouverts, une offre rouvre la génération) ;
 *  - solde 1→3 : bandeau d'URGENCE rouge, fermable, qui ne se ré-affiche pas à
 *    chaque navigation (voir DISMISS_KEY).
 * CTA « Voir les offres » → la section Offre de la page club.
 */
export function CreditsBanner() {
  // Un compteur pour forcer le re-render à la fermeture (l'état vit en sessionStorage).
  const [, bump] = useReducer((n: number) => n + 1, 0);
  const credits = useCredits();
  if (null === credits) {
    return null;
  }

  const offersCta = (
    <Link to="/club" className="shrink-0 font-medium underline underline-offset-2 hover:opacity-80">
      Voir les offres
    </Link>
  );

  if (0 === credits.remaining) {
    return (
      <NoticeBanner tone="destructive" role="alert" className="mb-4">
        <div className="flex items-center gap-2">
          <AlertTriangle className="size-4 shrink-0 text-destructive" aria-hidden="true" />
          <span className="min-w-0 flex-1">
            Vos crédits gratuits sont épuisés. Consultez et ajustez librement — passez à une offre pour générer à nouveau.
          </span>
          {offersCta}
        </div>
      </NoticeBanner>
    );
  }

  const dismissed = credits.remaining > 3 || sessionStorage.getItem(DISMISS_KEY) === String(credits.remaining);
  if (dismissed) {
    return null;
  }

  const close = () => {
    sessionStorage.setItem(DISMISS_KEY, String(credits.remaining));
    bump();
  };

  return (
    <NoticeBanner tone="destructive" role="alert" className="mb-4">
      <div className="flex items-center gap-2">
        <AlertTriangle className="size-4 shrink-0 text-destructive" aria-hidden="true" />
        <span className="min-w-0 flex-1">
          Il ne vous reste que {credits.remaining} crédit{credits.remaining > 1 ? "s" : ""} gratuit{credits.remaining > 1 ? "s" : ""} — chaque génération, placement de matchs ou export en consomme un.
        </span>
        {offersCta}
        <button type="button" onClick={close} aria-label="Masquer l'alerte crédits" className="shrink-0 rounded p-0.5 text-muted-foreground hover:text-foreground">
          <X className="size-4" />
        </button>
      </div>
    </NoticeBanner>
  );
}
