import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { Fixture, TeamMatchHabit, Venue, VenueMatchWindow, VenueUnavailability } from "./api";
import type { EnvelopeResult } from "./lib/envelope";
import { PlacementPanel } from "./PlacementPanel";

const fixture: Fixture = {
  id: "fx-1",
  teamId: "team-1",
  seasonId: "s",
  competitionId: null,
  matchDate: "2026-10-03", // a Saturday
  homeAway: "HOME",
  opponentLabel: "Voisins",
  status: "UNPLACED",
  venueId: null,
  kickoffTime: null,
  externalRef: null,
  fbiVenueLabel: null,
  placementSource: null,
  unplacedReason: null,
};
const venues: Venue[] = [
  { id: "venue-1", name: "Gymnase Alpha", color: null },
  { id: "venue-2", name: "Gymnase Beta", color: null },
];

// Mapped envelope: 14:00 is inside, 20:00 is outside.
const mappedEnvelope: EnvelopeResult = {
  mapped: true,
  windows: [{ id: "w", league: "AURA", category: "U13", level: "DEPARTEMENTAL", gender: null, dayOfWeek: 6, kickoffMin: "13:00", kickoffMax: "18:00" }],
  dayOk: true,
  timeOk: (k) => "14:00" === k,
};
const openEnvelope: EnvelopeResult = { mapped: false, windows: [], dayOk: false, timeOk: () => false };

interface Overrides {
  matchWindows?: VenueMatchWindow[];
  unavailabilities?: VenueUnavailability[];
  habits?: TeamMatchHabit[];
  fixture?: Fixture;
  onUnplace?: () => void;
  onToggleLock?: () => void;
  onStartSwap?: () => void;
  onEdit?: () => void;
  onDelete?: () => void;
  onSubmit?: () => void;
  onReopen?: () => void;
}

function renderPanel(envelope: EnvelopeResult, onPlace = vi.fn(), overrides: Overrides = {}) {
  render(
    <PlacementPanel
      fixture={overrides.fixture ?? fixture}
      venues={venues}
      matchWindows={overrides.matchWindows ?? []}
      unavailabilities={overrides.unavailabilities ?? []}
      habits={overrides.habits ?? []}
      teamLabel="U13"
      categoryLabel="U13"
      envelope={envelope}
      busy={false}
      onClose={vi.fn()}
      onPlace={onPlace}
      onUnplace={overrides.onUnplace ?? vi.fn()}
      onToggleLock={overrides.onToggleLock ?? vi.fn()}
      onStartSwap={overrides.onStartSwap ?? vi.fn()}
      onEdit={overrides.onEdit ?? vi.fn()}
      onDelete={overrides.onDelete ?? vi.fn()}
      onSubmit={overrides.onSubmit ?? vi.fn()}
      onReopen={overrides.onReopen ?? vi.fn()}
    />,
  );
  return onPlace;
}

describe("PlacementPanel — rappel de la raison venue_lost (P2-52)", () => {
  it("rappelle « le gymnase n'est plus affilié » tourné vers le prochain geste", () => {
    renderPanel(openEnvelope, vi.fn(), { fixture: { ...fixture, unplacedReason: "venue_lost" } });
    expect(screen.getByText(/Le gymnase n'est plus affilié au club — choisissez-en un autre\./)).toBeInTheDocument();
  });

  it("ne rappelle rien quand le match n'a pas perdu sa salle (falsification)", () => {
    renderPanel(openEnvelope, vi.fn(), { fixture: { ...fixture, unplacedReason: null } });
    expect(screen.queryByText(/n'est plus affilié au club/)).toBeNull();
  });
});

describe("PlacementPanel", () => {
  it("blocks placement out of the envelope when the team maps", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(mappedEnvelope);

    await user.selectOptions(screen.getByLabelText("Gymnase"), "venue-1");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "20:00");

    expect(screen.getByText(/Hors fenêtre autorisée/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Placer" })).toBeDisabled();
    expect(onPlace).not.toHaveBeenCalled();
  });

  it("allows and emits a placement inside the envelope", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(mappedEnvelope);

    await user.selectOptions(screen.getByLabelText("Gymnase"), "venue-1");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "14:00");

    const place = screen.getByRole("button", { name: "Placer" });
    expect(place).toBeEnabled();
    await user.click(place);
    expect(onPlace).toHaveBeenCalledWith({ venueId: "venue-1", kickoffTime: "14:00" });
  });

  it("never blocks an unmapped team (advisory only)", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(openEnvelope);

    await user.selectOptions(screen.getByLabelText("Gymnase"), "venue-1");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "23:00");

    expect(screen.getByRole("button", { name: "Placer" })).toBeEnabled();
    await user.click(screen.getByRole("button", { name: "Placer" }));
    expect(onPlace).toHaveBeenCalledOnce();
  });

  // ── Capacity guards (P1-4 PR B) ──────────────────────────────────────────

  it("only offers match venues once the club declared windows, and blocks outside them", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(openEnvelope, vi.fn(), {
      // venue-1 is a match venue on Saturdays 14:00-18:00; venue-2 is training-only.
      matchWindows: [{ id: "w1", venueId: "venue-1", dayOfWeek: 6, startTime: "14:00", endTime: "18:00" }],
    });

    // The selector masks the CHOICE: training-only venue-2 is not offered.
    expect(screen.queryByRole("option", { name: "Gymnase Beta" })).not.toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText("Gymnase"), "venue-1");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "20:00");
    expect(screen.getByText(/Hors fenêtre d'accès match \(14:00–18:00\)/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Placer" })).toBeDisabled();
    expect(onPlace).not.toHaveBeenCalled();
  });

  it("blocks a day without any access window on the venue", async () => {
    const user = userEvent.setup();
    // Sunday-only window; the fixture is on a Saturday.
    renderPanel(openEnvelope, vi.fn(), {
      matchWindows: [{ id: "w1", venueId: "venue-1", dayOfWeek: 7, startTime: "09:00", endTime: "18:00" }],
    });

    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "14:00");
    expect(screen.getByText(/Pas d'accès match le samedi/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Placer" })).toBeDisabled();
  });

  it("prefills venue and kickoff from the team's habit of that weekday — guards stay sovereign", async () => {
    // Saturday habit 15:30 at venue-2: both fields prefilled, hint shown.
    const user = userEvent.setup();
    const onPlace = renderPanel(openEnvelope, vi.fn(), {
      habits: [{ id: "h1", teamId: "team-1", dayOfWeek: 6, kickoffTime: "15:30", venueId: "venue-2" }],
    });

    expect(screen.getByText(/Habitude : 15:30 · Gymnase Beta/)).toBeInTheDocument();
    expect(screen.getByLabelText("Gymnase")).toHaveValue("venue-2");
    expect(screen.getByLabelText("Heure de coup d'envoi")).toHaveValue("15:30");

    await user.click(screen.getByRole("button", { name: "Placer" }));
    expect(onPlace).toHaveBeenCalledWith({ venueId: "venue-2", kickoffTime: "15:30" });
  });

  it("blocks an unavailable venue and keeps the full list when no window exists", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(openEnvelope, vi.fn(), {
      // No window anywhere (club has not adopted the data) → full list, no
      // window guard — but the unavailability still bites.
      unavailabilities: [{ id: "u1", venueId: "venue-1", startDate: "2026-10-01", endDate: "2026-10-05", label: "travaux" }],
    });

    expect(screen.getByRole("option", { name: "Gymnase Beta" })).toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText("Gymnase"), "venue-1");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "14:00");
    expect(screen.getByText(/indisponible du 1 oct\. au 5 oct\. \(travaux\)/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Placer" })).toBeDisabled();

    // The other venue stays placeable.
    await user.selectOptions(screen.getByLabelText("Gymnase"), "venue-2");
    expect(screen.getByRole("button", { name: "Placer" })).toBeEnabled();
    await user.click(screen.getByRole("button", { name: "Placer" }));
    expect(onPlace).toHaveBeenCalledWith({ venueId: "venue-2", kickoffTime: "14:00" });
  });

  // ── Manual loop (P1-4 PR E1) ─────────────────────────────────────────────

  const placedFixture: Fixture = { ...fixture, status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", placementSource: "MANUAL" };

  it("on a placed match the main action is Déplacer, disabled while nothing changed", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(openEnvelope, vi.fn(), { fixture: placedFixture });

    const move = screen.getByRole("button", { name: "Déplacer" });
    expect(move).toBeDisabled(); // unchanged placement — nothing to move

    await user.clear(screen.getByLabelText("Heure de coup d'envoi"));
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "18:00");
    expect(move).toBeEnabled();
    await user.click(move);
    expect(onPlace).toHaveBeenCalledWith({ venueId: "venue-1", kickoffTime: "18:00" });
  });

  it("offers Dé-placer, swap and lock-toggle on a placed match", async () => {
    const user = userEvent.setup();
    const onUnplace = vi.fn();
    const onStartSwap = vi.fn();
    renderPanel(openEnvelope, vi.fn(), { fixture: placedFixture, onUnplace, onStartSwap });

    await user.click(screen.getByRole("button", { name: "Dé-placer" }));
    expect(onUnplace).toHaveBeenCalledOnce();
    await user.click(screen.getByRole("button", { name: "Échanger avec…" }));
    expect(onStartSwap).toHaveBeenCalledOnce();
  });

  it("shows « Rendre au système » on a manual anchor", async () => {
    const user = userEvent.setup();
    const onToggleLock = vi.fn();
    renderPanel(openEnvelope, vi.fn(), { fixture: placedFixture, onToggleLock });
    await user.click(screen.getByRole("button", { name: "Rendre au système" }));
    expect(onToggleLock).toHaveBeenCalledOnce();
  });

  it("shows « Verrouiller » on a SOLVER placement", () => {
    renderPanel(openEnvelope, vi.fn(), { fixture: { ...placedFixture, placementSource: "SOLVER" } });
    expect(screen.getByRole("button", { name: "Verrouiller" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Rendre au système" })).not.toBeInTheDocument();
  });

  it("deletes only after the confirmation dialog", async () => {
    const user = userEvent.setup();
    const onDelete = vi.fn();
    renderPanel(openEnvelope, vi.fn(), { fixture: placedFixture, onDelete });

    await user.click(screen.getByRole("button", { name: "Supprimer" }));
    expect(onDelete).not.toHaveBeenCalled(); // the dialog gates the gesture
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(/Supprimer le match contre/)).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: "Supprimer" }));
    expect(onDelete).toHaveBeenCalledOnce();
  });

  // ── Weekly loop close (RMM-1 PR 1) ───────────────────────────────────────

  it("offers « Marquer saisi dans FBI » on a placed match and emits onSubmit", async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    renderPanel(openEnvelope, vi.fn(), { fixture: placedFixture, onSubmit });

    const submit = screen.getByRole("button", { name: "Marquer saisi dans FBI" });
    await user.click(submit);
    expect(onSubmit).toHaveBeenCalledOnce();
  });

  it("does not offer « Marquer saisi dans FBI » on an unplaced match (nothing placed yet)", () => {
    renderPanel(openEnvelope, vi.fn()); // default fixture is UNPLACED
    expect(screen.queryByRole("button", { name: "Marquer saisi dans FBI" })).not.toBeInTheDocument();
  });

  it("on a SUBMITTED match: French status, anchor sentence, and the « Corriger » exit — edit gestures gone", async () => {
    const user = userEvent.setup();
    const onReopen = vi.fn();
    renderPanel(openEnvelope, vi.fn(), { fixture: { ...placedFixture, status: "SUBMITTED" }, onReopen });

    expect(screen.getByText("Saisi dans FBI")).toBeInTheDocument();
    expect(screen.getByText(/les prochaines générations ne le déplaceront plus/)).toBeInTheDocument();

    // The edit gestures stay blocked while submitted.
    expect(screen.queryByRole("button", { name: "Déplacer" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Supprimer" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Marquer saisi dans FBI" })).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Corriger — repasser en Placé" }));
    expect(onReopen).toHaveBeenCalledOnce();
  });

  it("on a VALIDATED match: French status, finality sentence, and NO exit — the league owns it", () => {
    renderPanel(openEnvelope, vi.fn(), { fixture: { ...placedFixture, status: "VALIDATED" } });

    expect(screen.getByText("Validé ligue")).toBeInTheDocument();
    expect(screen.getByText(/La ligue a validé ce match/)).toBeInTheDocument();
    // No exit at all — not even the repair path.
    expect(screen.queryByRole("button", { name: "Corriger — repasser en Placé" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Marquer saisi dans FBI" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Déplacer" })).not.toBeInTheDocument();
  });
});
