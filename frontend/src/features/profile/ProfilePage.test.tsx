import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

const deleteMut = vi.fn();
const exportMut = vi.fn();
const requestEmailMut = vi.fn();
const cancelEmailMut = vi.fn();
const logoutFn = vi.fn();
let pendingEmail: string | null = null;
let isDemo = false;

vi.mock("./queries", () => ({
  useUpdateProfile: () => ({ mutate: vi.fn(), isPending: false }),
  useRequestEmailChange: () => ({ mutate: requestEmailMut, isPending: false }),
  useCancelEmailChange: () => ({ mutate: cancelEmailMut, isPending: false }),
  useChangePassword: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteAccount: () => ({ mutate: deleteMut, isPending: false }),
  useDownloadMyData: () => ({ mutate: exportMut, isPending: false }),
}));

vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({
    data: { id: "u1", email: "flo@club.fr", pendingEmail, firstName: "Flo", lastName: "Journey", isDemo, role: "admin", club: { name: "BCCL" } },
    isLoading: false,
  }),
}));
vi.mock("@/features/auth/queries", () => ({
  useLogout: () => logoutFn,
}));

import { ProfilePage } from "./ProfilePage";

describe("ProfilePage — zone de danger (RGPD)", () => {
  beforeEach(() => {
    deleteMut.mockClear();
    logoutFn.mockClear();
    requestEmailMut.mockClear();
    cancelEmailMut.mockClear();
    pendingEmail = null;
    isDemo = false;
  });

  it("désarme la suppression tant que le mot de passe n'est pas saisi (ré-authentification)", () => {
    render(<ProfilePage />);
    expect(screen.getByRole("button", { name: /Supprimer définitivement mon compte/ })).toBeDisabled();
    expect(deleteMut).not.toHaveBeenCalled();
  });

  it("arme et appelle la mutation avec le mot de passe saisi", async () => {
    const user = userEvent.setup();
    render(<ProfilePage />);

    await user.type(screen.getByLabelText(/Confirmez avec votre mot de passe/), "Password123!");
    const button = screen.getByRole("button", { name: /Supprimer définitivement mon compte/ });
    expect(button).toBeEnabled();

    await user.click(button);
    expect(deleteMut).toHaveBeenCalledWith("Password123!", expect.anything());
  });

  it("expose l'export RGPD de mes données", async () => {
    const user = userEvent.setup();
    render(<ProfilePage />);
    await user.click(screen.getByRole("button", { name: /Exporter mes données/ }));
    expect(exportMut).toHaveBeenCalled();
  });

  it("annonce la conséquence : anonymisation immédiate + purge club à 30 jours", () => {
    render(<ProfilePage />);
    expect(screen.getByText(/irréversible/)).toBeInTheDocument();
    expect(screen.getByText(/30 jours/)).toBeInTheDocument();
    expect(screen.getByText(/fiche\s+publique FFBB/)).toBeInTheDocument();
  });

  it("P4-74 — changer l'e-mail DÉCLENCHE la demande de confirmation (n'écrit pas en direct)", async () => {
    const user = userEvent.setup();
    render(<ProfilePage />);

    const emailInput = screen.getByLabelText("E-mail");
    await user.clear(emailInput);
    await user.type(emailInput, "nouvelle@club.fr");

    // Revue sécu P4-74 : le mot de passe courant est exigé — le bouton reste
    // fermé tant qu'il manque (le serveur refuse de toute façon en 400).
    const button = screen.getByRole("button", { name: /Envoyer un lien de confirmation/ });
    expect(button).toBeDisabled();
    await user.type(screen.getByLabelText("Votre mot de passe"), "MonMotDePasse!1");
    expect(button).toBeEnabled();
    await user.click(button);
    expect(requestEmailMut).toHaveBeenCalledWith(
      { email: "nouvelle@club.fr", currentPassword: "MonMotDePasse!1" },
      expect.anything(),
    );

    // Le message dit clairement que l'adresse actuelle reste active.
    expect(screen.getByText(/adresse actuelle reste active/)).toBeInTheDocument();
  });

  it("P4-74 — affiche l'attente et permet d'annuler quand un pendingEmail existe", async () => {
    pendingEmail = "nouvelle@club.fr";
    const user = userEvent.setup();
    render(<ProfilePage />);

    expect(screen.getByText(/En attente de confirmation/)).toBeInTheDocument();
    expect(screen.getByText("nouvelle@club.fr")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /Annuler/ }));
    expect(cancelEmailMut).toHaveBeenCalled();
  });
});

describe("ProfilePage — compte de démonstration (SEC-28)", () => {
  beforeEach(() => {
    isDemo = true;
  });

  it("annonce que le compte démo ne peut pas être modifié et désactive tous les gestes de profil", () => {
    render(<ProfilePage />);

    expect(screen.getByText(/Ce compte de démonstration ne peut pas être modifié/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /^Enregistrer$/ })).toBeDisabled();
    expect(screen.getByRole("button", { name: /Envoyer un lien de confirmation/ })).toBeDisabled();
    expect(screen.getByRole("button", { name: /Changer le mot de passe/ })).toBeDisabled();
    expect(screen.getByRole("button", { name: /Supprimer définitivement mon compte/ })).toBeDisabled();
    expect(screen.getByLabelText("Prénom")).toBeDisabled();
    expect(screen.getByLabelText("E-mail")).toBeDisabled();
  });
});

/**
 * P4-107 (3ᵉ tranche) — même cadre que la fiche Club. Le Profil était le plus étroit des
 * trois (`max-w-lg`, 512 px), soit 27 % de l'écran de référence 1920×1080.
 */
describe("largeur de la fiche Profil", () => {
  it("est cadrée par FichePage, sans largeur concurrente", () => {
    const { container } = render(<ProfilePage />);
    const root = container.firstChild as HTMLElement;
    const widths = root.className.split(/\s+/).filter((token) => token.startsWith("max-w-"));

    expect(widths).toEqual(["max-w-fiche"]);
    expect(root.className).toContain("mx-auto");
  });
});

