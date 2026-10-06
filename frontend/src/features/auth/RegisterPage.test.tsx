import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

const h = {
  register: vi.fn(),
  // P2-4 — le raccourci démo (mocké) + la navigation, pour tester le chaînage
  // 2xx / le fallback silencieux 422 / la bannière 409 après le 202 du register.
  demoRegister: vi.fn(),
  navigate: vi.fn(),
  // P5-3b/P2-4 — la config publique du register (mockée) : `demoShortcut` pilote
  // la tentative du raccourci démo ; null = Turnstile inactif + pas de raccourci.
  config: null as { turnstileSiteKey: string | null; demoShortcut?: boolean; demoEmail?: string | null } | null,
};

vi.mock("./queries", () => ({
  useRegister: () => ({ mutateAsync: h.register, isPending: false }),
  useRegisterConfig: () => ({ data: h.config }),
  useDevDemoRegister: () => ({ mutateAsync: h.demoRegister, isPending: false }),
}));
vi.mock("react-router", async (importOriginal) => ({
  ...(await importOriginal<typeof import("react-router")>()),
  useNavigate: () => h.navigate,
}));

import { RegisterPage } from "./RegisterPage";

/** Traverse l'étape sport (basket présélectionné) pour atteindre le formulaire. */
async function gotoDetails(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole("button", { name: /continuer/i }));
}

/** Remplit le formulaire de l'étape détails avec un jeu valide + coche le consentement. */
async function fillValidForm(user: ReturnType<typeof userEvent.setup>, email = "new@club.fr") {
  await user.type(screen.getByLabelText("Prénom"), "Mara");
  await user.type(screen.getByLabelText("Nom"), "Mb");
  await user.type(screen.getByLabelText("Email"), email);
  await user.type(screen.getByLabelText("Mot de passe"), "Sup3rStrongPwd!");
  await user.type(screen.getByLabelText("Confirmer le mot de passe"), "Sup3rStrongPwd!");
  await user.type(screen.getByLabelText(/code club ffbb/i), "BCCL0123");
  await user.type(screen.getByLabelText(/nom du club/i), "Basket Club");
  await user.click(screen.getByRole("checkbox", { name: /j'accepte/i }));
}

interface TurnstileStub {
  turnstile?: {
    render: (el: HTMLElement, opts: { callback: (t: string) => void }) => string;
    reset: (id?: string) => void;
    remove: (id?: string) => void;
  };
}

afterEach(() => {
  h.register.mockReset();
  h.demoRegister.mockReset();
  h.navigate.mockReset();
  h.config = null;
  delete (window as unknown as TurnstileStub).turnstile;
  document.querySelectorAll('script[src*="challenges.cloudflare.com"]').forEach((s) => s.remove());
});

describe("RegisterPage", () => {
  it("lands on the sport step first, basketball preselected", () => {
    renderWithProviders(<RegisterPage />);
    expect(screen.getByRole("button", { name: /basketball/i })).toHaveAttribute("aria-pressed", "true");
    // Les autres sports sont annoncés mais désactivés (aucun multi-sport).
    expect(screen.getByRole("button", { name: /handball/i })).toBeDisabled();
    expect(screen.getByRole("button", { name: /continuer/i })).toBeInTheDocument();
  });

  it("renders the club registration fields (incl. ARA) after the sport step", async () => {
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);
    await gotoDetails(user);
    expect(screen.getByLabelText("Prénom")).toBeInTheDocument();
    expect(screen.getByLabelText("Nom")).toBeInTheDocument();
    expect(screen.getByLabelText("Email")).toBeInTheDocument();
    expect(screen.getByLabelText("Mot de passe")).toBeInTheDocument();
    expect(screen.getByLabelText("Confirmer le mot de passe")).toBeInTheDocument();
    expect(screen.getByLabelText(/code club ffbb/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /créer le compte/i })).toBeInTheDocument();
  });

  it("validates the email on blur", async () => {
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);
    await gotoDetails(user);
    await user.type(screen.getByLabelText("Email"), "pas-un-email");
    await user.tab();
    expect(screen.getByText(/email invalide/i)).toBeInTheDocument();
  });

  it("blocks submit when the two passwords differ", async () => {
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);
    await gotoDetails(user);
    await user.type(screen.getByLabelText("Prénom"), "Mara");
    await user.type(screen.getByLabelText("Nom"), "Mb");
    await user.type(screen.getByLabelText("Email"), "new@club.fr");
    await user.type(screen.getByLabelText("Mot de passe"), "Sup3rStrongPwd!");
    await user.type(screen.getByLabelText("Confirmer le mot de passe"), "Sup3rStrongPwd?");
    await user.type(screen.getByLabelText(/code club ffbb/i), "BCCL0123");
    await user.click(screen.getByRole("checkbox", { name: /j'accepte/i }));
    await user.click(screen.getByRole("button", { name: /créer le compte/i }));
    // Non-correspondance signalée sous le champ (NewPasswordFields) ; submit bloqué.
    expect(screen.getByText(/ne sont pas identiques/i)).toBeInTheDocument();
    expect(h.register).not.toHaveBeenCalled();
  });

  // A3: register never authenticates — success shows a "check your email" state,
  // never a redirect into the app (the JWT only comes from the verification link).
  it("shows the check-your-email confirmation after a successful submit", async () => {
    h.register.mockResolvedValueOnce({ status: "verification_pending" });
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);
    await gotoDetails(user);

    await user.type(screen.getByLabelText("Prénom"), "Mara");
    await user.type(screen.getByLabelText("Nom"), "Mb");
    await user.type(screen.getByLabelText("Email"), "new@club.fr");
    await user.type(screen.getByLabelText("Mot de passe"), "Sup3rStrongPwd!");
    await user.type(screen.getByLabelText("Confirmer le mot de passe"), "Sup3rStrongPwd!");
    await user.type(screen.getByLabelText(/code club ffbb/i), "BCCL0123");
    await user.type(screen.getByLabelText(/nom du club/i), "Basket Club");
    // RGPD : soumettre sans cocher affiche une erreur claire, puis cocher débloque.
    await user.click(screen.getByRole("button", { name: /créer le compte/i }));
    expect(screen.getByText(/accepter les conditions/i)).toBeInTheDocument();
    await user.click(screen.getByRole("checkbox", { name: /j'accepte/i }));
    await user.click(screen.getByRole("button", { name: /créer le compte/i }));

    await waitFor(() => expect(screen.getByText(/email de confirmation/i)).toBeInTheDocument());
    expect(screen.getByText("new@club.fr")).toBeInTheDocument();
    // Le sport n'est pas envoyé (basket posé côté serveur) — payload sans `sport`/`confirm`.
    expect(h.register).toHaveBeenCalledWith(expect.not.objectContaining({ sport: expect.anything(), confirm: expect.anything() }));
  });

  // P5-3b — Turnstile inactif (config sans sitekey) : l'écran est STRICTEMENT
  // l'actuel — aucun script tiers chargé, aucun turnstileToken dans le payload.
  it("without a sitekey: renders no Turnstile widget/script and sends no token", async () => {
    h.config = { turnstileSiteKey: null };
    h.register.mockResolvedValueOnce({ status: "verification_pending" });
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);
    await gotoDetails(user);
    await fillValidForm(user);
    await user.click(screen.getByRole("button", { name: /créer le compte/i }));

    await waitFor(() => expect(h.register).toHaveBeenCalled());
    expect(h.register).toHaveBeenCalledWith(expect.not.objectContaining({ turnstileToken: expect.anything() }));
    expect(document.querySelector('script[src*="challenges.cloudflare.com"]')).toBeNull();
    expect(screen.queryByText(/vérification anti-robot/i)).not.toBeInTheDocument();
  });

  // P5-3b — Turnstile actif (config avec sitekey) : le widget est rendu et le
  // token qu'il émet est threadé dans le payload de register.
  it("with a sitekey: renders the Turnstile widget and threads the token into the payload", async () => {
    h.config = { turnstileSiteKey: "0xSITEKEY" };
    (window as unknown as TurnstileStub).turnstile = {
      render: (_el, opts) => {
        opts.callback("tok-xyz");
        return "wid-1";
      },
      reset: vi.fn(),
      remove: vi.fn(),
    };
    h.register.mockResolvedValueOnce({ status: "verification_pending" });
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);
    await gotoDetails(user);

    expect(screen.getByText(/vérification anti-robot/i)).toBeInTheDocument();
    await fillValidForm(user);
    await user.click(screen.getByRole("button", { name: /créer le compte/i }));

    await waitFor(() => expect(h.register).toHaveBeenCalledWith(expect.objectContaining({ turnstileToken: "tok-xyz" })));
  });

  // P2-4 — raccourci démo (config demoShortcut + adresse démo SAISIE) : après le 202, le
  // front tente la route dev. 2xx → PLUS de connexion directe : écran de démonstration
  // (« ce clic fait les deux »), puis « Votre club est prêt » → « Se connecter » mène à
  // /login. Jamais l'écran « e-mail », jamais navigate("/") vers l'app.
  it("demo shortcut: on 2xx (demo address entered), shows the demo screen then routes to /login", async () => {
    h.config = { turnstileSiteKey: null, demoShortcut: true, demoEmail: "demo@amateo.fr" };
    h.register.mockResolvedValueOnce({ status: "verification_pending" });
    h.demoRegister.mockResolvedValueOnce({ membershipStatus: "active", clubId: "club-1" });
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);
    await gotoDetails(user);
    await fillValidForm(user, "demo@amateo.fr");
    await user.click(screen.getByRole("button", { name: /créer le compte/i }));

    await waitFor(() => expect(h.demoRegister).toHaveBeenCalledWith(
      expect.objectContaining({ email: "demo@amateo.fr", ara: "BCCL0123", clubName: "Basket Club" }),
    ));
    // Écran intermédiaire « Démonstration » (texte EXACT du fondateur), pas l'app ni l'écran e-mail.
    await waitFor(() => expect(screen.getByRole("heading", { name: /démonstration/i })).toBeInTheDocument());
    expect(screen.getByText(/ce clic fait les deux/i)).toBeInTheDocument();
    expect(screen.queryByText(/email de confirmation/i)).not.toBeInTheDocument();
    expect(h.navigate).not.toHaveBeenCalled();
    // Continuer → « Votre club est prêt. Connectez-vous normalement. »
    await user.click(screen.getByRole("button", { name: /continuer/i }));
    expect(screen.getByRole("heading", { name: /votre club est prêt/i })).toBeInTheDocument();
    expect(screen.getByText(/connectez-vous normalement/i)).toBeInTheDocument();
    // Se connecter → /login (jamais navigate("/") vers l'app).
    await user.click(screen.getByRole("button", { name: /se connecter/i }));
    expect(h.navigate).toHaveBeenCalledWith("/login");
    expect(h.navigate).not.toHaveBeenCalledWith("/", { replace: true });
  });

  // P2-4 (revue sécu) — le raccourci n'est tenté QUE sur l'adresse démo : un vrai
  // prospect (autre adresse) ne voit JAMAIS son mot de passe partir vers la route dev.
  it("demo shortcut: a non-demo address never calls the dev route (check-your-email instead)", async () => {
    h.config = { turnstileSiteKey: null, demoShortcut: true, demoEmail: "demo@amateo.fr" };
    h.register.mockResolvedValueOnce({ status: "verification_pending" });
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);
    await gotoDetails(user);
    await fillValidForm(user, "prospect@club.fr");
    await user.click(screen.getByRole("button", { name: /créer le compte/i }));

    await waitFor(() => expect(screen.getByText(/email de confirmation/i)).toBeInTheDocument());
    expect(h.demoRegister).not.toHaveBeenCalled();
    expect(h.navigate).not.toHaveBeenCalled();
  });

  // P2-4 — le raccourci est INOFFENSIF : un 422 retombe SILENCIEUSEMENT sur l'écran
  // « vérifiez votre e-mail », sans bannière ni navigation.
  it("demo shortcut: on 422, falls back silently to the check-your-email screen", async () => {
    h.config = { turnstileSiteKey: null, demoShortcut: true, demoEmail: "demo@amateo.fr" };
    h.register.mockResolvedValueOnce({ status: "verification_pending" });
    h.demoRegister.mockRejectedValueOnce({ response: { status: 422 } });
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);
    await gotoDetails(user);
    await fillValidForm(user, "demo@amateo.fr");
    await user.click(screen.getByRole("button", { name: /créer le compte/i }));

    await waitFor(() => expect(screen.getByText(/email de confirmation/i)).toBeInTheDocument());
    expect(h.navigate).not.toHaveBeenCalled();
  });

  // P2-4 — un 409 « code FFBB déjà pris par un autre club » (error: ara_taken) → bannière
  // explicite, on reste sur le formulaire.
  it("demo shortcut: on 409 ara_taken, shows the 'code already used' banner", async () => {
    h.config = { turnstileSiteKey: null, demoShortcut: true, demoEmail: "demo@amateo.fr" };
    h.register.mockResolvedValueOnce({ status: "verification_pending" });
    h.demoRegister.mockRejectedValueOnce({ response: { status: 409 }, data: { error: "ara_taken" } });
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);
    await gotoDetails(user);
    await fillValidForm(user, "demo@amateo.fr");
    await user.click(screen.getByRole("button", { name: /créer le compte/i }));

    await waitFor(() => expect(screen.getByText(/déjà utilisé par un autre club/i)).toBeInTheDocument());
    expect(h.navigate).not.toHaveBeenCalled();
    expect(screen.queryByText(/email de confirmation/i)).not.toBeInTheDocument();
  });

  // P2-4 (revue sécu) — un 409 de teardown (error: teardown_refused) a une CAUSE
  // distincte : la bannière doit le dire (pas « ce code appartient à un autre club »).
  it("demo shortcut: on 409 teardown_refused, shows the distinct 'cannot replace previous demo' banner", async () => {
    h.config = { turnstileSiteKey: null, demoShortcut: true, demoEmail: "demo@amateo.fr" };
    h.register.mockResolvedValueOnce({ status: "verification_pending" });
    h.demoRegister.mockRejectedValueOnce({ response: { status: 409 }, data: { error: "teardown_refused" } });
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);
    await gotoDetails(user);
    await fillValidForm(user, "demo@amateo.fr");
    await user.click(screen.getByRole("button", { name: /créer le compte/i }));

    await waitFor(() => expect(screen.getByText(/remplacer votre club de démonstration/i)).toBeInTheDocument());
    expect(screen.queryByText(/déjà utilisé par un autre club/i)).not.toBeInTheDocument();
    expect(h.navigate).not.toHaveBeenCalled();
  });

  // P2-4 (revue sécu, conséquence de C-1) — le mot de passe n'est PLUS écrasé mais
  // VÉRIFIÉ : une faute de frappe du fondateur en démo → 401. Ça DOIT afficher une
  // bannière claire (« mot de passe incorrect ») et rester sur le formulaire — pas
  // ressembler à une éjection de session. (L'exemption de la redirection globale ky
  // est gardée séparément dans shared/api/client.test.ts.)
  it("demo shortcut: on 401 (typo'd password), shows an explicit banner and stays on the form", async () => {
    h.config = { turnstileSiteKey: null, demoShortcut: true, demoEmail: "demo@amateo.fr" };
    h.register.mockResolvedValueOnce({ status: "verification_pending" });
    h.demoRegister.mockRejectedValueOnce({ response: { status: 401 }, data: { error: "invalid_credentials" } });
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);
    await gotoDetails(user);
    await fillValidForm(user, "demo@amateo.fr");
    await user.click(screen.getByRole("button", { name: /créer le compte/i }));

    await waitFor(() => expect(screen.getByText(/mot de passe incorrect/i)).toBeInTheDocument());
    expect(h.navigate).not.toHaveBeenCalled();
    expect(screen.queryByText(/email de confirmation/i)).not.toBeInTheDocument();
  });
});
