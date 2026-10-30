import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { clearLastIncident, recordIncident } from "@/shared/api/lastIncidentStore";
import { useToastStore } from "@/shared/stores/toastStore";
import { renderWithProviders } from "@/test/utils";

import { submitFeedback } from "./api";
import { captureScreen } from "./captureScreen";
import { FeedbackDialog } from "./FeedbackDialog";
import { clearLastClick, recordLastClick } from "./lastClickStore";

vi.mock("./api", async (importOriginal) => {
  const original = await importOriginal<typeof import("./api")>();
  return { ...original, submitFeedback: vi.fn() };
});
// La capture est pilotée par son propre fichier (FeedbackDialogCapture.test.tsx) ; ici on la
// neutralise (aucune image jointe) pour que ces scénarios restent sur le chemin sans capture.
vi.mock("./captureScreen", () => ({ captureScreen: vi.fn() }));

const mockSubmit = vi.mocked(submitFeedback);
const mockCapture = vi.mocked(captureScreen);

/** Erreur HTTP factice à la façon de ky (status + `data` = corps déjà consommé). */
function httpError(status: number, body?: unknown): HTTPError {
  const err = new HTTPError(new Response(JSON.stringify(body ?? {}), { status }), new Request("http://t/api/feedback"), {} as never);
  (err as { data?: unknown }).data = body;
  return err;
}

beforeEach(() => {
  mockSubmit.mockReset();
  mockCapture.mockReset();
  mockCapture.mockResolvedValue(null);
  clearLastIncident();
  clearLastClick();
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

  // Chantier F PR2 — la porte contextuelle joint les repères que SEUL le front connaît :
  // le libellé d'écran lisible, les filtres/plage affichés, et le TRIO complet de l'incident
  // (statut + URL + code), pas seulement le request-id. Falsification : ignorer ces props /
  // ne joindre que le request-id laisse ces clés absentes du payload.
  it("joint screenLabel, activeFilters, displayedRange et le trio complet de l'incident", async () => {
    mockSubmit.mockResolvedValue({ id: "fb1" });
    recordIncident({ status: 500, url: "/api/venues", code: "venue_conflict", requestId: "req-1" });
    renderWithProviders(
      <FeedbackDialog
        variant="contextual"
        screen="wizard/venues"
        screenLabel="Assistant · Étape 2/6 · Gymnases"
        activeFilters="familles : Championnat"
        displayedRange="week-end du 08/11"
        onClose={vi.fn()}
      />,
      { route: "/wizard" },
    );

    await userEvent.type(screen.getByRole("textbox", { name: "Décrivez le problème" }), "Souci de gymnase");
    await userEvent.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(mockSubmit).toHaveBeenCalled());
    const ctx = mockSubmit.mock.calls[0][0].context as Record<string, unknown>;
    expect(ctx).toMatchObject({
      screenLabel: "Assistant · Étape 2/6 · Gymnases",
      activeFilters: "familles : Championnat",
      displayedRange: "week-end du 08/11",
      incidentUrl: "/api/venues",
      incidentCode: "venue_conflict",
    });
    expect(String(ctx.incidentStatus)).toBe("500");
  });

  // Note : la jointure du DERNIER CLIC (`lastClickStore`) est prouvée dans son propre
  // fichier `FeedbackDialogLastClick.test.tsx` (import de module neuf isolé — il rougit SEUL
  // tant que le store n'existe pas, sans casser le chargement de CE fichier sous Vite).

  // Garde de frontière : la porte LIBRE (burger) reste un contexte léger — les repères riches
  // de la porte contextuelle ne doivent JAMAIS fuiter dans un signalement libre.
  it("variante libre : n'ajoute aucun des repères riches contextuels", async () => {
    mockSubmit.mockResolvedValue({ id: "fb1" });
    recordIncident({ status: 500, url: "/api/x", code: "boom", requestId: "req-2" });
    renderWithProviders(<FeedbackDialog variant="free" onClose={vi.fn()} />, { route: "/planning" });

    await userEvent.type(screen.getByRole("textbox", { name: "Votre message" }), "Une idée");
    await userEvent.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(mockSubmit).toHaveBeenCalled());
    const ctx = mockSubmit.mock.calls[0][0].context as Record<string, unknown>;
    expect(ctx).not.toHaveProperty("screenLabel");
    expect(ctx).not.toHaveProperty("lastClick");
    expect(ctx).not.toHaveProperty("incidentStatus");
  });
});

describe("FeedbackDialog — version de l'app (P4-355)", () => {
  // La version du bundle (`appVersion`, foyer `shared/lib/appVersion.ts`, repli « dev » hors
  // build) est jointe au contexte de BASE des DEUX portes — le support compare « App vX · API vY ».
  // Falsification : tant qu'elle n'est pas ajoutée au contexte de base, la clé est absente du
  // payload. En jsdom (aucun VITE_APP_VERSION), c'est le repli → une chaîne non vide.
  it.each([["free"], ["contextual"]] as const)("joint appVersion au contexte sur la porte %s", async (variant) => {
    mockSubmit.mockResolvedValue({ id: "fb1" });
    renderWithProviders(<FeedbackDialog variant={variant} onClose={vi.fn()} />, { route: "/planning" });

    const messageLabel = "contextual" === variant ? "Décrivez le problème" : "Votre message";
    await userEvent.type(screen.getByRole("textbox", { name: messageLabel }), "Un souci");
    await userEvent.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(mockSubmit).toHaveBeenCalled());
    const ctx = mockSubmit.mock.calls[0][0].context as Record<string, unknown>;
    expect(typeof ctx.appVersion, "appVersion est une chaîne").toBe("string");
    expect((ctx.appVersion as string).length, "appVersion non vide (repli « dev »)").toBeGreaterThan(0);
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

describe("FeedbackDialog — fil d'incident client (D17)", () => {
  // La porte CONTEXTUELLE joint le fil que seul le front connaît : l'anneau des dernières erreurs API
  // (`recentErrors`), celui des derniers clics accessibles (`recentClicks`) et l'horloge RÉELLE DU POSTE
  // (`clientClock` {at, timeZone}, exception assumée à `shared/lib/clock.ts` : heure navigateur, pas
  // l'« aujourd'hui » métier). Falsification : retirer ces jointures du dialog laisse les clés absentes.
  it("joint recentErrors, recentClicks et clientClock au contexte envoyé", async () => {
    mockSubmit.mockResolvedValue({ id: "fb1" });
    // Deux erreurs API récentes (la plus récente en tête de l'anneau) et un clic accessible récent.
    recordIncident({ status: 500, url: "/api/a", method: "GET", route: "/api/a", code: "boom", errors: ["e1"], requestId: "r1" });
    recordIncident({ status: 422, url: "/api/b", method: "POST", route: "/api/b", errors: ["e2"], requestId: "r2" });
    const control = document.createElement("button");
    control.setAttribute("aria-label", "Importer le parc");
    recordLastClick(control);

    renderWithProviders(<FeedbackDialog variant="contextual" screen="wizard/venues" onClose={vi.fn()} />, { route: "/wizard" });
    await userEvent.type(screen.getByRole("textbox", { name: "Décrivez le problème" }), "Souci au collage");
    await userEvent.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(mockSubmit).toHaveBeenCalled());
    const ctx = mockSubmit.mock.calls[0][0].context as Record<string, unknown>;

    // recentErrors : la plus récente en tête, chaque entrée porte method/route/status.
    expect(Array.isArray(ctx.recentErrors), "recentErrors est un tableau").toBe(true);
    const recentErrors = ctx.recentErrors as Array<Record<string, unknown>>;
    expect(recentErrors.length).toBeGreaterThanOrEqual(2);
    expect(recentErrors[0]).toMatchObject({ method: "POST", route: "/api/b", status: 422 });
    // recentClicks : les noms accessibles des derniers clics (jamais une valeur saisie).
    expect(ctx.recentClicks).toEqual(expect.arrayContaining(["Importer le parc"]));
    // clientClock : horloge RÉELLE du poste (at ISO + fuseau).
    const clock = ctx.clientClock as { at?: unknown; timeZone?: unknown };
    expect(typeof clock.at, "clientClock.at est une chaîne ISO").toBe("string");
    expect(typeof clock.timeZone, "clientClock.timeZone est une chaîne").toBe("string");
  });
});
