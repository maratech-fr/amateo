import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { AdminHealthResponse } from "../api";
import { getAdminHealth } from "../api";
import { ExternalDepsSection } from "./ExternalDepsSection";

vi.mock("../api", async (importOriginal) => {
  const original = await importOriginal<typeof import("../api")>();
  return {
    ...original,
    getAdminHealth: vi.fn(),
  };
});

const baseHealth: AdminHealthResponse = {
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
  containers: [],
  externalDependencies: [],
};

const mockHealth = vi.mocked(getAdminHealth);

describe("ExternalDepsSection", () => {
  beforeEach(() => {
    mockHealth.mockReset();
  });

  it("renders 3 rows when health data has 3 external dependencies", async () => {
    mockHealth.mockResolvedValue({
      ...baseHealth,
      externalDependencies: [
        { key: "ffbb-api", name: "API FFBB", status: "up", latencyMs: 120 },
        { key: "ods-api", name: "API ODS Éducation", status: "up", latencyMs: 85 },
        { key: "etalab-api", name: "API Etalab Jours Fériés", status: "up", latencyMs: 45 },
      ],
    });

    renderWithProviders(<ExternalDepsSection />, { route: "/admin" });

    expect(await screen.findByText("API FFBB")).toBeInTheDocument();
    expect(screen.getByText("API ODS Éducation")).toBeInTheDocument();
    expect(screen.getByText("API Etalab Jours Fériés")).toBeInTheDocument();
    // 1 header row + 3 data rows.
    expect(screen.getAllByRole("row")).toHaveLength(4);
    expect(screen.getAllByText("Opérationnel")).toHaveLength(3);
    expect(screen.getByText("120 ms")).toBeInTheDocument();
    expect(screen.getByText("85 ms")).toBeInTheDocument();
    expect(screen.getByText("45 ms")).toBeInTheDocument();
  });

  it("shows red status and latency for a timed-out dependency (down, 2000 ms)", async () => {
    mockHealth.mockResolvedValue({
      ...baseHealth,
      externalDependencies: [
        { key: "ffbb-api", name: "API FFBB", status: "up", latencyMs: 120 },
        { key: "ods-api", name: "API ODS Éducation", status: "down", latencyMs: 2000 },
        { key: "etalab-api", name: "API Etalab Jours Fériés", status: "up", latencyMs: 45 },
      ],
    });

    renderWithProviders(<ExternalDepsSection />, { route: "/admin" });

    expect(await screen.findByText("API ODS Éducation")).toBeInTheDocument();
    const downStatus = screen.getByText("Indisponible");
    expect(downStatus).toBeInTheDocument();
    expect(downStatus.closest("span")).toHaveClass("text-console-danger-deep");
    expect(screen.getByText("2000 ms")).toBeInTheDocument();
  });

  it("shows — when latencyMs is undefined", async () => {
    mockHealth.mockResolvedValue({
      ...baseHealth,
      externalDependencies: [
        { key: "ffbb-api", name: "API FFBB", status: "up" },
      ],
    });

    renderWithProviders(<ExternalDepsSection />, { route: "/admin" });

    expect(await screen.findByText("API FFBB")).toBeInTheDocument();
    expect(screen.getByText("—")).toBeInTheDocument();
  });

  it("shows an empty state when no external dependencies are configured", async () => {
    mockHealth.mockResolvedValue(baseHealth);

    renderWithProviders(<ExternalDepsSection />, { route: "/admin" });

    expect(await screen.findByText("Aucune dépendance externe configurée.")).toBeInTheDocument();
  });
});