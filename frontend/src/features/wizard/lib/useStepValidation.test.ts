import { act, cleanup, renderHook } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import type { Reservation, Team, Venue, VenueTrainingSlot } from "../api";
import { computeReservationWarnings, useStepValidation } from "./useStepValidation";
import { useWizardStore } from "../store";

// Pilote l'état de l'ancre de période dans les mocks (union discriminée, P4-20).
const anchorState: { failed: boolean; absent: boolean } = { failed: false, absent: false };

// A gym with NO availability slot — the "sans créneau" rule fires on it in base
// mode but must stay silent in period mode (slots are inherited & read-only).
// Le plan de la période : ancre des réservations depuis le lot C3 (inv. 5).
// P1-4 PR B — l'exemption fenêtre match vient du module matches ; sans mock,
// le hook réel exigerait un QueryClient. État mutable pour tester l'exemption.
const matchWindowsState = vi.hoisted(() => ({ windows: [] as { id: string; venueId: string; dayOfWeek: number; startTime: string; endTime: string }[] }));
vi.mock("@/features/matches/queries", () => ({
  useVenueMatchWindows: () => ({ data: matchWindowsState.windows, isLoading: false }),
}));

vi.mock("@/features/cockpit/queries", () => ({
  useSchedulePlanForEntry: () => ({ data: { id: "plan-1" }, isLoading: false }),
  // Ancre résolue seulement si une entrée est fournie : mode période sans entrée = plan
  // non résolu (planId null), comme dans le vrai code.
  usePeriodAnchor: (entryId: string | null) =>
    null === entryId
      ? { state: "base", planId: null }
      : anchorState.failed
        ? { state: "failed", planId: null, retry: () => {} }
        : anchorState.absent
          ? { state: "absent", planId: null }
          : { state: "period", planId: "plan-1" },
  anchorIsWritable: (a: { state: string }) => "period" === a.state || "base" === a.state,
}));
vi.mock("../queries", () => ({
  useWizardTeams: () => ({ data: [{ id: "t1", name: "SM1", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 1, isActive: true }], isLoading: false }),
  useWizardVenues: () => ({ data: [{ id: "v1", name: "Gymnase A", color: null, canSplit: false, isActive: true }], isLoading: false }),
  useVenueSlots: () => ({ data: [], isLoading: false }),
  useWizardCoaches: () => ({ data: [{ id: "co1", firstName: "Ana", lastName: "A", email: null, isEmployee: true, isActive: true }], isLoading: false }),
  useWizardTeamCoaches: () => ({ data: [] }),
  useWizardCoachPlayers: () => ({ data: [] }),
  useConstraintValidation: () => ({ data: undefined, isLoading: false }),
  useReservations: () => ({ data: [] }),
  usePeriodSlots: () => ({ data: periodSlotsState.data, isLoading: periodSlotsState.isLoading, isError: periodSlotsState.isError }),
  useVenuePeriodOverrides: () => ({ data: [], isError: false }),
  useTeamPeriodOverrides: () => ({ data: [], isError: teamOverridesState.isError }),
}));

const periodSlotsState: { data: unknown[] | undefined; isLoading: boolean; isError: boolean } = { data: [], isLoading: false, isError: false };
const teamOverridesState = { isError: false };

const team = (id: string, name: string, sessionsPerWeek: number): Team => ({
  id,
  name,
  sportCategoryId: "c",
  priorityTierId: 1,
  tierOrder: 0,
  gender: null,
  level: null,
  sessionsPerWeek,
  isActive: true,
});

const venue = (id: string, name: string, canSplit: boolean): Venue => ({ id, name, color: null, canSplit, isActive: true });

const slot = (venueId: string, dayOfWeek: number, startTime: string, capacity: number): VenueTrainingSlot => ({
  id: `${venueId}-${dayOfWeek}-${startTime}`,
  venueId,
  dayOfWeek,
  startTime,
  durationMinutes: 90,
  capacity,
});

const reservation = (id: string, teamId: string, venueId: string, dayOfWeek: number, startTime: string): Reservation => ({
  id,
  schedulePlanId: null, // réservation de BASE (structure partagée, inv. 6)
  teamId,
  venueId,
  dayOfWeek,
  startTime,
  durationMinutes: 90,
});

describe("computeReservationWarnings (W6)", () => {
  it("returns no warning for a clean set", () => {
    const teams = [team("t1", "U13", 1), team("t2", "U15", 1)];
    const venues = [venue("v1", "Gymnase A", false)];
    const slots = [slot("v1", 2, "18:00", 1), slot("v1", 4, "18:00", 1)];
    const reservations = [reservation("r1", "t1", "v1", 2, "18:00"), reservation("r2", "t2", "v1", 4, "18:00")];
    expect(computeReservationWarnings(reservations, teams, venues, slots)).toEqual([]);
  });

  it("warns when a non-splittable slot is shared by two teams", () => {
    const teams = [team("t1", "U13", 1), team("t2", "U15", 1)];
    const venues = [venue("v1", "Gymnase A", false)];
    const slots = [slot("v1", 2, "18:00", 1)];
    const reservations = [reservation("r1", "t1", "v1", 2, "18:00"), reservation("r2", "t2", "v1", 2, "18:00")];
    const warnings = computeReservationWarnings(reservations, teams, venues, slots);
    expect(warnings).toHaveLength(1);
    expect(warnings[0]).toContain("Créneau partagé par 2 équipes (max 1)");
  });

  // D-03 — la porte avait sa PROPRE copie de `slotKey` et du calcul de capacité, et les deux
  // avaient divergé de `reservationSlots` (la source, dont le Récap se sert). Ces deux cas
  // épinglent les deux écarts : l'un faisait crier « max 1 » sur un créneau à 2 places.
  it("apparie la réservation au créneau même quand l'API rend les secondes (D-03)", () => {
    const teams = [team("t1", "U13", 1), team("t2", "U15", 1)];
    const venues = [venue("v1", "Gymnase A", true)];
    // Le créneau porte « 18:00:00 » (forme servie par l'API), la réservation « 18:00 ».
    const slots = [slot("v1", 2, "18:00:00", 2)];
    const reservations = [reservation("r1", "t1", "v1", 2, "18:00"), reservation("r2", "t2", "v1", 2, "18:00")];
    // Sans normalisation, la capacité du créneau n'était pas retrouvée : `?? 1` s'appliquait
    // et l'étape annonçait « max 1 » pendant que le Récap affichait « 2 places ».
    expect(computeReservationWarnings(reservations, teams, venues, slots)).toEqual([]);
  });

  it("ne rétrograde pas la capacité quand le gymnase n'est pas encore chargé (D-03)", () => {
    const teams = [team("t1", "U13", 1), team("t2", "U15", 1)];
    const slots = [slot("v1", 2, "18:00", 2)];
    const reservations = [reservation("r1", "t1", "v1", 2, "18:00"), reservation("r2", "t2", "v1", 2, "18:00")];
    // Liste des gymnases en vol : `effectiveSlotCapacity` fait confiance à `slot.capacity`
    // (le backend force 1 sur un gymnase non divisible), donc une requête en retard ne peut
    // pas faire disparaître une place — la copie locale, elle, retombait sur 1.
    expect(computeReservationWarnings(reservations, teams, [], slots)).toEqual([]);
  });

  it("nomme la cause quand un créneau à 2 places est saisi sur un gymnase NON divisible (v2)", () => {
    // Le créneau porte une capacité BRUTE de 2 (intention « deux équipes »), mais le gymnase
    // n'est pas déclaré divisible : la capacité effective retombe à 1. Le message générique
    // « max 1 » laissait le gestionnaire deviner POURQUOI ; ici on nomme la cause et le geste.
    const teams = [team("t1", "U13", 1), team("t2", "U15", 1)];
    const venues = [venue("v1", "Gymnase A", false)];
    const slots = [slot("v1", 2, "18:00", 2)];
    const reservations = [reservation("r1", "t1", "v1", 2, "18:00"), reservation("r2", "t2", "v1", 2, "18:00")];
    const warnings = computeReservationWarnings(reservations, teams, venues, slots);
    expect(warnings).toHaveLength(1);
    expect(warnings[0]).toContain("n'est pas déclaré divisible");
    expect(warnings[0]).toContain("Gymnase A");
  });

  it("garde le message générique quand la cause n'est PAS la divisibilité (créneau brut à 1)", () => {
    // Capacité brute 1 sur gymnase non divisible : le partage n'a rien à voir avec « terrain
    // divisible » — le message reste le générique « max 1 » (cas historique l.94-102).
    const teams = [team("t1", "U13", 1), team("t2", "U15", 1)];
    const venues = [venue("v1", "Gymnase A", false)];
    const slots = [slot("v1", 2, "18:00", 1)];
    const reservations = [reservation("r1", "t1", "v1", 2, "18:00"), reservation("r2", "t2", "v1", 2, "18:00")];
    const warnings = computeReservationWarnings(reservations, teams, venues, slots);
    expect(warnings[0]).toContain("Créneau partagé par 2 équipes (max 1)");
    expect(warnings[0]).not.toContain("divisible");
  });

  it("does not warn when a splittable slot with capacity 2 holds two teams", () => {
    const teams = [team("t1", "U13", 1), team("t2", "U15", 1)];
    const venues = [venue("v1", "Gymnase A", true)];
    const slots = [slot("v1", 2, "18:00", 2)];
    const reservations = [reservation("r1", "t1", "v1", 2, "18:00"), reservation("r2", "t2", "v1", 2, "18:00")];
    expect(computeReservationWarnings(reservations, teams, venues, slots)).toEqual([]);
  });

  // P3-20 (décision fondateur 2026-08-06) — « plus de réservations que de séances » est
  // désormais une INCOHÉRENCE qui BLOQUE, rendue par le serveur
  // (`ValidateConstraintsController::overBookedTeamBlockers`). Cet écran ne doit plus en
  // faire un avertissement : deux vérités, dont une plus tendre que la porte (§7.2 pt 2).
  it("ne dit plus « trop de réservations » ici — c'est un bloqueur SERVEUR, pas un avertissement d'écran", () => {
    const teams = [team("t1", "U13", 2)];
    const venues = [venue("v1", "Gymnase A", false)];
    const slots = [slot("v1", 1, "18:00", 1), slot("v1", 3, "18:00", 1), slot("v1", 5, "18:00", 1)];
    const reservations = [
      reservation("r1", "t1", "v1", 1, "18:00"),
      reservation("r2", "t1", "v1", 3, "18:00"),
      reservation("r3", "t1", "v1", 5, "18:00"),
    ];
    const warnings = computeReservationWarnings(reservations, teams, venues, slots);
    expect(warnings.filter((w) => w.includes("réservations pour"))).toEqual([]);
  });

  it("warns when a team has two sessions on the same day", () => {
    const teams = [team("t1", "U13", 2)];
    const venues = [venue("v1", "Gymnase A", false)];
    const slots = [slot("v1", 2, "18:00", 1), slot("v1", 2, "20:00", 1)];
    const reservations = [reservation("r1", "t1", "v1", 2, "18:00"), reservation("r2", "t1", "v1", 2, "20:00")];
    const warnings = computeReservationWarnings(reservations, teams, venues, slots);
    expect(warnings).toContainEqual("U13 : 2 séances le même jour (mardi).");
  });
});

describe("useStepValidation — venue slot rule (période : #8 PR-B, la grille est éditable)", () => {
  afterEach(() => {
    // FRT-34 — démonter le hook (TestComponent) AVANT de remettre le store à zéro, sinon le
    // set(...) re-rend un composant encore monté hors act. Le cleanup() global de RTL ne passe
    // qu'APRÈS cet afterEach.
    cleanup();
    useWizardStore.setState({ mode: "season", calendarEntryId: null, stepId: "teams" });
  });

  it("flags a gym without a slot in base (season) mode", () => {
    useWizardStore.setState({ mode: "season", calendarEntryId: null, stepId: "venues" });
    const { result } = renderHook(() => useStepValidation("venues"));
    expect(result.current.errors.some((e) => /sans créneau/.test(e))).toBe(true);
  });

  it("épargne un gymnase de MATCH (≥ 1 fenêtre) sans créneau d'entraînement — P1-4 PR B", () => {
    // Un gymnase loué pour les matchs seulement n'a légitimement aucun créneau
    // d'entraînement : la fenêtre match lève le blocage, en socle comme en période.
    matchWindowsState.windows = [{ id: "w1", venueId: "v1", dayOfWeek: 6, startTime: "14:00", endTime: "22:00" }];
    try {
      useWizardStore.setState({ mode: "season", calendarEntryId: null, stepId: "venues" });
      const { result } = renderHook(() => useStepValidation("venues"));
      expect(result.current.errors).toEqual([]);

      // FRT-34 — écriture de store en MILIEU de test : le premier renderHook est encore monté,
      // le set(...) le re-rend → act() pour absorber cette mise à jour.
      act(() => {
        useWizardStore.setState({ mode: "period", calendarEntryId: "e1", stepId: "venues" });
      });
      const { result: periodResult } = renderHook(() => useStepValidation("venues"));
      expect(periodResult.current.errors).toEqual([]);
    } finally {
      matchWindowsState.windows = [];
    }
  });

  it("flags an ACTIVE gym without a period slot — la période possède sa grille, plus d'héritage lecture seule", () => {
    // Avant #8 la règle était neutralisée en période (grille héritée). Depuis PR-B la
    // période édite sa grille : vider la grille du seul gymnase doit bloquer « Suivant »,
    // sinon la période se génère à vide sans un mot (revue #8 PR-B, finding #5).
    useWizardStore.setState({ mode: "period", calendarEntryId: "e1", stepId: "venues" });
    const { result } = renderHook(() => useStepValidation("venues"));
    expect(result.current.errors.some((e) => /sans créneau/.test(e))).toBe(true);
  });

  it("ne bloque PAS tant que le plan de la période n'est pas résolu (faux positif de chargement)", () => {
    // planId null (via calendarEntryId null → l'ancre n'est pas résolue dans le vrai code) :
    // les créneaux de la période ne sont pas encore lus, on reste neutre plutôt que de
    // crier « sans créneau » sur une grille qu'on n'a pas chargée.
    useWizardStore.setState({ mode: "period", calendarEntryId: null, stepId: "venues" });
    const { result } = renderHook(() => useStepValidation("venues"));
    expect(result.current.errors.some((e) => /sans créneau/.test(e))).toBe(false);
  });

  // NR T3 (P4-20, décision fondateur option A) — quand l'ancre de la période n'a pas pu
  // être chargée, on ne sait RIEN de ses réglages. Le récap doit le dire et bloquer :
  // un verdict vert sur une période cassée est le pire des deux mensonges, et un simple
  // `pending` ressemblerait à « ça charge » — le travers qu'on corrige.
  it("BLOQUE et le dit quand l'ancre de la période a échoué (jamais un récap vert)", () => {
    anchorState.failed = true;
    useWizardStore.setState({ mode: "period", calendarEntryId: "e1", stepId: "recap" });

    const { result } = renderHook(() => useStepValidation("recap"));

    expect(result.current.errors).toContain("La période n'a pas pu être chargée — impossible de vérifier ses réglages.");
    expect(result.current.pending).not.toBe(true);
    anchorState.failed = false;
  });

  // NR — une grille de période qui n'a PAS chargé ne doit pas être lue comme vide.
  // Le panneau disait correctement « Impossible de charger la grille », pendant que le
  // VERDICT fabriquait « Gymnase(s) sans créneau : … » en nommant tous les gymnases :
  // deux écrans, deux vérités, et le gestionnaire poussé à re-dessiner des créneaux
  // qui existent déjà (doublons).
  it("dit l'échec de la grille au lieu de fabriquer « gymnase sans créneau »", () => {
    periodSlotsState.isError = true;
    periodSlotsState.data = undefined; // échec SANS cache : le seul cas qui doit bloquer
    useWizardStore.setState({ mode: "period", calendarEntryId: "e1", stepId: "venues" });

    const { result } = renderHook(() => useStepValidation("venues"));

    expect(result.current.errors.some((e) => /sans créneau/.test(e))).toBe(false);
    expect(result.current.errors).toContain("La grille de la période n'a pas pu être chargée — impossible de vérifier ses créneaux.");
    periodSlotsState.isError = false;
    periodSlotsState.data = [];
  });

  // NR — une période SANS espace de travail est un état réel, pas un chargement.
  it("dit qu'une période sans espace de travail doit être adaptée (pas un pending éternel)", () => {
    anchorState.absent = true;
    useWizardStore.setState({ mode: "period", calendarEntryId: "e1", stepId: "recap" });

    const { result } = renderHook(() => useStepValidation("recap"));

    expect(result.current.pending).not.toBe(true);
    expect(result.current.errors.some((e) => /Adapter/.test(e))).toBe(true);
    anchorState.absent = false;
  });

  it("ne bloque PAS pendant que la query des créneaux de période charge (faux positif)", () => {
    // Round 2 finding #4 : le plan peut être résolu (ready) alors que la query period_slots
    // n'a pas encore chargé (data []). Armer la règle là dessus criait « sans créneau » sur
    // une grille en fait pleine. On attend que la query ait chargé.
    // Premier chargement = AUCUNE donnée encore (pas seulement `isLoading`) : c'est
    // l'absence de donnée qui interdit de conclure, pas le drapeau (readState).
    periodSlotsState.isLoading = true;
    periodSlotsState.data = undefined;
    useWizardStore.setState({ mode: "period", calendarEntryId: "e1", stepId: "venues" });
    const { result } = renderHook(() => useStepValidation("venues"));
    expect(result.current.errors.some((e) => /sans créneau/.test(e))).toBe(false);
    periodSlotsState.isLoading = false;
    periodSlotsState.data = [];
  });
});;
