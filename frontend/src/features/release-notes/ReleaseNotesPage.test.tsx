import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { frDateSlashed } from "@/shared/lib/date";
import { renderWithProviders } from "@/test/utils";

import { getReleaseNotes } from "./api";
import { ReleaseNotesPage } from "./ReleaseNotesPage";
import { RELEASE_NOTES_TEXTS } from "./texts";

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
        { id: "n1", date: "2026-08-13", title: "Vue planning", body: "Ligne 1\nLigne 2", publishedAt: "2026-08-13T09:00:00+00:00", version: null },
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

  /**
   * ⚑ DÉPEND D'A4 (capture SWITCH de `/nouveautes` attendue) — si la capture ne confirme pas le
   * vide à droite des cartes, ce test et la décision tombent ensemble (retour au fondateur).
   *
   * Promesse : **sur cette seule page, le corps d'une note occupe toute la largeur de sa carte** —
   * la borne `[&_p]:max-w-prose` de `FichePage` n'y est PAS émise (`prose={false}`). Prouvé par
   * l'ABSENCE de la classe, pas par l'ordre des règles Tailwind (`max-w-none` perdrait contre
   * `max-w-prose` à spécificité égale). L'effet calculé (`maxWidth === "none"`) est témoigné par
   * `tests/e2e/release-notes.spec.ts` ; Club et Profil gardent la borne (`fiche-page.test.tsx`).
   */
  it("n'émet pas la borne de lisibilité des paragraphes (prose={false})", () => {
    mockGet.mockResolvedValue({ seenUpTo: null, items: [] });
    const { container } = renderWithProviders(<ReleaseNotesPage />);
    const root = container.firstChild as HTMLElement;

    expect(root.className.split(/\s+/)).not.toContain("[&_p]:max-w-prose");
  });
});

/**
 * Promesse (A3) : **sur la page, chaque note s'annonce « date - version », puis son TITRE — un vrai
 * titre `<h2>` sous le `<h1>` de la page — puis son corps.** Jumeau modale : `WhatsNewModal.test.tsx`
 * (même ordre, titre `<h3>` sous le `<h2>` de la modale).
 */
describe("ReleaseNotesPage — en-tête date-version, titre, corps", () => {
  beforeEach(() => mockGet.mockReset());

  it("ordre de lecture par carte : en-tête → titre h2 → corps ; date seule sans version", async () => {
    mockGet.mockResolvedValue({
      seenUpTo: null,
      items: [
        { id: "n2", date: "2026-11-15", title: "Nouvelle vue planning", body: "Corps versionné", publishedAt: "2026-11-15T09:00:00+00:00", version: "1.3.5" },
        { id: "n1", date: "2026-10-13", title: "Gymnases fermés", body: "Corps sans version", publishedAt: "2026-10-13T09:00:00+00:00", version: null },
      ],
    });
    renderWithProviders(<ReleaseNotesPage />);

    const title = await screen.findByRole("heading", { level: 2, name: "Nouvelle vue planning" });
    const heading = screen.getByText(RELEASE_NOTES_TEXTS.heading(frDateSlashed("2026-11-15"), "1.3.5"));
    const body = screen.getByText("Corps versionné");
    expect(heading.compareDocumentPosition(title) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(title.compareDocumentPosition(body) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

    expect(screen.getByRole("heading", { level: 2, name: "Gymnases fermés" })).toBeInTheDocument();
    expect(screen.getByText(RELEASE_NOTES_TEXTS.heading(frDateSlashed("2026-10-13"), null))).toBeInTheDocument();
  });
});

