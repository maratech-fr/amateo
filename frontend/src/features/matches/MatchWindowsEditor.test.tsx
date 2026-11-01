import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { VenueMatchWindow } from "./api/venues";
import { MatchWindowsEditor } from "./MatchWindowsEditor";
import { MATCHES_TEXTS } from "./texts";

/**
 * PR2 « jours fériés » (Q1 option c, VALIDÉ fondateur 2026-10-27) — l'éditeur des accès match d'un
 * gymnase doit offrir une 8ᵉ ligne « Jours fériés » SOUS lundi…dimanche : elle s'applique à tout
 * jour férié officiel du territoire (pas de jour de semaine). On pilote les hooks (jamais le
 * réseau) et on MUTE la prod, jamais le mock (§ règles frontend).
 *
 * ROUGE aujourd'hui : le sélecteur « Jour » n'offre que les jours ISO 1-7 et `add()` n'émet que
 * `{ venueId, dayOfWeek, startTime, endTime }`. NEO rend VERT (8ᵉ ligne + envoi
 * `appliesOnPublicHolidays`). Forme exacte laissée à NEO — ces assertions visent le RÔLE + le nom
 * accessible et le CONTRAT de création, pas la mise en page.
 */
vi.mock("@/shared/session/queries", () => ({
  useWorkingSeason: () => ({ id: "season-1", name: "2026-2027", startDate: "2026-09-01", endDate: "2027-06-30", isCurrent: true, isReadonly: false }),
}));

const createWindow = vi.fn();
const deleteWindow = vi.fn();
const windowsState: { data: VenueMatchWindow[] } = { data: [] };

vi.mock("./queries", () => ({
  useVenueMatchWindows: () => ({ data: windowsState.data, isError: false, isLoading: false }),
  useCreateVenueMatchWindow: () => ({ mutate: createWindow, isPending: false }),
  useDeleteVenueMatchWindow: () => ({ mutate: deleteWindow, isPending: false }),
}));

/** Une ligne « Jours fériés » : pas de jour de semaine, `appliesOnPublicHolidays` à true. Le champ
 *  est ajouté au type par NEO (PR2) — cast le temps de la fixture de test. */
const holidayWindow = (): VenueMatchWindow =>
  ({ id: "wf", venueId: "v1", startTime: "10:00", endTime: "18:00", appliesOnPublicHolidays: true }) as unknown as VenueMatchWindow;

beforeEach(() => {
  createWindow.mockReset();
  deleteWindow.mockClear();
  windowsState.data = [];
});

describe("MatchWindowsEditor — ligne « Jours fériés » (PR2)", () => {
  it("offre une 8ᵉ ligne « Jours fériés » sous lundi…dimanche", () => {
    renderWithProviders(<MatchWindowsEditor venueId="v1" />);
    // Les sept jours de semaine restent proposés…
    expect(screen.getByRole("option", { name: "Lundi" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "Dimanche" })).toBeInTheDocument();
    // …et une 8ᵉ entrée « Jours fériés » les complète.
    expect(screen.getByRole("option", { name: "Jours fériés" })).toBeInTheDocument();
  });

  it("créer la ligne « Jours fériés » émet appliesOnPublicHolidays, jamais un jour de semaine", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchWindowsEditor venueId="v1" />);

    await user.selectOptions(screen.getByRole("combobox", { name: "Jour de la fenêtre match" }), "Jours fériés");
    await user.click(screen.getByRole("button", { name: "Ajouter la fenêtre match" }));

    expect(createWindow).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v1", appliesOnPublicHolidays: true }));
    expect(createWindow.mock.calls[0]?.[0]).not.toHaveProperty("dayOfWeek", expect.any(Number));
  });

  it("affiche une ligne « Jours fériés » existante et la supprime après confirmation", async () => {
    const user = userEvent.setup();
    windowsState.data = [holidayWindow()];
    renderWithProviders(<MatchWindowsEditor venueId="v1" />);

    // La ligne férié se nomme « Jours fériés », pas un jour de semaine.
    expect(screen.getByText(/Jours fériés/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /Supprimer la fenêtre Jours fériés/ }));
    // N2 : suppression derrière une confirmation (ConfirmDialog).
    expect(deleteWindow).not.toHaveBeenCalled();
    await user.click(screen.getByRole("button", { name: "Supprimer" }));
    expect(deleteWindow).toHaveBeenCalledWith("wf");
  });
});

/** Promesse : on ouvre un gymnase pour UN jour depuis son éditeur, et on sait ce que retirer cette ligne ne fait pas. */
describe("MatchWindowsEditor — ouvertures exceptionnelles", () => {
  const opening = (label: string | null = "Coupe"): VenueMatchWindow => ({ id: "o1", venueId: "v1", dayOfWeek: null, appliesOnPublicHolidays: false, openingDate: "2026-11-11", label, startTime: "14:00", endTime: "20:00" });

  it("section vide : le dit", () => {
    renderWithProviders(<MatchWindowsEditor venueId="v1" />);
    expect(screen.getByRole("heading", { name: MATCHES_TEXTS.openingSectionTitle })).toBeInTheDocument();
    expect(screen.getByText(MATCHES_TEXTS.openingsEmpty)).toBeInTheDocument();
  });

  it("ajouter envoie une date, une plage et un motif, sans jour de semaine", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchWindowsEditor venueId="v1" />);

    await user.type(screen.getByLabelText(MATCHES_TEXTS.openingDateLabel), "2026-11-11");
    await user.clear(screen.getByLabelText(MATCHES_TEXTS.openingStartLabel));
    await user.type(screen.getByLabelText(MATCHES_TEXTS.openingStartLabel), "14:00");
    await user.clear(screen.getByLabelText(MATCHES_TEXTS.openingEndLabel));
    await user.type(screen.getByLabelText(MATCHES_TEXTS.openingEndLabel), "20:00");
    await user.type(screen.getByLabelText(MATCHES_TEXTS.openingLabelLabel), "Coupe");
    await user.click(screen.getByRole("button", { name: MATCHES_TEXTS.openingAdd }));

    expect(createWindow.mock.calls[0]?.[0]).toEqual(expect.objectContaining({ venueId: "v1", openingDate: "2026-11-11", startTime: "14:00", endTime: "20:00", label: "Coupe" }));
    expect(createWindow.mock.calls[0]?.[0]).not.toHaveProperty("dayOfWeek", expect.any(Number));
  });

  it("une plage inversée n'envoie rien", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchWindowsEditor venueId="v1" prefillOpening={{ date: "2026-11-11", start: "20:00", end: "14:00" }} />);
    await user.click(screen.getByRole("button", { name: MATCHES_TEXTS.openingAdd }));
    expect(createWindow).not.toHaveBeenCalled();
    expect(screen.getByText(MATCHES_TEXTS.matchWindowRangeInvalid)).toBeInTheDocument();
  });

  it("A4 : une date hors saison avertit sans bloquer", () => {
    renderWithProviders(<MatchWindowsEditor venueId="v1" prefillOpening={{ date: "2027-07-10", start: "14:00", end: "20:00" }} />);
    expect(screen.getByText(MATCHES_TEXTS.openingOutOfSeason("1 sept. 2026", "30 juin 2027"))).toBeInTheDocument();
    expect(screen.getByRole("button", { name: MATCHES_TEXTS.openingAdd })).toBeEnabled();
  });

  it("une date dans la saison n'avertit pas", () => {
    renderWithProviders(<MatchWindowsEditor venueId="v1" prefillOpening={{ date: "2026-11-11", start: "14:00", end: "20:00" }} />);
    expect(screen.queryByText(MATCHES_TEXTS.openingOutOfSeason("1 sept. 2026", "30 juin 2027"))).toBeNull();
  });

  it("D5bis : prefillOpening préremplit la ligne d'ajout", () => {
    renderWithProviders(<MatchWindowsEditor venueId="v1" prefillOpening={{ date: "2026-11-11", start: "18:00", end: "22:00" }} />);
    expect(screen.getByLabelText(MATCHES_TEXTS.openingDateLabel)).toHaveValue("2026-11-11");
    expect(screen.getByLabelText(MATCHES_TEXTS.openingStartLabel)).toHaveValue("18:00");
    expect(screen.getByLabelText(MATCHES_TEXTS.openingEndLabel)).toHaveValue("22:00");
  });

  it("une ligne affiche la date, la plage et le motif ; la grille hebdo ne la prend pas pour un férié", () => {
    windowsState.data = [opening()];
    renderWithProviders(<MatchWindowsEditor venueId="v1" />);
    expect(screen.getByText(/mer\. 11 nov\. 2026 · 14:00 – 20:00 · Coupe/)).toBeInTheDocument();
    expect(screen.queryByText(MATCHES_TEXTS.matchWindowHolidayOption, { selector: "li *, li" })).toBeNull();
  });

  it("sans motif : la ligne s'arrête à la plage", () => {
    windowsState.data = [opening(null)];
    renderWithProviders(<MatchWindowsEditor venueId="v1" />);
    expect(screen.getByText(/mer\. 11 nov\. 2026 · 14:00 – 20:00$/)).toBeInTheDocument();
  });

  /** Retour visuel : ajouter réussi repart d'un formulaire vierge ; un refus laisse la saisie à corriger. */
  describe("après « Ajouter l'ouverture »", () => {
    const fieldValues = () => [
      (screen.getByLabelText(MATCHES_TEXTS.openingDateLabel) as HTMLInputElement).value,
      (screen.getByLabelText(MATCHES_TEXTS.openingStartLabel) as HTMLInputElement).value,
      (screen.getByLabelText(MATCHES_TEXTS.openingEndLabel) as HTMLInputElement).value,
      (screen.getByLabelText(MATCHES_TEXTS.openingLabelLabel) as HTMLInputElement).value,
    ];
    const outOfSeason = () => screen.queryByText(MATCHES_TEXTS.openingOutOfSeason("1 sept. 2026", "30 juin 2027"));

    it("réussi : date, motif et heures reviennent au défaut, l'avertissement hors saison part avec", async () => {
      const user = userEvent.setup();
      createWindow.mockImplementation((_input: unknown, options?: { onSuccess?: () => void }) => options?.onSuccess?.());
      renderWithProviders(<MatchWindowsEditor venueId="v1" prefillOpening={{ date: "2027-07-10", start: "18:00", end: "22:00" }} />);
      await user.type(screen.getByLabelText(MATCHES_TEXTS.openingLabelLabel), "Coupe");
      expect(outOfSeason()).toBeInTheDocument();

      await user.click(screen.getByRole("button", { name: MATCHES_TEXTS.openingAdd }));

      expect(fieldValues()).toEqual(["", "14:00", "20:00", ""]);
      expect(outOfSeason()).toBeNull();
    });

    it("refus serveur : rien n'est vidé", async () => {
      const user = userEvent.setup();
      createWindow.mockImplementation((_input: unknown, options?: { onError?: (error: Error) => void }) => options?.onError?.(new Error("refus")));
      renderWithProviders(<MatchWindowsEditor venueId="v1" prefillOpening={{ date: "2027-07-10", start: "18:00", end: "22:00" }} />);
      await user.type(screen.getByLabelText(MATCHES_TEXTS.openingLabelLabel), "Coupe");

      await user.click(screen.getByRole("button", { name: MATCHES_TEXTS.openingAdd }));

      expect(createWindow).toHaveBeenCalledTimes(1);
      expect(fieldValues()).toEqual(["2027-07-10", "18:00", "22:00", "Coupe"]);
      expect(outOfSeason()).toBeInTheDocument();
    });
  });

  it("D10 : supprimer passe par une confirmation qui dit que le match reste placé", async () => {
    const user = userEvent.setup();
    windowsState.data = [opening()];
    renderWithProviders(<MatchWindowsEditor venueId="v1" />);

    await user.click(screen.getByRole("button", { name: MATCHES_TEXTS.openingDelete("mer. 11 nov. 2026 14:00") }));
    expect(deleteWindow).not.toHaveBeenCalled();
    expect(screen.getByText(MATCHES_TEXTS.openingDeleteDescription("mer. 11 nov. 2026", "14:00 – 20:00"))).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: MATCHES_TEXTS.matchWindowDeleteConfirm }));
    expect(deleteWindow).toHaveBeenCalledWith("o1");
  });
});
