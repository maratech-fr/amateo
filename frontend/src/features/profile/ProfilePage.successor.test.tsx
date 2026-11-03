import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { createMemoryRouter, RouterProvider } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { MembersResponse } from "@/features/auth/api";
import type { MeResponse } from "@/shared/session/api";

/**
 * Chantier Membre PR 3 (plan v5, prévention D3 + correction 3) — avant de supprimer son compte, le
 * SEUL gestionnaire d'un club voit qu'il l'est et peut nommer un successeur en un clic (le geste du
 * sélecteur de rôle, `changeMemberRole(id, "admin")`) : le club ne fermera pas.
 *
 *  - L'avertissement vient du SERVEUR (`/api/me.soleManagerClubs`, jamais redérivé) et nomme TOUS
 *    les clubs concernés ; le sélecteur ne propose que les Membres actifs du club courant.
 *  - Un Membre n'interroge JAMAIS `/api/memberships` (403 garanti) — la requête n'est armée que
 *    pour qui peut gérer (`useCanManage`).
 *
 * Hooks RÉELS (session, adhésions) sur un vrai QueryClient : seules les couches `api` sont mockées.
 * Fichier à part de `ProfilePage.test.tsx` (celui-ci rend sans QueryClient, avec des hooks mockés).
 */
const session = vi.hoisted(() => ({ getMe: vi.fn() }));
vi.mock("@/shared/session/api", async (importOriginal) => ({ ...(await importOriginal<object>()), ...session }));
const auth = vi.hoisted(() => ({
  getMembers: vi.fn<() => Promise<MembersResponse>>(),
  changeMemberRole: vi.fn().mockResolvedValue({}),
}));
vi.mock("@/features/auth/api", async (importOriginal) => ({ ...(await importOriginal<object>()), ...auth }));
vi.mock("@/shared/stores/authStore", async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useAuthStore: (sel: (s: { isAuthenticated: boolean }) => unknown) => sel({ isAuthenticated: true }),
}));
const toast = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn(), info: vi.fn() }));
vi.mock("@/shared/stores/toastStore", () => ({ toast }));
vi.mock("./queries", () => ({
  useUpdateProfile: () => ({ mutate: vi.fn(), isPending: false }),
  useRequestEmailChange: () => ({ mutate: vi.fn(), isPending: false }),
  useCancelEmailChange: () => ({ mutate: vi.fn(), isPending: false }),
  useChangePassword: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteAccount: () => ({ mutate: vi.fn(), isPending: false }),
  useDownloadMyData: () => ({ mutate: vi.fn(), isPending: false }),
}));

import { ProfilePage } from "./ProfilePage";
import { PROFILE_TEXTS } from "./texts";

const marc = { id: "m-marc", userId: "u-marc", email: "marc@club.fr", firstName: "Marc", lastName: "Gestion", role: "admin", isSelf: true, managerRequestedAt: null, managerRequestEscalatedAt: null };
const lea = { id: "m-lea", userId: "u-lea", email: "lea@club.fr", firstName: "Léa", lastName: "Martin", role: "member", isSelf: false, managerRequestedAt: null, managerRequestEscalatedAt: null };

function me(role: string, soleManagerClubs: { id: string; name: string }[]): Partial<MeResponse> {
  return {
    id: "u-marc",
    email: "marc@club.fr",
    pendingEmail: null,
    firstName: "Marc",
    lastName: "Gestion",
    isDemo: false,
    membershipStatus: "active",
    role,
    soleManagerClubs,
    club: { id: "club-1", name: "BCCL", closingOn: null } as MeResponse["club"],
  };
}

function renderProfile() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const router = createMemoryRouter([{ path: "*", element: <ProfilePage /> }], { initialEntries: ["/profile"] });
  return render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

describe("ProfilePage — nommer un successeur avant de partir (D3)", () => {
  beforeEach(() => vi.clearAllMocks());

  it("seul gestionnaire : l'avertissement, la liste des Membres actifs ; nommer Léa appelle le geste du sélecteur, le dit, et l'avertissement disparaît", async () => {
    session.getMe.mockResolvedValueOnce(me("admin", [{ id: "club-1", name: "BCCL" }])).mockResolvedValue(me("admin", []));
    auth.getMembers.mockResolvedValueOnce({ members: [marc, lea] }).mockResolvedValue({ members: [marc, { ...lea, role: "admin" }] });
    const user = userEvent.setup();
    renderProfile();

    expect(await screen.findByText(PROFILE_TEXTS.soleManagerWarning("BCCL"))).toBeInTheDocument();
    const name = await screen.findByRole("button", { name: PROFILE_TEXTS.nameSuccessorOf("Léa Martin") });
    expect(screen.queryByRole("button", { name: PROFILE_TEXTS.nameSuccessorOf("Marc Gestion") })).not.toBeInTheDocument();

    await user.click(name);
    expect(auth.changeMemberRole).toHaveBeenCalledWith("m-lea", "admin");
    await vi.waitFor(() => expect(toast.success).toHaveBeenCalledWith(PROFILE_TEXTS.successorNamed("Léa Martin")));
    await vi.waitFor(() => expect(screen.queryByText(PROFILE_TEXTS.soleManagerWarning("BCCL"))).not.toBeInTheDocument());
  });

  it("aucun autre Membre actif : la phrase qui renvoie vers l'écran Club, aucun bouton", async () => {
    session.getMe.mockResolvedValue(me("admin", [{ id: "club-1", name: "BCCL" }]));
    auth.getMembers.mockResolvedValue({ members: [marc] });
    renderProfile();

    expect(await screen.findByText(PROFILE_TEXTS.noOtherActiveMember)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Nommer/ })).not.toBeInTheDocument();
  });

  it("l'avertissement nomme TOUS les clubs où il est seul gestionnaire", async () => {
    session.getMe.mockResolvedValue(me("admin", [{ id: "club-1", name: "BCCL" }, { id: "club-2", name: "AS Voisine" }]));
    auth.getMembers.mockResolvedValue({ members: [marc, lea] });
    renderProfile();

    expect(await screen.findByText(PROFILE_TEXTS.soleManagerWarning("BCCL"))).toBeInTheDocument();
    expect(screen.getByText(PROFILE_TEXTS.soleManagerWarning("AS Voisine"))).toBeInTheDocument();
  });

  it("un Membre : aucun avertissement, et JAMAIS d'appel à /api/memberships (il y recevrait 403)", async () => {
    session.getMe.mockResolvedValue(me("member", []));
    auth.getMembers.mockResolvedValue({ members: [] });
    renderProfile();

    expect(await screen.findByText(/irréversible/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Nommer/ })).not.toBeInTheDocument();
    expect(auth.getMembers).not.toHaveBeenCalled();
  });

  it("Membre du club courant mais seul gestionnaire d'un AUTRE club : l'avertissement le nomme, sans jamais interroger /api/memberships", async () => {
    // Le cas qui arme réellement l'avertissement chez un Membre (multi-club) : sans lui, la garde
    // « pas d'appel pour un Membre » serait verte pour rien (aucun avertissement → rien de monté).
    session.getMe.mockResolvedValue(me("member", [{ id: "club-2", name: "AS Voisine" }]));
    auth.getMembers.mockResolvedValue({ members: [marc, lea] });
    renderProfile();

    expect(await screen.findByText(PROFILE_TEXTS.soleManagerWarning("AS Voisine"))).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Nommer/ })).not.toBeInTheDocument();
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(auth.getMembers).not.toHaveBeenCalled();
  });
});
