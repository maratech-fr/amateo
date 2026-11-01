import { render, screen, within } from "@testing-library/react";
import { MemoryRouter } from "react-router";
import { describe, expect, it } from "vitest";

import type { Coach, Conflict, Team, Venue } from "./api";
import { ConflictLine } from "./ConflictLine";
import { MATCHES_TEXTS } from "./texts";

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
  // M11 (BCCL 2026-10-27) : ConflictLine rend un deep-link <Link> pour ACCESS_WINDOW_LOST →
  // il lui faut un contexte Router (adaptation mécanique du harness ; sans effet sur les autres familles).
  return render(
    <MemoryRouter>
      <ul>
        <ConflictLine conflict={overlap} teams={teams} coaches={coaches} venues={venues} tone="destructive" isNew={false} {...props} />
      </ul>
    </MemoryRouter>,
  );
}

describe("ConflictLine (extrait du radar, avec slot trailing)", () => {
  it("chantier H — un conflit de coach ADOUCI (relief) montre un tag « un adjoint peut couvrir », pas seulement une couleur", () => {
    // Amendement a : un conflit MATCH_MATCH dont une équipe a un adjoint disponible est adouci et
    // porte `relief`. Le radar le DISTINGUE par une icône + un TAG texte (jamais la couleur seule,
    // a11y) — le tag passe par la maison de textes `MATCHES_TEXTS` (clé, pas la phrase recopiée).
    const relieved = {
      type: "MATCH_MATCH",
      severity: 5,
      relief: true,
      resolution: null,
      coachId: "c1",
      fingerprint: "fp-relief",
      left: side("fx-1", "team-1"),
      right: side("fx-2", "team-2"),
    } as unknown as Conflict;
    renderLine({ conflict: relieved, tone: "warning" });
    expect(screen.getByText(MATCHES_TEXTS.coachReliefTag)).toBeInTheDocument();
  });

  it("rend le titre et la phrase du conflit", () => {
    renderLine();
    expect(screen.getByText(MATCHES_TEXTS.venueOverlapTitle)).toBeInTheDocument();
    expect(screen.getByText(/U13 et Seniors/)).toBeInTheDocument();
  });

  // M6 (retours Nico, BCCL 2026-10-27) — la date de la RENCONTRE dans le résumé d'un conflit porte
  // son jour (« sam. 3 oct. » ; 2026-10-03 = samedi) : `frDateShortNoYear` → `frDateWeekdayNoYear`.
  it("le résumé d'un conflit porte la date de rencontre AVEC son jour de la semaine", () => {
    renderLine();
    // Un token de jour (lettres + point) précède « 3 oct. » — « — 3 oct. » nu échoue.
    expect(screen.getByText(/U13 et Seniors — \p{L}+\.? 3 oct\./u)).toBeInTheDocument();
  });

  it("COMPETITION_INCOMPLETE OVER : titre « Calendrier à vérifier », phrase « N matchs pour une poule de M adversaires »", () => {
    const over: Conflict = { type: "COMPETITION_INCOMPLETE", severity: 6, resolution: null, teamId: "team-1", competitionName: "Championnat X", imported: 12, expected: 10, reason: "OVER" };
    renderLine({ conflict: over, tone: "muted" });
    expect(screen.getByText(MATCHES_TEXTS.calendarToCheckTitle)).toBeInTheDocument();
    expect(screen.getByText(/12 matchs pour une poule de 5 adversaires/)).toBeInTheDocument();
  });

  it("COMPETITION_INCOMPLETE INCOHERENT : titre « Calendrier incohérent », phrase « ni aller simple ni aller-retour »", () => {
    const incoherent: Conflict = { type: "COMPETITION_INCOMPLETE", severity: 6, resolution: null, teamId: "team-1", competitionName: "Championnat X", imported: 7, expected: 10, reason: "INCOHERENT" };
    renderLine({ conflict: incoherent, tone: "muted" });
    expect(screen.getByText(MATCHES_TEXTS.calendarIncoherentTitle)).toBeInTheDocument();
    expect(screen.getByText(/ni aller simple \(5\) ni aller-retour \(10\)/)).toBeInTheDocument();
  });

  it("COMPETITION_INCOMPLETE PENDING : titre « Calendrier incomplet », phrase « pas encore entièrement sortie »", () => {
    const pending: Conflict = { type: "COMPETITION_INCOMPLETE", severity: 7, resolution: null, teamId: "team-1", competitionName: "Championnat X", imported: 3, expected: 10, reason: "PENDING" };
    renderLine({ conflict: pending, tone: "muted" });
    expect(screen.getByText(MATCHES_TEXTS.calendarIncompleteTitle)).toBeInTheDocument();
    expect(screen.getByText(/3\/10 journées attendues : la phase n'est pas encore entièrement sortie/)).toBeInTheDocument();
  });

  // Retours Nico (BCCL, 2026-10-27), M11 + M6 — « Hors accès match » ne disait ni QUELLE équipe ni
  // QUAND. Le résumé NOMME désormais l'équipe (U13) et la DATE de la rencontre avec son jour
  // (« sam. 3 oct. », M6), en plus du gymnase touché. La tournure exacte est à NEO ; les promesses
  // pinées : l'équipe, le gymnase et une date À JOUR apparaissent dans la ligne.
  const accessLost: Conflict = {
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

  it("ACCESS_WINDOW_LOST : titre « Hors accès match » et résumé nommant l'ÉQUIPE, la DATE (jour) et le gymnase", () => {
    const { container } = renderLine({ conflict: accessLost });
    const li = container.querySelector("li");
    expect(screen.getByText(MATCHES_TEXTS.accessWindowLostTitle)).toBeInTheDocument();
    // M11 : l'équipe (U13) et le gymnase touché (Gymnase Mateo) sont nommés.
    expect(li).toHaveTextContent("U13");
    expect(li).toHaveTextContent("Gymnase Mateo");
    // M6 : la date de la rencontre (2026-10-03 = samedi) porte son jour — un token de jour précède « 3 oct. ».
    expect(li).toHaveTextContent(/\p{L}+\.? 3 oct\./u);
  });

  // M11 — le résumé offre un deep-link « Configurer les accès » vers l'éditeur d'accès du GYMNASE
  // touché : /matchs/configuration?acces=<venueId> (état dans l'URL, partageable → un vrai lien).
  // NEO décide de l'hôte du lien ; le contrat piné : un lien nommé « Configurer les accès » porte
  // ce href pour un ACCESS_WINDOW_LOST. (Côté consommateur : l'arrivée sur cette URL ouvre
  // l'éditeur d'accès du gymnase — à pinner dans ConfigurationPage, voir rapport TANK.)
  it("ACCESS_WINDOW_LOST : le lien « Ouvrir ce gymnase ce jour-là » porte le gymnase, la date et le début (D5bis)", () => {
    render(
      <MemoryRouter>
        <ul>
          <ConflictLine conflict={accessLost} teams={teams} coaches={coaches} venues={venues} tone="warning" isNew={false} />
        </ul>
      </MemoryRouter>,
    );
    expect(screen.getByRole("link", { name: MATCHES_TEXTS.accessWindowLostOpenLink })).toHaveAttribute("href", "/matchs/configuration?acces=v-1&date=2026-10-03&debut=15:00");
    expect(screen.queryByRole("link", { name: /Configurer les accès/ })).toBeNull();
  });

  // A8 : `dayLabelLong` rend "" hors bornes : chaque sorte de ligne a son libellé.
  it.each([
    ["ouverture", { dayOfWeek: null, appliesOnPublicHolidays: false, openingDate: "2026-11-11", startTime: "14:00", endTime: "20:00" }, "le 11 nov. 14:00–20:00"],
    ["férié", { dayOfWeek: null, appliesOnPublicHolidays: true, openingDate: null, startTime: "14:00", endTime: "18:00" }, "jours fériés 14:00–18:00"],
    ["jour", { dayOfWeek: 3, appliesOnPublicHolidays: false, openingDate: null, startTime: "14:00", endTime: "18:00" }, "mercredi 14:00–18:00"],
  ])("ACCESS_WINDOW_LOST : libellé d'une ligne %s", (_label, window, expected) => {
    const { container } = renderLine({ conflict: { ...accessLost, windows: [window] } as unknown as Conflict });
    expect(container.querySelector("li")).toHaveTextContent(expected);
  });

  // Retour visuel : un match non placé n'est pas « placé hors des accès ». Le statut vient du conflit (`bareFixtureView`).
  it.each([
    ["UNPLACED", /proposé hors des accès/],
    ["PLACED", /placé hors des accès/],
    ["SUBMITTED", /placé hors des accès/],
    ["VALIDATED", /placé hors des accès/],
  ])("ACCESS_WINDOW_LOST : un match %s dit le bon verbe", (status, expected) => {
    const conflict = { ...accessLost, windows: [], fixture: { ...accessLost.fixture, status } } as unknown as Conflict;
    const { container } = renderLine({ conflict });
    expect(container.querySelector("li")).toHaveTextContent(expected);
    expect(container.querySelector("li")).toHaveTextContent(/aucun accès match ce jour-là/);
  });

  it("ACCESS_WINDOW_LOST : un match UNPLACED ne porte jamais le mot « placé »", () => {
    const conflict = { ...accessLost, fixture: { ...accessLost.fixture, status: "UNPLACED" } } as unknown as Conflict;
    const { container } = renderLine({ conflict });
    expect(container.querySelector("li")?.textContent).not.toMatch(/(^|\s)placé(\s|$)/); // `\b` ne voit pas la fin d'un mot en « é » hors mode unicode
  });

  it("VENUE_TRAINING_OVERLAP (D8) : titre et résumé nomment le gymnase, le match et l'entraînement", () => {
    const overlapTraining = {
      type: "VENUE_TRAINING_OVERLAP",
      severity: 5,
      resolution: null,
      fingerprint: "fp-train",
      venueId: "v-1",
      fixture: { fixtureId: "fx-9", teamId: "team-2", homeAway: "HOME", matchDate: "2026-11-11", kickoffTime: "18:30", status: "PLACED" },
      trainings: [{ teamId: "team-1", start: "18:00", end: "19:30" }],
    } as unknown as Conflict;
    const { container } = renderLine({ conflict: overlapTraining });
    expect(screen.getByText("Entraînement dans le gymnase")).toBeInTheDocument();
    expect(container.querySelector("li")).toHaveTextContent(/Gymnase Mateo · mer\. 11 nov\. · match 18:30 – .* · entraînement U13 18:00 – 19:30/);
  });

  it("CLUB_RULE_VIOLATION : titre « Hors règle du club » et phrase nommant la règle violée (P4-272 ③)", () => {
    const clubRule: Conflict = {
      type: "CLUB_RULE_VIOLATION",
      severity: 3,
      resolution: null,
      fingerprint: "fp-club",
      fixture: { fixtureId: "fx-7", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "21:30", windowStart: "", windowEnd: "" },
      rules: [{ daysOfWeek: [6], kickoffMin: null, kickoffMax: "21:00" }],
    };
    renderLine({ conflict: clubRule });
    expect(screen.getByText(MATCHES_TEXTS.clubRuleViolationTitle)).toBeInTheDocument();
    expect(screen.getByText(/règle : « pas après 21:00 »/)).toBeInTheDocument();
    expect(screen.getByText(/pose libre, à surveiller/)).toBeInTheDocument();
  });

  it("TEAM_LEVEL_MISSING : titre « Niveau non renseigné » et résumé nommant l'équipe (clés MATCHES_TEXTS, P4-288)", () => {
    const missing: Conflict = { type: "TEAM_LEVEL_MISSING", severity: 7, resolution: null, teamId: "team-1" };
    renderLine({ conflict: missing, tone: "muted" });
    expect(screen.getByText(MATCHES_TEXTS.teamLevelMissingTitle)).toBeInTheDocument();
    // Le résumé passe par la CLÉ interpolée (jamais la phrase recopiée) ; team-1 → « U13 » (map).
    expect(screen.getByText(MATCHES_TEXTS.teamLevelMissingSummary("U13"))).toBeInTheDocument();
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
    expect(screen.getByText(MATCHES_TEXTS.newChip)).toBeInTheDocument();
  });

  it("sans isNew : aucune chip « Nouveau »", () => {
    renderLine({ isNew: false });
    expect(screen.queryByText(MATCHES_TEXTS.newChip)).not.toBeInTheDocument();
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
    // Contenu de test injecté via `below` (fixture, pas une phrase d'app) → const locale, pas une clé.
    const belowNote = "Note de traitement";
    renderLine({ below: <p>{belowNote}</p> });
    expect(screen.getByText(belowNote)).toBeInTheDocument();
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
  const coachesMap = new Map<string, Coach>([["p-1", { id: "p-1", firstName: "Mara", lastName: "MB", gender: "FEMALE" }]]);

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
    expect(screen.getByText(/U13 \(coach\) et Seniors \(joueuse\) —/)).toBeInTheDocument();
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
    expect(screen.getByText(MATCHES_TEXTS.matchVsTrainingSummary("Seniors (joueuse)", "U13 (coach)"))).toBeInTheDocument();
  });
});

describe("ConflictLine — détail par côté (P2-54)", () => {
  const coachesMap = new Map<string, Coach>([["p-1", { id: "p-1", firstName: "Mara", lastName: "MB", gender: "UNSPECIFIED" }]]);

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
    // 90 min so the home « durée estimée » (1h30) is distinct from the overlap
    // (1h55) — the assertion below targets the chevauchement uniquely.
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
    expect(screen.getByText(MATCHES_TEXTS.awayAt("Villeurbanne"))).toBeInTheDocument();
    expect(screen.getByText(MATCHES_TEXTS.homeSide)).toBeInTheDocument();
    expect(screen.getByText(MATCHES_TEXTS.versus("ASVEL - 2"))).toBeInTheDocument();
    expect(screen.getByText(MATCHES_TEXTS.versus("VAULX EN VELIN BASKET CLUB - 2"))).toBeInTheDocument();
    // Le chevauchement, avec sa durée compacte.
    expect(screen.getByText(/Chevauchement/)).toBeInTheDocument();
    expect(screen.getByText("1h55")).toBeInTheDocument();
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
    expect(screen.queryByText(MATCHES_TEXTS.estimatedTimeTag)).not.toBeInTheDocument();
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
    expect(screen.getByText(MATCHES_TEXTS.estimatedTimeTag)).toBeInTheDocument();
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
    expect(screen.getByText(MATCHES_TEXTS.versus("BC Villeurbanne"))).toBeInTheDocument();
    expect(screen.getByText(MATCHES_TEXTS.versus("ASVEL U21"))).toBeInTheDocument();
    // La pastille GLOBALE « heure estimée » et la ligne grise horaire disparaissent (comme pour les familles de personne).
    expect(screen.queryByText(MATCHES_TEXTS.estimatedTimeTag)).not.toBeInTheDocument();
    expect(screen.getByText(/Chevauchement/)).toBeInTheDocument();
  });

  // Correction d'affichage (fondateur, sur capture) : « 1 ligne par équipe ». jsdom n'a pas de
  // moteur de mise en page — on atteste les CLASSES qui GARANTISSENT la ligne unique (le rendu
  // réel se prouve en Playwright), exactement comme le détail personne le fait plus haut.
  const oneLineVenue: Conflict = {
    type: "VENUE_OVERLAP",
    severity: 1,
    resolution: null,
    venueId: "v-1",
    start: "2026-11-08T16:00:00",
    end: "2026-11-08T17:25:00",
    left: { ...homeReal, fixtureId: "fx-a", teamId: "team-1", kickoffTime: "15:30", matchDurationMinutes: 120, opponentLabel: "BC Villeurbanne" },
    right: { ...homeReal, fixtureId: "fx-b", teamId: "team-2", kickoffTime: "16:00", matchDurationMinutes: 120, opponentLabel: "ASVEL U21" },
  };

  it("VENUE_OVERLAP : équipe et « vs adversaire » sur UNE ligne (flex empilable), jamais deux blocs empilés", () => {
    renderDetail(oneLineVenue);
    const opponent = screen.getByText(MATCHES_TEXTS.versus("BC Villeurbanne"));
    // L'adversaire n'est plus un `block` (qui forçait un saut de ligne sous l'équipe).
    expect(opponent).not.toHaveClass("block");
    const identity = opponent.parentElement as HTMLElement;
    // Équipe + adversaire dans un conteneur `flex` (côte à côte au bureau), `flex-wrap` pour
    // s'empiler en largeur téléphone (repli assumé).
    expect(identity).toHaveClass("flex");
    expect(identity).toHaveClass("flex-wrap");
    const teamName = within(identity).getByText("U13");
    expect(teamName).not.toHaveClass("block");
  });

  it("VENUE_OVERLAP : le créneau ne se casse jamais autour de sa flèche (cellule insécable, sans flex-wrap)", () => {
    renderDetail(oneLineVenue);
    const table = screen.getByRole("table", { name: "Détail par équipe" });
    const firstRow = within(table).getAllByRole("row").slice(1)[0];
    // identité(0) · Gymnase(1) · Date(2) · Créneau(3)
    const creneau = cellsOf(firstRow)[3];
    expect(creneau).toHaveClass("whitespace-nowrap");
    // Le groupe coup d'envoi → fin n'enroule plus (plus de `flex-wrap` qui cassait « 18:45 → 20:45 »).
    const group = creneau.querySelector("span");
    expect(group).not.toHaveClass("flex-wrap");
  });

  it("VENUE_OVERLAP : la colonne d'identité ne capte plus tout le vide (pas de w-full → l'espace se répartit)", () => {
    renderDetail(oneLineVenue);
    const table = screen.getByRole("table", { name: "Détail par équipe" });
    const firstRow = within(table).getAllByRole("row").slice(1)[0];
    const identityCell = cellsOf(firstRow)[0];
    expect(identityCell).not.toHaveClass("w-full");
  });
});
