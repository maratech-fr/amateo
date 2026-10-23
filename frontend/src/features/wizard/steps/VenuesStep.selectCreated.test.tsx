import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { type ReactNode, useState } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { WizardFooterContext } from "../lib/footerSlot";
import { WIZARD_TEXTS } from "../texts";
import type { Venue } from "../api";

/**
 * P4-339 — PROMESSE : après l'ajout d'un gymnase, l'écran « Gymnases » sélectionne le gymnase
 * TOUT JUSTE CRÉÉ, pas le précédent. Le repli `?? venues[0]` de la dérivation `selected`
 * (`VenuesStep.tsx:312`) retombait sur le PREMIER gymnase de la liste tant que la requête des
 * gymnases n'avait pas re-fetché la nouvelle entrée : `selectedId` valait déjà l'id du gymnase
 * créé, mais `venues.find(selectedId)` rendait `undefined` (liste encore périmée), donc la carte
 * d'édition affichait le gymnase précédent pendant toute la fenêtre de refetch.
 *
 * On mute la PROD (le vrai `VenuesStep`), pas un double : la requête des gymnases reste à sa valeur
 * d'AVANT la création (`[Alpha]`) pendant que la mutation de création réussit en rendant le gymnase
 * neuf (`Beta`). La promesse est tenue si la carte d'édition montre « Beta » — le gymnase créé — et
 * non « Alpha ». Le gymnase créé est porté par la mutation (`create.data` ET l'argument
 * `onSuccess`) : le correctif peut s'appuyer sur l'un ou l'autre, le test exerce les deux.
 */

const CREATED: Venue = { id: "v2", name: "Beta", color: "#E74C3C", canSplit: false, isActive: true, externalRef: null, address: null, latitude: null, longitude: null };

const h = vi.hoisted(() => ({
  venues: { data: [] as Venue[] },
  created: { value: null as Venue | null },
}));

vi.mock("@/shared/lib/travelStream", () => ({
  useTravelStream: () => ({ connected: false, latest: null }),
}));

vi.mock("../queries", () => ({
  useWizardVenues: () => ({ data: h.venues.data, isSuccess: true }),
  useVenueSlots: () => ({ data: [] }),
  useReservations: () => ({ data: [] }),
  useVenueGeoCheck: () => ({ data: [] }),
  // La création : `mutate` mémorise le gymnase créé (exposé par `data`) ET invoque `onSuccess`
  // avec lui (ce que fait l'API réelle) — la liste des gymnases, elle, N'A PAS encore re-fetché.
  useCreateVenue: () => ({
    mutate: (_body: unknown, opts?: { onSuccess?: (created: Venue) => void }) => {
      h.created.value = CREATED;
      opts?.onSuccess?.(CREATED);
    },
    data: h.created.value,
    isPending: false,
  }),
  useUpdateVenue: () => ({ mutate: vi.fn(), isPending: false }),
  useLocateVenue: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteVenue: () => ({ mutate: vi.fn() }),
  useCreateSlot: () => ({ mutate: vi.fn() }),
  useUpdateSlot: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteSlot: () => ({ mutate: vi.fn() }),
  useDeletionImpact: () => ({ data: undefined, isPending: false, isError: false }),
  useFfbbSalles: () => ({ data: { salles: [] } }),
  useFfbbSallesProches: () => ({ data: { salles: [] } }),
}));

vi.mock("@/features/matches/queries", () => ({ useVenueMatchWindows: () => ({ data: [] }) }));
vi.mock("@/features/matches/MatchWindowsEditor", () => ({ MatchWindowsEditor: () => null }));
vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: { club: { postalCode: "69100" } } }) }));
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

const venue = (id: string, name: string): Venue => ({ id, name, color: "#3498DB", canSplit: false, isActive: true, externalRef: null, address: null, latitude: null, longitude: null });

function Harness() {
  const [extra, setExtra] = useState<ReactNode>(null);
  return (
    <WizardFooterContext.Provider value={{ setFooterExtra: setExtra, setSuppressScrollJump: () => {} }}>
      <VenuesStep />
      <div data-testid="footer-slot">{extra}</div>
    </WizardFooterContext.Provider>
  );
}

beforeEach(() => {
  h.venues.data = [venue("v1", "Alpha")];
  h.created.value = null;
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("VenuesStep — sélection du gymnase tout juste créé (P4-339)", () => {
  it("après l'ajout, la carte d'édition cible le gymnase créé même si la liste n'a pas re-fetché", async () => {
    const user = userEvent.setup();
    renderWithProviders(<Harness />);

    // Au départ, le seul gymnase (Alpha) est sélectionné.
    await screen.findByText(WIZARD_TEXTS.venueEditHeading("Alpha"));

    // Déplier « Ajouter des gymnases » puis créer « Beta ».
    await user.click(screen.getByRole("button", { name: WIZARD_TEXTS.addVenuesSectionTitle }));
    await user.type(screen.getByRole("textbox", { name: WIZARD_TEXTS.venueNameLabel }), "Beta");
    await user.click(screen.getByRole("button", { name: WIZARD_TEXTS.addVenueButton }));

    // La liste des gymnases est VOLONTAIREMENT restée [Alpha] (refetch non terminé) :
    // la carte d'édition doit malgré tout montrer le gymnase créé, Beta — et plus Alpha.
    await waitFor(() => expect(screen.getByText(WIZARD_TEXTS.venueEditHeading("Beta"))).toBeInTheDocument());
    expect(screen.queryByText(WIZARD_TEXTS.venueEditHeading("Alpha"))).not.toBeInTheDocument();
  });
});
