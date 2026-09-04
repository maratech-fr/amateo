import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { CalendarEntry } from "@/features/cockpit/api";

import type { CoachWishCampaign } from "./campaignApi";

// Le dialog est monté à la demande — on le neutralise, seul le bouton + badge nous intéressent.
vi.mock("./CampaignDialog", () => ({ CampaignDialog: () => null }));

import { RadarCoachWishAction } from "./RadarCoachWishAction";

const entry: CalendarEntry = {
  id: "e1",
  kind: "period",
  title: "Vacances",
  startDate: "2026-02-16",
  endDate: "2026-03-01",
  isDisruptive: false,
  periodType: "holiday",
  schoolHolidayId: null,
  parentEntryId: null,
  status: "active",
  createdBy: null,
  redatable: false,
};

const campaign = (over: Partial<CoachWishCampaign> = {}): CoachWishCampaign => ({
  id: "c1",
  calendarEntryId: "e1",
  deadline: "2027-06-30",
  weeks: ["2026-02-16"],
  teamIds: ["t1"],
  totalCoachCount: 3,
  respondedCoachCount: 2,
  openWishCount: 1,
  lastReminderAt: null,
  coaches: [],
  ...over,
});

describe("RadarCoachWishAction", () => {
  it("montre le bouton mais aucun badge sans campagne", () => {
    render(<RadarCoachWishAction entry={entry} season={null} campaign={null} />);
    expect(screen.getByRole("button", { name: /Solliciter les coachs/ })).toBeInTheDocument();
    expect(screen.queryByText(/ont répondu/)).not.toBeInTheDocument();
  });

  it("affiche le badge de suivi quand une campagne existe", () => {
    render(<RadarCoachWishAction entry={entry} season={null} campaign={campaign()} />);
    expect(screen.getByText(/2\/3 coachs ont répondu · 1 à traiter/)).toBeInTheDocument();
  });

  it("omet « à traiter » quand rien n'est en attente", () => {
    render(<RadarCoachWishAction entry={entry} season={null} campaign={campaign({ openWishCount: 0 })} />);
    expect(screen.getByText(/2\/3 coachs ont répondu/)).toBeInTheDocument();
    expect(screen.queryByText(/à traiter/)).not.toBeInTheDocument();
  });
});
