import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { expectNoA11yViolations, renderWithProviders } from "@/test/utils";

import { completeAdminTotp, startAdminPassword } from "./api";
import { AdminLoginPage } from "./AdminLoginPage";
import { useAdminStore } from "./store";
import { ADMIN_DASHBOARD_TEXTS } from "./texts/dashboard";

vi.mock("./api", () => ({
  completeAdminTotp: vi.fn(),
  startAdminPassword: vi.fn(),
}));

const startPassword = vi.mocked(startAdminPassword);
const completeTotp = vi.mocked(completeAdminTotp);

describe("AdminLoginPage", () => {
  beforeEach(() => {
    startPassword.mockReset();
    completeTotp.mockReset();
    useAdminStore.getState().clear();
  });

  it("requires the password step before showing the TOTP challenge", async () => {
    startPassword.mockResolvedValue({ mfaRequired: true });
    const user = userEvent.setup();
    renderWithProviders(<AdminLoginPage />);

    await user.type(screen.getByLabelText(ADMIN_DASHBOARD_TEXTS.loginEmailLabel), "ops@example.test");
    await user.type(screen.getByLabelText(ADMIN_DASHBOARD_TEXTS.loginPasswordLabel), "secret");
    await user.click(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.loginSubmit }));

    expect(await screen.findByLabelText(ADMIN_DASHBOARD_TEXTS.loginTotpLabel)).toBeInTheDocument();
    expect(startPassword).toHaveBeenCalledWith({ email: "ops@example.test", password: "secret" });
  });

  it("stores the CSRF token after a valid TOTP code", async () => {
    startPassword.mockResolvedValue({ mfaRequired: true });
    completeTotp.mockResolvedValue({ authenticated: true, csrfToken: "csrf-123" });
    const user = userEvent.setup();
    renderWithProviders(<AdminLoginPage />);

    await user.type(screen.getByLabelText(ADMIN_DASHBOARD_TEXTS.loginEmailLabel), "ops@example.test");
    await user.type(screen.getByLabelText(ADMIN_DASHBOARD_TEXTS.loginPasswordLabel), "secret");
    await user.click(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.loginSubmit }));
    const code = await screen.findByLabelText(ADMIN_DASHBOARD_TEXTS.loginTotpLabel);
    await user.type(code, "123456");
    await user.click(screen.getByRole("button", { name: ADMIN_DASHBOARD_TEXTS.loginTotpSubmit }));

    await waitFor(() => expect(useAdminStore.getState().csrfToken).toBe("csrf-123"));
    expect(completeTotp).toHaveBeenCalledWith("123456");
  });

  it("keeps the login structure accessible", async () => {
    await expectNoA11yViolations(<AdminLoginPage />);
  });
});
