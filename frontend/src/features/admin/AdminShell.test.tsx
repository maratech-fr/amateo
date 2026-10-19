import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { expectNoA11yViolations, renderWithProviders } from "@/test/utils";

import { logoutAdmin } from "./api";
import { AdminShell } from "./AdminShell";
import { useAdminStore } from "./store";
import { ADMIN_DASHBOARD_TEXTS } from "./texts/dashboard";

vi.mock("./api", () => ({ logoutAdmin: vi.fn() }));

const logout = vi.mocked(logoutAdmin);

describe("AdminShell", () => {
  beforeEach(() => {
    logout.mockReset();
    useAdminStore.setState({ identity: { id: "admin-1", email: "ops@example.test" }, csrfToken: "csrf-123" });
  });

  it("shows the authenticated identity and clears the session on logout", async () => {
    logout.mockResolvedValue(undefined);
    const user = userEvent.setup();
    renderWithProviders(<AdminShell />);

    expect(screen.getByText("ops@example.test")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.shellLogout }));

    expect(logout).toHaveBeenCalledWith("csrf-123");
    expect(useAdminStore.getState().identity).toBeNull();
  });

  it("keeps the SA0 shell accessible", async () => {
    await expectNoA11yViolations(<AdminShell />);
  });
});
