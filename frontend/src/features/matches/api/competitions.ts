import { api } from "@/shared/api/client";
import { collectionAll } from "@/shared/api/collection";

export interface Competition {
  id: string;
  teamId: string;
  name: string;
  competitionType: string;
  /** FBI club-team label — set when two club teams share one division. */
  fbiTeamLabel?: string | null;
  /** FFBB pairing refs (P1-4 PR F) — read-only, written by the pairing confirm. */
  ffbbCompetitionId?: string | null;
  ffbbPouleId?: string | null;
  ffbbPouleName?: string | null;
  ffbbCompetitionName?: string | null;
  expectedMatchdays?: number | null;
  /**
   * RMM-6 — entry-deadline projection, read-only (written by the bulk endpoint
   * below, never the CRUD). `entryDeadline` is the club's OWN value; the backend
   * serves the rule « club wins, else community default » in `effectiveEntryDeadline`
   * and names its origin in `deadlineSource`. The front NEVER recomputes the rule.
   */
  entryDeadline?: string | null;
  effectiveEntryDeadline?: string | null;
  deadlineSource?: "club" | "community" | null;
}

export interface LeagueWindow {
  id: string;
  league: string;
  category: string;
  level: string;
  gender: string | null;
  /** ISO 1..7 */
  dayOfWeek: number;
  /** HH:MM */
  kickoffMin: string;
  kickoffMax: string;
}

export interface LeagueWindowsResponse {
  league: string;
  items: LeagueWindow[];
  /** P1-4 PR E2 (dette iv) — teamId → applicable window ids, resolved by the
   * SERVER with the same join as the solver ([] = unmapped → advisory only). */
  resolvedTeamWindows: Record<string, string[]>;
}

export const getCompetitions = (): Promise<Competition[]> => collectionAll<Competition>("competitions");

/**
 * RMM-6 — pose (ou EFFACE) UNE échéance de saisie sur un lot de compétitions, en un
 * seul geste (une transaction backend). `deadline: null` EXPLICITE = effacer ; le
 * backend rejette (422) une clé absente pour ne jamais essuyer les échéances en
 * silence. Un id inconnu/étranger → 422, rien écrit. Management-gated.
 */
export const setEntryDeadlines = (competitionIds: string[], deadline: string | null): Promise<{ updated: string[]; deadline: string | null }> =>
  api.post("competitions/entry-deadlines", { json: { competitionIds, deadline } }).json<{ updated: string[]; deadline: string | null }>();

/** The league match-kickoff windows inherited by the club (envelope, AURA default). */
export const getLeagueWindows = (): Promise<LeagueWindowsResponse> =>
  api.get("league-match-windows").json<LeagueWindowsResponse>();

/**
 * RMM-3 — le « gardien » à l'ouverture du module. Ce que le POST rapporte : ce qui
 * a CHANGÉ depuis la précédente visite de CET utilisateur (matchs arrivés, conflits
 * neufs par empreinte, planning de saison qui a bougé). Le serveur stampe la visite
 * comme effet de bord (première visite = silencieuse, delta vide ; grâce glissante
 * de 30 min → un F5 rejoue le même delta sans le rotationner). Voir
 * `MatchModuleVisitController`.
 */
export interface ModuleVisitDelta {
  /** Première visite : la référence est figée en silence, tous les comptes à zéro. */
  firstVisit: boolean;
  /** Fixtures créés depuis la prise de référence. */
  newFixturesCount: number;
  /** Empreintes des conflits présents maintenant et absents de la référence. */
  newConflictFingerprints: string[];
  /** La version de saison pointée (ou la dernière COMPLETED) a changé. */
  planningChanged: boolean;
  /** L'instant contre lequel les badges sont mesurés (ISO). */
  referenceTakenAt: string;
}

/** Stampe la visite et rend le delta depuis la précédente (POST sans corps). */
export const postModuleVisit = (): Promise<ModuleVisitDelta> => api.post("matches/module-visit").json<ModuleVisitDelta>();

// ── Échéances de saisie : l'outlook J-7 du cockpit (RMM-6 PR-3) ───────────────

/**
 * Une échéance EFFECTIVE encore due, groupée (date, source) par le backend. La
 * règle J-7 (`withinWindow`) est calculée SERVEUR — la maison unique
 * `EntryDeadlineOutlook` : le front n'invente aucune fenêtre (🔴 `.claude/rules/frontend.md`).
 */
export interface DeadlineOutlookWindow {
  /** Y-m-d */
  deadline: string;
  /** 'club' (valeur du club) | 'community' (défaut communautaire proposé). */
  source: "club" | "community";
  competitionNames: string[];
  /** Domiciles pas encore saisis dans FBI (UNPLACED compris). */
  toEnterCount: number;
  /** Vraie dans les sept jours de l'échéance (dépassée comprise) — calcul BACKEND. */
  withinWindow: boolean;
}

/**
 * Le delta du « gardien » joint à l'outlook QUAND une fenêtre est ouverte et que
 * l'utilisateur a déjà une référence de visite — lu SANS stamper (RMM-6 PR-1).
 * Sous-ensemble de `ModuleVisitDelta` (ni `firstVisit` ni `referenceTakenAt`).
 */
export interface DeadlineGuardianDelta {
  newFixturesCount: number;
  newConflictFingerprints: string[];
  planningChanged: boolean;
}

export interface DeadlineOutlook {
  windows: DeadlineOutlookWindow[];
  /**
   * Le « à faire dans FBI » GLOBAL (toutes semaines), servi pour que le cockpit ET la
   * barre des compteurs n'aient jamais à charger les fixtures : à saisir = domiciles
   * PLACED, à corriger = entrées ouvertes du registre.
   * Optionnel côté TS (le backend le sert toujours ; l'absence retombe sur 0 à l'usage).
   */
  fbiTodo?: FbiTodo;
  /** Absent quand aucune fenêtre n'est ouverte OU sans référence de visite. */
  guardianDelta?: DeadlineGuardianDelta;
}

export interface FbiTodo {
  toEnter: number;
  toCorrect: number;
}

/** L'outlook J-7 des échéances de saisie (lecture seule, ouvert au Membre). */
export const getDeadlineOutlook = (): Promise<DeadlineOutlook> => api.get("matches/deadline-outlook").json<DeadlineOutlook>();

/**
 * Lot O — « validé ligue » en lot, PILOTÉ PAR L'ÉCHÉANCE du championnat. Un club qui
 * démarre EN COURS de saison confirme d'un geste les domiciles des championnats DONT
 * L'ÉCHÉANCE EST PASSÉE (jour inclus) : `confirmed` = combien ont basculé (VALIDATED +
 * source MANUAL). Un championnat dont l'échéance n'est pas passée (nouvelle vague, dates
 * provisoires) n'est proposé nulle part. Rejouable : un second appel rend 0.
 */
/** Un championnat ÉCHU (échéance passée, jour inclus) avec son compte de validables. */
export interface MaturedCompetition {
  competitionId: string;
  name: string;
  deadline: string;
  deadlineSource: "club" | "community";
  validatableCount: number;
}

/** Pourquoi un domicile d'un championnat échu n'est pas validable — nommé, jamais tu. */
export type LeagueToTreatReason = "NO_KICKOFF" | "NO_VENUE" | "PENDING_DEVIATION";

/** Une rencontre d'un championnat échu à traiter (ni heure ni gymnase, ou écart en attente). */
export interface LeagueToTreatFixture {
  fixtureId: string;
  teamId: string;
  competitionName: string;
  matchDate: string;
  opponentLabel: string;
  reason: LeagueToTreatReason;
}

/** Un championnat SANS échéance ayant pourtant des rencontres prêtes (à renseigner). */
export interface MissingDeadlineCompetition {
  competitionId: string;
  name: string;
  validatableCount: number;
}

/**
 * La lecture « validé ligue », pilotée par l'échéance du championnat. Le backend est la
 * SEULE maison de la règle (échéance passée ⇒ championnat proposé) — le front AFFICHE ce
 * qu'il sert, il ne le redérive JAMAIS (🔴 `.claude/rules/frontend.md`).
 */
export interface LeagueValidationOutlook {
  matured: MaturedCompetition[];
  toTreat: LeagueToTreatFixture[];
  missingDeadline: MissingDeadlineCompetition[];
  totalValidatable: number;
}

export interface LeagueValidationResult {
  confirmed: number;
}

export const getLeagueValidationOutlook = (): Promise<LeagueValidationOutlook> =>
  api.get("fixtures/league-validation").json<LeagueValidationOutlook>();

export const confirmLeagueValidatedFixtures = (): Promise<LeagueValidationResult> =>
  api.post("fixtures/league-validation").json<LeagueValidationResult>();
