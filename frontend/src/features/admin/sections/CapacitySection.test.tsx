import { screen, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { AdminCapacityResponse } from "../api";
import { getAdminCapacity } from "../api";
import { ADMIN_SECTIONS_TEXTS } from "../texts/sections";
import { CapacitySection } from "./CapacitySection";

vi.mock("../api", async (importOriginal) => {
  const original = await importOriginal<typeof import("../api")>();
  return {
    ...original,
    getAdminCapacity: vi.fn(),
  };
});

const mockCapacity = vi.mocked(getAdminCapacity);

function buildCapacity(overrides: Partial<AdminCapacityResponse> = {}): AdminCapacityResponse {
  return {
    windowDays: 90,
    totalSolves: 42,
    volume: {
      perDay: { p50: 3, max: 11, maxDate: "2026-09-02" },
      hourly: [
        { hour: 18, solves: 7 },
        { hour: 20, solves: 12 },
      ],
    },
    wait: { queueP50Ms: 20000, queueP95Ms: 90000, queueMaxMs: 120000, engineWaitP95Ms: 580 },
    bySize: [
      { bucket: "small", solves: 20, p50WallTimeMs: 3000, p95WallTimeMs: 9000, p95BudgetRatio: 0.15, unclosedProofRate: 0.1 },
      { bucket: "large", solves: 4, p50WallTimeMs: 120000, p95WallTimeMs: 180000, p95BudgetRatio: 1, unclosedProofRate: 0.5 },
    ],
    memory: { peakP50Mb: 210.4, peakP95Mb: 380, peakMaxMb: 460.2, lastBaselineMb: 61.5 },
    issues: {
      byStatus: [
        { status: "COMPLETED", solves: 38 },
        { status: "INFEASIBLE", solves: 3 },
        { status: "FAILED", solves: 1 },
      ],
      payloadP95Bytes: 250000,
    },
    ...overrides,
  };
}

describe("CapacitySection", () => {
  beforeEach(() => {
    mockCapacity.mockReset();
  });

  it("renders the capacity aggregates when data is present", async () => {
    mockCapacity.mockResolvedValue(buildCapacity());
    renderWithProviders(<CapacitySection />);

    // Header window (UI heading → clé) + total (donnée servie → littéral).
    expect(await screen.findByText(ADMIN_SECTIONS_TEXTS.capacityHeading(90))).toBeInTheDocument();
    expect(screen.getByText("42")).toBeInTheDocument();

    // Size buckets rendered with human labels + their solve counts.
    const bySize = screen.getByRole("table", { name: ADMIN_SECTIONS_TEXTS.capacitySizeCaption });
    expect(within(bySize).getByText(/≤\s*50/)).toBeInTheDocument();
    expect(within(bySize).getByText(/>\s*200/)).toBeInTheDocument();

    // Memory p95 and the static prod limit.
    expect(screen.getByText(/512 MiB/)).toBeInTheDocument();

    // Issues: a status label surfaces.
    expect(screen.getByText("COMPLETED")).toBeInTheDocument();
  });

  it("shows the empty state when no solve fell in the window", async () => {
    mockCapacity.mockResolvedValue(
      buildCapacity({
        totalSolves: 0,
        volume: { perDay: { p50: null, max: null, maxDate: null }, hourly: [] },
        wait: { queueP50Ms: null, queueP95Ms: null, queueMaxMs: null, engineWaitP95Ms: null },
        bySize: [],
        memory: { peakP50Mb: null, peakP95Mb: null, peakMaxMb: null, lastBaselineMb: null },
        issues: { byStatus: [], payloadP95Bytes: null },
      }),
    );
    renderWithProviders(<CapacitySection />);

    expect(await screen.findByText(ADMIN_SECTIONS_TEXTS.capacityEmpty(90))).toBeInTheDocument();
    // The empty state must NOT show a misleading table.
    expect(screen.queryByRole("table", { name: ADMIN_SECTIONS_TEXTS.capacitySizeCaption })).not.toBeInTheDocument();
  });

  it("shows the panel error when the capacity request fails", async () => {
    mockCapacity.mockRejectedValue(new Error("capacity unavailable"));
    renderWithProviders(<CapacitySection />);

    expect(await screen.findByText(ADMIN_SECTIONS_TEXTS.capacityError)).toBeInTheDocument();
  });
});
