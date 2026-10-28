import { describe, expect, it } from "vitest";

import type { CalendarEntry } from "../api";
import { unavailabilitiesToAlert, type RadarUnavailability } from "./venueUnavailabilityRadar";

const TODAY = "2026-02-01";

const unavailability = (over: Partial<RadarUnavailability> = {}): RadarUnavailability => ({
  id: "u1",
  venueId: "v1",
  startDate: "2026-02-10",
  endDate: "2026-02-20",
  label: "travaux",
  ...over,
});

const period = (startDate: string, endDate: string): CalendarEntry =>
  ({ id: `p-${startDate}`, kind: "period", periodType: "closure", title: "Fermeture", startDate, endDate }) as CalendarEntry;

// Promesse 5 — une période qui ferme TOUT le club (vacances / coupure) masque quel que soit le gymnase.
const clubWidePeriod = (periodType: "holiday" | "cutoff", startDate: string, endDate: string): CalendarEntry =>
  ({ id: `cw-${startDate}`, kind: "period", periodType, title: "Vacances", startDate, endDate }) as CalendarEntry;

// Promesse 5 — une FERMETURE servie avec son gymnase (feed `venue_closures` : {venueId, startDate,
// endDate}). C'est la SEULE source, côté front, du gymnase d'une fermeture — `CalendarEntry` n'en
// porte pas. Contrat proposé à NEO : 5ᵉ paramètre OPTIONNEL `venueClosures` (défaut []).
const closureOfVenue = (venueId: string, startDate: string, endDate: string) => ({ venueId, startDate, endDate });

// Signature CIBLE (contrat proposé à NEO) : 5ᵉ paramètre OPTIONNEL `venueClosures`. Le cast garde le
// fichier tsc-propre AVANT l'implémentation (la fonction n'a encore que 4 params) ; il reste valable
// APRÈS (la vraie signature est un sur-ensemble). Aucun effet runtime : la fonction ignore l'arg en trop.
type ToAlertWithVenueClosures = (
  unavailabilities: RadarUnavailability[],
  entries: CalendarEntry[],
  today: string,
  horizonDays: number,
  venueClosures: { venueId: string; startDate: string; endDate: string }[],
) => RadarUnavailability[];
const toAlertWithClosures = unavailabilitiesToAlert as unknown as ToAlertWithVenueClosures;

describe("unavailabilitiesToAlert", () => {
  it("alerte sur une indisponibilité à venir dans l'horizon", () => {
    expect(unavailabilitiesToAlert([unavailability()], [], TODAY, 30)).toHaveLength(1);
  });

  it("garde une indisponibilité DÉJÀ commencée : elle demande toujours un geste", () => {
    const started = unavailability({ startDate: "2026-01-20", endDate: "2026-02-15" });

    expect(unavailabilitiesToAlert([started], [], TODAY, 30)).toHaveLength(1);
  });

  it("écarte une indisponibilité révolue — elle n'appelle plus aucune action", () => {
    const past = unavailability({ startDate: "2026-01-05", endDate: "2026-01-20" });

    expect(unavailabilitiesToAlert([past], [], TODAY, 30)).toEqual([]);
  });

  it("écarte une indisponibilité au-delà de l'horizon", () => {
    const far = unavailability({ startDate: "2026-06-01", endDate: "2026-06-20" });

    expect(unavailabilitiesToAlert([far], [], TODAY, 30)).toEqual([]);
    // Témoin : le même cas sous un horizon large ressort — c'est bien l'horizon qui tranche.
    expect(unavailabilitiesToAlert([far], [], TODAY, 200)).toHaveLength(1);
  });

  it("se tait quand une période CLUB-ENTIER (vacances) couvre TOUTE la plage : elle porte déjà son « Adapter »", () => {
    // Promesse 5 : une période du calendrier ne masque que si elle ferme TOUT le club (vacances /
    // coupure) ; une FERMETURE de gymnase, elle, ne masque que via le feed `venue_closures` du MÊME
    // gymnase (plus jamais une fermeture quelconque de `entries`, dont le gymnase est inconnu ici).
    const covering = [clubWidePeriod("holiday", "2026-02-05", "2026-02-28")];

    expect(unavailabilitiesToAlert([unavailability()], covering, TODAY, 30)).toEqual([]);
  });

  it("alerte encore quand une période club-entier ne couvre la plage QUE partiellement", () => {
    // Les jours hors période ne sont traités par personne : les taire les perdrait.
    const partial = [clubWidePeriod("holiday", "2026-02-05", "2026-02-15")];

    expect(unavailabilitiesToAlert([unavailability()], partial, TODAY, 30)).toHaveLength(1);
  });

  // ── Promesse 5 (bug fondateur BCCL local) : le gymnase de la fermeture compte ──
  // Une indisponibilité ADN (3→6 nov.) ne remontait plus au radar « À traiter » parce qu'une
  // fermeture Matéo prolongée (31/08→19/12), concernant un AUTRE gymnase, couvrait sa plage et la
  // masquait (venueUnavailabilityRadar.ts:44 masque sur toute période couvrante). `CalendarEntry`
  // n'a pas de gymnase → la fermeture doit venir du feed `venue_closures` (venueId). Contrat NEO :
  // 5ᵉ paramètre OPTIONNEL `venueClosures: {venueId, startDate, endDate}[]` ; RadarPanel le fournit
  // via `useVenueClosures()` ; `entries` ne sert plus qu'aux périodes CLUB-ENTIER (holiday/cutoff).
  it("une fermeture d'un AUTRE gymnase couvrant la plage ne masque PLUS l'alerte", () => {
    const u = unavailability({ venueId: "v1" });
    // La fermeture Matéo EST bien une période du calendrier (entries) et sert son gymnase v2 (feed).
    const entries = [period("2026-02-05", "2026-02-28")];
    const otherVenueClosures = [closureOfVenue("v2", "2026-02-05", "2026-02-28")];

    expect(toAlertWithClosures([u], entries, TODAY, 30, otherVenueClosures)).toHaveLength(1);
  });

  it("une fermeture du MÊME gymnase couvrant la plage masque l'alerte (inchangé pour l'utilisateur)", () => {
    const u = unavailability({ venueId: "v1" });
    const sameVenueClosures = [closureOfVenue("v1", "2026-02-05", "2026-02-28")];

    expect(toAlertWithClosures([u], [], TODAY, 30, sameVenueClosures)).toEqual([]);
  });

  it("témoin : une fermeture du même gymnase qui ne couvre la plage que PARTIELLEMENT garde l'alerte", () => {
    const u = unavailability({ venueId: "v1" }); // plage [10, 20]
    const partial = [closureOfVenue("v1", "2026-02-05", "2026-02-15")];

    expect(toAlertWithClosures([u], [], TODAY, 30, partial)).toHaveLength(1);
  });

  it("témoin : une période CLUB-ENTIER (vacances) couvrant la plage masque quel que soit le gymnase", () => {
    const u = unavailability({ venueId: "v1" });
    const holiday = [clubWidePeriod("holiday", "2026-02-05", "2026-02-28")];

    expect(toAlertWithClosures([u], holiday, TODAY, 30, [])).toEqual([]);
  });

  it("trie par date de début pour un ordre stable", () => {
    const later = unavailability({ id: "u2", venueId: "v2", startDate: "2026-02-18", endDate: "2026-02-25" });
    const sooner = unavailability({ id: "u3", venueId: "v3", startDate: "2026-02-03", endDate: "2026-02-06" });

    expect(unavailabilitiesToAlert([later, sooner], [], TODAY, 30).map((u) => u.id)).toEqual(["u3", "u2"]);
  });
});
