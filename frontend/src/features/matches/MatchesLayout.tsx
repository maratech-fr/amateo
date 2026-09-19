import { Lock } from "lucide-react";
import { useEffect, useRef } from "react";
import { NavLink, Outlet, useLocation } from "react-router";

import { cn } from "@/shared/lib/utils";
import { useSocleValidated } from "@/shared/lib/socle";

import { openConflictCount } from "./lib/conflictResolution";
import { pendingReviewCount } from "./lib/reviewQueue";
import { useConflicts, useFixtures, useModuleVisit } from "./queries";

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
  const { pathname } = useLocation();
  const navRef = useRef<HTMLElement>(null);

  // La nav défile horizontalement à l'étroit (≤ 400 px) — le dernier onglet peut être hors
  // écran. À chaque changement de route, on ramène l'onglet actif (`aria-current`) dans le
  // champ visible, sans bouger la page verticalement.
  useEffect(() => {
    // `scrollIntoView` n'existe pas sous jsdom (aucun moteur de layout) — appel gardé.
    navRef.current?.querySelector('[aria-current="page"]')?.scrollIntoView?.({ inline: "nearest", block: "nearest" });
  }, [pathname]);

  // RMM-3 — le « gardien » : le POST de visite part au MONTAGE du module, une seule
  // fois (staleTime Infinity), et seulement APRÈS la garde socle — `enabled` piloté
  // par `socleValidated` pour qu'aucune visite ne soit stampée sur un module
  // verrouillé. Le résultat (bandeau résumé + chips « Nouveau ») est lu par la boucle
  // depuis le cache react-query partagé. Le layout enveloppant LES DEUX routes, la
  // navigation boucle⇄configuration ne le remonte pas → pas de re-POST.
  useModuleVisit(socleValidated);

  // PR-3b — le badge de l'onglet Importer : le nombre de rencontres à traiter
  // (NEW + OUT_OF_SYNC), dérivé du MÊME cache que la boucle et la file (aucune
  // requête en plus). En chargement/échec (pas encore de données), pas de compte —
  // jamais « Importer · 0 ».
  const fixtures = useFixtures();
  const pending = undefined !== fixtures.data ? pendingReviewCount(fixtures.data) : 0;

  // P4-207 — le badge de l'onglet Conflits : le nombre de conflits À TRAITER, lu du même
  // cache que le radar (aucune requête en plus). En chargement/échec, pas de compte —
  // jamais « Conflits · 0 ».
  const conflicts = useConflicts();
  const openConflicts = undefined !== conflicts.data ? openConflictCount(conflicts.data.conflicts) : 0;

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
      "shrink-0 whitespace-nowrap border-b-2 px-1 pb-2 pt-1 text-sm transition-colors focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40",
      isActive ? "border-accent font-medium text-foreground" : "border-transparent text-muted-foreground hover:border-border hover:text-foreground",
    );

  return (
    <div className="flex flex-col gap-4">
      <h1 className="border-l-[3px] border-accent pl-3 text-lg font-semibold">Matchs</h1>
      {/* Nav défilable horizontalement (pas de `flex-wrap`, pas de `scrollbar-hide`) : à l'étroit
          les onglets restent sur une ligne et l'actif est ramené en vue (`scrollIntoView` ci-dessus). */}
      <nav ref={navRef} aria-label="Espaces matchs" className="flex gap-4 overflow-x-auto border-b border-border sm:gap-6">
        {/* PR A — l'espace « Conflits » : tous les conflits de la saison, pivotés
            (coach/équipe/gymnase/journée), en lecture seule. P4-207 — badge = conflits
            À TRAITER, affiché seulement quand > 0. */}
        <NavLink to="/matchs/conflits" className={linkClass}>
          {openConflicts > 0 ? `Conflits · ${openConflicts}` : "Conflits"}
        </NavLink>
        {/* PR 3b — le « Calendrier » : l'écran unique du module (fusion Semaine⇄Consulter),
            placer/échanger/saisir + consulter Semaine · Mois · Phase. `end` : /matchs n'est pas
            actif quand on est sur un autre espace. */}
        <NavLink to="/matchs" end className={linkClass}>
          Calendrier
        </NavLink>
        {/* PR-3b — l'espace « Importer » : dépôt FBI / API + file de traitement par
            équipe. Le compte de rencontres à traiter s'affiche quand il est > 0. */}
        <NavLink to="/matchs/importer" className={linkClass}>
          {pending > 0 ? `Importer · ${pending}` : "Importer"}
        </NavLink>
        <NavLink to="/matchs/configuration" className={linkClass}>
          Configuration
        </NavLink>
        {/* C8 — les « Adversaires » : localisation + trajets, sortis de la Configuration
            (une page sœur dédiée, deep-linkable). */}
        <NavLink to="/matchs/adversaires" className={linkClass}>
          Adversaires
        </NavLink>
        {/* PR 2a — la « Semaine type » : le gabarit idéal + les créneaux partagés, sortis
            de la Configuration (une page sœur, deep-linkable). */}
        <NavLink to="/matchs/semaine-type" className={linkClass}>
          Semaine type
        </NavLink>
      </nav>
      <Outlet />
    </div>
  );
}
