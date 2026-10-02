import { fireEvent, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";
import type { MeResponse } from "@/shared/session/api";
import { setClubClock } from "@/shared/session/api";

import { DemoClockWidget } from "./DemoClockWidget";

let meData: Partial<MeResponse> | undefined;

vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: meData }) }));
vi.mock("@/shared/session/api", async (importOriginal) => {
  const original = await importOriginal<typeof import("@/shared/session/api")>();
  return { ...original, setClubClock: vi.fn() };
});

const mockSet = vi.mocked(setClubClock);

const club = (overrides: Record<string, unknown>): MeResponse["club"] =>
  ({ id: "c1", name: "BCCL", onboardingCompleted: true, logoUrl: null, isDemo: true, ...overrides }) as unknown as MeResponse["club"];

describe("DemoClockWidget", () => {
  beforeEach(() => {
    mockSet.mockReset().mockResolvedValue({ simulatedToday: null });
  });

  afterEach(() => {
    meData = undefined;
  });

  it("n'affiche RIEN pour un vrai club (isDemo faux)", () => {
    meData = { club: club({ isDemo: false }) };
    const { container } = renderWithProviders(<DemoClockWidget />);
    expect(container).toBeEmptyDOMElement();
  });

  it("affiche « Horloge : aujourd'hui » pour un club démo sans date posée", () => {
    meData = { club: club({ isDemo: true, simulatedToday: null }) };
    renderWithProviders(<DemoClockWidget />);
    expect(screen.getByRole("button", { name: /Horloge : aujourd’hui/ })).toBeInTheDocument();
  });

  it("affiche la date simulée en français quand elle est posée", () => {
    meData = { club: club({ isDemo: true, simulatedToday: "2027-05-15" }) };
    renderWithProviders(<DemoClockWidget />);
    expect(screen.getByRole("button", { name: /Aujourd’hui : 15 mai 2027/ })).toBeInTheDocument();
  });

  it("pose une date : ouvre le popover, saisit, Appliquer appelle l'API", async () => {
    meData = { club: club({ isDemo: true, simulatedToday: null }) };
    renderWithProviders(<DemoClockWidget />);

    fireEvent.click(screen.getByRole("button", { name: /Horloge : aujourd’hui/ }));
    fireEvent.change(screen.getByLabelText("Date simulée de la démo"), { target: { value: "2027-05-15" } });
    fireEvent.click(screen.getByRole("button", { name: "Appliquer" }));

    await waitFor(() => expect(mockSet).toHaveBeenCalledWith({ date: "2027-05-15" }));
  });

  it("revient à aujourd'hui : « Revenir à aujourd'hui » relâche l'horloge", async () => {
    meData = { club: club({ isDemo: true, simulatedToday: "2027-05-15" }) };
    renderWithProviders(<DemoClockWidget />);

    fireEvent.click(screen.getByRole("button", { name: /Aujourd’hui : 15 mai 2027/ }));
    fireEvent.click(screen.getByRole("button", { name: /Revenir à aujourd’hui/ }));

    await waitFor(() => expect(mockSet).toHaveBeenCalledWith({ clear: true }));
  });
});
