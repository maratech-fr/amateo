import { useQueryClient } from "@tanstack/react-query";
import { Lock } from "lucide-react";
import { useEffect, useRef } from "react";
import { NavLink, Outlet, useLocation } from "react-router";

import { HelpButton } from "@/shared/components/ui/help-button";
import { PageHeader } from "@/shared/components/ui/page-header";
import { cn } from "@/shared/lib/utils";
import { useSocleValidated } from "@/shared/lib/socle";

import type { Competition, Team } from "./api";
import { openConflictCount } from "./lib/conflictResolution";
import { composeActiveFilters, composeDisplayedRange } from "./lib/feedbackContext";
import { listPhases } from "./lib/phaseView";
import { MATCHES_HELP_LABEL, MATCHES_HELP_TRIGGER, MATCHES_TAB_HELP, MATCHES_TAB_LABEL, activeMatchesTab } from "./lib/screenHelp";
import { pendingReviewCount } from "./lib/reviewQueue";
import { useConflicts, useFixtures, useModuleVisit } from "./queries";
import { useMatchesStore } from "./store";
import { MATCHES_TEXTS } from "./texts";

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

  // UXS-07 — toute ENTRÉE dans le module (par n'importe quel onglet) consomme la règle
  // d'atterrissage conditionnel : la décision est marquée au montage, sous la même garde
  // socle que la visite. Conséquence voulue : entrer par `/matchs/importer` puis cliquer
  // « Calendrier » ne ramène JAMAIS de force sur Conflits (`MatchesLanding` voit alors la
  // décision déjà prise). La décision elle-même vit dans `MatchesLanding` (index).
  const markLandingDecided = useMatchesStore((s) => s.markLandingDecided);
  useEffect(() => {
    if (socleValidated) {
      markLandingDecided();
    }
  }, [socleValidated, markLandingDecided]);

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

  // P4-356 — les repères d'affichage du module joints au « Signaler » : les filtres actifs et la
  // plage affichée, composés depuis le store (+ le cache des phases pour le libellé de phase) en
  // deux chaînes bornées. Le front ne décide rien — il RÉSUME l'état d'affichage (`feedbackContext`).
  const { filterMode, filterIds, consultKinds, consultFamilies, consultAway, consultTemporality, selectedWeekend, consultMonth, consultPhaseId } = useMatchesStore();
  // Le libellé de phase se LIT dans le cache react-query (peuplé par le Calendrier) sans relancer
  // de requête ni s'y abonner — un repère de contexte best-effort : cache vide → plage de phase omise.
  const queryClient = useQueryClient();
  const phases = listPhases(queryClient.getQueryData<Competition[]>(["competitions"]) ?? [], queryClient.getQueryData<Team[]>(["teams"]) ?? []);
  const activePhaseId = phases.some((p) => p.competitionId === consultPhaseId) ? consultPhaseId : (phases[0]?.competitionId ?? null);
  const phaseLabel = phases.find((p) => p.competitionId === activePhaseId)?.label ?? null;
  const activeFilters = composeActiveFilters({ filterMode, filterIds, consultKinds, consultFamilies, consultAway });
  const displayedRange = composeDisplayedRange({ temporality: consultTemporality, weekendKey: selectedWeekend, month: consultMonth, phaseLabel });

  // Matchs verrouillés tant que le plan de saison ne pointe pas une version
  // (état cockpit 2) — même condition que le SocleGuard côté serveur. Le garde
  // couvre LES DEUX espaces puisqu'il enveloppe l'Outlet.
  if (!socleValidated) {
    // Même en-tête « Matchs » (trait + Signaler) que le module déverrouillé : le fondateur doit
    // pouvoir remonter une erreur même quand les matchs sont verrouillés. Le message de verrou
    // reste une carte centrée, sous l'en-tête.
    return (
      <div className="flex flex-col gap-4">
        <PageHeader
          title={MATCHES_TEXTS.pageTitle}
          screen={pathname}
          screenLabel={MATCHES_TEXTS.screenContext(MATCHES_TAB_LABEL[activeMatchesTab(pathname)])}
          activeFilters={activeFilters}
          displayedRange={displayedRange}
        />
        <div className="mx-auto max-w-md py-10 text-center">
          <Lock className="mx-auto mb-3 size-8 text-accent" />
          <h2 className="mb-1 text-lg font-semibold">{MATCHES_TEXTS.lockedTitle}</h2>
          <p className="text-sm text-muted-foreground">{MATCHES_TEXTS.lockedHint}</p>
        </div>
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
      {/* L'en-tête « Matchs » porte le bouton « Signaler » (contexte = la route de l'onglet
          courant) — un seul, en tête du module, à la place du bouton discret d'antan qui ne
          vivait que sur le Calendrier. */}
      <PageHeader
        title={MATCHES_TEXTS.pageTitle}
        screen={pathname}
        screenLabel={MATCHES_TEXTS.screenContext(MATCHES_TAB_LABEL[activeMatchesTab(pathname)])}
        activeFilters={activeFilters}
        displayedRange={displayedRange}
      />
      {/* LOT F — l'aide contextuelle (i) de l'onglet courant, à GAUCHE de la barre d'onglets :
          un seul bouton, cohérent sur les 7 écrans, dont le contenu suit l'onglet actif. */}
      <div className="flex items-center gap-2">
        <HelpButton label={MATCHES_HELP_LABEL} triggerLabel={MATCHES_HELP_TRIGGER}>
          {MATCHES_TAB_HELP[activeMatchesTab(pathname)]}
        </HelpButton>
        {/* Nav défilable horizontalement (pas de `flex-wrap`, pas de `scrollbar-hide`) : à l'étroit
            les onglets restent sur une ligne et l'actif est ramené en vue (`scrollIntoView` ci-dessus). */}
        <nav ref={navRef} aria-label={MATCHES_TEXTS.navAriaLabel} className="flex flex-1 gap-4 overflow-x-auto rounded-lg border border-border bg-card px-3 sm:gap-6">
        {/* PR A — l'espace « Conflits » : tous les conflits de la saison, pivotés
            (coach/équipe/gymnase/journée), en lecture seule. P4-207 — badge = conflits
            À TRAITER, affiché seulement quand > 0. */}
        <NavLink to="/matchs/conflits" className={linkClass}>
          {openConflicts > 0 ? MATCHES_TEXTS.tabConflictsBadge(openConflicts) : MATCHES_TEXTS.tabConflicts}
        </NavLink>
        {/* PR 3b — le « Calendrier » : l'écran unique du module (fusion Semaine⇄Consulter),
            placer/échanger/saisir + consulter Semaine · Mois · Phase. `end` : /matchs n'est pas
            actif quand on est sur un autre espace. */}
        <NavLink to="/matchs" end className={linkClass}>
          {MATCHES_TEXTS.tabCalendar}
        </NavLink>
        {/* PR-3b — l'espace « Importer » : dépôt FBI / API + file de traitement par
            équipe. Le compte de rencontres à traiter s'affiche quand il est > 0. */}
        <NavLink to="/matchs/importer" className={linkClass}>
          {pending > 0 ? MATCHES_TEXTS.tabImportBadge(pending) : MATCHES_TEXTS.tabImport}
        </NavLink>
        <NavLink to="/matchs/configuration" className={linkClass}>
          {MATCHES_TEXTS.tabConfiguration}
        </NavLink>
        {/* C8 — les « Adversaires » : localisation + trajets, sortis de la Configuration
            (une page sœur dédiée, deep-linkable). */}
        <NavLink to="/matchs/adversaires" className={linkClass}>
          {MATCHES_TEXTS.tabOpponents}
        </NavLink>
        {/* PR 2a — la « Semaine type » : le gabarit idéal + les créneaux partagés, sortis
            de la Configuration (une page sœur, deep-linkable). */}
        <NavLink to="/matchs/semaine-type" className={linkClass}>
          {MATCHES_TEXTS.tabTypicalWeek}
        </NavLink>
        {/* P4-272 ① — les « Contraintes » de match : l'écran unique (section Ligue éditable +
            Club/Équipes/Coachs à venir), en accordéon. */}
        <NavLink to="/matchs/contraintes" className={linkClass}>
          {MATCHES_TEXTS.tabConstraints}
        </NavLink>
        </nav>
      </div>
      <Outlet />
    </div>
  );
}
