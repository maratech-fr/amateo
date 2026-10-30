import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { type ReactNode, useState } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { Toaster } from "@/shared/components/ui/toaster";
import { useToastStore } from "@/shared/stores/toastStore";
import { renderWithProviders } from "@/test/utils";

import { WizardFooterContext } from "../lib/footerSlot";
import { WIZARD_TEXTS } from "../texts";
import type { FfbbSalle, Venue, VenuePayload } from "../api";

/**
 * Finitions d'interface (essai ALAP, 30/10, points 2 et 12) — ROUGE option A. PROMESSES :
 *  - après un ajout, l'accordéon « Ajouter des gymnases » se referme, quel que soit le chemin ;
 *  - Associer et Dissocier une salle FFBB se font sur la carte du gymnase, hors de l'accordéon, et
 *    passent par une confirmation (le menu « Associer à… » quitte la liste des salles) ;
 *  - Dissocier ne laisse que le NOM : adresse et GPS sont à ressaisir, à l'écran aussi ;
 *  - le toast « Annuler » restaure ce que le geste a changé.
 *
 * Niveau : composant, on exerce le vrai `VenuesStep`, le vrai champ d'adresse et le vrai `Toaster`.
 * La règle serveur `externalRef: ""` est prouvée par `backend/tests/Integration/Api/VenueDetachFfbbApiTest.php`.
 *
 * Contrat pour NEO :
 *  - clés de `WIZARD_TEXTS` : `associateVenueMenu(venue)`, `associateVenueTitle(salle, venue)`,
 *    `associateVenueConfirm`, `dissociateVenueButton(venue)`, `dissociateVenueTitle(venue)`,
 *    `dissociateVenueConfirm`, `venueDissociated(venue)`, `undoAction` ;
 *  - le toast porte une action : `toast.success(message, { action: { label, onClick } })`, rendue en
 *    bouton par `Toaster`. La mutation suit le patron existant `mutate(vars, { onSuccess })`.
 */

const SALLE: FfbbSalle = {
  name: "GYMNASE DU MONT BLANC",
  address: "10 RUE DU MONT BLANC",
  city: "Villeneuve",
  postalCode: "69000",
  externalRef: "166900042",
  latitude: "45.81732",
  longitude: "4.89901",
};

const venueOf = (over: Partial<Venue> = {}): Venue => ({
  id: "v1",
  name: "Alpha",
  color: "#3498DB",
  canSplit: false,
  isActive: true,
  externalRef: null,
  address: null,
  latitude: null,
  longitude: null,
  ...over,
});

const LINKED = venueOf({ externalRef: "166900001", address: "1 rue du Stade", latitude: "45.7000000", longitude: "4.8000000" });

type UpdateVars = { id: string; body: VenuePayload };

const h = vi.hoisted(() => ({
  venues: { data: [] as Venue[] },
  created: { value: null as Venue | null },
  createShouldSucceed: { value: true },
  updateCalls: [] as Array<{ id: string; body: VenuePayload }>,
  nameSearch: { salles: [] as FfbbSalle[] },
  nearby: { salles: [] as FfbbSalle[] },
}));

vi.mock("@/shared/lib/travelStream", () => ({ useTravelStream: () => ({ connected: false, latest: null }) }));

vi.mock("../queries", () => ({
  useWizardVenues: () => ({ data: h.venues.data, isSuccess: true }),
  useWizardTeams: () => ({ data: [] }),
  useWizardCoaches: () => ({ data: [] }),
  useVenueSlots: () => ({ data: [] }),
  useReservations: () => ({ data: [] }),
  useVenueGeoCheck: () => ({ data: [] }),
  useCreateVenue: () => ({
    mutate: (body: VenuePayload, opts?: { onSuccess?: (created: Venue) => void }) => {
      if (!h.createShouldSucceed.value) {
        return;
      }
      const created = venueOf({ id: "created-id", name: body.name });
      h.created.value = created;
      opts?.onSuccess?.(created);
    },
    data: h.created.value,
    isPending: false,
  }),
  // Rejoue la règle serveur sur la liste, pour que la carte se redessine comme après un vrai refetch.
  useUpdateVenue: () => ({
    mutate: (vars: UpdateVars, opts?: { onSuccess?: () => void }) => {
      h.updateCalls.push(vars);
      h.venues.data = h.venues.data.map((v) => {
        if (v.id !== vars.id) {
          return v;
        }
        const cleared = "" === vars.body.externalRef ? { externalRef: null, address: null, latitude: null, longitude: null } : {};
        const provided = Object.fromEntries(Object.entries(vars.body).filter(([key, value]) => null != value && !("" === value && "externalRef" === key)));
        return { ...v, ...cleared, ...provided };
      });
      opts?.onSuccess?.();
    },
    isPending: false,
  }),
  useLocateVenue: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteVenue: () => ({ mutate: vi.fn() }),
  useCreateSlot: () => ({ mutate: vi.fn() }),
  useUpdateSlot: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteSlot: () => ({ mutate: vi.fn() }),
  useDeletionImpact: () => ({ data: undefined, isPending: false, isError: false }),
  useFfbbSalles: () => ({ data: { salles: [] } }),
  useFfbbSallesProches: () => ({ data: { salles: h.nearby.salles } }),
  useFfbbSallesByName: () => ({ data: { postalCode: null, salles: h.nameSearch.salles }, isPending: false }),
}));

vi.mock("@/features/matches/queries", () => ({ useVenueMatchWindows: () => ({ data: [] }) }));
vi.mock("@/features/matches/MatchWindowsEditor", () => ({ MatchWindowsEditor: () => null }));
vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: { club: { id: "club-1", postalCode: "69000" } } }), useWorkingSeason: () => undefined }));
vi.mock("../parcImport/ParcImportModal", () => ({ ParcImportModal: () => null }));
vi.mock("@/shared/lib/socle", () => ({ useSocleValidated: () => false }));
vi.mock("@/shared/components/ui/venue-select", () => ({ VenueSelect: () => null }));
vi.mock("./slotFields", () => ({ CapacitySelect: () => null, DurationSelect: () => null, GroupLabelField: () => null, SharedSlotHint: () => null }));
vi.mock("./VenueAvailabilityGrid", () => ({ VenueAvailabilityGrid: () => null }));
vi.mock("./TravelMatrixModal", () => ({ TravelMatrixModal: () => null }));
vi.mock("../store", () => ({
  useWizardStore: (selector?: (s: { mode: string; calendarEntryId: string | null; clearDeepLinkOrigin: () => void }) => unknown) => {
    const state = { mode: "season", calendarEntryId: null, clearDeepLinkOrigin: () => {} };
    return selector ? selector(state) : state;
  },
}));

import { VenuesStep } from "./VenuesStep";

function Harness() {
  const [extra, setExtra] = useState<ReactNode>(null);
  return (
    <WizardFooterContext.Provider value={{ setFooterExtra: setExtra, setSuppressScrollJump: () => {} }}>
      <VenuesStep />
      <div>{extra}</div>
      <Toaster />
    </WizardFooterContext.Provider>
  );
}

const accordion = (): HTMLElement => screen.getByRole("button", { name: WIZARD_TEXTS.addVenuesSectionTitle });

beforeEach(() => {
  h.venues.data = [venueOf()];
  h.created.value = null;
  h.createShouldSucceed.value = true;
  h.updateCalls = [];
  h.nameSearch.salles = [];
  h.nearby.salles = [];
  useToastStore.setState({ toasts: [] });
});

describe("VenuesStep — l'accordéon d'ajout se referme après un ajout", () => {
  it("après un ajout par le formulaire, y compris le premier gymnase du club", async () => {
    const user = userEvent.setup();
    h.venues.data = [];
    renderWithProviders(<Harness />);
    expect(accordion()).toHaveAttribute("aria-expanded", "true");

    await user.type(screen.getByRole("textbox", { name: WIZARD_TEXTS.venueNameLabel }), "Beta");
    await user.click(screen.getByRole("button", { name: WIZARD_TEXTS.addVenueButton }));

    await waitFor(() => expect(accordion()).toHaveAttribute("aria-expanded", "false"));
  });

  it("après un ajout depuis la liste « à proximité »", async () => {
    const user = userEvent.setup();
    h.nearby.salles = [SALLE];
    renderWithProviders(<Harness />);

    await user.click(accordion());
    await user.click(screen.getByRole("button", { name: WIZARD_TEXTS.addTag }));

    await waitFor(() => expect(accordion()).toHaveAttribute("aria-expanded", "false"));
  });

  it("après un ajout depuis la recherche par nom", async () => {
    const user = userEvent.setup();
    h.nameSearch.salles = [SALLE];
    renderWithProviders(<Harness />);

    await user.click(accordion());
    await user.type(screen.getByRole("textbox", { name: WIZARD_TEXTS.venueNameLabel }), "MONT BLANC");
    await user.click(await screen.findByRole("button", { name: (n: string) => n.includes(SALLE.name) }));

    await waitFor(() => expect(accordion()).toHaveAttribute("aria-expanded", "false"));
  });

  it("reste ouvert et garde le nom saisi quand l'ajout échoue, pour que le gestionnaire puisse corriger", async () => {
    const user = userEvent.setup();
    h.createShouldSucceed.value = false;
    renderWithProviders(<Harness />);

    await user.click(accordion());
    await user.type(screen.getByRole("textbox", { name: WIZARD_TEXTS.venueNameLabel }), "Beta");
    await user.click(screen.getByRole("button", { name: WIZARD_TEXTS.addVenueButton }));

    expect(accordion()).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByRole("textbox", { name: WIZARD_TEXTS.venueNameLabel })).toHaveValue("Beta");
  });
});

describe("VenuesStep — Dissocier une salle FFBB, sur la carte du gymnase", () => {
  const dissociateButton = (): HTMLElement => screen.getByRole("button", { name: WIZARD_TEXTS.dissociateVenueButton("Alpha") });
  const dissociateDialog = (): HTMLElement => screen.getByRole("dialog", { name: WIZARD_TEXTS.dissociateVenueTitle("Alpha") });

  it("n'est proposé que sur un gymnase lié, accordéon fermé", () => {
    h.venues.data = [LINKED];
    renderWithProviders(<Harness />);

    expect(accordion()).toHaveAttribute("aria-expanded", "false");
    expect(dissociateButton()).toBeInTheDocument();
  });

  it("n'est pas proposé sur un gymnase sans salle FFBB", () => {
    renderWithProviders(<Harness />);

    expect(screen.queryByRole("button", { name: WIZARD_TEXTS.dissociateVenueButton("Alpha") })).not.toBeInTheDocument();
  });

  it("n'écrit rien sans confirmation, ni après « Annuler » dans la boîte de dialogue", async () => {
    const user = userEvent.setup();
    h.venues.data = [LINKED];
    renderWithProviders(<Harness />);

    await user.click(dissociateButton());
    expect(h.updateCalls).toHaveLength(0);

    await user.click(within(dissociateDialog()).getByRole("button", { name: "Annuler" }));
    expect(h.updateCalls).toHaveLength(0);
  });

  it("confirmé : envoie externalRef vide et laisse le nom, puis la fiche redemande l'adresse", async () => {
    const user = userEvent.setup();
    h.venues.data = [LINKED];
    renderWithProviders(<Harness />);
    // Gymnase localisé : le champ d'adresse est replié.
    expect(screen.queryByRole("textbox", { name: "Adresse" })).not.toBeInTheDocument();

    await user.click(dissociateButton());
    await user.click(within(dissociateDialog()).getByRole("button", { name: WIZARD_TEXTS.dissociateVenueConfirm }));

    expect(h.updateCalls).toEqual([{ id: "v1", body: expect.objectContaining({ name: "Alpha", externalRef: "" }) }]);
    // Le champ garde son état local tant qu'il n'est pas remonté : il doit repartir vide, à ressaisir.
    expect(await screen.findByRole("textbox", { name: "Adresse" })).toHaveValue("");
  });

  it("le toast « Annuler » restaure la salle, l'adresse et le GPS", async () => {
    const user = userEvent.setup();
    h.venues.data = [LINKED];
    renderWithProviders(<Harness />);

    await user.click(dissociateButton());
    await user.click(within(dissociateDialog()).getByRole("button", { name: WIZARD_TEXTS.dissociateVenueConfirm }));
    expect(await screen.findByText(WIZARD_TEXTS.venueDissociated("Alpha"))).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: WIZARD_TEXTS.undoAction }));

    expect(h.updateCalls.at(-1)).toEqual({
      id: "v1",
      body: expect.objectContaining({ name: "Alpha", externalRef: LINKED.externalRef, address: LINKED.address, latitude: LINKED.latitude, longitude: LINKED.longitude }),
    });
  });
});

describe("VenuesStep — Associer une salle FFBB, sur la carte du gymnase", () => {
  const associate = async (user: ReturnType<typeof userEvent.setup>): Promise<void> => {
    await user.click(screen.getByRole("button", { name: WIZARD_TEXTS.associateVenueMenu("Alpha") }));
    await user.click(screen.getByRole("menuitem", { name: SALLE.name }));
  };
  const confirm = async (user: ReturnType<typeof userEvent.setup>): Promise<void> => {
    const dialog = screen.getByRole("dialog", { name: WIZARD_TEXTS.associateVenueTitle(SALLE.name, "Alpha") });
    await user.click(within(dialog).getByRole("button", { name: WIZARD_TEXTS.associateVenueConfirm }));
  };

  it("le menu « Associer à… » a quitté la liste des salles de l'accordéon", async () => {
    const user = userEvent.setup();
    h.nearby.salles = [SALLE];
    renderWithProviders(<Harness />);

    await user.click(accordion());

    const row = screen.getByText(SALLE.name).closest("li") as HTMLElement;
    expect(within(row).queryByRole("button", { name: /associer/i })).not.toBeInTheDocument();
  });

  it("n'est pas proposé sur un gymnase déjà lié", () => {
    h.venues.data = [LINKED];
    h.nearby.salles = [SALLE];
    renderWithProviders(<Harness />);

    expect(screen.queryByRole("button", { name: WIZARD_TEXTS.associateVenueMenu("Alpha") })).not.toBeInTheDocument();
  });

  it("choisir une salle n'écrit rien avant la confirmation, puis pose le numéro fédéral et le GPS sans renommer", async () => {
    const user = userEvent.setup();
    h.nearby.salles = [SALLE];
    renderWithProviders(<Harness />);

    await associate(user);
    expect(h.updateCalls).toHaveLength(0);

    await confirm(user);
    expect(h.updateCalls).toEqual([
      { id: "v1", body: expect.objectContaining({ name: "Alpha", externalRef: SALLE.externalRef, latitude: SALLE.latitude, longitude: SALLE.longitude }) },
    ]);
    expect(await screen.findByText(WIZARD_TEXTS.salleLinkedToVenue(SALLE.name, "Alpha"))).toBeInTheDocument();
  });

  it("le toast « Annuler » retire la salle et rend la position saisie avant l'association", async () => {
    const user = userEvent.setup();
    h.venues.data = [venueOf({ address: "2 rue Basse", latitude: "45.1000000", longitude: "4.1000000" })];
    h.nearby.salles = [SALLE];
    renderWithProviders(<Harness />);

    await associate(user);
    await confirm(user);
    await user.click(await screen.findByRole("button", { name: WIZARD_TEXTS.undoAction }));

    expect(h.updateCalls.at(-1)).toEqual({
      id: "v1",
      body: expect.objectContaining({ name: "Alpha", externalRef: "", address: "2 rue Basse", latitude: "45.1000000", longitude: "4.1000000" }),
    });
  });

  it("un nouveau geste sur le même gymnase remplace le toast « Annuler » précédent : son retour arrière ne vaut plus", async () => {
    const user = userEvent.setup();
    h.venues.data = [LINKED];
    h.nearby.salles = [SALLE];
    renderWithProviders(<Harness />);

    await user.click(screen.getByRole("button", { name: WIZARD_TEXTS.dissociateVenueButton("Alpha") }));
    const dissociate = screen.getByRole("dialog", { name: WIZARD_TEXTS.dissociateVenueTitle("Alpha") });
    await user.click(within(dissociate).getByRole("button", { name: WIZARD_TEXTS.dissociateVenueConfirm }));
    expect(await screen.findByText(WIZARD_TEXTS.venueDissociated("Alpha"))).toBeInTheDocument();

    await associate(user);
    await confirm(user);

    expect(await screen.findByText(WIZARD_TEXTS.salleLinkedToVenue(SALLE.name, "Alpha"))).toBeInTheDocument();
    expect(screen.queryByText(WIZARD_TEXTS.venueDissociated("Alpha"))).not.toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: WIZARD_TEXTS.undoAction })).toHaveLength(1);
  });
});
