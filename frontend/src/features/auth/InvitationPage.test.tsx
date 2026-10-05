import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { MemoryRouter, Route, Routes } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

const info: { data: { clubName: string; email: string; role: string; hasAccount: boolean } | undefined; isPending: boolean; isError: boolean } = {
  data: undefined,
  isPending: false,
  isError: false,
};
const acceptNew = { mutateAsync: vi.fn(), isPending: false };
const acceptConnected = { mutateAsync: vi.fn(), isPending: false };

vi.mock("./queries", () => ({
  useInvitationInfo: () => info,
  useAcceptInvitationNewAccount: () => acceptNew,
  useAcceptInvitationConnected: () => acceptConnected,
}));

const auth = { isAuthenticated: false };
vi.mock("@/shared/stores/authStore", () => ({
  useAuthStore: (selector: (s: { isAuthenticated: boolean }) => unknown) => selector(auth),
}));

const navigateMock = vi.fn();
vi.mock("react-router", async (orig) => ({ ...(await orig<typeof import("react-router")>()), useNavigate: () => navigateMock }));

import { InvitationPage } from "./InvitationPage";

const TOKEN = "a".repeat(64);
const invited = { clubName: "BC Testville", email: "paul@club.fr", role: "member", hasAccount: false };

function renderAt() {
  return render(
    <MemoryRouter initialEntries={[`/invitation/${TOKEN}`]}>
      <Routes>
        <Route path="/invitation/:token" element={<InvitationPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

function httpError(status: number, message?: string): HTTPError {
  const err = new HTTPError(new Response("{}", { status }), new Request("http://t/api/invitations/x"), {} as never);
  if (undefined !== message) {
    (err as { data?: unknown }).data = { error: message };
  }
  return err;
}

describe("InvitationPage (P4-299 — page publique)", () => {
  beforeEach(() => {
    info.data = undefined;
    info.isPending = false;
    info.isError = false;
    auth.isAuthenticated = false;
    acceptNew.mutateAsync.mockReset().mockResolvedValue(undefined);
    acceptConnected.mutateAsync.mockReset().mockResolvedValue(undefined);
    navigateMock.mockReset();
  });

  it("chargement → spinner", () => {
    info.isPending = true;
    renderAt();
    expect(screen.getByText(/Chargement…/)).toBeInTheDocument();
  });

  it("lien mort → message uniforme « Lien invalide ou expiré », annoncé", () => {
    info.isError = true;
    renderAt();
    expect(screen.getByRole("alert")).toHaveTextContent(/Lien invalide ou expiré/);
  });

  it("sans compte : crée le compte (adresse verrouillée) puis entre DANS l'app", async () => {
    info.data = { ...invited, hasAccount: false };
    const user = userEvent.setup();
    renderAt();

    // L'adresse invitée est affichée en lecture seule (non éditable).
    const email = screen.getByLabelText("Email") as HTMLInputElement;
    expect(email.value).toBe("paul@club.fr");
    expect(email).toHaveAttribute("readonly");

    await user.type(screen.getByLabelText("Prénom"), "Paul");
    await user.type(screen.getByLabelText("Nom"), "Durand");
    await user.type(screen.getByLabelText("Mot de passe"), "MotDePasse!12");
    await user.type(screen.getByLabelText("Confirmer le mot de passe"), "MotDePasse!12");
    await user.click(screen.getByRole("checkbox"));
    await user.click(screen.getByRole("button", { name: /Rejoindre BC Testville/ }));

    await waitFor(() => expect(acceptNew.mutateAsync).toHaveBeenCalledWith({ firstName: "Paul", lastName: "Durand", password: "MotDePasse!12", consent: true }));
    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith("/", { replace: true }));
  });

  it("sans compte + 409 (compte déjà existant) → bascule « Se connecter pour accepter »", async () => {
    info.data = { ...invited, hasAccount: false };
    acceptNew.mutateAsync.mockRejectedValueOnce(httpError(409, "Un compte existe déjà pour cette adresse."));
    const user = userEvent.setup();
    renderAt();

    await user.type(screen.getByLabelText("Prénom"), "Paul");
    await user.type(screen.getByLabelText("Nom"), "Durand");
    await user.type(screen.getByLabelText("Mot de passe"), "MotDePasse!12");
    await user.type(screen.getByLabelText("Confirmer le mot de passe"), "MotDePasse!12");
    await user.click(screen.getByRole("checkbox"));
    await user.click(screen.getByRole("button", { name: /Rejoindre BC Testville/ }));

    expect(await screen.findByRole("button", { name: /Se connecter pour accepter/ })).toBeInTheDocument();
  });

  it("compte existant, non connecté → « Se connecter pour accepter » (retour vers l'invitation)", async () => {
    info.data = { ...invited, hasAccount: true };
    const user = userEvent.setup();
    renderAt();
    await user.click(screen.getByRole("button", { name: /Se connecter pour accepter/ }));
    expect(navigateMock).toHaveBeenCalledWith(`/login?next=${encodeURIComponent(`/invitation/${TOKEN}`)}`);
  });

  it("connecté → acceptation en un clic puis entrée dans l'app", async () => {
    info.data = { ...invited, hasAccount: true };
    auth.isAuthenticated = true;
    const user = userEvent.setup();
    renderAt();
    await user.click(screen.getByRole("button", { name: /Accepter l'invitation/ }));
    await waitFor(() => expect(acceptConnected.mutateAsync).toHaveBeenCalled());
    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith("/", { replace: true }));
  });

  it("connecté avec une autre adresse → refus clair (403), pas d'entrée", async () => {
    info.data = { ...invited, hasAccount: true };
    auth.isAuthenticated = true;
    acceptConnected.mutateAsync.mockRejectedValueOnce(httpError(403, "Cette invitation a été envoyée à une autre adresse."));
    const user = userEvent.setup();
    renderAt();
    await user.click(screen.getByRole("button", { name: /Accepter l'invitation/ }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Cette invitation a été envoyée à une autre adresse.");
    expect(navigateMock).not.toHaveBeenCalled();
  });
});
