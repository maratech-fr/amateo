import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { AdminDeployment } from "../api";
import { cancelAdminDeployment, getAdminDeployments, getAdminDeploymentTags, scheduleAdminDeployment } from "../api";
import { useAdminStore } from "../store";
import { DeploymentSection } from "./DeploymentSection";

vi.mock("../api", async (importOriginal) => {
  const original = await importOriginal<typeof import("../api")>();
  return {
    ...original,
    getAdminDeployments: vi.fn(),
    getAdminDeploymentTags: vi.fn(),
    scheduleAdminDeployment: vi.fn(),
    cancelAdminDeployment: vi.fn(),
  };
});

const mockList = vi.mocked(getAdminDeployments);
const mockTags = vi.mocked(getAdminDeploymentTags);
const mockSchedule = vi.mocked(scheduleAdminDeployment);
const mockCancel = vi.mocked(cancelAdminDeployment);

/** Une HTTPError ky avec son corps déjà parsé dans `.data` (patron FeedbackSection.test). */
function httpError(status: number, body?: unknown): HTTPError {
  const err = new HTTPError(new Response(JSON.stringify(body ?? {}), { status }), new Request("http://t/api/admin/deployments"), {} as never);
  (err as { data?: unknown }).data = body;
  return err;
}

const TAGS = { items: ["v1.4.0", "v1.3.0"] };

const SCHEDULED: AdminDeployment = {
  id: "11111111-1111-4111-8111-111111111111",
  version: "v1.2.0",
  status: "scheduled",
  scheduledAt: "2026-12-01T02:00:00+00:00",
  expectedReturnAt: "2026-12-01T02:30:00+00:00",
  dispatchedAt: null,
  githubRunId: null,
  failureReason: null,
  createdAt: "2026-11-30T10:00:00+00:00",
};

describe("DeploymentSection", () => {
  beforeEach(() => {
    mockList.mockReset();
    mockTags.mockReset();
    mockSchedule.mockReset();
    mockCancel.mockReset();
    mockTags.mockResolvedValue(TAGS);
    mockList.mockResolvedValue({ items: [], configured: true });
    useAdminStore.getState().setSession({ id: "sa", email: "sa@x" }, "csrf-token");
  });

  it("choisit la version dans un SELECT des tags déployables — jamais une saisie libre", async () => {
    renderWithProviders(<DeploymentSection />, { route: "/admin" });

    expect(await screen.findByRole("combobox", { name: /version/i })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "v1.4.0" })).toBeInTheDocument();
    // La promesse : aucune version tapée à la main (seulement un tag réel, listé par le backend).
    expect(screen.queryByRole("textbox", { name: /version/i })).toBeNull();
  });

  it("programme : POST {version, scheduledAt, windowMinutes} avec le jeton CSRF", async () => {
    const user = userEvent.setup();
    mockSchedule.mockResolvedValue(SCHEDULED);
    renderWithProviders(<DeploymentSection />, { route: "/admin" });

    await user.selectOptions(await screen.findByRole("combobox", { name: /version/i }), "v1.4.0");
    // <input type="datetime-local"> / number : valeur directe (jsdom ne les remplit pas frappe à frappe).
    fireEvent.change(screen.getByLabelText(/date et heure/i), { target: { value: "2026-12-01T03:00" } });
    fireEvent.change(screen.getByLabelText(/durée de la maintenance/i), { target: { value: "30" } });
    await user.click(screen.getByRole("button", { name: /programmer/i }));

    await waitFor(() => expect(mockSchedule).toHaveBeenCalledTimes(1));
    const [payload, csrf] = mockSchedule.mock.calls[0];
    expect(payload).toMatchObject({ version: "v1.4.0", windowMinutes: 30 });
    expect(typeof payload.scheduledAt).toBe("string");
    expect(payload.scheduledAt.length).toBeGreaterThan(0);
    expect(csrf).toBe("csrf-token");
  });

  it("jeton GitHub absent (configured=false) : message clair + formulaire désactivé", async () => {
    mockTags.mockResolvedValue({ items: [] });
    mockList.mockResolvedValue({ items: [], configured: false });
    renderWithProviders(<DeploymentSection />, { route: "/admin" });

    expect(await screen.findByText(/jeton github/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /programmer/i })).toBeDisabled();
  });

  it("une version refusée (422) affiche le message du serveur sans planter", async () => {
    const user = userEvent.setup();
    mockSchedule.mockRejectedValue(httpError(422, { error: "La version demandée n’est pas un tag déployable." }));
    renderWithProviders(<DeploymentSection />, { route: "/admin" });

    await user.selectOptions(await screen.findByRole("combobox", { name: /version/i }), "v1.4.0");
    fireEvent.change(screen.getByLabelText(/date et heure/i), { target: { value: "2026-12-01T03:00" } });
    fireEvent.change(screen.getByLabelText(/durée de la maintenance/i), { target: { value: "30" } });
    await user.click(screen.getByRole("button", { name: /programmer/i }));

    expect(await screen.findByText("La version demandée n’est pas un tag déployable.")).toBeInTheDocument();
  });

  it("un second déploiement (409) affiche le message « déjà programmée » sans planter", async () => {
    const user = userEvent.setup();
    mockSchedule.mockRejectedValue(httpError(409, { error: "Une mise en production est déjà programmée." }));
    renderWithProviders(<DeploymentSection />, { route: "/admin" });

    await user.selectOptions(await screen.findByRole("combobox", { name: /version/i }), "v1.4.0");
    fireEvent.change(screen.getByLabelText(/date et heure/i), { target: { value: "2026-12-01T03:00" } });
    fireEvent.change(screen.getByLabelText(/durée de la maintenance/i), { target: { value: "30" } });
    await user.click(screen.getByRole("button", { name: /programmer/i }));

    expect(await screen.findByText("Une mise en production est déjà programmée.")).toBeInTheDocument();
  });

  it("programmation active : la version s'affiche, et Annuler (après confirmation) appelle DELETE avec le jeton", async () => {
    const user = userEvent.setup();
    mockList.mockResolvedValue({ items: [SCHEDULED], configured: true });
    mockCancel.mockResolvedValue({ id: SCHEDULED.id, status: "cancelled" });
    renderWithProviders(<DeploymentSection />, { route: "/admin" });

    // v1.2.0 n'est pas dans les tags proposés : son unique occurrence est la programmation active.
    expect(await screen.findByText("v1.2.0")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /annuler la mise en production/i }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: /confirmer/i }));

    await waitFor(() => expect(mockCancel).toHaveBeenCalledWith(SCHEDULED.id, "csrf-token"));
  });

  it("un déploiement DISPATCHED (le train est parti) ne propose plus Annuler", async () => {
    mockList.mockResolvedValue({
      items: [{ ...SCHEDULED, status: "dispatched", dispatchedAt: "2026-12-01T02:00:00+00:00" }],
      configured: true,
    });
    renderWithProviders(<DeploymentSection />, { route: "/admin" });

    expect(await screen.findByText("v1.2.0")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /annuler la mise en production/i })).toBeNull();
  });

  it("l'historique montre un échec avec sa raison", async () => {
    mockList.mockResolvedValue({
      items: [{ ...SCHEDULED, id: "f1", version: "v1.1.0", status: "failed", failureReason: "Le workflow GitHub a échoué." }],
      configured: true,
    });
    renderWithProviders(<DeploymentSection />, { route: "/admin" });

    expect(await screen.findByText(/le workflow github a échoué/i)).toBeInTheDocument();
  });
});
