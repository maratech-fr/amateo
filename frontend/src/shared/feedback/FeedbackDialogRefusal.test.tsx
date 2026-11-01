import { QueryClientProvider } from "@tanstack/react-query";
import { act, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { createMemoryRouter, RouterProvider } from "react-router";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { queryClient } from "@/shared/lib/queryClient";
import { useToastStore } from "@/shared/stores/toastStore";
import { renderWithProviders } from "@/test/utils";

import { captureScreen } from "./captureScreen";
import { FeedbackDialog } from "./FeedbackDialog";
import { FEEDBACK_TEXTS } from "./texts";

/**
 * PROMESSE : quand le serveur refuse un signalement, la personne lit dans la fenêtre Signaler le
 * message du serveur (un texte générique seulement s'il n'en donne aucun d'exploitable), et ce
 * qu'elle a saisi reste là, prêt à être renvoyé.
 *
 * Fichier À PART de `FeedbackDialog.test.tsx`, dont le test « sur 422 » mocke `submitFeedback` et
 * fabrique lui-même `error.data` : il ne dit rien du trajet réel. Ici seul `fetch` est remplacé —
 * `submitFeedback`, le client `api` (ses vrais hooks) et ky tournent pour de vrai, et chaque réponse
 * reprend le corps et le statut émis par `backend/src/Controller/FeedbackController.php`.
 */
vi.mock("@/shared/api/client", async (importOriginal) => {
  const original = await importOriginal<typeof import("@/shared/api/client")>();
  // `baseUrl` absolu : le `Request` de Node ne résout pas une URL relative (patron `client.test.ts`).
  return { ...original, api: original.api.extend({ baseUrl: "http://localhost" }) };
});
vi.mock("./captureScreen", () => ({ captureScreen: vi.fn() }));

const mockCapture = vi.mocked(captureScreen);

const jsonResponse = (status: number, body: unknown): Response => new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });

/** Le 502 de nginx : du HTML, aucun message écrit pour la personne (cf. `shared/api/client.ts`). */
const nginxBadGateway = (): Response => new Response("<html><body><h1>502 Bad Gateway</h1></body></html>", { status: 502, headers: { "content-type": "text/html" } });

const SAISIE = "Le planning ne s'affiche plus";

async function typeAndSend(): Promise<void> {
  await userEvent.type(screen.getByRole("textbox", { name: "Votre message" }), SAISIE);
  await userEvent.click(screen.getByRole("button", { name: "Envoyer" }));
}

beforeEach(() => {
  mockCapture.mockReset();
  mockCapture.mockResolvedValue(null);
  useToastStore.setState({ toasts: [] });
  queryClient.clear();
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("FeedbackDialog — refus du serveur, trajet réel", () => {
  // FeedbackController.php:161 (corps JSON, sans capture) et :262 (multipart, capture jointe) :
  // les deux chemins d'envoi, un seul message à garder.
  it.each([
    ["sans capture", null, "Le message est trop long."],
    ["avec capture", new Blob(["x"], { type: "image/webp" }), "La capture est trop volumineuse."],
  ] as const)("affiche dans la fenêtre le message d'un 422 %s", async (_label, capture, serverMessage) => {
    mockCapture.mockResolvedValue(capture);
    vi.stubGlobal("fetch", vi.fn(async () => jsonResponse(422, { error: serverMessage })));
    const onClose = vi.fn();
    renderWithProviders(<FeedbackDialog variant="free" onClose={onClose} />, { route: "/matchs" });

    await typeAndSend();

    const dialog = screen.getByRole("dialog");
    expect(await within(dialog).findByRole("alert")).toHaveTextContent(serverMessage);
    expect(onClose).not.toHaveBeenCalled();
  });

  // FeedbackController.php:133 : le limiteur du canal a sa propre phrase, c'est elle qu'on lit.
  it("affiche dans la fenêtre le message d'un 429 du canal de signalement", async () => {
    const serverMessage = "Trop de signalements — réessayez plus tard.";
    vi.stubGlobal("fetch", vi.fn(async () => jsonResponse(429, { error: serverMessage })));
    renderWithProviders(<FeedbackDialog variant="free" onClose={vi.fn()} />, { route: "/matchs" });

    await typeAndSend();

    expect(await within(screen.getByRole("dialog")).findByRole("alert")).toHaveTextContent(serverMessage);
  });

  // PROMESSE : un message serveur en anglais n'est pas lisible par la personne, il n'atteint jamais
  // l'écran. FeedbackController.php:148 (400) et :138 (404).
  it.each([
    [400, "Invalid JSON"],
    [404, "Club not found."],
  ] as const)("garde un texte générique sur un %i au message anglais", async (status, englishMessage) => {
    vi.stubGlobal("fetch", vi.fn(async () => jsonResponse(status, { error: englishMessage })));
    renderWithProviders(<FeedbackDialog variant="free" onClose={vi.fn()} />, { route: "/matchs" });

    await typeAndSend();

    const alert = await within(screen.getByRole("dialog")).findByRole("alert");
    expect(alert.textContent?.trim()).not.toBe("");
    expect(alert).not.toHaveTextContent(englishMessage);
  });

  // PROMESSE : le limiteur global (ApiRateLimitSubscriber.php:61) ne met pas d'anglais à l'écran, le
  // repli doux prend sa place. Corps relevés sur le vrai serveur : `detail` vaut le message de
  // l'exception avec debug, la phrase de statut sans debug (RFC 7807 de Symfony).
  it.each([
    ["avec debug", "API rate limit exceeded."],
    ["sans debug", "Too Many Requests"],
  ] as const)("affiche le repli doux sur le 429 du limiteur global %s", async (_label, detail) => {
    const body = { type: "https://tools.ietf.org/html/rfc2616#section-10", title: "An error occurred", status: 429, detail };
    vi.stubGlobal("fetch", vi.fn(async () => jsonResponse(429, body)));
    renderWithProviders(<FeedbackDialog variant="free" onClose={vi.fn()} />, { route: "/matchs" });

    await typeAndSend();

    expect(await within(screen.getByRole("dialog")).findByRole("alert")).toHaveTextContent(FEEDBACK_TEXTS.tooManyReports);
  });

  // PROMESSE : un refus se lit à un seul endroit, la fenêtre. Seul test sur le vrai `queryClient` :
  // son filet global `MutationCache.onError` ajoute un toast si le hook ne porte pas d'`onError`.
  it("n'ajoute aucun toast au message de la fenêtre", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => jsonResponse(422, { error: "Le message est trop long." })));
    const router = createMemoryRouter([{ path: "*", element: <FeedbackDialog variant="free" onClose={vi.fn()} /> }], { initialEntries: ["/matchs"] });
    render(
      <QueryClientProvider client={queryClient}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    );

    await typeAndSend();

    expect(await within(screen.getByRole("dialog")).findByRole("alert")).toHaveTextContent("Le message est trop long.");
    // Le toast part d'une promesse (`errorMessage`) : laisser le filet aller au bout avant de conclure.
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 50));
    });
    expect(useToastStore.getState().toasts).toEqual([]);
  });

  it("garde un texte générique quand la réponse n'a aucun message exploitable", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => nginxBadGateway()));
    renderWithProviders(<FeedbackDialog variant="free" onClose={vi.fn()} />, { route: "/matchs" });

    await typeAndSend();

    const alert = await within(screen.getByRole("dialog")).findByRole("alert");
    expect(alert.textContent?.trim()).not.toBe("");
    expect(alert).not.toHaveTextContent("Bad Gateway");
    expect(alert).not.toHaveTextContent("<html");
  });

  it("garde la saisie après un refus et permet de la renvoyer telle quelle", async () => {
    const fetchMock = vi.fn(async () => jsonResponse(422, { error: "Le message est trop long." }));
    vi.stubGlobal("fetch", fetchMock);
    renderWithProviders(<FeedbackDialog variant="free" onClose={vi.fn()} />, { route: "/matchs" });

    await typeAndSend();
    await within(screen.getByRole("dialog")).findByRole("alert");

    expect(screen.getByRole("textbox", { name: "Votre message" })).toHaveValue(SAISIE);
    const send = screen.getByRole("button", { name: "Envoyer" });
    expect(send).toBeEnabled();

    await userEvent.click(send);
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
  });
});
