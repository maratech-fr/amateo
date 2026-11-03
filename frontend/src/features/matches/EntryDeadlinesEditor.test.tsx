import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Competition, Team } from "./api";
import { EntryDeadlinesEditor } from "./EntryDeadlinesEditor";

const setDeadlinesMutate = vi.fn();

// On pilote le hook PROD, jamais le réseau (patron TeamLinksSection/IdealSlots).
vi.mock("./queries", () => ({
  useSetEntryDeadlines: () => ({ mutate: setDeadlinesMutate, isPending: false }),
}));
// Chantier Membre (PR 2) : `useCanManage` piloté par cas (gestionnaire par défaut) ; le reste de la session reste réel.
const session = vi.hoisted(() => ({ canManage: true }));
vi.mock("@/shared/session/queries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/shared/session/queries")>()),
  useCanManage: () => session.canManage,
}));

const team = (id: string, name: string): Team => ({ id, name, sportCategoryId: "cat", level: null, gender: null, priorityTierId: 1, tierOrder: 0 });
const TEAMS: Team[] = [team("t1", "U13"), team("t2", "U15"), team("t3", "U11")];

const comp = (over: Partial<Competition> & { id: string }): Competition => ({
  teamId: "t1",
  name: "Compétition",
  competitionType: "CHAMPIONSHIP",
  entryDeadline: null,
  effectiveEntryDeadline: null,
  deadlineSource: null,
  ffbbCompetitionId: null,
  ...over,
});

// Trois provenances : club (date pleine, appariée), proposée (communautaire, appariée),
// aucune (non appariée).
const COMPETITIONS: Competition[] = [
  comp({ id: "c1", teamId: "t1", name: "Départemental U13", entryDeadline: "2026-09-10", effectiveEntryDeadline: "2026-09-10", deadlineSource: "club", ffbbCompetitionId: "ffbb-1" }),
  comp({ id: "c2", teamId: "t2", name: "Régional U15", entryDeadline: null, effectiveEntryDeadline: "2026-09-15", deadlineSource: "community", ffbbCompetitionId: "ffbb-2" }),
  comp({ id: "c3", teamId: "t3", name: "Amical U11", entryDeadline: null, effectiveEntryDeadline: null, deadlineSource: null, ffbbCompetitionId: null }),
];

beforeEach(() => {
  setDeadlinesMutate.mockReset();
});

// Deux compétitions sans échéance + une datée + une proposée : le filtre a de quoi trier.
const MIXED: Competition[] = [
  ...COMPETITIONS,
  comp({ id: "c4", teamId: "t3", name: "Coupe U11", entryDeadline: null, effectiveEntryDeadline: null, deadlineSource: null, ffbbCompetitionId: null }),
];
const DATED: Competition[] = COMPETITIONS.slice(0, 2);

const filterSegment = (name: RegExp) => screen.getByRole("button", { name });
const NO_DEADLINE = /Sans échéance/;
const ALL = /^Tous/;

describe("EntryDeadlinesEditor — filtre « Sans échéance | Tous »", () => {
  it("ouvre sur Tous, avec les deux compteurs ; une date proposée compte comme datée", () => {
    renderWithProviders(<EntryDeadlinesEditor competitions={MIXED} teams={TEAMS} />);
    expect(filterSegment(ALL)).toHaveAttribute("aria-pressed", "true");
    expect(filterSegment(ALL)).toHaveTextContent("4");
    expect(filterSegment(NO_DEADLINE)).toHaveAttribute("aria-pressed", "false");
    expect(filterSegment(NO_DEADLINE)).toHaveTextContent("2");
    expect(screen.getAllByRole("checkbox")).toHaveLength(4);
  });

  it("Sans échéance ne montre que les compétitions sans date, ni club ni proposée", async () => {
    const user = userEvent.setup();
    renderWithProviders(<EntryDeadlinesEditor competitions={MIXED} teams={TEAMS} />);
    await user.click(filterSegment(NO_DEADLINE));
    expect(screen.queryByRole("checkbox", { name: /Départemental U13/ })).toBeNull();
    expect(screen.queryByRole("checkbox", { name: /Régional U15/ })).toBeNull();
    expect(screen.getByRole("checkbox", { name: /Amical U11/ })).toBeInTheDocument();
    expect(screen.getByRole("checkbox", { name: /Coupe U11/ })).toBeInTheDocument();
  });

  it("Tout cocher n'agit que sur les lignes visibles : le POST n'envoie aucune ligne masquée", async () => {
    const user = userEvent.setup();
    renderWithProviders(<EntryDeadlinesEditor competitions={MIXED} teams={TEAMS} />);
    await user.click(filterSegment(NO_DEADLINE));
    await user.click(screen.getByRole("button", { name: "Tout cocher" }));
    await user.type(screen.getByLabelText(/Échéance à appliquer/), "2026-10-01");
    await user.click(screen.getByRole("button", { name: /Appliquer/ }));

    expect(setDeadlinesMutate).toHaveBeenCalledTimes(1);
    expect([...setDeadlinesMutate.mock.calls[0][0].competitionIds].sort()).toEqual(["c3", "c4"]);
  });

  it("changer de filtre vide la sélection", async () => {
    const user = userEvent.setup();
    renderWithProviders(<EntryDeadlinesEditor competitions={MIXED} teams={TEAMS} />);
    await user.click(screen.getByRole("checkbox", { name: /Départemental U13/ }));
    expect(screen.getByRole("button", { name: /Effacer l'échéance/ })).toBeEnabled();

    await user.click(filterSegment(NO_DEADLINE));
    expect(screen.getByRole("button", { name: /Effacer l'échéance/ })).toBeDisabled();

    await user.click(filterSegment(ALL));
    expect(screen.getByRole("checkbox", { name: /Départemental U13/ })).not.toBeChecked();
  });

  it("un rechargement qui date une ligne cochée la sort du compte et de l'envoi : rien de masqué ne part", async () => {
    const user = userEvent.setup();
    const { rerender } = render(<EntryDeadlinesEditor competitions={MIXED} teams={TEAMS} />);
    await user.click(filterSegment(NO_DEADLINE));
    await user.click(screen.getByRole("checkbox", { name: /Amical U11/ }));
    await user.click(screen.getByRole("checkbox", { name: /Coupe U11/ }));
    expect(screen.getByRole("button", { name: "Appliquer (2)" })).toBeInTheDocument();

    const reloaded = MIXED.map((c) => ("c3" === c.id ? { ...c, entryDeadline: "2026-09-20", effectiveEntryDeadline: "2026-09-20", deadlineSource: "club" as const } : c));
    rerender(<EntryDeadlinesEditor competitions={reloaded} teams={TEAMS} />);

    expect(screen.getByRole("button", { name: "Appliquer (1)" })).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /Effacer l'échéance/ }));
    expect(setDeadlinesMutate.mock.calls[0][0].competitionIds).toEqual(["c4"]);
  });

  it("toutes datées : le contrôle reste affiché et Sans échéance le dit, sans aucune ligne", async () => {
    const user = userEvent.setup();
    renderWithProviders(<EntryDeadlinesEditor competitions={DATED} teams={TEAMS} />);
    await user.click(filterSegment(NO_DEADLINE));

    expect(filterSegment(ALL)).toBeInTheDocument();
    expect(screen.getByText(/Toutes vos compétitions ont une échéance/)).toBeInTheDocument();
    expect(screen.queryAllByRole("checkbox")).toHaveLength(0);
    expect(screen.queryByText(/Aucune compétition/)).toBeNull();
  });
});

describe("EntryDeadlinesEditor — les échéances de saisie (RMM-6 PR-2)", () => {
  it("liste les 3 provenances DISTINCTES : club (date), proposée (communautaire), aucune", () => {
    renderWithProviders(<EntryDeadlinesEditor competitions={COMPETITIONS} teams={TEAMS} />);
    const c1 = screen.getByRole("checkbox", { name: /Départemental U13/ }).closest("li") as HTMLElement;
    const c2 = screen.getByRole("checkbox", { name: /Régional U15/ }).closest("li") as HTMLElement;
    const c3 = screen.getByRole("checkbox", { name: /Amical U11/ }).closest("li") as HTMLElement;
    // club : la date pleine, SANS marque « proposée ».
    expect(within(c1).getByText(/10 sept/)).toBeInTheDocument();
    expect(within(c1).queryByText(/proposée/)).not.toBeInTheDocument();
    // communautaire : la date pré-remplie, MARQUÉE « proposée ».
    expect(within(c2).getByText(/15 sept/)).toBeInTheDocument();
    expect(within(c2).getByText(/proposée/)).toBeInTheDocument();
    // aucune : dit explicitement l'absence.
    expect(within(c3).getByText(/aucune/i)).toBeInTheDocument();
  });

  it("le badge « partagée avec les autres clubs » n'apparaît que sur les compétitions APPARIÉES", () => {
    renderWithProviders(<EntryDeadlinesEditor competitions={COMPETITIONS} teams={TEAMS} />);
    // c1 + c2 sont appariées → badge ; c3 non appariée → pas de badge.
    expect(screen.getAllByText(/partagée avec les autres clubs/)).toHaveLength(2);
    const c3 = screen.getByRole("checkbox", { name: /Amical U11/ }).closest("li") as HTMLElement;
    expect(within(c3).queryByText(/partagée avec les autres clubs/)).not.toBeInTheDocument();
  });

  it("multi-sélection + date + Appliquer → UN POST bulk avec EXACTEMENT les ids cochés", async () => {
    const user = userEvent.setup();
    renderWithProviders(<EntryDeadlinesEditor competitions={COMPETITIONS} teams={TEAMS} />);
    await user.click(screen.getByRole("checkbox", { name: /Départemental U13/ }));
    await user.click(screen.getByRole("checkbox", { name: /Amical U11/ }));
    const dateInput = screen.getByLabelText(/Échéance/);
    await user.clear(dateInput);
    await user.type(dateInput, "2026-10-01");
    await user.click(screen.getByRole("button", { name: /Appliquer/ }));

    expect(setDeadlinesMutate).toHaveBeenCalledTimes(1);
    expect(setDeadlinesMutate.mock.calls[0][0]).toEqual({ competitionIds: ["c1", "c3"], deadline: "2026-10-01" });
  });

  it("Effacer → un POST avec deadline: null EXPLICITE (jamais implicite)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<EntryDeadlinesEditor competitions={COMPETITIONS} teams={TEAMS} />);
    await user.click(screen.getByRole("checkbox", { name: /Départemental U13/ }));
    await user.click(screen.getByRole("button", { name: /Effacer l'échéance/ }));

    expect(setDeadlinesMutate).toHaveBeenCalledTimes(1);
    expect(setDeadlinesMutate.mock.calls[0][0]).toEqual({ competitionIds: ["c1"], deadline: null });
    // FALSIFICATION : la clé deadline est bien PRÉSENTE et vaut null.
    expect(setDeadlinesMutate.mock.calls[0][0]).toHaveProperty("deadline", null);
  });

  it("une erreur (422/409) s'affiche LISIBLEMENT sous le formulaire (role=alert)", async () => {
    setDeadlinesMutate.mockImplementation((_input, opts?: { onError?: (e: unknown) => void }) => {
      opts?.onError?.(new Error("boom"));
    });
    const user = userEvent.setup();
    renderWithProviders(<EntryDeadlinesEditor competitions={COMPETITIONS} teams={TEAMS} />);
    await user.click(screen.getByRole("checkbox", { name: /Départemental U13/ }));
    await user.type(screen.getByLabelText(/Échéance/), "2026-10-01");
    await user.click(screen.getByRole("button", { name: /Appliquer/ }));

    expect(await screen.findByRole("alert")).toBeInTheDocument();
  });

  it("état vide : aucune compétition → message dédié, aucun contrôle bulk", () => {
    renderWithProviders(<EntryDeadlinesEditor competitions={[]} teams={TEAMS} />);
    expect(screen.getByText(/Aucune compétition/i)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Appliquer/ })).not.toBeInTheDocument();
  });

  it("Appliquer reste inerte tant qu'aucune ligne n'est cochée", async () => {
    renderWithProviders(<EntryDeadlinesEditor competitions={COMPETITIONS} teams={TEAMS} />);
    // Rien coché → le geste bulk est désactivé (ni date ni sélection).
    expect(screen.getByRole("button", { name: /Appliquer/ })).toBeDisabled();
    expect(screen.getByRole("button", { name: /Effacer l'échéance/ })).toBeDisabled();
  });

  /**
   * Chantier Membre (PR 2) : un Membre LIT les échéances de saisie (compétition, date) mais n'en
   * pose ni n'en efface aucune. Témoin gestionnaire : les cas « Appliquer » ci-dessus.
   */
  it("Membre : lit les échéances, sans « Appliquer » ni « Effacer »", () => {
    session.canManage = false;
    try {
      renderWithProviders(<EntryDeadlinesEditor competitions={MIXED} teams={TEAMS} />);
      expect(screen.getAllByText(/Départemental U13/).length).toBeGreaterThan(0);
      expect(screen.queryByRole("button", { name: /Appliquer/ })).not.toBeInTheDocument();
      expect(screen.queryByRole("button", { name: /Effacer l'échéance/ })).not.toBeInTheDocument();
    } finally {
      session.canManage = true;
    }
  });
});
