import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { AdminHealthResponse } from "../api";
import { getAdminHealth } from "../api";
import { ContainersSection } from "./ContainersSection";

vi.mock("../api", async (importOriginal) => {
  const original = await importOriginal<typeof import("../api")>();
  return {
    ...original,
    getAdminHealth: vi.fn(),
  };
});

const mockHealth = vi.mocked(getAdminHealth);

function buildHealth(containers: AdminHealthResponse["containers"]): AdminHealthResponse {
  return {
    status: "healthy",
    checkedAt: "2026-07-16T10:30:00+00:00",
    services: {
      database: { status: "up", latencyMs: 4 },
      redis: { status: "up", latencyMs: 2 },
      engine: { status: "up", latencyMs: 18 },
      mercure: { status: "up", latencyMs: 9 },
      worker: { status: "up", lastHeartbeatAt: "2026-07-16T10:29:55+00:00", ageSeconds: 5 },
    },
    messenger: { status: "up", backlog: 3, failed: 0, retriesToday: 1, backlogWarningThreshold: 100 },
    containers,
    externalDependencies: [],
  };
}

const THIRTEEN_CONTAINERS: AdminHealthResponse["containers"] = Array.from({ length: 13 }, (_, i) => ({
  key: `svc-${i}`,
  name: `Service ${i + 1}`,
  status: "up" as const,
  lastHeartbeatAt: "2026-07-16T10:29:55+00:00",
  ageSeconds: 5,
  latencyMs: 10,
}));

describe("ContainersSection", () => {
  beforeEach(() => {
    mockHealth.mockReset();
  });

  it("renders 13 rows when health data has 13 containers", async () => {
    mockHealth.mockResolvedValue(buildHealth(THIRTEEN_CONTAINERS));
    renderWithProviders(<ContainersSection />);

    expect(await screen.findByText("Service 1")).toBeInTheDocument();
    expect(screen.getByText("Service 13")).toBeInTheDocument();
    // 13 rows in the tbody — each row has a cell with the service name.
    const rows = screen.getAllByRole("row");
    // 1 header row + 13 body rows = 14.
    expect(rows).toHaveLength(14);
  });

  it("shows the empty state when containers array is empty", async () => {
    mockHealth.mockResolvedValue(buildHealth([]));
    renderWithProviders(<ContainersSection />);

    expect(await screen.findByText("Aucun conteneur à monitorer.")).toBeInTheDocument();
    expect(screen.queryByRole("table")).not.toBeInTheDocument();
  });

  it("renders a red status chip for a down container", async () => {
    mockHealth.mockResolvedValue(
      buildHealth([
        { key: "worker", name: "Worker", status: "down", lastHeartbeatAt: "2026-07-16T10:00:00+00:00" },
      ]),
    );
    renderWithProviders(<ContainersSection />);

    const chip = await screen.findByText("Indisponible");
    expect(chip).toBeInTheDocument();
    expect(chip.className).toContain("text-console-danger");
  });

  it("renders a green status chip for an up container", async () => {
    mockHealth.mockResolvedValue(
      buildHealth([{ key: "worker", name: "Worker", status: "up", ageSeconds: 3 }]),
    );
    renderWithProviders(<ContainersSection />);

    const chip = await screen.findByText("Opérationnel");
    expect(chip).toBeInTheDocument();
    expect(chip.className).toContain("text-console-success");
  });

  it("shows the panel error when health fails", async () => {
    mockHealth.mockRejectedValue(new Error("health unavailable"));
    renderWithProviders(<ContainersSection />);

    expect(await screen.findByText("Les conteneurs sont indisponibles.")).toBeInTheDocument();
  });
});