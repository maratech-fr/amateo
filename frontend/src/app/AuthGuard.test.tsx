import { onlineManager } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { useAuthStore } from "@/shared/stores/authStore";

import { AuthGuard } from "./AuthGuard";

const { meState, info, refetch } = vi.hoisted(() => ({
  meState: { data: undefined as unknown, isError: false },
  info: vi.fn(),
  refetch: vi.fn(),
}));

vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: meState.data, isLoading: false, isError: meState.isError, refetch }) }));
vi.mock("@/shared/stores/toastStore", () => ({ toast: { info } }));

// Onboarding lock is keyed on the baseline (main plan) existing, not the legacy
// club.onboardingCompleted flag: no baseline → still onboarding → locked.
// Onboarding is over once the club has generated at least once (inv. 8/16) —
// keyed on the plan's finished versions, never on its pointer.
const activeMember = (hasGenerated: boolean) => ({
  membershipStatus: "active",
  seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: hasGenerated ? "b1" : null, hasFinishedVersion: hasGenerated },
  club: { onboardingCompleted: hasGenerated },
});

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route element={<AuthGuard />}>
          <Route path="/" element={<div>COCKPIT</div>} />
          <Route path="/profile" element={<div>PROFILE</div>} />
          <Route path="/club" element={<div>CLUB</div>} />
          <Route path="/matchs" element={<div>MATCHS</div>} />
          <Route path="/confidentialite" element={<div>PRIVACY</div>} />
          <Route path="/boite-aux-lettres" element={<div>MAILBOX</div>} />
        </Route>
        <Route path="/wizard" element={<div>WIZARD</div>} />
        <Route path="/waiting" element={<div>WAITING</div>} />
        <Route path="/login" element={<div>LOGIN</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

describe("AuthGuard — onboarding lock", () => {
  beforeEach(() => {
    info.mockClear();
    meState.isError = false;
    useAuthStore.setState({ isAuthenticated: true });
  });

  it("keeps the burger routes reachable while onboarding (profile + club)", () => {
    meState.data = activeMember(false);
    renderAt("/profile");
    expect(screen.getByText("PROFILE")).toBeInTheDocument();
  });

  it("keeps /club reachable while onboarding", () => {
    meState.data = activeMember(false);
    renderAt("/club");
    expect(screen.getByText("CLUB")).toBeInTheDocument();
  });

  // Le menu compte affiche « Confidentialité » (AppLayout) : l'omettre de
  // ONBOARDING_ALLOWED en faisait un lien visible qui renvoyait au wizard — et c'est
  // justement la page qu'on veut pouvoir lire AVANT de finir son inscription.
  it("keeps /confidentialite reachable while onboarding", () => {
    meState.data = activeMember(false);
    renderAt("/confidentialite");
    expect(screen.getByText("PRIVACY")).toBeInTheDocument();
  });

  // La boîte aux lettres est lisible à TOUT moment (entrée de nav visible dès que l'horloge
  // est active) : l'omettre de ONBOARDING_ALLOWED renvoyait un club jamais généré vers /wizard.
  it("keeps /boite-aux-lettres reachable while onboarding", () => {
    meState.data = activeMember(false);
    renderAt("/boite-aux-lettres");
    expect(screen.getByText("MAILBOX")).toBeInTheDocument();
  });

  it("does NOT fire the cockpit hint for a pending (not-yet-active) member", () => {
    meState.data = { membershipStatus: "pending", seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: null, hasFinishedVersion: false }, club: { onboardingCompleted: false } };
    renderAt("/");
    expect(screen.getByText("WAITING")).toBeInTheDocument();
    expect(info).not.toHaveBeenCalled();
  });

  it("redirects the cockpit home to the wizard with an ephemeral hint", () => {
    meState.data = activeMember(false);
    renderAt("/");
    expect(screen.getByText("WIZARD")).toBeInTheDocument();
    expect(info).toHaveBeenCalledWith("Lancez votre première génération d'abord.");
  });

  it("redirects other locked routes to the wizard silently (no cockpit hint)", () => {
    meState.data = activeMember(false);
    renderAt("/matchs");
    expect(screen.getByText("WIZARD")).toBeInTheDocument();
    expect(info).not.toHaveBeenCalled();
  });

  it("lets the cockpit render once onboarding is complete", () => {
    meState.data = activeMember(true);
    renderAt("/");
    expect(screen.getByText("COCKPIT")).toBeInTheDocument();
    expect(info).not.toHaveBeenCalled();
  });
});

/**
 * P5-14 (axe auth) — le VRAI défaut corrigé : `AuthGuard` envoyait TOUT `isError`
 * de /api/me vers /login, y compris un 5xx ou une panne réseau — « le serveur
 * tombe et l'app dit reconnectez-vous ». Or le vrai 401 est déjà éjecté par
 * client.ts (qui vide l'auth). Ici : réseau coupé → écran hors-ligne ; sinon →
 * écran 500 avec « Réessayer » = refetch. JAMAIS /login sur un échec non-401.
 */
describe("AuthGuard — échec de /api/me (ne jamais mentir « reconnectez-vous »)", () => {
  beforeEach(() => {
    info.mockClear();
    refetch.mockClear();
    meState.data = undefined;
    meState.isError = false;
    useAuthStore.setState({ isAuthenticated: true });
    onlineManager.setOnline(true); // état réseau = source de vérité unique (useOnline) ; on mute la PROD
  });
  afterEach(() => {
    onlineManager.setOnline(true); // singleton global : ne pas laisser un test hors ligne polluer un autre
  });

  it("sans session (store vidé par un 401, cf. client.ts) → /login, comportement INCHANGÉ", () => {
    useAuthStore.setState({ isAuthenticated: false });
    renderAt("/");
    expect(screen.getByText("LOGIN")).toBeInTheDocument();
  });

  it("un 5xx (serveur tombé) → écran 500, JAMAIS /login", () => {
    onlineManager.setOnline(true); // en ligne : un échec non-réseau
    meState.isError = true;
    renderAt("/");
    expect(screen.getByRole("heading", { name: /arrêt de jeu imprévu/i })).toBeInTheDocument();
    expect(screen.queryByText("LOGIN")).toBeNull();
  });

  it("réseau coupé → écran hors-ligne, JAMAIS /login", () => {
    // On mute la PROD (le vrai onlineManager), pas `navigator.onLine` : depuis la convergence P5-14,
    // c'est `useOnline()` (→ onlineManager) qui tranche.
    onlineManager.setOnline(false);
    meState.isError = true;
    renderAt("/");
    expect(screen.getByRole("heading", { name: /pas de réseau/i })).toBeInTheDocument();
    expect(screen.queryByText("LOGIN")).toBeNull();
  });

  it("« Réessayer » relance le fetch de /api/me (refetch), sans recharger la page", async () => {
    onlineManager.setOnline(true);
    meState.isError = true;
    renderAt("/");
    await userEvent.click(screen.getByRole("button", { name: /réessayer/i }));
    expect(refetch).toHaveBeenCalled();
  });
});
