import { HTTPError } from "ky";
import { AlertTriangle, ArrowLeft, CalendarClock, ChevronsDown, ChevronsUp, MessageSquare, PanelLeftClose, PanelLeftOpen, X } from "lucide-react";
import { type ReactNode, useEffect, useMemo, useRef, useState } from "react";
import { useBlocker, useNavigate, useSearchParams } from "react-router";

import { useQueryClient } from "@tanstack/react-query";

import { useMe } from "@/shared/session/queries";
import { useCalendarEntry, useDeleteEntry, usePeriodAnchor } from "@/features/cockpit/queries";
import { frDateNumeric } from "@/features/cockpit/lib/date";
import { CoachWishesModal } from "@/features/coach-wishes/CoachWishesModal";
import { canOpenWishes, wishesMotherId, wishesWeekFilter } from "@/features/coach-wishes/wishesTarget";
import { DeletePlanningButton } from "@/features/cockpit/DeletePlanningButton";
import { FeedbackButton } from "@/features/feedback/FeedbackButton";
import { listSchedules } from "@/features/planning/api";
import { useSchedules } from "@/features/planning/queries";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { StepRail } from "@/shared/components/ui/step-rail";
import { cn } from "@/shared/lib/utils";
import { armNavTransition } from "@/shared/stores/navTransitionStore";

import { toast } from "@/shared/stores/toastStore";

import { parseWizardDeepLink, stepLockReason } from "./lib/deepLink";
import { WizardFooterContext } from "./lib/footerSlot";
import { WIZARD_STEPS, type WizardStepId } from "./lib/steps";
import { useStepValidation } from "./lib/useStepValidation";
import { useVenueMatchWindows } from "@/features/matches/queries";

import { useVenueSlots, useWizardCoaches, useWizardTeams, useWizardVenues } from "./queries";
import { CoachesStep } from "./steps/CoachesStep";
import { ConstraintsStep } from "./steps/ConstraintsStep";
import { GenerateStep } from "./steps/GenerateStep";
import { RecapStep } from "./steps/RecapStep";
import { TeamsStep } from "./steps/TeamsStep";
import { VenuesStep } from "./steps/VenuesStep";
import { useWizardStore } from "./store";
import { venuesWithoutSlot } from "./lib/useStepValidation";

function StepContent({ stepId }: { stepId: WizardStepId }) {
  switch (stepId) {
    case "teams":
      return <TeamsStep />;
    case "venues":
      return <VenuesStep />;
    case "coaches":
      return <CoachesStep />;
    case "constraints":
      return <ConstraintsStep />;
    case "recap":
      return <RecapStep />;
    case "generate":
      return <GenerateStep />;
  }
}

/** Floating top/bottom page-jump arrows, shown on every step whenever the page
 *  actually scrolls (was TeamsStep-only before). Pinned to the right edge at
 *  vertical center — NOT bottom-right, to avoid overlapping the sticky footer's
 *  Suivant button. `suppressed` hides them (e.g. during Teams drag-reorder). */
function ScrollJumpButtons({ suppressed }: { suppressed: boolean }) {
  const [scrollable, setScrollable] = useState(false);

  useEffect(() => {
    const check = () => setScrollable(document.documentElement.scrollHeight > window.innerHeight + 48);
    check();
    const observer = new ResizeObserver(check);
    observer.observe(document.body);
    window.addEventListener("resize", check);
    return () => {
      observer.disconnect();
      window.removeEventListener("resize", check);
    };
  }, []);

  if (suppressed || !scrollable) {
    return null;
  }
  return (
    <div className="fixed right-4 top-1/2 z-40 hidden -translate-y-1/2 flex-col gap-1 sm:flex">
      <Button size="icon" variant="outline" aria-label="Haut de page" onClick={() => window.scrollTo({ top: 0, behavior: "smooth" })}>
        <ChevronsUp className="size-4" />
      </Button>
      <Button size="icon" variant="outline" aria-label="Bas de page" onClick={() => window.scrollTo({ top: document.body.scrollHeight, behavior: "smooth" })}>
        <ChevronsDown className="size-4" />
      </Button>
    </div>
  );
}

export function WizardPage() {
  const { stepId, maxIndex, mode, calendarEntryId, setStep, jumpTo, next, prev, exitPeriodMode, deepLinkOrigin, setDeepLinkOrigin, clearDeepLinkOrigin } = useWizardStore();
  const { data: me } = useMe();
  const navigate = useNavigate();
  const periodMode = "period" === mode;
  const { data: periodEntry, error: periodEntryError } = useCalendarEntry(periodMode ? calendarEntryId : null);
  // #10 — les doléances vivent sur l'entrée MÈRE des vacances ; le store peut porter une
  // SEMAINE enfant. On résout la mère et on filtre la todo-list sur la semaine du plan
  // courant (plan de bloc → parentEntryId null → toutes les semaines).
  const { data: motherEntry } = useCalendarEntry(wishesMotherId(periodEntry));
  // Ne JAMAIS retomber sur l'ENFANT tant que la mère charge : la modale s'ancrerait à
  // l'id enfant → liste vide + ajout refusé en 422 (revue #10 C1). On n'utilise periodEntry
  // directement que s'il EST la mère (pas de parent) ; sinon on attend motherEntry.
  const wishesMother = periodEntry?.parentEntryId ? (motherEntry ?? null) : periodEntry ?? null;
  const wishesFilter = wishesWeekFilter(periodEntry);
  const [wishesOpen, setWishesOpen] = useState(false);
  // Le bouton n'apparaît QUE quand la mère est résolue : sur une semaine enfant, wishesMother
  // reste null un instant le temps du fetch ; l'afficher avant rendrait le clic mort (la
  // modale est gardée sur wishesMother non-null) sans retour (revue #10 C1 round 2).
  const canWishes = periodMode && canOpenWishes(periodEntry) && null !== wishesMother;
  const validation = useStepValidation(stepId);
  // The generation step is gated by the SAME blockers as the Récap "Continuer"
  // button — otherwise the left nav lets an onboarded club (nav never locked)
  // jump straight to génération and bypass the gate. Lock it here too, and keep
  // it locked while the verdict is still loading (fail-closed).
  const recapValidation = useStepValidation("recap");
  const generateBlocked = recapValidation.errors.length > 0 || true === recapValidation.pending;

  // P4-58 (b) — coches « étape terminée » dans le rail : mêmes verdicts que les
  // gates (une source, useStepValidation — un rail qui compterait autrement que
  // les portes ferait deux vérités, §7.2). Une coche n'apparaît qu'un verdict
  // RENDU (pas pendant le chargement — fail-silent, jamais fail-vert). La
  // génération n'en porte pas : c'est l'action, pas une saisie à terminer.
  const teamsValidation = useStepValidation("teams");
  const venuesValidation = useStepValidation("venues");
  const coachesValidation = useStepValidation("coaches");
  const constraintsValidation = useStepValidation("constraints");
  const stepDone: Partial<Record<WizardStepId, boolean>> = {
    teams: 0 === teamsValidation.errors.length && true !== teamsValidation.pending,
    venues: 0 === venuesValidation.errors.length && true !== venuesValidation.pending,
    coaches: 0 === coachesValidation.errors.length && true !== coachesValidation.pending,
    constraints: 0 === constraintsValidation.errors.length && true !== constraintsValidation.pending,
    recap: 0 === recapValidation.errors.length && true !== recapValidation.pending,
  };

  // « On part » — lu par le prédicat du blocker au moment de la navigation (les
  // valeurs de render y sont STALE : react-router enregistre le prédicat en
  // useEffect, donc une nav déclenchée dans le même cycle voit l'ancien état).
  const leavingRef = useRef(false);

  // The period mode is persisted (localStorage). If its entry was deleted in the
  // meantime, exit cleanly instead of leaving a dead wizard (404 + disabled CTA).
  // The query is meta.silent404 — this toast is the only, explicit, feedback.
  useEffect(() => {
    if (periodMode && periodEntryError instanceof HTTPError && 404 === periodEntryError.response.status) {
      toast.error("Cette période n'existe plus — retour à l'accueil.");
      leavingRef.current = true;
      exitPeriodMode();
      navigate("/");
    }
  }, [periodMode, periodEntryError, exitPeriodMode, navigate]);
  const index = WIZARD_STEPS.findIndex((s) => s.id === stepId);
  const currentStep = WIZARD_STEPS[index];
  const blocked = validation.errors.length > 0;
  const isLast = index === WIZARD_STEPS.length - 1;
  const [navCollapsed, setNavCollapsed] = useState(false);
  const [footerExtra, setFooterExtra] = useState<ReactNode>(null);
  const [suppressScrollJump, setSuppressScrollJump] = useState(false);
  const footerCtx = useMemo(() => ({ setFooterExtra, setSuppressScrollJump }), []);
  // Onboarding (the club has never generated) → guided: forward steps stay locked
  // until reached via "Suivant". Existing clubs edit freely. Period mode is never
  // guided (structure is inherited read-only; nav is open).
  const guided = !periodMode && !me?.seasonPlan?.hasFinishedVersion;

  // ── P2-25 — porte d'entrée : l'URL décide l'étape (adressabilité) ──
  // On AJOUTE une lecture d'URL, on ne réécrit PAS la navigation interne (jumpTo/maxIndex/
  // l'atterrissage guidé restent tels quels). Chaque deep-link n'est appliqué qu'UNE fois
  // (clé = querystring) : ni boucle, ni combat avec la navigation manuelle.
  const [searchParams] = useSearchParams();
  const deepLink = useMemo(() => parseWizardDeepLink(searchParams), [searchParams]);
  const appliedDeepLinkRef = useRef<string | null>(null);
  // L'étape sur laquelle le deep-link a fait atterrir — sert au retour nommé éphémère.
  const landedStepRef = useRef<WizardStepId | null>(null);
  useEffect(() => {
    // On attend de CONNAÎTRE le statut guidé (me chargé) avant d'appliquer : sans lui, la
    // vérification de verrou serait fausse. me est en cache app-wide → instantané en pratique.
    if (null === deepLink.step || undefined === me) {
      return;
    }
    const key = searchParams.toString();
    if (appliedDeepLinkRef.current === key) {
      return;
    }
    appliedDeepLinkRef.current = key;
    // Étape verrouillée en mode guidé : on NE saute PAS (atterrissage propre). Le lien SOURCE
    // est déjà rendu désactivé (WizardStepLink) ; ceci protège une URL tapée/collée à la main.
    if (null !== stepLockReason(deepLink.step, { guided, maxIndex })) {
      return;
    }
    jumpTo(deepLink.step);
    landedStepRef.current = deepLink.step;
    setDeepLinkOrigin(deepLink.origin);
  }, [deepLink, searchParams, guided, maxIndex, me, jumpTo, setDeepLinkOrigin]);

  // Retour nommé ÉPHÉMÈRE : dès qu'on repart (changement d'étape), il s'efface — sinon il
  // mentirait sur la provenance. L'« agir » (éditer/enregistrer) est effacé par l'étape elle-même.
  useEffect(() => {
    if (null !== deepLinkOrigin && null !== landedStepRef.current && stepId !== landedStepRef.current) {
      clearDeepLinkOrigin();
      landedStepRef.current = null;
    }
  }, [stepId, deepLinkOrigin, clearDeepLinkOrigin]);

  // On first entry of a guided wizard, land on the first incomplete step (no
  // team → Équipes, …); when everything is filled, land on Récap — the last
  // stop before generating the main plan.
  const teams = useWizardTeams();
  const venues = useWizardVenues();
  const slots = useVenueSlots();
  const coaches = useWizardCoaches();
  // P1-4 PR B — même exemption « fenêtre match » que le gate (useStepValidation)
  // et le bandeau (VenuesStep) : la règle vit à TROIS sites, ils bougent ensemble.
  // Un échec de lecture ne bloque pas le positionnement (settled, pas success) :
  // sans exemption on est plus strict, jamais moins.
  const matchWindows = useVenueMatchWindows();
  const positioned = useRef(false);
  const ready = teams.isSuccess && venues.isSuccess && slots.isSuccess && coaches.isSuccess && !matchWindows.isLoading;
  useEffect(() => {
    if (positioned.current || !guided || !ready) {
      return;
    }
    positioned.current = true;
    const venueList = venues.data ?? [];
    const slotList = slots.data ?? [];
    const matchVenues = new Set((matchWindows.data ?? []).map((w) => w.venueId));
    let gap: WizardStepId | null = null;
    // P2-21 lot A — des équipes importées AUTOMATIQUEMENT que le gestionnaire
    // n'a pas encore vues : l'atterrissage vise Équipes (la modale d'annonce y
    // vit), sinon le « premier trou » (gymnases) court-circuiterait l'écran que
    // la modale invite justement à corriger. Gate = vérité serveur
    // `ffbbTeamsImported` (jamais la seule absence du flag localStorage — elle
    // capturait toute saisie manuelle vue d'un navigateur vierge, e2e comprises) ;
    // même flag one-shot que TeamsStep.
    const clubId = me?.club?.id;
    const importUnseen =
      true === me?.club?.ffbbTeamsImported &&
      (teams.data ?? []).length > 0 &&
      undefined !== clubId &&
      null === window.localStorage.getItem(`ffbb-teams-import-notice-${clubId}`);
    if (0 === (teams.data ?? []).length || importUnseen) {
      gap = "teams";
      // D-24 : même prédicat que la porte et le bandeau — la règle ne vit plus à trois sites.
    } else if (0 === venueList.length || venuesWithoutSlot(venueList, slotList, matchVenues).length > 0) {
      gap = "venues";
    } else if (0 === (coaches.data ?? []).length) {
      gap = "coaches";
    }
    if (null !== gap) {
      jumpTo(gap); // pull back to the first incomplete step
    } else if (WIZARD_STEPS.findIndex((s) => s.id === stepId) < WIZARD_STEPS.findIndex((s) => "recap" === s.id)) {
      // Everything filled and the user is before Récap → land on Récap. Do NOT
      // pull a user already ON/AFTER Récap back (e.g. mid first-generation on the
      // génération step — a remount must not yank them off the progress view).
      jumpTo("recap");
    }
  }, [guided, ready, stepId, teams.data, venues.data, slots.data, coaches.data, matchWindows.data, jumpTo, me?.club?.id, me?.club?.ffbbTeamsImported]);

  // ── Abandon d'un ajustement de période jamais généré (retour fondateur 2026-07-18) ──
  // « Adapter » crée la période AVANT le wizard (ADR-0002 : le plan naît du geste) ;
  // repartir sans rien générer laissait une entrée orpheline sur tout le créneau des
  // vacances. Quitter (bouton ou navigation SPA) propose de retirer la période dès
  // qu'aucune version n'est CONNUE — donnée en vol/en échec incluse : dégrader en
  // sortie silencieuse referait exactement l'orphelin (revue #260 round 2). Le
  // dialogue est CONDITIONNEL (« si aucun planning n'a été généré… ») ; la décision
  // destructive, elle, se prend sur une lecture serveur FRAÎCHE (confirmAbandon) —
  // fetch muet ou plan irrésolu = jamais de suppression.
  const periodAnchor = usePeriodAnchor(periodMode ? calendarEntryId : null);
  const periodPlanId = periodAnchor.planId;
  const wizardSchedules = useSchedules(periodMode);
  const periodHasKnownVersion =
    null !== periodPlanId
    && undefined !== wizardSchedules.data
    && wizardSchedules.data.some((s) => s.schedulePlanId === periodPlanId);
  const periodMaybeEmpty = periodMode && !periodHasKnownVersion;
  const deleteEntry = useDeleteEntry();
  const queryClient = useQueryClient();
  const [quitAsked, setQuitAsked] = useState(false);
  // Latest-value : le prédicat est enregistré en useEffect par react-router — au
  // moment d'une navigation il closure sur le render PRÉCÉDENT. Les refs portent
  // l'état courant (armé ? en train de partir ?) sans dépendre du cycle de render :
  // sans elles, le navigate() post-confirmation est re-bloqué et le dialogue
  // se ré-ouvre après coup (revue #260 round 1).
  const guardArmedRef = useRef(false);
  useEffect(() => {
    // Post-commit, comme l'enregistrement du prédicat par react-router : la ref est
    // à jour avant toute navigation utilisateur.
    guardArmedRef.current = periodMaybeEmpty;
  });
  const abandoningRef = useRef(false);
  const blocker = useBlocker(({ nextLocation }) => guardArmedRef.current && !leavingRef.current && "/wizard" !== nextLocation.pathname);
  const abandonOpen = quitAsked || "blocked" === blocker.state;

  const finishQuit = () => {
    leavingRef.current = true;
    exitPeriodMode();
    navigate("/");
  };
  const quitPeriod = () => {
    if (periodMaybeEmpty) {
      setQuitAsked(true);
      return;
    }
    finishQuit();
  };
  const confirmAbandon = async () => {
    if (abandoningRef.current) {
      return;
    }
    abandoningRef.current = true;
    const entryId = calendarEntryId;
    const planId = periodPlanId;
    // Re-vérification FRAÎCHE avant le geste destructif : le cache ["schedules"]
    // peut être en retard d'une génération lancée à l'instant (l'invalidation ne
    // part qu'au onSuccess du launch) — supprimer sur cette foi détruirait la
    // version en vol via la cascade serveur. Fetch muet → on ne supprime PAS.
    // Trois issues, décidées sur la lecture FRAÎCHE : vide prouvé → suppression ;
    // version trouvée → conservation annoncée ; indécidable (fetch muet, plan
    // irrésolu) → conservation SANS affirmer qu'une génération existe.
    let verdict: "empty" | "has-version" | "unknown" = "unknown";
    try {
      const fresh = await queryClient.fetchQuery({ queryKey: ["schedules"], queryFn: listSchedules, staleTime: 0 });
      if (null !== planId) {
        verdict = fresh.some((s) => s.schedulePlanId === planId) ? "has-version" : "empty";
      }
    } catch {
      // Fetch muet → verdict reste "unknown" : on ne supprime jamais sur donnée inconnue.
    }
    // Sortir du mode période AVANT le delete : ça désactive useCalendarEntry
    // (sinon son 404 post-suppression déclenche le toast « n'existe plus »).
    leavingRef.current = true;
    exitPeriodMode();
    setQuitAsked(false);
    if ("empty" === verdict && null !== entryId) {
      // .then/.catch sur la promesse (pas un callback mutate()) : ils survivent au
      // démontage du wizard — le toast de succès arrive, et un échec est toasté
      // par le filet global MutationCache.onError (useDeleteEntry n'a pas d'onError).
      deleteEntry
        .mutateAsync(entryId)
        .then(() => toast.success("Période retirée du calendrier"))
        .catch(() => { /* toasté par le filet global (queryClient.ts) */ });
    } else if ("has-version" === verdict) {
      toast.success("Une génération existe pour cette période — elle est conservée.");
    } else {
      toast.info("Période conservée — son contenu n'a pas pu être vérifié.");
    }
    if ("blocked" === blocker.state) {
      blocker.proceed();
    } else {
      navigate("/");
    }
    abandoningRef.current = false;
  };
  const keepPeriod = () => {
    setQuitAsked(false);
    if ("blocked" === blocker.state) {
      blocker.reset();
    }
  };

  return (
    <WizardFooterContext.Provider value={footerCtx}>
      {/* P2-25 — retour NOMMÉ, affiché SEULEMENT si on est arrivé par un lien (deepLinkOrigin
          posé par la porte d'entrée). Il nomme l'origine et s'efface dès qu'on repart/agit.
          Une flèche nue serait ambiguë avec le retour navigateur : le libellé dit où l'on va. */}
      {null !== deepLinkOrigin ? (
        <div className="mb-3">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => {
              const to = deepLinkOrigin.returnTo;
              clearDeepLinkOrigin();
              landedStepRef.current = null;
              navigate(to);
            }}
          >
            <ArrowLeft className="size-4" />
            {deepLinkOrigin.label}
          </Button>
        </div>
      ) : null}
      {periodMode ? (
        // P4-38 — DEUX lignes plutôt qu'une. La forme d'origine accolait les dates au titre
        // et alignait quatre actions à droite : sur un titre long (« Vacances d'Été —
        // semaine du 17 août »), la ligne débordait et les dates passaient sous les boutons.
        // Ligne 1 = QUOI (le titre) + les gestes qui SORTENT du mode ; ligne 2 = QUAND (les
        // dates) + le geste qui y RESTE (Doléances).
        //
        // ⚠ Le titre porte DÉJÀ le repère de semaine : `cockpit/queries.ts:349` nomme une
        // semaine enfant « {mère} — semaine du {lundi} ». Rien à ajouter ici, sous peine de
        // l'écrire deux fois.
        <div className="mb-4 flex flex-col gap-1 rounded-lg border border-accent/40 bg-accent/10 px-4 py-2 text-sm">
          <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="flex items-center gap-2">
            <CalendarClock className="size-4 text-accent" />
            <span className="font-medium">Mode période — {periodEntry?.title ?? "…"}</span>
          </span>
          <span className="flex items-center gap-1">
            {/* Supprimer ce planning secondaire (cascade plan + versions) → retour cockpit.
                On ARME leavingRef comme finishQuit, sinon le useBlocker d'abandon
                intercepte le navigate et ré-ouvre « Quitter l'ajustement ? » sur une entrée déjà
                supprimée (revue B2 F1). Masqué sur l'étape GÉNÉRATION : une génération
                lancée à l'instant n'est pas encore dans le cache — supprimer là
                détruirait la version en vol (revue B2 F4 ; l'abandon relit le serveur,
                pas ce bouton). */}
            {null !== calendarEntryId && "generate" !== stepId ? (
              <DeletePlanningButton calendarEntryId={calendarEntryId} title={periodEntry?.title ?? "ce planning"} onDeleted={() => { leavingRef.current = true; exitPeriodMode(); navigate("/"); }} />
            ) : null}
            {/* « Quitter » se lisait comme « abandonner ma saisie » alors que le geste
                ramène au cockpit — le nom dit maintenant où l'on va. */}
            <Button variant="ghost" size="sm" onClick={quitPeriod}>
              <X className="size-4" />
              Retour à l'accueil
            </Button>
          </span>
          </div>

          {/* Ligne 2 seulement si elle a quelque chose à dire : sans dates chargées ni accès
              aux doléances, elle laisserait une bande vide sous le titre. */}
          {periodEntry || canWishes ? (
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="text-muted-foreground">
                {periodEntry ? `du ${frDateNumeric(periodEntry.startDate)} au ${frDateNumeric(periodEntry.endDate)}` : ""}
              </span>
              {canWishes ? (
                <Button variant="ghost" size="sm" onClick={() => setWishesOpen(true)}>
                  <MessageSquare className="size-4" />
                  Doléances
                </Button>
              ) : null}
            </div>
          ) : null}
        </div>
      ) : null}
      {/* Texte CONDITIONNEL : la vérité se lit au serveur À LA CONFIRMATION (une
          génération peut aboutir pendant que le dialogue est ouvert) — affirmer
          « aucun planning n'a été généré » ici pourrait contredire l'action. */}
      <ConfirmDialog
        open={abandonOpen}
        // « Abandonner » contredisait le bouton renommé « Retour à l'accueil » : le geste
        // paraît anodin, le dialogue accusait un abandon. Le titre dit ce qu'on QUITTE (il
        // s'ouvre aussi depuis le blocker de navigation, pas seulement depuis ce bouton),
        // la description dit ce qu'il advient de la période — inchangée (revue #350).
        title="Quitter l'ajustement ?"
        description="Si aucun planning n'a été généré pour cette période, elle sera retirée du calendrier (recréable via « Adapter »). Si une génération existe, la période sera conservée."
        confirmLabel="Retirer la période"
        cancelLabel="Rester sur l'ajustement"
        onConfirm={() => void confirmAbandon()}
        onCancel={keepPeriod}
      />
      <div className="flex flex-col gap-6 md:flex-row">
      {/* Left step navigation — collapsible (W8/N4) so any step (incl. génération) can go full-width.
          Le RAIL est la primitive partagée `step-rail` (RMM-2) : présentation pure. La logique de
          verrouillage (guidé/génération bloquée — P4-58 b) et les coches « étape terminée » restent
          calculées ICI (mêmes verdicts que les portes) et lui sont PASSÉES. Le voile de navigation
          (lot C) reste dans le onSelect : le composant ne connaît pas le navTransitionStore. */}
      {navCollapsed ? null : (
        <StepRail
          steps={WIZARD_STEPS.map((step, i) => ({
            ...step,
            done: true === stepDone[step.id],
            locked: (guided && i > maxIndex) || ("generate" === step.id && generateBlocked),
          }))}
          currentId={stepId}
          onSelect={(id) => {
            // GESTE de navigation → arme le voile « changement de page » (lot C).
            armNavTransition();
            setStep(id);
          }}
        />
      )}

      {/* Current step — fills the viewport height so the sticky footer sits at
          the real bottom (no floating gap on short steps) yet stays pinned on scroll. */}
      <div className="flex min-h-[calc(100vh-5.5rem)] min-w-0 flex-1 flex-col">
        {/* Sticky step title + collapse toggle (W7 title, W8/N4 collapse) */}
        <div className="sticky top-0 z-20 mb-4 flex items-center justify-between gap-2 border-b border-border bg-background py-3">
          <h2 className="text-lg font-semibold">
            <span className="text-muted-foreground">
              Étape {index + 1}/{WIZARD_STEPS.length} ·{" "}
            </span>
            {currentStep?.label}
          </h2>
          <div className="flex items-center gap-2">
            {/* P5-6 — porte contextuelle : l'étape courante voyage dans le contexte. */}
            <FeedbackButton screen={`wizard/${stepId}`} />
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setNavCollapsed((c) => !c)}
              aria-label={navCollapsed ? "Afficher les étapes" : "Masquer les étapes"}
            >
              {navCollapsed ? <PanelLeftOpen className="size-4" /> : <PanelLeftClose className="size-4" />}
              {navCollapsed ? "Étapes" : "Plein écran"}
            </Button>
          </div>
        </div>

        <StepContent stepId={stepId} />

        {/* Récap renders its own grouped blocker panel, so skip the generic alerts there. */}
        {"recap" === stepId
          ? null
          : validation.errors.map((error) => (
              <p key={error} role="alert" className="mt-3 flex items-center gap-2 text-sm text-destructive">
                <AlertTriangle className="size-4 shrink-0" />
                {error}
              </p>
            ))}
        {validation.warnings.map((warning) => (
          <p key={warning} className="mt-3 flex items-center gap-2 text-sm text-warning">
            <AlertTriangle className="size-4 shrink-0" />
            {warning}
          </p>
        ))}

        {/* Prev/Next footer (W7). Sticky on the data-entry steps; NOT sticky on
            Génération — there the embedded planning stack is taller than the
            viewport on short screens and a pinned bar would overlay the grid,
            so the footer sits in the flow below it instead. On Récap "Suivant"
            becomes the gated "Continuer vers la génération". A step can inject
            an action (footerExtra), e.g. "Trier" on the Teams step. */}
        <div
          className={cn(
            "z-20 mt-auto flex items-center justify-between gap-2 border-t border-border bg-background pt-4 pb-4",
            "generate" === stepId ? "" : "sticky bottom-0",
          )}
        >
          <Button
            variant="outline"
            disabled={0 === index}
            onClick={() => {
              armNavTransition(); // GESTE → arme le voile
              prev();
            }}
          >
            Précédent
          </Button>
          <div className="flex items-center gap-2">
            {footerExtra}
            {isLast ? null : (
              <Button
                disabled={blocked}
                onClick={() => {
                  armNavTransition(); // GESTE → arme le voile
                  next();
                }}
              >
                {"recap" === stepId ? "Continuer vers la génération" : "Suivant"}
              </Button>
            )}
          </div>
        </div>
      </div>
      </div>
      <ScrollJumpButtons suppressed={suppressScrollJump} />
      {wishesOpen && null !== wishesMother ? (
        <CoachWishesModal mother={wishesMother} weekFilter={wishesFilter} onClose={() => setWishesOpen(false)} />
      ) : null}
    </WizardFooterContext.Provider>
  );
}
