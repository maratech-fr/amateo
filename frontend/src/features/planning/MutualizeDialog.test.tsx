import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Slot, Team } from "./api";
import { MutualizeDialog } from "./MutualizeDialog";

const tiers = [{ id: 1, label: "S", name: "Fanion", color: null }];

const team = (id: string, name: string): Team => ({ id, name, sportCategoryId: "cat", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 1 });

const slot = (id: string, teamId: string, over: Partial<Slot> = {}): Slot => ({
  id,
  scheduleId: "sch",
  teamId,
  venueId: "v1",
  coachId: null,
  dayOfWeek: 2,
  startTime: "18:00:00",
  durationMinutes: 90,
  lockLevel: "NONE",
  lockOrigin: null,
  sharedTrainingBlockId: null,
  ...over,
});

const teams = [team("src", "U11F1"), team("j1", "U11M1"), team("j0", "U11F2"), team("j2", "U13M1")];

// U11F1 (source) + U11M1 (1 séance) + U13M1 (2 séances) placées ; U11F2 n'a AUCUNE séance.
const slots: Slot[] = [
  slot("anchor", "src"),
  slot("sj1", "j1"),
  slot("sj2a", "j2", { startTime: "18:00:00", venueId: "v1" }),
  slot("sj2b", "j2", { startTime: "20:00:00", venueId: "v2" }),
];

const venueName = (id: string): string => ({ v1: "Gymnase A", v2: "Gymnase B" })[id] ?? "Gymnase";
const teamName = (id: string): string => teams.find((t) => t.id === id)?.name ?? "?";

function renderDialog(over: { error?: string | null; busy?: boolean; onConfirm?: (b: unknown) => void; onClose?: () => void } = {}) {
  const onConfirm = over.onConfirm ?? vi.fn();
  renderWithProviders(
    <MutualizeDialog
      anchor={slots[0]}
      teams={teams}
      tiers={tiers}
      slots={slots}
      teamName={teamName}
      venueName={venueName}
      busy={over.busy ?? false}
      error={over.error ?? null}
      onClose={over.onClose ?? vi.fn()}
      onConfirm={onConfirm}
    />,
  );
  return { onConfirm };
}

const addTeam = async (name: string) => {
  await userEvent.click(screen.getByRole("button", { name: /Ajouter une équipe à mutualiser/ }));
  await userEvent.click(screen.getByRole("option", { name }));
};

describe("MutualizeDialog — lot 9 F3", () => {
  it("pré-coche la source VERROUILLÉE avec son motif, jamais proposée à l'ajout", async () => {
    renderDialog();
    expect(screen.getByText("U11F1")).toBeInTheDocument();
    expect(screen.getByText(/Équipe de référence/)).toBeInTheDocument();
    // La source ne figure pas dans le sélecteur d'ajout.
    await userEvent.click(screen.getByRole("button", { name: /Ajouter une équipe à mutualiser/ }));
    expect(screen.queryByRole("option", { name: "U11F1" })).toBeNull();
  });

  it("« Mutualiser » est désactivé tant qu'aucune équipe n'est rattachée, avec motif", () => {
    renderDialog();
    const confirm = screen.getByRole("button", { name: "Mutualiser" });
    expect(confirm).toHaveAttribute("aria-disabled", "true");
  });

  it("annonce l'activation d'une équipe à 0 séance (pastille « via mutualisation »)", async () => {
    renderDialog();
    await addTeam("U11F2");
    expect(screen.getByText(/n'a aucune séance : elle sera activée/)).toBeInTheDocument();
    expect(screen.getByText("via mutualisation")).toBeInTheDocument();
  });

  it("annonce la séance remplacée d'une équipe à séance unique (prise par défaut)", async () => {
    renderDialog();
    await addTeam("U11M1");
    expect(screen.getByText(/Sa séance du .*18:00.*Gymnase A.* sera remplacée/)).toBeInTheDocument();
  });

  it("fait CHOISIR la séance remplacée quand l'équipe en a plusieurs", async () => {
    renderDialog();
    await addTeam("U13M1");
    const select = screen.getByRole("combobox", { name: /Séance remplacée pour U13M1/ });
    const options = within(select).getAllByRole("option");
    expect(options).toHaveLength(2);
  });

  it("confirme avec teamIds + replacedSlotIds (séance unique prise par défaut), sans la source", async () => {
    const { onConfirm } = renderDialog();
    await addTeam("U11M1");
    await userEvent.click(screen.getByRole("button", { name: "Mutualiser" }));
    expect(onConfirm).toHaveBeenCalledWith({ teamIds: ["j1"], label: undefined, replacedSlotIds: ["sj1"] });
  });

  it("une équipe à 0 séance n'apporte AUCUN replacedSlotId", async () => {
    const { onConfirm } = renderDialog();
    await addTeam("U11F2");
    await userEvent.click(screen.getByRole("button", { name: "Mutualiser" }));
    expect(onConfirm).toHaveBeenCalledWith({ teamIds: ["j0"], label: undefined, replacedSlotIds: [] });
  });

  it("porte le nom du groupe (≤ 40) dans le corps", async () => {
    const { onConfirm } = renderDialog();
    await addTeam("U11M1");
    const nameField = screen.getByLabelText(/Nom du groupe/);
    expect(nameField).toHaveAttribute("maxLength", "40");
    await userEvent.type(nameField, "U11");
    await userEvent.click(screen.getByRole("button", { name: "Mutualiser" }));
    expect(onConfirm).toHaveBeenCalledWith({ teamIds: ["j1"], label: "U11", replacedSlotIds: ["sj1"] });
  });

  it("affiche un refus serveur TEL QUEL (422)", () => {
    renderDialog({ error: "La case visée est déjà occupée : elle ne peut pas accueillir la séance commune." });
    expect(screen.getByRole("alert")).toHaveTextContent(/déjà occupée/);
  });
});
