import { render, screen } from "@testing-library/react";
import { createMemoryRouter, RouterProvider } from "react-router";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { beforeEach, describe, expect, it, vi } from "vitest";

/**
 * UXS-09 — `ProfilePage` rendait un `FullPageSpinner` sur `isLoading || !data` : un échec de
 * lecture de `/api/me` (data undefined, isError) retombait donc dans un chargement ÉTERNEL (une
 * erreur rendue comme « ça charge »). On exerce la vraie page + le vrai `readState` ; seul `useMe`
 * est muté pour échouer.
 */
let meError = false;

vi.mock("@/shared/session/queries", () => ({
  useMe: () =>
    meError
      ? { data: undefined, isError: true, isLoading: false, refetch: vi.fn() }
      : { data: { id: "u1", email: "flo@club.fr", pendingEmail: null, firstName: "Flo", lastName: "J", isDemo: false, role: "admin", club: { name: "BCCL" } }, isError: false, isLoading: false, refetch: vi.fn() },
}));
vi.mock("./queries", () => ({
  useUpdateProfile: () => ({ mutate: vi.fn(), isPending: false }),
  useRequestEmailChange: () => ({ mutate: vi.fn(), isPending: false }),
  useCancelEmailChange: () => ({ mutate: vi.fn(), isPending: false }),
  useChangePassword: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteAccount: () => ({ mutate: vi.fn(), isPending: false }),
  useDownloadMyData: () => ({ mutate: vi.fn(), isPending: false }),
}));
vi.mock("@/features/auth/queries", () => ({ useLogout: () => vi.fn() }));

import { ProfilePage } from "./ProfilePage";

function renderProfile() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const router = createMemoryRouter([{ path: "*", element: <ProfilePage /> }], { initialEntries: ["/profile"] });
  return render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

describe("ProfilePage — échec de lecture (UXS-09)", () => {
  beforeEach(() => {
    meError = false;
  });

  it("échec de /api/me → « Le chargement a échoué » + Réessayer (jamais un spinner éternel)", () => {
    meError = true;
    renderProfile();

    expect(screen.getByRole("alert")).toHaveTextContent("Le chargement a échoué");
    expect(screen.getByRole("button", { name: "Réessayer" })).toBeInTheDocument();
    // Ce n'est PAS un chargement : pas de statut aria-busy.
    expect(screen.queryByText(/Mes informations/)).not.toBeInTheDocument();
  });

  it("lecture OK → la fiche profil s'affiche", () => {
    renderProfile();
    expect(screen.getByText("Mes informations")).toBeInTheDocument();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });
});
