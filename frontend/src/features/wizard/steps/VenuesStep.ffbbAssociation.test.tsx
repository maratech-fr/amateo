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
 * Finitions d'interface (essai d'onboarding, 30/10, points 2 et 12) — ROUGE option A. PROMESSES :
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
 *
 * Associer une salle introuvable à proximité (retours du 31/10) — ROUGE option A. PROMESSE : le
 * gestionnaire associe TOUJOURS son gymnase à une salle FFBB, même sans salle proche ou avec un
 * homonyme à côté : il cherche dans toute la FFBB, distingue les homonymes par l'adresse et la ville,
 * et l'adresse est reprise si le gymnase n'en a pas (sinon 0 trajet).
 * Contrat pour NEO : `AssociateSalleModal`, `SalleResultRow` et les clés `associateModalTitle`,
 * `associateModalHint`, `associateModalEmpty`, `associateTag`, `associateVenueAddress`,
 * `associateVenueDescriptionNoGps` de `WIZARD_TEXTS`. Une ligne de résultat est un `<li>` ; le nom
 * accessible de son bouton CONTIENT `action.label`.
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
  nameQueries: [] as string[],
  nameSearchState: { value: "ready" as "ready" | "loading" | "failed" },
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
  useFfbbSallesProches: () => ({ data: { radiusKm: 5, salles: h.nearby.salles }, isError: false }),
  // Comme le vrai hook : sous 3 caractères la requête est DÉSACTIVÉE (`data` undefined, jamais d'erreur).
  useFfbbSallesByName: (query: string) => {
    h.nameQueries.push(query);
    if (query.trim().length < 3 || "loading" === h.nameSearchState.value) {
      return { data: undefined, isError: false, isPending: true };
    }
    if ("failed" === h.nameSearchState.value) {
      return { data: undefined, isError: true, isPending: false };
    }
    return { data: { postalCode: null, salles: h.nameSearch.salles }, isError: false, isPending: false };
  },
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
  h.nameQueries = [];
  h.nameSearchState.value = "ready";
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
    const row = within(screen.getByRole("dialog", { name: WIZARD_TEXTS.associateModalTitle("Alpha") })).getByText(WIZARD_TEXTS.associateVenueAddress(SALLE)).closest("li") as HTMLElement;
    await user.click(within(row).getByRole("button", { name: (n: string) => n.includes(WIZARD_TEXTS.associateTag) }));
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

describe("VenuesStep — Associer : la recherche dans toute la FFBB, dans sa modale", () => {
  const HOMONYM: FfbbSalle = { ...SALLE, address: "3 ALLEE DES CIMES", city: "Valbois", postalCode: "74000", externalRef: "174000007", latitude: "45.92", longitude: "6.87" };
  const OTHER: FfbbSalle = { ...SALLE, name: "HALLE DES SPORTS", address: "5 RUE BASSE", externalRef: "166900099" };
  const NO_GPS: FfbbSalle = { ...SALLE, latitude: null, longitude: null };

  const openButton = (): HTMLElement => screen.getByRole("button", { name: WIZARD_TEXTS.associateVenueMenu("Alpha") });
  const modal = (): HTMLElement => screen.getByRole("dialog", { name: WIZARD_TEXTS.associateModalTitle("Alpha") });
  const searchField = (): HTMLElement => within(modal()).getByRole("textbox");
  const rowOf = (salle: FfbbSalle): HTMLElement => within(modal()).getByText(WIZARD_TEXTS.associateVenueAddress(salle)).closest("li") as HTMLElement;
  const pick = async (user: ReturnType<typeof userEvent.setup>, salle: FfbbSalle): Promise<void> => {
    await user.click(within(rowOf(salle)).getByRole("button", { name: (n: string) => n.includes(WIZARD_TEXTS.associateTag) }));
  };
  const confirmDialog = (salle: FfbbSalle): HTMLElement => screen.getByRole("dialog", { name: WIZARD_TEXTS.associateVenueTitle(salle.name, "Alpha") });

  it("le bouton est là même sans aucune salle à proximité, et ouvre la modale de recherche", async () => {
    const user = userEvent.setup();
    renderWithProviders(<Harness />);

    await user.click(openButton());

    expect(within(modal()).getByText(WIZARD_TEXTS.associateModalHint)).toBeInTheDocument();
    expect(searchField()).toHaveAccessibleName();
    expect(screen.queryByRole("menu")).not.toBeInTheDocument();
  });

  it("cherche dans toute la FFBB même quand un homonyme est à proximité, et distingue les homonymes par l'adresse et la ville", async () => {
    const user = userEvent.setup();
    h.nearby.salles = [SALLE];
    h.nameSearch.salles = [HOMONYM];
    renderWithProviders(<Harness />);

    await user.click(openButton());
    await user.type(searchField(), "mont blanc");

    expect(h.nameQueries).toContain("mont blanc");
    expect(rowOf(SALLE)).toBeInTheDocument();
    expect(rowOf(HOMONYM)).toBeInTheDocument();
  });

  it("la liste à proximité s'affiche à l'ouverture, sans chargement sans fin tant que rien n'est cherché, puis se filtre", async () => {
    const user = userEvent.setup();
    h.nearby.salles = [SALLE, OTHER];
    renderWithProviders(<Harness />);

    await user.click(openButton());
    expect(rowOf(SALLE)).toBeInTheDocument();
    expect(rowOf(OTHER)).toBeInTheDocument();
    expect(within(modal()).queryByLabelText("Chargement")).not.toBeInTheDocument();

    await user.type(searchField(), "mont blanc");
    expect(rowOf(SALLE)).toBeInTheDocument();
    expect(within(modal()).queryByText(WIZARD_TEXTS.associateVenueAddress(OTHER))).not.toBeInTheDocument();
  });

  it("une salle sans numéro fédéral n'est pas proposée : elle n'est pas associable", async () => {
    const user = userEvent.setup();
    const noRef = { ...OTHER, externalRef: null };
    const noRefNational = { ...HOMONYM, externalRef: null };
    h.nearby.salles = [noRef, SALLE];
    h.nameSearch.salles = [noRefNational];
    renderWithProviders(<Harness />);

    await user.click(openButton());
    expect(rowOf(SALLE)).toBeInTheDocument();
    expect(within(modal()).queryByText(noRef.name)).not.toBeInTheDocument();

    await user.type(searchField(), "mont blanc");
    expect(within(modal()).queryByText(WIZARD_TEXTS.associateVenueAddress(noRefNational))).not.toBeInTheDocument();
  });

  it("une salle déjà liée à un autre gymnase reste lisible mais fermée au geste", async () => {
    const user = userEvent.setup();
    h.venues.data = [venueOf(), venueOf({ id: "v2", name: "Beta", externalRef: SALLE.externalRef })];
    h.nearby.salles = [SALLE];
    renderWithProviders(<Harness />);

    await user.click(openButton());
    expect(within(rowOf(SALLE)).getByText(WIZARD_TEXTS.alreadyLinkedTag)).toBeInTheDocument();

    await user.click(within(rowOf(SALLE)).getByRole("button", { name: (n: string) => n.includes(WIZARD_TEXTS.associateTag) }));
    expect(screen.queryByRole("dialog", { name: WIZARD_TEXTS.associateVenueTitle(SALLE.name, "Alpha") })).not.toBeInTheDocument();
  });

  it("un clic ferme la modale et demande confirmation avec adresse et ville ; confirmé, il associe sans créer ni renommer et reprend l'adresse d'un gymnase qui n'en a pas", async () => {
    const user = userEvent.setup();
    h.nameSearch.salles = [HOMONYM];
    renderWithProviders(<Harness />);

    await user.click(openButton());
    await user.type(searchField(), "mont blanc");
    await pick(user, HOMONYM);

    expect(screen.queryByRole("dialog", { name: WIZARD_TEXTS.associateModalTitle("Alpha") })).not.toBeInTheDocument();
    expect(within(confirmDialog(HOMONYM)).getByText(WIZARD_TEXTS.associateVenueAddress(HOMONYM))).toBeInTheDocument();
    expect(h.updateCalls).toHaveLength(0);

    await user.click(within(confirmDialog(HOMONYM)).getByRole("button", { name: WIZARD_TEXTS.associateVenueConfirm }));

    expect(h.updateCalls).toEqual([
      { id: "v1", body: expect.objectContaining({ name: "Alpha", externalRef: HOMONYM.externalRef, address: HOMONYM.address, latitude: HOMONYM.latitude, longitude: HOMONYM.longitude }) },
    ]);
    expect(h.created.value).toBeNull();
    expect(await screen.findByText(WIZARD_TEXTS.salleLinkedToVenue(HOMONYM.name, "Alpha"))).toBeInTheDocument();
  });

  it("un gymnase qui a déjà une adresse la garde", async () => {
    const user = userEvent.setup();
    h.venues.data = [venueOf({ address: "2 rue Basse" })];
    h.nearby.salles = [SALLE];
    renderWithProviders(<Harness />);

    await user.click(openButton());
    await pick(user, SALLE);
    await user.click(within(confirmDialog(SALLE)).getByRole("button", { name: WIZARD_TEXTS.associateVenueConfirm }));

    expect(h.updateCalls).toEqual([{ id: "v1", body: expect.objectContaining({ externalRef: SALLE.externalRef, address: "2 rue Basse" }) }]);
  });

  it("une salle sans position le dit à la confirmation, l'adresse est reprise et la fiche la montre, position à saisir", async () => {
    const user = userEvent.setup();
    h.nearby.salles = [NO_GPS];
    renderWithProviders(<Harness />);

    await user.click(openButton());
    await pick(user, NO_GPS);

    expect(within(confirmDialog(NO_GPS)).getByText(WIZARD_TEXTS.associateVenueDescriptionNoGps)).toBeInTheDocument();
    expect(within(confirmDialog(NO_GPS)).queryByText(WIZARD_TEXTS.associateVenueDescription)).not.toBeInTheDocument();

    await user.click(within(confirmDialog(NO_GPS)).getByRole("button", { name: WIZARD_TEXTS.associateVenueConfirm }));

    expect(h.updateCalls[0]?.body).toEqual(expect.objectContaining({ externalRef: NO_GPS.externalRef, address: NO_GPS.address }));
    expect(await screen.findByRole("textbox", { name: "Adresse" })).toHaveValue(NO_GPS.address);
  });

  it("une salle avec position garde le texte habituel de confirmation", async () => {
    const user = userEvent.setup();
    h.nearby.salles = [SALLE];
    renderWithProviders(<Harness />);

    await user.click(openButton());
    await pick(user, SALLE);

    expect(within(confirmDialog(SALLE)).getByText(WIZARD_TEXTS.associateVenueDescription)).toBeInTheDocument();
    expect(within(confirmDialog(SALLE)).queryByText(WIZARD_TEXTS.associateVenueDescriptionNoGps)).not.toBeInTheDocument();
  });

  it("une adresse absente à la FFBB n'affiche jamais « null », ni sur la ligne ni à la confirmation", async () => {
    const user = userEvent.setup();
    const noAddress = { ...SALLE, address: null };
    h.nearby.salles = [noAddress];
    renderWithProviders(<Harness />);

    await user.click(openButton());
    expect(modal()).not.toHaveTextContent("null");

    await pick(user, noAddress);
    expect(confirmDialog(noAddress)).not.toHaveTextContent("null");
  });

  it("« Annuler » à la confirmation n'écrit rien", async () => {
    const user = userEvent.setup();
    h.nearby.salles = [SALLE];
    renderWithProviders(<Harness />);

    await user.click(openButton());
    await pick(user, SALLE);
    await user.click(within(confirmDialog(SALLE)).getByRole("button", { name: "Annuler" }));

    expect(h.updateCalls).toHaveLength(0);
  });

  it("le toast « Annuler » rend un gymnase sans adresse à son état d'avant : ni salle, ni adresse reprise", async () => {
    const user = userEvent.setup();
    h.nearby.salles = [SALLE];
    renderWithProviders(<Harness />);

    await user.click(openButton());
    await pick(user, SALLE);
    await user.click(within(confirmDialog(SALLE)).getByRole("button", { name: WIZARD_TEXTS.associateVenueConfirm }));
    await user.click(await screen.findByRole("button", { name: WIZARD_TEXTS.undoAction }));

    expect(h.updateCalls.at(-1)).toEqual({ id: "v1", body: expect.objectContaining({ externalRef: "", address: null, latitude: null, longitude: null }) });
    expect(h.venues.data[0]).toEqual(expect.objectContaining({ externalRef: null, address: null, latitude: null, longitude: null }));
  });

  it("rien trouvé : la modale le dit", async () => {
    const user = userEvent.setup();
    renderWithProviders(<Harness />);

    await user.click(openButton());
    await user.type(searchField(), "introuvable");

    expect(within(modal()).getByText(WIZARD_TEXTS.associateModalEmpty)).toBeInTheDocument();
  });

  it("une recherche en échec le dit, sans se faire passer pour un résultat vide", async () => {
    const user = userEvent.setup();
    h.nameSearchState.value = "failed";
    renderWithProviders(<Harness />);

    await user.click(openButton());
    await user.type(searchField(), "mont blanc");

    expect(within(modal()).getByText(WIZARD_TEXTS.nameSearchError)).toBeInTheDocument();
    expect(within(modal()).queryByText(WIZARD_TEXTS.associateModalEmpty)).not.toBeInTheDocument();
  });

  it("une recherche en cours montre le chargement, sans se faire passer pour un résultat vide", async () => {
    const user = userEvent.setup();
    h.nameSearchState.value = "loading";
    renderWithProviders(<Harness />);

    await user.click(openButton());
    await user.type(searchField(), "mont blanc");

    expect(within(modal()).getByLabelText("Chargement")).toBeInTheDocument();
    expect(within(modal()).queryByText(WIZARD_TEXTS.associateModalEmpty)).not.toBeInTheDocument();
  });
});
