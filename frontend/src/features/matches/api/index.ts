/**
 * Matches read/write API (module matchs, palier A PR-3). Tenant (club) + active
 * season are resolved server-side from the JWT — no header is sent. Consumes only
 * the endpoints delivered by PR-1/PR-2; this PR adds no backend.
 */

export * from "./opponents";
export * from "./fixtures";
export * from "./conflicts";
export * from "./venues";
export * from "./teams";
export * from "./competitions";
export * from "./fbi";
export * from "./ffbb";
