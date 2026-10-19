import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { AdminMessengerFailedResponse } from "../api";
import { ADMIN_JOURNAUX_TEXTS } from "../texts/journaux";
import { MessengerFailedSubtab } from "./MessengerFailedSubtab";

// Mock the hook — tests drive the component through its props contract, not the
// react-query plumbing (already covered by the shared layer).
vi.mock("../queries", () => ({
  useAdminMessengerFailed: vi.fn(),
}));

// Import after the mock so the spied module is the one under test.
import { useAdminMessengerFailed } from "../queries";

const mockedUseAdminMessengerFailed = vi.mocked(useAdminMessengerFailed);

function makeItem(overrides: Partial<AdminMessengerFailedResponse["items"][number]> = {}): AdminMessengerFailedResponse["items"][number] {
  return {
    id: "msg-1",
    class: "App\\Message\\GenerateScheduleMessage",
    failedAt: "2026-07-21T10:30:00.000Z",
    lastErrorMessage: "Transport exhausted retries",
    ...overrides,
  };
}

function makeResponse(items: AdminMessengerFailedResponse["items"], page = 1, total = items.length): AdminMessengerFailedResponse {
  const limit = 50;
  const pages = Math.max(Math.ceil(total / limit), 1);
  return { items, pagination: { page, limit, total, pages } };
}

function mockHook(data: AdminMessengerFailedResponse) {
  mockedUseAdminMessengerFailed.mockReturnValue({
    data,
    isPending: false,
    isError: false,
    isFetching: false,
    refetch: vi.fn(),
  } as unknown as ReturnType<typeof useAdminMessengerFailed>);
}

function mockEmpty() {
  mockedUseAdminMessengerFailed.mockReturnValue({
    data: makeResponse([], 1, 0),
    isPending: false,
    isError: false,
    isFetching: false,
    refetch: vi.fn(),
  } as unknown as ReturnType<typeof useAdminMessengerFailed>);
}

describe("MessengerFailedSubtab", () => {
  it("renders one row per failed message returned by the hook", () => {
    const items = [
      makeItem({ id: "msg-1", class: "App\\Message\\GenerateScheduleMessage", lastErrorMessage: "Transport exhausted retries" }),
      makeItem({ id: "msg-2", class: "App\\Message\\ExportPdfMessage", lastErrorMessage: "Engine unreachable" }),
    ];
    mockHook(makeResponse(items, 1, 2));

    render(<MessengerFailedSubtab />);

    // 1 header row + 2 body rows = 3.
    const rows = screen.getAllByRole("row");
    expect(rows).toHaveLength(3);

    // Each item's class and error message are visible.
    expect(screen.getByText("App\\Message\\GenerateScheduleMessage")).toBeInTheDocument();
    expect(screen.getByText("App\\Message\\ExportPdfMessage")).toBeInTheDocument();
    expect(screen.getByText("Transport exhausted retries")).toBeInTheDocument();
    expect(screen.getByText("Engine unreachable")).toBeInTheDocument();

    // Pagination summary reflects the total.
    expect(screen.getByText(ADMIN_JOURNAUX_TEXTS.messengerPagination(2, 1, 1))).toBeInTheDocument();
  });

  it("renders the empty state when there are no failed messages", () => {
    mockEmpty();
    render(<MessengerFailedSubtab />);

    expect(screen.getByText(ADMIN_JOURNAUX_TEXTS.messengerEmpty)).toBeInTheDocument();
    // No table in the empty state.
    expect(screen.queryByRole("table")).not.toBeInTheDocument();
  });
});