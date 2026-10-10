import { api } from "@/shared/api/client";
import { collectionAll } from "@/shared/api/collection";

/**
 * Une doléance coach (feature #10, lot C1) : un souhait par (équipe × semaine) pour une
 * période de vacances. Jamais une contrainte — aide à la négociation du plan réduit.
 * Ancrée à l'entrée MÈRE des vacances + le lundi de la semaine (weekStart, Y-m-d).
 */
export interface CoachWish {
  id: string;
  version: number;
  calendarEntryId: string;
  weekStart: string;
  teamId: string;
  coachId: string | null;
  slotsWanted: number;
  /** Jours indisponibles, ISO 1–7 (1 = lundi). */
  unavailableDays: number[];
  /** Jours souhaités, ISO 1–7 (informatif ; disjoint des indisponibilités). */
  wishedDays: number[];
  comment: string | null;
  done: boolean;
  /** Le coach souhaite garder ses créneaux habituels de saison cette semaine-là (volet B). */
  keepSeasonSlots: boolean;
}

export interface CoachWishPayload {
  calendarEntryId: string;
  weekStart: string;
  teamId: string;
  /** null = doléance dé-attribuée (coach supprimé). Requis à la CRÉATION (garde serveur). */
  coachId: string | null;
  slotsWanted: number;
  unavailableDays: number[];
  wishedDays: number[];
  comment: string | null;
  done: boolean;
  /** Le coach souhaite garder ses créneaux habituels de saison cette semaine-là (volet B). */
  keepSeasonSlots: boolean;
}

export const listCoachWishes = (calendarEntryId: string): Promise<CoachWish[]> =>
  collectionAll<CoachWish>("coach_wishes", { calendarEntryId });

export const createCoachWish = (body: CoachWishPayload): Promise<CoachWish> => api.post("coach_wishes", { json: body }).json();

export const updateCoachWish = (id: string, body: CoachWishPayload): Promise<CoachWish> => api.put(`coach_wishes/${id}`, { json: body }).json();

export const deleteCoachWish = (id: string): Promise<void> => api.delete(`coach_wishes/${id}`).then(() => undefined);

/**
 * Récap d'un « Transférer au planning » (P2-63 PR 5, volets E/F) : combien de doléances ont
 * produit des réglages de plan, combien ont été IGNORÉES et pourquoi (ex. `semaine_sans_plan`,
 * `introuvable`). `ignoredReasons` est un décompte par motif.
 */
export interface CoachWishTransferRecap {
  converted: number;
  ignored: number;
  ignoredReasons: Record<string, number>;
}

/**
 * Transfère les doléances COCHÉES vers le plan de leur semaine (geste gestionnaire).
 * 409 `generation_in_progress` si une génération tourne (son message serveur est surfacé par le
 * filet global des mutations).
 */
export const transferCoachWishes = (wishIds: string[]): Promise<CoachWishTransferRecap> =>
  api.post("coach-wishes/transfer", { json: { wishIds } }).json();
