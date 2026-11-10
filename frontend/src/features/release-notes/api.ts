import { api } from "@/shared/api/client";
import type { RichDoc } from "@/shared/lib/richDoc";

/**
 * Une note du journal de nouveautés, telle que servie au membre. `date` est la
 * date ÉDITORIALE (affichée, antidatable) ; `publishedAt` est l'instant de
 * publication — c'est LUI qui décide de la modale « quoi de neuf ». `bodyRich` est
 * le corps mis en forme (liste blanche serveur, rendu par `RichText`, jamais en
 * HTML) ; null = note TEXTE, dont `body` (texte brut, `whitespace-pre-line`) fait foi.
 */
export interface ReleaseNoteItem {
  id: string;
  date: string;
  title: string;
  body: string;
  publishedAt: string;
  /** Version du produit annoncée par la note (« 1.3.5 »), ou null si la note n'en porte pas. */
  version: string | null;
  /** Corps mis en forme, ou null pour une note texte (`body` seul). */
  bodyRich: RichDoc | null;
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
