import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { Coach, Conflict, Team } from "./api";
import { ConflictLine } from "./ConflictLine";

const teams = new Map<string, Team>([
  ["team-1", { id: "team-1", name: "U13", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
  ["team-2", { id: "team-2", name: "Seniors", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
]);
const coaches = new Map<string, Coach>();

function side(fixtureId: string, teamId: string, matchDate = "2026-10-03") {
  return { fixtureId, teamId, homeAway: "HOME" as const, matchDate, kickoffTime: "16:00", windowStart: "", windowEnd: "" };
}

const overlap: Conflict = { type: "VENUE_OVERLAP", severity: 1, resolution: null, fingerprint: "fp-1", left: side("fx-1", "team-1"), right: side("fx-2", "team-2") };

function renderLine(props: Partial<React.ComponentProps<typeof ConflictLine>> = {}) {
  return render(
    <ul>
      <ConflictLine conflict={overlap} teams={teams} coaches={coaches} tone="destructive" isNew={false} {...props} />
    </ul>,
  );
}

describe("ConflictLine (extrait du radar, avec slot trailing)", () => {
  it("rend le titre et la phrase du conflit", () => {
    renderLine();
    expect(screen.getByText("Deux matchs sur le même créneau")).toBeInTheDocument();
    expect(screen.getByText(/U13 et Seniors/)).toBeInTheDocument();
  });

  it("porte la tonalité de gravité sur le <li>", () => {
    const { container } = renderLine({ tone: "destructive" });
    expect(container.querySelector("li")).toHaveClass("border-destructive/40");
  });

  it("montre la chip « Nouveau » quand isNew, jamais sinon", () => {
    renderLine({ isNew: true });
    expect(screen.getByText("Nouveau")).toBeInTheDocument();
  });

  it("sans isNew : aucune chip « Nouveau »", () => {
    renderLine({ isNew: false });
    expect(screen.queryByText("Nouveau")).not.toBeInTheDocument();
  });

  it("rend le slot trailing quand il est fourni ; rien quand il est absent", () => {
    renderLine({ trailing: <button type="button">Voir la semaine</button> });
    expect(screen.getByRole("button", { name: "Voir la semaine" })).toBeInTheDocument();
  });

  it("sans trailing : aucun bouton", () => {
    renderLine();
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("rend le slot below sous la ligne (P4-207)", () => {
    renderLine({ below: <p>Note de traitement</p> });
    expect(screen.getByText("Note de traitement")).toBeInTheDocument();
  });

  it("porte aria-busy sur le <li> pendant une écriture (P4-207)", () => {
    const { container } = renderLine({ ariaBusy: true });
    expect(container.querySelector("li")).toHaveAttribute("aria-busy", "true");
  });

  it("sans ariaBusy : le <li> ne porte pas aria-busy", () => {
    const { container } = renderLine();
    expect(container.querySelector("li")).not.toHaveAttribute("aria-busy");
  });
});

describe("ConflictLine — personne en double, rôle PAR CÔTÉ (une personne = ses équipes)", () => {
  const coachesMap = new Map<string, Coach>([["p-1", { id: "p-1", firstName: "Mara", lastName: "MB" }]]);

  function personSide(fixtureId: string, teamId: string, role: "MAIN" | "ASSISTANT" | "PLAYER") {
    return { ...side(fixtureId, teamId), role };
  }

  function renderPerson(conflict: Conflict) {
    return render(
      <ul>
        <ConflictLine conflict={conflict} teams={teams} coaches={coachesMap} tone="warning" isNew={false} />
      </ul>,
    );
  }

  it("le titre est le NOM seul — plus de suffixe « (assistant d'un côté) »", () => {
    renderPerson({
      type: "MATCH_MATCH",
      severity: 5,
      resolution: null,
      coachId: "p-1",
      coachRole: "ASSISTANT",
      left: personSide("fx-1", "team-1", "ASSISTANT"),
      right: personSide("fx-2", "team-2", "MAIN"),
    });
    expect(screen.getByText("Mara MB")).toBeInTheDocument();
    expect(screen.queryByText(/assistant d'un côté/)).not.toBeInTheDocument();
  });

  it("MATCH_MATCH tout MAIN : le résumé reste NU (« U13 et Seniors »)", () => {
    renderPerson({
      type: "MATCH_MATCH",
      severity: 3,
      resolution: null,
      coachId: "p-1",
      coachRole: "MAIN",
      left: personSide("fx-1", "team-1", "MAIN"),
      right: personSide("fx-2", "team-2", "MAIN"),
    });
    expect(screen.getByText(/^U13 et Seniors —/)).toBeInTheDocument();
  });

  it("MATCH_MATCH coach×joueuse : CHAQUE côté est annoté (« U13 (coach) et Seniors (joueur) »)", () => {
    renderPerson({
      type: "MATCH_MATCH",
      severity: 3,
      resolution: null,
      coachId: "p-1",
      coachRole: "PLAYER",
      left: personSide("fx-1", "team-1", "MAIN"),
      right: personSide("fx-2", "team-2", "PLAYER"),
    });
    expect(screen.getByText(/U13 \(coach\) et Seniors \(joueur\) —/)).toBeInTheDocument();
  });

  it("MATCH_TRAINING : le match d'abord, annoté (« Match Seniors (joueur) × entraînement U13 (coach) »)", () => {
    renderPerson({
      type: "MATCH_TRAINING",
      severity: 5,
      resolution: null,
      coachId: "p-1",
      coachRole: "PLAYER",
      fixture: { ...side("fx-1", "team-2"), role: "PLAYER" },
      training: { slotTemplateId: "t", scheduleId: "sc", teamId: "team-1", venueId: "v", dayOfWeek: 3, startTime: "18:00", durationMinutes: 90, role: "MAIN", windowStart: "", windowEnd: "" },
    });
    expect(screen.getByText("Match Seniors (joueur) × entraînement U13 (coach)")).toBeInTheDocument();
  });
});
