import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Competition, Team } from "./api";
import { EntryDeadlinesEditor } from "./EntryDeadlinesEditor";

const setDeadlinesMutate = vi.fn();

// On pilote le hook PROD, jamais le réseau (patron TeamLinksSection/MatchSlotRotations).
vi.mock("./queries", () => ({
  useSetEntryDeadlines: () => ({ mutate: setDeadlinesMutate, isPending: false }),
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
});
