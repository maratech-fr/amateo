import "@tanstack/react-query";

/**
 * Lot C PR-2 — typage du `meta` porté par les queries/mutations, enfin déclaré (API
 * `Register` de react-query, disponible en ^5.101.4). Deux clés seulement aujourd'hui :
 *
 *  - `veil` : opt-out du VOILE bloquant global (`app/ActionVeil`). Absent = voilé par défaut.
 *    `false` = jamais voilé (les 4 hooks qui rendent 202 puis passent la main à
 *    `GenerationWaiting`, et la query de suivi de statut). `"long"` = TRAITEMENT LONG (le rail
 *    de retouche move/place/dry-run) : voile non relâchable au chrono, avec bouton d'abandon.
 *  - `silent404` : la query gère elle-même un 404 (préexistant, cf. `shared/lib/queryClient.ts`).
 *  - `ownsError` : la query DIT elle-même son échec (avec une relance) — aucun toast générique.
 */
declare module "@tanstack/react-query" {
  interface Register {
    mutationMeta: { veil?: false | "long" };
    queryMeta: { veil?: false; silent404?: boolean; ownsError?: boolean };
  }
}
