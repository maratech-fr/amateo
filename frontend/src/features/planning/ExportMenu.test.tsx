import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { pickListboxOption } from "@/test/pickListboxOption";

// On mocke les COUCHES VOISINES (export réseau, crédits) — la règle testée ici,
// « l'export annonce son périmètre quand l'écran est filtré » (P4-62), vit dans
// le composant, pas dans les mocks.
const exportRun = vi.hoisted(() => vi.fn());
vi.mock("./queries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("./queries")>()),
  useScheduleExport: () => ({ run: exportRun, busy: null }),
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

describe("ExportMenu — chantier E, le nom des coachs", () => {
  beforeEach(() => {
    localStorage.clear();
  });

  // Promesse fondateur : une case « Afficher le nom des coachs », DÉCOCHÉE par défaut. On vise le
  // RÔLE + le nom accessible (robuste au libellé exact) ; le stockage vide garantit le défaut
  // décoché quelle que soit la source du clubId.
  it("propose une case « Afficher le nom des coachs », décochée par défaut", async () => {
    render(<ExportMenu scheduleId="s1" venues={venues} screenFilterCount={0} />);
    await openMenu();

    const box = screen.getByRole("checkbox", { name: /coach/i });
    expect(box).not.toBeChecked();
  });
});

/**
 * PR6 « occupations de gymnase » (plan v5 §5 Export, §6 V3, D4-D5) — la case « Afficher les
 * occupations » n'existe que si la couche affichée porte au moins une occupation, décochée par
 * défaut ; cochée, l'export part avec l'option. Ce que le fichier contient alors est prouvé côté
 * serveur (`ScheduleExportOccupanciesTest`, Behat « J'exporte avec ou sans les occupations ») ;
 * ici, seulement la case et ce qu'elle transmet.
 */
describe("ExportMenu — afficher les occupations", () => {
  beforeEach(() => {
    localStorage.clear();
    exportRun.mockReset();
  });

  it("n'offre pas la case quand la couche affichée ne porte aucune occupation", async () => {
    render(<ExportMenu scheduleId="s1" venues={venues} screenFilterCount={0} occupancyCount={0} />);
    await openMenu();

    // Témoin : le panneau est bien ouvert (la case des coachs y est).
    expect(screen.getByRole("checkbox", { name: /coach/i })).toBeInTheDocument();
    expect(screen.queryByRole("checkbox", { name: /occupation/i })).not.toBeInTheDocument();
  });

  it("offre la case décochée dès qu'il existe une occupation ; cochée, l'export reçoit l'option", async () => {
    const user = userEvent.setup();
    render(<ExportMenu scheduleId="s1" venues={venues} screenFilterCount={0} occupancyCount={1} clubId="club-1" />);
    await openMenu();

    const box = screen.getByRole("checkbox", { name: /occupation/i });
    expect(box).not.toBeChecked();
    await user.click(box);
    await user.click(screen.getByRole("button", { name: /PDF/i }));

    expect(exportRun).toHaveBeenCalledWith("pdf", null, false, true);
  });
});
