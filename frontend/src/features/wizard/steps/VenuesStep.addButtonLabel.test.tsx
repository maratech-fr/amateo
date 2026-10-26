import { screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { WIZARD_TEXTS } from "../texts";
import type { Venue } from "../api";

/**
 * D10 — PROMESSE : le bouton d'ajout de gymnase garde son NOM ACCESSIBLE complet
 * « Ajouter un gymnase » (clé `WIZARD_TEXTS.addVenueButton`, l'invariant a11y / feedback), mais son
 * texte VISIBLE est raccourci à « Ajouter » (`addVenueButtonShort`) + un complément lu uniquement
 * par le lecteur d'écran (`<span class="sr-only">`, valeur `addVenueButtonSrSuffix`). But : la ligne
 * d'ajout cesse de déborder à l'étroit (le libellé long pesait ~188 px — cf. le NR e2e
 * `tests/e2e/layout-inline-rows.spec.ts`), le nom accessible restant identique.
 *
 * On MUTE la PROD (le vrai `VenuesStep`), pas un double ; le décor est celui, éprouvé, de
 * `VenuesStep.selectCreated.test.tsx`. Sans gymnase, l'accordéon « Ajouter des gymnases » s'ouvre
 * par défaut → la ligne d'ajout (et son bouton) est rendue directement.
 *
 * Écran MIGRÉ (`MIGRATED_FILES`, `hardcodedTextGuard`) : le test vérifie les CLÉS
 * (`addVenueButton`/`addVenueButtonShort`/`addVenueButtonSrSuffix`), jamais une phrase recopiée.
 */

vi.mock("@/shared/lib/travelStream", () => ({
  useTravelStream: () => ({ connected: false, latest: null }),
}));

vi.mock("../queries", () => ({
  useWizardVenues: () => ({ data: [] as Venue[], isSuccess: true }),
  useWizardTeams: () => ({ data: [] }),
  useWizardCoaches: () => ({ data: [] }),
  useVenueSlots: () => ({ data: [] }),
  useReservations: () => ({ data: [] }),
  useVenueGeoCheck: () => ({ data: [] }),
  useCreateVenue: () => ({ mutate: vi.fn(), data: null, isPending: false }),
  useUpdateVenue: () => ({ mutate: vi.fn(), isPending: false }),
  useLocateVenue: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteVenue: () => ({ mutate: vi.fn() }),
  useCreateSlot: () => ({ mutate: vi.fn() }),
  useUpdateSlot: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteSlot: () => ({ mutate: vi.fn() }),
  useDeletionImpact: () => ({ data: undefined, isPending: false, isError: false }),
  useFfbbSalles: () => ({ data: { salles: [] } }),
  useFfbbSallesProches: () => ({ data: { salles: [] } }),
  useFfbbSallesByName: () => ({ data: { postalCode: null, salles: [] }, isPending: false }),
}));

vi.mock("@/features/matches/queries", () => ({ useVenueMatchWindows: () => ({ data: [] }) }));
vi.mock("@/features/matches/MatchWindowsEditor", () => ({ MatchWindowsEditor: () => null }));
vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: { club: { id: "club-1", postalCode: "69100" } } }), useWorkingSeason: () => undefined }));
vi.mock("../parcImport/ParcImportModal", () => ({ ParcImportModal: () => null }));
vi.mock("@/shared/lib/socle", () => ({ useSocleValidated: () => false }));
vi.mock("@/shared/components/ui/venue-select", () => ({ VenueSelect: () => null }));
vi.mock("./slotFields", () => ({ CapacitySelect: () => null, DurationSelect: () => null, GroupLabelField: () => null, SharedSlotHint: () => null }));
vi.mock("./VenueGeocodeField", () => ({ VenueGeocodeField: () => null }));
vi.mock("./VenueAvailabilityGrid", () => ({ VenueAvailabilityGrid: () => null }));
vi.mock("./TravelMatrixModal", () => ({ TravelMatrixModal: () => null }));
vi.mock("../store", () => ({
  useWizardStore: (selector?: (s: { mode: string; calendarEntryId: string | null; clearDeepLinkOrigin: () => void }) => unknown) => {
    const state = { mode: "season", calendarEntryId: null, clearDeepLinkOrigin: () => {} };
    return selector ? selector(state) : state;
  },
}));

import { VenuesStep } from "./VenuesStep";

afterEach(() => {
  vi.restoreAllMocks();
});

beforeEach(() => {
  renderWithProviders(<VenuesStep />);
});

describe("VenuesStep — bouton « Ajouter » : libellé court visible, nom accessible complet (D10)", () => {
  it("garde le NOM ACCESSIBLE complet « Ajouter un gymnase » (invariant feedback / a11y)", () => {
    // Recherche par rôle + nom accessible COMPLET : vrai avant comme après le raccourcissement
    // (le complément sr-only est concaténé au texte visible pour reconstituer le nom).
    expect(screen.getByRole("button", { name: WIZARD_TEXTS.addVenueButton })).toBeInTheDocument();
  });

  it("porte un complément sr-only, de sorte que le texte VISIBLE soit le libellé COURT", () => {
    const addButton = screen.getByRole("button", { name: WIZARD_TEXTS.addVenueButton });

    // Le complément « un gymnase » est présent MAIS réservé au lecteur d'écran (sr-only).
    const srOnly = addButton.querySelector("span.sr-only");
    expect(srOnly).not.toBeNull();
    expect(srOnly?.textContent).toBe(WIZARD_TEXTS.addVenueButtonSrSuffix);

    // Le texte rendu À L'ÉCRAN (tout sauf le nœud sr-only, l'icône n'ayant pas de texte) = le court.
    const visibleText = Array.from(addButton.childNodes)
      .filter((node) => !(node instanceof HTMLElement && node.classList.contains("sr-only")))
      .map((node) => node.textContent ?? "")
      .join("")
      .trim();
    expect(visibleText).toBe(WIZARD_TEXTS.addVenueButtonShort);
  });
});
