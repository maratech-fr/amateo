import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { AdminSystemErrorsResponse } from "../api";
import { SystemErrorsSubtab } from "./SystemErrorsSubtab";

// Mock the hook — tests drive the component through its props contract, not the
// react-query plumbing (already covered by the shared layer).
vi.mock("../queries", () => ({
  useAdminSystemErrors: vi.fn(),
}));

// Import after the mock so the spied module is the one under test.
import { useAdminSystemErrors } from "../queries";

const mockedUseAdminSystemErrors = vi.mocked(useAdminSystemErrors);

function makeItem(overrides: Partial<AdminSystemErrorsResponse["items"][number]> = {}): AdminSystemErrorsResponse["items"][number] {
  return {
    source: "messenger",
    message: "Worker crashed mid-handling",
    severity: "error",
    createdAt: "2026-07-21T10:30:00.000Z",
    ...overrides,
  };
}

function makeResponse(items: AdminSystemErrorsResponse["items"], page = 1, total = items.length): AdminSystemErrorsResponse {
  const limit = 50;
  const pages = Math.max(Math.ceil(total / limit), 1);
  return { items, pagination: { page, limit, total, pages } };
}

function mockHook(data: AdminSystemErrorsResponse) {
  mockedUseAdminSystemErrors.mockReturnValue({
    data,
    isPending: false,
    isError: false,
    isFetching: false,
    refetch: vi.fn(),
  } as unknown as ReturnType<typeof useAdminSystemErrors>);
}

function mockEmpty() {
  mockedUseAdminSystemErrors.mockReturnValue({
    data: makeResponse([], 1, 0),
    isPending: false,
    isError: false,
    isFetching: false,
    refetch: vi.fn(),
  } as unknown as ReturnType<typeof useAdminSystemErrors>);
}

describe("SystemErrorsSubtab", () => {
  it("renders rows from two distinct sources in the same table", () => {
    const items = [
      makeItem({ source: "messenger", message: "Worker crashed mid-handling", severity: "error" }),
      makeItem({ source: "engine", message: "Solver returned INFEASIBLE", severity: "warning" }),
    ];
    mockHook(makeResponse(items, 1, 2));

    render(<SystemErrorsSubtab />);

    // 1 header row + 2 body rows = 3.
    const rows = screen.getAllByRole("row");
    expect(rows).toHaveLength(3);

    // Both sources are visible (rendered in monospace).
    expect(screen.getByText("messenger")).toBeInTheDocument();
    expect(screen.getByText("engine")).toBeInTheDocument();
    // Both messages are visible.
    expect(screen.getByText("Worker crashed mid-handling")).toBeInTheDocument();
    expect(screen.getByText("Solver returned INFEASIBLE")).toBeInTheDocument();

    // Pagination summary reflects the total.
    expect(screen.getByText(/2 erreurs · page 1 sur 1/)).toBeInTheDocument();
  });

  it("renders the empty state when there are no system errors", () => {
    mockEmpty();
    render(<SystemErrorsSubtab />);

    expect(screen.getByText("Aucune erreur système enregistrée")).toBeInTheDocument();
    // No table in the empty state.
    expect(screen.queryByRole("table")).not.toBeInTheDocument();
  });

  it("renders an error severity chip with the red style", () => {
    mockHook(makeResponse([makeItem({ source: "messenger", severity: "error", message: "boom" })], 1, 1));

    render(<SystemErrorsSubtab />);

    const chip = screen.getByText("Erreur");
    expect(chip).toBeInTheDocument();
    // The red style is applied to the severity chip (bg-console-danger-surface/15 text-console-danger).
    expect(chip.className).toContain("bg-console-danger-surface/15");
    expect(chip.className).toContain("text-console-danger");
  });
});