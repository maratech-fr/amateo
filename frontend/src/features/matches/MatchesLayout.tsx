import { Lock } from "lucide-react";
import { NavLink, Outlet } from "react-router";

import { cn } from "@/shared/lib/utils";
import { useSocleValidated } from "@/shared/lib/socle";

import { useModuleVisit } from "./queries";

/**
 * RMM-1 PR2 — « deux espaces ». Le module matchs écrasait deux temps que le
 * gestionnaire vit séparément (cadrage §1, §6ter) : la BOUCLE hebdo (importer,
 * placer, traiter, saisir) et le SET-UP rare (engagements, accès, habitudes,
 * image A/B). Ce layout porte ce qui est COMMUN aux deux espaces — le garde
 * socle (une seule fois, plus dupliqué dans chaque page) et la navigation — et
 * délègue le contenu à l'`<Outlet/>`. Les deux espaces sont deux ROUTES
 * (deep-link + bouton retour, allers-retours faciles — mission §1) ; la nav est
 * donc faite de vrais liens (react-router pose `aria-current`), stylés en
 * onglets soulignés pour lire comme une nav SECTIONNELLE, sous la nav applicative
 * (qui, elle, garde la langue des pastilles).
 */
export function MatchesLayout() {
  const socleValidated = useSocleValidated();

  // RMM-3 — le « gardien » : le POST de visite part au MONTAGE du module, une seule
  // fois (staleTime Infinity), et seulement APRÈS la garde socle — `enabled` piloté
  // par `socleValidated` pour qu'aucune visite ne soit stampée sur un module
  // verrouillé. Le résultat (bandeau résumé + chips « Nouveau ») est lu par la boucle
  // depuis le cache react-query partagé. Le layout enveloppant LES DEUX routes, la
  // navigation boucle⇄configuration ne le remonte pas → pas de re-POST.
  useModuleVisit(socleValidated);

  // Matchs verrouillés tant que le plan de saison ne pointe pas une version
  // (état cockpit 2) — même condition que le SocleGuard côté serveur. Le garde
  // couvre LES DEUX espaces puisqu'il enveloppe l'Outlet.
  if (!socleValidated) {
    return (
      <div className="mx-auto max-w-md py-16 text-center">
        <Lock className="mx-auto mb-3 size-8 text-accent" />
        <h1 className="mb-1 text-lg font-semibold">Matchs verrouillés</h1>
        <p className="text-sm text-muted-foreground">Validez d'abord votre planning principal (accueil → Ouvrir) pour débloquer les matchs.</p>
      </div>
    );
  }

  const linkClass = ({ isActive }: { isActive: boolean }): string =>
    cn(
      "border-b-2 px-1 pb-2 pt-1 text-sm transition-colors focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40",
      isActive ? "border-accent font-medium text-foreground" : "border-transparent text-muted-foreground hover:border-border hover:text-foreground",
    );

  return (
    <div className="flex flex-col gap-4">
      <h1 className="border-l-[3px] border-accent pl-3 text-lg font-semibold">Matchs</h1>
      <nav aria-label="Espaces matchs" className="flex gap-6 border-b border-border">
        {/* `end` : /matchs (la boucle) n'est pas actif quand on est en Configuration. */}
        <NavLink to="/matchs" end className={linkClass}>
          Semaine
        </NavLink>
        <NavLink to="/matchs/configuration" className={linkClass}>
          Configuration
        </NavLink>
      </nav>
      <Outlet />
    </div>
  );
}
