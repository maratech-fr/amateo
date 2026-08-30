import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { getReleaseNotes } from "./api";
import { ReleaseNotesPage } from "./ReleaseNotesPage";

vi.mock("./api", async (importOriginal) => {
  const original = await importOriginal<typeof import("./api")>();
  return { ...original, getReleaseNotes: vi.fn(), markReleaseNotesSeen: vi.fn().mockResolvedValue(undefined) };
});

const mockGet = vi.mocked(getReleaseNotes);

describe("ReleaseNotesPage", () => {
  beforeEach(() => mockGet.mockReset());

  it("liste les nouveautés datées, corps sur plusieurs lignes", async () => {
    mockGet.mockResolvedValue({
      seenUpTo: null,
      items: [
        { id: "n1", date: "2026-08-13", title: "Vue planning", body: "Ligne 1\nLigne 2", publishedAt: "2026-08-13T09:00:00+00:00" },
      ],
    });
    renderWithProviders(<ReleaseNotesPage />);

    expect(await screen.findByText("Vue planning")).toBeInTheDocument();
    // Le corps est rendu tel quel (whitespace-pre-line préserve le retour ligne).
    const body = screen.getByText(/Ligne 1/);
    expect(body).toHaveClass("whitespace-pre-line");
  });

  it("affiche un état vide en français quand il n'y a aucune note", async () => {
    mockGet.mockResolvedValue({ seenUpTo: null, items: [] });
    renderWithProviders(<ReleaseNotesPage />);

    // P4-150 — la copie d'écran EXACTE est assertée (une altération du libellé rougit,
    // pas seulement sa disparition).
    expect(await screen.findByText("Aucune nouveauté pour le moment.")).toBeInTheDocument();
  });
});

/**
 * P4-107 (3ᵉ tranche) — la page Nouveautés suit les deux autres fiches. Son contenu est du
 * TEXTE : c'est la borne de lisibilité de `FichePage` (65 caractères par ligne) qui le tient,
 * pas la largeur du cadre.
 */
describe("largeur de la page Nouveautés", () => {
  it("est cadrée par FichePage, sans largeur concurrente", () => {
    mockGet.mockResolvedValue({ seenUpTo: null, items: [] });
    const { container } = renderWithProviders(<ReleaseNotesPage />);
    const root = container.firstChild as HTMLElement;
    const widths = root.className.split(/\s+/).filter((token) => token.startsWith("max-w-"));

    expect(widths).toEqual(["max-w-fiche"]);
    expect(root.className).toContain("mx-auto");
  });
});

