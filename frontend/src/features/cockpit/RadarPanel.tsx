import { AlertTriangle, CalendarClock, CalendarOff, ChevronDown, MapPin, MessageSquare, OctagonX, PartyPopper, Pencil } from "lucide-react";
import { Link, useNavigate } from "react-router";

import { useWorkingSeason } from "@/shared/session/queries";
import { useUnavailabilityImpact, useVenues, useVenueUnavailabilities } from "@/features/matches/queries";
import { useSchedules, useSlots } from "@/features/planning/queries";
import { usePlanningStore } from "@/features/planning/store";
import { useWizardStore } from "@/features/wizard/store";
import { Button } from "@/shared/components/ui/button";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { cn } from "@/shared/lib/utils";

import type { CalendarEntry, CalendarEntryPeriodType, PublicHoliday, SchoolHoliday } from "./api";
import { useCreateVenueClosure, useEntryConflicts, useEntryConflictsList, useSchedulePlans } from "./queries";
import { clampRangeToSeason, daysUntil, frDateShort, groupCoverageSlots, isActionableWeek, todayISO, weeksCovering, type WeekWindow } from "./lib/date";
import { seasonLockTitle, useSocleValidated } from "./lib/socle";
import { unavailabilitiesToAlert } from "./lib/venueUnavailabilityRadar";
import { useWeekAdapt } from "./lib/useWeekAdapt";
import { WeekPickerDialog } from "./WeekPickerDialog";
import { WindowAlreadyPlannedNotice } from "./WindowAlreadyPlannedNotice";
import { CoachWishesModal } from "@/features/coach-wishes/CoachWishesModal";
import { RadarCoachWishAction } from "@/features/coach-wishes/RadarCoachWishAction";
import { useCoachWishCampaigns } from "@/features/coach-wishes/campaignQueries";
import { useState } from "react";
import { isoDayOf } from "@/shared/lib/days";

/** Public holidays further out than this are noise, not a to-do. */
export const PUBLIC_HOLIDAY_HORIZON_DAYS = 30;
/**
 * P3-13 — au-delà, une vacance scolaire est « TROP loin pour que je m'en occupe de suite »
 * (fondateur 2026-08-01) : en été, Toussaint et Noël s'affichaient. La valeur est celle du
 * fondateur — ramenée à 30 j le 2026-08-19 (elle valait 60) : une vacance n'apparaît au radar
 * que 30 jours avant son début.
 *
 * ⚠ Ce que l'horizon masque, il le masque AUSSI pour les doléances — `RadarCoachWishAction`
 * n'est rendu nulle part ailleurs dans l'application. Arbitrage fondateur 2026-08-01, après
 * que la revue #344 l'a soulevé : « en général ça se fait 3 semaines avant les vacances » —
 * la sollicitation tient donc largement dans les 30 j. Le cas d'une collecte plus lointaine
 * n'existe pas dans l'usage réel, et aucun second point d'entrée n'est à créer.
 *
 * Deux échappatoires subsistent, chacune pour ne jamais faire disparaître un travail
 * ENGAGÉ : une période qui porte un plan est rendue en carte « en cours »
 * (`inProgressEntries`), et une vacance qui porte une CAMPAGNE garde sa carte — son badge
 * « x à traiter » n'existe nulle part ailleurs. En pratique une campagne vit dans les 30 j,
 * donc c'est un filet, pas un chemin.
 */
export const SCHOOL_HOLIDAY_HORIZON_DAYS = 30;
/**
 * P4-68 — au-delà, une fermeture de gymnase n'est pas encore un to-do (même
 * doctrine que les fériés). Une indispo DÉJÀ commencée reste affichée : son
 * `daysUntil` est négatif, donc sous l'horizon, et elle demande toujours un geste.
 */
export const VENUE_UNAVAILABILITY_HORIZON_DAYS = 30;

/** Une semaine d'une période mère découpée : son enfant (ou null si à créer/manquante) et, pour
 *  une fermeture chevauchant des vacances, les libellés des vacances qui la gouvernent (A3). */
interface MotherWeekSlot {
  week: WeekWindow;
  child: CalendarEntry | null;
  governedBy: string[] | null;
}

interface RadarPanelProps {
  entries: CalendarEntry[];
  holidays: SchoolHoliday[];
  publicHolidays: PublicHoliday[];
  /** Public-holidays query still in flight — don't flash the all-clear meanwhile. */
  publicHolidaysLoading?: boolean;
  zone: string | null;
  /** Holidays query still in flight — don't flash "zone à renseigner" meanwhile. */
  zoneLoading?: boolean;
}

/** The manager's to-do, sorted by urgency. "Adapter" opens the wizard in period mode (palier B). */
export function RadarPanel({ entries, holidays, publicHolidays, publicHolidaysLoading = false, zone, zoneLoading = false }: RadarPanelProps) {
  const today = todayISO();
  const navigate = useNavigate();
  const startPeriodMode = useWizardStore((s) => s.startPeriodMode);
  const setSelectedScheduleId = usePlanningStore((s) => s.setSelectedScheduleId);
  // Gating (#5) : sans plan de SAISON validé (chosenScheduleId), aucun planning
  // secondaire — les boutons d'ajustement sont désactivés, un encart rouge invite
  // à finir la validation.
  const socleValidated = useSocleValidated();
  const lockTitle = seasonLockTitle(socleValidated);
  // Une période vit DANS sa saison : les dates de vacances sont clampées à la
  // fenêtre de saison avant création (l'été chevauche la frontière). Saison
  // inconnue (me en vol) → pas de création possible, fail-closed. Cache par
  // vacance (le clamp est lu au filtre + au disabled + au clic).
  const workingSeason = useWorkingSeason();
  const clampCache = new Map<string, { startDate: string; endDate: string } | null>();
  const seasonClamp = (h: SchoolHoliday): { startDate: string; endDate: string } | null => {
    if (!clampCache.has(h.id)) {
      clampCache.set(h.id, null === workingSeason ? null : clampRangeToSeason(h.startDate, h.endDate, workingSeason));
    }
    return clampCache.get(h.id) ?? null;
  };

  const adapt = (entryId: string) => {
    // Ceinture (bug fondateur 2026-08-19) : purge une sélection planning d'un autre
    // écran avant d'entrer en mode période — sinon elle survivait jusqu'à l'écran
    // embarqué. La vraie correction est la portée passée à PlanningPage (A) ; ceci
    // ferme la porte au cas où.
    setSelectedScheduleId(null);
    startPeriodMode(entryId);
    navigate("/wizard");
  };
  const viewOverlay = (overlayScheduleId: string) => {
    setSelectedScheduleId(overlayScheduleId);
    navigate("/planning");
  };

  // P2-5 E1 : flux de découpage partagé (radar + DayDialog) — voir requestAdapt.
  // Chemin `pending` : la mère vacances naît SEULEMENT à la confirmation du picker.
  const { pickerFor, setPickerFor, pendingMother, setPendingMother, openPendingPicker, needsPicker, createWeekChildren, createHoliday, adaptBlock, pickWeeks, pickWeeksPending, adaptWholePending, recordPendingOnly, createOneWeek, windowConflict, resetWindowConflict, requestAdapt: requestWeekAdapt, offerFor, pickerState, pickerOffer, pendingOffer, pendingPickerState, blockInfo, blockDeleting, blockDeleteFailed, deleteBlockVersionsAndSplit } = useWeekAdapt(adapt);
  // #10 — la todo-list des doléances d'une période de vacances (ouverte sur la MÈRE).
  const [wishesEntry, setWishesEntry] = useState<CalendarEntry | null>(null);
  // #10 C2 — les campagnes de collecte, indexées par période (une requête pour tout le
  // radar : bouton « Solliciter les coachs » + badge de suivi par carte vacances).
  const campaignsQuery = useCoachWishCampaigns();
  const campaignByEntry = new Map((campaignsQuery.data ?? []).map((c) => [c.calendarEntryId, c]));

  // ADR-0002 lot D-b : la « version active » d'une période = chosenScheduleId de son
  // plan (binaire — plan validé → on montre, non validé → on ajuste). Un seul appel,
  // mappé par entrée, plutôt qu'un hook par carte (règles des hooks dans la liste).
  // Fail-closed sur l'absence de DONNÉE : sans les plans, l'état d'une période est INCONNU —
  // on ne décide ni « à traiter » ni « tout roule », et on n'affiche aucun CTA qui pousserait à
  // régénérer un plan déjà validé (même philosophie que closureImpactsPending). Clé sur `data`,
  // PAS sur isSuccess : TanStack bascule en error sur un refetch d'arrière-plan tout en gardant
  // la donnée périmée — s'y fier ferait DISPARAÎTRE tout le radar sur un simple blip, alors qu'on
  // a des plans valides à afficher. Un 1er chargement en échec (aucune donnée) reste fail-closed.
  const plansQuery = useSchedulePlans();
  const plans = plansQuery.data;
  const plansUnresolved = undefined === plans;
  const activeByEntry = new Map<string, string>();
  for (const p of plans ?? []) {
    if (null !== p.calendarEntryId && null !== p.chosenScheduleId) {
      activeByEntry.set(p.calendarEntryId, p.chosenScheduleId);
    }
  }

  // The radar is a TO-DO list: entries the manager explicitly dismissed
  // (status=ignored) must not resurface (the calendar still shows them).
  const active = entries.filter((e) => e.status !== "ignored");

  // P2-5 E1 : une période DÉCOUPÉE = une mère + ses semaines enfants
  // (parentEntryId). Les cartes classiques ne montrent que les RACINES ; une mère
  // découpée porte une carte de COUVERTURE (chips par semaine) à la place.
  const childrenByParent = new Map<string, CalendarEntry[]>();
  for (const e of entries) {
    if (null !== e.parentEntryId) {
      childrenByParent.set(e.parentEntryId, [...(childrenByParent.get(e.parentEntryId) ?? []), e]);
    }
  }
  const roots = active.filter((e) => null === e.parentEntryId);

  // « Planning en cours » (retour fondateur 2026-07-18) : deux niveaux, à ne pas
  // confondre.
  // - `startedEntryIds` : plan AVEC versions, pas encore validé = travail COMMENCÉ.
  //   Sert au libellé des chips (« en cours » vs « à faire ») et à l'état d'une
  //   fermeture — le distinguer d'un plan à 0 version est le sens de ces libellés.
  // - `pendingEntryIds` : plan existant sans version validée, versions OU NON
  //   (retour fondateur 2026-07-19) — sert à la carte générique « en cours » : une
  //   vacance ajustée « d'un bloc » mais PAS encore générée doit rester visible pour
  //   être reprise. Ces cartes échappent au cap des vacances et au filtre « à venir ».
  const schedulesQuery = useSchedules();
  const schedulesUnresolved = undefined === schedulesQuery.data;
  const plansWithVersions = new Set((schedulesQuery.data ?? []).map((s) => s.schedulePlanId));
  const startedEntryIds = new Set(
    (plans ?? [])
      .filter((p) => null !== p.calendarEntryId && null === p.chosenScheduleId && plansWithVersions.has(p.id))
      .map((p) => p.calendarEntryId as string),
  );
  const pendingEntryIds = new Set(
    (plans ?? [])
      .filter((p) => null !== p.calendarEntryId && null === p.chosenScheduleId)
      .map((p) => p.calendarEntryId as string),
  );
  // Cartes génériques « en cours » : les périodes SANS carte riche (vacances & co).
  // Les fermetures gardent leur ClosureRadarItem (détail des séances touchées) —
  // le remplacer par une carte générique ferait disparaître l'avertissement
  // d'impact tant que le plan n'est pas validé (revue #260 round 1).
  const inProgressEntries = roots.filter((e) => pendingEntryIds.has(e.id) && "closure" !== e.periodType && !childrenByParent.has(e.id) && e.endDate >= today);

  // Mères découpées à COUVRIR : une semaine existante non validée OU une semaine
  // MANQUANTE (décochée au picker, échec partiel — revue #262 : sans chip « à
  // créer », une semaine décochée devenait à jamais implanifiable). Visible tant
  // que la DERNIÈRE fenêtre (mère ou enfants — une semaine pleine déborde la
  // mère) n'est pas passée ; tout couvert → la carte s'efface (to-do).
  const motherWeekSlots = (m: CalendarEntry): MotherWeekSlot[] => {
    // P3-13 (b) — les semaines RÉVOLUES ne comptent ni ne s'offrent : « 0/7 couvertes »
    // quand 3 étaient derrière décrivait un travail impossible. ⚠ RÉVOLUE, pas
    // « commencée » (revue #344) : une semaine dont il reste des jours porte encore du
    // travail — une fermeture du mercredi serait devenue implanifiable dès le lundi.
    // Filtre appliqué en SORTIE, donc aux deux branches ci-dessous.
    const stillOpen = (slots: MotherWeekSlot[]) => slots.filter(({ week }) => isActionableWeek(week, today));
    const children = childrenByParent.get(m.id) ?? [];
    if (null === workingSeason) {
      // Saison inconnue : pas de calcul des manquantes — les enfants existants font foi.
      return stillOpen(children.map((c) => ({ week: { startDate: c.startDate, endDate: c.endDate, monday: c.startDate }, child: c, governedBy: null })));
    }
    // A3 (P2-40) — l'OFFRE vient du foyer UNIQUE (`offerFor`) : pour une fermeture il écarte les
    // semaines gouvernées par des vacances (elles ne sont pas ajustables par elle), pour tout
    // autre type c'est l'offre historique. Aucune re-dérivation ici : on LIT sa sortie.
    const offer = offerFor(m.startDate, m.endDate, m.periodType);
    const offeredMondays = new Set(offer.offered.map((w) => w.monday));
    // Les libellés d'une semaine GOUVERNÉE par des vacances, lus des blocs exclus de l'offre.
    const governingLabels = (week: WeekWindow): string[] | null => {
      const range = offer.excludedRanges.find((r) => r.startDate <= week.endDate && r.endDate >= week.startDate);
      return range?.labels ?? null;
    };
    // Filet #262 + revue C F1 : on itère TOUTES les semaines calendaires et on garde une semaine
    // si elle porte un enfant EXISTANT (toujours visible/gérable), si elle est OFFERTE à la
    // création (periodAdjustWeeks écarte la semaine partielle d'une vacance démarrant Ven/Sam/Dim),
    // OU si elle est GOUVERNÉE par des vacances (grisée, informative). Une semaine partielle SANS
    // rien de tout ça disparaît (pas de chip « + créer »).
    return stillOpen(
      weeksCovering(m.startDate, m.endDate, workingSeason)
        .map((week) => {
          const child = children.find((c) => c.startDate <= week.endDate && c.endDate >= week.startDate) ?? null;
          // Un enfant l'emporte : une semaine portée par un plan est ajustable, jamais grisée.
          const governedBy = null !== child ? null : governingLabels(week);
          return { week, child, governedBy };
        })
        .filter(({ week, child, governedBy }) => null !== child || offeredMondays.has(week.monday) || null !== governedBy),
    );
  };
  // La carte de couverture vit tant qu'il RESTE une semaine à venir non couverte. Le
  // garde-fou `lastEnd < today` d'avant est devenu redondant : une mère entièrement
  // passée n'a plus aucun slot, donc plus aucun `some` vrai.
  // A3 — une semaine GOUVERNÉE par des vacances n'est pas un travail restant (le rappel vit dans
  // le planning des vacances) : seule une semaine AJUSTABLE non couverte garde la carte à l'écran.
  const splitMothers = roots.filter((e) => childrenByParent.has(e.id) && motherWeekSlots(e).some(({ child, governedBy }) => null === governedBy && (null === child || !activeByEntry.has(child.id))));

  // Semaines ORPHELINES D'AFFICHAGE : une semaine dont la mère ne porte AUCUNE
  // carte de couverture — mère sortie de la fenêtre radar (finie), OU écartée
  // (ignorée) : dans les deux cas, sans surface, la semaine encore courante et
  // non validée serait implanifiable (revue #262 rounds 2-3). Carte dédiée.
  const renderedMotherIds = new Set(splitMothers.map((m) => m.id));
  // Même règle que la couverture (P3-13 b) : une semaine orpheline dont il reste des
  // jours. ⚠ Ce filet existe pour « la semaine encore COURANTE et non validée » (revue
  // #262) — le restreindre aux semaines non commencées l'aurait vidé de sa raison d'être,
  // et le radar aurait affirmé « Tout roule » sur un gymnase fermé cette semaine-là.
  const orphanWeekChildren = active.filter(
    (e) => null !== e.parentEntryId && !renderedMotherIds.has(e.parentEntryId) && e.endDate >= today && !activeByEntry.has(e.id),
  );

  // P2-36 — la décision « semaines / bloc / chargement » vit dans useWeekAdapt (maison unique) :
  // le radar ne passe plus que l'entrée + son savoir « déjà découpée » (childrenByParent). Fini
  // la bascule silencieuse en bloc quand la condition tombait — le picker s'ouvre et NOMME l'état.
  const requestAdapt = (entry: CalendarEntry) => requestWeekAdapt(entry, { alreadySplit: childrenByParent.has(entry.id) });

  // A holiday already materialised as a period entry (matched by schoolHolidayId).
  // Ignored ones stay in the map so a dismissed holiday is skipped below, not re-proposed.
  const entryByHoliday = new Map(entries.filter((e) => null !== e.schoolHolidayId).map((e) => [e.schoolHolidayId as string, e]));

  const holidaysInScope = holidays
    // ⚠ `endDate`, pas `startDate` (revue #344 round 2) : une vacance DÉJÀ COMMENCÉE dont
    // les jours restent devant doit garder sa carte — c'est la même règle qu'au niveau
    // semaine (`isActionableWeek`), et la laisser diverger ici faisait disparaître, dès le
    // samedi de son début, le seul point d'entrée vers « Adapter » et « Solliciter ».
    .filter((h) => h.endDate >= today)
    // P3-13 (a) : horizon, comme les fériés en ont un depuis toujours. Sans lui, `.slice(0, 3)`
    // laissait passer Noël en plein été — trois vacances, mais pas les trois PROCHAINES au
    // sens où le gestionnaire peut agir dessus.
    // ⚠ EXEMPTION (revue #344) : une vacance qui porte déjà une CAMPAGNE de doléances
    // reste affichée quelle que soit sa distance. Cette carte est le seul endroit de
    // l'application qui rende le badge de suivi (« 5 à traiter ») et le bouton
    // « Solliciter les coachs » — l'horizon effaçait donc, avec le bruit, la seule surface
    // capable de dire qu'il y a des souhaits en attente de réponse.
    .filter((h) => {
      const e = entryByHoliday.get(h.id);
      return (undefined !== e && campaignByEntry.has(e.id)) || daysUntil(today, h.startDate) <= SCHOOL_HOLIDAY_HORIZON_DAYS;
    })
    .filter((h) => entryByHoliday.get(h.id)?.status !== "ignored")
    // Entièrement hors de la fenêtre de saison → rien à bâtir, pas de carte.
    // (Saison inconnue = on garde la carte ; le bouton Adapter, lui, est gardé.)
    .filter((h) => null === workingSeason || null !== seasonClamp(h))
    // Déjà affichée en carte « en cours » ou en carte de COUVERTURE — pas de doublon.
    .filter((h) => {
      const e = entryByHoliday.get(h.id);
      return undefined === e || (!pendingEntryIds.has(e.id) && !childrenByParent.has(e.id));
    })
    .sort((a, b) => a.startDate.localeCompare(b.startDate));

  // Le cap borne le BRUIT, pas les cartes qu'on a décidé de garder : trié par date, une
  // vacance lointaine exemptée est toujours la dernière, donc la première coupée — le cap
  // annulait silencieusement l'exemption qu'il côtoie (revue #344 round 2).
  const exemptFromCap = (h: SchoolHoliday): boolean => {
    const e = entryByHoliday.get(h.id);

    return undefined !== e && campaignByEntry.has(e.id);
  };
  const upcomingHolidays = [...holidaysInScope.filter((h) => !exemptFromCap(h)).slice(0, 3), ...holidaysInScope.filter(exemptFromCap)].sort((a, b) =>
    a.startDate.localeCompare(b.startDate),
  );

  const disruptiveEvents = roots
    .filter((e) => e.kind === "event" && e.isDisruptive && e.endDate >= today)
    .sort((a, b) => a.startDate.localeCompare(b.startDate));

  const upcomingPeriods = (periodType: CalendarEntryPeriodType): CalendarEntry[] =>
    roots.filter((e) => e.kind === "period" && e.periodType === periodType && e.endDate >= today).sort((a, b) => a.startDate.localeCompare(b.startDate));

  const closures = upcomingPeriods("closure");
  // Le radar montre ce qui CHANGE par rapport au quotidien, pas un inventaire : une
  // fermeture qui ne heurte aucune séance, sur un planning validé, ne demande rien —
  // elle n'a rien à faire dans une liste « à traiter ». On ne peut le savoir qu'en
  // lisant l'impact, que le serveur seul calcule ; d'où la lecture groupée ici, dont
  // le résultat sert aussi à garder `isEmpty` honnête (une carte qui s'efface toute
  // seule laisserait le panneau vide SANS son « Rien à l'horizon »).
  const closureImpacts = useEntryConflictsList(closures.map((e) => e.id));
  const visibleClosures = closures.filter((entry, i) => {
    if (childrenByParent.has(entry.id)) {
      return false; // mère découpée : sa carte de COUVERTURE prend le relais
    }
    if (activeByEntry.has(entry.id)) {
      return true; // un planning secondaire VALIDÉ existe : cette semaine EST différente
    }
    if (startedEntryIds.has(entry.id)) {
      return true; // travail commencé, non validé : toujours à traiter (jamais masqué)
    }
    const impact = closureImpacts[i];
    if (impact?.isPending) {
      return false; // on ne sait pas ENCORE : ne pas faire clignoter une carte qui va disparaître
    }
    if (undefined === impact?.data) {
      return true; // la requête a échoué : on ne sait pas, et ne pas savoir se traite
    }
    if (false === impact.data.seasonPlanChosen) {
      return true; // plan incomplet : impact non évalué
    }
    return impact.data.conflicts.some((c) => c.dates.length > 0);
  });
  // Masquer une fermeture parce qu'on ne SAIT PAS encore, tout en annonçant « Tout
  // roule », c'est le silence qui ment que `seasonPlanChosen` sert à tuer — déplacé
  // du libellé vers le filtre de visibilité, où le drapeau n'est même plus lu. Tant
  // qu'un impact est en vol, le panneau n'est pas « vide », il est incomplet.
  const closureImpactsPending = closureImpacts.some((q) => q.isPending);
  // Impact des mères CLOSURE découpées : la carte de couverture remplace leur
  // ClosureRadarItem — elle doit garder le CHIFFRE des séances touchées (revue
  // #260 l'avait exigé ; #262 round 2 l'a vu disparaître au découpage).
  const splitClosureMothers = splitMothers.filter((m) => "closure" === m.periodType);
  const splitClosureImpacts = useEntryConflictsList(splitClosureMothers.map((m) => m.id));
  const splitImpactCountByEntry = new Map<string, number>(
    splitClosureMothers.map((m, i) => [m.id, (splitClosureImpacts[i]?.data?.conflicts ?? []).reduce((sum, c) => sum + c.dates.length, 0)]),
  );
  // Disruption reminders, no CTA: a cutoff means "no training", there is no plan to prepare.
  const cutoffs = upcomingPeriods("cutoff");

  // P4-9 (décision fondateur 2026-08-04) — un férié ne mérite une carte que s'il
  // TOMBE un jour où le planning EN VIGUEUR a des séances, et la carte dit
  // l'IMPACT (« 2 séances ce jour-là ») au lieu de répéter la date. Sans socle
  // pointé : aucune séance à protéger → aucune carte férié.
  const seasonChosenId = (plansQuery.data ?? []).find((p) => "SEASON" === p.type)?.chosenScheduleId ?? null;
  const holidaysInHorizon = publicHolidays
    .filter((h) => h.date >= today && daysUntil(today, h.date) <= PUBLIC_HOLIDAY_HORIZON_DAYS)
    .sort((a, b) => a.date.localeCompare(b.date));
  // Lecture des séances du socle : seulement s'il y a des fériés à qualifier.
  const seasonSlotsQuery = useSlots(holidaysInHorizon.length > 0 ? seasonChosenId : null);
  const slotsPerIsoDay = new Map<number, number>();
  for (const slot of seasonSlotsQuery.data ?? []) {
    slotsPerIsoDay.set(slot.dayOfWeek, (slotsPerIsoDay.get(slot.dayOfWeek) ?? 0) + 1);
  }
  // D-30 : le jour ISO vient de `shared/lib/days` (midi UTC — hors de portée des fuseaux).
  // P4-68 (recadrage fondateur 2026-08-06) — l'indispo de gymnase entre au RADAR.
  // Elle vivait dans une carte statique plus bas : le gestionnaire n'était donc pas
  // alerté au moment d'agir, alors que tout le modèle repose sur lui (« il crée
  // l'overlay quand il le faut »). Le geste offert est celui qui EXISTE déjà au
  // DayDialog — créer la fermeture (période + contrainte datée « gymnase fermé »)
  // — enchaîné sur l'adaptation : le plan naît du geste d'Adapter (ADR-0002).
  const unavailabilitiesQuery = useVenueUnavailabilities();
  const venuesQuery = useVenues();
  const unavailabilityImpact = useUnavailabilityImpact();
  const createClosureFromUnavailability = useCreateVenueClosure();
  const venueNameOf = (id: string): string => (venuesQuery.data ?? []).find((v) => v.id === id)?.name ?? "Gymnase";
  const impactByUnavailability = new Map((unavailabilityImpact.data?.items ?? []).map((i) => [i.unavailabilityId, i]));
  const unavailabilityAlerts = unavailabilitiesToAlert(unavailabilitiesQuery.data ?? [], entries, today, VENUE_UNAVAILABILITY_HORIZON_DAYS);

  const upcomingPublicHolidays = holidaysInHorizon
    .map((h) => ({ ...h, sessionCount: slotsPerIsoDay.get(isoDayOf(h.date)) ?? 0 }))
    .filter((h) => h.sessionCount > 0);

  // P3-11 — le panneau reste NU tant que ces lectures sont en vol : ni carte, ni « Rien à
  // l'horizon » (le masquage est voulu — ne pas faire clignoter une carte qui va
  // disparaître), donc un cadre « À traiter » vide pendant quelques centaines de ms, qui
  // se lit comme « rien à faire ». Un squelette dit la seule chose vraie : on ne sait pas
  // encore.
  //
  // ⚠ CHARGER ≠ ÉCHOUER (revue #344 round 2, et c'est la doctrine `shared/lib/readState`) :
  // `plansUnresolved` veut dire « pas de donnée », donc il reste VRAI pour toujours après
  // un premier chargement en échec. Bâtir le squelette dessus transformait une erreur de
  // lecture en « Chargement… » perpétuel — l'écran affirmait qu'il travaillait alors qu'il
  // avait renoncé. On distingue donc les deux, et l'échec se DIT.
  // P4-9 : tant que les séances du socle sont en vol, l'impact d'un férié est
  // inconnu — squelette, jamais un « Tout roule » suivi d'une carte qui surgit.
  const holidayImpactLoading = holidaysInHorizon.length > 0 && null !== seasonChosenId && seasonSlotsQuery.isLoading;
  const stillLoading =
    readLoading(plansQuery) || readLoading(schedulesQuery) || readLoading(campaignsQuery) || closureImpactsPending || zoneLoading || publicHolidaysLoading || holidayImpactLoading || readLoading(unavailabilitiesQuery);
  const readsFailed = readFailed(plansQuery) || readFailed(schedulesQuery) || readFailed(campaignsQuery);

  const isEmpty =
    inProgressEntries.length === 0 &&
    orphanWeekChildren.length === 0 &&
    splitMothers.length === 0 &&
    upcomingHolidays.length === 0 &&
    disruptiveEvents.length === 0 &&
    visibleClosures.length === 0 &&
    !closureImpactsPending &&
    !plansUnresolved &&
    // Même règle que plansUnresolved : schedules en vol = une carte « en cours »
    // peut encore apparaître — « Tout roule » serait un silence menteur.
    !schedulesUnresolved &&
    // L'exemption d'horizon fait dépendre l'EXISTENCE d'une carte de cette lecture : sans
    // elle, une vacance lointaine qui porte une campagne disparaît et « Tout roule »
    // deviendrait un vide crédible (revue #344 round 2).
    undefined !== campaignsQuery.data &&
    cutoffs.length === 0 &&
    upcomingPublicHolidays.length === 0 &&
    // P4-68 — l'EXISTENCE d'une carte d'indispo dépend de cette lecture : sans la
    // garde, « Tout roule » s'affichait pendant que la liste arrivait (doctrine
    // readState : charger n'est pas « rien à signaler »).
    unavailabilityAlerts.length === 0 &&
    undefined !== unavailabilitiesQuery.data &&
    !holidayImpactLoading &&
    zone !== null &&
    !zoneLoading &&
    !publicHolidaysLoading;

  return (
    <aside className="space-y-3 rounded-lg border border-border bg-card p-4">
      <h2 className="text-sm font-semibold">À traiter</h2>

      {/* Refus de chevauchement (P2-38) hors picker (adapter un bloc / créer une semaine depuis
          une carte) : la proposition vit en tête du radar. Picker ouvert → elle vit DANS le
          picker (ci-dessous). */}
      {null !== windowConflict && null === pickerFor && null === pendingMother ? (
        <WindowAlreadyPlannedNotice message={windowConflict.message} onOpen={() => adapt(windowConflict.entryId)} />
      ) : null}

      {/* Gating (#5) : plan de saison non validé → tout ajustement est bloqué. Encart
          rouge en TÊTE, l'action la plus prioritaire : finir de valider la saison. */}
      {!socleValidated ? (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3">
          <div className="flex items-start gap-2">
            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-destructive" />
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium text-destructive">Planning de la saison à valider</p>
              <p className="text-xs text-muted-foreground">Validez le planning principal pour débloquer les ajustements.</p>
            </div>
          </div>
          <div className="mt-2 flex justify-end">
            <Button variant="outline" size="sm" asChild>
              <Link to="/wizard">Valider le planning</Link>
            </Button>
          </div>
        </div>
      ) : null}

      {zone === null && !zoneLoading ? (
        <RadarCard icon={<MapPin className="size-4" />} title="Zone scolaire à renseigner" detail="Renseignez la zone pour voir les vacances.">
          <Button variant="outline" size="sm" asChild>
            <Link to="/club">Renseigner</Link>
          </Button>
        </RadarCard>
      ) : null}

      {/* Plannings EN COURS d'abord : l'action la plus pressante, jamais cachée. */}
      {/* Le gating (#5/F3) bloque le DÉMARRAGE d'un secondaire, pas la REPRISE d'un
          travail déjà commencé (versions). Une carte « en cours » à ZÉRO version
          (créée mais pas générée) est bloquée tant que la saison n'est pas validée ;
          une carte avec versions reste reprenable même après une réouverture. */}
      {inProgressEntries.map((e) => {
        const locked = !socleValidated && !startedEntryIds.has(e.id);
        return (
          <RadarCard key={`wip-${e.id}`} icon={<Pencil className="size-4 text-accent" />} title={e.title} detail="Planning en cours — à finaliser">
            <Button variant="outline" size="sm" disabled={locked} title={locked ? lockTitle : undefined} onClick={() => adapt(e.id)}>
              Reprendre
            </Button>
          </RadarCard>
        );
      })}

      {/* Semaine dont la MÈRE est sortie de la fenêtre radar : sa seule surface. */}
      {orphanWeekChildren.map((e) => {
        const locked = !socleValidated && !startedEntryIds.has(e.id);
        return (
          <RadarCard key={`orphan-${e.id}`} icon={<Pencil className="size-4 text-accent" />} title={e.title} detail="Planning de semaine à finaliser">
            <Button variant="outline" size="sm" disabled={locked} title={locked ? lockTitle : undefined} onClick={() => adapt(e.id)}>
              Reprendre
            </Button>
          </RadarCard>
        );
      })}

      {/* Couverture d'une période DÉCOUPÉE (P2-5 E1) : l'état par semaine, d'un
          coup d'œil — validée → Voir, en cours/à faire → Reprendre, MANQUANTE
          (décochée, échec partiel) → « + créer » (le dead-end de la revue #262).
          Visible tant qu'une semaine n'est pas couverte. */}
      {splitMothers.map((m) => {
        const slots = motherWeekSlots(m);
        // A3 — deux populations : les semaines AJUSTABLES (que cette période peut couvrir) et les
        // semaines GOUVERNÉES par des vacances (informatives, grisées). Le compte ne porte QUE les
        // ajustables — « 0/4 » et non « 0/7 » quand 3 semaines sont sous vacances.
        const adjustableSlots = slots.filter(({ governedBy }) => null === governedBy);
        const governedSlots = slots.filter(({ governedBy }) => null !== governedBy);
        const covered = adjustableSlots.filter(({ child }) => null !== child && activeByEntry.has(child.id)).length;
        const impactCount = splitImpactCountByEntry.get(m.id) ?? 0;
        // ⚠ Le compteur de semaines ne porte QUE les semaines encore actionnables, alors
        // que le nombre de séances touchées vient du serveur, qui l'évalue sur TOUTE la
        // plage de la mère (revue #344). Les juxtaposer sans le dire laissait évaluer le
        // travail restant au double de ce que la carte permet de corriger : la phrase
        // nomme donc chaque périmètre au lieu de les faire passer pour le même.
        const coverageDetail = `${covered}/${adjustableSlots.length} semaine${adjustableSlots.length > 1 ? "s" : ""} à venir couverte${covered > 1 ? "s" : ""}${impactCount > 0 ? ` · ${impactCount} séance${impactCount > 1 ? "s" : ""} touchée${impactCount > 1 ? "s" : ""} sur toute la période` : ""}`;
        return (
          // La SEULE carte repliée par défaut : ses N puces de semaine sont ce qui allonge
          // vraiment le radar (P3-13 d, arbitrage fondateur 2026-08-01).
          // ⚠ Les DOLÉANCES restent hors du repli (`actions`) : le badge de suivi
          // (« 5 à traiter ») n'existe nulle part ailleurs dans l'application, et un
          // compteur dont la raison d'être est d'être vu d'un coup d'œil ne peut pas vivre
          // derrière un clic (revue #344).
          <RadarCard
            key={`split-${m.id}`}
            icon={<CalendarClock className="size-4 text-accent" />}
            title={m.title}
            detail={coverageDetail}
            collapsible
            actions={
              "holiday" === m.periodType ? (
                <>
                  <Button variant="ghost" size="sm" onClick={() => setWishesEntry(m)}>
                    <MessageSquare className="size-4" />
                    Doléances
                  </Button>
                  <RadarCoachWishAction entry={m} season={workingSeason} campaign={campaignByEntry.get(m.id) ?? null} />
                </>
              ) : null
            }
          >
            {/* P2-41 — groupé PAR ENFANT : un enfant-segment sur N semaines = UNE puce (son
                libellé « du X au Y »). Une semaine MANQUANTE (child null) reste individuelle — le
                geste « + créer » est ponctuel, à la semaine (décision ferme). Le compte
                « N/M couvertes » ci-dessus reste au niveau semaine (calculé sur les ajustables). */}
            {groupCoverageSlots(adjustableSlots).map((group) => {
              const child = group.child;
              if (null === child) {
                return (
                  <Button
                    key={group.key}
                    variant="outline"
                    size="sm"
                    disabled={createWeekChildren.isPending || !socleValidated}
                    title={lockTitle}
                    onClick={() => createOneWeek(m, group.weeks[0])}
                  >
                    {`+ sem. du ${frDateShort(group.startDate)}`}
                  </Button>
                );
              }
              const activeId = activeByEntry.get(child.id) ?? null;
              const wip = startedEntryIds.has(child.id);
              // Gating uniquement sur une semaine À CRÉER/DÉMARRER (« à faire ») :
              // « Voir » (validée) et « en cours » (reprise) restent actifs.
              const chipLocked = null === activeId && !wip && !socleValidated;
              const span = group.weeks.length > 1 ? ` au ${frDateShort(child.endDate)}` : "";
              return (
                <Button
                  key={child.id}
                  variant={null !== activeId ? "ghost" : "outline"}
                  size="sm"
                  disabled={chipLocked}
                  title={chipLocked ? lockTitle : undefined}
                  onClick={() => (null !== activeId ? viewOverlay(activeId) : adapt(child.id))}
                >
                  {`sem. du ${frDateShort(child.startDate)}${span} ${null !== activeId ? "✅" : wip ? "· en cours" : "· à faire"}`}
                </Button>
              );
            })}
            {/* A3 — les semaines gouvernées par des vacances : grisées, NON cliquables, avec leur
                raison. Le rappel d'ajustement vit dans le planning des vacances, pas ici. */}
            {governedSlots.map(({ week, governedBy }) => (
              <span key={`gov-${week.monday}`} className="rounded-md border border-border px-2 py-1 text-xs text-muted-foreground">
                {`sem. du ${frDateShort(week.startDate)} · gérée par ${(governedBy ?? []).join(", ")}`}
              </span>
            ))}
          </RadarCard>
        );
      })}

      {upcomingHolidays.map((h) => {
        const entry = entryByHoliday.get(h.id);
        const activeId = entry ? (activeByEntry.get(entry.id) ?? null) : null;
        // Entrée matérialisée mais plans encore en vol : validée ou non, on ne sait PAS —
        // on n'offre donc ni « Voir » ni « Adapter » (adapter à tort régénère un plan validé).
        const stateUnknown = undefined !== entry && plansUnresolved;
        // Une vacance DÉJÀ commencée reste affichée — c'est voulu (cf. le commentaire de
        // SCHOOL_HOLIDAY_HORIZON_DAYS : elle demande toujours un geste). Mais « Dans N j »
        // devient alors « Dans -35 j », qui ne veut rien dire pour un gestionnaire. Même
        // formulation que la carte des indisponibilités plus bas : on dit qu'elle court.
        const when = h.startDate <= today ? `En cours jusqu'au ${frDateShort(h.endDate)}` : `Dans ${daysUntil(today, h.startDate)} j`;
        return (
          <RadarCard key={h.id} icon={<CalendarClock className="size-4 text-accent" />} title={h.label} detail={`${when} · ${null !== activeId ? "planning validé" : stateUnknown ? "chargement…" : "pas de planning"}`}>
            {undefined !== entry ? (
              <Button variant="ghost" size="sm" onClick={() => setWishesEntry(entry)}>
                <MessageSquare className="size-4" />
                Doléances
              </Button>
            ) : null}
            {undefined !== entry ? <RadarCoachWishAction entry={entry} season={workingSeason} campaign={campaignByEntry.get(entry.id) ?? null} /> : null}
            {stateUnknown ? null : null !== activeId ? (
              <Button variant="outline" size="sm" onClick={() => viewOverlay(activeId)}>
                Voir le planning
              </Button>
            ) : entry ? (
              <Button variant="outline" size="sm" disabled={!socleValidated} title={lockTitle} onClick={() => requestAdapt(entry)}>
                Adapter
              </Button>
            ) : (
              <Button
                variant="outline"
                size="sm"
                disabled={createHoliday.isPending || null === seasonClamp(h) || !socleValidated}
                title={lockTitle}
                onClick={() => {
                  const range = seasonClamp(h);
                  if (null === range) {
                    return;
                  }
                  const payload = { schoolHolidayId: h.id, label: h.label, startDate: range.startDate, endDate: range.endDate };
                  // Vacances couvrant PLUSIEURS semaines → picker SANS création (la
                  // mère naît à la confirmation) ; 1 semaine → création + wizard direct.
                  if (needsPicker(range.startDate, range.endDate, "holiday")) {
                    openPendingPicker({ label: h.label, startDate: range.startDate, endDate: range.endDate, periodType: "holiday", create: () => createHoliday.mutateAsync(payload) });
                    return;
                  }
                  createHoliday.mutate(payload, { onSuccess: (created) => adapt(created.id) });
                }}
              >
                Adapter
              </Button>
            )}
          </RadarCard>
        );
      })}

      {disruptiveEvents.map((e) => (
        <RadarCard key={e.id} icon={<PartyPopper className="size-4 text-accent" />} title={e.title} detail={`Le ${frDateShort(e.startDate)} · pas d'entraînement`} />
      ))}

      {/* Fermetures : tenues tant que les plans chargent (Voir/Adapter dépend de l'état du plan). */}
      {plansUnresolved
        ? null
        : visibleClosures.map((e) => {
            const activeId = activeByEntry.get(e.id) ?? null;
            return <ClosureRadarItem key={e.id} entry={e} activeScheduleId={activeId} inProgress={startedEntryIds.has(e.id)} seasonUnvalidated={!socleValidated} adaptTitle={lockTitle} onAdapt={() => requestAdapt(e)} onView={() => null !== activeId && viewOverlay(activeId)} />;
          })}

      {cutoffs.map((e) => (
        <RadarCard
          key={e.id}
          icon={<OctagonX className="size-4 text-destructive" />}
          title={e.title}
          detail={e.startDate === e.endDate ? `Le ${frDateShort(e.startDate)} · aucun entraînement` : `Du ${frDateShort(e.startDate)} au ${frDateShort(e.endDate)} · aucun entraînement`}
        />
      ))}

      {/* P4-68 — indispos gymnase : alerter au bon moment, ouvrir le chemin, ne rien
          décider à la place du gestionnaire (modèle fondateur 2026-08-06). */}
      {unavailabilityAlerts.map((u) => {
        const impact = impactByUnavailability.get(u.id);
        const started = u.startDate <= today;
        const when = started ? `En cours jusqu'au ${frDateShort(u.endDate)}` : `Dans ${daysUntil(today, u.startDate)} j`;
        // « Impact non évalué » plutôt qu'un zéro rassurant : la lecture peut être en
        // vol ou avoir échoué, et annoncer « aucun entraînement » serait un fait jamais
        // vérifié (même doctrine que ClosureRadarItem).
        const what = undefined === impact
          ? "impact non évalué"
          : impact.trainingSlotCount > 0
            ? `${impact.trainingSlotCount} créneau${impact.trainingSlotCount > 1 ? "x" : ""} d'entraînement/sem. concerné${impact.trainingSlotCount > 1 ? "s" : ""}`
            : "aucun entraînement concerné";
        return (
          <RadarCard
            key={`unavail-${u.id}`}
            icon={<CalendarOff className="size-4 text-destructive" />}
            title={`${venueNameOf(u.venueId)} indisponible${null !== u.label ? ` (${u.label})` : ""}`}
            detail={`${when} · ${what}`}
          >
            <Button
              variant="outline"
              size="sm"
              disabled={!socleValidated || createClosureFromUnavailability.isPending}
              title={lockTitle}
              onClick={() => {
                const title = `${venueNameOf(u.venueId)} indisponible${null !== u.label ? ` (${u.label})` : ""}`;
                const params = { title, venueId: u.venueId, startDate: u.startDate, endDate: u.endDate };
                // P2-36 tranche 2 / P2-40 — l'indispo passe par la maison unique. On ouvre le choix
                // SANS créer la fermeture (l'entrée n'est pas encore née) dès qu'elle couvre PLUSIEURS
                // semaines OU qu'au moins une semaine est sous vacances (needsPicker) : le chemin
                // « pending » partagé avec les vacances la matérialise à la confirmation, via
                // createVenueClosure. Une seule semaine hors vacances → création + adaptBlock direct
                // (comportement conservé), le plan naissant du geste d'Adapter.
                if (needsPicker(u.startDate, u.endDate, "closure")) {
                  openPendingPicker({ label: title, startDate: u.startDate, endDate: u.endDate, periodType: "closure", create: () => createClosureFromUnavailability.mutateAsync(params) });
                  return;
                }
                createClosureFromUnavailability.mutate(params, { onSuccess: (entry) => void adaptBlock(entry.id) });
              }}
            >
              Adapter
            </Button>
          </RadarCard>
        );
      })}

      {upcomingPublicHolidays.map((h) => (
        <RadarCard
          key={h.id}
          icon={<CalendarOff className="size-4 text-destructive" />}
          title={h.label}
          detail={`Dans ${daysUntil(today, h.date)} j · ${h.sessionCount} séance${h.sessionCount > 1 ? "s" : ""} ce jour-là`}
        />
      ))}

      {/* P3-11 : squelette tant qu'on ne sait pas — jamais en même temps que « Tout roule »
          (`isEmpty` exige déjà que ces mêmes lectures soient résolues). */}
      {stillLoading ? (
        <div role="status" className="space-y-2">
          {/* Une région live annonce son CONTENU, jamais son `aria-label` : sans ce texte,
              le lecteur d'écran passait du titre « À traiter » au silence, ce qui se lit
              « rien à faire » (revue #344). */}
          <span className="sr-only">Chargement des éléments à traiter…</span>
          {[0, 1].map((i) => (
            <div key={i} className="animate-pulse rounded-md border border-border p-3">
              <div className="h-3 w-2/5 rounded bg-muted" />
              <div className="mt-2 h-2 w-3/5 rounded bg-muted" />
            </div>
          ))}
        </div>
      ) : null}

      {/* Une lecture ratée n'est ni « ça charge » ni « tout roule » : on le dit, sinon
          l'écran renonce en silence (doctrine `readState`). */}
      {readsFailed ? (
        <p role="alert" className="rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-foreground">
          Impossible de charger les éléments à traiter — cette liste est peut-être incomplète.
        </p>
      ) : null}

      {isEmpty ? <p className="text-sm text-muted-foreground">Rien à l'horizon. Tout roule.</p> : null}

      {/* Vacance PAS encore matérialisée : picker sur une mère synthétique (aucune
          création tant que non confirmé — annuler ne laisse aucun fantôme). */}
      {null !== pendingMother && null !== workingSeason ? (
        <WeekPickerDialog
          title={pendingMother.label}
          startDate={pendingMother.startDate}
          endDate={pendingMother.endDate}
          weeks={pendingOffer.offered}
          season={workingSeason}
          excludedRanges={pendingOffer.excludedRanges}
          plannedRanges={pendingOffer.plannedRanges}
          state={pendingPickerState}
          busy={createHoliday.isPending || createClosureFromUnavailability.isPending || createWeekChildren.isPending}
          onPickSegments={(segments) => pickWeeksPending(pendingMother, segments)}
          onAdaptWhole={() => adaptWholePending(pendingMother)}
          onRecordOnly={() => recordPendingOnly(pendingMother)}
          onClose={() => { resetWindowConflict(); setPendingMother(null); }}
          conflict={windowConflict}
          onOpenConflict={adapt}
        />
      ) : null}

      {null !== pickerFor && null !== workingSeason ? (
        <WeekPickerDialog
          title={pickerFor.title}
          startDate={pickerFor.startDate}
          endDate={pickerFor.endDate}
          weeks={pickerOffer.offered}
          season={workingSeason}
          excludedRanges={pickerOffer.excludedRanges}
          plannedRanges={pickerOffer.plannedRanges}
          busy={createWeekChildren.isPending}
          state={pickerState}
          block={{ ...blockInfo, deleting: blockDeleting, deleteFailed: blockDeleteFailed, onDeleteVersions: deleteBlockVersionsAndSplit }}
          onPickSegments={(segments) => pickWeeks(pickerFor, segments)}
          onAdaptWhole={() => {
            setPickerFor(null);
            void adaptBlock(pickerFor.id);
          }}
          onClose={() => { resetWindowConflict(); setPickerFor(null); }}
          conflict={windowConflict}
          onOpenConflict={adapt}
        />
      ) : null}
      {null !== wishesEntry ? <CoachWishesModal mother={wishesEntry} weekFilter={null} onClose={() => setWishesEntry(null)} /> : null}
    </aside>
  );
}

function ClosureRadarItem({ entry, activeScheduleId, inProgress = false, seasonUnvalidated = false, adaptTitle, onAdapt, onView }: { entry: CalendarEntry; activeScheduleId: string | null; inProgress?: boolean; seasonUnvalidated?: boolean; adaptTitle?: string; onAdapt: () => void; onView: () => void }) {
  const { data } = useEntryConflicts(entry.id);
  const count = data?.conflicts.reduce((sum, c) => sum + c.dates.length, 0) ?? 0;
  // ADR-0002 lot D-b : « a un overlay » = le plan de la période est VALIDÉ (chosenScheduleId).
  const hasOverlay = null !== activeScheduleId;
  // Le plan de la saison existe mais ne pointe aucune version : il est INCOMPLET,
  // et le serveur n'a donc aucun calendrier à comparer. Dire « aucun impact » serait
  // un mensonge rassurant — le gestionnaire n'adapterait pas une fermeture qui, en
  // vrai, touchera ses séances. Un plan qui pointe et ne heurte rien, lui, n'a
  // vraiment rien à signaler : les deux états ne doivent pas se dire pareil.
  // Trois causes distinctes de « pas de chiffre », à ne pas confondre : le plan de la
  // saison ne pointe rien (fait VÉRIFIÉ), ou la lecture a échoué (on ne sait pas, et
  // affirmer « plan incomplet » serait énoncer un fait jamais contrôlé).
  const planIncomplete = false === data?.seasonPlanChosen;
  const impactUnknown = undefined === data;

  // Le parent ne monte cette carte que s'il y a quelque chose à traiter (voir
  // visibleClosures) : pas de branche « rien à signaler » ici, elle ne serait
  // jamais rendue — et l'écrire laisserait croire que le radar inventorie.
  // « en cours » remplace « absent » sans perdre le CHIFFRE d'impact : la carte
  // générique qui gommait le détail des séances touchées est réservée aux
  // périodes sans carte riche (revue #260 round 1).
  const detail = hasOverlay
    ? "Planning secondaire validé"
    : impactUnknown
      ? "Impact non évalué · réessayez"
      : planIncomplete
        ? "Planning de la saison incomplet · impact non évalué"
        : `${count} séance${count > 1 ? "s" : ""} à replacer · ${inProgress ? "planning en cours — à finaliser" : "planning secondaire absent"}`;

  return (
    <RadarCard
      icon={<AlertTriangle className={hasOverlay ? "size-4 text-accent" : "size-4 text-destructive"} />}
      title={entry.title}
      detail={detail}
    >
      {hasOverlay ? (
        <>
          <Button variant="outline" size="sm" onClick={onView}>
            Voir le planning
          </Button>
          {/* Overlay validé → « Ajuster » retouche un secondaire existant (pas une
              création) : jamais bloqué par le gating saison. */}
          <Button variant="ghost" size="sm" onClick={onAdapt}>
            Ajuster
          </Button>
        </>
      ) : (
        // Gating seulement sur une fermeture À DÉMARRER (« Adapter ») ; « Reprendre »
        // (travail en cours) reste actif même si la saison est rouverte.
        <Button variant="outline" size="sm" disabled={!inProgress && seasonUnvalidated} title={!inProgress ? adaptTitle : undefined} onClick={onAdapt}>
          {inProgress ? "Reprendre" : "Adapter"}
        </Button>
      )}
    </RadarCard>
  );
}

/**
 * L'encart d'une ligne du radar — toutes les cartes passent par ici.
 *
 * P3-13 (d) — « le radar devient très long » (fondateur). Le repli est CIBLÉ : seules les
 * cartes à détail volumineux (`collapsible`, aujourd'hui la couverture d'une période
 * découpée et ses N puces de semaine) démarrent repliées. Une carte à une action garde son
 * bouton visible — mesuré à l'implémentation : tout replier mettait CHAQUE geste du radar
 * à deux clics (13 tests d'action tombaient) sans raccourcir ce qui est réellement long.
 * L'en-tête reste toujours lisible : c'est ce qu'on demande au panneau d'un coup d'œil.
 */
function RadarCard({
  icon,
  title,
  detail,
  collapsible = false,
  actions,
  children,
}: {
  icon: React.ReactNode;
  title: string;
  detail: string;
  collapsible?: boolean;
  /** Rendu TOUJOURS, même carte repliée — pour ce qui doit se lire d'un coup d'œil. */
  actions?: React.ReactNode;
  children?: React.ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const foldable = collapsible && undefined !== children && null !== children;

  return (
    <div className="rounded-md border border-border p-3">
      <div className="flex items-start gap-2">
        <span className="mt-0.5">{icon}</span>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-medium">{title}</p>
          <p className="text-xs text-muted-foreground">{detail}</p>
        </div>
        {foldable ? (
          <button
            type="button"
            aria-expanded={open}
            aria-label={`${open ? "Replier" : "Déplier"} ${title}`}
            className="shrink-0 rounded p-0.5 text-muted-foreground hover:text-foreground"
            onClick={() => setOpen((prev) => !prev)}
          >
            <ChevronDown className={cn("size-4 transition-transform", open && "rotate-180")} />
          </button>
        ) : null}
      </div>
      {actions ? <div className="mt-2 flex flex-col items-end gap-1">{actions}</div> : null}
      {/* Empilées à droite (pas en ligne) : les chips par semaine d'une carte de
          couverture débordaient de l'encart en ligne (retour fondateur 2026-07-24). */}
      {children && (!foldable || open) ? <div className="mt-2 flex flex-col items-end gap-1">{children}</div> : null}
    </div>
  );
}
