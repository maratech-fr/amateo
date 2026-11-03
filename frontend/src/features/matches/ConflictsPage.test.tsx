import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { createMemoryRouter, RouterProvider, useLocation } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useToastStore } from "@/shared/stores/toastStore";

import type { Conflict, ConflictMailingResult } from "./api";
import * as matchesApi from "./api";
import { ConflictsPage } from "./ConflictsPage";
import { useMatchesStore } from "./store";
import { MATCHES_TEXTS } from "./texts";
import { MATCHES_CONFLICTS_MAIL_TEXTS as MAIL } from "./texts/conflictsMail";

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

// Envoi aux coachs — `coachMailing` est servi par défaut (contrat complet) ; `undefined` le RETIRE
// de la réponse (cas « champ absent ⇒ bouton désactivé »).
const DEFAULT_COACH_MAILING = { personConflictCount: 3, pendingRecipientCount: 0, last: null };
const state = vi.hoisted(() => ({ conflicts: [] as Conflict[], held: [] as unknown[], coachMailing: undefined as unknown }));

// P4-207 — gestionnaire par défaut : l'éditeur de traitement est proposé (« Traiter »).
// Envoi aux coachs : le club (démo ?) et la saison de travail (nom, archivée ?) viennent de la session.
const meState = vi.hoisted(() => ({ role: "admin" as string | null, isDemo: false, seasonReadonly: false }));
vi.mock("@/shared/session/queries", () => {
  const season = () => ({ id: "s", name: "2026-2027", startDate: "2026-08-01", endDate: "2027-07-15", isCurrent: true, isReadonly: meState.seasonReadonly });
  return {
    useMe: () => ({ data: { role: meState.role, club: { id: "c", isDemo: meState.isDemo }, seasons: [season()], currentSeasonId: "s" } }),
    useWorkingSeason: () => season(),
  };
});

const compState = vi.hoisted(() => ({ competitions: [] as unknown[] }));

vi.mock("./api", () => ({
  getConflicts: vi.fn(() =>
    Promise.resolve({ clubId: "c", seasonId: "s", seasonPlanChosen: true, conflicts: state.conflicts, held: state.held, ...(undefined === state.coachMailing ? {} : { coachMailing: state.coachMailing }) }),
  ),
  mailConflictsToCoaches: vi.fn(),
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
  getCoaches: vi.fn(() =>
    Promise.resolve([
      { id: "coach-1", firstName: "Mara", lastName: "" },
      { id: "coach-karim", firstName: "Karim", lastName: "Benali" },
      { id: "coach-emma", firstName: "Emma", lastName: "Durand" },
    ]),
  ),
  getFixtures: vi.fn(() =>
    Promise.resolve([
      { id: "fx-1", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Adv", status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", externalRef: null, fbiVenueLabel: null, placementSource: "MANUAL", unplacedReason: null, neutralGround: false, reviewState: "NEW", reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
      { id: "fx-2", teamId: "team-2", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Adv", status: "PLACED", venueId: "venue-1", kickoffTime: "18:00", externalRef: null, fbiVenueLabel: null, placementSource: "MANUAL", unplacedReason: null, neutralGround: false, reviewState: "NEW", reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
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
  state.held = [];
  state.coachMailing = DEFAULT_COACH_MAILING;
  compState.competitions = [];
  meState.role = "admin";
  meState.isDemo = false;
  meState.seasonReadonly = false;
  useToastStore.setState({ toasts: [] });
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

describe("ConflictsPage — la section « Un autre coach tient »", () => {
  const heldLine = {
    type: "MATCH_TRAINING",
    coachId: "coach-1",
    heldBy: "coach-1",
    heldSide: "training",
    heldByTeamId: null,
    fixture: { ...side("fx-1", "team-1"), homeAway: "AWAY" as const },
    training: { slotTemplateId: "t", scheduleId: "sc", teamId: "team-2", venueId: "venue-1", dayOfWeek: 6, startTime: "16:00", durationMinutes: 90, windowStart: "", windowEnd: "" },
  };

  it("sans conflit à traiter : l'état vide reste en tête, la section repliée suit", async () => {
    state.conflicts = [];
    state.held = [heldLine];
    renderAt();

    expect(await screen.findByText(MATCHES_TEXTS.noSeasonConflictsTitle)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: new RegExp(MATCHES_TEXTS.heldSectionTitle(1)) })).toHaveAttribute("aria-expanded", "false");
  });

  it("sans conflit ni ligne tenue : l'état vide seul, aucune section", async () => {
    state.conflicts = [];
    state.held = [];
    renderAt();

    expect(await screen.findByText(MATCHES_TEXTS.noSeasonConflictsTitle)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: new RegExp(MATCHES_TEXTS.heldSectionTitle(1)) })).not.toBeInTheDocument();
  });

  it("ouverte : l'aide, qui tient, « Voir la semaine » et aucun menu de traitement", async () => {
    const user = userEvent.setup();
    state.conflicts = [];
    state.held = [heldLine];
    renderAt();

    await user.click(await screen.findByRole("button", { name: new RegExp(MATCHES_TEXTS.heldSectionTitle(1)) }));

    expect(screen.getByText(MATCHES_TEXTS.heldSectionHint)).toBeInTheDocument();
    expect(screen.getByText(MATCHES_TEXTS.heldSessionTag("Seniors", "Mara"))).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Voir la semaine" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Traiter/ })).not.toBeInTheDocument();
  });

  it("ignore les filtres de la page : un extérieur tenu reste listé avec le filtre domicile, et les comptes ne le voient pas", async () => {
    state.conflicts = [DEFAULT_CONFLICTS[3] as Conflict];
    state.held = [heldLine];
    useMatchesStore.setState({ conflictsHomeOnly: true, conflictsFamilies: [] });
    renderAt();

    expect(await screen.findByRole("button", { name: new RegExp(MATCHES_TEXTS.heldSectionTitle(1)) })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Mara · 1/ })).not.toBeInTheDocument();
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

describe("ConflictsPage — « Voir la semaine » focalise le conflit du coach (correctif 2)", () => {
  async function clickVoirSemaine() {
    const user = userEvent.setup();
    renderAt();
    await user.click(await screen.findByRole("button", { name: /Mara · 1/ }));
    await user.click((await screen.findAllByRole("button", { name: "Voir la semaine" }))[0]);
    await screen.findByText("PLACER");
    return new URLSearchParams(screen.getByTestId("calendar-search").textContent ?? "");
  }

  it("conflit de coach → filtre coach (vue=coach&filtre=coach-1) + les DEUX matchs surlignés (conflit=), SANS sélection (pas de match=)", async () => {
    // Le bug : « Voir la semaine » sélectionnait UN match (match=) et ouvrait le panneau. Le focus
    // doit au contraire montrer le PLANNING du coach et surligner les deux rencontres.
    state.conflicts = [
      { type: "MATCH_MATCH", severity: 3, resolution: null, coachId: "coach-1", start: "2026-10-03T20:00:00", end: "2026-10-03T22:00:00", left: side("fx-1", "team-1"), right: side("fx-2", "team-2") },
    ];
    const params = await clickVoirSemaine();
    expect(params.get("vue")).toBe("coach");
    expect(params.get("filtre")).toBe("coach-1");
    expect(params.get("conflit")).toBe("fx-1,fx-2");
    expect(params.get("match")).toBeNull();
  });

  it("un côté à l'extérieur : les DEUX rencontres sont surlignées (le focus ne sélectionne rien)", async () => {
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
    const params = await clickVoirSemaine();
    expect(params.get("conflit")).toBe("fx-1,fx-2");
    expect(params.get("match")).toBeNull();
  });
});

describe("ConflictsPage — « Voir la semaine » d'un conflit SANS coach filtre les équipes (correctif 3)", () => {
  async function clickVoirSemaineAutres() {
    const user = userEvent.setup();
    renderAt();
    await user.click(await screen.findByRole("button", { name: /Autres conflits/ }));
    await user.click((await screen.findAllByRole("button", { name: "Voir la semaine" }))[0]);
    await screen.findByText("PLACER");
    return new URLSearchParams(screen.getByTestId("calendar-search").textContent ?? "");
  }

  it("collision de gymnase (sans coach) → filtre les DEUX équipes (filtre=team-1,team-2, mode équipe) + les deux rencontres surlignées (conflit=), sans coach ni match=", async () => {
    // Le bug : un conflit sans coach sélectionnait UN match (match=) et ouvrait le panneau sur une
    // seule équipe. Le focus doit filtrer sur les deux équipes en litige et surligner les deux.
    state.conflicts = [{ type: "VENUE_OVERLAP", severity: 2, resolution: null, venueId: "venue-1", left: side("fx-1", "team-1"), right: side("fx-2", "team-2") }];
    const params = await clickVoirSemaineAutres();
    expect(params.get("filtre")).toBe("team-1,team-2");
    expect(params.get("vue")).toBeNull(); // mode équipe = défaut : pas de vue=
    expect(params.get("conflit")).toBe("fx-1,fx-2");
    expect(params.get("match")).toBeNull();
  });

  it("un seul côté (gymnase indisponible) → filtre sur cette SEULE équipe + focus", async () => {
    state.conflicts = [{ type: "VENUE_UNAVAILABLE", severity: 1, resolution: null, fixture: side("fx-1", "team-1") }];
    const params = await clickVoirSemaineAutres();
    expect(params.get("filtre")).toBe("team-1");
    expect(params.get("conflit")).toBe("fx-1");
    expect(params.get("match")).toBeNull();
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
    // M4 (BCCL 2026-10-27) : le filtre domicile est désormais un contrôle SEGMENTÉ (bouton
    // aria-pressed), plus une case à cocher — adaptation mécanique du widget, intention inchangée.
    await user.click(screen.getByRole("button", { name: /Avec un match à domicile/ }));
    expect(screen.getByRole("button", { name: /Avec un match à domicile/ })).toHaveAttribute("aria-pressed", "true");
    // Quitter puis revenir sur l'onglet (URL nue) : le store porte la session, le segment reste pressé.
    unmount();
    renderAt("/matchs/conflits");
    await screen.findByRole("group", { name: "Regrouper par" });
    expect(screen.getByRole("button", { name: /Avec un match à domicile/ })).toHaveAttribute("aria-pressed", "true");
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
    // M4 (BCCL 2026-10-27) : contrôle segmenté « Avec un match à domicile » (adaptation du widget).
    await user.click(screen.getByRole("button", { name: /Avec un match à domicile/ }));
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
      { id: "fx-away", teamId: "team-1", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "AWAY", opponentLabel: "Adv", status: "UNPLACED", venueId: null, kickoffTime: null, externalRef: null, fbiVenueLabel: "Halle", placementSource: null, unplacedReason: null, neutralGround: false, reviewState: "NEW", reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
      { id: "fx-2", teamId: "team-2", seasonId: "s", competitionId: null, matchDate: "2026-10-03", homeAway: "HOME", opponentLabel: "Adv", status: "PLACED", venueId: "venue-1", kickoffTime: "18:00", externalRef: null, fbiVenueLabel: null, placementSource: "MANUAL", unplacedReason: null, neutralGround: false, reviewState: "NEW", reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null },
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

describe("ConflictsPage — filtre domicile SEGMENTÉ (M4, BCCL 2026-10-27)", () => {
  // Deux conflits du coach Mara : un à DOMICILE, un extérieur/extérieur (masqué par « domicile »).
  const homeConflict: Conflict = { type: "MATCH_MATCH", severity: 3, resolution: null, coachId: "coach-1", left: side("fx-1", "team-1"), right: side("fx-2", "team-2") };
  const awayAwayConflict: Conflict = {
    type: "MATCH_MATCH",
    severity: 3,
    resolution: null,
    coachId: "coach-1",
    left: { ...side("fx-3", "team-1"), homeAway: "AWAY" as const },
    right: { ...side("fx-4", "team-2"), homeAway: "AWAY" as const },
  };

  it("domicile pressé reflète le store, les segments portent leurs comptes, « Tous » révèle l'ext/ext masqué", async () => {
    const user = userEvent.setup();
    state.conflicts = [homeConflict, awayAwayConflict];
    // Le store NAÎT à true (store.test.ts) ; le beforeEach du fichier le force à false — on rétablit
    // le défaut ici pour éprouver le REFLET du store dans l'UI du contrôle segmenté.
    useMatchesStore.setState({ conflictsHomeOnly: true });
    renderAt();

    // Le segment « domicile » est pressé (reflète le store), « Tous » ne l'est pas ; chacun son compte.
    const home = await screen.findByRole("button", { name: "Avec un match à domicile (1)" });
    expect(home).toHaveAttribute("aria-pressed", "true");
    const tous = screen.getByRole("button", { name: "Tous (2)" });
    expect(tous).toHaveAttribute("aria-pressed", "false");
    // Sous « domicile », Mara ne compte QUE le conflit à domicile (l'ext/ext est masqué).
    expect(screen.getByRole("button", { name: /Mara · 1/ })).toBeInTheDocument();

    // « Tous » RÉVÈLE l'ext/ext : Mara repasse à 2 et le segment « Tous » devient pressé.
    await user.click(tous);
    expect(await screen.findByRole("button", { name: /Mara · 2/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Tous (2)" })).toHaveAttribute("aria-pressed", "true");
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

// FRT-36 — le FILET des chemins de LECTURE (aucune ligne de production ne bouge). Le test frère
// n'exerçait ni le chargement ni l'échec de `ConflictsPage.tsx:413-428` (readLoading →
// FullPageSpinner ; readFailed(conflicts||teams||venues) → LoadErrorHint avec un onRetry qui
// refetch LES TROIS lectures). Ces tests épinglent l'effet observable : rôle DOM, textes,
// refetch, jamais une classe.
describe("ConflictsPage — filet des chemins de lecture (FRT-36)", () => {
  it("getConflicts rejette → alerte d'échec + « Réessayer », JAMAIS l'EmptyState « Aucun conflit »", async () => {
    vi.mocked(matchesApi.getConflicts).mockRejectedValueOnce(new Error("réseau"));
    renderAt();
    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("Le chargement a échoué.");
    expect(within(alert).getByRole("button", { name: "Réessayer" })).toBeInTheDocument();
    // Le mensonge exact que `LoadErrorHint` existe pour tuer : un échec déguisé en « aucun conflit ».
    expect(screen.queryByText("Aucun conflit sur la saison")).not.toBeInTheDocument();
  });

  it("le retry RÉPARE : échec initial sur getTeams, clic « Réessayer » → refetch des trois, le contenu apparaît", async () => {
    // getTeams échoue au 1ᵉʳ appel seulement ; le retry refetch les TROIS lectures — dont
    // getTeams, désormais au vert — et la page revient. (Falsif. : retirer `void teams.refetch()`
    // de l'onRetry ⇒ getTeams reste en échec, l'alerte ne cède jamais.)
    vi.mocked(matchesApi.getTeams).mockRejectedValueOnce(new Error("réseau"));
    const user = userEvent.setup();
    renderAt();
    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("Le chargement a échoué.");
    await user.click(within(alert).getByRole("button", { name: "Réessayer" }));
    expect(await screen.findByRole("button", { name: /Mara · 3/ })).toBeInTheDocument();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });

  it("l'échec d'UNE lecture secondaire (getTeams seul) lève l'alerte", async () => {
    vi.mocked(matchesApi.getTeams).mockRejectedValueOnce(new Error("réseau"));
    renderAt();
    expect(await screen.findByText("Le chargement a échoué.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Mara · 3/ })).not.toBeInTheDocument();
  });

  it("l'échec d'UNE lecture secondaire (getVenues seul) lève l'alerte", async () => {
    vi.mocked(matchesApi.getVenues).mockRejectedValueOnce(new Error("réseau"));
    renderAt();
    expect(await screen.findByText("Le chargement a échoué.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Mara · 3/ })).not.toBeInTheDocument();
  });

  it("chargement : FullPageSpinner tant qu'une lecture fondatrice n'a rien rendu", async () => {
    // getConflicts ne résout JAMAIS → la page reste au premier chargement (spinner pleine page),
    // jamais un vide ni une alerte fabriqués.
    vi.mocked(matchesApi.getConflicts).mockReturnValueOnce(new Promise(() => {}) as never);
    renderAt();
    expect(await screen.findByLabelText("Chargement")).toBeInTheDocument();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
    expect(screen.queryByText("Aucun conflit sur la saison")).not.toBeInTheDocument();
  });
});

// ── Envoyer les conflits aux coachs ─────────────────────────────────────────────────────────
// Le serveur calcule TOUT (`coachMailing` du GET, résultat du POST) ; l'écran n'affiche que ça.
// Textes : clés de `texts/conflictsMail.ts`, jamais la phrase recopiée.

const SENT_AT = "2026-11-03T14:32:00+01:00"; // affiché « 03/11/2026 à 14:32 » (heure murale servie)

function mailingResult(over: Partial<ConflictMailingResult> = {}): ConflictMailingResult {
  return { sentAt: SENT_AT, conflictCount: 0, recipients: [], cleared: [], skipped: [], failed: [], ...over };
}
const recipient = (n: number) => ({ coachId: `r-${n}`, firstName: `Coach${n}`, lastName: "Liste", conflictCount: 1 });
const clearedCoach = (n: number) => ({ coachId: `c-${n}`, firstName: `Coach${n}`, lastName: "Plus" });
const KARIM = { coachId: "coach-karim", firstName: "Karim", lastName: "Benali" };
const EMMA = { coachId: "coach-emma", firstName: "Emma", lastName: "Durand" };
const LEA = { coachId: "coach-lea", firstName: "Léa", lastName: "Roux" };

function httpError(status: number, data: unknown): HTTPError {
  const response = new Response(JSON.stringify(data), { status, headers: { "content-type": "application/json" } });
  const error = new HTTPError(response, new Request("http://localhost/api/fixtures/conflicts/mail-coaches", { method: "POST" }), {} as never);
  (error as unknown as { data?: unknown }).data = data;
  return error;
}

const findSendButton = () => screen.findByRole("button", { name: MAIL.sendButton });
const lastToast = () => useToastStore.getState().toasts.at(-1);
const statusPrefix = () => MAIL.statusLine({ date: "§", time: "§", sender: null, mailedCount: 1, skippedNames: [] }).split("§")[0];

describe("ConflictsPage — envoyer les conflits aux coachs : qui voit le bouton", () => {
  it("un gestionnaire voit « Envoyer aux coachs », actif quand il y a des conflits de personne", async () => {
    renderAt();
    const button = await findSendButton();
    expect(button).not.toHaveAttribute("aria-disabled");
    expect(button).toBeEnabled();
  });

  it("un membre n'a pas le bouton mais lit la ligne du dernier envoi", async () => {
    meState.role = "member";
    state.coachMailing = { ...DEFAULT_COACH_MAILING, last: { sentAt: SENT_AT, sentByFirstName: "Nadia", mailedCount: 7, skippedCoachIds: [] } };
    renderAt();
    expect(await screen.findByText(MAIL.statusLine({ date: "03/11/2026", time: "14:32", sender: "Nadia", mailedCount: 7, skippedNames: [] }))).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: MAIL.sendButton })).not.toBeInTheDocument();
  });

  it("saison sans conflit : le bouton et la ligne d'état restent rendus au-dessus de l'état vide", async () => {
    state.conflicts = [];
    state.coachMailing = { personConflictCount: 0, pendingRecipientCount: 0, last: { sentAt: SENT_AT, sentByFirstName: "Nadia", mailedCount: 2, skippedCoachIds: [] } };
    renderAt();
    const button = await findSendButton();
    expect(screen.getByText(MATCHES_TEXTS.noSeasonConflictsTitle)).toBeInTheDocument();
    expect(screen.getByText(MAIL.statusLine({ date: "03/11/2026", time: "14:32", sender: "Nadia", mailedCount: 2, skippedNames: [] }))).toBeInTheDocument();
    expect(button).toHaveAttribute("aria-disabled", "true");
    expect(button).toHaveAccessibleDescription(MAIL.disabledNothingToSend);
  });

  it("zéro conflit de personne mais un coach à prévenir (« plus aucun conflit ») : le bouton reste ACTIF", async () => {
    state.conflicts = [];
    state.coachMailing = { personConflictCount: 0, pendingRecipientCount: 1, last: { sentAt: SENT_AT, sentByFirstName: "Nadia", mailedCount: 2, skippedCoachIds: [] } };
    renderAt();
    const button = await findSendButton();
    expect(button).not.toHaveAttribute("aria-disabled");
    expect(button).toBeEnabled();
  });
});

describe("ConflictsPage — envoyer aux coachs : désactivé, avec sa raison découvrable", () => {
  it.each([
    ["rien à envoyer ni personne à prévenir", (): void => { state.coachMailing = { personConflictCount: 0, pendingRecipientCount: 0, last: null }; }, (): string => MAIL.disabledNothingToSend],
    ["club de démonstration", (): void => { meState.isDemo = true; }, (): string => MAIL.disabledDemo],
    ["saison archivée (saison de travail en lecture seule)", (): void => { meState.seasonReadonly = true; }, (): string => MAIL.disabledArchived],
    ["réponse sans `coachMailing`", (): void => { state.coachMailing = undefined; }, (): string => MAIL.disabledUnavailable],
  ])("%s : bouton désactivé, raison annoncée, le clic n'ouvre rien", async (_label, arrange, reason) => {
    arrange();
    const user = userEvent.setup();
    renderAt();
    const button = await findSendButton();
    expect(button).toHaveAttribute("aria-disabled", "true");
    expect(button).toHaveAccessibleDescription(reason());
    await user.click(button);
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(matchesApi.mailConflictsToCoaches).not.toHaveBeenCalled();
  });
});

describe("ConflictsPage — envoyer aux coachs : confirmation puis envoi", () => {
  it("le clic ouvre une confirmation qui nomme la saison ; « Annuler » n'envoie rien", async () => {
    const user = userEvent.setup();
    renderAt();
    await user.click(await findSendButton());
    const dialog = screen.getByRole("dialog", { name: MAIL.confirmTitle });
    expect(within(dialog).getByText(MAIL.confirmBody("2026-2027"))).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: MAIL.confirmCancel }));
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(matchesApi.mailConflictsToCoaches).not.toHaveBeenCalled();
  });

  it("« Envoyer » poste UNE fois ; pendant la requête « Envoi… » est désactivé ; au retour le radar est relu", async () => {
    let resolve: (value: ConflictMailingResult) => void = () => undefined;
    vi.mocked(matchesApi.mailConflictsToCoaches).mockReturnValueOnce(
      new Promise<ConflictMailingResult>((r) => {
        resolve = r;
      }),
    );
    const user = userEvent.setup();
    renderAt();
    await user.click(await findSendButton());
    const dialog = screen.getByRole("dialog", { name: MAIL.confirmTitle });
    await user.click(within(dialog).getByRole("button", { name: MAIL.confirmAction }));

    const pending = await within(dialog).findByRole("button", { name: MAIL.sending });
    expect(pending).toBeDisabled();
    await user.click(pending);
    expect(matchesApi.mailConflictsToCoaches).toHaveBeenCalledTimes(1);

    const readsBefore = vi.mocked(matchesApi.getConflicts).mock.calls.length;
    resolve(mailingResult({ recipients: [recipient(1), recipient(2)] }));
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    await waitFor(() => expect(vi.mocked(matchesApi.getConflicts).mock.calls.length).toBeGreaterThan(readsBefore));
  });

  it("un refus serveur `{detail}` (409 socle rouvert) s'affiche tel quel en toast d'erreur", async () => {
    const detail = "Validez le planning principal avant de créer un match ou un planning secondaire.";
    vi.mocked(matchesApi.mailConflictsToCoaches).mockRejectedValueOnce(httpError(409, { detail }));
    const user = userEvent.setup();
    renderAt();
    await user.click(await findSendButton());
    await user.click(within(screen.getByRole("dialog", { name: MAIL.confirmTitle })).getByRole("button", { name: MAIL.confirmAction }));
    await waitFor(() => expect(lastToast()?.message).toBe(detail));
    expect(lastToast()?.variant).toBe("error");
  });
});

describe("ConflictsPage — envoyer aux coachs : le toast dit ce qui est parti", () => {
  it.each([
    ["un e-mail", mailingResult({ recipients: [recipient(1)] }), { mailedCount: 1, skippedNames: [] as string[], failedNames: [] as string[] }],
    ["listes ET « plus aucun conflit » comptent ensemble", mailingResult({ recipients: [1, 2, 3, 4, 5].map(recipient), cleared: [clearedCoach(6), clearedCoach(7)] }), { mailedCount: 7, skippedNames: [], failedNames: [] }],
    ["un coach sans adresse nommé", mailingResult({ recipients: [1, 2, 3, 4, 5, 6, 7].map(recipient), skipped: [{ ...KARIM, kind: "conflicts" }] }), { mailedCount: 7, skippedNames: ["Karim B."], failedNames: [] }],
    [
      "deux e-mails non partis nommés (dont un « plus aucun »)",
      mailingResult({ recipients: [1, 2, 3, 4, 5, 6, 7].map(recipient), failed: [{ ...LEA, kind: "conflicts" }, { ...EMMA, kind: "cleared" }] }),
      { mailedCount: 7, skippedNames: [], failedNames: ["Léa R.", "Emma D."] },
    ],
    ["rien enfilé, deux sans adresse", mailingResult({ sentAt: null, skipped: [{ ...KARIM, kind: "conflicts" }, { ...EMMA, kind: "cleared" }] }), { mailedCount: 0, skippedNames: ["Karim B.", "Emma D."], failedNames: [] }],
  ])("%s", async (_label, result, expected) => {
    vi.mocked(matchesApi.mailConflictsToCoaches).mockResolvedValueOnce(result);
    const user = userEvent.setup();
    renderAt();
    await user.click(await findSendButton());
    await user.click(within(screen.getByRole("dialog", { name: MAIL.confirmTitle })).getByRole("button", { name: MAIL.confirmAction }));
    await waitFor(() => expect(lastToast()?.message).toBe(MAIL.toastResult(expected)));
  });
});

describe("ConflictsPage — envoyer aux coachs : la ligne « Envoyé le … »", () => {
  it.each([
    ["un e-mail, par Nadia", { sentByFirstName: "Nadia", mailedCount: 1, skippedCoachIds: [] as string[] }, { sender: "Nadia" as string | null, mailedCount: 1, skippedNames: [] as string[] }],
    ["auteur disparu : sans « par »", { sentByFirstName: null, mailedCount: 7, skippedCoachIds: [] }, { sender: null, mailedCount: 7, skippedNames: [] }],
    ["un coach sans adresse à cet envoi", { sentByFirstName: "Nadia", mailedCount: 7, skippedCoachIds: ["coach-karim"] }, { sender: "Nadia", mailedCount: 7, skippedNames: ["Karim B."] }],
    ["deux coachs sans adresse", { sentByFirstName: "Nadia", mailedCount: 7, skippedCoachIds: ["coach-karim", "coach-emma"] }, { sender: "Nadia", mailedCount: 7, skippedNames: ["Karim B.", "Emma D."] }],
    ["un coach supprimé depuis : « un coach retiré »", { sentByFirstName: "Nadia", mailedCount: 7, skippedCoachIds: ["coach-disparu"] }, { sender: "Nadia", mailedCount: 7, skippedNames: [MAIL.coachRemoved] }],
  ])("%s", async (_label, last, expected) => {
    state.coachMailing = { ...DEFAULT_COACH_MAILING, last: { sentAt: SENT_AT, ...last } };
    renderAt();
    expect(await screen.findByText(MAIL.statusLine({ date: "03/11/2026", time: "14:32", ...expected }))).toBeInTheDocument();
  });

  it("aucun envoi encore : pas de ligne « Envoyé le »", async () => {
    renderAt();
    await findSendButton();
    expect(screen.queryByText((text) => text.startsWith(statusPrefix()))).not.toBeInTheDocument();
  });
});
