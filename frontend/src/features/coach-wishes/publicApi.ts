import ky, { HTTPError } from "ky";

/**
 * Client HTTP DÉDIÉ à la page publique de doléances (feature #10, lot C2) — SANS LOGIN.
 *
 * Volontairement PAS le client partagé : aucun Bearer, aucun X-Season-Id, aucun hook
 * afterResponse (celui du client partagé redirige vers /login sur 401 — or ici un 401
 * ne doit JAMAIS survenir, la route est PUBLIC_ACCESS ; et le coach n'a pas de session).
 * Le token dans l'URL porte l'identité et le club.
 */
const publicClient = ky.create({ prefix: "/api", retry: 0 });

/** Une (équipe × semaine) déjà saisie — pré-remplissage de l'état courant. */
export interface PublicWish {
  teamId: string;
  weekStart: string;
  slotsWanted: number;
  /** Jours indisponibles ISO 1–7. */
  unavailableDays: number[];
  /** Jours souhaités ISO 1–7 (informatif ; disjoint des indisponibilités). */
  wishedDays: number[];
  comment: string | null;
}

/** Une mutualisation déjà déclarée (une par équipe, pour la période) — pré-remplissage. */
export interface PublicMutualization {
  teamId: string;
  /** Équipes partenaires pressenties (uuid). */
  partnerTeamIds: string[];
  /** Nombre de séances à mutualiser (1–7). */
  sharedSlots: number;
}

/** Une passerelle (couple d'équipes liées), restreinte aux équipes de la collecte. */
export interface PublicTeamLink {
  teamAId: string;
  teamBId: string;
}

export interface PublicWishContext {
  coachFirstName: string;
  periodTitle: string;
  /** Bornes de la période mère (Y-m-d). `null` si l'entrée de calendrier a disparu. */
  periodStart: string | null;
  periodEnd: string | null;
  /** Y-m-d. */
  deadline: string;
  /** Lundis Y-m-d retenus. */
  weeks: string[];
  teams: { id: string; name: string }[];
  /** Équipes de la campagne proposables en partenaires (le front retire l'équipe courante). */
  partnerTeams: { id: string; name: string }[];
  /** Passerelles restreintes aux équipes de la campagne (partenaires à hisser en tête). */
  teamLinks: PublicTeamLink[];
  wishes: PublicWish[];
  /** Mutualisations déjà déclarées par le coach (pré-remplissage). */
  mutualizations: PublicMutualization[];
  respondedAt: string | null;
}

export interface PublicWishSubmission {
  teamId: string;
  weekStart: string;
  slotsWanted: number;
  unavailableDays: number[];
  wishedDays: number[];
  comment: string | null;
}

/** La soumission d'une mutualisation (0 partenaire = supprimer la ligne côté serveur). */
export interface PublicMutualizationSubmission {
  teamId: string;
  partnerTeamIds: string[];
  sharedSlots: number;
}

/** Statut d'erreur métier exploitable par la page (404 lien invalide, 410 expiré). */
export type PublicWishError = { status: number };

export const isPublicWishError = (e: unknown): e is HTTPError => e instanceof HTTPError;

export const getPublicWishContext = (token: string): Promise<PublicWishContext> => publicClient.get(`coach-wishes/public/${token}`).json();

export const submitPublicWishes = (
  token: string,
  submissions: PublicWishSubmission[],
  mutualizations: PublicMutualizationSubmission[],
): Promise<{ deadline: string }> => publicClient.post(`coach-wishes/public/${token}`, { json: { submissions, mutualizations } }).json();
