import { useMutation, useQueries, useQuery, useQueryClient } from "@tanstack/react-query";
import { HTTPError } from "ky";

import { createConstraint } from "@/features/wizard/api";
import { errorMessage } from "@/shared/lib/errorMessage";
import { toast } from "@/shared/stores/toastStore";

import * as cockpitApi from "./api";
import type { CalendarEntry, CreateClosurePayload, CreateCutoffPayload, CreateEventPayload } from "./api";
import { asWindowAlreadyPlanned, PreviewTokenStaleError, WindowAlreadyPlannedError } from "./api";
import { frDateShort, segmentWeekCount, type WeekSegment } from "./lib/date";

/**
 * ADR-0002 inv. 4 (P2-38 PR3) — LE HOOK POSSÈDE SON FEEDBACK (patron `ownSlotEditFeedback`,
 * planning/queries). Le filet global `MutationCache.onError` (queryClient) ne toaste QUE les
 * mutations SANS onError de NIVEAU HOOK — un onError `mutate()` ne le désarme pas. Sans ce
 * onError-ci, un refus « une seule planification par fenêtre » tomberait dans le filet et
 * deviendrait « Problème de connexion » (mensonger). Ici on TAIT le refus (le dialogue l'affiche
 * comme une proposition) et on ne parle que d'un vrai échec transport — remplaçant le filet,
 * jamais le doublant.
 */
function ownWindowConflictFeedback(error: unknown): void {
  if (error instanceof WindowAlreadyPlannedError || error instanceof PreviewTokenStaleError) {
    // Le dialogue du geste affiche ces deux refus lui-même (WindowAlreadyPlannedNotice pour le
    // chevauchement ; WarningPanel + ré-aperçu pour le jeton périmé, D3 v2) — jamais un toast.
    return;
  }
  void errorMessage(error).then((message) => toast.error(message));
}

export function useCalendarEntries(from: string, to: string, enabled = true) {
  return useQuery({
    queryKey: ["calendar-entries", from, to],
    queryFn: () => cockpitApi.getCalendarEntries(from, to),
    enabled,
    staleTime: 30_000,
  });
}

export function useCalendarEntry(id: string | null) {
  return useQuery({
    // Under the "calendar-entries" prefix so the shared invalidation (creation,
    // overlay generation, reopen) also refreshes the detail — a singular key
    // kept a stale plan pointer (chosenScheduleId) for 30s after validating.
    queryKey: ["calendar-entries", "detail", id],
    queryFn: () => cockpitApi.getCalendarEntry(id as string),
    enabled: null !== id,
    staleTime: 30_000,
    // A 404 means the entry was deleted — the wizard exits period mode cleanly
    // (WizardPage effect) instead of the global net toasting a raw error.
    meta: { silent404: true },
  });
}

/**
 * Le plan d'une période (ADR-0002 lot C). Il naît avec le geste « ajuster cette
 * période », donc il est là dès que l'entrée existe — inutile d'attendre une
 * génération. Porte les réglages de la période (inv. 5), dont le garde de seed
 * `teamSelectionInitialized`.
 *
 * Le flag `teamSelectionInitialized` bascule côté SERVEUR au 1er override, sans
 * mutation directe sur le plan : aucune invalidation ne le rafraîchit (les mutations
 * d'override n'invalident que ["wizard", "team_period_overrides", …]). Ce qui protège
 * le seed d'un double déclenchement, c'est le garde `periodSeedWasClaimed` — pas cette
 * clé. Ne pas retirer ce garde en croyant qu'un refetch prend le relais.
 */
export function useSchedulePlanForEntry(calendarEntryId: string | null) {
  return useQuery({
    queryKey: ["calendar-entries", "plan", calendarEntryId],
    queryFn: () => cockpitApi.getSchedulePlanForEntry(calendarEntryId as string),
    enabled: null !== calendarEntryId,
    staleTime: 30_000,
  });
}

/**
 * Tous les plans de la saison — le radar y lit, PAR PÉRIODE, la « version active »
 * (chosenScheduleId, ADR-0002 lot D-b). Sous le préfixe "calendar-entries" pour que
 * l'invalidation partagée (génération d'overlay, validation, reopen) le rafraîchisse.
 */
export function useSchedulePlans() {
  return useQuery({
    queryKey: ["calendar-entries", "plans"],
    queryFn: () => cockpitApi.getAllSchedulePlans(),
    staleTime: 30_000,
  });
}

/**
 * L'ANCRE des réglages d'une période, et son état — ADR-0002 inv. 5 (lots C2-C3).
 *
 * À utiliser PARTOUT plutôt que `useSchedulePlanForEntry(x).data?.id ?? null`. Cet idiome
 * nu a produit deux bugs en deux rounds de review, et toujours le même : il écrase
 * « le plan n'est pas encore résolu » et « mode socle » dans le même `null`.
 *
 * Or `null` est une ancre LÉGITIME — elle veut dire « ligne de base », structure partagée
 * (inv. 6) — que le serveur ne peut pas refuser. Écrire pendant la fenêtre de chargement
 * pose donc le réglage SUR LE SOCLE DU CLUB : le gymnase prêté pour une semaine de
 * vacances devient un créneau permanent, nourrit toutes les générations de la saison, et
 * se transmet à N+1. Aucune erreur, aucun signal.
 *
 * **UNION DISCRIMINÉE, PAS UN DRAPEAU** (P4-20). Un booléen `ready` recouvrait trois
 * situations (ça charge / ça a échoué / pas de plan) et se laissait oublier : quatre fois
 * en quatre revues, dont une en tentant de corriger la ligne par un `isError` ajouté au
 * tuple — même contrat facultatif, même oubli. Ici l'appelant doit NOMMER le cas :
 *
 *  - `loading` — la réponse n'est pas encore là ;
 *  - `failed`  — le GET a échoué ; `retry` relance ;
 *  - `absent`  — le GET a RÉUSSI et cette période n'a aucun plan (jamais adaptée) ;
 *  - `base`    — hors mode période : `null` EST la bonne ancre (structure partagée) ;
 *  - `period`  — l'ancre est certaine.
 *
 * `absent` est distinct de `loading` À DESSEIN : les confondre rendait un spinner
 * ÉTERNEL sur une période sans plan (une semaine-enfant dont la ligne manque, une
 * entrée rouverte depuis un cache périmé) — le cul-de-sac même que la ligne visait.
 * Une attente qui ne finit jamais est un bug, pas un état.
 *
 * **Écrire n'est licite que sur `period` (ou `base` hors période)** — cf. `anchorIsWritable`.
 * Lire est sans risque (la requête est désactivée), mais l'écran doit alors dire LEQUEL
 * des deux : une liste vide affirmerait « aucun réglage » et pousserait le gestionnaire à
 * re-saisir… donc à déclencher l'écriture corrompue. Les écrans passent par
 * `PeriodAnchorGate`, qui ne livre le `planId` qu'après avoir traité `loading` et `failed`.
 */
export type PeriodAnchor =
  | { state: "loading"; planId: null }
  | { state: "failed"; planId: null; retry: () => void }
  | { state: "absent"; planId: null }
  | { state: "base"; planId: null }
  | { state: "period"; planId: string };

export function usePeriodAnchor(calendarEntryId: string | null): PeriodAnchor {
  const { data, isSuccess, isFetching, isError, refetch } = useSchedulePlanForEntry(calendarEntryId);
  const planId = data?.id ?? null;

  // Hors mode période : aucune requête n'est lancée, `null` est la réponse.
  if (null === calendarEntryId) {
    return { state: "base", planId: null };
  }
  // Une ancre EN CACHE reste valide même si un refetch d'arrière-plan a échoué :
  // react-query conserve `data` et bascule `status` à error (d'où `isRefetchError`).
  // Basculer l'écran en erreur ici détruirait une vue qui fonctionne — `queryClient.ts`
  // applique déjà ce raisonnement à ses toasts.
  if (null !== planId) {
    return { state: "period", planId };
  }
  if (isError) {
    return {
      state: "failed",
      planId: null,
      retry: () => {
        void refetch();
      },
    };
  }
  // `absent` ne se conclut que sur une réponse RÉUSSIE et AU REPOS :
  //  - `isSuccess` — une query jamais résolue (offline : fetchStatus `paused`,
  //    isFetching faux) n'est PAS « pas de plan », c'est « on ne sait pas » ;
  //  - `!isFetching` — après « Adapter », la clé est invalidée et re-fetchée avec
  //    un `null` PÉRIMÉ en cache : conclure disait « adaptez cette période » un
  //    clic après que le gestionnaire l'a fait.
  if (isSuccess && !isFetching) {
    return { state: "absent", planId: null };
  }

  return { state: "loading", planId: null };
}

/** L'écriture d'un réglage n'est licite que sur une ancre CERTAINE. */
export function anchorIsWritable(anchor: PeriodAnchor): boolean {
  return "period" === anchor.state || "base" === anchor.state;
}

/**
 * School holidays. Without a window → the season default (radar, season-wide).
 * With a [from, to] → that window (the calendar's visible month, so summer and
 * any month outside the season are shown when browsed).
 */
export function useSchoolHolidays(from?: string, to?: string) {
  return useQuery({
    queryKey: ["school-holidays", from ?? null, to ?? null],
    queryFn: () => cockpitApi.getSchoolHolidays(from, to),
    staleTime: 3_600_000,
  });
}

export function usePublicHolidays(from: string, to: string) {
  return useQuery({
    queryKey: ["public-holidays", from, to],
    queryFn: () => cockpitApi.getPublicHolidays(from, to),
    staleTime: 3_600_000,
  });
}

/**
 * Les conflits de PLUSIEURS périodes d'un coup. Le radar est une liste « à traiter » :
 * il ne peut décider de masquer une fermeture sans impact qu'en connaissant l'impact,
 * or seul le serveur le sait. Même queryKey que useEntryConflicts → le cache dédoublonne,
 * la carte enfant ne refait aucune requête.
 */
export function useEntryConflictsList(entryIds: string[]) {
  return useQueries({
    queries: entryIds.map((entryId) => ({
      queryKey: ["entry-conflicts", entryId],
      queryFn: () => cockpitApi.getEntryConflicts(entryId),
      staleTime: 30_000,
    })),
  });
}

export function useEntryConflicts(entryId: string | null) {
  return useQuery({
    queryKey: ["entry-conflicts", entryId],
    queryFn: () => cockpitApi.getEntryConflicts(entryId as string),
    enabled: null !== entryId,
    staleTime: 30_000,
  });
}

function invalidateEntries(queryClient: ReturnType<typeof useQueryClient>): void {
  void queryClient.invalidateQueries({ queryKey: ["calendar-entries"] });
}

/** P2-38 (prévention) — une création de plan/semaines CHANGE le verdict « déjà planifié » : on
 *  périme toute lecture de `planned-windows` pour que la modale rouverte reflète la nouvelle réalité. */
function invalidatePlannedWindows(queryClient: ReturnType<typeof useQueryClient>): void {
  void queryClient.invalidateQueries({ queryKey: ["planned-windows"] });
}

/** De quoi interroger `planned-windows` : la fenêtre + la référence (entrée matérialisée OU saison
 *  pour une mère pending). `null` ⇒ la requête est désactivée (aucun picker ouvert). */
export interface PlannedWindowsRef {
  start: string;
  end: string;
  entryId?: string;
  seasonId?: string;
}

/**
 * P2-38 (prévention, étapes 4-6) — LES FENÊTRES DÉJÀ PLANIFIÉES par un AUTRE plan de période sur la
 * fenêtre visée. Activée SEULEMENT quand un picker est ouvert (`ref` non nul) : aucun fetch sur les
 * chemins sans modale — la prévention est un confort borné à la modale, le 409 reste la garde.
 */
export function usePlannedWindows(ref: PlannedWindowsRef | null) {
  const start = ref?.start ?? "";
  const end = ref?.end ?? "";
  const entryId = ref?.entryId;
  const seasonId = ref?.seasonId;
  return useQuery({
    queryKey: ["planned-windows", start, end, entryId ?? seasonId ?? null],
    queryFn: () => cockpitApi.getPlannedWindows({ start, end, entryId, seasonId }),
    enabled: null !== ref,
    staleTime: 30_000,
  });
}

/**
 * LE GESTE « Adapter » (ADR-0002 amendé 2026-07-24) : crée le plan de la période
 * AVANT d'ouvrir le wizard — une période n'a plus de plan à sa matérialisation,
 * et usePeriodAnchor attendrait à l'infini sans lui. Idempotent côté serveur.
 * L'invalidation du préfixe ["calendar-entries"] couvre "plan"/"plans".
 */
export function useCreatePeriodPlan() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (calendarEntryId: string) => cockpitApi.createSchedulePlan(calendarEntryId),
    onSuccess: () => {
      invalidateEntries(queryClient);
      invalidatePlannedWindows(queryClient);
    },
    // Le hook possède son feedback : un refus de chevauchement (P2-38) est TU ici (le dialogue
    // l'affiche), tout autre échec remplace le toast du filet global.
    onError: ownWindowConflictFeedback,
  });
}

export function useCreateEvent() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateEventPayload) =>
      cockpitApi.createCalendarEntry({
        kind: "event",
        title: payload.title,
        startDate: payload.startDate,
        endDate: payload.endDate,
        isDisruptive: payload.isDisruptive,
      }),
    onSuccess: () => invalidateEntries(queryClient),
  });
}

/**
 * A venue closure is a period entry PLUS a dated FACILITY constraint that carries
 * the closed venue. Two calls: if the constraint fails, roll back the entry. If
 * the rollback ALSO fails, surface a distinct error so the orphan period (a ⛔
 * marker with no closed-venue constraint) is not hidden behind a generic failure.
 */
export function useCreateVenueClosure() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (payload: CreateClosurePayload) => {
      const entry = await cockpitApi.createCalendarEntry({
        kind: "period",
        periodType: "closure",
        title: payload.title,
        startDate: payload.startDate,
        endDate: payload.endDate,
      });
      try {
        await createConstraint({
          name: payload.title,
          scope: "FACILITY",
          scopeTargetId: payload.venueId,
          family: "FACILITY",
          ruleType: "HARD",
          config: { type: "venue_closed", startDate: payload.startDate, endDate: payload.endDate },
          calendarEntryId: entry.id,
        });
      } catch (error) {
        try {
          await cockpitApi.deleteCalendarEntry(entry.id);
        } catch {
          // AUD-UXC-13 « salle » → « gymnase ». Et le tutoiement isolé part avec (même axe que
          // UXC-11) : la phrase était réécrite de toute façon.
          throw new Error("Le gymnase n'a pas pu être bloqué et l'annulation a échoué — supprimez la période à la main.");
        }
        throw error;
      }
      return entry;
    },
    // Hook-level (unmount-safe): surfaces the tailored rollback message even if
    // the DayDialog was closed while the two-call sequence was in flight.
    onError: (error) => {
      if (error instanceof Error && !("response" in error)) {
        toast.error(error.message);
        return;
      }
      void errorMessage(error).then((message) => toast.error(message));
    },
    onSuccess: () => invalidateEntries(queryClient),
  });
}

/** "Adapter" on a school holiday first materialises it as a period entry (holiday), then period mode adapts it. */
export function useCreateHolidayPeriod() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (holiday: { schoolHolidayId: string; label: string; startDate: string; endDate: string }) =>
      cockpitApi.createCalendarEntry({
        kind: "period",
        periodType: "holiday",
        // Décision fondateur 2026-08-23 — un TITRE de période porte sa fenêtre. Le nom du plan
        // NAÎT de ce titre côté backend, donc la date doit y être : le gestionnaire confirme d'un
        // coup d'œil qu'il n'a pas visé la mauvaise vacance. Le label seul (« Vacances de la
        // Toussaint ») ne la portait pas ; on l'enrichit « — du {début} au {fin} », même patron que
        // les titres de segments-enfants (useCreateWeekChildren) et même helper de dates.
        title: `${holiday.label} — du ${frDateShort(holiday.startDate)} au ${frDateShort(holiday.endDate)}`.slice(0, 180),
        startDate: holiday.startDate,
        endDate: holiday.endDate,
        schoolHolidayId: holiday.schoolHolidayId,
      }),
    onSuccess: () => invalidateEntries(queryClient),
  });
}

/** Le 422 « cette semaine existe déjà » (chevauchement) — le SEUL sauté par
 *  useCreateWeekChildren : l'état visé existe. Tout autre 422 (titre trop long, mère
 *  générée en bloc, type) est une vraie erreur (revue #262 round 2). Match sur le
 *  noyau stable « déjà découpée » (moins fragile qu'un reword ; couplage au message
 *  serveur assumé tant qu'il n'y a pas de code machine — revue #262 round 3). */
async function isAlreadySplit422(error: unknown): Promise<boolean> {
  if (!(error instanceof HTTPError) || 422 !== error.response.status) {
    return false;
  }
  try {
    const body: unknown = await error.response.clone().json();
    const detail = "object" === typeof body && null !== body && "detail" in body && "string" === typeof body.detail ? body.detail : "";
    return detail.includes("déjà découpée");
  } catch {
    return false;
  }
}

export interface WeekChildrenResult {
  created: CalendarEntry[];
  /** Semaines en ÉCHEC RÉEL (hors « existe déjà ») — l'appelant doit le dire. */
  failedCount: number;
}

/**
 * P2-5 E1 / P2-41 — découpe une période mère en SEGMENTS : une entrée ENFANT par segment coché
 * (parentEntryId), type hérité, fenêtre = bornes du segment (clamp saison conservé). Un segment de
 * taille 1 garde le titre E6 historique (« {mère} — semaine du {lundi} ») ; un segment multi-semaines
 * prend « {mère} — semaines du {X} au {Y} ». Chaque enfant naît avec son plan (rail 1 entrée = 1 plan).
 *
 * Reprenable (revue #262) : seul le 422 « chevauche une semaine déjà découpée »
 * est sauté (l'état visé existe — un retry ne meurt plus dessus) ; toute autre
 * erreur est comptée (failedCount) et relevée si RIEN n'a été créé. Invalidation
 * en onSettled : même un échec partiel rafraîchit le cache (les enfants créés
 * apparaissent, les chips « à créer » listent les manquantes). Titre borné à 180
 * (colonne title) — un titre de mère long ne fait plus 422 chaque segment.
 */
export function useCreateWeekChildren() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { mother: CalendarEntry; segments: WeekSegment[] }): Promise<WeekChildrenResult> => {
      const created: CalendarEntry[] = [];
      let failedCount = 0;
      let firstHardError: unknown = null;
      for (const segment of payload.segments) {
        const title =
          segmentWeekCount(segment) > 1
            ? `${payload.mother.title} — semaines du ${frDateShort(segment.monday)} au ${frDateShort(segment.endDate)}`
            : `${payload.mother.title} — semaine du ${frDateShort(segment.monday)}`;
        try {
          created.push(
            await cockpitApi.createCalendarEntry({
              kind: "period",
              periodType: payload.mother.periodType,
              title: title.slice(0, 180),
              startDate: segment.startDate,
              endDate: segment.endDate,
              parentEntryId: payload.mother.id,
            }),
          );
        } catch (error) {
          if (await isAlreadySplit422(error)) {
            continue;
          }
          // Une semaine dont la fenêtre est déjà gouvernée par un AUTRE plan (409, P2-38) : on
          // ABANDONNE le lot et on remonte le refus typé — le dialogue le propose (ouvrir /
          // supprimer / découper), plutôt que de compter un échec muet noyé dans failedCount.
          const conflict = asWindowAlreadyPlanned(error);
          if (null !== conflict) {
            throw conflict;
          }
          failedCount += 1;
          firstHardError = firstHardError ?? error;
        }
      }
      if (null !== firstHardError && 0 === created.length) {
        throw firstHardError;
      }
      return { created, failedCount };
    },
    onSettled: () => {
      invalidateEntries(queryClient);
      invalidatePlannedWindows(queryClient);
    },
    // Le hook possède son feedback : le refus de chevauchement (P2-38) est TU (le picker/l'encart
    // l'affiche), tout autre échec remplace le toast du filet global.
    onError: ownWindowConflictFeedback,
  });
}

/** A cutoff means "no training on the window" — a bare period entry, no dated constraint, never an overlay. */
export function useCreateCutoff() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateCutoffPayload) =>
      cockpitApi.createCalendarEntry({
        kind: "period",
        periodType: "cutoff",
        title: payload.title,
        startDate: payload.startDate,
        endDate: payload.endDate,
      }),
    onSuccess: () => invalidateEntries(queryClient),
  });
}

export function useDeleteEntry() {
  const queryClient = useQueryClient();
  return useMutation({
    // The backend cascades the entry's dated constraints AND its overlay
    // schedule on delete → schedules and conflicts must refresh too, or the
    // baseline banner keeps counting a ghost overlay ("Voir le plan" → 404).
    mutationFn: (id: string) => cockpitApi.deleteCalendarEntry(id),
    onSuccess: () => {
      invalidateEntries(queryClient);
      void queryClient.invalidateQueries({ queryKey: ["schedules"] });
      void queryClient.invalidateQueries({ queryKey: ["entry-conflicts"] });
    },
  });
}

/**
 * D3 v1 PR-2 — RE-DATER une racine fermeture à plan (PUT). Le serveur déplace le plan (les
 * versions survivent, marquées à régénérer) et les contraintes appariées : on périme donc les
 * mêmes clés que la suppression (calendar-entries, schedules, entry-conflicts) plus planned-windows
 * (le verdict « déjà planifié » change avec la fenêtre). Le hook POSSÈDE son feedback (patron
 * `ownWindowConflictFeedback`) : un refus de chevauchement (409 typé) est TU ici — le mode
 * re-datage l'affiche comme une proposition (`WindowAlreadyPlannedNotice`) ; tout autre échec (422
 * hors saison / fin avant début) remplace le toast du filet global par le message serveur.
 */
export function useRedateEntry() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (vars: { entry: CalendarEntry; startDate: string; endDate: string; previewToken?: string }) =>
      cockpitApi.updateCalendarEntry(vars.entry, { startDate: vars.startDate, endDate: vars.endDate, previewToken: vars.previewToken }),
    onSuccess: () => {
      invalidateEntries(queryClient);
      void queryClient.invalidateQueries({ queryKey: ["schedules"] });
      void queryClient.invalidateQueries({ queryKey: ["entry-conflicts"] });
      invalidatePlannedWindows(queryClient);
    },
    onError: ownWindowConflictFeedback,
  });
}

/**
 * D3 v2 (P4-174) — l'APERÇU des effets du re-datage d'une mère découpée. Lecture pure : aucune
 * invalidation de cache (rien n'est écrit tant que l'utilisateur n'a pas confirmé). Le geste
 * possède son feedback (le 422 s'affiche DANS le formulaire), donc pas de onError de niveau hook.
 */
export function useRedatePreview() {
  return useMutation({
    mutationFn: (vars: { id: string; startDate: string; endDate: string }) =>
      cockpitApi.previewRedate(vars.id, { startDate: vars.startDate, endDate: vars.endDate }),
  });
}
