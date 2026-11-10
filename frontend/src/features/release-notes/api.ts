import { api } from "@/shared/api/client";

/**
 * Une note du journal de nouveautés, telle que servie au membre. `date` est la
 * date ÉDITORIALE (affichée, antidatable) ; `publishedAt` est l'instant de
 * publication — c'est LUI qui décide de la modale « quoi de neuf ». `body` est
 * du TEXTE BRUT : aucun rendu markdown/html (affiché `whitespace-pre-line`).
 */
export interface ReleaseNoteItem {
  id: string;
  date: string;
  title: string;
  body: string;
  publishedAt: string;
  /** Version du produit annoncée par la note (« 1.3.5 »), ou null si la note n'en porte pas. */
  version: string | null;
}

export interface ReleaseNotesResponse {
  /** Jusqu'où le membre a lu (ISO), ou null s'il ne l'a jamais marqué. */
  seenUpTo: string | null;
  items: ReleaseNoteItem[];
}

/** Les notes PUBLIÉES (les plus récentes d'abord) + le filigrane de lecture. */
export const getReleaseNotes = (): Promise<ReleaseNotesResponse> => api.get("release-notes").json();

/** Marquer le journal lu jusqu'à maintenant (self-only, 204). */
export const markReleaseNotesSeen = (): Promise<void> => api.post("release-notes/seen").then(() => undefined);
