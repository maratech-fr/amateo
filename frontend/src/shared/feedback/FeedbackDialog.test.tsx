import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { clearLastIncident, recordIncident } from "@/shared/api/lastIncidentStore";
import { useToastStore } from "@/shared/stores/toastStore";
import { renderWithProviders } from "@/test/utils";

import { submitFeedback } from "./api";
import { FeedbackDialog } from "./FeedbackDialog";

vi.mock("./api", async (importOriginal) => {
  const original = await importOriginal<typeof import("./api")>();
  return { ...original, submitFeedback: vi.fn() };
});

const mockSubmit = vi.mocked(submitFeedback);

/** Erreur HTTP factice à la façon de ky (status + `data` = corps déjà consommé). */
function httpError(status: number, body?: unknown): HTTPError {
  const err = new HTTPError(new Response(JSON.stringify(body ?? {}), { status }), new Request("http://t/api/feedback"), {} as never);
  (err as { data?: unknown }).data = body;
  return err;
}

beforeEach(() => {
  mockSubmit.mockReset();
  clearLastIncident();
  useToastStore.setState({ toasts: [] });
});

describe("FeedbackDialog — variante libre (burger)", () => {
  it("propose 3 topics et envoie le topic choisi avec un contexte léger", async () => {
    mockSubmit.mockResolvedValue({ id: "fb1" });
    renderWithProviders(<FeedbackDialog variant="free" onClose={vi.fn()} />, { route: "/planning" });

    const select = screen.getByRole("combobox", { name: "Type de signalement" });
    expect(within(select).getAllByRole("option")).toHaveLength(3);

    await userEvent.selectOptions(select, "idea");
    await userEvent.type(screen.getByRole("textbox", { name: "Votre message" }), "Une idée");
    await userEvent.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(mockSubmit).toHaveBeenCalledTimes(1));
    const payload = mockSubmit.mock.calls[0][0];
    expect(payload.topic).toBe("idea");
    expect(payload.message).toBe("Une idée");
    expect(payload.context).toMatchObject({ screen: "/planning" });
    expect(payload.context?.userAgent).toBeDefined();
    // Contexte léger seul : ni planning ni incident joints.
    expect(payload.context).not.toHaveProperty("scheduleId");
    expect(payload.context).not.toHaveProperty("requestId");
  });
});

describe("FeedbackDialog — variante contextuelle", () => {
  // ⚠ Topic figé à « bug » : AUCUN sélecteur. Falsification prévue : rendre le topic
  // variable (afficher un sélecteur) rend ce test rouge.
  it("fige le topic à « bug », sans sélecteur, et joint screen + scheduleId", async () => {
    mockSubmit.mockResolvedValue({ id: "fb1" });
    renderWithProviders(<FeedbackDialog variant="contextual" screen="/planning" scheduleId="sc1" onClose={vi.fn()} />, { route: "/planning" });

    expect(screen.queryByRole("combobox", { name: "Type de signalement" })).not.toBeInTheDocument();

    await userEvent.type(screen.getByRole("textbox", { name: "Décrivez le problème" }), "Ça casse");
    await userEvent.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(mockSubmit).toHaveBeenCalledTimes(1));
    const payload = mockSubmit.mock.calls[0][0];
    expect(payload.topic).toBe("bug");
    expect(payload.context).toMatchObject({ screen: "/planning", scheduleId: "sc1" });
  });

  it("joint le request-id d'un incident serveur récent (< 10 min)", async () => {
    mockSubmit.mockResolvedValue({ id: "fb1" });
    recordIncident({ status: 500, url: "/api/planning", requestId: "req-999" });
    renderWithProviders(<FeedbackDialog variant="contextual" screen="/planning" onClose={vi.fn()} />, { route: "/planning" });

    await userEvent.type(screen.getByRole("textbox", { name: "Décrivez le problème" }), "Erreur 500 vue");
    await userEvent.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(mockSubmit).toHaveBeenCalled());
    expect(mockSubmit.mock.calls[0][0].context).toMatchObject({ requestId: "req-999" });
  });

  it("ne joint aucun request-id sans incident récent", async () => {
    mockSubmit.mockResolvedValue({ id: "fb1" });
    renderWithProviders(<FeedbackDialog variant="contextual" screen="/planning" onClose={vi.fn()} />, { route: "/planning" });

    await userEvent.type(screen.getByRole("textbox", { name: "Décrivez le problème" }), "Rien de spécial");
    await userEvent.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(mockSubmit).toHaveBeenCalled());
    expect(mockSubmit.mock.calls[0][0].context).not.toHaveProperty("requestId");
  });
});

describe("FeedbackDialog — retours serveur", () => {
  it("sur 201 : toast de confirmation puis fermeture", async () => {
    mockSubmit.mockResolvedValue({ id: "fb1" });
    const onClose = vi.fn();
    renderWithProviders(<FeedbackDialog variant="free" onClose={onClose} />, { route: "/matchs" });

    await userEvent.type(screen.getByRole("textbox", { name: "Votre message" }), "Merci");
    await userEvent.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(onClose).toHaveBeenCalled());
    expect(useToastStore.getState().toasts.some((t) => t.message.includes("Signalement envoyé"))).toBe(true);
  });

  it("sur 429 : message doux, la modale reste ouverte", async () => {
    mockSubmit.mockRejectedValue(httpError(429, { error: "throttled" }));
    const onClose = vi.fn();
    renderWithProviders(<FeedbackDialog variant="free" onClose={onClose} />, { route: "/matchs" });

    await userEvent.type(screen.getByRole("textbox", { name: "Votre message" }), "Encore un bug");
    await userEvent.click(screen.getByRole("button", { name: "Envoyer" }));

    expect(await screen.findByText("Trop de signalements, réessayez plus tard.")).toBeInTheDocument();
    expect(onClose).not.toHaveBeenCalled();
  });

  it("sur 422 : rend le message du serveur", async () => {
    mockSubmit.mockRejectedValue(httpError(422, { error: "Le message est trop long." }));
    renderWithProviders(<FeedbackDialog variant="free" onClose={vi.fn()} />, { route: "/matchs" });

    await userEvent.type(screen.getByRole("textbox", { name: "Votre message" }), "x");
    await userEvent.click(screen.getByRole("button", { name: "Envoyer" }));

    expect(await screen.findByText("Le message est trop long.")).toBeInTheDocument();
  });
});
