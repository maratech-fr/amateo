/**
 * Textes du journal de nouveautés (modale « Quoi de neuf ? » et page « Nouveautés »).
 * `WhatsNewModal.tsx` et `ReleaseNotesPage.tsx` sont inscrits à `MIGRATED_FILES`
 * (`src/test/hardcodedTextGuard.test.ts`) : aucun libellé en dur n'y reste.
 */
export const RELEASE_NOTES_TEXTS = {
  modalLabel: "Quoi de neuf",
  modalTitle: "Quoi de neuf ?",
  /** Les nouveautés fraîches NON affichées : « et 1 autre nouveauté » / « et 2 autres nouveautés ». */
  modalOthers: (n: number): string => (1 === n ? "et 1 autre nouveauté" : `et ${n} autres nouveautés`),
  modalSeeAll: "Voir tout",
  modalGotIt: "J'ai compris",
  pageTitle: "Nouveautés",
  pageSubtitle: "Ce qui a changé récemment dans l'application.",
  loading: "Chargement…",
  loadError: "Le journal n'a pas pu être chargé.",
  empty: "Aucune nouveauté pour le moment.",
  /** En-tête d'une note : date jj/mm/aaaa, suivie de « - v1.3.5 » quand la note porte une version. */
  heading: (date: string, version: string | null): string => (null === version ? date : `${date} - v${version}`),
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
