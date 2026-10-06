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

export interface PublicWishContext {
  coachFirstName: string;
  periodTitle: string;
  /** Y-m-d. */
  deadline: string;
  /** Lundis Y-m-d retenus. */
  weeks: string[];
  teams: { id: string; name: string }[];
  wishes: PublicWish[];
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

/** Statut d'erreur métier exploitable par la page (404 lien invalide, 410 expiré). */
export type PublicWishError = { status: number };

export const isPublicWishError = (e: unknown): e is HTTPError => e instanceof HTTPError;

export const getPublicWishContext = (token: string): Promise<PublicWishContext> => publicClient.get(`coach-wishes/public/${token}`).json();

export const submitPublicWishes = (token: string, submissions: PublicWishSubmission[]): Promise<{ deadline: string }> =>
  publicClient.post(`coach-wishes/public/${token}`, { json: { submissions } }).json();
