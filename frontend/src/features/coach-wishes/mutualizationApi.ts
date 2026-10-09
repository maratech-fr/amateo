import { api } from "@/shared/api/client";
import { collectionAll } from "@/shared/api/collection";

/**
 * Une demande de mutualisation coach (feature #10, lot D2) : une par (équipe × période de
 * vacances). DEMANDE informative, jamais une contrainte — aide à la négociation du plan réduit.
 * Ancrée à l'entrée MÈRE des vacances.
 */
export interface CoachWishMutualization {
  id: string;
  version: number;
  calendarEntryId: string;
  teamId: string;
  /** null = demande dé-attribuée (coach supprimé). Requis à la CRÉATION (garde serveur). */
  coachId: string | null;
  /** Équipes partenaires pressenties (uuid). */
  partnerTeamIds: string[];
  /** Nombre de séances à mutualiser (1–7). */
  sharedSlots: number;
  done: boolean;
}

export interface CoachWishMutualizationPayload {
  calendarEntryId: string;
  teamId: string;
  coachId: string | null;
  partnerTeamIds: string[];
  sharedSlots: number;
  done: boolean;
}

export const listCoachWishMutualizations = (calendarEntryId: string): Promise<CoachWishMutualization[]> =>
  collectionAll<CoachWishMutualization>("coach_wish_mutualizations", { calendarEntryId });

export const createCoachWishMutualization = (body: CoachWishMutualizationPayload): Promise<CoachWishMutualization> =>
  api.post("coach_wish_mutualizations", { json: body }).json();

export const updateCoachWishMutualization = (id: string, body: CoachWishMutualizationPayload): Promise<CoachWishMutualization> =>
  api.put(`coach_wish_mutualizations/${id}`, { json: body }).json();

export const deleteCoachWishMutualization = (id: string): Promise<void> => api.delete(`coach_wish_mutualizations/${id}`).then(() => undefined);
