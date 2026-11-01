import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { ClubEntitlements } from "@/shared/session/api";
import { openListbox, pickListboxOption } from "@/test/pickListboxOption";
import { renderWithProviders } from "@/test/utils";

import * as matchesApi from "./api";
import { ExportMatchesDialog } from "./ExportMatchesDialog";
import { MATCHES_EXPORT_TEXTS as TEXTS } from "./texts/export";

/**
 * Promesse : la fenêtre d'export envoie exactement ce que le gestionnaire a choisi, nomme le fichier
 * d'après son choix, dit ce qu'un export coûte en Découverte, et ne déclenche rien sur « Annuler » ni
 * sur une plage impossible. Tout membre y accède (parité planning) : le rôle n'entre pas ici.
 * Le contrôle « Équipes » est un `SegmentedControl` (boutons), « Équipe » un `TeamSelect` : la liste
 * porte le repère d'entente et suit l'ordre des rangs, comme partout (`ctcMarkGuard.test.ts`).
 */
const entitlements = vi.hoisted(() => ({ value: undefined as Partial<ClubEntitlements> | undefined }));
vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { club: { entitlements: entitlements.value } } }),
}));

vi.mock("./api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("./api")>()),
  exportFixturesPdf: vi.fn(),
}));

const downloadSpy = vi.hoisted(() => vi.fn());
vi.mock("@/shared/lib/download", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/shared/lib/download")>()),
  downloadBlob: downloadSpy,
}));

const teams = [
  { id: "team-13", name: "U13 M1", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true },
  { id: "team-15", name: "U15 F", sportCategoryId: "c", priorityTierId: 1, tierOrder: 1, gender: null, level: null, sessionsPerWeek: 2, isActive: true, isCtc: true, ctcPartner: "Les Lions" },
];

const tiers = [{ id: 1, label: "S", name: "Fanion", color: null }];

function open(defaults: { teamId: string | null; from: string; to: string } = { teamId: null, from: "2026-11-01", to: "2026-11-30" }) {
  const onClose = vi.fn();
  renderWithProviders(<ExportMatchesDialog open onClose={onClose} teams={teams} tiers={tiers} defaults={defaults} />);
  return { onClose, dialog: within(screen.getByRole("dialog", { name: TEXTS.dialogTitle })) };
}

type DialogQueries = ReturnType<typeof within>;

/** Le déclencheur de `TeamSelect` : son nom accessible est « Équipe + valeur choisie ». */
function queryTeamTrigger(dialog: DialogQueries): HTMLElement | null {
  return dialog.queryByRole("button", { name: (name) => name.includes(TEXTS.teamLabel) });
}

function teamTrigger(dialog: DialogQueries): HTMLElement {
  return dialog.getByRole("button", { name: (name) => name.includes(TEXTS.teamLabel) });
}

function credits(remaining: number): Partial<ClubEntitlements> {
  return { creditsMax: 10, creditsUsed: 10 - remaining, canGenerate: remaining > 0, canPlaceMatches: remaining > 0, canExportPdf: remaining > 0 };
}

beforeEach(() => {
  vi.mocked(matchesApi.exportFixturesPdf).mockReset().mockResolvedValue(new Blob(["%PDF"]));
  downloadSpy.mockReset();
  entitlements.value = { creditsMax: null, creditsUsed: 0, canExportPdf: true };
});

describe("ExportMatchesDialog — ce qui part au serveur", () => {
  it("tout le club : teamId null, fichier nommé club + plage", async () => {
    const user = userEvent.setup();
    const { dialog } = open();

    await user.click(dialog.getByRole("button", { name: TEXTS.downloadButton }));

    await waitFor(() => expect(matchesApi.exportFixturesPdf).toHaveBeenCalledWith({ from: "2026-11-01", to: "2026-11-30", teamId: null }));
    await waitFor(() => expect(downloadSpy).toHaveBeenCalledWith(expect.any(Blob), "matchs-club-2026-11-01-2026-11-30.pdf"));
  });

  it("une équipe pré-choisie : son id, fichier nommé d'après elle", async () => {
    const user = userEvent.setup();
    const { dialog } = open({ teamId: "team-13", from: "2026-11-09", to: "2026-11-15" });

    expect(teamTrigger(dialog)).toHaveAccessibleName(expect.stringContaining("U13 M1"));
    await user.click(dialog.getByRole("button", { name: TEXTS.downloadButton }));

    await waitFor(() => expect(matchesApi.exportFixturesPdf).toHaveBeenCalledWith({ from: "2026-11-09", to: "2026-11-15", teamId: "team-13" }));
    await waitFor(() => expect(downloadSpy).toHaveBeenCalledWith(expect.any(Blob), "matchs-u13-m1-2026-11-09-2026-11-15.pdf"));
  });

  it("choisir une équipe puis revenir à Tout le club vide l'équipe", async () => {
    const user = userEvent.setup();
    const { dialog } = open();

    await user.click(dialog.getByRole("button", { name: TEXTS.scopeTeam }));
    await pickListboxOption(user, TEXTS.teamLabel, "U15 F");
    await user.click(dialog.getByRole("button", { name: TEXTS.scopeClub }));

    expect(queryTeamTrigger(dialog)).not.toBeInTheDocument();
    await user.click(dialog.getByRole("button", { name: TEXTS.downloadButton }));

    await waitFor(() => expect(matchesApi.exportFixturesPdf).toHaveBeenCalledWith(expect.objectContaining({ teamId: null })));
  });

  it("« Une équipe » sans équipe choisie : bouton inactif, aucun appel", async () => {
    const user = userEvent.setup();
    const { dialog } = open();

    await user.click(dialog.getByRole("button", { name: TEXTS.scopeTeam }));

    const download = dialog.getByRole("button", { name: TEXTS.downloadButton });
    expect(download).toBeDisabled();
    await user.click(download);
    expect(matchesApi.exportFixturesPdf).not.toHaveBeenCalled();
  });

  it("une équipe choisie ensuite réactive le bouton et part au serveur", async () => {
    const user = userEvent.setup();
    const { dialog } = open();

    await user.click(dialog.getByRole("button", { name: TEXTS.scopeTeam }));
    await pickListboxOption(user, TEXTS.teamLabel, "U15 F");
    await user.click(dialog.getByRole("button", { name: TEXTS.downloadButton }));

    await waitFor(() => expect(matchesApi.exportFixturesPdf).toHaveBeenCalledWith(expect.objectContaining({ teamId: "team-15" })));
  });

  it("un téléchargement réussi ferme la fenêtre", async () => {
    const user = userEvent.setup();
    const { dialog, onClose } = open();

    await user.click(dialog.getByRole("button", { name: TEXTS.downloadButton }));

    await waitFor(() => expect(downloadSpy).toHaveBeenCalled());
    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
  });

  it("un échec laisse la fenêtre ouverte, pour réessayer sans tout ressaisir", async () => {
    vi.mocked(matchesApi.exportFixturesPdf).mockRejectedValueOnce(new Error("panne"));
    const user = userEvent.setup();
    const { dialog, onClose } = open();

    await user.click(dialog.getByRole("button", { name: TEXTS.downloadButton }));

    await waitFor(() => expect(matchesApi.exportFixturesPdf).toHaveBeenCalled());
    await waitFor(() => expect(dialog.getByRole("button", { name: TEXTS.downloadButton })).toBeEnabled());
    expect(onClose).not.toHaveBeenCalled();
    expect(downloadSpy).not.toHaveBeenCalled();
  });

  it("les dates saisies partent telles quelles", async () => {
    const user = userEvent.setup();
    const { dialog } = open();

    fireEvent.change(dialog.getByLabelText(TEXTS.fromLabel), { target: { value: "2026-12-15" } });
    fireEvent.change(dialog.getByLabelText(TEXTS.toLabel), { target: { value: "2027-01-15" } });
    await user.click(dialog.getByRole("button", { name: TEXTS.downloadButton }));

    await waitFor(() => expect(matchesApi.exportFixturesPdf).toHaveBeenCalledWith({ from: "2026-12-15", to: "2027-01-15", teamId: null }));
  });

  it("Annuler ferme sans rien appeler", async () => {
    const user = userEvent.setup();
    const { dialog, onClose } = open();

    await user.click(dialog.getByRole("button", { name: TEXTS.cancel }));

    expect(onClose).toHaveBeenCalled();
    expect(matchesApi.exportFixturesPdf).not.toHaveBeenCalled();
  });

  it("une fin avant le début : message, bouton inactif, aucun appel", () => {
    const { dialog } = open();

    fireEvent.change(dialog.getByLabelText(TEXTS.toLabel), { target: { value: "2026-10-01" } });

    expect(dialog.getByText(TEXTS.rangeError)).toBeInTheDocument();
    expect(dialog.getByRole("button", { name: TEXTS.downloadButton })).toBeDisabled();
    expect(matchesApi.exportFixturesPdf).not.toHaveBeenCalled();
  });

  it("une même date en début et en fin reste une plage valide", () => {
    const { dialog } = open({ teamId: null, from: "2026-11-14", to: "2026-11-14" });

    expect(dialog.queryByText(TEXTS.rangeError)).not.toBeInTheDocument();
    expect(dialog.getByRole("button", { name: TEXTS.downloadButton })).toBeEnabled();
  });
});

describe("ExportMatchesDialog — le sélecteur d'équipe", () => {
  it("une équipe en entente porte son repère dans la liste, les autres non", async () => {
    const user = userEvent.setup();
    const { dialog } = open({ teamId: "team-13", from: "2026-11-01", to: "2026-11-30" });

    const list = await openListbox(user, TEXTS.teamLabel);
    expect(dialog.getByRole("listbox")).toBe(list);

    const ctc = within(list).getByRole("option", { name: /U15 F/ });
    expect(within(ctc).getByRole("img", { name: "Entente (CTC) avec Les Lions" })).toBeInTheDocument();
    const plain = within(list).getByRole("option", { name: /U13 M1/ });
    expect(within(plain).queryByRole("img", { name: /Entente/ })).not.toBeInTheDocument();
  });
});

describe("ExportMatchesDialog — ce que la fenêtre dit", () => {
  it("la phrase sur les lieux est toujours présente", () => {
    const { dialog } = open();

    expect(dialog.getByText(TEXTS.fixedNote)).toBeInTheDocument();
  });

  it("offre non bridée : ni mention de crédit, ni suffixe", () => {
    const { dialog } = open();

    expect(dialog.queryByText(TEXTS.creditNotice)).not.toBeInTheDocument();
    expect(dialog.getByRole("button", { name: TEXTS.downloadButton })).toBeEnabled();
  });

  it("Découverte avec du crédit : mention et solde sur le bouton", () => {
    entitlements.value = credits(3);
    const { dialog } = open();

    expect(dialog.getByText(TEXTS.creditNotice)).toBeInTheDocument();
    expect(dialog.getByRole("button", { name: `${TEXTS.downloadButton} (3)` })).toBeEnabled();
  });

  it("Découverte à 0 : bouton désactivé et raison affichée", () => {
    entitlements.value = credits(0);
    const { dialog } = open();

    expect(dialog.getByRole("button", { name: `${TEXTS.downloadButton} (0)` })).toBeDisabled();
    expect(dialog.getByText(TEXTS.creditsExhausted)).toBeInTheDocument();
  });

  it("pendant le téléchargement : le bouton le dit et ne se reclique pas", async () => {
    vi.mocked(matchesApi.exportFixturesPdf).mockReturnValue(new Promise(() => {}));
    const user = userEvent.setup();
    const { dialog } = open();

    await user.click(dialog.getByRole("button", { name: TEXTS.downloadButton }));

    expect(await dialog.findByRole("button", { name: TEXTS.downloading })).toBeDisabled();
  });
});
