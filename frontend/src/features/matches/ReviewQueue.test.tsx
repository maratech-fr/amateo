import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { createMemoryRouter, RouterProvider, useLocation } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Fixture, Team, Venue } from "./api";
import { ReviewQueue } from "./ReviewQueue";
import { useMatchesStore } from "./store";
import { MATCHES_TEXTS } from "./texts";

// La file n'exerce ici que le geste « Replacer » (A8) : on isole les mutations.
vi.mock("./queries", () => ({
  useReviewFixtures: () => ({ mutate: vi.fn(), isPending: false }),
  useResolveFixtureDeviation: () => ({ mutate: vi.fn(), isPending: false }),
  useAttachVenueLabel: () => ({ mutate: vi.fn(), isPending: false }),
  useCompetitions: () => ({ data: [] }),
}));
// Chantier Membre (PR 2) : `useCanManage` piloté par cas (gestionnaire par défaut) ; le reste de la session reste réel.
const session = vi.hoisted(() => ({ canManage: true }));
vi.mock("@/shared/session/queries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/shared/session/queries")>()),
  useCanManage: () => session.canManage,
}));

const team = { id: "team-1", name: "SM1", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 } as unknown as Team;
const venue = { id: "venue-1", name: "Alpha", color: null, externalLabels: [] } as unknown as Venue;
// Domicile AMICAL futur (competitionId null ⇒ amical, hors défauts) : « Replacer » disponible.
const homeAmical = {
  id: "fx-1",
  teamId: "team-1",
  seasonId: "s",
  competitionId: null,
  matchDate: "2030-01-05",
  homeAway: "HOME",
  opponentLabel: "Adv",
  status: "UNPLACED",
  venueId: null,
  kickoffTime: null,
  externalRef: null,
  fbiVenueLabel: null,
  placementSource: "MANUAL",
  unplacedReason: null,
  neutralGround: false,
  reviewState: "NEW",
  reviewedAt: null,
  pendingDeviations: [],
  ffbbRencontreId: null,
  opponentOrganismeCode: null,
  opponentTeamKey: null,
  suggestedVenueId: null,
} as unknown as Fixture;

function CalendarProbe() {
  const location = useLocation();
  return (
    <div>
      <span>PLACER</span>
      <span data-testid="calendar-search">{location.search}</span>
    </div>
  );
}

function renderQueue() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const router = createMemoryRouter(
    [
      { path: "/matchs/importer", element: <ReviewQueue fixtures={[homeAmical]} teams={[team]} venues={[venue]} /> },
      { path: "/matchs", element: <CalendarProbe /> },
    ],
    { initialEntries: ["/matchs/importer"] },
  );
  return render(
    <QueryClientProvider client={qc}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  useMatchesStore.setState({ consultKinds: null, consultAway: false, filterMode: "equipe", filterIds: [], selectedFixtureId: null });
});

/**
 * M3 (2026-10-27) — la file d'import ne parle plus d'une « validation » (« ça fait quoi, ça mène
 * où ? »), c'est un ACQUITTEMENT : une phrase d'aide en tête, un en-tête d'équipe « N nouveautés à
 * voir · M écarts à trancher », un bouton de corps « Tout marquer comme vu ». Lot prod Matchs PR 2 :
 * l'aide et le bouton vivent dans `texts.ts` (clés `reviewHelp`, `reviewMarkAllSeen`) — le test
 * vérifie la CLÉ ; l'en-tête garde ses mots pinés (« à valider » a disparu).
 */
describe("ReviewQueue — file d'import = acquittement, pas validation (M3)", () => {
  function fixture(extra: Partial<Fixture>): Fixture {
    return {
      ...(homeAmical as Fixture),
      ...extra,
    } as Fixture;
  }

  function renderWith(fixtures: Fixture[]) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const router = createMemoryRouter(
      [{ path: "/matchs/importer", element: <ReviewQueue fixtures={fixtures} teams={[team]} venues={[venue]} /> }],
      { initialEntries: ["/matchs/importer"] },
    );
    return render(
      <QueryClientProvider client={qc}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    );
  }

  const newFixture = fixture({ id: "fx-new", reviewState: "NEW", matchDate: "2030-01-05" });
  const outOfSync = fixture({
    id: "fx-ecart",
    reviewState: "OUT_OF_SYNC",
    matchDate: "2030-01-12",
    pendingDeviations: [{ field: "date", appValue: "2030-01-12", sourceValue: "2030-01-19", channel: "FBI_XLSX", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: false }] as Fixture["pendingDeviations"],
  });

  it("l'en-tête d'équipe dit « nouveautés à voir » + « écarts à trancher », jamais « à valider »", () => {
    renderWith([newFixture, outOfSync]);
    const header = screen.getByRole("button", { name: /SM1/ });
    expect(header).toHaveTextContent(/nouveaut\p{L}* à voir/u);
    expect(header).toHaveTextContent(/écart\p{L}* à trancher/u);
    expect(header).not.toHaveTextContent("à valider");
  });

  it("les textes de la file vivent dans texts.ts (aide, gestes de masse, toasts)", () => {
    expect(typeof MATCHES_TEXTS.reviewHelp).toBe("string");
    expect(typeof MATCHES_TEXTS.reviewMarkAllSeen).toBe("string");
    expect(typeof MATCHES_TEXTS.reviewMarkAwaySeen).toBe("string");
    expect(typeof MATCHES_TEXTS.reviewMarkAwaySeenDisabled).toBe("string");
    expect(typeof MATCHES_TEXTS.reviewAwaySeenToast).toBe("function");
    expect(typeof MATCHES_TEXTS.reviewSkippedToast).toBe("function");
    // Contrôle visuel PR 2 — l'état vide « extérieurs masqués, encore à marquer comme vus » a sa propre clé.
    expect(typeof MATCHES_TEXTS.reviewNothingToTreatAwayHiddenDescription).toBe("string");
    expect(MATCHES_TEXTS.reviewNothingToTreatAwayHiddenDescription).not.toBe(MATCHES_TEXTS.reviewNothingToTreatDescription);
  });

  it("une phrase d'aide explique que l'import a déjà mis à jour les matchs, à marquer comme vus", () => {
    renderWith([newFixture, outOfSync]);
    expect(screen.getByText(MATCHES_TEXTS.reviewHelp)).toBeInTheDocument();
  });

  it("le bouton de corps est « Tout marquer comme vu » (clé), plus « Tout valider »", async () => {
    const user = userEvent.setup();
    renderWith([newFixture, outOfSync]);
    await user.click(screen.getByRole("button", { name: /SM1/ }));
    expect(screen.getByRole("button", { name: MATCHES_TEXTS.reviewMarkAllSeen })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Tout valider" })).not.toBeInTheDocument();
  });

  /**
   * Chantier Membre (PR 2) : la file d'import se LIT par un Membre (nouveautés, écarts) mais il
   * n'acquitte rien — aucun « Tout marquer comme vu ». Témoin : le cas précédent, même décor.
   */
  it("Membre : lit la file, sans « Tout marquer comme vu »", async () => {
    session.canManage = false;
    try {
      const user = userEvent.setup();
      renderWith([newFixture, outOfSync]);
      await user.click(screen.getByRole("button", { name: /SM1/ }));
      expect(screen.queryByRole("button", { name: MATCHES_TEXTS.reviewMarkAllSeen })).not.toBeInTheDocument();
    } finally {
      session.canManage = true;
    }
  });

  /**
   * Contrôle visuel PR 2 : l'aide « Marquez-les comme vus pour vider la liste » parle d'un geste que
   * le Membre n'a pas — elle ne lui est pas montrée, et rien à l'écran ne l'invite à « marquer ».
   * Témoin : « une phrase d'aide explique… » (gestionnaire, même décor, `reviewHelp` affichée).
   */
  it("Membre : l'aide ne parle pas du geste « marquer comme vu » qu'il ne peut pas faire", () => {
    session.canManage = false;
    try {
      renderWith([newFixture, outOfSync]);
      expect(screen.getByRole("button", { name: /SM1/ })).toBeInTheDocument();
      expect(screen.queryByText(MATCHES_TEXTS.reviewHelp)).not.toBeInTheDocument();
      expect(screen.queryByText(/marquez/i)).not.toBeInTheDocument();
    } finally {
      session.canManage = true;
    }
  });
});

/**
 * Lot prod Matchs PR 2 — les toasts des gestes de masse s'accordent sur CHAQUE nombre (singulier /
 * pluriel, « Aucune » jamais « 0 rencontre ») et NOMMENT les rencontres laissées de côté (équipe +
 * date pour le geste extérieurs, date pour le geste équipe), « aucune vue, aucune laissée » se lisant
 * comme la raison du bouton désactivé. Contrôle visuel PR 2 : le nommage est BORNÉ — au plus 3 noms,
 * puis « et N autre(s) », accordé ; jamais un mur de texte à 5 entrées et plus. Les formes exactes
 * 0 · 1 · 3 · 4 · 5 sont FIXÉES ici (la forme « 0 saut » du geste équipe = aucun toast, gardée dans
 * `ImportPage.test.tsx`). Une rencontre absente de la liste chargée (ni équipe ni date) n'est pas
 * nommée mais COMPTÉE dans « autre(s) ».
 */
describe("ReviewQueue — toasts de masse accordés et bornés à 3 noms (lot prod Matchs PR 2)", () => {
  const away = [
    { team: "U15F2", date: "sam. 5 déc." },
    { team: "SM1", date: "dim. 13 déc." },
    { team: "SF2", date: "sam. 19 déc." },
    { team: "U13M1", date: "dim. 20 déc." },
    { team: "SM2", date: "sam. 9 janv." },
  ];
  const dates = ["sam. 5 déc.", "sam. 12 déc.", "sam. 19 déc.", "sam. 9 janv.", "sam. 16 janv."];
  const unknown = { team: "", date: "" };

  it("extérieurs, 0 laissée de côté : seul le compte des vues, accordé", () => {
    expect(MATCHES_TEXTS.reviewAwaySeenToast(0, [])).toBe(MATCHES_TEXTS.reviewMarkAwaySeenDisabled);
    expect(MATCHES_TEXTS.reviewAwaySeenToast(1, [])).toBe("1 rencontre à l'extérieur marquée comme vue.");
    expect(MATCHES_TEXTS.reviewAwaySeenToast(9, [])).toBe("9 rencontres à l'extérieur marquées comme vues.");
  });

  it("extérieurs, 1 laissée de côté : nommée « équipe date »", () => {
    expect(MATCHES_TEXTS.reviewAwaySeenToast(9, away.slice(0, 1))).toBe("9 rencontres à l'extérieur marquées comme vues ; 1 laissée de côté (U15F2 sam. 5 déc.) — tranchez son écart.");
  });

  it("extérieurs, 3 laissées de côté : les trois nommées, sans « autre »", () => {
    expect(MATCHES_TEXTS.reviewAwaySeenToast(1, away.slice(0, 3))).toBe(
      "1 rencontre à l'extérieur marquée comme vue ; 3 laissées de côté (U15F2 sam. 5 déc., SM1 dim. 13 déc., SF2 sam. 19 déc.) — tranchez leurs écarts une par une.",
    );
  });

  it("extérieurs, 4 laissées de côté : trois nommées « et 1 autre » ; aucune vue = « Aucune », jamais « 0 rencontre »", () => {
    expect(MATCHES_TEXTS.reviewAwaySeenToast(0, away.slice(0, 4))).toBe(
      "Aucune rencontre à l'extérieur marquée comme vue ; 4 laissées de côté (U15F2 sam. 5 déc., SM1 dim. 13 déc., SF2 sam. 19 déc. et 1 autre) — tranchez leurs écarts une par une.",
    );
  });

  it("extérieurs, 5 laissées de côté : trois nommées « et 2 autres »", () => {
    expect(MATCHES_TEXTS.reviewAwaySeenToast(2, away)).toBe(
      "2 rencontres à l'extérieur marquées comme vues ; 5 laissées de côté (U15F2 sam. 5 déc., SM1 dim. 13 déc., SF2 sam. 19 déc. et 2 autres) — tranchez leurs écarts une par une.",
    );
  });

  it("extérieurs, 12 laissées de côté : la borne tient (3 noms, « et 9 autres »)", () => {
    const twelve = Array.from({ length: 12 }, (_, i) => away[i % away.length]);
    const message = MATCHES_TEXTS.reviewAwaySeenToast(0, twelve);
    expect(message.match(/\b(?:déc|janv)\./g)).toHaveLength(3);
    expect(message).toContain("et 9 autres)");
  });

  it("extérieurs, rencontre absente de la liste chargée : comptée dans « autre(s) », jamais nommée vide", () => {
    expect(MATCHES_TEXTS.reviewAwaySeenToast(1, [unknown])).toBe("1 rencontre à l'extérieur marquée comme vue ; 1 laissée de côté — tranchez son écart.");
    expect(MATCHES_TEXTS.reviewAwaySeenToast(1, [away[0], unknown])).toBe(
      "1 rencontre à l'extérieur marquée comme vue ; 2 laissées de côté (U15F2 sam. 5 déc. et 1 autre) — tranchez leurs écarts une par une.",
    );
  });

  it("équipe, 1 laissée de côté : nommée par sa date", () => {
    expect(MATCHES_TEXTS.reviewSkippedToast("SM1", 1, dates.slice(0, 1))).toBe("SM1 : 1 rencontre à écart laissée de côté (sam. 5 déc.) — tranchez son écart.");
  });

  it("équipe, 3 laissées de côté : les trois dates, sans « autre »", () => {
    expect(MATCHES_TEXTS.reviewSkippedToast("SM1", 3, dates.slice(0, 3))).toBe(
      "SM1 : 3 rencontres à écart laissées de côté (sam. 5 déc., sam. 12 déc., sam. 19 déc.) — tranchez leurs écarts une par une.",
    );
  });

  it("équipe, 4 laissées de côté : trois dates « et 1 autre »", () => {
    expect(MATCHES_TEXTS.reviewSkippedToast("SM1", 4, dates.slice(0, 4))).toBe(
      "SM1 : 4 rencontres à écart laissées de côté (sam. 5 déc., sam. 12 déc., sam. 19 déc. et 1 autre) — tranchez leurs écarts une par une.",
    );
  });

  it("équipe, 5 laissées de côté : trois dates « et 2 autres »", () => {
    expect(MATCHES_TEXTS.reviewSkippedToast("SM1", 5, dates)).toBe(
      "SM1 : 5 rencontres à écart laissées de côté (sam. 5 déc., sam. 12 déc., sam. 19 déc. et 2 autres) — tranchez leurs écarts une par une.",
    );
  });

  it("équipe, date inconnue (rencontre absente de la liste chargée) : comptée dans « autre(s) »", () => {
    expect(MATCHES_TEXTS.reviewSkippedToast("SM1", 1, [])).toBe("SM1 : 1 rencontre à écart laissée de côté — tranchez son écart.");
    expect(MATCHES_TEXTS.reviewSkippedToast("SM1", 2, dates.slice(0, 1))).toBe("SM1 : 2 rencontres à écart laissées de côté (sam. 5 déc. et 1 autre) — tranchez leurs écarts une par une.");
  });
});

describe("ReviewQueue.onPlace (A8)", () => {
  it("« Replacer » un domicile amical porte le masque de type (amical) dans l'URL de destination + pointe la rencontre", async () => {
    const user = userEvent.setup();
    renderQueue();
    // Ouvrir l'accordéon de l'équipe puis « Replacer ».
    await user.click(await screen.findByRole("button", { name: /SM1/ }));
    await user.click(await screen.findByRole("button", { name: "Replacer" }));
    // Le masque de type voyage DANS L'URL (le store serait écrasé par le seed de CalendarPage).
    await screen.findByText("PLACER");
    const search = (await screen.findByTestId("calendar-search")).textContent ?? "";
    expect(search).toMatch(/[?&]type=[^&]*amical/);
    // La rencontre pointée survit par le store (le seed n'y touche pas).
    expect(useMatchesStore.getState().selectedFixtureId).toBe("fx-1");
  });
});
