import { HTTPError, TimeoutError } from "ky";

import { api } from "@/shared/api/client";
import { sortByName } from "@/shared/lib/nameOrder";
import type { ScheduleStatus } from "@/shared/lib/scheduleStatus";

/**
 * Planning read API. Tenant (club) + active season are resolved server-side from
 * the JWT (BL2) — no header is sent. Collections come back as API Platform
 * JSON-LD (`{ member: [...] }`); `collection()` also tolerates a plain array.
 */
async function collection<T>(path: string, searchParams?: Record<string, string>): Promise<T[]> {
  const raw = await api.get(path, searchParams ? { searchParams } : undefined).json<unknown>();
  if (Array.isArray(raw)) {
    return raw as T[];
  }
  if (null !== raw && typeof raw === "object" && Array.isArray((raw as { member?: unknown }).member)) {
    return (raw as { member: T[] }).member;
  }
  return [];
}

const PAGE_SIZE = 30;

/**
 * Reference collections are paginated (30/page) and unfiltered — page through them
 * so every club row is resolved (team/venue/coach names). Dedupe by id and stop on
 * a short page or a page that adds nothing new (guards against a no-op `page` param).
 */
async function collectionAll<T extends { id: string }>(path: string, searchParams?: Record<string, string>): Promise<T[]> {
  const seen = new Set<string>();
  const all: T[] = [];
  for (let page = 1; page <= 50; page += 1) {
    const batch = await collection<T>(path, { ...searchParams, page: String(page) });
    const fresh = batch.filter((item) => !seen.has(item.id));
    for (const item of fresh) {
      seen.add(item.id);
      all.push(item);
    }
    if (batch.length < PAGE_SIZE || 0 === fresh.length) {
      break;
    }
  }
  return all;
}

/** Canonical FR labels for a schedule status (toolbar + cockpit banner). */
export const STATUS_LABELS: Record<ScheduleStatus, string> = {
  DRAFT: "Brouillon",
  PENDING: "En attente",
  GENERATING: "Génération…",
  COMPLETED: "Terminé",
  FAILED: "Échec",
};
// ENG-21: SOFT is not a supported lock (it had no solver effect); only NONE/HARD.
/**
 * ⚠ Volontairement PLUS ÉTROIT que l'enum PHP, qui porte aussi `SOFT` : le verrou souple est
 * un placebo (le solveur ne lit jamais sa pénalité) et l'API le REFUSE en le nommant
 * (ENG-21). Le cas existe côté serveur pour pouvoir rendre « use NONE or HARD » plutôt qu'un
 * « Invalid lockLevel » générique ; l'UI, elle, ne doit pas offrir un verrou sans effet.
 * L'écart est déclaré dans `TsUnionsMatchPhpEnumsTest::DELIBERATE_GAPS` — ne pas « aligner »
 * cette union sans lire cette raison : on casserait soit le message d'erreur, soit l'écran.
 */
export type LockLevel = "NONE" | "HARD";
/**
 * Pourquoi un créneau est verrouillé (F1) — la réponse que `lockLevel` seul ne donne pas.
 * `RESERVATION` = né d'une réservation de gymnase (on n'y touche pas) ; `MANUAL` = épinglé
 * à la main (on peut le retirer) ; `UNKNOWN` = verrouillé, origine indécidable (une
 * ignorance, PAS une absence de verrou). `null` sur l'API quand le créneau n'est pas
 * verrouillé. Jumelle de l'enum PHP `LockOrigin` (gardée par `TsUnionsMatchPhpEnumsTest`).
 */
export type LockOrigin = "RESERVATION" | "MANUAL" | "UNKNOWN";
export type DiagnosticSeverity = "ERROR" | "WARNING" | "INFO" | "SUCCESS";
/** ADR-0002: a plan's type — SEASON is the socle, CLOSURE/HOLIDAY are period overlays. */
export type SchedulePlanType = "SEASON" | "CLOSURE" | "HOLIDAY";

/**
 * P2-8 : les permissions d'une version, calculées SERVEUR par le même code que les
 * gardes d'écriture (`ScheduleCapabilityResolver`) — « capacité affichée == verdict du
 * refus ». Le front ne re-dérive plus ces règles. Absent (`null`) sur les réponses
 * d'écriture (POST/PUT, le front refetch la collection) et fail-closed sur cache périmé :
 * un geste ne s'offre que sur `=== true`, jamais par défaut permissif.
 *  - canDelete         : supprimer cette version (choisie / en vol / seule terminée de saison → false)
 *  - canValidate       : la valider (terminée, aucune sœur en vol ; la choisie reste validable — no-op)
 *  - canRegenerateFrom : recharger sa structure (socle terminé non-choisi, rien en cours, photo présente)
 *  - versionsDeletedOnValidate : nb de sœurs supprimées si l'on valide (annoncé avant le clic)
 *  - overlaysDroppedOnValidate : nb de plannings de période détruits si l'on valide (= count du 409 overlays_exist)
 */
export interface ScheduleCapabilities {
  canDelete: boolean;
  canValidate: boolean;
  canRegenerateFrom: boolean;
  versionsDeletedOnValidate: number;
  overlaysDroppedOnValidate: number;
}
// isSeasonPlanType vit dans ./lib/versions (module PUR, jamais mocké) : le définir ici,
// où plusieurs tests posent un vi.mock("./api") manuel, le rendait indéfini sous le mock.

export interface Schedule {
  id: string;
  name: string;
  status: ScheduleStatus;
  score: number | null;
  /**
   * F2b : ce planning a-t-il été retouché à la main (déplacement de créneau) depuis sa
   * génération ? Vrai ⇒ le `score` ci-dessus est PÉRIMÉ (le placement a changé, pas le
   * score) — l'écran le dit. Remis à faux par une (re)génération. Absent (undefined) sur
   * une réponse d'écriture nue, traité comme faux.
   */
  manuallyEditedSinceGeneration?: boolean;
  /**
   * F2c : une contrainte a-t-elle changé (créée, modifiée, supprimée) depuis la génération de
   * ce planning ? Vrai ⇒ le planning décrit un état ANTÉRIEUR des règles — pas faux, mais
   * PÉRIMÉ ; l'écran le dit (bannière unifiée avec « retouché à la main »). Remis à faux par
   * une (re)génération. Absent (undefined) sur une réponse d'écriture nue, traité comme faux.
   */
  constraintsChangedSinceGeneration?: boolean;
  /**
   * P4-87 : une DONNÉE DU CLUB autre qu'une contrainte (gymnase, coach, créneau/grille de
   * période, réservation, override, tag d'équipe, calendrier) a-t-elle changé depuis la
   * génération ? Vrai ⇒ le planning décrit un état ANTÉRIEUR des données — pas faux, PÉRIMÉ ;
   * l'écran le dit (même bannière unifiée). Remis à faux par une (re)génération. Absent
   * (undefined) sur une réponse d'écriture nue, traité comme faux.
   */
  resourcesChangedSinceGeneration?: boolean;
  createdAt: string;
  updatedAt: string;
  /**
   * ADR-0002 C4: the type of THIS version's plan. "SEASON" = the socle (base plan);
   * "CLOSURE"/"HOLIDAY" = a period overlay. Replaces the removed `calendarEntryId` for
   * the "is this an overlay?" question. null only for an anomalous unlinked version.
   */
  planType: SchedulePlanType | null;
  /** ADR-0002: the SchedulePlan this version belongs to — versions of one plan share it
   *  (season versions share the season plan; a period's versions share its plan). Groups the
   *  version selector, replacing the removed `calendarEntryId`. */
  schedulePlanId: string | null;
  /** PDF export lifecycle (async worker): null | pending | generating | completed | failed. */
  pdfExportStatus?: string | null;
  pdfExportUrl?: string | null;
  /** Teams in the frozen solve input — divergence banner ("générée avec N équipes"). */
  generatedTeamCount?: number | null;
  /** Hash of the frozen solve input for this version. */
  snapshotHash?: string | null;
  /** D3: carries a restorable structure photo → "Charger cette version" can succeed (pre-D2 plans have none). */
  hasStructurePhoto?: boolean;
  /** ★ : this version's structure is the season's currently loaded context (set server-side). */
  isLiveContext?: boolean;
  /**
   * ADR-0002 inv. 1: this version's plan POINTS at it — it is the one in force.
   * That is the whole of "validated"; no status says it. Works for the season's
   * calendar and a period's overlay alike (set server-side).
   */
  isChosen?: boolean;
  /**
   * P2-8: server-computed permissions for THIS version — the front reads them
   * instead of re-deriving the write guards. null on write responses (POST/PUT,
   * the front refetches the collection) and treated fail-closed when absent.
   */
  capabilities?: ScheduleCapabilities | null;
}

/** Export scope: all venues (null) or a single one. */
export type ExportVenueScope = string | null;

/** Queue a PDF export (async worker). Poll the schedule for pdfExportStatus/Url. */
export const exportSchedulePdf = (id: string, venueId: ExportVenueScope): Promise<unknown> =>
  api
    .post(`schedules/${id}/export-pdf`, {
      // Le corps reste VIDE dans le cas par défaut : mêmes octets qu'avant P3-20.
      json: null === venueId ? {} : { venueId },
    })
    .json();

/** Download the schedule as an .xlsx (synchronous stream). Returns the blob. */
export const exportScheduleXlsx = async (id: string, venueId: ExportVenueScope): Promise<Blob> =>
  api.post(`schedules/${id}/export-xlsx`, { json: null === venueId ? {} : { venueId } }).blob();

export interface Slot {
  id: string;
  scheduleId: string;
  teamId: string;
  venueId: string;
  coachId: string | null;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
  lockLevel: LockLevel;
  /** Pourquoi ce créneau est verrouillé (F1). `null` quand il ne l'est pas. */
  lockOrigin: LockOrigin | null;
}

/** Un placement (où, quand) — l'identité d'un écart (P2-44 PR-5) ; ni durée ni coach. */
export interface SocleDeviationPlacement {
  dayOfWeek: number;
  startTime: string;
  venueId: string;
}

/** Une séance DÉPLACÉE : du placement du socle (`from`) vers celui de la période (`to`). */
export interface SocleDeviationMoved {
  teamId: string;
  from: SocleDeviationPlacement;
  to: SocleDeviationPlacement;
}

/**
 * Une séance du socle NON REPLACÉE dans la période. `reason` est SERVIE par le backend
 * (`team_reduced` | `venue_disabled` | `venue_closed`), ou `null` quand la sélection ne l'explique
 * pas (suppression manuelle) — jamais fabriquée. Le front rend alors la ligne sans étiquette.
 */
export interface SocleDeviationUnplaced {
  teamId: string;
  dayOfWeek: number;
  startTime: string;
  venueId: string;
  reason: string | null;
}

/**
 * P2-44 PR-5 (ADR-0004) — les ÉCARTS NOMMÉS d'une version de plan de FERMETURE vs la version pointée
 * du socle, SERVIS par `GET /schedules/{id}/socle-deviation`. Deux catégories seulement (déplacée /
 * non replacée) ; le front NOMME (agrégat + ligne à ligne), il ne redérive RIEN.
 */
export interface SocleDeviation {
  socleScheduleId: string;
  moved: SocleDeviationMoved[];
  unplaced: SocleDeviationUnplaced[];
}

/** Les 7 familles de cause qu'un `session_below_effective_min` peut porter (contrat 2.8,
 *  `DiagnosticCauseSchema.kind`). Fermée côté moteur ; le front la traite en `string` (cf.
 *  `DiagnosticCause.kind`) pour dégrader proprement sur un kind futur qu'il ne connaît pas
 *  encore — la table de libellés reste, elle, exhaustive sur ces 7. */
export type DiagnosticCauseKind =
  | "hard_lock"
  | "venue_forbidden"
  | "coach_unavailability"
  | "time_window"
  | "day_conflict"
  | "day_forbidden"
  | "forced_venue_elsewhere";

/** Une cause MESURÉE (pas re-dérivée) d'un créneau fermé pour l'équipe. Le backend a
 *  déjà ramené `constraintId` à l'UUID de l'entité (il avait suffixé les CLUB→TEAM) : c'est
 *  la cible directe du deep-link `?edit=<id>`. `constraintId`/`label` sont `null` quand la
 *  contrainte source n'a pas pu être nommée — la cause s'affiche alors sans lien. */
export interface DiagnosticCause {
  /** `string` et non l'union : une donnée future doit rester représentable et s'afficher
   *  dégradée, jamais faire mentir le type ni planter le rendu. */
  kind: string;
  constraintId: string | null;
  label: string | null;
  /** Nombre de créneaux candidats que cette cause a fermés pour l'équipe. */
  count: number;
}

export interface Diagnostic {
  id: string;
  scheduleId: string;
  type: string;
  severity: DiagnosticSeverity;
  teamId: string | null;
  coachId: string | null;
  venueId: string | null;
  /** Jour (ISO 1-7) + heure « HH:MM » de la séance fautive : renseignés SEULEMENT par un
   *  `conflict` (l'engine les porte, cf. schéma 2.6), null sur les 10 autres types. Avec
   *  venueId, ils identifient LE créneau à ouvrir sur la grille. */
  dayOfWeek: number | null;
  startTime: string | null;
  /** Clé de la règle implicite « bien-être » assouplie — renseignée SEULEMENT par un
   *  `implicit_rule_not_honored` (l'engine la porte, cf. contrat 2.7), null partout ailleurs.
   *  Elle cible le réglage à ajuster dans le wizard (`?step=constraints&rule=<ruleKey>`). */
  ruleKey: string | null;
  message: string;
  suggestions: unknown;
  /** Causes MESURÉES d'une séance manquante — renseignées SEULEMENT par un
   *  `session_below_effective_min` (contrat 2.8), `[]` sur les autres types. */
  causes: DiagnosticCause[];
  /** Créneaux restés OUVERTS (rien ne les a fermés, l'arbitrage a placé autre chose).
   *  ⚠ `null` = non mesuré, `0` = aucun n'est resté ouvert — deux choses différentes. */
  openCandidates: number | null;
}

export interface Team {
  id: string;
  name: string;
  sportCategoryId: string;
  /** Priority tier (1=S…5=D) + manual order within it — drives the canonical
   *  team ordering (see shared/lib/teamTiers). Exposed by TeamResource. */
  priorityTierId: number;
  tierOrder: number;
  /** P2-30 : nombre de séances attendues par semaine (seuil de dérive). Exposé par
   *  TeamResource ; le plan de PÉRIODE peut le surcharger (override). Le front l'AFFICHE
   *  (compte des séances à replacer), il ne décide d'aucune règle. */
  sessionsPerWeek: number;
}

export interface Venue {
  id: string;
  name: string;
  color: string | null;
}

/** A venue availability window (defined in the wizard). A window with no team
 *  placement in the schedule is an "empty slot" surfaced in the grid. */
export interface VenueTrainingSlot {
  id: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
  capacity: number;
  /** P2-17 — libellé d'un créneau mutualisé (« CEC3 »), null/absent quand il n'en porte pas.
   *  Purement esthétique : titre la carte fusionnée de la vue gymnase. */
  groupLabel?: string | null;
}

export interface Coach {
  id: string;
  firstName: string;
  lastName: string;
}

export interface Category {
  id: string;
  name: string;
}

export interface TeamCoach {
  id: string;
  teamId: string;
  coachId: string;
  role: "MAIN" | "ASSISTANT";
}

export interface CoachPlayerMembership {
  id: string;
  teamId: string;
  coachId: string;
  isActive: boolean;
}

export interface SlotMovePatch {
  dayOfWeek?: number;
  startTime?: string;
  venueId?: string;
  /** P2-30 : éviction OPTIONNELLE — l'id du créneau occupant la cible, à retirer pour
   *  laisser la place. Absent = déplacement vers une cible libre. */
  evictSlotId?: string;
  /** P2-32 : un ESSAI — le moteur juge (verdict + compromis) SANS rien écrire. Le déplacement
   *  n'a pas lieu ; la réponse porte `dryRun: true`. `true` seul (jamais `false` : on n'envoie
   *  le champ que pour un essai). */
  dryRun?: true;
}

/**
 * P2-32 — un COMPROMIS nommé d'un candidat ACCEPTÉ : une préférence SOUPLE que ce geste
 * CASSE (`broken`) ou RÉTABLIT (`gained`). `family` = la famille de règle (regroupement) ;
 * `effect` distingue recul et gain ; `message` est déjà HUMAIN (le moteur y nomme équipe/
 * coach/gymnase, AUCUN identifiant interne) ; les ids sont optionnels/nullables (absent du
 * verdict → null), pour un éventuel surlignage. Miroir de `CompromiseSchema` du contrat
 * backend⇄engine (contrat 2.10). Le front l'AFFICHE (tri + pastille dérivés de `effect` =
 * présentation), il ne re-dérive AUCUNE règle : le backend a déjà tranché ce qui est compromis.
 */
export type CompromiseEffect = "broken" | "gained";
export interface Compromise {
  family: string;
  effect: CompromiseEffect;
  message: string;
  teamId?: string | null;
  coachId?: string | null;
  venueId?: string | null;
  dayOfWeek?: number | null;
  startTime?: string | null;
}

/**
 * P2-30 — l'occupant ÉVINCÉ par un déplacement, tel qu'il était AVANT sa suppression. Le
 * front s'en sert pour proposer de le REPLACER (raccourci du toast) et pour l'annuler.
 * Miroir du bloc `evicted` du backend (200 de /move).
 */
export interface EvictedSlot {
  slotId: string;
  teamId: string;
  dayOfWeek: number;
  startTime: string;
  venueId: string;
  durationMinutes: number;
}

/**
 * Réponse d'un déplacement. Un déplacement RÉEL accepté arrive `valid: true` (+ `compromises`,
 * + `evicted` si une cible occupée a été libérée) ; un refus RÉEL est un 422 → {@link MoveRejectedError}.
 *
 * P2-32 — un ESSAI (`dryRun`) rend TOUJOURS un résultat (jamais un throw de légalité), en 200 :
 *  - accepté  → `valid: true`, `dryRun: true`, `compromises` (peut être vide), `evicted` si éviction ;
 *  - REFUSÉ   → `valid: false`, `dryRun: true`, `violations` NOMMÉES (le 200 {valid:false} n'est
 *    PAS un 422 : le parsing le rend tel quel, il ne lève pas).
 * `compromises` est normalisé à `[]` par le parseur — jamais absent à la lecture.
 */
export interface SlotMoveResult {
  valid: boolean;
  compromises: Compromise[];
  violations?: MoveViolation[];
  evicted?: EvictedSlot;
  dryRun?: boolean;
}

/** Corps d'un placement de séance à la dérive (P2-30). `durationMinutes` est OPTIONNEL :
 *  la fenêtre de gymnase fait foi côté serveur, ce n'est qu'une assertion du client. */
export interface PlaceSlotBody {
  teamId: string;
  dayOfWeek: number;
  startTime: string;
  venueId: string;
  durationMinutes?: number;
  /** P2-32 : un ESSAI — le moteur juge SANS créer de séance ; la réponse porte `dryRun: true`. */
  dryRun?: true;
}

/**
 * Réponse d'un placement. Accepté RÉEL → `valid: true` + `slotId` (créneau créé) + `compromises` ;
 * refus RÉEL → 422 {@link MoveRejectedError}. Un ESSAI (`dryRun`) rend un résultat en 200 (jamais
 * un throw de légalité) : accepté → `valid: true`, `dryRun: true`, `compromises` ; refusé →
 * `valid: false`, `dryRun: true`, `violations`. `compromises` est normalisé à `[]` par le parseur.
 */
export interface PlaceSlotResult {
  valid: boolean;
  slotId?: string | null;
  compromises: Compromise[];
  violations?: MoveViolation[];
  dryRun?: boolean;
}

/**
 * Une contrainte du club, lue telle quelle depuis `GET /api/constraints`. Le wrap de créneau
 * (F1) COMPOSE côté client celles qui s'appliquent à un créneau donné (par scope + cible) —
 * aucun champ calculé serveur. `scope`/`ruleType`/`family` restent des chaînes (mêmes enums
 * que le wizard) : le wrap n'affiche que `name` et le niveau (dur/souple).
 *
 * `config` porte le brut (dont `targetTag` d'une CLUB ciblant un groupe) : le wrap l'utilise
 * pour n'afficher une CLUB+tag que sur les équipes taguées — miroir de l'éclatement backend
 * (cf. lib/applicableConstraints).
 */
export interface Constraint {
  id: string;
  name: string;
  scope: string;
  scopeTargetId: string | null;
  family: string;
  ruleType: string;
  config: Record<string, unknown>;
  isActive: boolean;
}

/** Lock (HARD) or unlock (NONE) a placed slot so the next solve keeps/frees it. */
export const lockSlot = (id: string, lockLevel: LockLevel): Promise<unknown> =>
  api.post(`schedule-slots/${id}/manual-edit/lock`, { json: { lockLevel } }).json();

/** Une règle HARD cassée par un déplacement refusé : un code machine (pour brancher), un
 *  message déjà humain (le moteur y nomme coach/gymnase/heure) ET les ids des entités
 *  fautives, tels que le moteur les émet — pour surligner le conflit côté écran. Chaque id
 *  est optionnel et nullable (absent du verdict → null). `conflictingTeamId` porte l'équipe
 *  DÉJÀ en place qui bloque le candidat. Miroir de `AssignmentViolationSchema` (contrat 2.8). */
export interface MoveViolation {
  rule: string;
  message: string;
  teamId?: string | null;
  coachId?: string | null;
  venueId?: string | null;
  dayOfWeek?: number | null;
  startTime?: string | null;
  conflictingTeamId?: string | null;
}

/** Le moteur a REFUSÉ le déplacement (422) : il n'a pas eu lieu, voici les règles violées. */
export class MoveRejectedError extends Error {
  readonly violations: MoveViolation[];

  constructor(violations: MoveViolation[]) {
    super("move_rejected");
    this.name = "MoveRejectedError";
    this.violations = violations;
  }
}

/** Une génération tourne pour le club (409) : déplacer maintenant écraserait son résultat. */
export class GenerationInProgressError extends Error {
  constructor() {
    super("generation_in_progress");
    this.name = "GenerationInProgressError";
  }
}

/**
 * Le moteur a été TROP LENT à rendre son verdict (504 `engine_timeout`) — DISTINCT d'un moteur
 * injoignable/cassé (502). RIEN n'a été écrit. Le geste était peut-être parfaitement LÉGAL : le
 * backend a simplement abandonné avant la réponse (régression fondateur, club dense). Le message
 * serveur est déjà humain ; l'écran le NOMME (modale d'essai en échec, ou toast) et propose de
 * réessayer, au lieu d'afficher un numéro nu que personne ne peut interpréter.
 */
export class EngineTimeoutError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "EngineTimeoutError";
  }
}

/**
 * P4-119 (b) — la vérification a été INTERROMPUE CÔTÉ CLIENT avant la réponse du serveur : le
 * timeout de ky a expiré (ou l'appel a été aborté). Le geste n'a PAS abouti, mais on n'a AUCUNE
 * preuve d'une panne — le moteur peut très bien avoir répondu `valid` une seconde après (constaté :
 * nginx 499 pendant que le moteur concluait). DISTINCT d'un moteur injoignable (transport 5xx/502)
 * ET d'un 504 `engine_timeout` (là, le SERVEUR a tranché « trop lent »). L'écran NOMME
 * l'interruption et propose de réessayer — jamais « le moteur est indisponible », qui serait faux.
 */
export class EngineVerificationInterruptedError extends Error {
  constructor() {
    super("verification_interrupted");
    this.name = "EngineVerificationInterruptedError";
  }
}

/**
 * Lot C PR-2 — l'attente du verdict a été ABANDONNÉE VOLONTAIREMENT par le gestionnaire (bouton
 * « Abandonner ce déplacement » du voile bloquant), pas coupée par un timeout client. Sous-classe
 * de {@link EngineVerificationInterruptedError} À DESSEIN : même honnêteté (le geste n'a PAS
 * abouti côté client, mais le serveur a PU l'appliquer une seconde plus tard), et les branches qui
 * consomment déjà la classe mère (panneau `interrupted`, modale d'essai en échec) restent justes
 * PAR HÉRITAGE. Le distinguo sert au rail : sur un abandon, on resynchronise le paquet d'un
 * déplacement accepté (le serveur a pu écrire) et on NOMME l'abandon, sans « réessayez ».
 */
export class VerdictAbandonedError extends EngineVerificationInterruptedError {
  constructor() {
    super();
    this.name = "VerdictAbandonedError";
  }
}

/**
 * P4-119 (a) — le rail de retouche (déplacer / placer / essai) attend le VERDICT du moteur. Côté
 * serveur : construction du snapshot PUIS un budget transport de 20 s vers le moteur
 * (`MoveSlotService::VALIDATE_HTTP_TIMEOUT_SECONDS`), soit un bout-en-bout mesuré > 30 s sur un club
 * dense. Le timeout client par défaut de ky (10 s) raccrochait AVANT que le serveur ait pu rendre
 * son verdict (nginx 499, faux « moteur indisponible ») alors que le moteur répondait `valid`. On
 * l'aligne AU-DESSUS du pire cas serveur : 20 s transport + ~15 s de build observé + ~10 s de marge
 * réseau = 45 s. Un vrai dépassement moteur revient toujours NOMMÉ (504 `engine_timeout`) bien avant
 * cette borne — elle ne masque donc jamais un timeout serveur, elle empêche seulement le front de
 * raccrocher trop tôt.
 */
export const MOVE_VERDICT_TIMEOUT_MS = 45_000;

/**
 * La MÊME borne, EN SECONDES, pour la phrase d'attente montrée à l'utilisateur (« …jusqu'à N s »).
 * DÉRIVÉE du budget transport ci-dessus, jamais un littéral indépendant : une dérive de l'un sans
 * l'autre romprait en silence la cohérence message⇄comportement (P4-119 c bis). Source unique.
 */
export const MOVE_VERDICT_TIMEOUT_SECONDS = MOVE_VERDICT_TIMEOUT_MS / 1000;

/**
 * Une interruption CÔTÉ CLIENT (timeout de ky, ou abort) — à traduire en
 * {@link EngineVerificationInterruptedError}. ky lève un `TimeoutError` sur son propre timeout ; un
 * abort externe donne une erreur nommée `AbortError`. On teste le type ET le nom (robuste quel que
 * soit le porteur), jamais un `HTTPError` (celui-là porte une vraie réponse serveur).
 */
function isClientInterruption(error: unknown): boolean {
  return error instanceof TimeoutError || (error instanceof Error && ("TimeoutError" === error.name || "AbortError" === error.name));
}

/**
 * P2-30 D3 — la cible occupée à évincer est VERROUILLÉE (422 `target_locked`) : un verrou est
 * souverain, on ne le libère pas. Le message serveur est déjà humain (« déverrouillez-le
 * d'abord ») — l'écran le montre et RESTE en mode cible pour réessayer ailleurs.
 */
export class TargetLockedError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "TargetLockedError";
  }
}

/**
 * P2-30 — un refus 422 CODÉ du rail de retouche autre qu'un verdict moteur ou un verrou :
 * cible d'éviction incohérente (`evict_target_mismatch`), aucune fenêtre de gymnase
 * (`slot_unavailable`), durée annoncée fausse (`duration_mismatch`). Le message serveur est
 * déjà humain — on le montre tel quel. `code` permet de brancher sans re-dériver le message.
 */
export class SlotEditError extends Error {
  readonly code: string;

  constructor(code: string, message: string) {
    super(message);
    this.name = "SlotEditError";
    this.code = code;
  }
}

/**
 * Déplacer un créneau (jour / heure / gymnase) SOUS LE VERDICT DU MOTEUR (F2b).
 *
 * Remplace l'ancien rail `manual-edit/one-time` (chevauchements bruts, ni capacité ni
 * fenêtres ni repos) : le déplacement ne s'écrit QUE si le moteur l'accepte. Un refus
 * (422) devient un {@link MoveRejectedError} portant les règles violées nommées ; une
 * génération en cours (409 `generation_in_progress`) un {@link GenerationInProgressError}.
 * Les trois champs sont obligatoires côté serveur (l'UI les envoie toujours ensemble).
 */
export async function moveSlot(id: string, patch: SlotMovePatch, signal?: AbortSignal): Promise<SlotMoveResult> {
  try {
    // Un 200 {valid:false} (essai REFUSÉ) résout ici SANS lever : seul un 422 (refus réel) devient
    // un MoveRejectedError ci-dessous. `compromises` est normalisé à [] — jamais absent à la lecture.
    // `signal` (lot C PR-2) : l'abandon volontaire du voile bloquant abort la requête ky.
    const result = await api.post(`schedule-slots/${id}/move`, { json: patch, timeout: MOVE_VERDICT_TIMEOUT_MS, signal }).json<SlotMoveResult>();
    return { ...result, compromises: result.compromises ?? [] };
  } catch (error) {
    // P4-119 (b) : un abandon CLIENT (timeout ky / abort) ≠ un moteur en panne — on le NOMME.
    if (isClientInterruption(error)) {
      // Lot C PR-2 : un abort VOLONTAIRE (le signal a été aborté) est DISTINCT d'un timeout ky.
      throw true === signal?.aborted ? new VerdictAbandonedError() : new EngineVerificationInterruptedError();
    }
    if (error instanceof HTTPError) {
      // ky 2.x parse le corps d'erreur sur error.data (re-lire la réponse throw).
      const body = ((error as { data?: unknown }).data ?? {}) as { code?: string; error?: string; violations?: MoveViolation[] };
      if (422 === error.response.status) {
        // Un 422 CODÉ (verrou, cible incohérente) précède le verdict moteur : le verdict de
        // légalité, lui, arrive SANS code, avec ses `violations`.
        if ("target_locked" === body.code) {
          throw new TargetLockedError(body.error ?? "Ce créneau est verrouillé — déverrouillez-le d'abord.");
        }
        if (undefined !== body.code) {
          throw new SlotEditError(body.code, body.error ?? "Le déplacement n'a pas pu être appliqué.");
        }
        throw new MoveRejectedError(body.violations ?? []);
      }
      if (409 === error.response.status && "generation_in_progress" === body.code) {
        throw new GenerationInProgressError();
      }
      // 504 `engine_timeout` : le moteur a été trop lent (rien écrit). NOMMÉ pour l'écran.
      if (504 === error.response.status && "engine_timeout" === body.code) {
        throw new EngineTimeoutError(body.error ?? "Le moteur n'a pas répondu à temps — réessayez.");
      }
    }
    throw error;
  }
}

/**
 * PLACER une séance à la dérive (surnuméraire / rattrapage) SOUS LE VERDICT DU MOTEUR
 * (P2-30). Même modèle d'erreurs que {@link moveSlot} : 422 avec `violations` → verdict
 * refusé ({@link MoveRejectedError}) ; 422 codé (`slot_unavailable`/`duration_mismatch`) →
 * {@link SlotEditError} ; 409 → {@link GenerationInProgressError}.
 */
export async function placeSlot(scheduleId: string, body: PlaceSlotBody, signal?: AbortSignal): Promise<PlaceSlotResult> {
  try {
    const result = await api.post(`schedules/${scheduleId}/place-slot`, { json: body, timeout: MOVE_VERDICT_TIMEOUT_MS, signal }).json<PlaceSlotResult>();
    return { ...result, compromises: result.compromises ?? [] };
  } catch (error) {
    // P4-119 (b) : un abandon CLIENT (timeout ky / abort) ≠ un moteur en panne — on le NOMME.
    if (isClientInterruption(error)) {
      // Lot C PR-2 : un abort VOLONTAIRE (le signal a été aborté) est DISTINCT d'un timeout ky.
      throw true === signal?.aborted ? new VerdictAbandonedError() : new EngineVerificationInterruptedError();
    }
    if (error instanceof HTTPError) {
      const data = ((error as { data?: unknown }).data ?? {}) as { code?: string; error?: string; violations?: MoveViolation[] };
      if (422 === error.response.status) {
        if (undefined !== data.code) {
          throw new SlotEditError(data.code, data.error ?? "Le placement n'a pas pu être appliqué.");
        }
        throw new MoveRejectedError(data.violations ?? []);
      }
      if (409 === error.response.status && "generation_in_progress" === data.code) {
        throw new GenerationInProgressError();
      }
      if (504 === error.response.status && "engine_timeout" === data.code) {
        throw new EngineTimeoutError(data.error ?? "Le moteur n'a pas répondu à temps — réessayez.");
      }
    }
    throw error;
  }
}

/** Queue a (re)generation of the schedule (202). Locked slots survive; the rest reshuffles. */
export const generateSchedule = (id: string): Promise<unknown> => api.post(`schedules/${id}/generate`).json();

/**
 * Choose a COMPLETED version: its plan POINTS at it — it becomes the calendar in
 * force (read-only), and its sibling versions are SUPPRIMÉES. Choosing a different
 * version while period overlays exist → 409 overlays_exist unless
 * confirmDeleteOverlays is passed (same destructive idiom as reopen).
 */
export async function validateSchedule(id: string, opts?: { confirmDeleteOverlays?: boolean }): Promise<unknown> {
  try {
    return await api.post(`schedules/${id}/validate`, opts?.confirmDeleteOverlays ? { json: { confirmDeleteOverlays: true } } : undefined).json();
  } catch (error) {
    if (error instanceof HTTPError && 409 === error.response.status) {
      const body = ((error as { data?: unknown }).data ?? {}) as { code?: string; count?: number; overlays?: { entryId: string; title: string }[] };
      if ("overlays_exist" === body.code) {
        throw new OverlaysExistError(body.count ?? 0, body.overlays ?? []);
      }
    }
    throw error;
  }
}

/** Thrown when reopening the season's chosen version would delete period overlays (409). */
export class OverlaysExistError extends Error {
  readonly count: number;
  readonly overlays: { entryId: string; title: string }[];

  constructor(count: number, overlays: { entryId: string; title: string }[]) {
    super("overlays_exist");
    this.name = "OverlaysExistError";
    this.count = count;
    this.overlays = overlays;
  }
}

/**
 * Reopen the version the plan points at → the plan un-points it. Reopening the
 * season's chosen version with overlays
 * → 409 {code:"overlays_exist"} unless confirmDeleteOverlays is passed.
 */
export async function reopenSchedule(id: string, opts?: { confirmDeleteOverlays?: boolean }): Promise<unknown> {
  try {
    return await api.post(`schedules/${id}/reopen`, opts?.confirmDeleteOverlays ? { json: { confirmDeleteOverlays: true } } : undefined).json();
  } catch (error) {
    if (error instanceof HTTPError && 409 === error.response.status) {
      // ky 2.x parses the error body into error.data (re-reading the response
      // throws "body stream already read").
      const body = ((error as { data?: unknown }).data ?? {}) as { code?: string; count?: number; overlays?: { entryId: string; title: string }[] };
      if ("overlays_exist" === body.code) {
        throw new OverlaysExistError(body.count ?? 0, body.overlays ?? []);
      }
    }
    throw error;
  }
}

/** Delete a work version (server refuses the chosen version / in-flight with 409). */
export const deleteSchedule = (id: string): Promise<void> => api.delete(`schedules/${id}`).then(() => undefined);

/**
 * planning-versions D3: restore this version's structure photo and queue a
 * fresh generation → a new linear version. Returns the new schedule id.
 */
export const regenerateFromVersion = (id: string): Promise<{ id: string }> => api.post(`schedules/${id}/regenerate-from`).json();
/** planning-versions: the plain "Régénérer" creates a NEW linear version (V2…)
 *  from the current structure, carrying the version's HARD-locked slots. */
export const regenerate = (id: string): Promise<{ id: string }> => api.post(`schedules/${id}/regenerate`).json();

/**
 * P2-44 PR-3 (comblement) — un solve PARTIEL d'une version de plan de PÉRIODE : les placements
 * de {@link id} sont épinglés HARD par le serveur (tout ce qui est placé reste EXACTEMENT en
 * place) et le solveur ne place QUE les séances à replacer (équipes sous leur volume). Crée une
 * V+1 (savepoint) et renvoie son id ; le rail de génération existant prend le relais. Un refus
 * (409 non-période/choisie/génération en cours, 422 complexité/épinglage orphelin) remonte tel
 * quel — le hook l'affiche. */
export const fillSchedule = (id: string): Promise<{ id: string }> => api.post(`schedules/${id}/fill`).json();

/** planning-versions (overlay versions): create a new overlay version (DRAFT) UNDER a
 *  period's plan (ADR-0002 C4: a version names its plan) — the caller then generates it.
 *  A period's plan may hold several versions. */
export const createOverlayVersion = (schedulePlanId: string): Promise<{ id: string }> =>
  api.post("schedules", { json: { schedulePlanId, status: "DRAFT" } }).json();

// API Platform 4 OMITS null fields from JSON, so a plan's null nullable fields
// arrive ABSENT (undefined), not null. planType/schedulePlanId → every
// `"SEASON" === planType` / grouping-by-plan check silently fails (UX-02 journey
// regression); score → historiquement, un plan sans score (DRAFT/en vol) affichait le
// littéral « score undefined ». Depuis P4-39 plus aucun écran ne l'affiche et plus AUCUN
// code ne le lit : la normalisation ne corrige donc plus de bug visible, elle garde le type
// honnête (`score: number | null`) pour un champ que l'API sert toujours — donc tout
// consommateur voit un vrai null.
export const listSchedules = (): Promise<Schedule[]> =>
  collectionAll<Schedule>("schedules").then((rows) =>
    rows.map((s) => ({ ...s, planType: s.planType ?? null, schedulePlanId: s.schedulePlanId ?? null, score: s.score ?? null })),
  );
export const getSchedule = (id: string): Promise<Schedule> => api.get(`schedules/${id}`).json<Schedule>();
export const getSlots = (scheduleId: string): Promise<Slot[]> => collection<Slot>("schedule_slot_templates", { scheduleId });
/**
 * Les écarts NOMMÉS d'une version de plan de FERMETURE vs le socle pointé (P2-44 PR-5). Lecture pure
 * re-appelable ; le backend calcule, le front présente. Réponse JSON simple (pas une collection).
 */
export const getSocleDeviation = (scheduleId: string): Promise<SocleDeviation> => api.get(`schedules/${scheduleId}/socle-deviation`).json<SocleDeviation>();

/** P2-52 — combien de matchs perdront leur salle si l'on VALIDE ce planning (gymnase disparu),
 * et combien parmi eux sont déjà déclarés à la fédération. Même prédicat que la gâchette. */
export interface ValidateImpact {
  orphanedFixtures: number;
  declaredOrphanedFixtures: number;
}

export const getValidateImpact = (scheduleId: string): Promise<ValidateImpact> => api.get(`schedules/${scheduleId}/validate-impact`).json<ValidateImpact>();
export const getDiagnostics = (scheduleId: string): Promise<Diagnostic[]> => collection<Diagnostic>("schedule_diagnostics", { scheduleId });
export const getTeams = (): Promise<Team[]> => collectionAll<Team>("teams");
export const getVenues = async (): Promise<Venue[]> => sortByName(await collectionAll<Venue>("venues"));
/**
 * Les créneaux de la COUCHE d'une version : le socle (aucun filtre → le provider
 * restreint à `schedulePlanId IS NULL`) ou la grille que possède un plan de période
 * (#8). Sans ce filtre, l'écran affichait les cases vides de la SAISON sur un planning
 * de période, alors que l'export PDF remis aux coachs listait celles de la période :
 * deux vues du même planning qui ne coïncidaient pas.
 */
export const getTrainingSlots = (schedulePlanId: string | null): Promise<VenueTrainingSlot[]> =>
  collectionAll<VenueTrainingSlot>("venue_training_slots", null === schedulePlanId ? undefined : { schedulePlanId });
export const getCoaches = (): Promise<Coach[]> => collectionAll<Coach>("coaches");
export const getCategories = (): Promise<Category[]> => collectionAll<Category>("sport_categories");
export const getTeamCoaches = (): Promise<TeamCoach[]> => collectionAll<TeamCoach>("team_coaches");
export const getCoachPlayers = (): Promise<CoachPlayerMembership[]> => collectionAll<CoachPlayerMembership>("coach_player_memberships");
/** Toutes les contraintes du club — le wrap de créneau (F1) filtre côté client celles qui
 *  s'appliquent au créneau sélectionné (composition, pas de calcul serveur). */
export const getConstraints = (): Promise<Constraint[]> => collectionAll<Constraint>("constraints");
