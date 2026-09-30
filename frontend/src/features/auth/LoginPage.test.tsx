import { fireEvent, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { markSessionExpired } from "@/shared/lib/sessionExpiredNotice";
import { useLoginSplashStore } from "@/shared/stores/loginSplashStore";
import { renderWithProviders } from "@/test/utils";

import { LoginPage } from "./LoginPage";

// P4-252 — la mutation login est mockée pour piloter succès/échec sans réseau (les tests d'UI
// existants ci-dessus ne soumettent pas, ils tolèrent ce double).
const { loginMock } = vi.hoisted(() => ({
  loginMock: { mutateAsync: vi.fn(), isPending: false },
}));
vi.mock("./queries", () => ({ useLogin: () => loginMock }));

describe("LoginPage", () => {
  it("renders the login form", () => {
    renderWithProviders(<LoginPage />);
    expect(screen.getByLabelText("Email")).toBeInTheDocument();
    expect(screen.getByLabelText("Mot de passe")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /se connecter/i })).toBeInTheDocument();
  });

  it("links to registration and password recovery", () => {
    renderWithProviders(<LoginPage />);
    expect(screen.getByRole("link", { name: /créer un compte/i })).toHaveAttribute("href", "/register");
    expect(screen.getByRole("link", { name: /oublié/i })).toHaveAttribute("href", "/forgot-password");
  });
});

/**
 * P5-14 — la session expirée n'est plus une redirection MUETTE. Un 401 hors login
 * pose un marqueur one-shot (client.ts) ; LoginPage le lit ET le consomme au
 * montage et rend le bloc de réassurance « Fin du temps réglementaire » au-dessus
 * du formulaire. « Se reconnecter » EST le formulaire déjà présent — pas de page
 * séparée, pas de query param (une URL en favori ne doit jamais l'afficher à tort).
 */
describe("LoginPage — bloc « session expirée »", () => {
  afterEach(() => {
    window.sessionStorage.clear();
  });

  it("affiche le bloc quand le marqueur est présent", async () => {
    markSessionExpired();
    renderWithProviders(<LoginPage />);
    expect(await screen.findByText(/fin du temps réglementaire/i)).toBeInTheDocument();
    expect(screen.getByText(/votre session a expiré/i)).toBeInTheDocument();
  });

  it("n'affiche PAS le bloc sans marqueur", () => {
    renderWithProviders(<LoginPage />);
    expect(screen.queryByText(/fin du temps réglementaire/i)).toBeNull();
  });

  it("consomme le marqueur : absent au second montage (one-shot)", async () => {
    markSessionExpired();
    const first = renderWithProviders(<LoginPage />);
    expect(await screen.findByText(/fin du temps réglementaire/i)).toBeInTheDocument();
    first.unmount();

    renderWithProviders(<LoginPage />);
    expect(screen.queryByText(/fin du temps réglementaire/i)).toBeNull();
  });
});

/**
 * P4-252 — le submit lance le splash « Signature » (`loginSplashStore.start`) ; un échec
 * l'annule (`cancel`), réaffiche le message d'erreur ACTUEL et rend le focus au champ e-mail.
 */
describe("LoginPage — splash de connexion", () => {
  beforeEach(() => {
    useLoginSplashStore.setState({ phase: "idle" });
    loginMock.mutateAsync.mockReset();
  });

  function fillAndSubmit() {
    fireEvent.change(screen.getByLabelText("Email"), { target: { value: "coach@club.fr" } });
    fireEvent.change(screen.getByLabelText("Mot de passe"), { target: { value: "s3cret-passphrase" } });
    fireEvent.click(screen.getByRole("button", { name: /se connecter/i }));
  }

  it("au submit : le splash démarre (idle → intro)", async () => {
    loginMock.mutateAsync.mockResolvedValueOnce(undefined);
    renderWithProviders(<LoginPage />);
    fillAndSubmit();
    // start() est synchrone au submit — l'intro est lancée avant même la résolution réseau.
    expect(useLoginSplashStore.getState().phase).toBe("intro");
    await waitFor(() => expect(loginMock.mutateAsync).toHaveBeenCalled());
  });

  it("identifiants refusés : splash annulé, erreur affichée, focus au champ e-mail", async () => {
    // Un rejet quelconque : `errorMessage` en tire un message FR (ici le repli connexion) — ce
    // test garde le PARCOURS (annulation + message + focus), pas le texte exact de `errorMessage`.
    loginMock.mutateAsync.mockRejectedValueOnce(new Error("boom"));
    renderWithProviders(<LoginPage />);
    fillAndSubmit();

    await waitFor(() => expect(useLoginSplashStore.getState().phase).toBe("cancelling"));
    expect(await screen.findByText(/problème de connexion/i)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByLabelText("Email")).toHaveFocus());
  });
});
