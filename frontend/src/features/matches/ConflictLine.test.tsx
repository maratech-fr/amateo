import { render, screen, within } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { Coach, Conflict, Team, Venue } from "./api";
import { ConflictLine } from "./ConflictLine";

const teams = new Map<string, Team>([
  ["team-1", { id: "team-1", name: "U13", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
  ["team-2", { id: "team-2", name: "Seniors", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
]);
const coaches = new Map<string, Coach>();
const venues = new Map<string, Venue>([["v-1", { id: "v-1", name: "Gymnase Mateo", color: null, externalLabels: [] }]]);

function side(fixtureId: string, teamId: string, matchDate = "2026-10-03") {
  return { fixtureId, teamId, homeAway: "HOME" as const, matchDate, kickoffTime: "16:00", windowStart: "", windowEnd: "" };
}

const overlap: Conflict = { type: "VENUE_OVERLAP", severity: 1, resolution: null, fingerprint: "fp-1", left: side("fx-1", "team-1"), right: side("fx-2", "team-2") };

function renderLine(props: Partial<React.ComponentProps<typeof ConflictLine>> = {}) {
  return render(
    <ul>
      <ConflictLine conflict={overlap} teams={teams} coaches={coaches} venues={venues} tone="destructive" isNew={false} {...props} />
    </ul>,
  );
}

describe("ConflictLine (extrait du radar, avec slot trailing)", () => {
  it("rend le titre et la phrase du conflit", () => {
    renderLine();
    expect(screen.getByText("Deux matchs sur le même créneau")).toBeInTheDocument();
    expect(screen.getByText(/U13 et Seniors/)).toBeInTheDocument();
  });

  it("ACCESS_WINDOW_LOST : titre « Hors accès match » et phrase nommant le gymnase + les accès (jour du match d'abord)", () => {
    const access: Conflict = {
      type: "ACCESS_WINDOW_LOST",
      severity: 4,
      resolution: null,
      fingerprint: "fp-acc",
      venueId: "v-1",
      fixture: { fixtureId: "fx-9", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "15:00", windowStart: "", windowEnd: "" },
      windows: [
        { dayOfWeek: 6, startTime: "16:00", endTime: "18:00" },
        { dayOfWeek: 3, startTime: "18:00", endTime: "20:00" },
      ],
    };
    renderLine({ conflict: access });
    expect(screen.getByText("Hors accès match")).toBeInTheDocument();
    expect(
      screen.getByText("Placé hors des accès match de Gymnase Mateo (samedi 16:00–18:00, mercredi 18:00–20:00) — déplacez le match ou ajustez l'accès dans Configuration."),
    ).toBeInTheDocument();
  });

  it("ACCESS_WINDOW_LOST : sans aucun accès match sur le gymnase → « (aucun accès match ce jour-là) »", () => {
    const access: Conflict = {
      type: "ACCESS_WINDOW_LOST",
      severity: 4,
      resolution: null,
      fingerprint: "fp-acc2",
      venueId: "v-1",
      fixture: { fixtureId: "fx-9", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "15:00", windowStart: "", windowEnd: "" },
      windows: [],
    };
    renderLine({ conflict: access });
    expect(screen.getByText(/\(aucun accès match ce jour-là\)/)).toBeInTheDocument();
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
        <ConflictLine conflict={conflict} teams={teams} coaches={coachesMap} venues={venues} tone="warning" isNew={false} />
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

describe("ConflictLine — détail par côté (P2-54)", () => {
  const coachesMap = new Map<string, Coach>([["p-1", { id: "p-1", firstName: "Mara", lastName: "MB" }]]);

  const awayEstimated = {
    fixtureId: "fx-a",
    teamId: "team-1",
    homeAway: "AWAY" as const,
    matchDate: "2026-11-08",
    kickoffTime: null,
    estimatedKickoff: true,
    estimatedKickoffTime: "15:00",
    travelOneWayMinutes: 85,
    windowStart: "2026-11-08T13:30:00",
    windowEnd: "2026-11-08T18:25:00",
    matchDurationMinutes: 105,
    opponentLabel: "ASVEL - 2",
    opponentPlace: "Villeurbanne",
    role: "MAIN" as const,
  };
  const homeReal = {
    fixtureId: "fx-b",
    teamId: "team-2",
    homeAway: "HOME" as const,
    matchDate: "2026-11-08",
    kickoffTime: "15:30",
    windowStart: "2026-11-08T15:00:00",
    windowEnd: "2026-11-08T17:25:00",
    // 90 min so the home « durée estimée » (1 h 30) is distinct from the overlap
    // (1 h 55) — the assertion below targets the chevauchement uniquely.
    matchDurationMinutes: 90,
    opponentLabel: "VAULX EN VELIN BASKET CLUB - 2",
    role: "PLAYER" as const,
  };
  const matchMatch: Conflict = {
    type: "MATCH_MATCH",
    severity: 3,
    resolution: null,
    coachId: "p-1",
    start: "2026-11-08T15:30:00",
    end: "2026-11-08T17:25:00",
    left: awayEstimated,
    right: homeReal,
  };

  function renderDetail(conflict: Conflict) {
    return render(
      <ul>
        <ConflictLine conflict={conflict} teams={teams} coaches={coachesMap} venues={venues} tone="warning" isNew={false} />
      </ul>,
    );
  }

  /** Les cellules d'une ligne DANS L'ORDRE des colonnes (identité rowheader + 4 horaires). */
  function cellsOf(row: HTMLElement): Element[] {
    return Array.from(row.querySelectorAll("th[scope='row'], td"));
  }

  it("rend un TABLEAU « Détail par équipe » (4 en-têtes de colonne) + une ligne par côté + le chevauchement", () => {
    renderDetail(matchMatch);
    const table = screen.getByRole("table", { name: "Détail par équipe" });
    // 4 en-têtes NOMMÉS (la colonne d'identité n'en porte pas) : Départ, Coup d'envoi, Fin/retour, Durée.
    const headers = within(table).getAllByRole("columnheader").map((h) => h.textContent);
    expect(headers).toEqual(["Départ", "Coup d'envoi", "Fin / retour", "Durée"]);
    // Deux lignes de corps (une par côté).
    const bodyRows = within(table).getAllByRole("row").slice(1);
    expect(bodyRows).toHaveLength(2);
    // Lieu + adversaire par côté.
    expect(screen.getByText("extérieur à Villeurbanne")).toBeInTheDocument();
    expect(screen.getByText("domicile")).toBeInTheDocument();
    expect(screen.getByText("vs ASVEL - 2")).toBeInTheDocument();
    expect(screen.getByText("vs VAULX EN VELIN BASKET CLUB - 2")).toBeInTheDocument();
    // Le chevauchement, avec sa durée aérée.
    expect(screen.getByText(/Chevauchement/)).toBeInTheDocument();
    expect(screen.getByText("1 h 55")).toBeInTheDocument();
  });

  it("le coup d'envoi est TOUJOURS la même colonne (index 2) — l'alignement est un fait DOM", () => {
    renderDetail(matchMatch);
    const table = screen.getByRole("table", { name: "Détail par équipe" });
    const [awayRow, homeRow] = within(table).getAllByRole("row").slice(1);
    // Côté extérieur (estimé 15:00) et côté domicile (15:30) : le coup d'envoi tombe dans la
    // MÊME cellule (index 2) — l'alignement des heures ne dépend pas de la nature du côté.
    expect(cellsOf(awayRow)[2].textContent).toContain("15:00");
    expect(cellsOf(homeRow)[2].textContent).toContain("15:30");
  });

  it("le coup d'envoi estimé porte la pastille « estimé » DANS sa case ; la pastille GLOBALE « heure estimée » disparaît", () => {
    renderDetail(matchMatch);
    expect(screen.queryByText("heure estimée")).not.toBeInTheDocument();
    const table = screen.getByRole("table", { name: "Détail par équipe" });
    const awayRow = within(table).getAllByRole("row").slice(1)[0];
    expect(cellsOf(awayRow)[2].textContent).toContain("estimé");
  });

  it("côté sans trajet : Départ = « — » et « trajet inconnu » dans l'en-tête de ligne", () => {
    const noTravel = { ...awayEstimated, travelOneWayMinutes: null };
    renderDetail({ ...matchMatch, left: noTravel });
    const table = screen.getByRole("table", { name: "Détail par équipe" });
    const awayRow = within(table).getAllByRole("row").slice(1)[0];
    const cells = cellsOf(awayRow);
    // Colonne Départ (index 1) = tiret muet ; « trajet inconnu » vit dans le rowheader (index 0).
    expect(cells[1].textContent).toBe("—");
    expect(cells[0].textContent).toContain("trajet inconnu");
  });

  it("adversaire long : l'adversaire s'enroule (ni whitespace-nowrap ni truncate), les cellules horaires restent insécables", () => {
    const longOpp = "VAULX EN VELIN BASKET CLUB SUD OUEST - 2"; // 40 caractères
    renderDetail({ ...matchMatch, right: { ...homeReal, opponentLabel: longOpp } });

    // Le bloc adversaire s'enroule et se casse (overflow-wrap), jamais nowrap ni truncate.
    const opponent = screen.getByText(new RegExp(longOpp));
    expect(opponent).not.toHaveClass("whitespace-nowrap");
    expect(opponent).not.toHaveClass("truncate");
    expect(opponent).toHaveClass("[overflow-wrap:anywhere]");

    // Une cellule horaire (le coup d'envoi) reste, elle, insécable.
    const table = screen.getByRole("table", { name: "Détail par équipe" });
    const homeRow = within(table).getAllByRole("row").slice(1)[1];
    expect(cellsOf(homeRow)[2]).toHaveClass("whitespace-nowrap");
  });

  it("l'en-tête « Durée » se replie sous @sm (container query) — le masquage réel se prouve en Playwright", () => {
    renderDetail(matchMatch);
    const duree = screen.getByRole("columnheader", { name: "Durée" });
    // jsdom n'applique aucun CSS Tailwind : on atteste la CLASSE, pas le rendu (Playwright le fait).
    expect(duree).toHaveClass("@sm:table-cell");
    expect(duree).toHaveClass("hidden");
  });

  it("VENUE_OVERLAP SANS venueId (donnée dégradée) : repli sur la pastille GLOBALE « heure estimée » et AUCUN détail par côté", () => {
    const venueOverlap: Conflict = {
      type: "VENUE_OVERLAP",
      severity: 1,
      resolution: null,
      start: "2026-11-08T15:30:00",
      end: "2026-11-08T17:25:00",
      left: { ...awayEstimated },
      right: { ...homeReal },
    };
    renderDetail(venueOverlap);
    expect(screen.getByText("heure estimée")).toBeInTheDocument();
    expect(screen.queryByRole("table", { name: "Détail par équipe" })).not.toBeInTheDocument();
  });

  it("VENUE_OVERLAP (collision de gymnase) : détail par côté — équipe · vs adversaire · gymnase sur CHAQUE ligne · date · créneau, sans pastille globale", () => {
    const homeA = { ...homeReal, fixtureId: "fx-a", teamId: "team-1", kickoffTime: "15:30", matchDurationMinutes: 120, opponentLabel: "BC Villeurbanne" };
    const homeB = { ...homeReal, fixtureId: "fx-b", teamId: "team-2", kickoffTime: "16:00", matchDurationMinutes: 120, opponentLabel: "ASVEL U21" };
    const venueOverlap: Conflict = {
      type: "VENUE_OVERLAP",
      severity: 1,
      resolution: null,
      venueId: "v-1",
      start: "2026-11-08T16:00:00",
      end: "2026-11-08T17:25:00",
      left: homeA,
      right: homeB,
    };
    renderDetail(venueOverlap);
    const table = screen.getByRole("table", { name: "Détail par équipe" });
    expect(within(table).getAllByRole("columnheader").map((h) => h.textContent)).toEqual(["Gymnase", "Date", "Créneau"]);
    // Le gymnase est nommé sur CHAQUE ligne (forme imposée par le fondateur).
    expect(within(table).getAllByText("Gymnase Mateo")).toHaveLength(2);
    expect(screen.getByText("vs BC Villeurbanne")).toBeInTheDocument();
    expect(screen.getByText("vs ASVEL U21")).toBeInTheDocument();
    // La pastille GLOBALE « heure estimée » et la ligne grise horaire disparaissent (comme pour les familles de personne).
    expect(screen.queryByText("heure estimée")).not.toBeInTheDocument();
    expect(screen.getByText(/Chevauchement/)).toBeInTheDocument();
  });
});
