import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { clearLastClick, recordLastClick } from "@/shared/feedback/lastClickStore";
import { renderWithProviders } from "@/test/utils";

import { submitFeedback } from "./api";
import { FeedbackDialog } from "./FeedbackDialog";

/**
 * Chantier F PR2 — la porte contextuelle joint le DERNIER CLIC accessible (nom + rôle du
 * contrôle, jamais une valeur saisie). Fichier À PART : il importe le module NEUF
 * `lastClickStore`, donc il rougit SEUL tant que le store/le câblage n'existent pas — sans
 * faire tomber au chargement la suite verte de `FeedbackDialog.test.tsx`.
 */
vi.mock("./api", async (importOriginal) => {
  const original = await importOriginal<typeof import("./api")>();
  return { ...original, submitFeedback: vi.fn() };
});

const mockSubmit = vi.mocked(submitFeedback);

beforeEach(() => {
  mockSubmit.mockReset();
  clearLastClick();
});

describe("FeedbackDialog — dernier clic accessible (contextuel)", () => {
  it("joint le nom accessible du dernier contrôle cliqué au contexte", async () => {
    mockSubmit.mockResolvedValue({ id: "fb1" });
    const btn = document.createElement("button");
    btn.setAttribute("aria-label", "Ajouter un gymnase");
    recordLastClick(btn);

    renderWithProviders(<FeedbackDialog variant="contextual" screen="wizard/venues" onClose={vi.fn()} />, { route: "/wizard" });
    await userEvent.type(screen.getByRole("textbox", { name: "Décrivez le problème" }), "Souci");
    await userEvent.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(mockSubmit).toHaveBeenCalled());
    const ctx = mockSubmit.mock.calls[0][0].context as Record<string, unknown>;
    expect(String(ctx.lastClick)).toContain("Ajouter un gymnase");
  });
});
