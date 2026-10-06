import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { MembersResponse } from "@/features/auth/api";

// On mocke le module API VOISIN (auth/api) et on monte les VRAIS hooks react-query
// (queries.ts) sur un QueryClient réel — le test garde donc la logique de lecture,
// d'invalidation et de restitution d'erreur, pas seulement le câblage (règle §7.2).
const api = vi.hoisted(() => ({
  getMembers: vi.fn<() => Promise<MembersResponse>>(),
  changeMemberRole: vi.fn().mockResolvedValue({}),
  deactivateMember: vi.fn().mockResolvedValue(undefined),
  reactivateMember: vi.fn().mockResolvedValue(undefined),
}));
vi.mock("@/features/auth/api", () => api);

const toast = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn(), info: vi.fn() }));
vi.mock("@/shared/stores/toastStore", () => ({ toast }));

import { MembersSection } from "./MembersSection";

const self = { id: "self", userId: "us", email: "boss@club.fr", firstName: "Grace", lastName: "Hopper", role: "admin", isSelf: true };
const bob = { id: "m-bob", userId: "ub", email: "bob@club.fr", firstName: "Bob", lastName: "Martin", role: "member", isSelf: false };

function renderSection(data: MembersResponse) {
  api.getMembers.mockResolvedValue(data);
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MembersSection />
    </QueryClientProvider>,
  );
}

describe("MembersSection", () => {
  beforeEach(() => vi.clearAllMocks());

  it("liste actifs (badge « vous » + rôle) et désactivés", async () => {
    renderSection({ members: [self, bob], deactivated: [{ id: "m-ada", userId: "ua", email: "ada@club.fr", firstName: "Ada", lastName: "Lovelace", role: "member" }] });
    expect(await screen.findByText("Grace Hopper")).toBeInTheDocument();
    expect(screen.getByText("vous")).toBeInTheDocument();
    expect(screen.getByText("Bob Martin")).toBeInTheDocument();
    // Bloc désactivés : nom + rôle en libellé + geste de réactivation.
    expect(screen.getByText("Ada Lovelace")).toBeInTheDocument();
    expect(screen.getByText(/ada@club\.fr · Membre/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Réactiver/ })).toBeInTheDocument();
  });

  it("changer le rôle d'un autre membre appelle l'API avec (id, rôle)", async () => {
    const user = userEvent.setup();
    renderSection({ members: [self, bob], deactivated: [] });
    await screen.findByText("Bob Martin");
    await user.selectOptions(screen.getByLabelText("Rôle de Bob Martin"), "Gestionnaire");
    expect(api.changeMemberRole).toHaveBeenCalledWith("m-bob", "admin");
  });

  it("réactiver un désactivé appelle l'API avec son id", async () => {
    const user = userEvent.setup();
    renderSection({ members: [self], deactivated: [{ id: "m-ada", userId: "ua", email: "ada@club.fr", firstName: "Ada", lastName: "Lovelace", role: "member" }] });
    await screen.findByText("Ada Lovelace");
    await user.click(screen.getByRole("button", { name: /Réactiver/ }));
    expect(api.reactivateMember).toHaveBeenCalledWith("m-ada");
  });

  it("dernier gestionnaire sur soi : rôle et désactivation désactivés, avec explication (serveur seul juge sinon)", async () => {
    renderSection({ members: [self], deactivated: [] });
    await screen.findByText("Grace Hopper");
    expect(screen.getByLabelText("Rôle de Grace Hopper")).toBeDisabled();
    const deactivate = screen.getByRole("button", { name: /Désactiver/ });
    // Désactivé mais DÉCOUVRABLE (A11Y-30, `disabledReason`) : aria-disabled + raison en description.
    expect(deactivate).toHaveAttribute("aria-disabled", "true");
    expect(deactivate).toHaveAttribute("title", expect.stringContaining("seul gestionnaire"));
  });

  it("avec deux gestionnaires, le geste sur soi redevient possible (aucune prédiction du 409)", async () => {
    const coManager = { ...bob, role: "admin" };
    renderSection({ members: [self, coManager], deactivated: [] });
    await screen.findByText("Grace Hopper");
    expect(screen.getByLabelText("Rôle de Grace Hopper")).not.toBeDisabled();
  });

  it("une lecture en échec affiche un message d'erreur, pas une liste vide", async () => {
    api.getMembers.mockRejectedValue(new Error("boom"));
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MembersSection />
      </QueryClientProvider>,
    );
    expect(await screen.findByText(/Impossible de charger les membres/)).toBeInTheDocument();
  });

  it("un geste refusé par le serveur est restitué (toast), jamais avalé", async () => {
    const user = userEvent.setup();
    api.deactivateMember.mockRejectedValueOnce(new Error("409"));
    renderSection({ members: [self, bob], deactivated: [] });
    await screen.findByText("Bob Martin");
    // Désactiver Bob (non-soi) : l'UI ne prédit rien, elle tente et restitue l'échec.
    await user.click(screen.getAllByRole("button", { name: /Désactiver/ })[1]);
    await vi.waitFor(() => expect(toast.error).toHaveBeenCalled());
  });
});
