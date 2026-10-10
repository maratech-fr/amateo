import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { ClubViewTable } from "./ClubViewTable";
import type { ClubViewModel } from "./lib/clubView";

const entry = (over: Partial<ClubViewModel["groups"][number]["rows"][number]["cells"][number]["entries"][number]> = {}) => ({
  slotId: "s1",
  venueLabel: "Matéo",
  venueColor: "#ff0000",
  startLabel: "18:00",
  endLabel: "19:30",
  locked: false,
  lockOrigin: null,
  sharedTrainingBlockId: null,
  ...over,
});

const model = (): ClubViewModel => ({
  dayColumns: [
    { day: 1, label: "Lundi" },
    { day: 4, label: "Jeudi" },
  ],
  groups: [
    {
      label: "S · Fanion",
      rows: [
        {
          teamId: "t1",
          teamLabel: "SM1",
          sessionCount: 1,
          cells: [
            { day: 1, entries: [entry()] },
            { day: 4, entries: [] },
          ],
        },
        { teamId: "t2", teamLabel: "U13 F1", sessionCount: 0, cells: [{ day: 1, entries: [] }, { day: 4, entries: [] }] },
      ],
    },
  ],
});

describe("ClubViewTable — la vue par club à l'écran (P3-20)", () => {
  it("rend la matrice équipes × jours : gymnase + heure en cellule, trou signalé, pas de coach", () => {
    render(<ClubViewTable model={model()} selectedSlotId={null} onSelectSlot={vi.fn()} />);

    expect(screen.getByRole("columnheader", { name: "Lundi" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Matéo/ })).toHaveTextContent("18:00");
    // Une équipe sans séance garde sa ligne ET le dit — le trou ne se lit pas en creux.
    expect(screen.getByRole("rowheader", { name: /U13 F1/ })).toHaveTextContent(/aucune séance/i);
  });

  it("porte les MÊMES gestes que la grille : clic → détail, cadenas frère qui ne sélectionne pas", async () => {
    const onSelectSlot = vi.fn();
    const onToggleLock = vi.fn();
    const { container } = render(<ClubViewTable model={model()} selectedSlotId={null} onSelectSlot={onSelectSlot} onToggleLock={onToggleLock} />);

    await userEvent.click(screen.getByRole("button", { name: /Matéo/ }));
    expect(onSelectSlot).toHaveBeenCalledWith("s1");

    await userEvent.click(screen.getByRole("button", { name: /Verrouiller SM1/ }));
    expect(onToggleLock).toHaveBeenCalledWith("s1");
    expect(onSelectSlot).toHaveBeenCalledTimes(1);
    // Cible du clic-diagnostic (scroll + surlignage), comme sur la grille.
    expect(container.querySelector('[data-slot-id="s1"]')).not.toBeNull();
  });

  it("en mode cible : une séance est désignable, et la limite des cases vides est DITE", async () => {
    const onPickTarget = vi.fn();
    render(
      <ClubViewTable model={model()} selectedSlotId={null} onSelectSlot={vi.fn()} targetMode={{ active: true, sourceSlotId: "other", variant: "move" }} onPickTarget={onPickTarget} />,
    );

    // Une case (équipe, jour) vide ne porte ni gymnase ni heure : ce n'est pas une destination,
    // et l'écran le dit au lieu de laisser cliquer dans le vide.
    expect(screen.getByRole("status")).toHaveTextContent(/créneau libre/i);
    await userEvent.click(screen.getByRole("button", { name: /Matéo/ }));
    expect(onPickTarget).toHaveBeenCalledWith("s1");
  });

  it("le surlignage de conflit PRIME sur la lentille de verrous", () => {
    const two = model();
    two.groups[0]!.rows[0]!.cells[1]!.entries.push(entry({ slotId: "s2", venueLabel: "Debarros", venueColor: null, locked: true, lockOrigin: "MANUAL" }));
    const { container } = render(<ClubViewTable model={two} selectedSlotId={null} onSelectSlot={vi.fn()} highlightSlotIds={new Set(["s1"])} lockLens />);

    expect(container.querySelector('[data-slot-id="s2"]')?.className).toContain("opacity-30");
    expect(container.querySelector("[data-lens]")).toBeNull();
  });
});
