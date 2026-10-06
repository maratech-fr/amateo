import { fireEvent, render, screen, within } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Coach, CoachPlayerMembership, Team } from "../api";
import { useWizardStore } from "../store";

const updateMut = vi.fn();
const createMut = vi.fn();

const coachesState: { data: Coach[] } = { data: [] };
const teamsState: { data: Team[] } = { data: [] };
const coachPlayersState: { data: CoachPlayerMembership[] } = { data: [] };

vi.mock("../queries", () => ({
  useWizardCoaches: () => ({ data: coachesState.data }),
  useWizardTeams: () => ({ data: teamsState.data }),
  usePriorityTiers: () => ({ data: [] }),
  useWizardTeamCoaches: () => ({ data: [] }),
  useWizardCoachPlayers: () => ({ data: coachPlayersState.data }),
  useCreateCoach: () => ({ mutate: createMut, isPending: false }),
  useUpdateCoach: () => ({ mutate: updateMut, isPending: false }),
  useDeleteCoach: () => ({ mutate: vi.fn() }),
  useCreateTeamCoach: () => ({ mutate: vi.fn() }),
  useDeleteTeamCoach: () => ({ mutate: vi.fn() }),
  useCreateCoachPlayer: () => ({ mutate: vi.fn() }),
  useDeleteCoachPlayer: () => ({ mutate: vi.fn() }),
  useDeletionImpact: () => ({ data: null, isPending: false, isError: false }),
}));

// P4-269 — l'étape Coachs lit le radar « personne à deux endroits » du planning EN VIGUEUR.
const placedConflictsState: { data: { conflicts: unknown[] } | undefined } = { data: { conflicts: [] } };
vi.mock("@/features/planning/queries", () => ({
  usePlacedConflicts: () => ({ data: placedConflictsState.data }),
}));

import { CoachesStep } from "./CoachesStep";

const coach = (over: Partial<Coach> & Pick<Coach, "id" | "firstName">): Coach => ({
  lastName: "Martin",
  email: null,
  isEmployee: false,
  isActive: true,
  maxDaysOverride: null,
  isVehicled: false,
  gender: "UNSPECIFIED",
  ...over,
});

const team = (over: Partial<Team> & Pick<Team, "id" | "name">): Team => ({
  sportCategoryId: "s1",
  priorityTierId: 1,
  tierOrder: 0,
  gender: null,
  level: null,
  sessionsPerWeek: 1,
  isActive: true,
  ...over,
});

const playerLink = (over: Partial<CoachPlayerMembership> & Pick<CoachPlayerMembership, "coachId">): CoachPlayerMembership => ({
  id: `pl-${over.coachId}`,
  teamId: "t1",
  isActive: true,
  ...over,
});

/**
 * Rendu avec un wrapper STABLE (même QueryClient) afin que `rerender()` conserve l'état React
 * (l'édition en cours) : la carte gelée dans sa section se prouve en changeant la donnée
 * `coachPlayers` PENDANT l'édition. Les queries sont mockées, aucun contexte Router requis.
 */
function renderEditor() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const tree = () => (
    <QueryClientProvider client={client}>
      <CoachesStep />
    </QueryClientProvider>
  );
  const utils = render(tree());
  return { ...utils, rerender: () => utils.rerender(tree()) };
}

const cardOf = (coachId: string) => document.querySelector(`[data-coach-id="${coachId}"]`) as HTMLElement;
const sectionLabelOf = (coachId: string) => cardOf(coachId)?.closest("section")?.querySelector("h3")?.textContent;

beforeEach(() => {
  updateMut.mockClear();
  createMut.mockReset();
  coachesState.data = [];
  teamsState.data = [];
  coachPlayersState.data = [];
  placedConflictsState.data = { conflicts: [] };
  useWizardStore.setState({ mode: "season" });
});

describe("CoachesStep — conflit « personne à deux endroits » du planning en vigueur (P4-269)", () => {
  const aConflict = {
    personId: "c1",
    personName: "Anna Dupont",
    dayOfWeek: 2,
    first: { teamId: "tA", teamName: "U13F", venueId: "vB", venueName: "Gymnase B", startTime: "18:00" },
    second: { teamId: "tB", teamName: "U11M1", venueId: "vA", venueName: "Gymnase A", startTime: "18:00" },
  };

  it("affiche l'encart quand le backend signale un conflit", () => {
    placedConflictsState.data = { conflicts: [aConflict] };
    renderWithProviders(<CoachesStep />);
    expect(
      screen.getByText("Anna Dupont est à deux endroits le mardi à 18:00 (U13F · Gymnase B / U11M1 · Gymnase A)"),
    ).toBeInTheDocument();
  });

  it("n'affiche aucun encart quand le backend n'en signale aucun", () => {
    placedConflictsState.data = { conflicts: [] };
    renderWithProviders(<CoachesStep />);
    expect(screen.queryByText(/est à deux endroits/)).not.toBeInTheDocument();
  });
});

describe("CoachesStep — statut véhiculé", () => {
  it("un coach non véhiculé : la case « Véhiculé » est décochée par défaut", () => {
    coachesState.data = [coach({ id: "c1", firstName: "Léa", isVehicled: false })];
    renderWithProviders(<CoachesStep />);
    fireEvent.click(screen.getByRole("button", { name: "Éditer le coach" }));

    const vehicled = screen.getByRole("checkbox", { name: "Véhiculé·e" });
    expect(vehicled).not.toBeChecked();
  });

  it("cocher « Véhiculé » PATCHe le coach avec isVehicled=true (mute la prod, pas le mock)", () => {
    coachesState.data = [coach({ id: "c1", firstName: "Léa", isVehicled: false })];
    renderWithProviders(<CoachesStep />);
    fireEvent.click(screen.getByRole("button", { name: "Éditer le coach" }));

    fireEvent.click(screen.getByRole("checkbox", { name: "Véhiculé·e" }));

    expect(updateMut).toHaveBeenCalledTimes(1);
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ id: "c1", body: expect.objectContaining({ isVehicled: true }) }));
  });

  it("un coach déjà véhiculé : la case est cochée, la décocher envoie isVehicled=false", () => {
    coachesState.data = [coach({ id: "c1", firstName: "Léa", isVehicled: true })];
    renderWithProviders(<CoachesStep />);
    fireEvent.click(screen.getByRole("button", { name: "Éditer le coach" }));

    const vehicled = screen.getByRole("checkbox", { name: "Véhiculé·e" });
    expect(vehicled).toBeChecked();

    fireEvent.click(vehicled);
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ id: "c1", body: expect.objectContaining({ isVehicled: false }) }));
  });
});

describe("CoachesStep — pastilles read-only (P4-178 : StatusPill accent, repli AA)", () => {
  it("« Salarié » et le plafond préféré s'affichent SANS `text-accent` sur le texte (repli AA)", () => {
    coachesState.data = [coach({ id: "c1", firstName: "Léa", isEmployee: true, maxDaysOverride: 3 })];
    renderWithProviders(<CoachesStep />);

    // « Salarié » apparaît aussi comme label de case dans le formulaire d'ajout : on cible le span-pastille.
    const salarie = screen.getByText("Salarié·e", { selector: "span" });
    expect(salarie).toBeInTheDocument();
    expect(salarie).not.toHaveClass("text-accent");

    const plafond = screen.getByText(/j\/sem/);
    expect(plafond).toBeInTheDocument();
    expect(plafond).not.toHaveClass("text-accent");
    // Le `title` explicatif du plafond survit à la migration.
    expect(plafond).toHaveAttribute("title", expect.stringContaining("Plafond préféré"));
  });
});

describe("CoachesStep — la carte ne change pas de section pendant l'édition", () => {
  it("lier comme joueur pendant l'édition garde la carte dans sa section ; « Terminé » reclasse", () => {
    coachesState.data = [coach({ id: "c1", firstName: "Mara", lastName: "Bénévole" })];
    coachPlayersState.data = [];
    const utils = renderEditor();

    // Départ : Mara est en « Bénévoles » (aucun lien joueur).
    expect(sectionLabelOf("c1")).toBe("Bénévoles");

    fireEvent.click(within(cardOf("c1")).getByRole("button", { name: "Éditer le coach" }));

    // Le serveur enregistre le lien joueur (refetch) ⇒ Mara devient « coach-joueur » côté données…
    coachPlayersState.data = [playerLink({ coachId: "c1" })];
    utils.rerender();

    // …mais la carte est en édition : elle RESTE dans « Bénévoles », toujours ouverte.
    expect(sectionLabelOf("c1")).toBe("Bénévoles");
    expect(within(cardOf("c1")).getByRole("button", { name: "Terminer l'édition" })).toBeInTheDocument();

    // « Terminé » ⇒ reclassement en « Coachs-joueurs ».
    fireEvent.click(within(cardOf("c1")).getByRole("button", { name: "Terminer l'édition" }));
    expect(sectionLabelOf("c1")).toBe("Coachs-joueurs");
  });
});

describe("CoachesStep — un coach créé s'ouvre en édition", () => {
  it("créer un coach l'ouvre directement en édition et met le focus sur le contrôle de liaison", () => {
    teamsState.data = [team({ id: "t1", name: "U13M1" })];
    createMut.mockImplementation((body: { firstName: string }, opts?: { onSuccess?: (c: Coach) => void }) => {
      const created = coach({ id: "new1", firstName: body.firstName });
      coachesState.data = [...coachesState.data, created];
      opts?.onSuccess?.(created);
    });
    renderWithProviders(<CoachesStep />);

    fireEvent.change(screen.getByRole("textbox", { name: "Prénom" }), { target: { value: "Zoé" } });
    fireEvent.click(screen.getByRole("button", { name: "Ajouter le coach" }));

    // La carte du coach créé existe et est en édition (bouton « Terminer l'édition »).
    const card = cardOf("new1");
    expect(card).not.toBeNull();
    expect(within(card).getByRole("button", { name: "Terminer l'édition" })).toBeInTheDocument();
    // Le focus se pose sur le 1ᵉʳ contrôle de liaison (le sélecteur d'équipe).
    expect(within(card).getByRole("button", { name: /Équipe/ })).toHaveFocus();
  });
});

describe("CoachesStep — recherche par nom", () => {
  const searchBox = () => screen.getByRole("searchbox", { name: "Rechercher un coach" });

  beforeEach(() => {
    coachesState.data = [
      coach({ id: "c1", firstName: "Léa", lastName: "Martin" }),
      coach({ id: "c2", firstName: "Noé", lastName: "Bernard" }),
    ];
  });

  it("filtre par nom, insensible à la casse et aux accents", () => {
    renderWithProviders(<CoachesStep />);

    fireEvent.change(searchBox(), { target: { value: "LÉA" } });
    expect(screen.getByText("Léa Martin")).toBeInTheDocument();
    expect(screen.queryByText("Noé Bernard")).not.toBeInTheDocument();

    // Recherche sans accent : « noe » retrouve « Noé ».
    fireEvent.change(searchBox(), { target: { value: "noe" } });
    expect(screen.getByText("Noé Bernard")).toBeInTheDocument();
    expect(screen.queryByText("Léa Martin")).not.toBeInTheDocument();
  });

  it("une carte en édition reste visible même si elle ne correspond plus au filtre", () => {
    renderWithProviders(<CoachesStep />);

    fireEvent.click(within(cardOf("c1")).getByRole("button", { name: "Éditer le coach" }));
    // « noe » ne matche pas Léa, mais sa carte est en édition : elle reste rendue.
    fireEvent.change(searchBox(), { target: { value: "noe" } });
    expect(cardOf("c1")).not.toBeNull();
    expect(cardOf("c2")).not.toBeNull();
  });

  it("affiche un état vide « Aucun coach ne correspond » quand rien ne matche", () => {
    renderWithProviders(<CoachesStep />);

    fireEvent.change(searchBox(), { target: { value: "zzz" } });
    expect(screen.getByText("Aucun coach ne correspond.")).toBeInTheDocument();
    expect(cardOf("c1")).toBeNull();
    expect(cardOf("c2")).toBeNull();
  });
});
