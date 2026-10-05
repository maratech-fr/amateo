import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

const createMut = { mutateAsync: vi.fn(), isPending: false };
const resendMut = { mutate: vi.fn(), isPending: false };
const revokeMut = { mutate: vi.fn(), isPending: false };
const invitations: { data: { invitations: { id: string; email: string; role: string; expiresAt: string }[] } | undefined; isError: boolean; refetch: ReturnType<typeof vi.fn> } = {
  data: { invitations: [] },
  isError: false,
  refetch: vi.fn(),
};

// Couche queries voisine mockée (seam de mock D-31) : le composant PROD est monté tel quel,
// seuls les hooks réseau sont doublés.
vi.mock("./queries", () => ({
  useInvitations: () => invitations,
  useCreateInvitation: () => createMut,
  useResendInvitation: () => resendMut,
  useRevokeInvitation: () => revokeMut,
}));

import { InvitationsSection } from "./InvitationsSection";

/** HTTPError porteuse d'un corps métier (comme ky l'expose via `error.data`). */
function businessError(status: number, message: string): HTTPError {
  const err = new HTTPError(new Response("{}", { status }), new Request("http://t/api/invitations"), {} as never);
  (err as { data?: unknown }).data = { error: message };
  return err;
}

describe("InvitationsSection (P4-299)", () => {
  beforeEach(() => {
    createMut.mutateAsync.mockReset().mockResolvedValue(undefined);
    resendMut.mutate.mockReset();
    revokeMut.mutate.mockReset();
    invitations.data = { invitations: [] };
    invitations.isError = false;
  });

  it("invite avec le rôle MEMBRE par défaut (moindre privilège)", async () => {
    const user = userEvent.setup();
    render(<InvitationsSection />);
    await user.type(screen.getByLabelText("Adresse e-mail"), "paul@club.fr");
    await user.click(screen.getByRole("button", { name: /Inviter/ }));
    await waitFor(() => expect(createMut.mutateAsync).toHaveBeenCalledWith({ email: "paul@club.fr", role: "member" }));
  });

  it("choisir Gestionnaire → le rôle part avec l'invitation", async () => {
    const user = userEvent.setup();
    render(<InvitationsSection />);
    await user.type(screen.getByLabelText("Adresse e-mail"), "co-president@club.fr");
    await user.selectOptions(screen.getByLabelText("Rôle"), "Gestionnaire");
    await user.click(screen.getByRole("button", { name: /Inviter/ }));
    await waitFor(() => expect(createMut.mutateAsync).toHaveBeenCalledWith({ email: "co-president@club.fr", role: "admin" }));
  });

  it("adresse invalide → message inline, aucune émission", async () => {
    const user = userEvent.setup();
    render(<InvitationsSection />);
    await user.type(screen.getByLabelText("Adresse e-mail"), "pas-une-adresse");
    await user.click(screen.getByRole("button", { name: /Inviter/ }));
    expect(screen.getByRole("alert")).toHaveTextContent(/adresse e-mail valide/i);
    expect(createMut.mutateAsync).not.toHaveBeenCalled();
  });

  it("refus serveur (« déjà membre ») → le message exact s'affiche inline, annoncé (role=alert)", async () => {
    createMut.mutateAsync.mockRejectedValueOnce(businessError(422, "Cette personne est déjà membre de votre club."));
    const user = userEvent.setup();
    render(<InvitationsSection />);
    await user.type(screen.getByLabelText("Adresse e-mail"), "deja@club.fr");
    await user.click(screen.getByRole("button", { name: /Inviter/ }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Cette personne est déjà membre de votre club.");
  });

  it("liste : adresse, rôle, expiration ; « Renvoyer » ré-émet", async () => {
    invitations.data = { invitations: [{ id: "i1", email: "paul@club.fr", role: "member", expiresAt: "2026-10-12" }] };
    const user = userEvent.setup();
    render(<InvitationsSection />);
    expect(screen.getByText("paul@club.fr")).toBeInTheDocument();
    expect(screen.getByText(/Membre · Expire le 12\/10\/2026/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /Renvoyer/ }));
    expect(resendMut.mutate).toHaveBeenCalledWith("i1");
  });

  it("« Révoquer » passe par une confirmation (N2) — pas de révocation au clic direct", async () => {
    invitations.data = { invitations: [{ id: "i1", email: "paul@club.fr", role: "member", expiresAt: "2026-10-12" }] };
    const user = userEvent.setup();
    render(<InvitationsSection />);
    await user.click(screen.getByRole("button", { name: /Révoquer/ }));
    // Rien n'est révoqué tant que la confirmation n'est pas validée.
    expect(revokeMut.mutate).not.toHaveBeenCalled();
    const dialog = screen.getByRole("dialog");
    expect(within(dialog).getByText(/Révoquer l'invitation \?/)).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: "Révoquer" }));
    expect(revokeMut.mutate).toHaveBeenCalledWith("i1");
  });

  it("état vide / échec de lecture", () => {
    const { unmount } = render(<InvitationsSection />);
    expect(screen.getByText(/Aucune invitation en cours/)).toBeInTheDocument();
    unmount();

    invitations.data = undefined;
    invitations.isError = true;
    render(<InvitationsSection />);
    expect(screen.getByRole("alert")).toHaveTextContent(/Impossible de charger les invitations/);
  });
});
