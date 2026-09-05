import { describe, expect, it } from "vitest";

import type { CalendarEntry } from "../api";
import { entryIcon, isHolidayAnchor } from "./markers";

/**
 * Retour fondateur 2026-07-24, étendu à la MÈRE le 2026-08-19 : une VACANCE ne porte jamais ⛔
 * — le surlignage amber (et l'emoji de saison) la marque déjà, un ⛔ en plus la ferait passer
 * pour une interdiction. La doctrine masquait les ENFANTS ; la mère holiday (« Vacances d'été »,
 * racine, `periodType=holiday`) recevait encore ⛔ par le défaut « toute période non-cutoff → ⛔ ».
 * Les FERMETURES gardent leur ⛔ (sans amber, seule trace au calendrier), les COUPURES leur 🛑.
 */
const period = (over: Partial<CalendarEntry>): CalendarEntry => ({
  id: "e",
  kind: "period",
  title: "",
  startDate: "2026-01-01",
  endDate: "2026-01-07",
  isDisruptive: false,
  periodType: "closure",
  schoolHolidayId: null,
  parentEntryId: null,
  status: "active",
  createdBy: null,
  redatable: false, redateNeedsPreview: false,
  ...over,
});

describe("entryIcon — la vacance ne porte jamais ⛔", () => {
  it("une MÈRE holiday (racine) ne produit AUCUN marqueur ⛔", () => {
    // RED avant le fix : `entryIcon` renvoyait ⛔ pour toute période non-cutoff, mère vacances
    // comprise — elle s'affichait comme une interdiction sur la grille d'accueil.
    const mother = period({ periodType: "holiday", schoolHolidayId: "sh-1", parentEntryId: null, title: "Vacances d'été" });
    expect(entryIcon(mother)).not.toBe("⛔");
    // Et la mère holiday SANS schoolHolidayId — celle qui échappe à `isHolidayAnchor` et
    // atteint donc réellement `entryIcon` sur la grille — pas de ⛔ non plus.
    expect(entryIcon(period({ periodType: "holiday", schoolHolidayId: null }))).not.toBe("⛔");
  });

  it("une FERMETURE garde son ⛔, une COUPURE son 🛑 (NR)", () => {
    expect(entryIcon(period({ periodType: "closure" }))).toBe("⛔");
    expect(entryIcon(period({ periodType: "cutoff" }))).toBe("🛑");
  });
});

/**
 * `isHolidayAnchor` est la SEULE maison du prédicat « cette entrée ANCRE une vacance scolaire »
 * (P4-121) : RadarPanel (`entryByHoliday`) et DayDialog (l'entrée d'une vacance donnée) l'importent
 * au lieu de refiltrer sur `schoolHolidayId` seul. Le cas décisif — un ENFANT qui porterait
 * (hypothétiquement) un schoolHolidayId → false — est exactement la régression que la garde
 * d'ancrage prévient sur ces deux sites : sans elle, un tel enfant serait pris pour l'ancre.
 */
describe("isHolidayAnchor — la maison du prédicat d'ancrage vacances", () => {
  it("une entrée RACINE avec schoolHolidayId ancre → true", () => {
    expect(isHolidayAnchor(period({ periodType: "holiday", schoolHolidayId: "sh-1", parentEntryId: null }))).toBe(true);
  });

  it("un ENFANT — même en portant (hypothétiquement) un schoolHolidayId — n'ancre PAS → false", () => {
    // Falsification du fix RadarPanel/DayDialog : le filtre `schoolHolidayId`-seul d'avant aurait
    // renvoyé true ici et pris cet enfant pour l'ancre ; la garde `parentEntryId === null` l'exclut.
    expect(isHolidayAnchor(period({ periodType: "holiday", schoolHolidayId: "sh-1", parentEntryId: "mother" }))).toBe(false);
  });

  it("une RACINE sans schoolHolidayId n'ancre rien → false", () => {
    expect(isHolidayAnchor(period({ periodType: "holiday", schoolHolidayId: null, parentEntryId: null }))).toBe(false);
  });

  it("une FERMETURE (racine, sans schoolHolidayId) n'ancre rien → false", () => {
    expect(isHolidayAnchor(period({ periodType: "closure", schoolHolidayId: null, parentEntryId: null }))).toBe(false);
  });
});
