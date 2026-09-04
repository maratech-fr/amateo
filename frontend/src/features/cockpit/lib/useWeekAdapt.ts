import { useState } from "react";

import { useWorkingSeason } from "@/shared/session/queries";
import type { Schedule } from "@/features/planning/api";
import { IN_FLIGHT_STATUSES } from "@/shared/lib/scheduleStatus";
import { useDeleteSchedule, useSchedules } from "@/features/planning/queries";
import { toast } from "@/shared/stores/toastStore";

import type { CalendarEntry, CalendarEntryPeriodType, PlannedWindow, SchedulePlan } from "../api";
import { WindowAlreadyPlannedError } from "../api";
import { useCalendarEntries, useCreateHolidayPeriod, useCreatePeriodPlan, useCreateWeekChildren, usePlannedWindows, useSchedulePlans, useSchoolHolidays, type PlannedWindowsRef, type WeekChildrenResult } from "../queries";
import { closureWeeksOffer, holidayWindows, periodWeeksToAdjust, segmentsFromOffer, subtractPlannedWeeks, todayISO, type ClosureWeeksOffer, type WeekSegment, type WeekWindow } from "./date";

/**
 * L'offre servie au picker : les semaines offertes + les blocs vacances écartés (P2-40) + les
 * fenêtres DÉJÀ PLANIFIÉES par un autre plan (P2-38). Les trois notions sont ORTHOGONALES : une
 * fenêtre peut être partiellement sous vacances ET partiellement déjà planifiée — `plannedRanges`
 * n'est donc PAS un 5ᵉ état du picker mais une liste à côté de l'état.
 */
export interface PickerOffer extends ClosureWeeksOffer {
  plannedRanges: PlannedWindow[];
}

/** Le refus « une seule planification par fenêtre » (P2-38), tel que l'affiche le geste. */
export interface WindowConflict {
  message: string;
  entryId: string;
}

/**
 * Une période mère PAS encore matérialisée (P2-5 E1, généralisée P2-36 tranche 2). Le picker
 * s'ouvre sur cette référence SANS rien créer ; la mère naît à la confirmation via `create`.
 * Deux surfaces l'utilisent avec leur propre créateur — une vacance scolaire
 * (`createHolidayPeriod`) et une indisponibilité de gymnase (`createVenueClosure`) — mais le
 * CHEMIN (créer à la confirmation, puis découper en semaines ou adapter d'un bloc) est unique.
 */
export interface PendingMother {
  /** Libellé affiché dans le picker tant que la mère n'existe pas encore. */
  label: string;
  startDate: string;
  endDate: string;
  /** Pilote le calcul des semaines à offrir (holiday écarte la semaine partielle Ven/Sam/Dim ;
   *  closure garde weeksCovering). */
  periodType: CalendarEntryPeriodType;
  /** Matérialise la mère À la confirmation (jamais avant — annuler ne laisse aucun fantôme).
   *  Résout l'entrée créée pour enchaîner la découpe/adaptation. */
  create: () => Promise<CalendarEntry>;
}

/**
 * P2-36 — LA décision « comment ouvrir l'ajustement d'une période multi-semaines »,
 * maison UNIQUE. Le radar et le DayDialog la dupliquaient avec des entrées différentes
 * (`RadarPanel` gardait `!schedulesUnresolved`, `DayDialog` `schedulesResolved &&
 * planResolved && childrenResolved`) : corriger un seul côté garantissait un écart. Le
 * défaut qu'elle ferme : quand la condition tombait, l'écran BASCULAIT en bloc SANS RIEN
 * DIRE (le serveur, lui, refuse le découpage forcé par un 422 explicite).
 *
 * ⚠ CHAQUE cause est NOMMÉE — un « ça part en bloc » générique recréerait le défaut avec
 * plus de mots. Quatre causes distinctes, deux issues :
 *  - `single-week` / `already-split` → aucun choix à offrir : bloc direct (comportement
 *    conservé — une seule semaine calendaire, ou une mère déjà découpée que sa carte de
 *    couverture gouverne) ;
 *  - `loading` → plans/plannings/enfants pas encore résolus : le picker s'OUVRE et le dit,
 *    au lieu de partir en bloc en silence (le repli « ne jamais cocher sans savoir » reste,
 *    seul le silence disparaît) ;
 *  - `block-generated` → un plan de bloc porte déjà des versions : le picker s'OUVRE et
 *    NOMME le fait, garde « Continuer d'un bloc » et propose la découpe destructive.
 */
export type WeekAdaptDecision =
  | { kind: "single-week" }
  | { kind: "already-split" }
  | { kind: "loading" }
  | { kind: "block-generated" }
  | { kind: "holiday-covered" }
  | { kind: "weeks" };

export interface WeekAdaptInput {
  /** La période couvre-t-elle PLUSIEURS semaines calendaires à ajuster (sinon : rien à choisir) ? */
  multiWeek: boolean;
  /** La mère porte-t-elle déjà des semaines-enfants (sa carte de couverture prend le relais) ? */
  alreadySplit: boolean;
  /** Plans + plannings + enfants tous résolus ? Faux ⇒ on ne PEUT pas décider bloc/semaines. */
  resolved: boolean;
  /** Le plan « bloc » de la période porte-t-il déjà ≥ 1 version ? */
  blockGenerated: boolean;
  /**
   * P2-40 — au moins une semaine de la fermeture est GOUVERNÉE par des vacances (exclue de
   * l'offre). Dès qu'une exclusion existe : le picker s'ouvre TOUJOURS (même 0 ou 1 semaine
   * offerte) et le chemin « d'un bloc » DISPARAÎT (un plan de bloc gouvernerait la fenêtre des
   * vacances). L'exclusion l'emporte donc sur single-week / already-split / block-generated —
   * mais jamais sur le chargement (on ne conclut pas sans les données).
   */
  holidayCovered: boolean;
}

export function decideWeekAdapt({ multiWeek, alreadySplit, resolved, blockGenerated, holidayCovered }: WeekAdaptInput): WeekAdaptDecision {
  if (!multiWeek && !holidayCovered) {
    return { kind: "single-week" };
  }
  if (alreadySplit && !holidayCovered) {
    return { kind: "already-split" };
  }
  if (!resolved) {
    return { kind: "loading" };
  }
  if (holidayCovered) {
    return { kind: "holiday-covered" };
  }
  if (blockGenerated) {
    return { kind: "block-generated" };
  }
  return { kind: "weeks" };
}

/** Les états VISIBLES du picker. `null` ⇒ la décision ne l'ouvre pas (bloc direct). */
export type WeekPickerState = "weeks" | "loading" | "block" | "holiday";
export function pickerStateOf(decision: WeekAdaptDecision): WeekPickerState | null {
  switch (decision.kind) {
    case "weeks":
      return "weeks";
    case "loading":
      return "loading";
    case "block-generated":
      return "block";
    case "holiday-covered":
      return "holiday";
    case "single-week":
    case "already-split":
      return null;
  }
}

/** Ce que le picker doit dire de l'état « bloc déjà généré » (lu du plan + de ses versions). */
export interface BlockVersionsInfo {
  versionCount: number;
  /** Le plan de bloc porte une version CHOISIE/validée : la découpe destructive n'est PAS offerte. */
  validated: boolean;
  /** Une version est EN GÉNÉRATION : la découpe est désactivée tant qu'elle vole. */
  generationInFlight: boolean;
}

/**
 * P2-5 E1 — le flux « découper une période en semaines » PARTAGÉ par le radar et
 * le DayDialog (revue #262 round 3 : il était copié-collé, un fix d'un côté
 * divergeait de l'autre). La DÉCISION d'ouvrir le picker (P2-36) et la mutation,
 * les toasts, l'auto-adapt à une seule semaine et la découpe destructive vivent ici,
 * une seule fois. Les deux surfaces ne passent plus que l'entrée (+ leur connaissance
 * des semaines-enfants, qu'elles seules ont) — la logique, elle, ne se dédouble plus.
 *
 * Retour fondateur (2026-07-19) : adapter une VACANCE ne doit RIEN créer tant que
 * les semaines ne sont pas confirmées — la vacance scolaire est déjà l'événement,
 * on ne matérialise sa mère qu'à la confirmation du picker (chemin `pending`). Une
 * fois les semaines créées, le wizard s'ouvre directement sur la 1ʳᵉ (on travaille
 * tout de suite ; les autres semaines restent au radar / « tous les plannings »).
 */
export function useWeekAdapt(adapt: (entryId: string) => void, childrenResolved = true) {
  const [pickerFor, setPickerFor] = useState<CalendarEntry | null>(null);
  // Mère PAS encore créée : le picker s'ouvre sur cette référence SANS la
  // matérialiser — annuler ne doit rien laisser. La mère naît à la confirmation
  // (pickWeeksPending / adaptWholePending), par le `create` que la surface fournit.
  const [pendingMother, setPendingMother] = useState<PendingMother | null>(null);
  const createWeekChildren = useCreateWeekChildren();
  const createHoliday = useCreateHolidayPeriod();
  const createPeriodPlan = useCreatePeriodPlan();
  // P2-36 — les lectures dont dépend la décision d'ouverture ET l'état « bloc déjà généré » :
  // sourcées ICI (react-query dédoublonne avec les mêmes lectures des surfaces) pour que
  // celles-ci ne passent plus que l'entrée. `undefined` = pas encore résolu → état « chargement ».
  const plansQuery = useSchedulePlans();
  const plans = plansQuery.data;
  const schedulesQuery = useSchedules();
  const schedules = schedulesQuery.data;
  const workingSeason = useWorkingSeason();
  const deleteSchedule = useDeleteSchedule();
  const [blockDeleting, setBlockDeleting] = useState(false);
  const [blockDeleteFailed, setBlockDeleteFailed] = useState(false);
  // P2-40 — les fenêtres de vacances SERVIES, sourcées ICI (react-query dédoublonne avec les
  // lectures des surfaces) pour que celles-ci cessent de recalculer l'offre localement. Deux
  // sources (comme le radar) : le feed scolaire (défaut saison) ∪ les entrées calendrier de type
  // vacances non ignorées, sur la fenêtre de la saison. `undefined` = pas encore résolu → l'offre
  // d'une fermeture reste en « chargement » (jamais une bascule en bloc en silence).
  const schoolHolidaysQuery = useSchoolHolidays();
  const calendarEntriesQuery = useCalendarEntries(workingSeason?.startDate ?? "", workingSeason?.endDate ?? "", null !== workingSeason);
  const holidayWindowsResolved = undefined !== schoolHolidaysQuery.data && undefined !== calendarEntriesQuery.data;
  const holidayWins = null === workingSeason || !holidayWindowsResolved ? [] : holidayWindows(calendarEntriesQuery.data ?? [], schoolHolidaysQuery.data?.items ?? [], workingSeason);

  // P2-38 (prévention, étapes 4-6) — LES FENÊTRES DÉJÀ PLANIFIÉES par un AUTRE plan de période,
  // servies par le backend (règle d'or : jamais recalculées). Une entrée matérialisée s'interroge
  // par `entryId`, une mère pending par `seasonId`. La requête n'est ARMÉE que sur le picker ouvert
  // (aucun fetch sur les chemins sans modale ; la prévention est bornée à la modale).
  //
  // FAIL-OPEN sur ERREUR de lecture : pendant la requête → non résolu (le picker s'ouvre en
  // « chargement » plutôt qu'en offre qu'on rétracte ensuite) ; si la lecture ÉCHOUE → on offre
  // TOUT (zéro exclusion), parce que le pire cas est exactement l'existant (le 409
  // `window_already_planned` reste rendu par `noteWindowConflict`) alors qu'un fail-closed
  // bloquerait un geste légitime sur une panne transitoire. La prévention est un confort ; le
  // refus est la garde.
  const plannedWindowsRef: PlannedWindowsRef | null =
    null !== pickerFor
      ? { start: pickerFor.startDate, end: pickerFor.endDate, entryId: pickerFor.id }
      : null !== pendingMother && null !== workingSeason
        ? { start: pendingMother.startDate, end: pendingMother.endDate, seasonId: workingSeason.id }
        : null;
  const plannedWindowsQuery = usePlannedWindows(plannedWindowsRef);
  const plannedWindowsResolved = undefined !== plannedWindowsQuery.data || plannedWindowsQuery.isError;
  const plannedWindows: PlannedWindow[] = plannedWindowsQuery.data ?? [];

  const planForEntry = (entryId: string): SchedulePlan | null => (plans ?? []).find((p) => p.calendarEntryId === entryId) ?? null;
  const versionsOf = (planId: string | null): Schedule[] => (null === planId ? [] : (schedules ?? []).filter((s) => s.schedulePlanId === planId));

  // P2-40 — l'OFFRE de semaines d'une fenêtre : pour une fermeture, `closureWeeksOffer` écarte
  // les semaines gouvernées par des vacances ; pour tout autre type, l'offre historique. Foyer
  // unique exposé aux surfaces (`weekOffer`) pour qu'elles ne recalculent plus.
  const offerFor = (startDate: string, endDate: string, periodType: CalendarEntryPeriodType | null): ClosureWeeksOffer => {
    if (null === workingSeason) {
      return { offered: [], excludedRanges: [] };
    }
    if ("closure" !== periodType) {
      return { offered: periodWeeksToAdjust(startDate, endDate, workingSeason, periodType, todayISO()), excludedRanges: [] };
    }
    return closureWeeksOffer(startDate, endDate, workingSeason, todayISO(), holidayWins);
  };

  const decisionForWindow = (
    startDate: string,
    endDate: string,
    periodType: CalendarEntryPeriodType | null,
    { alreadySplit, blockGenerated }: { alreadySplit: boolean; blockGenerated: boolean },
  ): WeekAdaptDecision => {
    const isClosure = "closure" === periodType;
    // Une fermeture ne peut décider qu'une fois les vacances résolues : sinon on ne SAIT PAS si
    // une semaine est couverte (et un raccourci « une seule semaine » filerait en bloc à tort).
    const holidayResolved = !isClosure || holidayWindowsResolved;
    // P2-38 — le verdict « déjà planifié » entre dans `resolved` (même mécanique que holidayResolved) :
    // la modale s'ouvre en « chargement » plutôt qu'en offre qu'on rétracte ensuite. INDISPENSABLE :
    // la resynchro `sig !== signature` du picker réinitialise segments/coches dès que la liste des
    // lundis change — un verdict arrivant tard effacerait sinon le scindage/fusion manuel du
    // gestionnaire sans un mot. (Fail-open : `plannedWindowsResolved` est vrai aussi sur ERREUR.)
    const resolved = undefined !== plans && undefined !== schedules && childrenResolved && holidayResolved && plannedWindowsResolved;
    const offer = offerFor(startDate, endDate, periodType);
    const holidayCovered = offer.excludedRanges.length > 0;
    const totalWeeks = null === workingSeason ? 0 : periodWeeksToAdjust(startDate, endDate, workingSeason, periodType, todayISO()).length;
    // FERMETURE (règle fondateur 2026-09-05) : « d'un bloc » n'est direct que si la fenêtre se
    // décompose en UN SEUL segment (une semaine alignée lun→dim, un bout entamé, OU un milieu
    // aligné lun→dim). Dès qu'elle compte ≥2 segments (début·milieu·fin), le picker s'ouvre pour
    // les créer un à un — miroir de la garde backend WeekSegmentationRule. Aux vacances non
    // résolues, on force `multiWeek` pour ne PAS court-circuiter en single-week (la décision
    // retombe sur « chargement »). Les autres types gardent le compte de semaines historique.
    const multiWeek = isClosure ? (!holidayResolved || segmentsFromOffer(offer.offered, startDate, endDate).length > 1) : totalWeeks > 1;
    return decideWeekAdapt({ multiWeek, alreadySplit, resolved, blockGenerated, holidayCovered });
  };

  const decisionFor = (entry: CalendarEntry, alreadySplit: boolean): WeekAdaptDecision => {
    const plan = planForEntry(entry.id);
    const blockGenerated = null !== plan && versionsOf(plan.id).length > 0;
    return decisionForWindow(entry.startDate, entry.endDate, entry.periodType, { alreadySplit, blockGenerated });
  };

  // P2-40 — les surfaces demandent au foyer unique s'il faut OUVRIR le picker pour une fenêtre pas
  // encore matérialisée (chemin pending) : plusieurs semaines OU au moins une exclue par les
  // vacances. Une seule semaine hors vacances reste sur le chemin direct (création + adaptBlock).
  const needsPicker = (startDate: string, endDate: string, periodType: CalendarEntryPeriodType | null): boolean => {
    if (null === workingSeason) {
      return false;
    }
    const offer = offerFor(startDate, endDate, periodType);
    if (offer.excludedRanges.length > 0) {
      return true;
    }
    // FERMETURE : le picker s'ouvre dès que le découpage compte ≥2 segments (début·milieu·fin) ;
    // un seul segment (semaine alignée, bout entamé, milieu aligné) reste sur le chemin direct.
    if ("closure" === periodType) {
      return segmentsFromOffer(offer.offered, startDate, endDate).length > 1;
    }
    return periodWeeksToAdjust(startDate, endDate, workingSeason, periodType, todayISO()).length > 1;
  };
  // P2-38 — le refus de chevauchement affiché à l'endroit du geste (picker ouvert → dans le
  // picker ; sinon → dans l'encart). Un seul état : les deux cas s'excluent (un refus de picker
  // GARDE le picker ouvert ; adaptBlock/createOneWeek se jouent sans picker). Réinitialisé au
  // début de chaque tentative pour ne jamais coller un refus périmé sur un nouveau geste.
  const [windowConflict, setWindowConflict] = useState<WindowConflict | null>(null);
  const resetWindowConflict = (): void => setWindowConflict(null);
  const noteWindowConflict = (error: unknown): void => {
    if (error instanceof WindowAlreadyPlannedError) {
      setWindowConflict({ message: error.message, entryId: error.conflictingEntryId });
    }
  };

  // LE GESTE « Adapter » un BLOC (mère entière / fermeture) — ADR-0002 amendé
  // 2026-07-24 : la période n'a plus de plan à sa matérialisation, il naît ICI,
  // AVANT startPeriodMode (sinon usePeriodAnchor attendrait à l'infini). Les
  // SEMAINES, elles, naissent avec leur plan : leur adapt() reste direct.
  // Idempotent côté serveur ; erreur (ex. 422 période découpée sur données
  // périmées) relevée par le filet global des mutations — on ne navigue pas.
  const adaptBlock = async (entryId: string): Promise<void> => {
    setWindowConflict(null);
    try {
      await createPeriodPlan.mutateAsync(entryId);
      adapt(entryId);
    } catch (error) {
      // Refus de chevauchement (P2-38) → affiché comme proposition ; tout autre échec → filet
      // global des mutations (queryClient.ts).
      noteWindowConflict(error);
    }
  };

  const announce = ({ created, failedCount }: WeekChildrenResult): void => {
    if (failedCount > 0) {
      toast.error(`${failedCount} semaine${failedCount > 1 ? "s" : ""} n'a pas pu être créée — réessayez depuis la carte de couverture.`);
    }
    if (0 === created.length && 0 === failedCount) {
      toast.info("Ces semaines étaient déjà découpées.");
    }
  };

  // Suite commune une fois les semaines créées (mère matérialisée OU fraîchement
  // née) : on ouvre le wizard sur la 1ʳᵉ semaine créée (retour fondateur
  // 2026-07-19). ≥2 créées → toast qui rappelle où retrouver les autres. En cas
  // d'ÉCHEC PARTIEL (des semaines n'ont pas été créées), on NE navigue PAS : le
  // gestionnaire doit lire le toast d'erreur et relancer les manquantes depuis la
  // carte de couverture (revue B1 : naviguer emporterait l'avertissement).
  const finishChildren = (result: WeekChildrenResult): void => {
    announce(result);
    if (0 === result.created.length || result.failedCount > 0) {
      return;
    }
    if (result.created.length > 1) {
      toast.success(`${result.created.length} plannings de semaine créés — le 1ᵉʳ est ouvert, les autres au radar.`);
    }
    adapt(result.created[0].id);
  };

  // Segments cochés d'une mère DÉJÀ matérialisée → création des plans (un enfant par segment).
  const pickWeeks = (mother: CalendarEntry, segments: WeekSegment[]): void => {
    setWindowConflict(null);
    createWeekChildren.mutate(
      { mother, segments },
      {
        onSuccess: (result) => {
          setPickerFor(null);
          finishChildren(result);
        },
        // Une semaine gouvernée par un autre plan (P2-38) : le picker RESTE ouvert et affiche
        // le refus ; les autres échecs vont au filet global.
        onError: noteWindowConflict,
      },
    );
  };

  const openPendingPicker = (mother: PendingMother): void => setPendingMother(mother);

  // Confirmation du picker pour une mère PAS encore créée : elle naît ICI (via son
  // `create`), puis ses semaines. Le créateur est un mutateAsync (pas d'onSuccess
  // portée) : navigation/toasts doivent partir même si la modale se referme pendant
  // le POST. Erreur relevée par le filet global des mutations (queryClient.ts).
  const pickWeeksPending = async (pending: PendingMother, segments: WeekSegment[]): Promise<void> => {
    setWindowConflict(null);
    try {
      const mother = await pending.create();
      setPendingMother(null);
      createWeekChildren.mutate({ mother, segments }, { onSuccess: finishChildren, onError: noteWindowConflict });
    } catch (error) {
      // La création de la mère elle-même ne heurte pas la garde (entrée racine, sans plan) ;
      // seul le lot de semaines peut. Par sûreté, un refus typé est aussi capté ici.
      noteWindowConflict(error);
    }
  };

  // Chemin « d'un bloc » d'une mère PAS encore créée : elle naît SANS plan
  // (amendement 2026-07-24), puis le geste adaptBlock le crée et ouvre le wizard.
  const adaptWholePending = async (pending: PendingMother): Promise<void> => {
    try {
      const mother = await pending.create();
      setPendingMother(null);
      await adaptBlock(mother.id);
    } catch {
      /* relevé par le filet global des mutations */
    }
  };

  // P2-40 — « Consigner l'indisponibilité » : quand il ne reste AUCUNE semaine à découper ni bloc à
  // adapter, le FAIT doit exister quand même. Sans lui, le pré-remplissage des coches gymnase dans
  // le planning qui gouverne la fenêtre n'a rien à lire. On matérialise donc la fermeture via son
  // `create`, SANS plan ni navigation. Réservé au chemin pending (une entrée déjà en base n'a rien
  // à consigner).
  //
  // ⚠ **Le message DIT où le rappel attend, et ce n'est plus toujours les vacances** (2026-08-22).
  // Depuis que la prévention ouvre ce chemin au cas « fenêtre déjà gouvernée par un autre plan de
  // période », la phrase d'origine — « dans le planning des vacances » — devenait FAUSSE la moitié
  // du temps : le rappel attend dans le plan qui gouverne, quel qu'il soit. On ne nomme donc que ce
  // qu'on sait : les vacances quand c'est une couverture de vacances, le planning en place sinon.
  const recordPendingOnly = async (pending: PendingMother): Promise<void> => {
    setWindowConflict(null);
    const governedByPlan = pendingOffer.plannedRanges.length > 0;
    try {
      await pending.create();
      setPendingMother(null);
      toast.success(
        governedByPlan
          ? "Indisponibilité consignée — le rappel vous attend dans le planning qui couvre ces dates."
          : "Indisponibilité consignée — le rappel vous attend dans le planning des vacances.",
      );
    } catch (error) {
      noteWindowConflict(error);
    }
  };

  // Chip « + créer » d'UNE semaine manquante (couverture) → crée puis adapte. Geste de rattrapage
  // ponctuel : reste à la SEMAINE (segment de taille 1), décision ferme.
  const createOneWeek = (mother: CalendarEntry, week: WeekWindow): void => {
    setWindowConflict(null);
    createWeekChildren.mutate(
      { mother, segments: [{ weeks: [week], startDate: week.startDate, endDate: week.endDate, monday: week.monday, partial: false }] },
      {
        onSuccess: (result) => {
          announce(result);
          if (result.created[0]) {
            adapt(result.created[0].id);
          }
        },
        // Cette semaine recoupe un autre plan (P2-38) → refus affiché dans l'encart.
        onError: noteWindowConflict,
      },
    );
  };

  // P2-36 — LE geste « Adapter » d'une période multi-semaines : les deux surfaces l'appellent
  // avec la seule entrée (+ leur savoir « déjà découpée »). `single-week`/`already-split` →
  // bloc direct ; les trois autres OUVRENT le picker (qui NOMME loading / block / weeks) —
  // fini la bascule silencieuse.
  const requestAdapt = (entry: CalendarEntry, { alreadySplit }: { alreadySplit: boolean }): void => {
    const decision = decisionFor(entry, alreadySplit);
    if (null !== pickerStateOf(decision)) {
      setWindowConflict(null);
      setBlockDeleteFailed(false);
      setPickerFor(entry);
      return;
    }
    void adaptBlock(entry.id);
  };

  // État courant du picker ouvert, RECALCULÉ à chaque rendu depuis les lectures vives : un
  // « chargement » bascule tout seul en « choix des semaines » (ou « bloc ») dès que les
  // données arrivent, et une découpe destructive réussie le fait passer de « bloc » à
  // « semaines » sans un clic de plus (les versions supprimées → `blockGenerated` retombe).
  const pickerDecision = null === pickerFor ? null : decisionFor(pickerFor, false);
  const pickerState: WeekPickerState = null === pickerDecision ? "weeks" : (pickerStateOf(pickerDecision) ?? "weeks");
  // L'offre (semaines offertes + blocs exclus par les vacances) du picker ouvert et de la mère
  // pending — exposée pour que les surfaces ne recalculent plus (P2-40). P2-38 : on RETIRE en plus
  // les semaines qu'une fenêtre déjà planifiée recoupe, et on expose `plannedRanges` À CÔTÉ des
  // exclusions vacances (notions orthogonales — jamais fusionnées). `offerFor` (générique, consommé
  // par les cartes de couverture) reste INTACT : la prévention est bornée à la modale.
  const pickerBaseOffer: ClosureWeeksOffer = null === pickerFor ? { offered: [], excludedRanges: [] } : offerFor(pickerFor.startDate, pickerFor.endDate, pickerFor.periodType);
  const pendingBaseOffer: ClosureWeeksOffer = null === pendingMother ? { offered: [], excludedRanges: [] } : offerFor(pendingMother.startDate, pendingMother.endDate, pendingMother.periodType);
  const pickerOffer: PickerOffer = {
    offered: subtractPlannedWeeks(pickerBaseOffer.offered, plannedWindows),
    excludedRanges: pickerBaseOffer.excludedRanges,
    plannedRanges: null === pickerFor ? [] : plannedWindows,
  };
  const pendingOffer: PickerOffer = {
    offered: subtractPlannedWeeks(pendingBaseOffer.offered, plannedWindows),
    excludedRanges: pendingBaseOffer.excludedRanges,
    plannedRanges: null === pendingMother ? [] : plannedWindows,
  };
  const pendingDecision = null === pendingMother ? null : decisionForWindow(pendingMother.startDate, pendingMother.endDate, pendingMother.periodType, { alreadySplit: false, blockGenerated: false });
  const pendingPickerState: WeekPickerState = null === pendingDecision ? "weeks" : (pickerStateOf(pendingDecision) ?? "weeks");
  const pickerPlan = null === pickerFor ? null : planForEntry(pickerFor.id);
  const pickerVersions = versionsOf(pickerPlan?.id ?? null);
  const blockInfo: BlockVersionsInfo = {
    versionCount: pickerVersions.length,
    validated: null !== (pickerPlan?.chosenScheduleId ?? null),
    generationInFlight: pickerVersions.some((v) => IN_FLIGHT_STATUSES.includes(v.status)),
  };

  // La chaîne « supprimer les versions et découper en semaines » (décision fondateur) —
  // n'orchestre QUE des gestes existants : DELETE /api/schedules/{id} version par version.
  // Jamais le plan (le serveur l'emporte au découpage) ni l'entrée (elle doit survivre pour
  // être découpée). Échec PARTIEL → on reste dans l'état bloqué avec le compte à jour (pas de
  // fausse atomicité — même doctrine que le compteur d'échecs de la création de semaines).
  // Gardes défensives (le bouton les applique déjà côté UI, on ne s'y fie pas) : jamais sur un
  // bloc VALIDÉ (chaîne non atomique), jamais pendant une génération.
  const deleteBlockVersionsAndSplit = async (): Promise<void> => {
    if (null === pickerFor || null === pickerPlan || null !== pickerPlan.chosenScheduleId) {
      return;
    }
    const versions = versionsOf(pickerPlan.id);
    if (versions.some((v) => IN_FLIGHT_STATUSES.includes(v.status))) {
      return;
    }
    setBlockDeleteFailed(false);
    setBlockDeleting(true);
    let failed = 0;
    for (const version of versions) {
      try {
        await deleteSchedule.mutateAsync(version.id);
      } catch {
        failed += 1;
      }
    }
    setBlockDeleting(false);
    if (failed > 0) {
      setBlockDeleteFailed(true);
      toast.error(`${failed} version${failed > 1 ? "s" : ""} n'a pas pu être supprimée — réessayez.`);
    }
    // Succès total : useDeleteSchedule a invalidé « schedules » → le picker rebascule seul en
    // « choix des semaines » (pickerState réactif). Rien d'autre à faire (pas de navigation).
  };

  return {
    requestAdapt,
    needsPicker,
    // P2-40 — foyer UNIQUE de l'offre de semaines (closureWeeksOffer pour une fermeture,
    // periodWeeksToAdjust sinon), exposé aux surfaces pour qu'elles ne redérivent JAMAIS
    // l'exclusion des semaines sous vacances (A3 : la carte de couverture s'en sert).
    offerFor,
    pickerState,
    pickerOffer,
    pendingOffer,
    pendingPickerState,
    blockInfo,
    blockDeleting,
    blockDeleteFailed,
    deleteBlockVersionsAndSplit,
    pickerFor,
    setPickerFor,
    pendingMother,
    setPendingMother,
    openPendingPicker,
    createWeekChildren,
    createHoliday,
    createPeriodPlan,
    adaptBlock,
    pickWeeks,
    pickWeeksPending,
    adaptWholePending,
    recordPendingOnly,
    createOneWeek,
    windowConflict,
    resetWindowConflict,
  };
}
