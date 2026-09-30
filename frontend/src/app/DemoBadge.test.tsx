import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import type { MeResponse } from "@/shared/session/api";
import { DemoBadge } from "./DemoBadge";

let meData: Partial<MeResponse> | undefined;

vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: meData }),
}));

const club = (overrides: Record<string, unknown>): MeResponse["club"] =>
  ({ id: "c1", name: "BCCL", onboardingCompleted: true, logoUrl: null, isDemo: false, ...overrides }) as unknown as MeResponse["club"];

afterEach(() => {
  meData = undefined;
});

describe("DemoBadge", () => {
  it("affiche la pastille « Démo » pour un club de démonstration", () => {
    meData = { club: club({ isDemo: true }) };
    render(<DemoBadge />);
    expect(screen.getByText("Démo")).toBeInTheDocument();
  });

  it("n'affiche RIEN pour un vrai club (isDemo faux)", () => {
    meData = { club: club({ isDemo: false }) };
    const { container } = render(<DemoBadge />);
    expect(screen.queryByText("Démo")).toBeNull();
    expect(container).toBeEmptyDOMElement();
  });

  it("n'affiche rien tant qu'aucun club n'est chargé", () => {
    meData = {};
    const { container } = render(<DemoBadge />);
    expect(container).toBeEmptyDOMElement();
  });
});
