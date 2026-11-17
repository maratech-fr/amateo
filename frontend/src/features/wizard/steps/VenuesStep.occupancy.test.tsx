import { act, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { type ReactNode, useState } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { pickListboxOption } from "@/test/pickListboxOption";
import { renderWithProviders } from "@/test/utils";
import { toast } from "@/shared/stores/toastStore";

import { WizardFooterContext } from "../lib/footerSlot";
import { WIZARD_TEXTS } from "../texts";
import type { Venue, VenueOccupancy, VenuePeriodOverride, VenueTrainingSlot } from "../api";

/**
 * PR6 « occupations de gymnase » (plan v5 §5, §6 V1) — ce que SEUL l'écran Gymnases porte : le
 * choix [Créneau | Occupation] au moment de poser, le motif obligatoire, le verrou du planning
 * validé qui laisse les occupations modifiables, le bandeau d'un créneau et d'une occupation
 * superposés (gestionnaire seul), la hauteur de grille qui compte les occupations, et — en période —
 * la RELECTURE des occupations après « Vider » et « Reprendre » (le cache de l'écran ne garde pas
 * ce que le serveur vient de détruire).
 *
 * On mute la PROD : les VRAIS `VenuesStep`, `PeriodVenues`, `VenueAvailabilityGrid` et les VRAIS
 * hooks de `../queries` (dont `invalidatePeriodGrid`) tournent ; le mock s'arrête à `../api`. Le
 * double du serveur CHANGE sa réponse après Vider/Reprendre : c'est la relecture par l'écran qui
 * est prouvée, pas le double. Les écritures refusées par le serveur (403 Membre, chevauchement)
 * sont prouvées par le Behat `une-occupation-nomme-un-moment-ou-le-gymnase-n-est-pas-au-club` et
 * `VenueOccupancyApiTest` ; ici, seulement ce que l'écran offre ou retient.
 */

const h = vi.hoisted(() => ({
  canManage: true,
  socleValidated: false,
  mode: "season" as "season" | "period",
  venues: [] as unknown[],
  seasonSlots: [] as unknown[],
  periodSlots: [] as unknown[],
  seasonOccupancies: [] as unknown[],
  periodOccupancies: [] as unknown[],
  overrides: [] as unknown[],
  listVenueOccupancies: vi.fn(),
  createVenueOccupancy: vi.fn(),
  updateVenueOccupancy: vi.fn(),
  deleteVenueOccupancy: vi.fn(),
  createSlot: vi.fn(),
  updateSlot: vi.fn(),
  resetVenuePeriodGrid: vi.fn(),
  clearVenuePeriodGrid: vi.fn(),
  deleteVenuePeriodOverride: vi.fn(),
}));

// La couche réseau seule est doublée. `listVenueOccupancies` et les trois écritures sont les
// fonctions du contrat du plan (§3 Frontend, `wizard/api.ts`).
vi.mock("../api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../api")>()),
  listVenues: async () => h.venues,
  listVenueSlots: async () => h.seasonSlots,
  listPeriodSlots: async () => h.periodSlots,
  listVenuePeriodOverrides: async () => h.overrides,
  listReservations: async () => [],
  geoCheckVenues: async () => [],
  listVenueOccupancies: h.listVenueOccupancies,
  createVenueOccupancy: h.createVenueOccupancy,
  updateVenueOccupancy: h.updateVenueOccupancy,
  deleteVenueOccupancy: h.deleteVenueOccupancy,
  createSlot: h.createSlot,
  updateSlot: h.updateSlot,
  resetVenuePeriodGrid: h.resetVenuePeriodGrid,
  clearVenuePeriodGrid: h.clearVenuePeriodGrid,
  deleteVenuePeriodOverride: h.deleteVenuePeriodOverride,
}));

// Les lectures VOISINES de l'écran (équipes, FFBB, géo, réservations, impact de suppression) sont
// réduites à des stubs : elles ne disent rien des occupations. Tout le reste de `../queries` est réel.
vi.mock("../queries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../queries")>()),
  useWizardTeams: () => ({ data: [] }),
  useWizardCoaches: () => ({ data: [] }),
  useReservations: () => ({ data: [] }),
  useVenueGeoCheck: () => ({ data: [] }),
  useFfbbSalles: () => ({ data: { salles: [] } }),
  useFfbbSallesProches: () => ({ data: { salles: [] } }),
  useFfbbSallesByName: () => ({ data: { postalCode: null, salles: [] }, isPending: false }),
  useCreateVenue: () => ({ mutate: vi.fn(), isPending: false }),
  useUpdateVenue: () => ({ mutate: vi.fn(), isPending: false }),
  useLocateVenue: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteVenue: () => ({ mutate: vi.fn() }),
  useDeletionImpact: () => ({ data: undefined, isPending: false, isError: false }),
}));

vi.mock("@/features/cockpit/queries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/features/cockpit/queries")>()),
  usePeriodAnchor: () => ({ state: "period", planId: "plan-1" }),
  useEntryConflicts: () => ({
    data: { venueIds: [], closures: [], fullyClosedVenueIds: [], effectiveClosedWeekdays: {}, disabledVenueIds: [] },
    isError: false,
    isSuccess: true,
    refetch: vi.fn(),
  }),
}));

vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { role: h.canManage ? "admin" : "member", membershipStatus: "active", club: { id: "club-1", postalCode: "69100" } } }),
  useWorkingSeason: () => undefined,
  useCanManage: () => h.canManage,
}));
vi.mock("@/shared/lib/socle", () => ({ useSocleValidated: () => h.socleValidated }));
vi.mock("@/shared/lib/travelStream", () => ({ useTravelStream: () => ({ connected: false, latest: null }) }));
vi.mock("@/features/matches/queries", () => ({ useVenueMatchWindows: () => ({ data: [] }) }));
vi.mock("@/features/matches/MatchWindowsEditor", () => ({ MatchWindowsEditor: () => null }));
vi.mock("../parcImport/ParcImportModal", () => ({ ParcImportModal: () => null }));
vi.mock("./VenueGeocodeField", () => ({ VenueGeocodeField: () => null }));
vi.mock("./TravelMatrixModal", () => ({ TravelMatrixModal: () => null }));
vi.mock("../store", () => ({
  useWizardStore: (selector?: (s: { mode: string; calendarEntryId: string | null; clearDeepLinkOrigin: () => void }) => unknown) => {
    const state = { mode: h.mode, calendarEntryId: "period" === h.mode ? "e1" : null, clearDeepLinkOrigin: () => {} };
    return selector ? selector(state) : state;
  },
}));

import { VenuesStep } from "./VenuesStep";

const venue = (id: string, name: string): Venue => ({ id, name, color: "#3498DB", canSplit: false, isActive: true, externalRef: null, address: null, latitude: null, longitude: null });

const trainingSlot = (over: Partial<VenueTrainingSlot>): VenueTrainingSlot =>
  ({ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90, capacity: 1, groupLabel: null, schedulePlanId: null, ...over }) as VenueTrainingSlot;

const occupancy = (over: Partial<VenueOccupancy>): VenueOccupancy =>
  ({ id: "o1", version: 0, venueId: "v1", dayOfWeek: 2, startTime: "14:00", durationMinutes: 135, label: "École", schedulePlanId: null, ...over }) as VenueOccupancy;

const overrideDisabled = (venueId: string): VenuePeriodOverride =>
  ({ id: "ov1", schedulePlanId: "plan-1", venueId, mode: "DISABLED", dayOverrides: null }) as VenuePeriodOverride;

/** Le bandeau « une occupation chevauche un créneau » pour « École » le mardi 18:00–19:00. */
const OVERLAP_ECOLE = (): string => WIZARD_TEXTS.occupancyOverlapOne("École", "mardi 18:00–19:00");

function Harness() {
  const [extra, setExtra] = useState<ReactNode>(null);
  return (
    <WizardFooterContext.Provider value={{ setFooterExtra: setExtra, setSuppressScrollJump: () => {} }}>
      <VenuesStep />
      <div>{extra}</div>
    </WizardFooterContext.Provider>
  );
}

async function renderStep(): Promise<ReturnType<typeof userEvent.setup>> {
  const user = userEvent.setup();
  renderWithProviders(<Harness />);
  // Témoin de rendu : la grille est montée (une case de la grille porte « Lun 08:00 » en édition,
  // ou la gouttière « 08:00 » en lecture seule).
  await screen.findAllByText("08:00");
  return user;
}

/** Choisit le mode « Occupation » dans la barre « À poser » et saisit le motif. */
async function chooseOccupancy(user: ReturnType<typeof userEvent.setup>, label: string | null): Promise<void> {
  await user.click(screen.getByRole("button", { name: WIZARD_TEXTS.placementKindOccupancy }));
  if (null !== label) {
    await user.type(screen.getByRole("textbox", { name: WIZARD_TEXTS.occupancyLabelField }), label);
  }
}

/** Un message affiché à l'utilisateur : en toast d'erreur ou dans l'écran (le plan ne fixe pas le canal). */
function messageShown(errorSpy: ReturnType<typeof vi.spyOn>, text: string): boolean {
  return errorSpy.mock.calls.some((call) => call[0] === text) || null !== screen.queryByText(text);
}

beforeEach(() => {
  h.canManage = true;
  h.socleValidated = false;
  h.mode = "season";
  h.venues = [venue("v1", "Gymnase Alpha"), venue("v2", "Gymnase Beta")];
  h.seasonSlots = [];
  h.periodSlots = [];
  h.seasonOccupancies = [];
  h.periodOccupancies = [];
  h.overrides = [];
  h.listVenueOccupancies.mockReset().mockImplementation(async (schedulePlanId?: string) => (undefined === schedulePlanId || null === schedulePlanId ? h.seasonOccupancies : h.periodOccupancies));
  h.createVenueOccupancy.mockReset().mockImplementation(async (body: Record<string, unknown>) => ({ id: "o-created", version: 0, ...body }));
  h.updateVenueOccupancy.mockReset().mockImplementation(async (id: string, body: Record<string, unknown>) => ({ id, version: 1, ...body }));
  h.deleteVenueOccupancy.mockReset().mockResolvedValue(undefined);
  h.createSlot.mockReset().mockImplementation(async (body: Record<string, unknown>) => ({ id: "s-created", version: 0, ...body }));
  h.updateSlot.mockReset().mockImplementation(async (id: string, body: Record<string, unknown>) => ({ id, version: 1, ...body }));
  h.resetVenuePeriodGrid.mockReset().mockResolvedValue({});
  h.clearVenuePeriodGrid.mockReset().mockResolvedValue({});
  h.deleteVenuePeriodOverride.mockReset().mockResolvedValue(undefined);
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("Gymnases de saison — le clic pose un créneau, ou une occupation si on l'a choisie", () => {
  it("par défaut, « Créneau » est choisi et le clic sur la grille crée un créneau, jamais une occupation", async () => {
    const user = await renderStep();

    expect(screen.getByRole("button", { name: WIZARD_TEXTS.placementKindSlot })).toHaveAttribute("aria-pressed", "true");
    await user.click(screen.getByRole("button", { name: "Mar 14:00" }));

    await waitFor(() => expect(h.createSlot).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v1", dayOfWeek: 2, startTime: "14:00" })));
    expect(h.createVenueOccupancy).not.toHaveBeenCalled();
  });

  it("en mode « Occupation » avec un motif, le clic crée une occupation de la durée choisie et le mode reste « Occupation »", async () => {
    const user = await renderStep();

    await chooseOccupancy(user, "École");
    await user.selectOptions(screen.getByRole("combobox", { name: WIZARD_TEXTS.durationToPlaceLabel }), "135");
    await user.click(screen.getByRole("button", { name: "Mar 14:00" }));

    await waitFor(() =>
      expect(h.createVenueOccupancy).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v1", dayOfWeek: 2, startTime: "14:00", durationMinutes: 135, label: "École" })),
    );
    expect(h.createSlot).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: WIZARD_TEXTS.placementKindOccupancy })).toHaveAttribute("aria-pressed", "true");
  });

  it("en mode « Occupation » sans motif, le clic affiche le refus et n'écrit rien", async () => {
    const errorSpy = vi.spyOn(toast, "error").mockImplementation(() => 0);
    const user = await renderStep();

    await chooseOccupancy(user, null);
    await user.click(screen.getByRole("button", { name: "Mar 14:00" }));

    await waitFor(() => expect(messageShown(errorSpy, WIZARD_TEXTS.occupancyMissingLabel)).toBe(true));
    expect(h.createVenueOccupancy).not.toHaveBeenCalled();
    expect(h.createSlot).not.toHaveBeenCalled();
  });

  it("changer de gymnase ramène le choix sur « Créneau »", async () => {
    const user = await renderStep();

    await chooseOccupancy(user, "École");
    await pickListboxOption(user, WIZARD_TEXTS.venueSelectAria, "Gymnase Beta");

    expect(screen.getByRole("button", { name: WIZARD_TEXTS.placementKindSlot })).toHaveAttribute("aria-pressed", "true");
    expect(screen.queryByRole("textbox", { name: WIZARD_TEXTS.occupancyLabelField })).not.toBeInTheDocument();
  });

  it("une occupation à 07:00 étend la grille : la gouttière affiche « 07:00 » et le bloc est posé sur cette première ligne", async () => {
    h.seasonOccupancies = [occupancy({ id: "o-menage", label: "Ménage", startTime: "07:00", durationMinutes: 60 })];
    renderWithProviders(<Harness />);

    // Le bloc d'occupation porte son motif dans son nom accessible ; la grille partant de 07:00, il
    // occupe la ligne 2 (la ligne 1 est l'en-tête des jours) sur quatre quarts d'heure.
    const block = await screen.findByRole("button", { name: /Occupation Ménage/ });
    expect(block).toHaveStyle({ gridRow: "2 / span 4" });
    expect(screen.getByText("07:00")).toBeInTheDocument();
  });
});

describe("Gymnases de saison — planning validé (Q1 = oui) : les créneaux sont verrouillés, les occupations non", () => {
  it("gestionnaire : la case pose une occupation, sans sélecteur, et le créneau reste inerte", async () => {
    h.socleValidated = true;
    h.seasonSlots = [trainingSlot({ id: "s1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90 })];
    const user = userEvent.setup();
    renderWithProviders(<Harness />);
    await screen.findAllByText("08:00");

    // Barre sans sélecteur : le mot « Occupation » remplace le choix ; le motif reste à saisir.
    expect(screen.queryByRole("button", { name: WIZARD_TEXTS.placementKindSlot })).not.toBeInTheDocument();
    await user.type(screen.getByRole("textbox", { name: WIZARD_TEXTS.occupancyLabelField }), "École");
    await user.click(screen.getByRole("button", { name: "Mar 14:00" }));

    await waitFor(() => expect(h.createVenueOccupancy).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v1", dayOfWeek: 2, startTime: "14:00", label: "École" })));
    expect(h.createSlot).not.toHaveBeenCalled();
    // Le créneau du mardi 18:00 se voit mais ne s'ouvre pas.
    expect(screen.queryByRole("button", { name: /18:00 – 19:30/ })).not.toBeInTheDocument();
    expect(screen.getByText("18:00 – 19:30")).toBeInTheDocument();
  });

  it("Membre : ni barre de pose, ni champ « Motif », ni case cliquable", async () => {
    h.socleValidated = true;
    h.canManage = false;
    renderWithProviders(<Harness />);
    await screen.findAllByText("08:00");

    expect(screen.queryByRole("textbox", { name: WIZARD_TEXTS.occupancyLabelField })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: WIZARD_TEXTS.placementKindOccupancy })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Mar 14:00" })).not.toBeInTheDocument();
  });
});

describe("Gymnases de saison — un créneau et une occupation superposés (Q2 = les deux restent)", () => {
  const superposes = (): void => {
    h.seasonSlots = [trainingSlot({ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90 })];
    h.seasonOccupancies = [occupancy({ id: "o1", venueId: "v1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 60 })];
  };

  it("le gestionnaire voit le bandeau qui nomme l'occupation à déplacer", async () => {
    superposes();
    renderWithProviders(<Harness />);

    expect(await screen.findByText(OVERLAP_ECOLE())).toBeInTheDocument();
  });

  it("un Membre voit les deux blocs, sans bandeau", async () => {
    superposes();
    h.canManage = false;
    renderWithProviders(<Harness />);

    // Témoin positif : le bloc d'occupation est bien rendu (sinon l'absence de bandeau ne prouverait rien).
    expect(await screen.findByText("École")).toBeInTheDocument();
    expect(screen.queryByText(OVERLAP_ECOLE())).not.toBeInTheDocument();
  });

  it("aucun bandeau quand l'occupation superposée est dans un AUTRE gymnase que celui affiché", async () => {
    h.seasonSlots = [trainingSlot({ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90 })];
    h.seasonOccupancies = [occupancy({ id: "o1", venueId: "v2", dayOfWeek: 2, startTime: "18:00", durationMinutes: 60 })];
    renderWithProviders(<Harness />);
    await screen.findByText("18:00 – 19:30");
    await waitFor(() => expect(h.listVenueOccupancies).toHaveBeenCalled());

    expect(screen.queryByText(OVERLAP_ECOLE())).not.toBeInTheDocument();
  });

  it("« Modifier le créneau » : déplacer le créneau sur une occupation affiche le refus et n'écrit rien", async () => {
    h.seasonSlots = [trainingSlot({ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90 })];
    h.seasonOccupancies = [occupancy({ id: "o1", venueId: "v1", dayOfWeek: 2, startTime: "14:00", durationMinutes: 135 })];
    const user = userEvent.setup();
    renderWithProviders(<Harness />);
    await screen.findByText("École");

    await user.click(screen.getByRole("button", { name: /^18:00 – 19:30/ }));
    const dialog = screen.getByRole("dialog", { name: WIZARD_TEXTS.editSlotTitle });
    const start = within(dialog).getByLabelText(WIZARD_TEXTS.slotStartLabel);
    await user.clear(start);
    await user.type(start, "14:30");
    await user.click(within(dialog).getByRole("button", { name: WIZARD_TEXTS.save }));

    expect(within(dialog).getByRole("alert")).toHaveTextContent("École");
    expect(h.updateSlot).not.toHaveBeenCalled();
  });
});

describe("Gymnases d'une période — gestionnaire", () => {
  beforeEach(() => {
    h.mode = "period";
    h.venues = [venue("v1", "Gymnase Alpha")];
  });

  it("le mode « Occupation » pose une occupation dans la couche de la période, et le clic sur le bloc ouvre « Modifier l'occupation »", async () => {
    h.periodOccupancies = [occupancy({ id: "o-p1", schedulePlanId: "plan-1" })];
    const user = await renderStep();

    await chooseOccupancy(user, "Mairie");
    await user.click(screen.getByRole("button", { name: "Ven 09:00" }));
    await waitFor(() =>
      expect(h.createVenueOccupancy).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v1", dayOfWeek: 5, startTime: "09:00", label: "Mairie", schedulePlanId: "plan-1" })),
    );

    await user.click(screen.getByRole("button", { name: /Occupation École/ }));
    expect(screen.getByRole("dialog", { name: WIZARD_TEXTS.editOccupancyTitle })).toBeInTheDocument();
  });

  it("« Vider la grille » reste offert quand le gymnase ne porte plus que des occupations", async () => {
    h.periodOccupancies = [occupancy({ id: "o-p1", schedulePlanId: "plan-1" })];
    await renderStep();
    await screen.findByText("École");

    expect(screen.getByRole("button", { name: "Vider la grille" })).toBeEnabled();
  });

  it("« Vider » confirmé : l'écran relit les occupations — bloc gris, bandeau et bouton « Vider » s'en vont", async () => {
    h.periodSlots = [trainingSlot({ id: "ps1", schedulePlanId: "plan-1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90 })];
    h.periodOccupancies = [occupancy({ id: "o-p1", schedulePlanId: "plan-1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 60 })];
    h.clearVenuePeriodGrid.mockImplementation(async () => {
      // Le serveur vide créneaux ET occupations du gymnase pour la période.
      h.periodSlots = [];
      h.periodOccupancies = [];
      return {};
    });
    const user = await renderStep();
    expect(await screen.findByText(OVERLAP_ECOLE())).toBeInTheDocument();
    const readsBefore = h.listVenueOccupancies.mock.calls.length;

    await user.click(screen.getByRole("button", { name: "Vider la grille" }));
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Vider" }));

    await waitFor(() => expect(h.listVenueOccupancies.mock.calls.length).toBeGreaterThan(readsBefore));
    await waitFor(() => expect(screen.queryByText("École")).not.toBeInTheDocument());
    expect(screen.queryByText(OVERLAP_ECOLE())).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Vider la grille" })).toBeDisabled();
  });

  it("« Reprendre » confirmé : l'écran relit les occupations et le clic sur le bloc ouvre l'occupation au NOUVEL identifiant", async () => {
    h.periodOccupancies = [occupancy({ id: "o-old", schedulePlanId: "plan-1", dayOfWeek: 5, startTime: "13:45", durationMinutes: 120 })];
    h.resetVenuePeriodGrid.mockImplementation(async () => {
      // Le serveur recopie celles de la saison : nouvelles lignes, nouveaux identifiants.
      h.periodOccupancies = [occupancy({ id: "o-new", schedulePlanId: "plan-1", dayOfWeek: 2, startTime: "14:00", durationMinutes: 135 })];
      return {};
    });
    const user = await renderStep();
    await screen.findByText("École");

    await user.click(screen.getByRole("button", { name: "Reprendre la grille du planning de saison" }));
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Reprendre" }));
    await waitFor(() => expect(screen.getByRole("button", { name: /Occupation École · .*14:00 – 16:15/ })).toBeInTheDocument());

    await user.click(screen.getByRole("button", { name: /Occupation École · .*14:00 – 16:15/ }));
    const editor = screen.getByRole("dialog", { name: WIZARD_TEXTS.editOccupancyTitle });
    await user.click(within(editor).getByRole("button", { name: WIZARD_TEXTS.save }));

    await waitFor(() => expect(h.updateVenueOccupancy).toHaveBeenCalled());
    expect(h.updateVenueOccupancy.mock.calls[0]?.[0]).toBe("o-new");
  });

  it("gymnase désactivé pour la période : pas de bandeau de chevauchement ; réactivé, le bandeau paraît", async () => {
    h.overrides = [overrideDisabled("v1")];
    h.periodSlots = [trainingSlot({ id: "ps1", schedulePlanId: "plan-1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90 })];
    h.periodOccupancies = [occupancy({ id: "o-p1", schedulePlanId: "plan-1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 60 })];
    h.deleteVenuePeriodOverride.mockImplementation(async () => {
      h.overrides = [];
    });
    const user = await renderStep();
    // Témoin positif : l'occupation est bien rendue sur la grille gelée.
    await screen.findByText("École");
    expect(screen.queryByText(OVERLAP_ECOLE())).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Réactiver" }));

    expect(await screen.findByText(OVERLAP_ECOLE())).toBeInTheDocument();
  });
});

describe("Gymnases d'une période — Membre", () => {
  it("voit le bloc gris, sans sélecteur ni champ « Motif » ; le bloc n'est pas un bouton ; aucun bandeau", async () => {
    h.mode = "period";
    h.canManage = false;
    h.venues = [venue("v1", "Gymnase Alpha")];
    h.periodSlots = [trainingSlot({ id: "ps1", schedulePlanId: "plan-1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90 })];
    h.periodOccupancies = [occupancy({ id: "o-p1", schedulePlanId: "plan-1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 60 })];
    renderWithProviders(<Harness />);

    const label = await screen.findByText("École");
    expect(label.closest("button")).toBeNull();
    expect(screen.queryByRole("button", { name: /Occupation École/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: WIZARD_TEXTS.placementKindOccupancy })).not.toBeInTheDocument();
    expect(screen.queryByRole("textbox", { name: WIZARD_TEXTS.occupancyLabelField })).not.toBeInTheDocument();
    expect(screen.queryByText(OVERLAP_ECOLE())).not.toBeInTheDocument();
    await act(async () => {
      await Promise.resolve();
    });
    expect(h.createVenueOccupancy).not.toHaveBeenCalled();
  });
});
