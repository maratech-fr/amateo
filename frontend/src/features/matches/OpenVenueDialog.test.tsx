import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Venue } from "./api";
import { OpenVenueDialog } from "./OpenVenueDialog";
import { MATCHES_TEXTS } from "./texts";

/**
 * D5 : la petite fenêtre dit ce que l'ouverture change et ne change pas, enregistre un fait du gymnase
 * (jamais la pose), et « Annuler » n'écrit rien. Rouge tant que le composant n'existe pas (module absent).
 */
const create = vi.hoisted(() => vi.fn());
vi.mock("./queries", () => ({ useCreateVenueMatchWindow: () => ({ mutate: create, isPending: false }) }));
vi.mock("@/shared/session/queries", () => ({
  useWorkingSeason: () => ({ id: "season-1", name: "2026-2027", startDate: "2026-09-01", endDate: "2027-06-30", isCurrent: true, isReadonly: false }),
}));

const venue: Venue = { id: "venue-1", name: "Gymnase A", color: null, externalLabels: [] };

function open(matchDate = "2026-11-11") {
  const onClose = vi.fn();
  const onOpened = vi.fn();
  renderWithProviders(<OpenVenueDialog venue={venue} matchDate={matchDate} defaultStart="18:00" defaultEnd="22:00" onClose={onClose} onOpened={onOpened} />);
  return { onClose, onOpened, dialog: screen.getByRole("dialog") };
}

beforeEach(() => {
  create.mockReset();
  create.mockImplementation((_input: unknown, options?: { onSuccess?: () => void }) => options?.onSuccess?.());
});

describe("OpenVenueDialog", () => {
  it("se présente sur ce gymnase, ce jour, avec la plage proposée", () => {
    const { dialog } = open();
    expect(within(dialog).getByRole("heading", { name: MATCHES_TEXTS.openVenueDialogTitle("Gymnase A", "mer. 11 nov.") })).toBeInTheDocument();
    expect(within(dialog).getByLabelText(MATCHES_TEXTS.openingStartLabel)).toHaveValue("18:00");
    expect(within(dialog).getByLabelText(MATCHES_TEXTS.openingEndLabel)).toHaveValue("22:00");
  });

  it("« Ouvrir » écrit une ligne datée, sans jour de semaine, puis prévient le panneau", async () => {
    const user = userEvent.setup();
    const { dialog, onOpened, onClose } = open();
    await user.type(within(dialog).getByLabelText(MATCHES_TEXTS.openingLabelLabel), "Coupe");
    await user.click(within(dialog).getByRole("button", { name: MATCHES_TEXTS.openVenueConfirm }));

    expect(create).toHaveBeenCalledTimes(1);
    expect(create.mock.calls[0]?.[0]).toEqual({ venueId: "venue-1", openingDate: "2026-11-11", startTime: "18:00", endTime: "22:00", label: "Coupe" });
    expect(onOpened).toHaveBeenCalled();
    expect(onClose).toHaveBeenCalled();
  });

  it("sans motif, aucun label vide n'est envoyé", async () => {
    const user = userEvent.setup();
    const { dialog } = open();
    await user.click(within(dialog).getByRole("button", { name: MATCHES_TEXTS.openVenueConfirm }));
    expect(create.mock.calls[0]?.[0]).not.toHaveProperty("label", "");
  });

  it("« Annuler » n'écrit rien", async () => {
    const user = userEvent.setup();
    const { dialog, onClose, onOpened } = open();
    await user.click(within(dialog).getByRole("button", { name: MATCHES_TEXTS.openVenueCancel }));
    expect(create).not.toHaveBeenCalled();
    expect(onOpened).not.toHaveBeenCalled();
    expect(onClose).toHaveBeenCalled();
  });

  it("une plage inversée n'écrit rien et le dit", async () => {
    const user = userEvent.setup();
    const { dialog } = open();
    const end = within(dialog).getByLabelText(MATCHES_TEXTS.openingEndLabel);
    await user.clear(end);
    await user.type(end, "10:00");
    await user.click(within(dialog).getByRole("button", { name: MATCHES_TEXTS.openVenueConfirm }));
    expect(create).not.toHaveBeenCalled();
    expect(within(dialog).getByText(MATCHES_TEXTS.matchWindowRangeInvalid)).toBeInTheDocument();
  });

  it("A4 : une date hors saison avertit, et « Ouvrir » reste actif", () => {
    const { dialog } = open("2027-07-10");
    expect(within(dialog).getByText(MATCHES_TEXTS.openingOutOfSeason("1 sept. 2026", "30 juin 2027"))).toBeInTheDocument();
    expect(within(dialog).getByRole("button", { name: MATCHES_TEXTS.openVenueConfirm })).toBeEnabled();
  });

  it("une date de la saison n'avertit pas", () => {
    const { dialog } = open();
    expect(within(dialog).queryByText(MATCHES_TEXTS.openingOutOfSeason("1 sept. 2026", "30 juin 2027"))).toBeNull();
  });
});
