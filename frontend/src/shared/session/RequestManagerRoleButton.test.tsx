import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

/**
 * Promesse (UXS-20, petites lignes) : **« Demander à devenir gestionnaire » ne part jamais sur un
 * clic seul** — le bouton ouvre une confirmation qui dit où va la demande ; « Annuler » ou Échap la
 * referment sans rien envoyer, le bouton reste là.
 *
 * Le bouton est la maison unique du geste, partagée par le bandeau de fermeture et la carte « Mon
 * rôle ». Le chemin CONFIRMÉ (la demande part une fois, le toast, la relecture de la session) est
 * prouvé sur ses deux sites : `ClubClosingBanner.test.tsx` et `features/club/ClubPage.test.tsx`
 * (carte « Mon rôle ») — jamais rejoué ici. Hooks RÉELS (`./queries`), seule `./api` est mockée.
 */
const h = vi.hoisted(() => ({ requestManagerRole: vi.fn() }));
vi.mock("./api", async (importOriginal) => ({ ...(await importOriginal<object>()), ...h }));
vi.mock("@/shared/stores/authStore", () => ({
  useAuthStore: (sel: (s: { isAuthenticated: boolean }) => unknown) => sel({ isAuthenticated: true }),
}));
const toast = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn(), info: vi.fn() }));
vi.mock("@/shared/stores/toastStore", () => ({ toast }));

import { RequestManagerRoleButton } from "./RequestManagerRoleButton";
import { SESSION_TEXTS } from "./texts";

function renderButton() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <RequestManagerRoleButton />
    </QueryClientProvider>,
  );
}

describe("RequestManagerRoleButton — la demande se confirme (UXS-20)", () => {
  beforeEach(() => vi.clearAllMocks());

  it("le clic ouvre la confirmation (titre, où va la demande, « Envoyer la demande ») sans rien envoyer", async () => {
    renderButton();
    await userEvent.click(screen.getByRole("button", { name: SESSION_TEXTS.requestManagerRole }));

    const dialog = screen.getByRole("dialog", { name: SESSION_TEXTS.requestManagerRoleConfirmTitle });
    expect(within(dialog).getByText(SESSION_TEXTS.requestManagerRoleConfirmDescription)).toBeInTheDocument();
    expect(within(dialog).getByRole("button", { name: SESSION_TEXTS.requestManagerRoleConfirmLabel })).toBeInTheDocument();
    expect(h.requestManagerRole).not.toHaveBeenCalled();
  });

  it.each(["Annuler", "Échap"] as const)("%s : la confirmation se ferme, rien n'est envoyé, le bouton reste", async (exit) => {
    renderButton();
    await userEvent.click(screen.getByRole("button", { name: SESSION_TEXTS.requestManagerRole }));
    const dialog = screen.getByRole("dialog");
    if ("Annuler" === exit) {
      await userEvent.click(within(dialog).getByRole("button", { name: "Annuler" }));
    } else {
      await userEvent.keyboard("{Escape}");
    }

    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(h.requestManagerRole).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: SESSION_TEXTS.requestManagerRole })).toBeInTheDocument();
  });
});
