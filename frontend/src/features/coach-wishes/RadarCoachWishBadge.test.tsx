import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { CoachWishCampaign } from "./campaignApi";

import { RadarCoachWishBadge } from "./RadarCoachWishBadge";

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

describe("RadarCoachWishBadge", () => {
  // Depuis la fusion de la fenêtre (2026-10-09), le badge ne porte PLUS de bouton : l'ouverture
  // passe par l'unique bouton « Doléances » de la carte radar (testé dans RadarPanel.test.tsx).
  it("n'affiche aucun badge sans campagne", () => {
    const { container } = render(<RadarCoachWishBadge campaign={null} />);
    expect(screen.queryByText(/ont répondu/)).not.toBeInTheDocument();
    expect(container).toBeEmptyDOMElement();
  });

  it("affiche le badge de suivi quand une campagne existe", () => {
    render(<RadarCoachWishBadge campaign={campaign()} />);
    const badge = screen.getByText(/2\/3 coachs ont répondu · 1 à traiter/);
    expect(badge).toBeInTheDocument();
    // P4-178 — repli AA : StatusPill accent, le texte reste `text-foreground`.
    expect(badge).not.toHaveClass("text-accent");
  });

  it("omet « à traiter » quand rien n'est en attente", () => {
    render(<RadarCoachWishBadge campaign={campaign({ openWishCount: 0 })} />);
    expect(screen.getByText(/2\/3 coachs ont répondu/)).toBeInTheDocument();
    expect(screen.queryByText(/à traiter/)).not.toBeInTheDocument();
  });
});
