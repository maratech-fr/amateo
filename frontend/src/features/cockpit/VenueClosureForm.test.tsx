import { fireEvent, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { setTodayOverride } from "@/shared/lib/clock";
import { useToastStore } from "@/shared/stores/toastStore";
import { pickListboxOption } from "@/test/pickListboxOption";
import { renderWithProviders } from "@/test/utils";

import { COCKPIT_TEXTS } from "./texts";
import { VenueClosureForm } from "./VenueClosureForm";

/**
 * Promesse (chantier « Fermetures de gymnase », PR 1, C3 + correction finale 3 + C3bis) :
 * « Enregistrer une fermeture, après lecture de l'aperçu, vaut geste » — le formulaire unique
 * (carte du cockpit ET jour du calendrier) ne laisse JAMAIS enregistrer sans que l'aperçu des
 * retraits ait été LU pour ces dates (en vol ou en échec → bouton inactif), refuse une fenêtre
 * ENTIÈREMENT hors saison (miroir du 422 serveur) mais accepte une fenêtre à cheval en le disant,
 * et confirme au toast ce qui a été retiré (`removed` de la réponse).
 *
 * Le jumeau serveur du « rien d'écrit pendant une génération » est
 * `backend/tests/Api/ClosureRemovesPinsAtomicallyTest.php` (409 generation_in_progress) ; le toast
 * de ce 409 vit au niveau HOOK (`queries.test.tsx`), le formulaire, lui, reste ouvert.
 */

type PreviewState = { data: unknown; isPending: boolean; isFetching: boolean; isError: boolean; isSuccess: boolean };
const inFlight: PreviewState = { data: undefined, isPending: true, isFetching: true, isError: false, isSuccess: false };
const failed: PreviewState = { data: undefined, isPending: false, isFetching: false, isError: true, isSuccess: false };
const readyImpact = {
  venueIds: ["v-mistral"],
  startDate: "2999-01-05",
  endDate: "2999-01-09",
  seasonPlanChosen: true,
  teams: [],
  trainingOccurrences: 0,
  trainingSlotCount: 0,
  matchesToMove: [],
  governingPlans: [],
  fullyGoverned: false,
  closedSessions: [],
};
const ready: PreviewState = { data: readyImpact, isPending: false, isFetching: false, isError: false, isSuccess: true };

let previewState: PreviewState = inFlight;
const previewHook = vi.fn<(params: unknown) => PreviewState>(() => previewState);
const createMutate = vi.fn();

vi.mock("./queries", () => ({
  useClosureImpactPreview: (params: unknown) => previewHook(params),
  useCreateVenueClosure: () => ({ mutate: createMutate, isPending: false }),
}));
const allVenues = [
  { id: "v-mistral", name: "Mistral", color: null, canSplit: false, isActive: true },
  { id: "v-brel", name: "Brel", color: null, canSplit: false, isActive: true },
];
let venuesData: typeof allVenues | undefined = allVenues;
vi.mock("@/features/planning/queries", () => ({
  useVenues: () => ({ data: venuesData }),
}));
vi.mock("@/features/matches/queries", () => ({
  useVenues: () => ({ data: venuesData }),
}));
// Saison 15/7 → 14/7 (comme la saison seedée), ancrée en 2998-2999 pour ne jamais périmer.
vi.mock("@/shared/session/queries", () => ({
  useWorkingSeason: () => ({ id: "sn1", name: "2998-2999", startDate: "2998-07-15", endDate: "2999-07-14", isCurrent: true, isReadonly: false }),
  useMe: () => ({ data: { seasonPlan: { chosenScheduleId: "s-season" } } }),
}));

const onDone = vi.fn();
const saveButton = () => screen.getByRole("button", { name: COCKPIT_TEXTS.save });

/** Choisit le gymnase et pose la fenêtre [start, end] (champs date : `fireEvent`, comme la saisie native). */
async function fillForm(user: ReturnType<typeof userEvent.setup>, start: string, end: string) {
  await pickListboxOption(user, COCKPIT_TEXTS.unavailableVenueLabel, "Mistral");
  fireEvent.change(screen.getByLabelText("Du"), { target: { value: start } });
  fireEvent.change(screen.getByLabelText("Jusqu'au"), { target: { value: end } });
}

/** Le bouton est inerte s'il est `disabled` natif OU `aria-disabled` (motif découvrable, A11Y-30). */
const isInert = (el: HTMLElement) => el.hasAttribute("disabled") || "true" === el.getAttribute("aria-disabled");

describe("VenueClosureForm — Enregistrer vaut geste (C3)", () => {
  beforeEach(() => {
    setTodayOverride("2998-12-01");
    previewState = inFlight;
    venuesData = allVenues;
    previewHook.mockClear();
    createMutate.mockReset();
    onDone.mockReset();
    useToastStore.setState({ toasts: [] });
  });
  afterEach(() => setTodayOverride(null));

  it("lit l'aperçu pour le gymnase et les dates saisis", async () => {
    const user = userEvent.setup();
    previewState = ready;
    renderWithProviders(<VenueClosureForm initialStartDate={null} onDone={onDone} />);
    await fillForm(user, "2999-01-05", "2999-01-09");

    await waitFor(() =>
      expect(previewHook).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v-mistral", startDate: "2999-01-05", endDate: "2999-01-09" })),
    );
  });

  it("aperçu EN VOL : « Enregistrer » reste inactif — on ne retire rien sans l'avoir annoncé", async () => {
    const user = userEvent.setup();
    previewState = inFlight;
    renderWithProviders(<VenueClosureForm initialStartDate={null} onDone={onDone} />);
    await fillForm(user, "2999-01-05", "2999-01-09");

    expect(screen.getByText(COCKPIT_TEXTS.closureImpactEvaluating)).toBeInTheDocument();
    expect(isInert(saveButton())).toBe(true);
    await user.click(saveButton());
    expect(createMutate).not.toHaveBeenCalled();
  });

  it("aperçu EN ÉCHEC : « Impact non évalué — réessayez », bouton inactif", async () => {
    const user = userEvent.setup();
    previewState = failed;
    renderWithProviders(<VenueClosureForm initialStartDate={null} onDone={onDone} />);
    await fillForm(user, "2999-01-05", "2999-01-09");

    expect(await screen.findByText(COCKPIT_TEXTS.closureImpactFailed)).toBeInTheDocument();
    expect(isInert(saveButton())).toBe(true);
    await user.click(saveButton());
    expect(createMutate).not.toHaveBeenCalled();
  });

  it("aperçu LU : « Enregistrer » s'active et envoie gymnase + dates", async () => {
    const user = userEvent.setup();
    previewState = ready;
    renderWithProviders(<VenueClosureForm initialStartDate={null} onDone={onDone} />);
    await fillForm(user, "2999-01-05", "2999-01-09");

    await waitFor(() => expect(isInert(saveButton())).toBe(false));
    await user.click(saveButton());
    expect(createMutate).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v-mistral", startDate: "2999-01-05", endDate: "2999-01-09" }), expect.anything());
  });

  /**
   * Dette reprise de `VenueUnavailabilityCard.test.tsx` (carte retirée, P4-122) : les gymnases
   * arrivent APRÈS l'ouverture du formulaire — l'écran dit « rien de choisi » au lieu d'afficher le
   * premier (il mentirait : l'état est vide), et le PREMIER gymnase de la liste reste atteignable.
   */
  it("gymnases chargés APRÈS l'ouverture : rien n'est montré comme choisi, et le 1er gymnase reste atteignable", async () => {
    const user = userEvent.setup();
    previewState = ready;
    venuesData = undefined;
    const { rerender } = renderWithProviders(<VenueClosureForm initialStartDate={null} onDone={onDone} />);
    venuesData = allVenues;
    rerender(<VenueClosureForm initialStartDate={null} onDone={onDone} />);

    const trigger = await screen.findByRole("button", { name: new RegExp(COCKPIT_TEXTS.unavailableVenueLabel) });
    expect(trigger).not.toHaveTextContent("Mistral");

    await fillForm(user, "2999-01-05", "2999-01-09");
    await waitFor(() => expect(isInert(saveButton())).toBe(false));
    await user.click(saveButton());
    expect(createMutate).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v-mistral" }), expect.anything());
  });

  it("fenêtre ENTIÈREMENT hors saison : le dit (dates de la saison) et bloque — miroir du 422 serveur", async () => {
    const user = userEvent.setup();
    previewState = ready;
    renderWithProviders(<VenueClosureForm initialStartDate={null} onDone={onDone} />);
    await fillForm(user, "2999-08-01", "2999-08-05");

    expect(await screen.findByText(COCKPIT_TEXTS.closureOutOfSeason("15/7", "14/7"))).toBeInTheDocument();
    expect(isInert(saveButton())).toBe(true);
  });

  it("correction finale 3 — fenêtre À CHEVAL sur la fin de saison : seules les dates en saison comptent, bouton ACTIF", async () => {
    const user = userEvent.setup();
    previewState = ready;
    renderWithProviders(<VenueClosureForm initialStartDate={null} onDone={onDone} />);
    await fillForm(user, "2999-07-10", "2999-07-20");

    // Clé supposée (absente du §D) : `closureSeasonClipped(début, finDeSaison)` —
    // « Seules les dates du 10/7 au 14/7 comptent (fin de saison). »
    expect(await screen.findByText(COCKPIT_TEXTS.closureSeasonClipped("10/7", "14/7"))).toBeInTheDocument();
    expect(screen.queryByText(COCKPIT_TEXTS.closureOutOfSeason("15/7", "14/7"))).not.toBeInTheDocument();
    await waitFor(() => expect(isInert(saveButton())).toBe(false));
  });

  it("enregistré : le toast dit ce qui a été RETIRÉ (removed de la réponse), puis le formulaire se ferme", async () => {
    const user = userEvent.setup();
    previewState = ready;
    // Contrat supposé : la mutation résout `{ entry, removed: { locks, reservations } }`.
    createMutate.mockImplementation((_payload: unknown, options?: { onSuccess?: (data: unknown) => void }) => {
      options?.onSuccess?.({ entry: { id: "e-new" }, removed: { locks: 10, reservations: 1 } });
    });
    renderWithProviders(<VenueClosureForm initialStartDate={null} onDone={onDone} />);
    await fillForm(user, "2999-01-05", "2999-01-09");
    await waitFor(() => expect(isInert(saveButton())).toBe(false));
    await user.click(saveButton());

    const messages = useToastStore.getState().toasts.map((t) => t.message);
    expect(messages).toContain(COCKPIT_TEXTS.closureSavedToast(10, 1, "Mistral"));
    expect(onDone).toHaveBeenCalledTimes(1);
  });

  it("génération en cours (409) : rien n'est fermé, le formulaire RESTE ouvert (le toast vit au hook)", async () => {
    const user = userEvent.setup();
    previewState = ready;
    // La mutation échoue : ni onSuccess, ni fermeture.
    createMutate.mockImplementation(() => undefined);
    renderWithProviders(<VenueClosureForm initialStartDate={null} onDone={onDone} />);
    await fillForm(user, "2999-01-05", "2999-01-09");
    await waitFor(() => expect(isInert(saveButton())).toBe(false));
    await user.click(saveButton());

    expect(createMutate).toHaveBeenCalledTimes(1);
    expect(onDone).not.toHaveBeenCalled();
    expect(saveButton()).toBeInTheDocument();
  });
});
