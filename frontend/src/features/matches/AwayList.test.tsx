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
  ...over,
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

  // ── RMM-0 (§6bis B5) — l'heure porte les conflits de coach : jamais coupée sans secours ─────
  it("B5 — la ligne extérieur n'est plus tronquée et porte un title de secours avec l'heure", () => {
    render(<AwayList fixtures={[away({ kickoffTime: "15:30" })]} teams={teams} habits={[]} onEdit={vi.fn()} onDelete={vi.fn()} />);

    const outer = screen.getByText("SM2").parentElement as HTMLElement;
    expect(outer).not.toHaveClass("truncate");
    expect(outer.getAttribute("title")).toContain("15:30");
  });
});
