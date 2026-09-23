import { api } from "@/shared/api/client";
import { collectionAll } from "@/shared/api/collection";
import type { OpponentLocationPrecision } from "./opponents";

export type HomeAway = "HOME" | "AWAY";
export type FixtureStatus = "UNPLACED" | "PLACED" | "SUBMITTED" | "VALIDATED";

/**
 * PR-3a — l'axe TRAITEMENT d'une rencontre (a-t-elle été EXAMINÉE par le
 * gestionnaire), distinct de `status` (le placement). NEW = jamais examinée,
 * OUT_OF_SYNC = déphasée (un écart pendant subsiste), REVIEWED = traitée.
 */
export type FixtureReviewState = "NEW" | "OUT_OF_SYNC" | "REVIEWED";

/**
 * PR-3a — un écart encore ouvert sur une rencontre, un par champ du périmètre
 * (`date`/`kickoff`/`venue`). `channel` = la source qui l'a fait apparaître ;
 * `autoApplied` = une valeur imposée hors périmètre pendant que le match était
 * traité (il retombe OUT_OF_SYNC, la valeur app a déjà été déplacée).
 */
export interface PendingDeviation {
  field: DeviationField;
  appValue: string | null;
  sourceValue: string | null;
  channel: "FBI_XLSX" | "FFBB_API";
  seenAt: string;
  autoApplied: boolean;
}

export interface Fixture {
  id: string;
  teamId: string;
  seasonId: string;
  competitionId: string | null;
  /** Y-m-d */
  matchDate: string;
  homeAway: HomeAway;
  opponentLabel: string;
  status: FixtureStatus;
  venueId: string | null;
  /** HH:MM, null until placed/estimated */
  kickoffTime: string | null;
  /** FBI match number (import idempotence key) — null for manual entries. */
  externalRef: string | null;
  /** Raw FBI « Salle » label, HOME and AWAY — never a Venue reference. */
  fbiVenueLabel: string | null;
  /** MANUAL | SOLVER | null — who placed it (re-solve anchor marker, PR D). */
  placementSource: "MANUAL" | "SOLVER" | null;
  /**
   * P2-52 — persistent reason it went back to « à placer »: `venue_lost` (its venue is no
   * longer affiliated to the club), else null. Distinct from the volatile auto-placement reason.
   */
  unplacedReason: "venue_lost" | null;
  /** PR-3a — l'état de TRAITEMENT (a-t-elle été examinée), distinct de `status`. */
  reviewState: FixtureReviewState;
  /** PR-3a — dernier traitement (ISO), null = jamais examinée. */
  reviewedAt: string | null;
  /** PR-3a — les écarts encore ouverts, un par champ ; [] quand en phase. */
  pendingDeviations: PendingDeviation[];
  /** PR-3a — la rencontre FFBB appariée (canal API), null sinon. */
  ffbbRencontreId: string | null;
  /**
   * P4-187b — proposition FLOUE de gymnase (lecture seule) : servie pour un HOME
   * SANS `venueId` portant un `fbiVenueLabel` dont le nom/alias matche un gymnase
   * actif du club. Ambiguïté (≥ 2 candidats) → null ; jamais un placement, juste un
   * pré-remplissage suggéré dans le geste « Rattacher ». Le front l'AFFICHE.
   */
  suggestedVenueId: string | null;
  /**
   * P2-54 « adversaire multi-gymnases » — le code FFBB de l'organisme adverse, stampé
   * best-effort par le serveur sur les AWAY, null quand l'adversaire n'a pas pu être
   * résolu. Read-only. Clé de jointure vers le trajet adverse (avec `opponentTeamKey`).
   */
  opponentOrganismeCode: string | null;
  /**
   * P2-54 « adversaire multi-gymnases » — le libellé adverse NORMALISÉ côté serveur : le
   * grain d'une surcharge de trajet PAR ÉQUIPE (une même organisation joue parfois dans
   * plusieurs gymnases selon son équipe). Null quand le libellé est vide. Read-only. Le
   * front joint le trajet par `(opponentOrganismeCode, opponentTeamKey)` sans re-dériver.
   */
  opponentTeamKey: string | null;
  /**
   * Mémo « FBI affiche encore … » d'un domicile rétrogradé « à saisir » par une heure
   * prise du fichier : `{field, value, at}` ou null. Sert la mention de la ligne « à
   * saisir » de la liste « FBI — à faire ». Servi, jamais recalculé côté front.
   * Optionnel côté TS (nullable, pas `required` côté OpenAPI) ; `normalizeFixture` le
   * matérialise TOUJOURS à `null` quand la source ne l'envoie pas.
   */
  fbiEcho?: FbiEcho | null;
  /**
   * Le trajet d'une rencontre EXTÉRIEURE, DÉRIVÉ de la rencontre (jamais du club), calculé
   * SERVEUR : le lieu de l'adversaire + l'aller simple depuis le siège. null pour un domicile
   * ou si rien de connu. Optionnel côté TS (nullable, pas `required` côté OpenAPI, patron
   * `fbiEcho`) ; `normalizeFixture` le matérialise TOUJOURS à null quand absent.
   */
  awayTravel?: AwayTravel | null;
}

/** Mémo `Fixture.fbiEcho` — ce que FBI affiche encore pour un champ, sur un domicile « à saisir ». */
export interface FbiEcho {
  field: string;
  value: string;
  /** ISO. */
  at: string;
}

/**
 * `Fixture.awayTravel` — le trajet d'une rencontre extérieure, tout SERVEUR (le front n'en
 * dérive RIEN, il affiche). `basis` porte la CAUSE : `linked` (salle appariée, exact),
 * `most_frequent` (repli gymnase le plus fréquent → « gymnase supposé »), `city` (coordonnées
 * de ville → « ville seule »). `approximated` = trajet approché (repli). `oneWayMinutes` = aller
 * simple, null si pas encore calculé (le lieu peut être connu sans les minutes).
 */
export interface AwayTravel {
  venueLabel: string | null;
  city: string | null;
  precision: OpponentLocationPrecision | null;
  oneWayMinutes: number | null;
  approximated: boolean;
  basis: "linked" | "most_frequent" | "city";
}

export interface CreateFixtureInput {
  teamId: string;
  matchDate: string;
  homeAway: HomeAway;
  opponentLabel: string;
  competitionId?: string | null;
}

/** The placement of a home fixture: venue + kickoff, status → PLACED. */
export interface PlaceFixtureInput {
  venueId: string;
  kickoffTime: string;
}

/** The API omits null props from JSON → coerce the optionals back to null so
 * `null !==` guards and the grid/envelope logic never see `undefined`. */
function normalizeFixture(raw: Fixture): Fixture {
  return {
    ...raw,
    competitionId: raw.competitionId ?? null,
    venueId: raw.venueId ?? null,
    kickoffTime: raw.kickoffTime ?? null,
    externalRef: raw.externalRef ?? null,
    fbiVenueLabel: raw.fbiVenueLabel ?? null,
    placementSource: raw.placementSource ?? null,
    unplacedReason: raw.unplacedReason ?? null,
    pendingDeviations: raw.pendingDeviations ?? [],
    reviewedAt: raw.reviewedAt ?? null,
    ffbbRencontreId: raw.ffbbRencontreId ?? null,
    suggestedVenueId: raw.suggestedVenueId ?? null,
    opponentOrganismeCode: raw.opponentOrganismeCode ?? null,
    opponentTeamKey: raw.opponentTeamKey ?? null,
    fbiEcho: raw.fbiEcho ?? null,
    awayTravel: raw.awayTravel ?? null,
  };
}

export const getFixtures = async (): Promise<Fixture[]> => (await collectionAll<Fixture>("fixtures")).map(normalizeFixture);
// ── Auto-placement (P1-4 PR D) ───────────────────────────────────────────────

export type UnplacedReason = "no_access_window" | "no_league_intersection" | "venue_unavailable" | "venue_full";

export interface PlaceMatchesResult {
  placed: number;
  /** Placements refused at write time — a manual gesture won during the solve. */
  skipped: number;
  unplaced: { matchId: string; reason: UnplacedReason; message: string }[];
  diagnostics: { type: string; severity: string; message: string }[];
}

/** Synchronous solve: the engine places every placeable home match (seconds).
 * A non-placeable match is NOT an error — it comes back named in `unplaced`. */
export const placeMatches = (): Promise<PlaceMatchesResult> => api.post("fixtures/place").json<PlaceMatchesResult>();

/** RMM-4 — the reconciliation perimeter: the three home fields that become a
 * CHOICE when the file diverges from an already-placed match. */
export type DeviationField = "date" | "kickoff" | "venue";
export const createFixture = (input: CreateFixtureInput): Promise<Fixture> =>
  api.post("fixtures", { json: { competitionId: null, ...input } }).json<Fixture>();

/**
 * Place a home fixture. PUT is a full replace in this API, so the whole fixture
 * body is resent — only venue/kickoff/status change; identity + opponent +
 * competition are echoed so they are not wiped.
 */
export const placeFixture = (fixture: Fixture, input: PlaceFixtureInput): Promise<Fixture> =>
  api
    .put(`fixtures/${fixture.id}`, {
      json: {
        teamId: fixture.teamId,
        matchDate: fixture.matchDate,
        homeAway: fixture.homeAway,
        opponentLabel: fixture.opponentLabel,
        competitionId: fixture.competitionId,
        venueId: input.venueId,
        kickoffTime: input.kickoffTime,
        status: "PLACED",
      },
    })
    .json<Fixture>();

// ── Manual loop (P1-4 PR E1) ─────────────────────────────────────────────────

/** Full-replace echo body — PUT wipes whatever is not resent. */
const fixtureEchoBody = (fixture: Fixture): Record<string, unknown> => ({
  teamId: fixture.teamId,
  matchDate: fixture.matchDate,
  homeAway: fixture.homeAway,
  opponentLabel: fixture.opponentLabel,
  competitionId: fixture.competitionId,
  venueId: fixture.venueId ?? "",
  kickoffTime: fixture.kickoffTime ?? "",
  status: fixture.status,
});

export interface EditFixtureInput {
  matchDate: string;
  homeAway: HomeAway;
  opponentLabel: string;
  competitionId: string | null;
}

/** Edit body rule (pure, unit-tested): a manual date change KEEPS the placement
 * (the manager IS the decision — unlike an FBI re-import, which un-places); the
 * diagnostic flags any problem the new date creates. Switching HOME → AWAY is
 * the one exception: our venue makes no sense for an away game, the slot is
 * freed (same rule as the FBI re-import switch). */
export const editFixtureBody = (fixture: Fixture, input: EditFixtureInput): Record<string, unknown> => {
  const switchedAway = "AWAY" === input.homeAway && "HOME" === fixture.homeAway;
  const unplace = switchedAway ? { status: "UNPLACED", venueId: "", kickoffTime: "" } : {};
  return { ...fixtureEchoBody(fixture), ...input, ...unplace };
};

export const updateFixture = (fixture: Fixture, input: EditFixtureInput): Promise<Fixture> =>
  api.put(`fixtures/${fixture.id}`, { json: editFixtureBody(fixture, input) }).json<Fixture>();

export const deleteFixture = (id: string): Promise<void> => api.delete(`fixtures/${id}`).then(() => undefined);

/** Back to the "à placer" list: placement cleared, placementSource cleared server-side. */
export const unplaceFixture = (fixture: Fixture): Promise<Fixture> =>
  api
    .put(`fixtures/${fixture.id}`, { json: { ...fixtureEchoBody(fixture), status: "UNPLACED", venueId: "", kickoffTime: "" } })
    .json<Fixture>();

/**
 * Ferme la boucle hebdo : le match placé est SAISI DANS FBI (`status: SUBMITTED`).
 * Full-replace comme tous les PUT — on ÉCHO tout le fixture, seul `status` bouge.
 * ⚠ Effet de bord ASSUMÉ (décision fondateur) : côté serveur, toute écriture de
 * statut ≠ UNPLACED tamponne `placementSource = MANUAL`. Marquer « saisi » ANCRE
 * donc le match — c'est voulu : un match déclaré à la fédération ne doit plus
 * bouger au solve.
 */
export const submitFixture = (fixture: Fixture): Promise<Fixture> =>
  api.put(`fixtures/${fixture.id}`, { json: { ...fixtureEchoBody(fixture), status: "SUBMITTED" } }).json<Fixture>();

/**
 * Sortie de SUBMITTED : « Corriger » repasse le match en PLACED (chemin de
 * réparation — gymnase mort, erreur de saisie FBI). Full-replace, seul `status`
 * change. Le match reste MANUAL après ce retour ; « Rendre au système » demeure
 * ensuite possible sur un placement intact.
 */
export const reopenFixture = (fixture: Fixture): Promise<Fixture> =>
  api.put(`fixtures/${fixture.id}`, { json: { ...fixtureEchoBody(fixture), status: "PLACED" } }).json<Fixture>();

/** Move a placed fixture (venue and/or kickoff) — stays a MANUAL anchor. */
export const moveFixture = (fixture: Fixture, input: PlaceFixtureInput): Promise<Fixture> =>
  api
    .put(`fixtures/${fixture.id}`, { json: { ...fixtureEchoBody(fixture), venueId: input.venueId, kickoffTime: input.kickoffTime, status: "PLACED" } })
    .json<Fixture>();

/** Padlock: an echo PUT stamps MANUAL server-side — the solver never moves it again. */
export const lockFixture = (fixture: Fixture): Promise<Fixture> =>
  api.put(`fixtures/${fixture.id}`, { json: fixtureEchoBody(fixture) }).json<Fixture>();

/** Hand back to the solver — accepted by the server ONLY on an untouched placement (422 otherwise). */
export const unlockFixture = (fixture: Fixture): Promise<Fixture> =>
  api.put(`fixtures/${fixture.id}`, { json: { ...fixtureEchoBody(fixture), placementSource: "SOLVER" } }).json<Fixture>();

/**
 * Swap the placements (venue + kickoff, NEVER the dates — the league owns them)
 * of two placed fixtures. Two sequential PUTs, no server transaction: nothing is
 * blocking, so a network failure mid-swap leaves a visible, recoverable state.
 */
export const swapFixtures = async (a: Fixture, b: Fixture): Promise<void> => {
  await moveFixture(a, { venueId: b.venueId ?? "", kickoffTime: b.kickoffTime ?? "" });
  await moveFixture(b, { venueId: a.venueId ?? "", kickoffTime: a.kickoffTime ?? "" });
};
