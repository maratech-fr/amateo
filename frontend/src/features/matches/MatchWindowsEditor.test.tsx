import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { VenueMatchWindow } from "./api/venues";
import { MatchWindowsEditor } from "./MatchWindowsEditor";

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
  createWindow.mockClear();
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
