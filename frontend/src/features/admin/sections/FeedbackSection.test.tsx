import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { AdminFeedbackDetail, AdminFeedbackItem, AdminFeedbackQos, AdminFeedbackResponse } from "../api";
import { getAdminFeedback, getAdminFeedbackDetail, treatAdminFeedback, untreatAdminFeedback } from "../api";
import { useAdminStore } from "../store";
import { ADMIN_SECTIONS_TEXTS } from "../texts/sections";
import { FeedbackSection } from "./FeedbackSection";

vi.mock("../api", async (importOriginal) => {
  const original = await importOriginal<typeof import("../api")>();
  return {
    ...original,
    getAdminFeedback: vi.fn(),
    getAdminFeedbackDetail: vi.fn(),
    treatAdminFeedback: vi.fn(),
    untreatAdminFeedback: vi.fn(),
  };
});

const mockList = vi.mocked(getAdminFeedback);
const mockDetail = vi.mocked(getAdminFeedbackDetail);
const mockTreat = vi.mocked(treatAdminFeedback);
const mockUntreat = vi.mocked(untreatAdminFeedback);

function httpError(status: number, body?: unknown): HTTPError {
  const err = new HTTPError(new Response(JSON.stringify(body ?? {}), { status }), new Request("http://t/api/admin/feedback"), {} as never);
  (err as { data?: unknown }).data = body;
  return err;
}

const qosBase: AdminFeedbackQos = {
  treatDelayByMonth: [{ month: "2026-08", avgHours: 3, p95Hours: 4 }],
  volumeByTopicMonth: [{ month: "2026-08", topic: "idea", count: 2 }],
  treatedShare: 0.5,
  oldestUntreatedAgeHours: 7.5,
};

const itemBase: AdminFeedbackItem = {
  id: "fb1",
  clubId: "c1",
  clubName: "Basket Club Lyon",
  topic: "bug",
  message: "Le planning ne s'affiche pas",
  createdAt: "2026-08-10T09:00:00+00:00",
  status: "untreated",
  treatedAt: null,
  hasHeavyContext: true,
};

function listResponse(item?: Partial<AdminFeedbackItem>): AdminFeedbackResponse {
  return {
    items: [{ ...itemBase, ...item }],
    pagination: { page: 1, limit: 25, total: 1, pages: 1 },
    qos: qosBase,
  };
}

const detailBase: AdminFeedbackDetail = {
  ...itemBase,
  context: {
    screen: "/planning",
    url: "http://app/planning",
    userAgent: "UA-test",
    scheduleId: "sc1",
    seasonId: "se1",
    scheduleStatus: "COMPLETED",
    snapshot: { weeks: [1, 2, 3] },
    diagnostics: [{ type: "VENUE_OVERBOOKED" }],
  },
};

beforeEach(() => {
  mockList.mockReset();
  mockDetail.mockReset();
  mockTreat.mockReset();
  mockUntreat.mockReset();
  useAdminStore.getState().setSession({ id: "sa", email: "sa@x" }, "csrf-token");
});

describe("FeedbackSection", () => {
  it("affiche le panneau qualité de service en tête", async () => {
    mockList.mockResolvedValue(listResponse());
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    expect(await screen.findByText(ADMIN_SECTIONS_TEXTS.feedbackQos)).toBeInTheDocument();
    expect(screen.getByText("50 %")).toBeInTheDocument(); // part traitée
    expect(screen.getByText(/7[.,]5\s*h/)).toBeInTheDocument(); // âge du plus vieux non traité
  });

  it("liste les signalements (club, topic FR, statut)", async () => {
    mockList.mockResolvedValue(listResponse());
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    const list = await screen.findByRole("region", { name: ADMIN_SECTIONS_TEXTS.feedbackListLabel });
    expect(await within(list).findByText("Basket Club Lyon")).toBeInTheDocument();
    expect(within(list).getByText(ADMIN_SECTIONS_TEXTS.feedbackTopicLabel("bug"))).toBeInTheDocument();
    expect(within(list).getByText(ADMIN_SECTIONS_TEXTS.feedbackStatusLabel("untreated"))).toBeInTheDocument();
  });

  it("filtre par statut — requête serveur ciblée", async () => {
    mockList.mockResolvedValue(listResponse());
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await screen.findByText("Basket Club Lyon");
    await userEvent.click(screen.getByRole("button", { name: ADMIN_SECTIONS_TEXTS.feedbackFilterUntreated }));

    await waitFor(() => expect(mockList).toHaveBeenCalledWith({ status: "untreated" }));
  });

  it("le bouton Traiter appelle l'API avec le jeton CSRF", async () => {
    mockList.mockResolvedValue(listResponse());
    mockDetail.mockResolvedValue(detailBase);
    mockTreat.mockResolvedValue({ ...itemBase, status: "treated", treatedAt: "2026-08-11T09:00:00+00:00" });
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await userEvent.click(await screen.findByRole("button", { name: /voir le signalement de basket club lyon/i }));
    await screen.findByRole("dialog");
    await userEvent.click(screen.getByRole("button", { name: ADMIN_SECTIONS_TEXTS.feedbackTreat }));
    await userEvent.click(screen.getByRole("button", { name: /confirmer/i }));

    await waitFor(() => expect(mockTreat).toHaveBeenCalledWith("fb1", "csrf-token"));
  });

  it("rend un 409 lisible sur Traiter", async () => {
    mockList.mockResolvedValue(listResponse());
    mockDetail.mockResolvedValue(detailBase);
    mockTreat.mockRejectedValue(httpError(409, { error: "Ce signalement est déjà traité." }));
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await userEvent.click(await screen.findByRole("button", { name: /voir le signalement de basket club lyon/i }));
    await screen.findByRole("dialog");
    await userEvent.click(screen.getByRole("button", { name: ADMIN_SECTIONS_TEXTS.feedbackTreat }));
    await userEvent.click(screen.getByRole("button", { name: /confirmer/i }));

    expect(await screen.findByText("Ce signalement est déjà traité.")).toBeInTheDocument();
  });

  it("le bouton Rouvrir appelle untreat avec le jeton CSRF", async () => {
    mockList.mockResolvedValue(listResponse({ status: "treated", treatedAt: "2026-08-11T09:00:00+00:00" }));
    mockDetail.mockResolvedValue({ ...detailBase, status: "treated", treatedAt: "2026-08-11T09:00:00+00:00" });
    mockUntreat.mockResolvedValue({ ...itemBase, status: "untreated", treatedAt: null });
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await userEvent.click(await screen.findByRole("button", { name: /voir le signalement de basket club lyon/i }));
    await screen.findByRole("dialog");
    await userEvent.click(screen.getByRole("button", { name: ADMIN_SECTIONS_TEXTS.feedbackReopen }));
    await userEvent.click(screen.getByRole("button", { name: /confirmer/i }));

    await waitFor(() => expect(mockUntreat).toHaveBeenCalledWith("fb1", "csrf-token"));
  });

  // ⚠ Consigne revue PR 2 : le contenu utilisateur se rend en TEXTE PUR. Falsification
  // prévue : un dangerouslySetInnerHTML dans le détail interpréterait le HTML et rendrait
  // ce test rouge (le texte littéral disparaît, un <img> apparaît).
  it("rend le message du détail en texte pur (HTML échappé, jamais interprété)", async () => {
    const htmlMessage = "<img src=x onerror=alert(1)>Bonjour";
    mockList.mockResolvedValue(listResponse({ id: "fb1", message: htmlMessage }));
    mockDetail.mockResolvedValue({ ...detailBase, message: htmlMessage });
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await userEvent.click(await screen.findByRole("button", { name: /voir le signalement de basket club lyon/i }));
    const dialog = await screen.findByRole("dialog");

    expect(within(dialog).getByText(htmlMessage)).toBeInTheDocument();
    expect(dialog.querySelector("img")).toBeNull();
  });
});
