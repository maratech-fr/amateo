import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { Fixture, Team, TeamMatchHabit } from "./api";
import { AwayList } from "./AwayList";

const away = (over: Partial<Fixture> = {}): Fixture => ({
  id: "fx-away",
  teamId: "team-1",
  seasonId: "s",
  competitionId: null,
  matchDate: "2026-10-03", // Saturday
  homeAway: "AWAY",
  opponentLabel: "Grenoble",
  status: "UNPLACED",
  venueId: null,
  kickoffTime: null,
  externalRef: null,
  fbiVenueLabel: "Halle Clemenceau",
  placementSource: null,
  unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, suggestedVenueId: null, opponentOrganismeCode: null, opponentTeamKey: null, fbiEcho: null, awayTravel: null, ...over,
});

const teams = new Map<string, Team>([["team-1", { id: "team-1", name: "SM2", sportCategoryId: "cat", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }]]);
const habit: TeamMatchHabit = { id: "h-1", teamId: "team-1", dayOfWeek: 6, kickoffTime: "20:30", venueId: null };

describe("AwayList (P1-4 PR E2 — l'extérieur visible)", () => {
  it("renders the away match with the opponent venue and the habitual hour tagged estimée", () => {
    render(<AwayList fixtures={[away()]} teams={teams} habits={[habit]} onEdit={vi.fn()} onDelete={vi.fn()} />);

    expect(screen.getByText("SM2")).toBeInTheDocument();
    expect(screen.getByText(/à Grenoble \(Halle Clemenceau\) · 20:30/)).toBeInTheDocument();
    expect(screen.getByText("heure estimée")).toBeInTheDocument();
  });

  it("a real hour is never tagged estimée; no habit that weekday = heure inconnue", () => {
    render(
      <AwayList
        fixtures={[away({ id: "fx-real", kickoffTime: "15:30" }), away({ id: "fx-sunday", matchDate: "2026-10-04" })]}
        teams={teams}
        habits={[habit]} // Saturday habit only
        onEdit={vi.fn()}
        onDelete={vi.fn()}
      />,
    );

    expect(screen.getByText(/· 15:30/)).toBeInTheDocument();
    expect(screen.queryByText("heure estimée")).not.toBeInTheDocument();
    expect(screen.getByText(/heure inconnue/)).toBeInTheDocument();
  });

  it("PR 3b — le titre de la bande est un <h2> (même niveau que « À placer » sur le Calendrier)", () => {
    render(<AwayList fixtures={[away()]} teams={teams} habits={[habit]} onEdit={vi.fn()} onDelete={vi.fn()} />);
    expect(screen.getByRole("heading", { level: 2, name: /À l'extérieur ce week-end/ })).toBeInTheDocument();
  });

  it("renders nothing when the weekend has no away match", () => {
    const { container } = render(<AwayList fixtures={[away({ homeAway: "HOME" })]} teams={teams} habits={[]} onEdit={vi.fn()} onDelete={vi.fn()} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("edits directly, deletes only through the confirmation", async () => {
    const user = userEvent.setup();
    const onEdit = vi.fn();
    const onDelete = vi.fn();
    render(<AwayList fixtures={[away()]} teams={teams} habits={[]} onEdit={onEdit} onDelete={onDelete} />);

    await user.click(screen.getByRole("button", { name: "Modifier le match contre Grenoble" }));
    expect(onEdit).toHaveBeenCalledOnce();

    await user.click(screen.getByRole("button", { name: "Supprimer le match contre Grenoble" }));
    expect(onDelete).not.toHaveBeenCalled();
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Supprimer" }));
    expect(onDelete).toHaveBeenCalledWith(expect.objectContaining({ id: "fx-away" }));
  });

  // ── PR-2a — variante LECTURE SEULE (Consulter) : sans onEdit/onDelete, aucun bouton ─────
  it("sans onEdit/onDelete (lecture seule), n'affiche NI le crayon NI la corbeille", () => {
    render(<AwayList fixtures={[away()]} teams={teams} habits={[]} />);

    expect(screen.getByText("SM2")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Modifier le match contre Grenoble" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Supprimer le match contre Grenoble" })).not.toBeInTheDocument();
  });

  // ── Amendement 2026-09-20 — le trajet est DÉRIVÉ de la rencontre (`fixture.awayTravel`) ─────
  it("affiche le trajet porté par la rencontre (fixture.awayTravel)", () => {
    render(
      <AwayList
        fixtures={[away({ awayTravel: { venueLabel: "Halle Y", city: null, precision: "VENUE", oneWayMinutes: 22, approximated: false, basis: "linked" } })]}
        teams={teams}
        habits={[]}
      />,
    );
    expect(screen.getByText("Halle Y")).toBeInTheDocument();
    expect(screen.getByText("22 min")).toBeInTheDocument();
  });

  it("une rencontre sans awayTravel reste « lieu inconnu »", () => {
    render(<AwayList fixtures={[away({ awayTravel: null })]} teams={teams} habits={[]} />);
    expect(screen.queryByText("Halle Y")).not.toBeInTheDocument();
    expect(screen.getByText("lieu inconnu")).toBeInTheDocument();
  });

  // ── PR-3a — tri commun à la colonne « Extérieur » de la grille (compareAway) ─────
  it("trie comme la grille : à même date, les sans-heure d'abord, puis par heure", () => {
    render(
      <AwayList
        // Même date, même équipe : « timed » a une heure, « unknown » n'en a pas (aucune habitude).
        fixtures={[away({ id: "timed", kickoffTime: "15:30", opponentLabel: "Timed" }), away({ id: "unknown", opponentLabel: "Unknown" })]}
        teams={teams}
        habits={[]}
      />,
    );
    const items = screen.getAllByRole("listitem");
    expect(items[0]).toHaveTextContent("Unknown"); // sans-heure d'abord
    expect(items[1]).toHaveTextContent("Timed");
  });

  // ── RMM-0 (§6bis B5) — l'heure porte les conflits de coach : jamais coupée sans secours ─────
  it("B5 — la ligne extérieur n'est plus tronquée et porte un title de secours avec l'heure", () => {
    render(<AwayList fixtures={[away({ kickoffTime: "15:30" })]} teams={teams} habits={[]} onEdit={vi.fn()} onDelete={vi.fn()} />);

    const outer = screen.getByText("SM2").parentElement as HTMLElement;
    expect(outer).not.toHaveClass("truncate");
    expect(outer.getAttribute("title")).toContain("15:30");
  });
});
