import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { listboxTrigger, openListbox, pickListboxOption } from "@/test/pickListboxOption";

import type { Conflict, Fixture, Venue, VenueMatchWindow } from "./api";
import type { EnvelopeResult } from "./lib/envelope";
import { PlacementPanel } from "./PlacementPanel";
import { MATCHES_TEXTS } from "./texts";

/**
 * Parcours P1-P3 du lot « ouverture datée », au niveau du panneau : le gymnase importé reste affiché, le
 * refus d'accès (et lui seul) offre « Ouvrir ce gymnase ce jour-là », et une fois la ligne servie
 * « Placer » s'active sans ressaisie. Le POST réel est prouvé par `OpenVenueDialog.test.tsx`, la
 * garde serveur par `FixtureApiTest`.
 */
const create = vi.hoisted(() => vi.fn());
vi.mock("./queries", async (original) => ({
  ...(await original<typeof import("./queries")>()),
  useCreateVenueMatchWindow: () => ({ mutate: create, isPending: false }),
}));
vi.mock("@/shared/session/queries", () => ({
  useWorkingSeason: () => ({ id: "season-1", name: "2026-2027", startDate: "2026-09-01", endDate: "2027-06-30", isCurrent: true, isReadonly: false }),
}));

const HOLIDAY = "2026-11-11";
const venues: Venue[] = [
  { id: "venue-1", name: "Gymnase Alpha", color: null, externalLabels: [] },
  { id: "venue-2", name: "Gymnase Beta", color: null, externalLabels: [] },
  { id: "venue-3", name: "Gymnase Gamma", color: null, externalLabels: [] },
];
const line = (over: Partial<VenueMatchWindow>): VenueMatchWindow => ({ id: "w", venueId: "venue-1", dayOfWeek: 6, appliesOnPublicHolidays: false, openingDate: null, label: null, startTime: "14:00", endTime: "18:00", ...over });
const saturdayAlpha = line({});
const opening = (venueId: string, date = HOLIDAY): VenueMatchWindow => line({ id: `o-${venueId}`, venueId, dayOfWeek: null, openingDate: date, startTime: "14:00", endTime: "20:00" });

const fixture = (over: Partial<Fixture> = {}): Fixture => ({
  id: "fx-1",
  teamId: "team-1",
  seasonId: "s",
  competitionId: "comp-1",
  matchDate: HOLIDAY,
  homeAway: "HOME",
  opponentLabel: "Voisins",
  status: "UNPLACED",
  venueId: "venue-2",
  kickoffTime: "15:00",
  externalRef: "FBI-1",
  fbiVenueLabel: null,
  placementSource: null,
  unplacedReason: null,
  neutralGround: false,
  reviewState: "NEW",
  reviewedAt: null,
  pendingDeviations: [],
  ffbbRencontreId: null,
  opponentOrganismeCode: null,
  opponentTeamKey: null,
  suggestedVenueId: null,
  ...over,
});
const openEnvelope: EnvelopeResult = { mapped: false, windows: [], dayOk: false, timeOk: () => false, holiday: false };

interface Options {
  fixture?: Fixture;
  matchWindows?: VenueMatchWindow[];
  holidays?: string[];
  envelope?: EnvelopeResult;
  unavailabilities?: { id: string; venueId: string; startDate: string; endDate: string; label: string | null }[];
  closures?: { id: string; venueId: string; startDate: string; endDate: string; title: string }[];
  matchMinutes?: number;
  conflicts?: Conflict[];
  onPlace?: () => void;
}

function panel(o: Options = {}) {
  return (
    <PlacementPanel
      fixture={o.fixture ?? fixture()}
      venues={venues}
      guards={{ state: "ready", matchWindows: o.matchWindows ?? [saturdayAlpha], unavailabilities: o.unavailabilities ?? [], closures: (o.closures ?? []) as never, holidays: o.holidays ?? [HOLIDAY], retry: vi.fn() }}
      habits={[]}
      teamLabel="U13"
      categoryLabel="U13"
      envelope={o.envelope ?? openEnvelope}
      busy={false}
      matchMinutes={o.matchMinutes ?? 90}
      conflicts={o.conflicts ?? []}
      onClose={vi.fn()}
      onPlace={o.onPlace ?? vi.fn()}
      onUnplace={vi.fn()}
      onToggleLock={vi.fn()}
      onStartSwap={vi.fn()}
      onEdit={vi.fn()}
      onDelete={vi.fn()}
      onSubmit={vi.fn()}
      onReopen={vi.fn()}
    />
  );
}

const placeButton = () => screen.getByRole("button", { name: /^(Placer|Confirmer ce placement)$/ });
const openAction = () => screen.queryByRole("button", { name: MATCHES_TEXTS.openVenueAction });

beforeEach(() => create.mockClear());

describe("PlacementPanel — le gymnase importé reste (D6)", () => {
  it("un gymnase importé hors de la liste filtrée reste choisi, avec sa sous-ligne, jamais remplacé en silence", async () => {
    const user = userEvent.setup();
    render(panel());
    expect(listboxTrigger("Gymnase")).toHaveTextContent("Gymnase Beta");
    const list = await openListbox(user, "Gymnase");
    const option = within(list).getByRole("option", { name: /Gymnase Beta/ });
    expect(option).toHaveTextContent(MATCHES_TEXTS.venueNoAccessThatDay);
    expect(option).not.toHaveAttribute("aria-disabled", "true");
  });

  it("un gymnase avec une ouverture ce jour-là est proposé, sans sous-ligne", async () => {
    const user = userEvent.setup();
    render(panel({ matchWindows: [saturdayAlpha, opening("venue-3")] }));
    const option = within(await openListbox(user, "Gymnase")).getByRole("option", { name: /Gymnase Gamma/ });
    expect(option).not.toHaveTextContent(MATCHES_TEXTS.venueNoAccessThatDay);
  });

  it("un samedi ordinaire sans gymnase importé : liste et valeur initiale inchangées", async () => {
    const user = userEvent.setup();
    render(panel({ fixture: fixture({ matchDate: "2026-11-21", venueId: null, kickoffTime: null }), holidays: [] }));
    expect(listboxTrigger("Gymnase")).toHaveTextContent("Gymnase Alpha");
    const list = await openListbox(user, "Gymnase");
    expect(within(list).queryByRole("option", { name: /Gymnase Beta/ })).toBeNull();
    expect(within(list).queryByRole("option", { name: /Gymnase Gamma/ })).toBeNull();
  });

  it("une ouverture d'une autre date ne propose pas ce gymnase", async () => {
    const user = userEvent.setup();
    render(panel({ matchWindows: [saturdayAlpha, opening("venue-3", "2026-11-18")] }));
    expect(within(await openListbox(user, "Gymnase")).queryByRole("option", { name: /Gymnase Gamma/ })).toBeNull();
  });
});

describe("PlacementPanel — le raccourci d'ouverture (D5)", () => {
  it("un match refusé faute d'accès offre le bouton et « Placer » reste inactif", () => {
    render(panel());
    expect(screen.getByText("Pas d'accès match le mer. 11 nov. (jour férié) à Gymnase Beta.")).toBeInTheDocument();
    expect(openAction()).toBeInTheDocument();
    expect(placeButton()).toBeDisabled();
  });

  it("hors plage aussi", () => {
    render(panel({ fixture: fixture({ kickoffTime: "21:00" }), matchWindows: [saturdayAlpha, opening("venue-2")] }));
    expect(screen.getByText("Hors des accès match de ce jour (14:00 – 20:00).")).toBeInTheDocument();
    expect(openAction()).toBeInTheDocument();
  });

  it("jamais pour un amical, seulement averti", () => {
    render(panel({ fixture: fixture({ competitionId: null }) }));
    expect(openAction()).toBeNull();
    expect(placeButton()).toBeEnabled();
  });

  it("jamais sur une indisponibilité : une ouverture ne lève pas un gymnase fermé", () => {
    render(panel({ unavailabilities: [{ id: "u", venueId: "venue-2", startDate: HOLIDAY, endDate: HOLIDAY, label: null }] }));
    expect(openAction()).toBeNull();
    expect(placeButton()).toBeDisabled();
  });

  it("jamais sur une fermeture du calendrier", () => {
    render(panel({ closures: [{ id: "c", venueId: "venue-2", startDate: HOLIDAY, endDate: HOLIDAY, title: "Mairie" }] }));
    expect(openAction()).toBeNull();
    expect(placeButton()).toBeDisabled();
  });

  it("après l'ouverture servie, le refus disparaît et « Placer » s'active sans rien ressaisir, avec le gymnase et l'heure importés", async () => {
    const user = userEvent.setup();
    const onPlace = vi.fn();
    const { rerender } = render(panel({ onPlace }));
    expect(placeButton()).toBeDisabled();

    rerender(panel({ onPlace, matchWindows: [saturdayAlpha, opening("venue-2")] }));

    expect(openAction()).toBeNull();
    expect(placeButton()).toBeEnabled();
    await user.click(placeButton());
    expect(onPlace).toHaveBeenCalledWith({ venueId: "venue-2", kickoffTime: "15:00" });
  });

  it("choisir un autre gymnase autorisé fait disparaître le bouton (le refus suit la sélection)", async () => {
    const user = userEvent.setup();
    render(panel({ matchWindows: [saturdayAlpha, opening("venue-1")] }));
    await pickListboxOption(user, "Gymnase", /Gymnase Alpha/);
    expect(openAction()).toBeNull();
  });
});

describe("PlacementPanel — la plage proposée par le raccourci (D4)", () => {
  async function proposed(kickoff: string | null, matchMinutes: number): Promise<[string, string]> {
    const user = userEvent.setup();
    render(panel({ fixture: fixture({ kickoffTime: kickoff }), matchMinutes }));
    await user.click(openAction() as HTMLElement);
    const dialog = screen.getByRole("dialog");
    return [
      (within(dialog).getByLabelText(MATCHES_TEXTS.openingStartLabel) as HTMLInputElement).value,
      (within(dialog).getByLabelText(MATCHES_TEXTS.openingEndLabel) as HTMLInputElement).value,
    ];
  }

  it("18:30, 1h30 → 18:00 – 22:00 : assez large pour plusieurs matchs", async () => {
    expect(await proposed("18:30", 90)).toEqual(["18:00", "22:00"]);
  });

  it("21:30 → 21:00 – 23:00 : plafonnée à 23:00", async () => {
    expect(await proposed("21:30", 90)).toEqual(["21:00", "23:00"]);
  });

  it("sans heure → 14:00 – 18:00", async () => {
    expect(await proposed(null, 90)).toEqual(["14:00", "18:00"]);
  });

  it("match long : la fin couvre le match entier, arrondie au quart d'heure SUPÉRIEUR (15:10 + 280 min = 19:50 → 20:00)", async () => {
    expect(await proposed("15:10", 280)).toEqual(["15:00", "20:00"]);
  });
});

describe("PlacementPanel — ligue un férié et entraînement dans le gymnase", () => {
  it("D7 : un férié dit que la fenêtre de ligue ne s'applique pas, sans alerte ligue", () => {
    const envelope: EnvelopeResult = { mapped: true, windows: [], dayOk: false, timeOk: () => false, holiday: true };
    render(panel({ envelope, matchWindows: [saturdayAlpha, opening("venue-2")] }));
    expect(screen.getByText(MATCHES_TEXTS.leagueHolidayNote)).toBeInTheDocument();
    expect(screen.queryByText(/Hors fenêtre autorisée/)).toBeNull();
  });

  it("D7 : un mardi non férié garde l'alerte ligue", () => {
    const envelope: EnvelopeResult = { mapped: true, windows: [], dayOk: false, timeOk: () => false, holiday: false };
    render(panel({ envelope, fixture: fixture({ matchDate: "2026-11-24", kickoffTime: "20:00" }), holidays: [], matchWindows: [saturdayAlpha, opening("venue-2", "2026-11-24")] }));
    expect(screen.getByText(/Hors fenêtre autorisée/)).toBeInTheDocument();
    expect(screen.queryByText(MATCHES_TEXTS.leagueHolidayNote)).toBeNull();
  });

  const placed = fixture({ status: "PLACED", placementSource: "MANUAL", kickoffTime: "18:30", matchDate: "2026-11-18" });
  const overlap = (trainings: { teamId: string; start: string; end: string }[]): Conflict =>
    ({ type: "VENUE_TRAINING_OVERLAP", severity: 5, resolution: null, fingerprint: "f", venueId: "venue-2", fixture: { fixtureId: "fx-1" }, trainings, trainingLabels: {} }) as unknown as Conflict;

  it("D8 : un match posé ré-ouvert porte la phrase, et un entraînement ne bloque rien", () => {
    render(panel({ fixture: placed, holidays: [], matchWindows: [saturdayAlpha, opening("venue-2", "2026-11-18")], conflicts: [overlap([{ teamId: "team-1", start: "18:00", end: "19:30" }])] }));
    expect(screen.getByText(/Un entraînement occupe Gymnase Beta ce jour-là \(18:00 – 19:30.*\)\. Le match reste placé\./)).toBeInTheDocument();
  });

  it("D8 : deux, puis trois entraînements", () => {
    const two = [{ teamId: "t", start: "18:00", end: "19:30" }, { teamId: "t", start: "19:30", end: "21:00" }];
    const { unmount } = render(panel({ fixture: placed, holidays: [], conflicts: [overlap(two)] }));
    expect(screen.getByText(/Deux entraînements occupent Gymnase Beta ce jour-là/)).toBeInTheDocument();
    unmount();
    render(panel({ fixture: placed, holidays: [], conflicts: [overlap([...two, { teamId: "t", start: "21:00", end: "22:00" }])] }));
    expect(screen.getByText(/3 entraînements occupent Gymnase Beta/)).toBeInTheDocument();
  });

  it("D8 : aucun signal sans conflit de ce type", () => {
    render(panel({ fixture: placed, holidays: [] }));
    expect(screen.queryByText(/entraînement/i)).toBeNull();
  });
});
