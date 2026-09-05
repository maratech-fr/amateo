import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { Coach, Conflict, Team } from "./api";
import { ConflictRadar } from "./ConflictRadar";

const teams = new Map<string, Team>([
  ["team-1", { id: "team-1", name: "U13", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
  ["team-2", { id: "team-2", name: "Seniors", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
]);
const coaches = new Map<string, Coach>();

function side(fixtureId: string, teamId: string, matchDate: string) {
  return { fixtureId, teamId, homeAway: "HOME" as const, matchDate, kickoffTime: "16:00", windowStart: "", windowEnd: "" };
}

// Two VENUE_OVERLAP conflicts (severity 1, unfolded) with stable fingerprints.
function conflictsFixture(): Conflict[] {
  return [
    {
      type: "VENUE_OVERLAP",
      severity: 1,
      fingerprint: "fp-new",
      left: side("fx-1", "team-1", "2026-10-03"),
      right: side("fx-2", "team-2", "2026-10-03"),
    },
    {
      type: "VENUE_OVERLAP",
      severity: 1,
      fingerprint: "fp-old",
      left: side("fx-3", "team-2", "2026-10-04"),
      right: side("fx-4", "team-1", "2026-10-04"),
    },
  ];
}

describe("ConflictRadar — chip « Nouveau » (RMM-3, ornement pur)", () => {
  it("un conflit dont l'empreinte ∈ la liste porte une chip « Nouveau »", () => {
    render(<ConflictRadar conflicts={conflictsFixture()} teams={teams} coaches={coaches} newFingerprints={new Set(["fp-new"])} />);
    const chips = screen.getAllByText("Nouveau");
    expect(chips).toHaveLength(1);
    // P4-178 — repli AA : StatusPill accent, le texte reste `text-foreground` (l'icône porte `text-accent`).
    expect(chips[0]).not.toHaveClass("text-accent");
  });

  it("un conflit dont l'empreinte ∉ la liste n'a PAS de chip (falsification)", () => {
    render(<ConflictRadar conflicts={conflictsFixture()} teams={teams} coaches={coaches} newFingerprints={new Set(["fp-absent"])} />);
    expect(screen.queryByText("Nouveau")).not.toBeInTheDocument();
  });

  it("delta absent (aucune empreinte) → aucune chip, le radar reste intact", () => {
    render(<ConflictRadar conflicts={conflictsFixture()} teams={teams} coaches={coaches} />);
    expect(screen.queryByText("Nouveau")).not.toBeInTheDocument();
    // Le radar rend toujours ses conflits (les deux collisions).
    expect(screen.getAllByText("Deux matchs sur le même créneau")).toHaveLength(2);
  });
});

describe("ConflictRadar — le titre dit « Conflits » (mot unique, UXC-18)", () => {
  it("intitule la carte « Conflits », jamais « Diagnostic »", () => {
    render(<ConflictRadar conflicts={conflictsFixture()} teams={teams} coaches={coaches} />);
    // Le titre est un <h2> (CardTitle). Falsification : remettre « Diagnostic » casse ce test.
    expect(screen.getByRole("heading", { level: 2, name: /Conflits/ })).toBeInTheDocument();
    expect(screen.queryByText("Diagnostic")).toBeNull();
  });
});
