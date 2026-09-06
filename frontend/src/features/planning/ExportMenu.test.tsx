import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { pickListboxOption } from "@/test/pickListboxOption";

// On mocke les COUCHES VOISINES (export réseau, crédits) — la règle testée ici,
// « l'export annonce son périmètre quand l'écran est filtré » (P4-62), vit dans
// le composant, pas dans les mocks.
vi.mock("./queries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("./queries")>()),
  useScheduleExport: () => ({ run: vi.fn(), busy: null }),
}));
vi.mock("@/shared/credits/useCredits", () => ({ useCredits: () => null }));

import { ExportMenu } from "./ExportMenu";

const venues = [
  { id: "v1", name: "Debarros" },
  { id: "v2", name: "Tonkin" },
] as never[];

const openMenu = async (): Promise<void> => {
  const user = userEvent.setup();
  await user.click(screen.getByRole("button", { name: /Exporter/ }));
};

describe("ExportMenu — AUD-FRT-23, on ne promet pas un menu qu'on n'implémente pas", () => {
  it("expose un GROUPE nommé, jamais un role=menu (le panneau contient des <select>)", async () => {
    render(<ExportMenu scheduleId="s1" venues={venues} screenFilterCount={0} />);
    await openMenu();

    // Le panneau est une zone de réglages dépliée, avec un nom pour le lecteur d'écran.
    expect(screen.getByRole("group", { name: /Options d'export/i })).toBeInTheDocument();

    // ⚑ Le cœur du garde : un role="menu" PROMET la navigation aux flèches (modèle APG) et
    // n'admet que des menuitem — or ce panneau porte deux <select> et un paragraphe, et
    // n'écoute aucune flèche. L'annoncer « menu » donnait au lecteur d'écran un mode d'emploi
    // qui ne répond pas. Falsification : remettre role="menu" sur le panneau rougit ici.
    expect(screen.queryByRole("menu")).not.toBeInTheDocument();
    expect(screen.queryAllByRole("menuitem")).toHaveLength(0);

    // Et les formats restent atteignables comme de vrais boutons.
    expect(screen.getByRole("button", { name: /PDF/i })).toBeInTheDocument();
  });
});

describe("ExportMenu — P4-62, l'export annonce son périmètre", () => {
  it("ne dit RIEN quand l'écran n'est pas filtré (aucun écart à signaler)", async () => {
    render(<ExportMenu scheduleId="s1" venues={venues} screenFilterCount={0} />);
    await openMenu();

    expect(screen.queryByText(/L'écran est filtré/)).not.toBeInTheDocument();
  });

  it("annonce l'écart quand l'écran est filtré : l'export porte sur TOUS les gymnases", async () => {
    render(<ExportMenu scheduleId="s1" venues={venues} screenFilterCount={2} />);
    await openMenu();

    const note = screen.getByText(/L'écran est filtré/);
    expect(note).toHaveTextContent("2 ressources");
    expect(note).toHaveTextContent("tous les gymnases");
  });

  it("accorde le singulier et nomme le gymnase choisi comme périmètre réel", async () => {
    const user = userEvent.setup();
    render(<ExportMenu scheduleId="s1" venues={venues} screenFilterCount={1} />);
    await openMenu();

    expect(screen.getByText(/L'écran est filtré/)).toHaveTextContent("1 ressource");

    // Le périmètre annoncé suit le sélecteur de l'export, jamais le filtre d'écran :
    // c'est ce que le serveur rendra (le PDF ignore tout filtre client).
    await pickListboxOption(user, "Périmètre de l'export", "Tonkin");
    expect(screen.getByText(/L'écran est filtré/)).toHaveTextContent("Tonkin");
  });
});
