import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useToastStore } from "@/shared/stores/toastStore";
import { renderWithProviders } from "@/test/utils";

import { submitFeedback } from "./api";
import { captureScreen } from "./captureScreen";
import { FeedbackDialog } from "./FeedbackDialog";

/**
 * Chantier F PR3 — la CAPTURE D'ÉCRAN jointe par la modale de signalement.
 *
 * Fichier À PART (patron FeedbackDialogLastClick) : il importe le module NEUF `./captureScreen`,
 * donc il rougit SEUL tant que ce module / le câblage n'existent pas, sans faire tomber au
 * chargement la suite verte de `FeedbackDialog.test.tsx`. La CAPTURE est derrière cette
 * abstraction (mockée ici) : le choix de la librairie (modern-screenshot / html-to-image, tranché
 * par le spike) reste isolé — le test ne connaît que `captureScreen(): Promise<Blob | null>`.
 */
vi.mock("./api", async (importOriginal) => {
  const original = await importOriginal<typeof import("./api")>();
  return { ...original, submitFeedback: vi.fn() };
});
vi.mock("./captureScreen", () => ({ captureScreen: vi.fn() }));

const mockSubmit = vi.mocked(submitFeedback);
const mockCapture = vi.mocked(captureScreen);

/** Le dernier payload remis à `submitFeedback` (la capture y est un champ `screenshot`). */
function lastPayload(): { screenshot?: unknown } {
  return mockSubmit.mock.calls[0][0] as { screenshot?: unknown };
}

beforeEach(() => {
  mockSubmit.mockReset();
  mockCapture.mockReset();
  mockSubmit.mockResolvedValue({ id: "fb1" });
  useToastStore.setState({ toasts: [] });
});

describe("FeedbackDialog — capture d'écran (PR3)", () => {
  // PROMESSE : la case « Joindre une capture » est COCHÉE PAR DÉFAUT, sur les DEUX portes
  // (contextuelle en-tête + libre burger). Falsification : case absente ou décochée par défaut.
  it("coche « Joindre une capture » par défaut sur la porte contextuelle", () => {
    renderWithProviders(<FeedbackDialog variant="contextual" screen="/planning" onClose={vi.fn()} />, { route: "/planning" });
    expect(screen.getByRole("checkbox", { name: "Joindre une capture" })).toBeChecked();
  });

  it("coche « Joindre une capture » par défaut sur la porte libre (burger)", () => {
    renderWithProviders(<FeedbackDialog variant="free" onClose={vi.fn()} />, { route: "/planning" });
    expect(screen.getByRole("checkbox", { name: "Joindre une capture" })).toBeChecked();
  });

  // PROMESSE : case cochée + capture réussie → l'image est JOINTE à l'envoi, et la MODALE
  // elle-même est EXCLUE de la capture. Témoin falsifiable choisi : `captureScreen` reçoit un
  // `excludeSelector` qui, résolu dans le DOM, désigne un nœud CONTENANT la modale (donc le
  // bouton « Envoyer ») — ce nœud ne sera pas peint dans la capture. Sans cet argument, ROUGE.
  it("joint la capture et EXCLUT la modale (excludeSelector couvrant le dialogue)", async () => {
    const blob = new Blob(["png-bytes"], { type: "image/png" });
    mockCapture.mockResolvedValue(blob);
    renderWithProviders(<FeedbackDialog variant="contextual" screen="/planning" onClose={vi.fn()} />, { route: "/planning" });

    await userEvent.type(screen.getByRole("textbox", { name: "Décrivez le problème" }), "Ça casse");
    await userEvent.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(mockSubmit).toHaveBeenCalledTimes(1));
    expect(mockCapture).toHaveBeenCalledTimes(1);

    const opts = mockCapture.mock.calls[0][0] as { excludeSelector?: string } | undefined;
    expect(opts?.excludeSelector).toBeTruthy();
    const excluded = document.querySelector(opts?.excludeSelector ?? "");
    expect(excluded).not.toBeNull();
    // La modale (son bouton « Envoyer ») est à l'intérieur du nœud exclu → jamais dans la capture.
    expect(excluded).toContainElement(screen.getByRole("button", { name: "Envoyer" }));

    expect(lastPayload().screenshot).toBe(blob);
  });

  // PROMESSE : case DÉCOCHÉE → aucune capture déclenchée, aucun fichier envoyé (chemin JSON).
  it("décochée : ne capture rien et n'envoie aucun fichier", async () => {
    mockCapture.mockResolvedValue(new Blob(["x"], { type: "image/png" }));
    renderWithProviders(<FeedbackDialog variant="contextual" screen="/planning" onClose={vi.fn()} />, { route: "/planning" });

    await userEvent.click(screen.getByRole("checkbox", { name: "Joindre une capture" }));
    await userEvent.type(screen.getByRole("textbox", { name: "Décrivez le problème" }), "Sans image");
    await userEvent.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(mockSubmit).toHaveBeenCalledTimes(1));
    expect(mockCapture).not.toHaveBeenCalled();
    expect(lastPayload().screenshot).toBeUndefined();
  });

  // PROMESSE : un ÉCHEC de capture ne perd pas le signalement — il part SANS image, sans planter.
  it("échec de capture : le signalement part quand même, sans image", async () => {
    mockCapture.mockRejectedValue(new Error("canvas indisponible"));
    const onClose = vi.fn();
    renderWithProviders(<FeedbackDialog variant="contextual" screen="/planning" onClose={onClose} />, { route: "/planning" });

    await userEvent.type(screen.getByRole("textbox", { name: "Décrivez le problème" }), "Capture ratée");
    await userEvent.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(mockSubmit).toHaveBeenCalledTimes(1));
    expect(lastPayload().screenshot).toBeUndefined();
    await waitFor(() => expect(onClose).toHaveBeenCalled());
    expect(useToastStore.getState().toasts.some((t) => t.message.includes("Signalement envoyé"))).toBe(true);
  });

  // D16 (anti double envoi) — `FeedbackDialog` attend `captureScreen` AVANT `mutate` : deux « Envoyer »
  // PENDANT la capture en attente ne doivent déclencher qu'UN SEUL `submitFeedback` (cas réel ALAP ×4
  // à la même seconde). Falsification : sans garde « envoi en cours », le 2ᵉ clic relance une capture
  // et un 2ᵉ envoi. Serveur : la dédup 60 s est le filet (Integration `FeedbackTest`).
  it("D16 — deux « Envoyer » pendant la capture ne déclenchent qu'UN seul envoi", async () => {
    let resolveCapture: (b: Blob) => void = () => {};
    mockCapture.mockImplementation(() => new Promise<Blob>((resolve) => { resolveCapture = resolve; }));
    renderWithProviders(<FeedbackDialog variant="contextual" screen="/planning" onClose={vi.fn()} />, { route: "/planning" });

    await userEvent.type(screen.getByRole("textbox", { name: "Décrivez le problème" }), "Clic double");
    const envoyer = screen.getByRole("button", { name: "Envoyer" });
    await userEvent.click(envoyer); // 1er envoi → capture PENDANTE
    await userEvent.click(envoyer); // 2e envoi pendant la capture → doit être inerte

    resolveCapture(new Blob(["x"], { type: "image/png" }));

    await waitFor(() => expect(mockSubmit).toHaveBeenCalledTimes(1));
    expect(mockCapture, "une seule capture, jamais deux").toHaveBeenCalledTimes(1);
  });
});
