import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { AdminReleaseNote } from "../api";
import { createAdminReleaseNote, getAdminReleaseNotes, publishAdminReleaseNote } from "../api";
import { useAdminStore } from "../store";
import { ADMIN_SECTIONS_TEXTS } from "../texts/sections";
import { ReleaseNotesSection } from "./ReleaseNotesSection";

vi.mock("../api", async (importOriginal) => {
  const original = await importOriginal<typeof import("../api")>();
  return {
    ...original,
    getAdminReleaseNotes: vi.fn(),
    createAdminReleaseNote: vi.fn(),
    publishAdminReleaseNote: vi.fn(),
    deleteAdminReleaseNote: vi.fn(),
  };
});

const mockList = vi.mocked(getAdminReleaseNotes);
const mockCreate = vi.mocked(createAdminReleaseNote);
const mockPublish = vi.mocked(publishAdminReleaseNote);

const DRAFT: AdminReleaseNote = { id: "d1", title: "Refonte du planning", body: "…", date: "2026-08-10", publishedAt: null, createdAt: "2026-08-10T10:00:00+00:00" };
const PUBLISHED: AdminReleaseNote = { id: "p1", title: "Export PDF amélioré", body: "…", date: "2026-08-12", publishedAt: "2026-08-12T09:00:00+00:00", createdAt: "2026-08-12T08:00:00+00:00" };

describe("ReleaseNotesSection", () => {
  beforeEach(() => {
    mockList.mockReset();
    mockCreate.mockReset();
    mockPublish.mockReset();
    useAdminStore.getState().setSession({ id: "sa", email: "sa@x" }, "csrf-token");
  });

  it("liste les brouillons ET les publiées", async () => {
    mockList.mockResolvedValue({ items: [DRAFT, PUBLISHED] });
    renderWithProviders(<ReleaseNotesSection />);

    expect(await screen.findByText("Refonte du planning")).toBeInTheDocument();
    expect(screen.getByText("Export PDF amélioré")).toBeInTheDocument();
  });

  it("enregistre une nouvelle note depuis le formulaire titre/date/corps", async () => {
    const user = userEvent.setup();
    mockList.mockResolvedValue({ items: [] });
    mockCreate.mockResolvedValue({ ...DRAFT, id: "new" });
    renderWithProviders(<ReleaseNotesSection />);

    await screen.findByRole("button", { name: /enregistrer/i });
    await user.type(screen.getByLabelText(/titre/i), "Ma note");
    // <input type="date"> ne se remplit pas caractère par caractère en jsdom : valeur directe.
    fireEvent.change(screen.getByLabelText(/date/i), { target: { value: "2026-08-13" } });
    await user.type(screen.getByLabelText(/contenu/i), "Le corps");
    await user.click(screen.getByRole("button", { name: /enregistrer/i }));

    await waitFor(() =>
      expect(mockCreate).toHaveBeenCalledWith({ title: "Ma note", body: "Le corps", noteDate: "2026-08-13" }, "csrf-token"),
    );
  });

  // P4-149 (option 1) — le vide « Aucune note » passe par EmptyHint variant="console"
  // (skin partagé `text-console-muted`), comme ses 14 voisins, jamais un <p> console fait main.
  it("rend l'état vide via le skin console partagé (EmptyHint)", async () => {
    mockList.mockResolvedValue({ items: [] });
    renderWithProviders(<ReleaseNotesSection />);

    const hint = await screen.findByText(ADMIN_SECTIONS_TEXTS.releaseNotesEmpty);
    expect(hint.className).toContain("text-console-muted");
  });

  it("publie un brouillon", async () => {
    const user = userEvent.setup();
    mockList.mockResolvedValue({ items: [DRAFT] });
    mockPublish.mockResolvedValue({ ...DRAFT, publishedAt: "2026-08-13T09:00:00+00:00" });
    renderWithProviders(<ReleaseNotesSection />);

    const row = (await screen.findByText("Refonte du planning")).closest("li");
    expect(row).not.toBeNull();
    await user.click(within(row as HTMLElement).getByRole("button", { name: /publier/i }));

    await waitFor(() => expect(mockPublish).toHaveBeenCalledWith("d1", "csrf-token"));
  });
});
