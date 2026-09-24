import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MatchesLanding } from "./MatchesLanding";
import { useMatchesStore } from "./store";

// UXS-07 — la route d'atterrissage décide entre Conflits et Calendrier. On mocke la
// page lourde (elle ne participe pas à la décision) et on pilote le compte de conflits
// par l'API, comme le fait `MatchesLayout.test.tsx` (même cache `useConflicts`).
vi.mock("./CalendarPage", () => ({ CalendarPage: () => <div>CALENDRIER</div> }));

// Le canal des conflits : `open` = nombre de conflits À TRAITER (résolution `null`),
// `fail` = la lecture échoue, `settled` = la promesse s'est posée (sert à flusher la
// requête DANS `act` sur les cas où l'écran ne change pas visiblement).
const conflictsState = vi.hoisted(() => ({ open: 0, fail: false, settled: false }));
vi.mock("./api", () => ({
  getConflicts: vi.fn(() => {
    if (conflictsState.fail) {
      return Promise.reject(new Error("boom")).catch((error: unknown) => {
        conflictsState.settled = true;
        throw error;
      });
    }
    const conflicts = Array.from({ length: conflictsState.open }, (_unused, i) => ({
      type: "VENUE_OVERLAP",
      severity: 1,
      resolution: null,
      fingerprint: `open-${i}`,
    }));
    return Promise.resolve({ clubId: "c", seasonId: "s", seasonPlanChosen: true, conflicts }).then((value) => {
      conflictsState.settled = true;
      return value;
    });
  }),
}));

beforeEach(() => {
  conflictsState.open = 0;
  conflictsState.fail = false;
  conflictsState.settled = false;
  // Session vierge par défaut : la décision n'est pas encore prise.
  useMatchesStore.setState({ landingDecided: false });
});

function renderAt(path: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return {
    queryClient,
    ...render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[path]}>
          <Routes>
            <Route path="/matchs" element={<MatchesLanding />} />
            <Route path="/matchs/conflits" element={<div>CONFLITS</div>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    ),
  };
}

describe("MatchesLanding (UXS-07 — atterrissage conditionnel)", () => {
  it("conflits à traiter → SPINNER pendant l'attente (jamais le Calendrier avant le compte) puis Conflits", async () => {
    conflictsState.open = 2;
    renderAt("/matchs");
    // On attend le compte : spinner, et surtout PAS le Calendrier tant qu'on ne sait pas.
    expect(screen.getByLabelText("Chargement")).toBeInTheDocument();
    expect(screen.queryByText("CALENDRIER")).not.toBeInTheDocument();
    expect(await screen.findByText("CONFLITS")).toBeInTheDocument();
    expect(screen.queryByText("CALENDRIER")).not.toBeInTheDocument();
  });

  it("zéro conflit ouvert → Calendrier, aucune redirection", async () => {
    conflictsState.open = 0;
    renderAt("/matchs");
    expect(await screen.findByText("CALENDRIER")).toBeInTheDocument();
    expect(screen.queryByText("CONFLITS")).not.toBeInTheDocument();
  });

  it("lecture des conflits en ÉCHEC → Calendrier (fail-open), jamais un module qui n'ouvre rien", async () => {
    conflictsState.fail = true;
    renderAt("/matchs");
    expect(await screen.findByText("CALENDRIER")).toBeInTheDocument();
    expect(screen.queryByText("CONFLITS")).not.toBeInTheDocument();
  });

  it("décision déjà prise dans la session → Calendrier IMMÉDIAT, aucun spinner (même avec des conflits)", async () => {
    useMatchesStore.setState({ landingDecided: true });
    conflictsState.open = 2;
    renderAt("/matchs");
    // Rendu synchrone : le Calendrier est là d'emblée, sans passer par le spinner.
    expect(screen.getByText("CALENDRIER")).toBeInTheDocument();
    expect(screen.queryByLabelText("Chargement")).not.toBeInTheDocument();
    // On laisse la requête (partagée) se poser DANS act : la décision figée ne renvoie jamais.
    await waitFor(() => expect(conflictsState.settled).toBe(true));
    expect(screen.queryByText("CONFLITS")).not.toBeInTheDocument();
  });

  it("URL avec paramètres → décision SAUTÉE : Calendrier, aucune redirection MALGRÉ des conflits (lien profond prioritaire)", async () => {
    conflictsState.open = 2;
    renderAt("/matchs?fbi=1");
    expect(screen.getByText("CALENDRIER")).toBeInTheDocument();
    expect(screen.queryByLabelText("Chargement")).not.toBeInTheDocument();
    await waitFor(() => expect(conflictsState.settled).toBe(true));
    expect(screen.queryByText("CONFLITS")).not.toBeInTheDocument();
  });

  it("entré SANS conflit puis un conflit APPARAÎT → on RESTE sur le Calendrier (l'ISSUE est figée, pas seulement l'entrée)", async () => {
    // Entrée sur une session vierge, zéro conflit : on atterrit sur le Calendrier.
    conflictsState.open = 0;
    const { queryClient } = renderAt("/matchs");
    expect(await screen.findByText("CALENDRIER")).toBeInTheDocument();
    expect(screen.queryByText("CONFLITS")).not.toBeInTheDocument();

    // Un conflit naît PENDANT le travail : l'utilisateur saisit une rencontre, ce qui en
    // prod invalide le préfixe `["fixtures"]` (donc `["fixtures","conflicts"]`) via
    // `invalidateFixtures` (`queries.ts`). On reproduit le geste EXACT — `invalidateQueries`
    // refetch la requête active (le `staleTime` de 10 s ne protège pas d'une invalidation),
    // le compte passe de 0 à 2, et react-query notifie ses observateurs sur un macrotask :
    // on le laisse se vider DANS `act` pour que le re-rendu (celui qui, buggé, rejouait le
    // renvoi) ait bien lieu.
    conflictsState.open = 2;
    await act(async () => {
      await queryClient.invalidateQueries({ queryKey: ["fixtures", "conflicts"] });
      await new Promise((resolve) => setTimeout(resolve));
    });

    // L'atterrissage était résolu sur « calendrier » : un conflit né APRÈS ne doit jamais
    // rejouer le renvoi et éjecter l'utilisateur. Il se voit dans le badge « Conflits · N »,
    // pas en le déplaçant de force. Calendrier toujours là, Conflits jamais monté.
    expect(screen.getByText("CALENDRIER")).toBeInTheDocument();
    expect(screen.queryByText("CONFLITS")).not.toBeInTheDocument();
  });
});
