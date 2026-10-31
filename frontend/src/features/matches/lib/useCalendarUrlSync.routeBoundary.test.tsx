import { act, render, screen, waitFor } from "@testing-library/react";
import { useSyncExternalStore } from "react";
import { createMemoryRouter, RouterProvider } from "react-router";
import { beforeEach, describe, expect, it } from "vitest";

import type { Coach, Competition, Fixture, Team, Venue } from "../api";
import { useMatchesStore } from "../store";
import { useCalendarUrlSync } from "./useCalendarUrlSync";

/**
 * PROMESSE : un écrivain d'URL du Calendrier n'écrit jamais sur la route d'à côté. Après « Voir la
 * semaine » puis un retour immédiat, l'entrée `/matchs/conflits?…&ouvert=…` garde son adresse, qui est
 * un lien partageable.
 *
 * Mécanisme, lu dans react-router 8.5.0 : `setSearchParams` appelle `navigate("?…")` (`dom/lib.js:750`),
 * un chemin sans `pathname` se résout depuis `state.location` du routeur et non depuis la route de
 * l'appelant (`router.js:424`, `utils.js:610`), et `RouterProvider` n'applique le nouvel état qu'en
 * `startTransition` (`components.js:153`). Entre les deux, le Calendrier reste monté alors que le
 * routeur est déjà sur Conflits : si ses lectures arrivent là, sa re-synchro remplace l'entrée Conflits.
 *
 * Trace en CI : run 41130756611, `matches.spec.ts:410`, URL `/matchs/conflits?type=…&exterieurs=1&
 * semaine=…&filtre=…&conflit=…&pivot=journee`, sans `ouvert` et sans le `match=` que seule cette
 * re-synchro retire.
 *
 * Le routeur et le hook sont réels. Seul l'instant d'arrivée des lectures est piloté, pour poser la
 * fenêtre sans minuteur.
 */

const CONFLICTS_URL = "/matchs/conflits?pivot=journee&ouvert=2027-03-13";
const VOIR_LA_SEMAINE_URL = "/matchs?exterieurs=1&semaine=2027-03-13&match=F1";
const NO_COMPETITIONS = new Map<string, Competition>();
const FIXTURE = { id: "F1", matchDate: "2027-03-13", competitionId: null } as unknown as Fixture;

type Reads = { teams?: Team[]; coaches?: Coach[]; venues?: Venue[]; fixtures?: Fixture[] };

function createReads() {
  let current: Reads = {};
  const listeners = new Set<() => void>();
  return {
    subscribe: (listener: () => void) => {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    get: () => current,
    arrive: () => {
      current = { teams: [], coaches: [], venues: [], fixtures: [FIXTURE] };
      listeners.forEach((listener) => listener());
    },
  };
}

beforeEach(() => {
  useMatchesStore.setState(useMatchesStore.getInitialState(), true);
});

describe("useCalendarUrlSync — frontière de route", () => {
  it("des lectures arrivées pendant le retour n'écrivent rien sur l'entrée de Conflits", async () => {
    const reads = createReads();

    function Calendar() {
      const { teams, coaches, venues, fixtures } = useSyncExternalStore(reads.subscribe, reads.get);
      useCalendarUrlSync(teams, coaches, venues, fixtures, NO_COMPETITIONS, () => {});
      return <h1>Calendrier</h1>;
    }

    const router = createMemoryRouter(
      [
        { path: "/matchs", element: <Calendar /> },
        { path: "/matchs/conflits", element: <h1>Conflits</h1> },
      ],
      { initialEntries: [CONFLICTS_URL, VOIR_LA_SEMAINE_URL], initialIndex: 1 },
    );
    render(<RouterProvider router={router} />);
    await screen.findByRole("heading", { name: "Calendrier" });

    // Le routeur vient de passer sur Conflits, React n'a pas encore démonté le Calendrier.
    const unsubscribe = router.subscribe((state) => {
      if ("/matchs/conflits" === state.location.pathname) {
        unsubscribe();
        reads.arrive();
      }
    });
    await act(async () => {
      await router.navigate(-1);
    });
    await screen.findByRole("heading", { name: "Conflits" });

    // Témoin : le seed a tourné dans la fenêtre, sinon le test ne prouverait rien.
    await waitFor(() => expect(useMatchesStore.getState().selectedFixtureId).toBe("F1"));
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });

    expect(`${router.state.location.pathname}${router.state.location.search}`).toBe(CONFLICTS_URL);
  });
});
