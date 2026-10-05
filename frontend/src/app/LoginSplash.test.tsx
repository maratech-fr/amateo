import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { createMemoryRouter, RouterProvider } from "react-router";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { type LoginSplashPhase, useLoginSplashStore } from "@/shared/stores/loginSplashStore";
import { renderWithProviders } from "@/test/utils";

import { LoginSplash } from "./LoginSplash";
import { isConnectionReady } from "./loginReady";

// BrandSplash est mocké : on ne teste PAS ici l'animation (ça, ce sont ses fns pures), mais le
// CÂBLAGE de l'orchestrateur — quelles transitions il déclenche selon `ready`, et son overlay.
vi.mock("@/shared/components/ui/brand-splash", () => ({
  BrandSplash: (props: {
    phase: string;
    ready: boolean;
    onIntroComplete: () => void;
    onBreathingSettled: () => void;
    onOutroComplete: () => void;
    onCancelComplete: () => void;
  }) => (
    <div data-testid="splash-stub" data-phase={props.phase} data-ready={String(props.ready)}>
      <button type="button" onClick={props.onIntroComplete}>
        introComplete
      </button>
      <button type="button" onClick={props.onBreathingSettled}>
        breathingSettled
      </button>
      <button type="button" onClick={props.onOutroComplete}>
        outroComplete
      </button>
      <button type="button" onClick={props.onCancelComplete}>
        cancelComplete
      </button>
    </div>
  ),
}));

// `me` contrôlé pour piloter le « prêt » et l'échec de session.
const meState = { isSuccess: false, isError: false };
vi.mock("@/shared/session/queries", () => ({
  useMe: () => meState,
}));

const store = useLoginSplashStore;
const phase = () => store.getState().phase;
// Les mutations du store hors événement React doivent passer par `act` pour que l'orchestrateur
// (abonné via useSyncExternalStore) se re-rende avant l'assertion.
const start = () => act(() => store.getState().start());
const setPhase = (phase: LoginSplashPhase) => act(() => store.setState({ phase }));

beforeEach(() => {
  store.setState({ phase: "idle" });
  meState.isSuccess = false;
  meState.isError = false;
});

function mount(route: string) {
  return renderWithProviders(
    <LoginSplash>
      <div data-testid="content" />
    </LoginSplash>,
    { route },
  );
}

describe("isConnectionReady", () => {
  it("prêt = me OK + navigation posée + hors /login (une membership /waiting compte comme prête)", () => {
    expect(isConnectionReady({ pathname: "/", navIdle: true, meReady: true })).toBe(true);
    expect(isConnectionReady({ pathname: "/waiting", navIdle: true, meReady: true })).toBe(true);
    expect(isConnectionReady({ pathname: "/login", navIdle: true, meReady: true })).toBe(false);
    expect(isConnectionReady({ pathname: "/", navIdle: false, meReady: true })).toBe(false);
    expect(isConnectionReady({ pathname: "/", navIdle: true, meReady: false })).toBe(false);
  });
});

describe("LoginSplash — câblage", () => {
  it("au repos : aucun overlay, le contenu n'est pas inert", () => {
    mount("/login");
    expect(screen.queryByTestId("splash-stub")).toBeNull();
    expect(screen.getByTestId("content").parentElement).not.toHaveAttribute("inert");
  });

  it("intro : overlay monté et contenu rendu inert", () => {
    mount("/login");
    start();
    expect(screen.getByTestId("splash-stub")).toHaveAttribute("data-phase", "intro");
    expect(screen.getByTestId("content").parentElement).toHaveAttribute("inert");
  });

  it("prêt PENDANT l'intro ⇒ fin d'intro enchaîne directement l'outro", () => {
    meState.isSuccess = true; // + route "/" ⇒ prêt
    mount("/");
    start();
    expect(screen.getByTestId("splash-stub")).toHaveAttribute("data-ready", "true");
    fireEvent.click(screen.getByText("introComplete"));
    expect(phase()).toBe("outro");
  });

  it("PAS prêt à la fin de l'intro ⇒ respiration, puis outro une fois posée", () => {
    mount("/login"); // /login ⇒ jamais prêt
    start();
    fireEvent.click(screen.getByText("introComplete"));
    expect(phase()).toBe("breathing");
    // La respiration signale son arrêt (cycle revenu à 1) ⇒ outro.
    fireEvent.click(screen.getByText("breathingSettled"));
    expect(phase()).toBe("outro");
  });

  it("fin de l'outro ⇒ retour au repos, overlay démonté", () => {
    mount("/");
    setPhase("outro");
    fireEvent.click(screen.getByText("outroComplete"));
    expect(phase()).toBe("idle");
    expect(screen.queryByTestId("splash-stub")).toBeNull();
  });

  it("annulation : contenu réinteractif (non inert), fin de fondu ⇒ repos", () => {
    mount("/login");
    setPhase("cancelling");
    expect(screen.getByTestId("content").parentElement).not.toHaveAttribute("inert");
    fireEvent.click(screen.getByText("cancelComplete"));
    expect(phase()).toBe("idle");
  });
});

/**
 * ANTI-BLOCAGE (revue sécu) : le splash ne doit JAMAIS laisser l'écran `inert` à vie si « prêt »
 * n'arrive pas. Trois sorties douces (→ `cancelling`).
 */
describe("LoginSplash — filets anti-blocage", () => {
  it("(1) la query me échoue après le démarrage ⇒ effacement doux", () => {
    meState.isError = true;
    mount("/"); // login abouti (navigué) mais me en erreur : jamais « prêt »
    start();
    expect(phase()).toBe("cancelling");
  });

  it("(2) retour à /login après l'avoir quitté ⇒ effacement doux", () => {
    // Routeur NAVIGABLE : on démarre sur "/" (login abouti), puis on rebondit vers /login.
    const router = createMemoryRouter(
      [{ path: "*", element: <LoginSplash><div data-testid="content" /></LoginSplash> }],
      { initialEntries: ["/"] },
    );
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    );
    act(() => store.getState().start()); // intro sur "/" ⇒ leftEntry mémorise qu'on a quitté /login
    expect(phase()).toBe("intro");
    act(() => void router.navigate("/login")); // rebond
    expect(phase()).toBe("cancelling");
  });

  it("(3) filet 30 s : « prêt » n'arrive pas ⇒ effacement doux (horloge factice)", () => {
    vi.useFakeTimers();
    try {
      mount("/login"); // jamais prêt
      start();
      expect(phase()).toBe("intro");
      act(() => vi.advanceTimersByTime(30_000));
      expect(phase()).toBe("cancelling");
    } finally {
      vi.useRealTimers();
    }
  });
});

afterEach(() => {
  vi.useRealTimers();
});
