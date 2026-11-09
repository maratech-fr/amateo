import { render, screen, within } from "@testing-library/react";
import type { Matcher } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import { frDateNumericNoYear } from "@/shared/lib/date";

import { SocleDeviationPanel } from "./SocleDeviationPanel";
import { placementLabel } from "./lib/socleDeviationCells";
import { toReplaceReasonLabel } from "./lib/toReplaceReason";
import { PLANNING_TEXTS } from "./texts";
import type { SocleDeviation, SocleDeviationRank, SocleDeviationTeam } from "./api";

/**
 * Le BILAN PAR RANG (fermetures de gymnase, PR A — D7) : le panneau dit, rang par rang puis équipe
 * par équipe, ce que la période a fait au planning de saison, à partir des SEULS comptes servis
 * (`teams[]`, `ranks[]`) — il ne recompte rien. Écran NÉ MIGRÉ : tout texte se vérifie par sa clé
 * `PLANNING_TEXTS` ; le seul littéral est le test « mot pour mot » des clés (patron
 * `PlacedAbsencesNotice.test.tsx`) et le rendu de la ligne la plus longue (lisibilité, plan v6 f).
 */

const TEAMS: Record<string, { name: string; isCtc: boolean; ctcPartner: string | null }> = {
  tA: { name: "U11A", isCtc: true, ctcPartner: "Club voisin" },
  tB: { name: "U11B", isCtc: false, ctcPartner: null },
  tC: { name: "U11C", isCtc: true, ctcPartner: "Autre club" },
  tR: { name: "U13M", isCtc: false, ctcPartner: null },
  tP: { name: "U15F", isCtc: false, ctcPartner: null },
  tN: { name: "U9B", isCtc: false, ctcPartner: null },
  tL: { name: "SM2", isCtc: false, ctcPartner: null },
  tS1: { name: "SM1", isCtc: false, ctcPartner: null },
  tS2: { name: "SF1", isCtc: false, ctcPartner: null },
};
const teamOf = (id: string) => TEAMS[id] ?? { name: id, isCtc: false, ctcPartner: null };
const venueName = (id: string) => ({ vX: "Matéo", vY: "Brel", vZ: "Salle Bleue" })[id] ?? id;

const team = (over: Partial<SocleDeviationTeam> & Pick<SocleDeviationTeam, "teamId">): SocleDeviationTeam => ({
  tierLabel: "C",
  tierWeight: 20,
  tierOrder: 0,
  paused: false,
  socleSessions: 1,
  keptSessions: 1,
  lost: 0,
  reducedByChoice: 0,
  movedSessions: 0,
  newSessions: 0,
  chosenSessions: null,
  daysKept: true,
  changed: false,
  sharedKept: 0,
  sharedWith: [],
  ...over,
});

const rank = (over: Partial<SocleDeviationRank> & Pick<SocleDeviationRank, "tierLabel">): SocleDeviationRank => ({
  tierWeight: 20,
  teams: 1,
  socleSessions: 1,
  keptSessions: 1,
  lost: 0,
  chosenReductions: 0,
  newSessions: 0,
  paused: 0,
  unchanged: 1,
  ...over,
});

// Rang S : deux équipes, toutes en pause. Rang C : 7 équipes — U11A déplacée (commune avec U11B et
// U11C, servies dans le DÉSORDRE des noms), U11B et U11C sans changement, U13M réduite par choix,
// U15F en pause, U9B absente du socle (1 nouvelle), SM2 tout perdu (une commune avec U11B).
const TEAM_ROWS: SocleDeviationTeam[] = [
  team({ teamId: "tS1", tierLabel: "S", tierWeight: 100, paused: true, daysKept: null, socleSessions: 2, keptSessions: 0 }),
  team({ teamId: "tS2", tierLabel: "S", tierWeight: 100, tierOrder: 1, paused: true, daysKept: null, socleSessions: 2, keptSessions: 0 }),
  team({ teamId: "tA", socleSessions: 3, keptSessions: 3, movedSessions: 1, changed: true, sharedKept: 1, sharedWith: ["tC", "tB"] }),
  team({ teamId: "tB", socleSessions: 2, keptSessions: 2, tierOrder: 1 }),
  team({ teamId: "tC", tierOrder: 2 }),
  team({ teamId: "tR", tierOrder: 3, socleSessions: 3, keptSessions: 2, reducedByChoice: 1, chosenSessions: 2, changed: true }),
  team({ teamId: "tP", tierOrder: 4, paused: true, daysKept: null, keptSessions: 0 }),
  team({ teamId: "tN", tierOrder: 5, socleSessions: 0, keptSessions: 0, newSessions: 1, daysKept: null, changed: true }),
  team({ teamId: "tL", tierOrder: 6, socleSessions: 2, keptSessions: 0, lost: 2, daysKept: null, changed: true }),
];

const DEVIATION: SocleDeviation = {
  socleScheduleId: "socle",
  window: { startDate: "2026-03-09", endDate: "2026-03-15" },
  moved: [
    { teamId: "tA", from: { dayOfWeek: 1, startTime: "18:00", venueId: "vX", sharedWith: ["tB"] }, to: { dayOfWeek: 4, startTime: "19:00", venueId: "vY", slotId: "slot-A", sharedWith: ["tB"] } },
  ],
  unplaced: [
    { teamId: "tR", dayOfWeek: 5, startTime: "20:00", venueId: "vX", reason: "team_reduced", sharedWith: [] },
    { teamId: "tL", dayOfWeek: 3, startTime: "18:00", venueId: "vZ", reason: "venue_closed", sharedWith: ["tB"] },
    { teamId: "tL", dayOfWeek: 2, startTime: "17:00", venueId: "vZ", reason: null, sharedWith: [] },
  ],
  changedSlotIds: ["slot-A", "slot-N"],
  teams: TEAM_ROWS,
  ranks: [
    rank({ tierLabel: "S", tierWeight: 100, teams: 2, socleSessions: 0, keptSessions: 0, paused: 2, unchanged: 0 }),
    rank({ tierLabel: "C", teams: 7, socleSessions: 11, keptSessions: 8, lost: 2, chosenReductions: 1, newSessions: 1, paused: 1, unchanged: 2 }),
  ],
};

const rowOf = (id: string): SocleDeviationTeam => TEAM_ROWS.find((t) => t.teamId === id) as SocleDeviationTeam;
const lineOf = (id: string, partners: string[] = []): string => PLANNING_TEXTS.teamReportLine(TEAMS[id].name, rowOf(id), partners);

const renderPanel = (deviation: SocleDeviation = DEVIATION, onSelectSlot?: (slotId: string) => void) =>
  render(<SocleDeviationPanel deviation={deviation} teamOf={teamOf} venueName={venueName} onSelectSlot={onSelectSlot} />);

const titleOneWeek = (): string => PLANNING_TEXTS.rankReportTitleWeek(frDateNumericNoYear("2026-03-09"));

/** L'élément de liste d'une équipe — sa ligne ET ses lignes de détail (« sous la ligne de l'équipe »). */
const teamItem = (line: string): HTMLElement => {
  const item = screen.getByText(line).closest("li");
  expect(item, `la ligne « ${line} » vit dans un élément de liste`).not.toBeNull();
  return item as HTMLElement;
};

/** Matcher Testing Library « contient ce texte » — sans RegExp construite (règle Semgrep detect-non-literal-regexp). */
const contains =
  (text: string) =>
  (content: string): boolean =>
    content.includes(text);

/**
 * Q7 = b (fondateur) — le bilan est REPLIÉ par défaut : une ligne de RANG est un bouton
 * `aria-expanded` (nom = sa ligne de synthèse, toujours visible), une ligne d'ÉQUIPE qui a du
 * détail aussi (nom = sa ligne, repère CTC éventuel compris : on cherche par inclusion).
 */
const rankButton = (r: SocleDeviationRank): HTMLElement => screen.getByRole("button", { name: contains(PLANNING_TEXTS.rankReportLine(r)) });
const teamButton = (line: string): HTMLElement => screen.getByRole("button", { name: contains(line) });

/** Absente du DOM ou masquée : une ligne repliée ne se voit pas (les deux mises en œuvre passent). */
const expectNotShown = (text: Matcher): void => {
  for (const el of screen.queryAllByText(text)) {
    expect(el).not.toBeVisible();
  }
};

const RANK_S = DEVIATION.ranks[0]; // lost 0 → replié par défaut
const RANK_C = DEVIATION.ranks[1]; // lost 2 → ouvert par défaut

describe("SocleDeviationPanel — le bilan par rang", () => {
  it("les libellés sont ceux validés, mot pour mot (ligne d'équipe, de rang, pied, détail, titres)", () => {
    // Ligne d'équipe (v3 §B, v4 m1/m2, v5 §3) — singulier, pluriel, « aucune », pause, absente du socle.
    expect(PLANNING_TEXTS.teamReportLine("U13M", team({ teamId: "x", socleSessions: 3, keptSessions: 2, reducedByChoice: 1, chosenSessions: 2, changed: true }), [])).toBe("U13M : jour gardé, 2 séances sur 3 (réduction choisie à 2)");
    expect(PLANNING_TEXTS.teamReportLine("U13M", team({ teamId: "x", socleSessions: 3, keptSessions: 2, lost: 1, chosenSessions: 2, changed: true }), [])).toBe("U13M : jour gardé, 2 séances sur 3, dont 1 perdue");
    expect(PLANNING_TEXTS.teamReportLine("U9", team({ teamId: "x", socleSessions: 2, keptSessions: 2, newSessions: 1, changed: true }), [])).toBe("U9 : jour gardé, 2 séances sur 2, 1 nouvelle");
    expect(PLANNING_TEXTS.teamReportLine("U11A", team({ teamId: "x", socleSessions: 3, keptSessions: 3, movedSessions: 1, changed: true, sharedKept: 1, sharedWith: ["b"] }), ["U11B"])).toBe("U11A : jour gardé, 3 séances sur 3, 1 déplacée — 1 commune avec U11B");
    expect(PLANNING_TEXTS.teamReportLine("U13F", team({ teamId: "x", daysKept: false, movedSessions: 1, changed: true }), [])).toBe("U13F : jour changé, 1 séance sur 1, 1 déplacée");
    expect(PLANNING_TEXTS.teamReportLine("SM2", team({ teamId: "x", socleSessions: 2, keptSessions: 0, lost: 2, daysKept: null, changed: true }), [])).toBe("SM2 : aucune séance sur 2, dont 2 perdues");
    expect(PLANNING_TEXTS.teamReportLine("U11A", team({ teamId: "x", socleSessions: 4, keptSessions: 4, movedSessions: 2, newSessions: 2, changed: true, sharedKept: 2, sharedWith: ["b"] }), ["U11B"])).toBe("U11A : jour gardé, 4 séances sur 4, 2 déplacées, 2 nouvelles — 2 communes avec U11B");
    expect(PLANNING_TEXTS.teamReportLine("U15F", team({ teamId: "x", paused: true, daysKept: null }), [])).toBe("U15F : en pause");
    expect(PLANNING_TEXTS.teamReportLine("U9B", team({ teamId: "x", socleSessions: 0, keptSessions: 0, newSessions: 1, daysKept: null, changed: true }), [])).toBe("U9B : 1 nouvelle séance (absente du planning de saison)");
    expect(PLANNING_TEXTS.teamReportLine("U9B", team({ teamId: "x", socleSessions: 0, keptSessions: 0, newSessions: 2, daysKept: null, changed: true }), [])).toBe("U9B : 2 nouvelles séances (absentes du planning de saison)");

    // Ligne de rang (v3 §B, v4 M1 : « retirée(s) par choix » compte des SÉANCES ; v4 m2 b).
    expect(PLANNING_TEXTS.rankReportLine(rank({ tierLabel: "C", teams: 5, socleSessions: 13, keptSessions: 11, lost: 1, chosenReductions: 1, paused: 1, unchanged: 2 }))).toBe("C — 5 équipes : 11 séances sur 13, dont 1 perdue (1 séance retirée par choix), 1 en pause");
    expect(PLANNING_TEXTS.rankReportLine(rank({ tierLabel: "C", teams: 1, socleSessions: 4, keptSessions: 2, chosenReductions: 2, unchanged: 0 }))).toBe("C — 1 équipe : 2 séances sur 4 (2 séances retirées par choix)");
    expect(PLANNING_TEXTS.rankReportLine(rank({ tierLabel: "B", teams: 3, socleSessions: 6, keptSessions: 6, newSessions: 2, unchanged: 1 }))).toBe("B — 3 équipes : 6 séances sur 6, 2 nouvelles");
    expect(PLANNING_TEXTS.rankReportLine(rank({ tierLabel: "A", teams: 1, socleSessions: 2, keptSessions: 0, lost: 2, unchanged: 0 }))).toBe("A — 1 équipe : aucune séance sur 2, dont 2 perdues");
    expect(PLANNING_TEXTS.rankReportLine(rank({ tierLabel: "S", teams: 2, socleSessions: 0, keptSessions: 0, paused: 2, unchanged: 0 }))).toBe("S — 2 équipes : toutes en pause");

    // Pied, détail « commune avec », titres (D12 : une semaine / chaque semaine), sous-titre (fondateur).
    expect(PLANNING_TEXTS.rankReportFooter(2, 4, 1)).toBe("2 équipes touchées · 4 sans changement · 1 en pause");
    expect(PLANNING_TEXTS.rankReportFooter(1, 0, 0)).toBe("1 équipe touchée");
    expect(PLANNING_TEXTS.sharedWithDetail(["U11B"])).toBe("— commune avec U11B");
    expect(PLANNING_TEXTS.sharedWithDetail(["U11B", "U11C"])).toBe("— commune avec U11B et U11C");
    expect(PLANNING_TEXTS.rankReportTitleWeek("9/3")).toBe("Bilan par rang — semaine du 9/3");
    expect(PLANNING_TEXTS.rankReportTitleWeeks("9/3", "29/3")).toBe("Bilan par rang — chaque semaine du 9/3 au 29/3");
    expect(PLANNING_TEXTS.rankReportSubtitle).toBe("Semaine type, comparée au planning de saison.");
    expect(PLANNING_TEXTS.changesOnly).toBe("Seulement les séances qui ont changé");
    expect(PLANNING_TEXTS.changesOnlyEmpty).toBe("Aucune séance déplacée ni nouvelle : les changements sont des séances perdues, listées dans le bilan.");
    expect(PLANNING_TEXTS.rankReportFailed).toBe("Le bilan n'a pas pu être lu.");
  });

  it("le titre dit la fenêtre (une semaine, ou chaque semaine d'une fenêtre plus longue) et le sous-titre la comparaison", () => {
    const { unmount } = renderPanel();
    const region = screen.getByRole("region", { name: titleOneWeek() });
    expect(within(region).getByRole("heading", { name: titleOneWeek() })).toBeInTheDocument();
    expect(within(region).getByText(PLANNING_TEXTS.rankReportSubtitle)).toBeInTheDocument();
    unmount();

    renderPanel({ ...DEVIATION, window: { startDate: "2026-03-09", endDate: "2026-03-29" } });
    const weeks = PLANNING_TEXTS.rankReportTitleWeeks(frDateNumericNoYear("2026-03-09"), frDateNumericNoYear("2026-03-29"));
    expect(screen.getByRole("heading", { name: weeks })).toBeInTheDocument();
  });

  it("une ligne par RANG, dans l'ordre servi, puis sous chaque rang ses équipes touchées ou en pause — jamais les inchangées", async () => {
    renderPanel();
    const rankS = screen.getByText(PLANNING_TEXTS.rankReportLine(DEVIATION.ranks[0]));
    const rankC = screen.getByText(PLANNING_TEXTS.rankReportLine(DEVIATION.ranks[1]));
    // Ordre servi (le serveur trie : poids décroissant) — le front ne retrie pas.
    expect(rankS.compareDocumentPosition(rankC) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

    // Les équipes en pause du rang S sont sous le rang S (avant la ligne du rang C) — rang déplié.
    await userEvent.click(rankButton(RANK_S));
    const sm1 = screen.getByText(lineOf("tS1"));
    expect(rankS.compareDocumentPosition(sm1) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(sm1.compareDocumentPosition(rankC) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

    // Rang C : touchées (déplacée, réduite, nouvelle, perdue) et en pause — les deux inchangées n'ont pas de ligne.
    for (const id of ["tR", "tP", "tN", "tL"]) {
      expect(screen.getByText(lineOf(id))).toBeInTheDocument();
    }
    expect(screen.queryByText(lineOf("tB"))).not.toBeInTheDocument();
    expect(screen.queryByText(lineOf("tC"))).not.toBeInTheDocument();

    // Pied : 4 touchées (la réduite par choix comprise), 2 sans changement, 3 en pause.
    expect(screen.getByText(PLANNING_TEXTS.rankReportFooter(4, 2, 3))).toBeInTheDocument();
  });

  it("la mention « commune avec » nomme les partenaires TRIÉS PAR NOM (ids servis), sans repère CTC devant un partenaire", () => {
    renderPanel();
    // sharedWith servi ["tC", "tB"] → nommés « U11B et U11C ».
    const item = teamItem(lineOf("tA", ["U11B", "U11C"]));
    // Le repère d'entente précède le nom de l'équipe de la LIGNE (U11A, en CTC)…
    expect(within(item).getByRole("img", { name: "Entente (CTC) avec Club voisin" })).toBeInTheDocument();
    // …mais jamais un partenaire embarqué dans une phrase (U11C, pourtant en CTC).
    expect(within(item).queryByRole("img", { name: "Entente (CTC) avec Autre club" })).not.toBeInTheDocument();
  });

  it("D4/D6-d — la déplacée est détaillée SOUS son équipe (une fois dépliée), cliquable vers sa carte, et dit « commune avec » quand elle l'est encore", async () => {
    const onSelectSlot = vi.fn();
    renderPanel(DEVIATION, onSelectSlot);
    const from = placementLabel(1, "18:00", "Matéo");
    const to = placementLabel(4, "19:00", "Brel");
    // Repliée par défaut : la ligne de détail ne se voit pas.
    expectNotShown(contains(`${from} → `));
    await userEvent.click(teamButton(lineOf("tA", ["U11B", "U11C"])));
    const item = teamItem(lineOf("tA", ["U11B", "U11C"]));

    const movedButton = within(item).getByRole("button", { name: contains(`${from} → ${to}`) });
    expect(movedButton).toHaveTextContent(PLANNING_TEXTS.sharedWithDetail(["U11B"]));
    await userEvent.click(movedButton);
    expect(onSelectSlot).toHaveBeenCalledTimes(1);
    expect(onSelectSlot).toHaveBeenCalledWith("slot-A");

    // Aucune mention « plus commune » n'existe (v6 majeur 2).
    expect(screen.queryByText(/plus commune/i)).not.toBeInTheDocument();
  });

  it("les non replacées sont détaillées sous leur équipe : RAISON en clair (« Séances réduites » comprise), raison nulle sans étiquette, « commune avec » si servie", async () => {
    renderPanel();
    await userEvent.click(teamButton(lineOf("tR")));
    await userEvent.click(teamButton(lineOf("tL")));
    const reducedItem = teamItem(lineOf("tR"));
    expect(within(reducedItem).getByText(toReplaceReasonLabel("team_reduced"))).toBeInTheDocument();
    expect(within(reducedItem).getByText(contains(placementLabel(5, "20:00", "Matéo")))).toBeInTheDocument();

    const lostItem = teamItem(lineOf("tL"));
    expect(within(lostItem).getByText(toReplaceReasonLabel("venue_closed"))).toBeInTheDocument();
    expect(within(lostItem).queryByText("venue_closed")).not.toBeInTheDocument();
    const sharedLost = within(lostItem).getByText(contains(placementLabel(3, "18:00", "Salle Bleue"))).closest("li");
    expect(sharedLost).toHaveTextContent(PLANNING_TEXTS.sharedWithDetail(["U11B"]));
    // La ligne à raison NULLE n'a ni étiquette ni mention commune.
    const nullLost = within(lostItem).getByText(contains(placementLabel(2, "17:00", "Salle Bleue"))).closest("li");
    expect(nullLost).not.toBeNull();
    for (const reason of ["team_reduced", "venue_closed", "venue_disabled", "unknown"]) {
      expect(nullLost).not.toHaveTextContent(toReplaceReasonLabel(reason));
    }
    expect(nullLost?.textContent ?? "").not.toMatch(/commune/);
    // Les lignes « à replacer » restent du texte (seule la ligne d'équipe, qui déplie, est un bouton).
    expect(within(sharedLost as HTMLElement).queryByRole("button")).not.toBeInTheDocument();
    expect(within(nullLost as HTMLElement).queryByRole("button")).not.toBeInTheDocument();
  });

  it("sans onSelectSlot, aucune ligne de DÉTAIL n'est un bouton (seuls les boutons de dépliage restent)", async () => {
    renderPanel();
    await userEvent.click(rankButton(RANK_S));
    await userEvent.click(teamButton(lineOf("tA", ["U11B", "U11C"])));
    const buttons = within(screen.getByRole("region", { name: titleOneWeek() })).getAllByRole("button");
    for (const button of buttons) {
      expect(button, `« ${button.textContent} » n'est qu'un bouton de dépliage`).toHaveAttribute("aria-expanded");
    }
  });

  it("Q7 — un rang qui PERD des séances est ouvert, un rang sans perte est replié ; les synthèses et le pied restent visibles", () => {
    renderPanel();
    expect(rankButton(RANK_C)).toHaveAttribute("aria-expanded", "true");
    expect(rankButton(RANK_S)).toHaveAttribute("aria-expanded", "false");
    // Rang C ouvert : ses lignes d'équipe se voient ; rang S replié : les siennes non.
    expect(screen.getByText(lineOf("tR"))).toBeVisible();
    expectNotShown(lineOf("tS1"));
    expectNotShown(lineOf("tS2"));
    expect(screen.getByText(PLANNING_TEXTS.rankReportLine(RANK_S))).toBeVisible();
    expect(screen.getByText(PLANNING_TEXTS.rankReportFooter(4, 2, 3))).toBeVisible();
  });

  it("Q7 — un rang sans AUCUNE équipe à lister (toutes inchangées) n'est pas un bouton ; un rang qui n'a qu'une équipe en pause en est un", () => {
    const calm = rank({ tierLabel: "S", tierWeight: 100, teams: 2, socleSessions: 4, keptSessions: 4, unchanged: 2 });
    const pausedOnly = rank({ tierLabel: "C", teams: 1, socleSessions: 0, keptSessions: 0, paused: 1, unchanged: 0 });
    renderPanel({
      ...DEVIATION,
      moved: [],
      unplaced: [],
      changedSlotIds: [],
      teams: [
        team({ teamId: "tS1", tierLabel: "S", tierWeight: 100, socleSessions: 2, keptSessions: 2 }),
        team({ teamId: "tS2", tierLabel: "S", tierWeight: 100, tierOrder: 1, socleSessions: 2, keptSessions: 2 }),
        team({ teamId: "tP", paused: true, daysKept: null, keptSessions: 0 }),
      ],
      ranks: [calm, pausedOnly],
    });

    // Rang tout inchangé : sa synthèse se lit, en simple texte — ni bouton ni chevron à déplier pour rien.
    const calmLine = screen.getByText(PLANNING_TEXTS.rankReportLine(calm));
    expect(calmLine).toBeVisible();
    expect(calmLine.closest("button"), "un rang sans équipe à lister n'est pas un bouton").toBeNull();
    expect(screen.queryByRole("button", { name: contains(PLANNING_TEXTS.rankReportLine(calm)) })).not.toBeInTheDocument();

    // Rang avec une seule équipe en pause : il a quelque chose à montrer, il reste un bouton.
    expect(rankButton(pausedOnly)).toHaveAttribute("aria-expanded");
  });

  it("Q7 — cliquer (ou Entrée / Espace au clavier) sur une ligne de rang la déplie puis la replie", async () => {
    const user = userEvent.setup();
    renderPanel();
    await user.click(rankButton(RANK_S));
    expect(rankButton(RANK_S)).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByText(lineOf("tS1"))).toBeVisible();
    await user.click(rankButton(RANK_S));
    expect(rankButton(RANK_S)).toHaveAttribute("aria-expanded", "false");
    expectNotShown(lineOf("tS1"));

    rankButton(RANK_S).focus();
    await user.keyboard("{Enter}");
    expect(rankButton(RANK_S)).toHaveAttribute("aria-expanded", "true");
    await user.keyboard(" ");
    expect(rankButton(RANK_S)).toHaveAttribute("aria-expanded", "false");
  });

  it("Q7 — une équipe à détail est un bouton REPLIÉ par défaut qui déplie ses lignes ; sans détail (pause, seulement des nouvelles), pas de bouton", async () => {
    const user = userEvent.setup();
    renderPanel();
    const reduced = teamButton(lineOf("tR"));
    expect(reduced).toHaveAttribute("aria-expanded", "false");
    expectNotShown(toReplaceReasonLabel("team_reduced"));
    await user.click(reduced);
    expect(teamButton(lineOf("tR"))).toHaveAttribute("aria-expanded", "true");
    expect(within(teamItem(lineOf("tR"))).getByText(toReplaceReasonLabel("team_reduced"))).toBeVisible();

    // U15F (en pause) et U9B (rien que des nouvelles) : de simples lignes.
    for (const id of ["tP", "tN"]) {
      expect(screen.getByText(lineOf(id)).closest("button"), `${TEAMS[id].name} n'a pas de bouton`).toBeNull();
    }
    expect(screen.queryByRole("button", { name: contains(lineOf("tP")) })).not.toBeInTheDocument();
  });

  it("la ligne la plus longue reste lisible, rendue en entier (plan v6 f)", () => {
    const longest = team({ teamId: "tA", socleSessions: 5, keptSessions: 3, lost: 1, reducedByChoice: 1, chosenSessions: 4, movedSessions: 1, newSessions: 1, changed: true, sharedKept: 2, sharedWith: ["tC", "tB"] });
    renderPanel({ ...DEVIATION, teams: [longest], ranks: [rank({ tierLabel: "C", teams: 1, socleSessions: 5, keptSessions: 3, lost: 1, chosenReductions: 1, newSessions: 1, unchanged: 0 })] });
    expect(screen.getByText("U11A : jour gardé, 3 séances sur 5, dont 1 perdue, 1 déplacée, 1 nouvelle (réduction choisie à 4) — 2 communes avec U11B et U11C")).toBeInTheDocument();
  });

  it("aucun rang servi (aucune équipe n'a de séance) → ne rend RIEN (pas de région fantôme)", () => {
    const { container } = renderPanel({ ...DEVIATION, moved: [], unplaced: [], changedSlotIds: [], teams: [], ranks: [] });
    expect(container).toBeEmptyDOMElement();
  });

  it("est accessible (axe)", async () => {
    const { container } = renderPanel(DEVIATION, vi.fn());
    expect(await axe(container)).toHaveNoViolations();
  });
});
