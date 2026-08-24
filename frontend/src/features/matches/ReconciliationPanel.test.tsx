import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it, vi } from "vitest";

import type { Deviation, DeviationDecision } from "./api";
import { ReconciliationPanel } from "./ReconciliationPanel";

const teamName = (id: string): string => (id === "team-1" ? "SM1" : id === "team-2" ? "SF3" : "?");

const dateDev: Deviation = {
  fixtureId: "fx-date",
  externalRef: "101137",
  division: "DF2",
  teamId: "team-1",
  status: "PLACED",
  persisting: false,
  fields: { date: { app: "2026-11-28", file: "2026-12-05" } },
};

const kickoffSubmitted: Deviation = {
  fixtureId: "fx-kick",
  externalRef: "202244",
  division: "PNM",
  teamId: "team-2",
  status: "SUBMITTED",
  persisting: true,
  fields: { kickoff: { app: "15:30", file: "17:00" }, venue: { app: "Coubertin", file: "Mateo" } },
};

/** Harnais contrôlé : il tient les décisions et les EXPOSE, pour prouver que le
 * panneau émet exactement ce qui est posé, à travers plusieurs clics successifs. */
function Harness({ deviations, onChange }: { deviations: Deviation[]; onChange?: (d: DeviationDecision[]) => void }) {
  const [decisions, setDecisions] = useState<DeviationDecision[]>([]);
  return (
    <>
      <ReconciliationPanel
        deviations={deviations}
        decisions={decisions}
        onDecisionsChange={(next) => {
          setDecisions(next);
          onChange?.(next);
        }}
        teamName={teamName}
      />
      <div data-testid="readout">{JSON.stringify(decisions)}</div>
    </>
  );
}

const readout = (): DeviationDecision[] => JSON.parse(screen.getByTestId("readout").textContent ?? "[]");

describe("ReconciliationPanel (RMM-4 — agnostique du canal)", () => {
  it("une CARTE par écart, en-tête équipe/division + n° de rencontre + statut FR", () => {
    render(<Harness deviations={[dateDev, kickoffSubmitted]} />);
    const cards = screen.getAllByRole("article");
    expect(cards).toHaveLength(2);
    expect(within(cards[0]).getByText(/SM1/)).toBeInTheDocument();
    expect(within(cards[0]).getByText(/DF2/)).toBeInTheDocument();
    expect(within(cards[0]).getByText(/101137/)).toBeInTheDocument();
    // Statut FR, jamais le code.
    expect(within(cards[0]).getByText("Placé")).toBeInTheDocument();
    expect(within(cards[1]).getByText("Saisi dans FBI")).toBeInTheDocument();
  });

  it("ne montre QUE les champs divergents", () => {
    render(<Harness deviations={[dateDev]} />);
    const card = screen.getByRole("article");
    expect(within(card).getByText("Date")).toBeInTheDocument();
    // dateDev n'a pas d'écart d'heure ni de salle.
    expect(within(card).queryByText("Heure")).not.toBeInTheDocument();
    expect(within(card).queryByText("Salle")).not.toBeInTheDocument();
    // Les deux colonnes portent les deux valeurs.
    expect(within(card).getByText("2026-11-28")).toBeInTheDocument();
    expect(within(card).getByText("2026-12-05")).toBeInTheDocument();
  });

  it("conséquence VISIBLE avant le choix : prendre la date libère le créneau", () => {
    render(<Harness deviations={[dateDev]} />);
    expect(screen.getByText(/libère le créneau/i)).toBeInTheDocument();
  });

  it("SUBMITTED/VALIDATED : signalement renforcé role=alert (« saisi dans FBI »)", () => {
    const { unmount } = render(<Harness deviations={[kickoffSubmitted]} />);
    const alert = screen.getByRole("alert");
    expect(alert).toHaveTextContent(/saisi dans FBI/i);
    unmount();
    // Un PLACED n'a PAS de bande renforcée.
    render(<Harness deviations={[dateDev]} />);
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });

  it("persisting → badge « Écart persistant »", () => {
    const { unmount } = render(<Harness deviations={[kickoffSubmitted]} />);
    expect(screen.getByText(/Écart persistant/i)).toBeInTheDocument();
    unmount();
    render(<Harness deviations={[dateDev]} />);
    expect(screen.queryByText(/Écart persistant/i)).not.toBeInTheDocument();
  });

  it("choix par CHAMP : cliquer « Prendre le fichier » pose exactement cette décision", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<Harness deviations={[dateDev]} onChange={onChange} />);

    await user.click(screen.getByRole("button", { name: /Prendre le fichier.*Date/i }));

    expect(readout()).toEqual([{ fixtureId: "fx-date", field: "date", choice: "take_file" }]);
  });

  it("callback reçoit EXACTEMENT les décisions posées, à travers plusieurs clics (toggle par champ)", async () => {
    const user = userEvent.setup();
    render(<Harness deviations={[kickoffSubmitted]} />);

    await user.click(screen.getByRole("button", { name: /Garder l'app.*Heure/i }));
    await user.click(screen.getByRole("button", { name: /Prendre le fichier.*Salle/i }));

    expect(readout()).toEqual([
      { fixtureId: "fx-kick", field: "kickoff", choice: "keep_app" },
      { fixtureId: "fx-kick", field: "venue", choice: "take_file" },
    ]);

    // Re-cliquer l'AUTRE option du même champ REMPLACE (pas de doublon).
    await user.click(screen.getByRole("button", { name: /Prendre le fichier.*Heure/i }));
    const posed = readout();
    expect(posed.filter((d) => d.field === "kickoff")).toEqual([{ fixtureId: "fx-kick", field: "kickoff", choice: "take_file" }]);
  });

  it("geste de masse « Tout prendre du fichier » pose TOUS les choix affichés", async () => {
    const user = userEvent.setup();
    render(<Harness deviations={[dateDev, kickoffSubmitted]} />);

    await user.click(screen.getByRole("button", { name: /Tout prendre du fichier/i }));

    const posed = readout();
    // 1 champ (date) + 2 champs (kickoff, venue) = 3 décisions, toutes take_file.
    expect(posed).toHaveLength(3);
    expect(posed.every((d) => d.choice === "take_file")).toBe(true);
    expect(new Set(posed.map((d) => `${d.fixtureId}|${d.field}`))).toEqual(new Set(["fx-date|date", "fx-kick|kickoff", "fx-kick|venue"]));
  });

  it("geste de masse « Tout garder » pose tous les choix en keep_app", async () => {
    const user = userEvent.setup();
    render(<Harness deviations={[dateDev, kickoffSubmitted]} />);

    await user.click(screen.getByRole("button", { name: /Tout garder/i }));
    expect(readout().every((d) => d.choice === "keep_app")).toBe(true);
    expect(readout()).toHaveLength(3);
  });
});
