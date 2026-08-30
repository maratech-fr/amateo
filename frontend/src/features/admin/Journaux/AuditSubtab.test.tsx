import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { AdminAuditLogResponse } from "../api";
import { AuditSubtab } from "./AuditSubtab";

// Mock the hook — tests drive the component through its props contract, not the
// react-query plumbing (already covered by the shared layer).
vi.mock("../queries", () => ({
  useAdminAuditLog: vi.fn(),
}));

// Import after the mock so the spied module is the one under test.
import { useAdminAuditLog } from "../queries";

const mockedUseAdminAuditLog = vi.mocked(useAdminAuditLog);

function makeItem(overrides: Partial<AdminAuditLogResponse["items"][number]> = {}): AdminAuditLogResponse["items"][number] {
  return {
    id: "audit-1",
    actorId: "actor-1",
    actorEmail: "root@club.test",
    route: "POST /api/admin/clubs",
    context: null,
    status: 200,
    createdAt: "2026-07-21T10:30:00.000Z",
    ...overrides,
  };
}

function makeResponse(items: AdminAuditLogResponse["items"], page = 1, total = items.length): AdminAuditLogResponse {
  const limit = 50;
  const pages = Math.max(Math.ceil(total / limit), 1);
  return { items, pagination: { page, limit, total, pages } };
}

function mockHook(data: AdminAuditLogResponse) {
  mockedUseAdminAuditLog.mockReturnValue({
    data,
    isPending: false,
    isError: false,
    isFetching: false,
    refetch: vi.fn(),
  } as unknown as ReturnType<typeof useAdminAuditLog>);
}

function mockEmpty() {
  mockedUseAdminAuditLog.mockReturnValue({
    data: makeResponse([], 1, 0),
    isPending: false,
    isError: false,
    isFetching: false,
    refetch: vi.fn(),
  } as unknown as ReturnType<typeof useAdminAuditLog>);
}

describe("AuditSubtab", () => {
  it("renders 50 rows when the page returns 50 items", () => {
    const items = Array.from({ length: 50 }, (_, i) =>
      makeItem({ id: `audit-${i}`, actorEmail: `admin${i}@club.test`, route: `POST /api/admin/clubs/${i}` }),
    );
    mockHook(makeResponse(items, 1, 120));

    render(<AuditSubtab />);

    // 50 body rows — one per item.
    const rows = screen.getAllByRole("row");
    // 1 header row + 50 body rows = 51.
    expect(rows).toHaveLength(51);
    expect(screen.getByText("admin0@club.test")).toBeInTheDocument();
    expect(screen.getByText("admin49@club.test")).toBeInTheDocument();
    // Pagination summary reflects the full total, not just the page slice.
    expect(screen.getByText(/120 entrées · page 1 sur 3/)).toBeInTheDocument();
  });

  it("reflects the current page in the pagination summary and disables prev on page 1", () => {
    const items = Array.from({ length: 50 }, (_, i) => makeItem({ id: `audit-${i}` }));
    mockHook(makeResponse(items, 2, 120));

    render(<AuditSubtab />);

    expect(screen.getByText(/120 entrées · page 2 sur 3/)).toBeInTheDocument();
    // Prev enabled on page 2, next enabled (page 2 < 3).
    expect(screen.getByRole("button", { name: "Page précédente" })).toBeEnabled();
    expect(screen.getByRole("button", { name: "Page suivante" })).toBeEnabled();
  });

  it("moves to the next page when the next button is clicked", async () => {
    const page1 = makeResponse(Array.from({ length: 50 }, (_, i) => makeItem({ id: `a-${i}` })), 1, 100);
    const page2 = makeResponse(Array.from({ length: 50 }, (_, i) => makeItem({ id: `b-${i}`, actorEmail: `page2-${i}@club.test` })), 2, 100);

    let current = page1;
    mockedUseAdminAuditLog.mockImplementation(() => ({
      data: current,
      isPending: false,
      isError: false,
      isFetching: false,
      refetch: vi.fn(),
    } as unknown as ReturnType<typeof useAdminAuditLog>));

    render(<AuditSubtab />);
    expect(screen.getByText(/page 1 sur 2/)).toBeInTheDocument();

    // Swap the mocked data, then click next — the component re-renders with page 2.
    current = page2;
    await userEvent.click(screen.getByRole("button", { name: "Page suivante" }));

    expect(screen.getByText(/page 2 sur 2/)).toBeInTheDocument();
    expect(screen.getByText("page2-0@club.test")).toBeInTheDocument();
  });

  it("renders the empty state when there are no items", () => {
    mockEmpty();
    render(<AuditSubtab />);
    expect(screen.getByText("Aucun SuperAdmin audité pour le moment")).toBeInTheDocument();
    // No table in the empty state.
    expect(screen.queryByRole("table")).not.toBeInTheDocument();
  });

  it("renders an error status chip with the red style", () => {
    mockHook(makeResponse([makeItem({ id: "err-1", status: 403 })], 1, 1));

    render(<AuditSubtab />);

    const chip = screen.getByText("Erreur");
    expect(chip).toBeInTheDocument();
    // The red style is applied to the status chip (bg-console-danger-surface/15 text-console-danger).
    expect(chip.className).toContain("bg-console-danger-surface/15");
    expect(chip.className).toContain("text-console-danger");
  });

  it("renders a success status chip with the green style", () => {
    mockHook(makeResponse([makeItem({ id: "ok-1", status: 200 })], 1, 1));

    render(<AuditSubtab />);

    const chip = screen.getByText("Succès");
    expect(chip).toBeInTheDocument();
    expect(chip.className).toContain("bg-console-success-surface/15");
    expect(chip.className).toContain("text-console-success");
  });

  it("maps a >=400 status code to Erreur and shows the code (never a false Succès)", () => {
    mockHook(makeResponse([makeItem({ id: "denied", status: 403 })], 1, 1));
    render(<AuditSubtab />);
    expect(screen.getByText("Erreur")).toBeInTheDocument();
    expect(screen.getByText("403")).toBeInTheDocument();
    expect(screen.queryByText("Succès")).not.toBeInTheDocument();
  });

  it("maps a null status code to the neutral unknown chip, not Succès", () => {
    mockHook(makeResponse([makeItem({ id: "no-code", status: null })], 1, 1));
    render(<AuditSubtab />);
    expect(screen.getByText("—")).toBeInTheDocument();
    expect(screen.queryByText("Succès")).not.toBeInTheDocument();
  });

  it("formats the createdAt date in fr-FR with the expected options", () => {
    mockHook(makeResponse([makeItem({ id: "d-1", createdAt: "2026-07-21T10:30:00.000Z" })], 1, 1));

    render(<AuditSubtab />);

    // The exact rendered string depends on the TZ, but it must contain the
    // short month name (juil.) and the 2-digit year — the formatter's signature.
    const dateCell = screen.getByText(/juil\. 2026/);
    expect(dateCell).toBeInTheDocument();
  });
});