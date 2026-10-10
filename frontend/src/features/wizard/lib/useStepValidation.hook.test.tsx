import { renderHook } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const q = <T,>(data: T, isLoading = false) => ({ data, isLoading });

// Mutable per-test query stubs.
let teams: ReturnType<typeof q>;
let venues: ReturnType<typeof q>;
let slots: ReturnType<typeof q>;
let coaches: ReturnType<typeof q>;
let constraintValidation: ReturnType<typeof q>;
let teamOverrides: ReturnType<typeof q>;
let venueOverrides: ReturnType<typeof q>;
let periodSlots: ReturnType<typeof q>;
let reservations: ReturnType<typeof q>;
const store = { reservations: [], mode: "season", calendarEntryId: null as string | null, stepId: "recap" };

// Le plan de la période : ancre des réservations depuis le lot C3 (inv. 5).
// P1-4 PR B — l'exemption fenêtre match vient du module matches (mock voisin).
vi.mock("@/features/matches/queries", () => ({
  useVenueMatchWindows: () => ({ data: [], isLoading: false }),
}));

vi.mock("@/features/cockpit/queries", () => ({
  useSchedulePlanForEntry: () => ({ data: { id: "plan-1" }, isLoading: false }),
  usePeriodAnchor: () => ({ state: "period", planId: "plan-1" }),
  anchorIsWritable: (a: { state: string }) => "period" === a.state || "base" === a.state,
}));
vi.mock("../queries", () => ({
  useWizardTeams: () => teams,
  useWizardVenues: () => venues,
  useVenueSlots: () => slots,
  useWizardCoaches: () => coaches,
  useWizardTeamCoaches: () => q([]),
  useWizardCoachPlayers: () => q([]),
  useConstraintValidation: () => constraintValidation,
  useReservations: () => reservations,
  useTeamPeriodOverrides: () => teamOverrides,
  usePeriodSlots: () => periodSlots,
  useVenuePeriodOverrides: () => venueOverrides,
}));
vi.mock("../store", () => ({
  useWizardStore: (selector: (s: unknown) => unknown) => selector(store),
}));

import { useStepValidation } from "./useStepValidation";

describe("useStepValidation — no false blocking error during load", () => {
  beforeEach(() => {
    teams = q([]);
    venues = q([]);
    slots = q([]);
    coaches = q([]);
    constraintValidation = q(undefined);
    teamOverrides = q([]);
    venueOverrides = q([]);
    reservations = q([]);
    periodSlots = q([]);
    store.mode = "season";
    store.calendarEntryId = null;
  });

  it("stays neutral while a query is still loading (no 'add a team' flash)", () => {
    teams = q([], true); // first load, data defaulted to []
    const { result } = renderHook(() => useStepValidation("teams"));
    expect(result.current.errors).toEqual([]);
  });

  it("reports the empty-teams error only once the query has settled", () => {
    teams = q([]); // loaded, genuinely empty
    const { result } = renderHook(() => useStepValidation("teams"));
    expect(result.current.errors).toEqual(["Ajoutez au moins une équipe."]);
  });

  it("passes when teams exist", () => {
    teams = q([{ id: "t1" }]);
    const { result } = renderHook(() => useStepValidation("teams"));
    expect(result.current.errors).toEqual([]);
  });
});

describe("useStepValidation — les avertissements du serveur remontent au récap", () => {
  beforeEach(() => {
    teams = q([{ id: "t1" }]);
    venues = q([{ id: "v1" }]);
    slots = q([{ venueId: "v1" }]);
    coaches = q([{ id: "c1" }]);
    constraintValidation = q(undefined);
    teamOverrides = q([]);
    venueOverrides = q([]);
    reservations = q([]);
    periodSlots = q([]);
    store.mode = "season";
    store.calendarEntryId = null;
  });

  it("remonte les warnings alors que la validation est VALIDE", () => {
    // ⚠ Le cœur de la règle : un avertissement n'invalide rien (#8), il arrive donc
    // avec `valid: true`. Le lire dans la branche `if (!valid)` ne remonterait jamais rien.
    constraintValidation = q({
      valid: true,
      errors: {},
      conflicts: [],
      warnings: ["« Pas le lundi » vise le gymnase Debarros, désactivé pour cette période : elle ne sera pas appliquée."],
    });
    const { result } = renderHook(() => useStepValidation("recap"));

    expect(result.current.warnings).toEqual(["« Pas le lundi » vise le gymnase Debarros, désactivé pour cette période : elle ne sera pas appliquée."]);
  });

  it("n'invalide pas le récap : un avertissement ne produit aucune erreur", () => {
    constraintValidation = q({ valid: true, errors: {}, conflicts: [], warnings: ["un avertissement"] });
    const { result } = renderHook(() => useStepValidation("recap"));

    expect(result.current.errors).toEqual([]);
  });

  it("reste vide quand le serveur n'envoie pas la clé (serveur antérieur)", () => {
    constraintValidation = q({ valid: true, errors: {}, conflicts: [] });
    const { result } = renderHook(() => useStepValidation("recap"));

    expect(result.current.warnings).toEqual([]);
  });
});

/**
 * P2-15 round 2 — LE VERDICT COMPTE CE QUE L'ÉCRAN AFFICHE.
 *
 * Le récap est passé aux listes ACTIVES de la période ; le verdict, lui, comptait encore
 * les listes de SAISON. Une période dont toutes les équipes sont en pause affichait donc
 * « Équipes 0 » et « Tout est prêt » dans la même vue, bouton « Générer » ouvert — la
 * génération partait et rendait un planning vide.
 */
describe("useStepValidation — période : le verdict compte les ACTIFS", () => {
  beforeEach(() => {
    teams = q([{ id: "t1" }, { id: "t2" }]);
    venues = q([{ id: "v1" }, { id: "v2" }]);
    slots = q([{ venueId: "v1" }, { venueId: "v2" }]);
    coaches = q([{ id: "c1" }]);
    constraintValidation = q(undefined);
    teamOverrides = q([]);
    venueOverrides = q([]);
    reservations = q([]);
    periodSlots = q([{ venueId: "v1" }, { venueId: "v2" }]);
    store.mode = "period";
    store.calendarEntryId = "entry-1";
  });

  it("bloque quand TOUTES les équipes de la période sont en pause, en disant quoi faire", () => {
    teamOverrides = q([{ teamId: "t1", isActive: false }, { teamId: "t2", isActive: false }]);
    const { result } = renderHook(() => useStepValidation("recap"));

    // Le message envoie au bon écran : « ajoutez une équipe » enverrait re-saisir des
    // équipes qui existent déjà.
    expect(result.current.errors).toContain("Aucune équipe n'est active pour cette période — cochez-en au moins une.");
  });

  it("bloque quand TOUS les gymnases de la période sont désactivés", () => {
    venueOverrides = q([{ venueId: "v1", mode: "DISABLED" }, { venueId: "v2", mode: "DISABLED" }]);
    const { result } = renderHook(() => useStepValidation("recap"));

    expect(result.current.errors).toContain("Aucun gymnase n'est actif pour cette période — réactivez-en au moins un.");
  });

  it("laisse passer tant qu'il reste une équipe et un gymnase actifs", () => {
    teamOverrides = q([{ teamId: "t1", isActive: false }]);
    venueOverrides = q([{ venueId: "v1", mode: "DISABLED" }]);
    const { result } = renderHook(() => useStepValidation("recap"));

    expect(result.current.errors).toEqual([]);
  });

  // La sélection d'équipes est la TROISIÈME lecture qui porte la couche : sans elle dans
  // la garde de chargement, `data ?? []` passait pour « aucune pause » et le verdict
  // sortait vert sur une période peut-être vide — la fenêtre exacte que cette règle ferme.
  it("reste PENDING tant que la sélection d'équipes n'est pas lue", () => {
    teamOverrides = q(undefined);
    const { result } = renderHook(() => useStepValidation("recap"));

    expect(result.current.pending).toBe(true);
    expect(result.current.errors).toEqual([]);
  });
});

/**
 * D2 (lot 2) — le verdict du récap PORTE les notices de créneau partagé (une seule vérité, lue
 * par le rail, l'accordéon et le gate). La LOGIQUE de `sharedSlotStatuses` est couverte par
 * `reservationSlots.test.ts` ; ici on vérifie seulement que le verdict les expose, typées.
 */
describe("useStepValidation — recap : les notices de créneau partagé (D2)", () => {
  const sharedSlot = { id: "sl1", venueId: "v1", dayOfWeek: 1, startTime: "18:00", durationMinutes: 90, capacity: 2 };
  beforeEach(() => {
    teams = q([{ id: "t1" }, { id: "t2" }]);
    venues = q([{ id: "v1", name: "Gymnase A", canSplit: true }]);
    slots = q([sharedSlot]);
    coaches = q([{ id: "c1" }]);
    constraintValidation = q(undefined);
    teamOverrides = q([]);
    venueOverrides = q([]);
    periodSlots = q([]);
    reservations = q([]);
    store.mode = "season";
    store.calendarEntryId = null;
    store.stepId = "recap";
  });

  it("expose une notice d'INFORMATION grise sur un créneau partagé sans réservation", () => {
    const { result } = renderHook(() => useStepValidation("recap"));
    expect(result.current.notices).toEqual([
      expect.objectContaining({ key: "sl1", tone: "muted", place: expect.stringContaining("Gymnase A · Lun 18:00") }),
    ]);
  });

  it("expose une notice d'AVERTISSEMENT (orange) sur un créneau partiellement réservé", () => {
    reservations = q([{ id: "r1", teamId: "t1", venueId: "v1", dayOfWeek: 1, startTime: "18:00" }]);
    const { result } = renderHook(() => useStepValidation("recap"));
    expect(result.current.notices).toEqual([
      expect.objectContaining({ key: "sl1", tone: "warning" }),
    ]);
  });

  it("se tait quand le créneau partagé est plein", () => {
    reservations = q([
      { id: "r1", teamId: "t1", venueId: "v1", dayOfWeek: 1, startTime: "18:00" },
      { id: "r2", teamId: "t2", venueId: "v1", dayOfWeek: 1, startTime: "18:00" },
    ]);
    const { result } = renderHook(() => useStepValidation("recap"));
    expect(result.current.notices).toEqual([]);
  });
});
