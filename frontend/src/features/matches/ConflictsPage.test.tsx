import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { createMemoryRouter, RouterProvider, useLocation } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Conflict } from "./api";
import * as matchesApi from "./api";
import { ConflictsPage } from "./ConflictsPage";
import { useMatchesStore } from "./store";

function side(fixtureId: string, teamId: string, matchDate = "2026-10-03") {
  return { fixtureId, teamId, homeAway: "HOME" as const, matchDate, kickoffTime: "16:00", windowStart: "", windowEnd: "" };
}

// Saison par défaut : 3 conflits du coach « Mara » + 1 sans coach (Gymnase indisponible, daté).
const DEFAULT_CONFLICTS: Conflict[] = [
  { type: "MATCH_MATCH", severity: 3, resolution: null, coachId: "coach-1", start: "2026-10-03T20:00:00", end: "2026-10-03T22:00:00", left: side("fx-1", "team-1"), right: side("fx-2", "team-2") },
  { type: "MATCH_MATCH", severity: 3, resolution: null, coachId: "coach-1", left: side("fx-1", "team-1"), right: side("fx-2", "team-2") },
  { type: "MATCH_TRAINING", severity: 5, resolution: null, coachId: "coach-1", fixture: side("fx-1", "team-1"), training: { slotTemplateId: "t", scheduleId: "sc", teamId: "team-2", venueId: "venue-1", dayOfWeek: 3, startTime: "18:00", durationMinutes: 90, windowStart: "", windowEnd: "" } },
  { type: "VENUE_UNAVAILABLE", severity: 1, resolution: null, fixture: side("fx-1", "team-1", "2026-10-03") },
];

const state = vi.hoisted(() => ({ conflicts: [] as Conflict[] }));

// P4-207 — gestionnaire par défaut : l'éditeur de traitement est proposé (« Traiter »).
const meState = vi.hoisted(() => ({ role: "admin" as string | null }));
vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: { role: meState.role } }) }));

const compState = vi.hoisted(() => ({ competitions: [] as unknown[] }));

vi.mock("./api", () => ({
  getConflicts: vi.fn(() => Promise.resolve({ clubId: "c", seasonId: "s", seasonPlanChosen: true, conflicts: state.conflicts })),
  getCompetitions: vi.fn(() => Promise.resolve(compState.competitions)),
  putConflictResolution: vi.fn(() => Promise.resolve({ fingerprint: "fp", resolution: { status: "DEROGATION_REQUESTED", note: null, updatedAt: "2026-10-03T20:45:00+02:00" } })),
  deleteConflictResolution: vi.fn(() => Promise.resolve(undefined)),
  getTeams: vi.fn(() =>
    Promise.resolve([
      { id: "team-1", name: "U13", sportCategoryId: "cat-1", level: null, gender: null, priorityTierId: 3, tierOrder: 0 },
      { id: "team-2", name: "Seniors", sportCategoryId: "cat-2", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
    ]),
  ),
  getVenues: vi.fn(() => Promise.resolve([{ id: "venue-1", name: "Gymnase Alpha", color: null, externalLabels: [] }])),
  getCoaches: vi.fn(() => Promise.resolve([{ id: "coach-1", firstName: "Mara", lastName: "" }])),
  getFixtures: vi.fn(() =>
    Promise.resolve([
      { id: "fx-1", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Adv", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", externalRef: null, fbiVenueLabel: null, placementSource: "MANUAL", unplacedReason: null, reviewState: "NEW", reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
      { id: "fx-2", teamId: "team-2", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Adv", status: "PLACED", venueId: "venue-1", kickoffTime: "18:00", externalRef: null, fbiVenueLabel: null, placementSource: "MANUAL", unplacedReason: null, reviewState: "NEW", reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
    ]),
  ),
  postModuleVisit: vi.fn(() => Promise.resolve({ firstVisit: true, newFixturesCount: 0, newConflictFingerprints: [], planningChanged: false, referenceTakenAt: "2026-10-01T10:00:00+00:00" })),
}));

// Sonde de destination : « Voir la semaine » navigue vers /matchs ; on LIT la query de
// la cible (l'A8 doit porter les masques dans l'URL — le store est écrasé par le seed).
function CalendarProbe() {
  const location = useLocation();
  return (
    <div>
      <span>PLACER</span>
      <span data-testid="calendar-search">{location.search}</span>
    </div>
  );
}

function renderAt(path = "/matchs/conflits") {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const router = createMemoryRouter(
    [
      { path: "/matchs", element: <CalendarProbe /> },
      { path: "/matchs/conflits", element: <ConflictsPage /> },
    ],
    { initialEntries: [path] },
  );
  return render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  state.conflicts = DEFAULT_CONFLICTS;
  compState.competitions = [];
  meState.role = "admin";
  useMatchesStore.setState({ selectedWeekend: null, conflictsPivot: "coach", conflictsFamilies: null, conflictsTreatments: null, conflictsHomeOnly: false, consultKinds: null, consultAway: false });
});

describe("ConflictsPage — pivot par défaut coach", () => {
  it("groupe par coach : « Mara · 3 » et la sentinelle « Autres conflits · 1 » en dernier", async () => {
    renderAt();
    expect(await screen.findByRole("button", { name: /Mara · 3/ })).toBeInTheDocument();
    const accordions = screen.getAllByRole("button", { name: /· \d/ });
    // La sentinelle « Autres conflits » est la DERNIÈRE entrée.
    expect(accordions[accordions.length - 1]).toHaveAccessibleName(/Autres conflits · 1/);
  });

  it("n'a PAS de MatchesFilterBar (le filtre partagé fausserait le compte saison)", async () => {
    renderAt();
    await screen.findByRole("button", { name: /Mara · 3/ });
    expect(screen.queryByRole("button", { name: "Par coach" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Par équipe" })).not.toBeInTheDocument();
  });
});

describe("ConflictsPage — contrôle du pivot", () => {
  it("porte le groupe « Regrouper par » avec Coach · Équipe · Gymnase · Journée", async () => {
    renderAt();
    await screen.findByRole("button", { name: /Mara · 3/ });
    const group = screen.getByRole("group", { name: "Regrouper par" });
    const labels = within(group)
      .getAllByRole("button")
      .map((b) => b.textContent);
    expect(labels).toEqual(["Coach", "Équipe", "Gymnase", "Journée"]);
    expect(within(group).getByRole("button", { name: "Coach" })).toHaveAttribute("aria-pressed", "true");
  });

  it("passer à « Équipe » re-pivote : U13 (4) et Seniors (3), compte décroissant", async () => {
    const user = userEvent.setup();
    renderAt();
    await screen.findByRole("button", { name: /Mara · 3/ });
    await user.click(screen.getByRole("button", { name: "Équipe" }));
    expect(await screen.findByRole("button", { name: /U13 · 4/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Seniors · 3/ })).toBeInTheDocument();
  });
});

describe("ConflictsPage — chips familles (compteurs saison, séparés de Consulter)", () => {
  it("montre les familles présentes avec leur compte SAISON ; décocher réduit l'entrée mais pas le compte", async () => {
    const user = userEvent.setup();
    renderAt();
    await screen.findByRole("button", { name: /Mara · 3/ });
    // « Personne en double » (MATCH_MATCH) : 2 sur la saison.
    const chip = screen.getByRole("button", { name: /Personne en double/ });
    expect(chip).toHaveTextContent("2");
    expect(chip).toHaveAttribute("aria-pressed", "true");
    await user.click(chip);
    // Décoché : Mara ne garde que le MATCH_TRAINING → « Mara · 1 ». Le compte de la chip reste 2 (saison).
    expect(await screen.findByRole("button", { name: /Mara · 1/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Personne en double/ })).toHaveTextContent("2");
    expect(screen.getByRole("button", { name: /Personne en double/ })).toHaveAttribute("aria-pressed", "false");
  });
});

describe("ConflictsPage — accordéons (une seule ouverte à la fois, ?ouvert)", () => {
  it("ouvre une entrée puis en ouvre une autre : la première se replie", async () => {
    const user = userEvent.setup();
    renderAt();
    const mara = await screen.findByRole("button", { name: /Mara · 3/ });
    const autres = screen.getByRole("button", { name: /Autres conflits · 1/ });
    expect(mara).toHaveAttribute("aria-expanded", "false");
    await user.click(mara);
    expect(mara).toHaveAttribute("aria-expanded", "true");
    await user.click(autres);
    expect(autres).toHaveAttribute("aria-expanded", "true");
    expect(mara).toHaveAttribute("aria-expanded", "false");
  });
});

describe("ConflictsPage — « Voir la semaine » mène à Placer", () => {
  it("clique le bouton → navigue vers /matchs et pose la semaine du conflit", async () => {
    const user = userEvent.setup();
    renderAt();
    const mara = await screen.findByRole("button", { name: /Mara · 3/ });
    await user.click(mara);
    const buttons = await screen.findAllByRole("button", { name: "Voir la semaine" });
    await user.click(buttons[0]);
    expect(await screen.findByText("PLACER")).toBeInTheDocument();
    expect(useMatchesStore.getState().selectedWeekend).toBe("2026-10-03");
  });
});

describe("ConflictsPage — « Voir la semaine » vise LA rencontre (match=)", () => {
  async function clickVoirSemaine() {
    const user = userEvent.setup();
    renderAt();
    await user.click(await screen.findByRole("button", { name: /Mara · 1/ }));
    await user.click((await screen.findAllByRole("button", { name: "Voir la semaine" }))[0]);
    await screen.findByText("PLACER");
    return new URLSearchParams(screen.getByTestId("calendar-search").textContent ?? "");
  }

  it("deux côtés à domicile → vise le côté GAUCHE", async () => {
    state.conflicts = [
      { type: "MATCH_MATCH", severity: 3, resolution: null, coachId: "coach-1", start: "2026-10-03T20:00:00", end: "2026-10-03T22:00:00", left: side("fx-1", "team-1"), right: side("fx-2", "team-2") },
    ];
    expect((await clickVoirSemaine()).get("match")).toBe("fx-1");
  });

  it("un seul côté à domicile → vise CE domicile (pas le gauche par défaut)", async () => {
    state.conflicts = [
      {
        type: "MATCH_MATCH",
        severity: 3,
        resolution: null,
        coachId: "coach-1",
        start: "2026-10-03T20:00:00",
        end: "2026-10-03T22:00:00",
        left: { ...side("fx-1", "team-1"), homeAway: "AWAY" as const },
        right: side("fx-2", "team-2"),
      },
    ];
    // Le gauche est extérieur, le droit est domicile → c'est le droit qui est visé.
    expect((await clickVoirSemaine()).get("match")).toBe("fx-2");
  });
});

describe("ConflictsPage — phrase sr-only aria-live", () => {
  it("vide au premier rendu, remplie APRÈS une interaction de pivot", async () => {
    const user = userEvent.setup();
    renderAt();
    await screen.findByRole("button", { name: /Mara · 3/ });
    expect(screen.queryByText(/Regroupé par/)).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Équipe" }));
    expect(await screen.findByText(/Regroupé par équipe/)).toBeInTheDocument();
  });
});

describe("ConflictsPage — le rôle PAR CÔTÉ ne change ni le pivot ni les compteurs", () => {
  it("un MATCH_MATCH dont un côté est PLAYER pivote toujours par personne et compte sa famille", async () => {
    // Décision 13 — le pivot/filtre/compteur coach s'appuie sur `coachId` (la
    // personne), jamais sur le rôle : une joueuse en double se range sous elle et
    // nourrit la famille « Personne en double » comme un coach.
    state.conflicts = [
      { type: "MATCH_MATCH", severity: 3, resolution: null, coachId: "coach-1", left: { ...side("fx-1", "team-1"), role: "MAIN" }, right: { ...side("fx-2", "team-2"), role: "PLAYER" } },
    ];
    renderAt();
    expect(await screen.findByRole("button", { name: /Mara · 1/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Personne en double/ })).toHaveTextContent("1");
  });
});

describe("ConflictsPage — états vides", () => {
  it("saison sans conflit : EmptyState « Aucun conflit sur la saison »", async () => {
    state.conflicts = [];
    renderAt();
    expect(await screen.findByText("Aucun conflit sur la saison")).toBeInTheDocument();
  });

  it("conflits présents mais toutes les familles décochées : EmptyHint sous les chips (chips visibles)", async () => {
    const user = userEvent.setup();
    renderAt();
    await screen.findByRole("button", { name: /Mara · 3/ });
    // Décocher les trois familles présentes.
    await user.click(screen.getByRole("button", { name: /Personne en double/ }));
    await user.click(screen.getByRole("button", { name: /Match × entraînement/ }));
    await user.click(screen.getByRole("button", { name: /Gymnase indisponible/ }));
    expect(await screen.findByText(/Aucun conflit ne correspond aux filtres/)).toBeInTheDocument();
    // Les chips restent visibles.
    expect(screen.getByRole("button", { name: /Personne en double/ })).toBeInTheDocument();
  });
});

describe("ConflictsPage — pivot journée + sans date", () => {
  it("un conflit sans date tombe dans « Sans date » et n'offre PAS « Voir la semaine »", async () => {
    // Un unique conflit sans date (severity 3 pour rester déplié).
    state.conflicts = [{ type: "COMPETITION_INCOMPLETE", severity: 3, resolution: null, teamId: "team-1", competitionId: "comp-1" }];
    const user = userEvent.setup();
    renderAt();
    // Passer en pivot Journée.
    await user.click(await screen.findByRole("button", { name: "Journée" }));
    // Une seule entrée → ouverte d'office ; son titre est « Sans date · 1 ».
    expect(await screen.findByRole("button", { name: /Sans date · 1/ })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Voir la semaine" })).not.toBeInTheDocument();
  });
});

describe("ConflictsPage — deep-link ?pivot", () => {
  it("?pivot=gymnase ouvre sur le pivot gymnase", async () => {
    renderAt("/matchs/conflits?pivot=gymnase");
    await waitFor(() => expect(screen.getByRole("group", { name: "Regrouper par" })).toBeInTheDocument());
    expect(screen.getByRole("button", { name: "Gymnase" })).toHaveAttribute("aria-pressed", "true");
  });
});

describe("ConflictsPage — mémoire de session des filtres (URL nue vs clé, maison Calendrier)", () => {
  it("URL NUE : garde le pivot de session du store (ne reseede PAS aux défauts)", async () => {
    // Session posée dans le store (mémoire non persistée), puis retour sur l'onglet SANS param :
    // le pivot doit être GARDÉ, jamais réécrasé au défaut « coach » par un seed au montage.
    useMatchesStore.setState({ conflictsPivot: "gymnase" });
    renderAt("/matchs/conflits");
    await screen.findByRole("group", { name: "Regrouper par" });
    expect(screen.getByRole("button", { name: "Gymnase" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByRole("button", { name: "Coach" })).toHaveAttribute("aria-pressed", "false");
  });

  it("URL NUE : le filtre « domicile » survit à un retour sur l'onglet", async () => {
    const user = userEvent.setup();
    const { unmount } = renderAt("/matchs/conflits");
    await screen.findByRole("button", { name: /Mara · 3/ });
    await user.click(screen.getByRole("checkbox", { name: "Seulement avec un match à domicile" }));
    expect(screen.getByRole("checkbox", { name: "Seulement avec un match à domicile" })).toBeChecked();
    // Quitter puis revenir sur l'onglet (URL nue) : le store porte la session, la case reste cochée.
    unmount();
    renderAt("/matchs/conflits");
    await screen.findByRole("group", { name: "Regrouper par" });
    expect(screen.getByRole("checkbox", { name: "Seulement avec un match à domicile" })).toBeChecked();
  });

  it("URL NUE : une puce « Traitement » décochée survit à un retour sur l'onglet", async () => {
    const user = userEvent.setup();
    state.conflicts = [
      { type: "MATCH_MATCH", severity: 3, coachId: "coach-1", fingerprint: "fp-open", resolution: null, left: side("fx-1", "team-1"), right: side("fx-2", "team-2") },
      { type: "MATCH_TRAINING", severity: 5, coachId: "coach-1", fingerprint: "fp-treated", resolution: { status: "RESOLVED_INTERNALLY", note: null, updatedAt: "2026-10-03T20:45:00+02:00" }, fixture: side("fx-1", "team-1"), training },
    ];
    const { unmount } = renderAt("/matchs/conflits");
    await screen.findByRole("group", { name: "Traitement" });
    await user.click(within(screen.getByRole("group", { name: "Traitement" })).getByRole("button", { name: /Réglé en interne/ }));
    expect(within(screen.getByRole("group", { name: "Traitement" })).getByRole("button", { name: /Réglé en interne/ })).toHaveAttribute("aria-pressed", "false");
    unmount();
    renderAt("/matchs/conflits");
    await screen.findByRole("group", { name: "Traitement" });
    expect(within(screen.getByRole("group", { name: "Traitement" })).getByRole("button", { name: /Réglé en interne/ })).toHaveAttribute("aria-pressed", "false");
  });

  it("URL avec CLÉ fait foi : ?pivot=journee écrase la session gymnase du store", async () => {
    useMatchesStore.setState({ conflictsPivot: "gymnase" });
    renderAt("/matchs/conflits?pivot=journee");
    await screen.findByRole("group", { name: "Regrouper par" });
    expect(screen.getByRole("button", { name: "Journée" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByRole("button", { name: "Gymnase" })).toHaveAttribute("aria-pressed", "false");
  });

  it("?ouvert COMPTE comme une clé : un lien vers une entrée fait foi malgré la session", async () => {
    // Session pivot « journée » : sous ce pivot l'entrée coach `coach-1` n'existe pas. Le lien
    // `?ouvert=coach-1` porte une clé → l'URL fait foi → le pivot revient au défaut « coach » →
    // l'entrée « Mara » existe et s'ouvre. Sans compter `?ouvert`, la session masquerait l'entrée.
    useMatchesStore.setState({ conflictsPivot: "journee" });
    renderAt("/matchs/conflits?ouvert=coach-1");
    const mara = await screen.findByRole("button", { name: /Mara · 3/ });
    expect(mara).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByRole("button", { name: "Coach" })).toHaveAttribute("aria-pressed", "true");
  });
});

const training = { slotTemplateId: "t", scheduleId: "sc", teamId: "team-2", venueId: "venue-1", dayOfWeek: 3, startTime: "18:00", durationMinutes: 90, windowStart: "", windowEnd: "" };

describe("ConflictsPage — traitement des conflits (P4-207)", () => {
  it("gestionnaire, conflit à traiter : « Traiter » ouvre les 3 statuts ; choisir écrit (PUT immédiat)", async () => {
    const user = userEvent.setup();
    state.conflicts = [{ type: "MATCH_MATCH", severity: 3, coachId: "coach-1", fingerprint: "fp-x", resolution: null, left: side("fx-1", "team-1"), right: side("fx-2", "team-2") }];
    renderAt();
    const traiter = await screen.findByRole("button", { name: "Traiter le conflit" });
    await user.click(traiter);
    await user.click(await screen.findByRole("menuitem", { name: "Dérogation demandée" }));
    expect(matchesApi.putConflictResolution).toHaveBeenCalledWith("fp-x", { status: "DEROGATION_REQUESTED", note: undefined });
  });

  it("conflit annoté : la pastille du statut + l'entrée « Mara · 0 » (le « · 0 » en sourdine)", async () => {
    state.conflicts = [{ type: "MATCH_MATCH", severity: 3, coachId: "coach-1", fingerprint: "fp-y", resolution: { status: "RESOLVED_INTERNALLY", note: null, updatedAt: "2026-10-03T20:45:00+02:00" }, left: side("fx-1", "team-1"), right: side("fx-2", "team-2") }];
    renderAt();
    expect(await screen.findByRole("button", { name: /Mara · 0/ })).toBeInTheDocument();
    expect(screen.getByText("· 0")).toHaveClass("text-muted-foreground");
    // La pastille EST le déclencheur d'un menu (gestionnaire) : nom accessible portant statut + date.
    expect(screen.getByRole("button", { name: /Statut de traitement : Réglé en interne/ })).toBeInTheDocument();
  });

  it("groupe Traitement : décocher « Réglé en interne » cache la ligne annotée sans toucher au reste", async () => {
    const user = userEvent.setup();
    state.conflicts = [
      { type: "MATCH_MATCH", severity: 3, coachId: "coach-1", fingerprint: "fp-open", resolution: null, left: side("fx-1", "team-1"), right: side("fx-2", "team-2") },
      { type: "MATCH_TRAINING", severity: 5, coachId: "coach-1", fingerprint: "fp-treated", resolution: { status: "RESOLVED_INTERNALLY", note: null, updatedAt: "2026-10-03T20:45:00+02:00" }, fixture: side("fx-1", "team-1"), training },
    ];
    renderAt();
    await screen.findByRole("button", { name: /Mara · 1/ });
    expect(screen.getByRole("button", { name: /Statut de traitement : Réglé en interne/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Traiter le conflit" })).toBeInTheDocument();

    // Décocher la puce « Réglé en interne » du groupe Traitement (scopé pour ne pas viser la pastille).
    const treatment = screen.getByRole("group", { name: "Traitement" });
    await user.click(within(treatment).getByRole("button", { name: /Réglé en interne/ }));
    expect(screen.queryByRole("button", { name: /Statut de traitement : Réglé en interne/ })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Traiter le conflit" })).toBeInTheDocument();
  });

  it("groupe Traitement : les 4 puces avec compteurs FIXES saison, un compteur à 0 en sourdine", async () => {
    state.conflicts = [
      { type: "MATCH_MATCH", severity: 3, coachId: "coach-1", fingerprint: "fp-open", resolution: null, left: side("fx-1", "team-1"), right: side("fx-2", "team-2") },
      { type: "MATCH_TRAINING", severity: 5, coachId: "coach-1", fingerprint: "fp-treated", resolution: { status: "RESOLVED_INTERNALLY", note: null, updatedAt: "2026-10-03T20:45:00+02:00" }, fixture: side("fx-1", "team-1"), training },
    ];
    renderAt();
    const treatment = await screen.findByRole("group", { name: "Traitement" });
    for (const label of ["À traiter", "Dérogation demandée", "Réglé en interne", "Sans solution pour l'instant"]) {
      expect(within(treatment).getByRole("button", { name: new RegExp(label) })).toBeInTheDocument();
    }
    // 1 à traiter, 1 réglé en interne, 0 dérogation (en sourdine).
    expect(within(within(treatment).getByRole("button", { name: /À traiter/ })).getByText("1")).not.toHaveClass("text-muted-foreground");
    expect(within(within(treatment).getByRole("button", { name: /Dérogation demandée/ })).getByText("0")).toHaveClass("text-muted-foreground");
  });

  it("le filtre « domicile » masque un COMPETITION_INCOMPLETE (aucun côté)", async () => {
    const user = userEvent.setup();
    state.conflicts = [
      { type: "MATCH_MATCH", severity: 3, coachId: "coach-1", fingerprint: "fp-home", resolution: null, left: side("fx-1", "team-1"), right: side("fx-2", "team-2") },
      { type: "COMPETITION_INCOMPLETE", severity: 6, resolution: null, teamId: "team-1", competitionId: "comp-1" },
    ];
    renderAt();
    await screen.findByRole("button", { name: /Mara · 1/ });
    expect(screen.getByRole("button", { name: /Autres conflits · 1/ })).toBeInTheDocument();
    await user.click(screen.getByRole("checkbox", { name: "Seulement avec un match à domicile" }));
    // Le conflit à côté domicile reste ; le COMPETITION_INCOMPLETE (aucun côté) disparaît.
    expect(screen.getByRole("button", { name: /Mara · 1/ })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Autres conflits/ })).not.toBeInTheDocument();
  });

  it("une famille entièrement traitée garde sa chip, compteur ouverts 0 en sourdine", async () => {
    state.conflicts = [
      { type: "MATCH_MATCH", severity: 3, coachId: "coach-1", fingerprint: "fp-o", resolution: null, left: side("fx-1", "team-1"), right: side("fx-2", "team-2") },
      { type: "MATCH_TRAINING", severity: 5, coachId: "coach-1", fingerprint: "fp-t", resolution: { status: "RESOLVED_INTERNALLY", note: null, updatedAt: "2026-10-03T20:45:00+02:00" }, fixture: side("fx-1", "team-1"), training },
    ];
    renderAt();
    // La famille « Match × entraînement » est TOUTE traitée : sa chip reste, à 0 en sourdine.
    const chip = await screen.findByRole("button", { name: /Match × entraînement/ });
    const zero = within(chip).getByText("0");
    expect(zero).toHaveClass("text-muted-foreground");
    // La famille « Personne en double » garde son compte ouvert (1), non muet.
    expect(within(screen.getByRole("button", { name: /Personne en double/ })).getByText("1")).not.toHaveClass("text-muted-foreground");
  });

  it("membre : pastille en LECTURE (aucun menu, aucun « Traiter »)", async () => {
    meState.role = "member";
    state.conflicts = [{ type: "MATCH_MATCH", severity: 3, coachId: "coach-1", fingerprint: "fp-z", resolution: { status: "RESOLVED_INTERNALLY", note: null, updatedAt: "2026-10-03T20:45:00+02:00" }, left: side("fx-1", "team-1"), right: side("fx-2", "team-2") }];
    renderAt();
    await screen.findByRole("button", { name: /Mara · 0/ });
    // « Réglé en interne » paraît sur la pastille du conflit ET dans la puce du groupe Traitement.
    expect(screen.getAllByText(/Réglé en interne/).length).toBeGreaterThan(0);
    expect(screen.queryByRole("button", { name: /modifier/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Traiter le conflit" })).not.toBeInTheDocument();
  });
});

describe("ConflictsPage — filtre Traitement, domicile, resets (B)", () => {
  const trainingB = { slotTemplateId: "t", scheduleId: "sc", teamId: "team-2", venueId: "venue-1", dayOfWeek: 3, startTime: "18:00", durationMinutes: 90, windowStart: "", windowEnd: "" };

  it("rétro-compat ?traites=masques : ne montre que l'à traiter (« À traiter » pressé, réécrit)", async () => {
    state.conflicts = [
      { type: "MATCH_MATCH", severity: 3, coachId: "coach-1", fingerprint: "fp-open", resolution: null, left: side("fx-1", "team-1"), right: side("fx-2", "team-2") },
      { type: "MATCH_TRAINING", severity: 5, coachId: "coach-1", fingerprint: "fp-treated", resolution: { status: "RESOLVED_INTERNALLY", note: null, updatedAt: "2026-10-03T20:45:00+02:00" }, fixture: side("fx-1", "team-1"), training: trainingB },
    ];
    renderAt("/matchs/conflits?traites=masques");
    await screen.findByRole("button", { name: /Mara · 1/ });
    expect(screen.getByRole("button", { name: "Traiter le conflit" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Statut de traitement : Réglé en interne/ })).not.toBeInTheDocument();
    const treatment = screen.getByRole("group", { name: "Traitement" });
    expect(within(treatment).getByRole("button", { name: /À traiter/ })).toHaveAttribute("aria-pressed", "true");
    expect(within(treatment).getByRole("button", { name: /Réglé en interne/ })).toHaveAttribute("aria-pressed", "false");
  });

  it("« Tout décocher » vide les familles → EmptyHint + « Réinitialiser les filtres » les rétablit", async () => {
    const user = userEvent.setup();
    renderAt();
    await screen.findByRole("button", { name: /Mara · 3/ });
    const families = screen.getByRole("group", { name: "Familles" });
    await user.click(within(families).getByRole("button", { name: "Tout décocher" }));
    expect(await screen.findByText(/Aucun conflit ne correspond aux filtres/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Réinitialiser les filtres" }));
    expect(await screen.findByRole("button", { name: /Mara · 3/ })).toBeInTheDocument();
  });

  it("A8 : « Voir la semaine » sur un conflit à côté extérieur/amical pose consultAway + consultKinds AVANT de naviguer", async () => {
    const user = userEvent.setup();
    vi.mocked(matchesApi.getFixtures).mockResolvedValueOnce([
      { id: "fx-away", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "AWAY", opponentLabel: "Adv", status: "UNPLACED", venueId: null, kickoffTime: null, externalRef: null, fbiVenueLabel: "Halle", placementSource: null, unplacedReason: null, reviewState: "NEW", reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
      { id: "fx-2", teamId: "team-2", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Adv", status: "PLACED", venueId: "venue-1", kickoffTime: "18:00", externalRef: null, fbiVenueLabel: null, placementSource: "MANUAL", unplacedReason: null, reviewState: "NEW", reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
    ] as never);
    state.conflicts = [
      {
        type: "MATCH_MATCH",
        severity: 3,
        resolution: null,
        coachId: "coach-1",
        left: { fixtureId: "fx-away", teamId: "team-1", homeAway: "AWAY", matchDate: "2026-10-03", kickoffTime: null, windowStart: "", windowEnd: "" },
        right: side("fx-2", "team-2"),
      },
    ];
    renderAt();
    const voir = await screen.findByRole("button", { name: "Voir la semaine" });
    await user.click(voir);
    // Les masques du Calendrier sont portés par l'URL DE DESTINATION (le store serait écrasé
    // par le seed de CalendarPage) : `exterieurs=1` (côté AWAY) + `type=…amical…` + `semaine=`.
    await screen.findByText("PLACER");
    const search = (await screen.findByTestId("calendar-search")).textContent ?? "";
    expect(search).toMatch(/[?&]exterieurs=1/);
    expect(search).toMatch(/[?&]type=[^&]*amical/);
    expect(search).toMatch(/[?&]semaine=2026-10-03/);
    expect(useMatchesStore.getState().selectedWeekend).toBe("2026-10-03");
  });
});

describe("ConflictsPage — lot N : lien d'import + puce de traitement conditionnelle", () => {
  it("un « Calendrier incomplet » (sans date) offre « Importer » au lieu de « Voir la semaine »", async () => {
    const user = userEvent.setup();
    state.conflicts = [
      { type: "COMPETITION_INCOMPLETE", severity: 6, resolution: null, teamId: "team-1", competitionId: "comp-1", competitionName: "Championnat X", imported: 2, expected: 10 },
    ];
    renderAt();
    const entry = await screen.findByRole("button", { name: /Autres conflits · 1/ });
    await user.click(entry);
    // La gravité 6 (« Calendriers incomplets ») est repliée derrière un compte : on la déplie.
    await user.click(await screen.findByRole("button", { name: /Calendriers incomplets/ }));
    expect(await screen.findByRole("button", { name: "Importer" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Voir la semaine" })).not.toBeInTheDocument();
  });

  it("la puce de traitement « Erreur FBI » ne se rend QUE si un conflit la porte ; pas les autres conditionnelles", async () => {
    state.conflicts = [
      { type: "VENUE_OVERLAP", severity: 1, resolution: { status: "FBI_ERROR", note: null, updatedAt: "2026-10-03T20:45:00+02:00" }, left: side("fx-1", "team-1"), right: side("fx-2", "team-2") },
    ];
    renderAt();
    const group = await screen.findByRole("group", { name: "Traitement" });
    expect(within(group).getByRole("button", { name: /Erreur FBI/ })).toBeInTheDocument();
    // Les deux autres puces propres à une famille restent absentes (aucun conflit ne les porte).
    expect(within(group).queryByRole("button", { name: /Importer les matchs manquants/ })).not.toBeInTheDocument();
    expect(within(group).queryByRole("button", { name: /Match à déplacer/ })).not.toBeInTheDocument();
  });
});
