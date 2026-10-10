import { render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import type { Coach, Slot, Team, Venue } from "./api";
import type { Lookups } from "./lib/grid";
import { LocksPanel } from "./LocksPanel";

const lookups: Lookups = {
  teams: new Map<string, Team>([
    ["t1", { id: "t1", name: "U11", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }],
    ["t2", { id: "t2", name: "U13", sportCategoryId: "c", priorityTierId: 2, tierOrder: 0, sessionsPerWeek: 2 }],
  ]),
  venues: new Map<string, Venue>([
    ["v1", { id: "v1", name: "Gymnase Alpha", color: "#00aa00" }],
    ["v2", { id: "v2", name: "Gymnase Beta", color: "#0000aa" }],
  ]),
  coaches: new Map<string, Coach>(),
  teamCoach: new Map<string, string>(),
  teamPlayerCoaches: new Map<string, string[]>(),
};

const slot = (over: Partial<Slot>): Slot => ({
  id: "id",
  scheduleId: "s",
  teamId: "t1",
  venueId: "v1",
  coachId: null,
  dayOfWeek: 1,
  startTime: "18:00:00",
  durationMinutes: 90,
  lockLevel: "HARD",
  lockOrigin: "MANUAL",
  sharedTrainingBlockId: null,
  ...over,
});

// Deux verrous manuels, saisis dans le désordre pour prouver le tri jour+heure.
const locks: Slot[] = [
  slot({ id: "mardi", teamId: "t2", venueId: "v2", dayOfWeek: 2, startTime: "19:00:00" }),
  slot({ id: "lundi", teamId: "t1", venueId: "v1", dayOfWeek: 1, startTime: "18:00:00" }),
];

function renderPanel(over: Partial<Parameters<typeof LocksPanel>[0]> = {}) {
  return render(
    <LocksPanel
      locks={locks}
      lookups={lookups}
      selectedSlotId={null}
      onSelectSlot={vi.fn()}
      lensActive={false}
      onToggleLens={vi.fn()}
      onCollapse={vi.fn()}
      {...over}
    />,
  );
}

describe("LocksPanel — panneau latéral des verrous manuels (PR 3)", () => {
  it("liste chaque verrou : équipe, jour, heure, gymnase, trié par jour puis heure", () => {
    renderPanel();

    const items = screen.getAllByRole("listitem");
    expect(items).toHaveLength(2);
    // Tri : lundi 18:00 avant mardi 19:00, quel que soit l'ordre d'entrée.
    expect(within(items[0]).getByText("U11")).toBeInTheDocument();
    expect(within(items[0]).getByText(/Lun/)).toBeInTheDocument();
    expect(within(items[0]).getByText(/18:00/)).toBeInTheDocument();
    expect(within(items[0]).getByText("Gymnase Alpha")).toBeInTheDocument();
    expect(within(items[1]).getByText("U13")).toBeInTheDocument();
    expect(within(items[1]).getByText(/Mar/)).toBeInTheDocument();
    expect(within(items[1]).getByText("Gymnase Beta")).toBeInTheDocument();
  });

  it("l'en-tête annonce le nombre de verrous posés à la main (preuve du travail)", () => {
    renderPanel();
    expect(screen.getByText(/2 verrous posés à la main/i)).toBeInTheDocument();
  });

  it("cliquer une entrée sélectionne son créneau (onSelectSlot)", () => {
    const onSelectSlot = vi.fn();
    renderPanel({ onSelectSlot });

    screen.getByRole("button", { name: /U11/ }).click();
    expect(onSelectSlot).toHaveBeenCalledWith("lundi");
  });

  it("le repli suit l'affordance des diagnostics (retour fondateur : une seule manière)", () => {
    const onCollapse = vi.fn();
    renderPanel({ onCollapse });

    // MÊME libellé/patron que « Réduire les diagnostics » — pas de bouton « Fermer ».
    expect(screen.queryByRole("button", { name: /fermer/i })).not.toBeInTheDocument();
    screen.getByRole("button", { name: /réduire les verrous manuels/i }).click();
    expect(onCollapse).toHaveBeenCalled();
  });

  it("le toggle « Voir sur la grille » déclenche onToggleLens", () => {
    const onToggleLens = vi.fn();
    renderPanel({ onToggleLens });

    screen.getByRole("button", { name: /voir sur la grille/i }).click();
    expect(onToggleLens).toHaveBeenCalled();
  });

  it("affiche la légende (3 catégories) quand la lentille est active", () => {
    renderPanel({ lensActive: true });

    // Trois lignes de légende : Verrou manuel / Réservation / Origine inconnue.
    expect(screen.getByText(/Verrou manuel/i)).toBeInTheDocument();
    expect(screen.getByText(/Réservation/i)).toBeInTheDocument();
    expect(screen.getByText(/inconnue/i)).toBeInTheDocument();
  });

  it("ne montre pas la légende tant que la lentille est éteinte", () => {
    renderPanel({ lensActive: false });
    expect(screen.queryByText(/Origine inconnue/i)).not.toBeInTheDocument();
  });

  it("passe l'audit d'accessibilité (liste navigable, aria-labels)", async () => {
    const { container } = renderPanel({ lensActive: true });
    expect(await axe(container)).toHaveNoViolations();
  });
});
