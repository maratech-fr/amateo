import { cleanup, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { setTodayOverride, toISODate, todayISO } from "@/shared/lib/clock";
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

  // D12 (console PR 1) — la console compte « aujourd'hui » à la date RÉELLE : venu d'un club démo
  // daté (horloge simulée posée par AppLayout), entrer sous /admin relâche l'override au montage,
  // sinon « Il y a N jours » se compterait depuis 2020. Le retour dans l'app ré-applique la date du
  // club : useApplySimulatedClock.test.tsx (remontage).
  it("relâche l'horloge simulée d'un club démo au montage : la console vit à la date réelle", () => {
    setTodayOverride("2020-01-01");
    try {
      renderWithProviders(<AdminShell />);

      expect(todayISO()).toBe(toISODate(new Date()));
    } finally {
      cleanup();
      setTodayOverride(null);
    }
  });
});
