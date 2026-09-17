import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import type { ReactElement } from "react";
import { describe, expect, it, vi } from "vitest";

import type { Coach, Conflict, Team, Venue } from "./api";
import { ConflictRadar } from "./ConflictRadar";

// Le radar lit `me` (rôle) pour décider s'il propose l'éditeur de traitement (P4-207).
// Ici : membre (aucun rôle) → pastilles/éditeur absents, le radar rend ses lignes nues.
vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: undefined }) }));

/** Le radar monte des mutations react-query (l'éditeur de traitement) → il lui faut un client. */
function renderRadar(ui: ReactElement) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>);
}

const teams = new Map<string, Team>([
  ["team-1", { id: "team-1", name: "U13", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
  ["team-2", { id: "team-2", name: "Seniors", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
]);
const coaches = new Map<string, Coach>();
const venues = new Map<string, Venue>();

function side(fixtureId: string, teamId: string, matchDate: string) {
  return { fixtureId, teamId, homeAway: "HOME" as const, matchDate, kickoffTime: "16:00", windowStart: "", windowEnd: "" };
}

// Two VENUE_OVERLAP conflicts (severity 1, unfolded) with stable fingerprints.
function conflictsFixture(): Conflict[] {
  return [
    {
      type: "VENUE_OVERLAP",
      severity: 1, resolution: null,
      fingerprint: "fp-new",
      left: side("fx-1", "team-1", "2026-10-03"),
      right: side("fx-2", "team-2", "2026-10-03"),
    },
    {
      type: "VENUE_OVERLAP",
      severity: 1, resolution: null,
      fingerprint: "fp-old",
      left: side("fx-3", "team-2", "2026-10-04"),
      right: side("fx-4", "team-1", "2026-10-04"),
    },
  ];
}

describe("ConflictRadar — chip « Nouveau » (RMM-3, ornement pur)", () => {
  it("un conflit dont l'empreinte ∈ la liste porte une chip « Nouveau »", () => {
    renderRadar(<ConflictRadar conflicts={conflictsFixture()} teams={teams} coaches={coaches} venues={venues} newFingerprints={new Set(["fp-new"])} />);
    const chips = screen.getAllByText("Nouveau");
    expect(chips).toHaveLength(1);
    // P4-178 — repli AA : StatusPill accent, le texte reste `text-foreground` (l'icône porte `text-accent`).
    expect(chips[0]).not.toHaveClass("text-accent");
  });

  it("un conflit dont l'empreinte ∉ la liste n'a PAS de chip (falsification)", () => {
    renderRadar(<ConflictRadar conflicts={conflictsFixture()} teams={teams} coaches={coaches} venues={venues} newFingerprints={new Set(["fp-absent"])} />);
    expect(screen.queryByText("Nouveau")).not.toBeInTheDocument();
  });

  it("delta absent (aucune empreinte) → aucune chip, le radar reste intact", () => {
    renderRadar(<ConflictRadar conflicts={conflictsFixture()} teams={teams} coaches={coaches} venues={venues} />);
    expect(screen.queryByText("Nouveau")).not.toBeInTheDocument();
    // Le radar rend toujours ses conflits (les deux collisions).
    expect(screen.getAllByText("Deux matchs sur le même créneau")).toHaveLength(2);
  });
});

describe("ConflictRadar — le titre dit « Conflits » (mot unique, UXC-18)", () => {
  it("intitule la carte « Conflits », jamais « Diagnostic »", () => {
    renderRadar(<ConflictRadar conflicts={conflictsFixture()} teams={teams} coaches={coaches} venues={venues} />);
    // Le titre est un <h2> (CardTitle). Falsification : remettre « Diagnostic » casse ce test.
    expect(screen.getByRole("heading", { level: 2, name: /Conflits/ })).toBeInTheDocument();
    expect(screen.queryByText("Diagnostic")).toBeNull();
  });
});

describe("ConflictRadar — personne en double, rôle PAR CÔTÉ (une personne = ses équipes)", () => {
  it("titre = nom seul ; le rôle vit PAR CÔTÉ dans le résumé (« U13 (assistant) et Seniors (coach) »)", () => {
    // Le rôle nuancé quitte le TITRE (fini « (assistant d'un côté) ») pour annoter
    // CHAQUE côté dans le résumé — coach d'un côté, assistant de l'autre.
    const coachesMap = new Map<string, Coach>([["coach-a", { id: "coach-a", firstName: "Anna", lastName: "B" }]]);
    const conflicts: Conflict[] = [
      {
        type: "MATCH_MATCH",
        severity: 5, resolution: null,
        coachId: "coach-a",
        coachRole: "ASSISTANT",
        left: { ...side("fx-1", "team-1", "2026-10-03"), role: "ASSISTANT" },
        right: { ...side("fx-2", "team-2", "2026-10-03"), role: "MAIN" },
      },
    ];
    renderRadar(<ConflictRadar conflicts={conflicts} teams={teams} coaches={coachesMap} venues={venues} />);
    // Titre = nom seul.
    expect(screen.getByText("Anna B")).toBeInTheDocument();
    expect(screen.queryByText(/\(assistant d'un côté\)/)).toBeNull();
    // Résumé annoté par côté.
    expect(screen.getByText(/U13 \(assistant\) et Seniors \(coach\)/)).toBeInTheDocument();
  });
});

describe("ConflictRadar — heure murale sans offset (P4-191)", () => {
  it("rend l'heure telle qu'écrite depuis une borne ISO sans décalage", () => {
    // P4-191 — une borne `2026-09-03T20:45:00` (heure murale du club, sans
    // offset) se lit en local puis se formate en local : l'heure 20:45 est
    // conservée, quel que soit le fuseau du navigateur.
    const conflicts: Conflict[] = [
      {
        type: "VENUE_OVERLAP",
        severity: 1, resolution: null,
        start: "2026-09-03T20:45:00",
        end: "2026-09-03T22:30:00",
        left: side("fx-1", "team-1", "2026-09-03"),
        right: side("fx-2", "team-2", "2026-09-03"),
      },
    ];
    renderRadar(<ConflictRadar conflicts={conflicts} teams={teams} coaches={coaches} venues={venues} />);
    expect(screen.getByText(/20:45/)).toBeInTheDocument();
    expect(screen.getByText(/22:30/)).toBeInTheDocument();
  });
});
