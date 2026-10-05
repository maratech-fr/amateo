import { fireEvent, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { ClubLeagueWindow, Coach, MatchConstraint, MatchConstraintCoherence, PriorityTier, Team, Venue } from "./api";
import { ConstraintsPage } from "./ConstraintsPage";

const createWindow = vi.fn();
const updateWindow = vi.fn();
const deleteWindow = vi.fn();
const windowsState: { data: ClubLeagueWindow[] | undefined; isError: boolean } = { data: [], isError: false };
const createRule = vi.fn();
const updateRule = vi.fn();
const deleteRule = vi.fn();
const rulesState: { data: MatchConstraint[] | undefined; isError: boolean } = { data: [], isError: false };
const coherenceState: { data: MatchConstraintCoherence } = { data: { byRule: [], byHabit: [] } };
const teamsState: { data: Team[] | undefined; isError: boolean } = { data: [], isError: false };
const venuesState: { data: Venue[] | undefined; isError: boolean } = { data: [], isError: false };
// Les paliers de rang alimentent le groupage du `TeamSelect` de la ligne d'ajout ; une liste
// vide ⇒ liste plate (aucun groupe), ce qui suffit ici — on n'y teste que la valeur choisie.
const tiersState: { data: PriorityTier[] | undefined; isError: boolean } = { data: [], isError: false };
const coachesState: { data: Coach[] | undefined; isError: boolean } = { data: [], isError: false };
// ALIGN-19 — liens équipe⇄coach (rôle) : sert à savoir si un coach n'est qu'adjoint.
const teamCoachesState: { data: { id: string; teamId: string; coachId: string; role: "MAIN" | "ASSISTANT" }[] | undefined; isError: boolean } = { data: [], isError: false };
// P4-271 — le réglage d'affichage A/B vient de la session (`me.club.weekendAlternates`).
const meState: { weekendAlternates: boolean } = { weekendAlternates: true };

vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { role: "admin", club: { weekendAlternates: meState.weekendAlternates } } }),
}));

// On pilote les hooks (miroir de la copie stockée), jamais le réseau. Le badge et
// l'alerte de cohérence viennent du SERVEUR : l'écran les affiche, on ne les recalcule pas ici.
vi.mock("./queries", () => ({
  useClubLeagueWindows: () => ({ ...windowsState, refetch: vi.fn() }),
  useCreateClubLeagueWindow: () => ({ mutate: createWindow, isPending: false }),
  useUpdateClubLeagueWindow: () => ({ mutate: updateWindow, isPending: false }),
  useDeleteClubLeagueWindow: () => ({ mutate: deleteWindow, isPending: false }),
  // Bloc « Plages suggérées » : neutre ici (instance null → rien rendu). Testé
  // séparément dans LeagueSuggestions.test.tsx.
  useLeagueWindowSuggestions: () => ({ data: { instance: null, items: [] } }),
  useApplyLeagueWindowSuggestions: () => ({ mutate: vi.fn(), isPending: false }),
  // Section Club (P4-272 ③).
  useMatchConstraints: () => ({ ...rulesState, refetch: vi.fn() }),
  useMatchConstraintCoherence: () => ({ data: coherenceState.data }),
  useCreateMatchConstraint: () => ({ mutate: createRule, isPending: false }),
  useUpdateMatchConstraint: () => ({ mutate: updateRule, isPending: false }),
  useDeleteMatchConstraint: () => ({ mutate: deleteRule, isPending: false }),
  // Section Équipes (P4-272 ④) : équipes + gymnases + paliers pour les sélecteurs d'interdiction.
  useTeams: () => ({ ...teamsState, refetch: vi.fn() }),
  useVenues: () => ({ ...venuesState, refetch: vi.fn() }),
  usePriorityTiers: () => ({ ...tiersState, refetch: vi.fn() }),
  // Section Coachs (P4-272 ⑤) : entraîneurs pour le sélecteur d'indisponibilité.
  useCoaches: () => ({ ...coachesState, refetch: vi.fn() }),
  // ALIGN-19 : liens équipe⇄coach (rôle) pour repérer un coach uniquement adjoint.
  useTeamCoaches: () => ({ ...teamCoachesState, refetch: vi.fn() }),
}));

const window = (over: Partial<ClubLeagueWindow> = {}): ClubLeagueWindow => ({
  id: "w1",
  version: 1,
  league: "AURA",
  category: "Seniors",
  level: "REGIONAL",
  gender: null,
  dayOfWeek: 6,
  kickoffMin: "14:00",
  kickoffMax: "16:00",
  badge: null,
  ...over,
});

function openLigue(): void {
  renderWithProviders(<ConstraintsPage />, { route: "/matchs/contraintes?section=ligue" });
}

const rule = (over: Partial<MatchConstraint> = {}): MatchConstraint => ({
  id: "r1",
  version: 1,
  scope: "CLUB",
  scopeTargetId: null,
  ruleType: "HARD",
  daysOfWeek: [6],
  kickoffMin: null,
  kickoffMax: "21:00",
  venueId: null,
  ...over,
});

function openClub(): void {
  renderWithProviders(<ConstraintsPage />, { route: "/matchs/contraintes?section=club" });
}

function openEquipes(): void {
  renderWithProviders(<ConstraintsPage />, { route: "/matchs/contraintes?section=equipes" });
}

function openCoachs(): void {
  renderWithProviders(<ConstraintsPage />, { route: "/matchs/contraintes?section=coachs" });
}

const coachOf = (id: string, firstName: string, lastName: string): Coach => ({ id, firstName, lastName });

beforeEach(() => {
  createWindow.mockClear();
  updateWindow.mockClear();
  deleteWindow.mockClear();
  windowsState.data = [];
  windowsState.isError = false;
  createRule.mockClear();
  updateRule.mockClear();
  deleteRule.mockClear();
  rulesState.data = [];
  rulesState.isError = false;
  coherenceState.data = { byRule: [], byHabit: [] };
  meState.weekendAlternates = true;
  teamsState.data = [];
  teamsState.isError = false;
  venuesState.data = [];
  venuesState.isError = false;
  coachesState.data = [];
  coachesState.isError = false;
  teamCoachesState.data = [];
  teamCoachesState.isError = false;
});

describe("ConstraintsPage — section Ligue (P4-272 ①)", () => {
  it("affiche le bandeau « aucune règle fédérale » quand la copie est vide", () => {
    windowsState.data = [];
    openLigue();
    expect(screen.getByText(/Aucune fenêtre ligue — le placement n'applique plus de règle fédérale\./)).toBeInTheDocument();
  });

  it("affiche le badge « modifié »/« ajouté » calculé par le serveur (jamais redérivé)", () => {
    windowsState.data = [window({ id: "w1", category: "Seniors", badge: "modified" }), window({ id: "w2", category: "Poussins", badge: "added" })];
    openLigue();
    expect(screen.getByText("Modifié")).toBeInTheDocument();
    expect(screen.getByText("Ajouté")).toBeInTheDocument();
  });

  it("ajoute une fenêtre via l'API (genre « Tous » → null)", async () => {
    const user = userEvent.setup();
    windowsState.data = [];
    openLigue();

    // La ligne d'ajout (bordée en pointillés) porte le bouton « Ajouter ».
    const addRow = screen.getByRole("button", { name: "Ajouter" }).closest("div") as HTMLElement;
    fireEvent.change(within(addRow).getByLabelText("Catégorie"), { target: { value: "U13" } });
    await user.selectOptions(within(addRow).getByLabelText("Niveau"), "DEPARTEMENTAL");
    await user.selectOptions(within(addRow).getByLabelText("Jour"), "7");
    fireEvent.change(within(addRow).getByLabelText("De"), { target: { value: "10:00" } });
    fireEvent.change(within(addRow).getByLabelText("À"), { target: { value: "11:30" } });

    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    expect(createWindow).toHaveBeenCalledWith(
      { category: "U13", level: "DEPARTEMENTAL", gender: null, dayOfWeek: 7, kickoffMin: "10:00", kickoffMax: "11:30" },
      expect.anything(),
    );
  });

  it("enregistre une correction de fenêtre via l'API (PUT id + input) après avoir déplié la ligne", async () => {
    const user = userEvent.setup();
    windowsState.data = [window({ id: "w1", kickoffMax: "16:00" })];
    openLigue();

    // Au repos, la ligne est compacte (résumé + ✎) — on la déplie.
    await user.click(screen.getByRole("button", { name: "Modifier" }));
    // Le bouton reste inerte tant que rien n'a changé.
    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeDisabled();
    // La ligne d'édition rend AVANT la ligne d'ajout → le premier champ « À » est le sien.
    fireEvent.change(screen.getAllByLabelText("À")[0], { target: { value: "17:30" } });
    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeEnabled();
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(updateWindow).toHaveBeenCalledWith(
      {
        id: "w1",
        input: { category: "Seniors", level: "REGIONAL", gender: null, dayOfWeek: 6, kickoffMin: "14:00", kickoffMax: "17:30" },
      },
      expect.anything(),
    );
  });
});

describe("ConstraintsPage — section Club (P4-272 ③)", () => {
  it("indique l'absence de règle quand la liste est vide", () => {
    rulesState.data = [];
    openClub();
    expect(screen.getByText(/Aucune règle de club/)).toBeInTheDocument();
  });

  it("ajoute une règle « pas après 21:00 » le samedi via l'API (bornes nullables)", async () => {
    const user = userEvent.setup();
    rulesState.data = [];
    openClub();

    // La ligne d'ajout par défaut a samedi déjà coché ; on renseigne « Pas après ».
    fireEvent.change(screen.getByLabelText("Pas après (heure de fin)"), { target: { value: "21:00" } });
    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    expect(createRule).toHaveBeenCalledWith(
      { ruleType: "HARD", daysOfWeek: [6], kickoffMin: null, kickoffMax: "21:00" },
      expect.anything(),
    );
  });

  it("affiche l'alerte de cohérence (calculée serveur) sous la règle qui heurte un créneau idéal", () => {
    rulesState.data = [rule({ id: "r1", kickoffMax: "21:00" })];
    coherenceState.data = {
      byRule: [{ ruleId: "r1", habits: [{ teamId: "t1", teamName: "U13M", week: "A", dayOfWeek: 6, kickoff: "21:30" }] }],
      byHabit: [],
    };
    openClub();

    expect(screen.getByText("Cette règle heurte le créneau idéal des U13M (semaine A) : samedi 21:30.")).toBeInTheDocument();
  });

  it("omet « (semaine …) » quand le club n'alterne pas (weekendAlternates faux)", () => {
    meState.weekendAlternates = false;
    rulesState.data = [rule({ id: "r1", kickoffMax: "21:00" })];
    coherenceState.data = {
      byRule: [{ ruleId: "r1", habits: [{ teamId: "t2", teamName: "SM1", week: "A", dayOfWeek: 7, kickoff: "21:45" }] }],
      byHabit: [],
    };
    openClub();

    expect(screen.getByText("Cette règle heurte le créneau idéal des SM1 : dimanche 21:45.")).toBeInTheDocument();
  });

  it("ne montre PAS les interdictions de gymnase (scope TEAM) dans la section Club", () => {
    // Une interdiction TEAM et une règle CLUB partagent la même collection ; la section
    // Club ne rend QUE la règle CLUB (l'interdiction vit dans « Équipes »).
    rulesState.data = [rule({ id: "r1", scope: "CLUB", kickoffMax: "21:00" }), rule({ id: "b1", scope: "TEAM", scopeTargetId: "t1", venueId: "v1", daysOfWeek: [], kickoffMax: null })];
    openClub();
    // Au repos, chaque règle CLUB porte un ✎ « Modifier » ; l'interdiction TEAM n'ajoute
    // pas de seconde ligne (une seule règle CLUB → un seul « Modifier »).
    expect(screen.getAllByRole("button", { name: "Modifier" })).toHaveLength(1);
    expect(screen.queryByText(/Aucune règle de club/)).not.toBeInTheDocument();
  });
});

const teamOf = (id: string, name: string): Team => ({ id, name }) as Team;
const venueOf = (id: string, name: string): Venue => ({ id, name, color: null, externalLabels: [] }) as Venue;

describe("ConstraintsPage — section Équipes (P4-272 ④)", () => {
  it("indique l'absence d'interdiction quand la liste est vide", () => {
    rulesState.data = [];
    teamsState.data = [teamOf("t1", "SM1")];
    venuesState.data = [venueOf("v1", "Gymnase A")];
    openEquipes();
    expect(screen.getByText(/Aucune interdiction/)).toBeInTheDocument();
  });

  it("crée une interdiction de gymnase via l'API (scope TEAM, HARD, sans jour ni horaire)", async () => {
    const user = userEvent.setup();
    rulesState.data = [];
    teamsState.data = [teamOf("t1", "SM1")];
    venuesState.data = [venueOf("v1", "Gymnase A")];
    openEquipes();

    // Équipe et gymnase passent par TeamSelect/VenueSelect (Listbox) : on ouvre le trigger de la
    // ligne d'ajout puis on clique l'option (le panneau est porté sur `document.body`).
    const addRow = screen.getByRole("button", { name: "Interdire" }).closest("div") as HTMLElement;
    await user.click(within(addRow).getByRole("button", { name: /Équipe/ }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: "SM1" }));
    await user.click(within(addRow).getByRole("button", { name: /Gymnase interdit/ }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: "Gymnase A" }));
    await user.click(screen.getByRole("button", { name: "Interdire" }));

    expect(createRule).toHaveBeenCalledWith(
      { scope: "TEAM", scopeTargetId: "t1", venueId: "v1", ruleType: "HARD", daysOfWeek: [], kickoffMin: null, kickoffMax: null },
      expect.anything(),
    );
  });

  it("affiche une interdiction existante (équipe → gymnase) et la lève via l'API", async () => {
    const user = userEvent.setup();
    rulesState.data = [rule({ id: "b1", scope: "TEAM", scopeTargetId: "t1", venueId: "v1", daysOfWeek: [], kickoffMax: null })];
    teamsState.data = [teamOf("t1", "SM1")];
    venuesState.data = [venueOf("v1", "Gymnase A")];
    openEquipes();

    // « SM1 »/« Gymnase A » figurent AUSSI dans les <option> des sélecteurs d'ajout, et
    // « ne joue jamais à » dans le libellé de la ligne d'ajout : on cible la LIGNE
    // d'interdiction par son texte COMPLET (unique) et on vérifie son contenu.
    const banRow = screen.getByText((_, el) => "SPAN" === el?.tagName && "SM1 ne joue jamais à Gymnase A" === el.textContent);
    expect(banRow).toHaveTextContent("SM1");
    expect(banRow).toHaveTextContent("Gymnase A");

    await user.click(screen.getByRole("button", { name: "Supprimer" }));
    await user.click(screen.getByRole("button", { name: "Lever l'interdiction" }));

    expect(deleteRule).toHaveBeenCalledWith("b1");
  });
});

describe("ConstraintsPage — section Coachs (P4-272 ⑤)", () => {
  it("indique l'absence d'indisponibilité quand la liste est vide", () => {
    rulesState.data = [];
    coachesState.data = [coachOf("c1", "Anna", "Martin")];
    openCoachs();
    expect(screen.getByText(/Aucune indisponibilité/)).toBeInTheDocument();
  });

  it("crée une indisponibilité via l'API (scope COACH, toujours PREFERRED, bornes nullables)", async () => {
    const user = userEvent.setup();
    rulesState.data = [];
    coachesState.data = [coachOf("c1", "Anna", "Martin")];
    openCoachs();

    // La ligne d'ajout par défaut a samedi coché ; on choisit l'entraîneur et « Indisponible de ».
    // Disposition en DEUX rangées (uniformité PR 7/7) : l'entraîneur est en rangée 1 et « Indisponible de »
    // en rangée 2 — on scope au bloc d'ajout ENTIER (bordure pointillée), pas au seul parent du bouton.
    const addRow = screen.getByRole("button", { name: "Ajouter" }).closest(".border-dashed") as HTMLElement;
    await user.selectOptions(within(addRow).getByLabelText("Coach"), "c1");
    fireEvent.change(within(addRow).getByLabelText("Indisponible de (début de la plage)"), { target: { value: "14:00" } });
    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    expect(createRule).toHaveBeenCalledWith(
      { scope: "COACH", scopeTargetId: "c1", ruleType: "PREFERRED", daysOfWeek: [6], kickoffMin: "14:00", kickoffMax: null },
      expect.anything(),
    );
  });

  it("ALIGN-19 — une indisponibilité d'un coach UNIQUEMENT adjoint affiche la phrase « ne bloque jamais une séance »", () => {
    rulesState.data = [rule({ id: "u1", scope: "COACH", scopeTargetId: "c1", daysOfWeek: [6], kickoffMin: "14:00", kickoffMax: null })];
    coachesState.data = [coachOf("c1", "Anna", "Martin")];
    // c1 est ADJOINT sur son unique équipe (jamais principal).
    teamCoachesState.data = [{ id: "tc1", teamId: "t1", coachId: "c1", role: "ASSISTANT" }];
    openCoachs();
    expect(screen.getByText("un adjoint indisponible ne bloque jamais une séance")).toBeInTheDocument();
  });

  it("ALIGN-19 — un coach PRINCIPAL ne déclenche pas la phrase (son indisponibilité, elle, ferme)", () => {
    rulesState.data = [rule({ id: "u1", scope: "COACH", scopeTargetId: "c1", daysOfWeek: [6], kickoffMin: "14:00", kickoffMax: null })];
    coachesState.data = [coachOf("c1", "Anna", "Martin")];
    teamCoachesState.data = [{ id: "tc1", teamId: "t1", coachId: "c1", role: "MAIN" }];
    openCoachs();
    expect(screen.queryByText("un adjoint indisponible ne bloque jamais une séance")).not.toBeInTheDocument();
  });

  it("ne montre PAS les règles CLUB ni les interdictions TEAM dans la section Coachs", () => {
    // Les trois scopes partagent la même collection ; la section Coachs ne rend QUE le scope COACH.
    rulesState.data = [
      rule({ id: "r1", scope: "CLUB", kickoffMax: "21:00" }),
      rule({ id: "b1", scope: "TEAM", scopeTargetId: "t1", venueId: "v1", daysOfWeek: [], kickoffMax: null }),
      rule({ id: "u1", scope: "COACH", scopeTargetId: "c1", daysOfWeek: [6], kickoffMin: "14:00", kickoffMax: null }),
    ];
    coachesState.data = [coachOf("c1", "Anna", "Martin")];
    openCoachs();
    // Une seule indisponibilité (scope COACH) au repos → un seul « Modifier » (ni la règle CLUB ni l'interdiction TEAM).
    expect(screen.getAllByRole("button", { name: "Modifier" })).toHaveLength(1);
    expect(screen.queryByText(/Aucune indisponibilité/)).not.toBeInTheDocument();
  });

  it("résume une indisponibilité dans le bon sens (ALIGN-17) : « à partir de » et « jusqu'à »", () => {
    // kickoffMin seul = indisponible À PARTIR DE ; kickoffMax seul = indisponible JUSQU'À.
    // Le résumé NOMME la plage dans le sens de la disponibilité du coach (plus de « pas avant »).
    rulesState.data = [
      rule({ id: "u1", scope: "COACH", scopeTargetId: "c1", daysOfWeek: [6], kickoffMin: "14:00", kickoffMax: null }),
      rule({ id: "u2", scope: "COACH", scopeTargetId: "c1", daysOfWeek: [6], kickoffMin: null, kickoffMax: "14:00" }),
    ];
    coachesState.data = [coachOf("c1", "Anna", "Martin")];
    openCoachs();
    // Résumés EXACTS (préfixe coach + jour) — l'intro contient aussi « indisponible jusqu'à 14:00 ».
    expect(screen.getByText("Anna Martin · Sam · indisponible à partir de 14:00")).toBeInTheDocument();
    expect(screen.getByText("Anna Martin · Sam · indisponible jusqu'à 14:00")).toBeInTheDocument();
  });

  it("supprime une indisponibilité existante via l'API", async () => {
    const user = userEvent.setup();
    rulesState.data = [rule({ id: "u1", scope: "COACH", scopeTargetId: "c1", daysOfWeek: [6], kickoffMin: "14:00", kickoffMax: null })];
    coachesState.data = [coachOf("c1", "Anna", "Martin")];
    openCoachs();

    await user.click(screen.getByRole("button", { name: "Supprimer" }));
    await user.click(screen.getAllByRole("button", { name: "Supprimer" })[1]);

    expect(deleteRule).toHaveBeenCalledWith("u1");
  });
});
