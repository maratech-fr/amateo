import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { AdminFeedbackDetail, AdminFeedbackItem, AdminFeedbackQos, AdminFeedbackResponse } from "../api";
import { getAdminFeedback, getAdminFeedbackDetail } from "../api";
import { useAdminStore } from "../store";
import { FeedbackSection } from "./FeedbackSection";

/**
 * Chantier F PR3 — l'APERÇU de la capture dans le détail de la console superadmin.
 *
 * Fichier À PART de `FeedbackSection.test.tsx` (vert) : il porte le NOUVEAU champ `hasScreenshot`
 * et n'écrase pas la suite existante. L'image est chargée depuis le rail superadmin
 * (`/api/admin/feedback/{id}/screenshot`, session cookie) UNIQUEMENT quand le signalement en
 * porte une — rien sinon.
 */
vi.mock("../api", async (importOriginal) => {
  const original = await importOriginal<typeof import("../api")>();
  return { ...original, getAdminFeedback: vi.fn(), getAdminFeedbackDetail: vi.fn(), treatAdminFeedback: vi.fn(), untreatAdminFeedback: vi.fn() };
});

const mockList = vi.mocked(getAdminFeedback);
const mockDetail = vi.mocked(getAdminFeedbackDetail);

const qosBase: AdminFeedbackQos = {
  treatDelayByMonth: [],
  volumeByTopicMonth: [],
  treatedShare: 0,
  oldestUntreatedAgeHours: null,
};

// `hasScreenshot` est le NOUVEAU pointeur léger exposé par le backend (PR3) — ajouté au type par NEO.
const itemBase = {
  id: "fb1",
  clubId: "c1",
  clubName: "Basket Club Lyon",
  topic: "bug",
  message: "Le planning ne s'affiche pas",
  createdAt: "2026-08-10T09:00:00+00:00",
  status: "untreated",
  treatedAt: null,
  hasHeavyContext: false,
  hasScreenshot: false,
} satisfies Record<string, unknown> as unknown as AdminFeedbackItem;

function listResponse(item: Partial<AdminFeedbackItem>): AdminFeedbackResponse {
  return { items: [{ ...itemBase, ...item }], pagination: { page: 1, limit: 25, total: 1, pages: 1 }, qos: qosBase };
}

function detail(hasScreenshot: boolean): AdminFeedbackDetail {
  return { ...itemBase, hasScreenshot, context: { screen: "/planning", url: "http://app/planning", userAgent: "UA" } } as unknown as AdminFeedbackDetail;
}

beforeEach(() => {
  mockList.mockReset();
  mockDetail.mockReset();
  useAdminStore.getState().setSession({ id: "sa", email: "sa@x" }, "csrf-token");
});

describe("FeedbackSection — aperçu de la capture (PR3)", () => {
  // PROMESSE : hasScreenshot → un aperçu <img> pointant vers le rail superadmin de CE signalement,
  // avec un nom accessible (alt). Falsification : aucun <img>, ou un src qui ne cible pas l'endpoint.
  it("affiche l'aperçu de la capture quand hasScreenshot", async () => {
    mockList.mockResolvedValue(listResponse({ hasScreenshot: true }));
    mockDetail.mockResolvedValue(detail(true));
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await userEvent.click(await screen.findByRole("button", { name: /voir le signalement de basket club lyon/i }));
    const dialog = await screen.findByRole("dialog");

    const img = await within(dialog).findByRole("img");
    expect(img.getAttribute("src")).toContain("/api/admin/feedback/fb1/screenshot");
    expect((img.getAttribute("alt") ?? "").length).toBeGreaterThan(0);
  });

  // PROMESSE : pas de capture → aucun aperçu (jamais une image cassée ni un endpoint sollicité à vide).
  it("n'affiche aucun aperçu quand hasScreenshot est faux", async () => {
    mockList.mockResolvedValue(listResponse({ hasScreenshot: false }));
    mockDetail.mockResolvedValue(detail(false));
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await userEvent.click(await screen.findByRole("button", { name: /voir le signalement de basket club lyon/i }));
    const dialog = await screen.findByRole("dialog");

    expect(within(dialog).queryByRole("img")).toBeNull();
  });
});

describe("FeedbackSection — capture agrandie", () => {
  /** Ouvre le détail d'un signalement avec capture et rend la vignette, un bouton nommé. */
  async function openThumbnail(): Promise<HTMLElement> {
    mockList.mockResolvedValue(listResponse({ hasScreenshot: true }));
    mockDetail.mockResolvedValue(detail(true));
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await userEvent.click(await screen.findByRole("button", { name: /voir le signalement de basket club lyon/i }));
    const dialog = await screen.findByRole("dialog");
    return within(dialog).findByRole("button", { name: /capture/i });
  }

  /** La fenêtre agrandie : la seule, parmi les fenêtres ouvertes, nommée d'après la capture. */
  const zoomDialog = (): HTMLElement => screen.getByRole("dialog", { name: /capture/i });

  // PROMESSE : un clic sur la vignette ouvre la capture de CE signalement en grand, dans une
  // fenêtre nommée, par-dessus le détail.
  it("ouvre la capture en grand au clic sur la vignette", async () => {
    await userEvent.click(await openThumbnail());

    const img = within(zoomDialog()).getByRole("img");
    expect(img.getAttribute("src")).toContain("/api/admin/feedback/fb1/screenshot");
    expect((img.getAttribute("alt") ?? "").length).toBeGreaterThan(0);
    expect(screen.getAllByRole("dialog")).toHaveLength(2);
  });

  // PROMESSE : la vignette s'atteint et s'active au clavier, sans souris.
  it.each([["Entrée", "{Enter}"], ["Espace", " "]] as const)("ouvre la capture en grand avec %s", async (_label, key) => {
    (await openThumbnail()).focus();

    await userEvent.keyboard(key);

    expect(within(zoomDialog()).getByRole("img")).toBeInTheDocument();
  });

  // PROMESSE : Échap ferme la seule capture, le détail reste ouvert et le focus revient à la vignette.
  it("ferme la capture avec Échap sans fermer le détail", async () => {
    const thumbnail = await openThumbnail();
    await userEvent.click(thumbnail);
    expect(zoomDialog()).toHaveFocus();

    await userEvent.keyboard("{Escape}");

    expect(screen.queryByRole("dialog", { name: /capture/i })).toBeNull();
    expect(screen.getAllByRole("dialog")).toHaveLength(1);
    expect(thumbnail).toHaveFocus();
  });

  // PROMESSE : un bouton nommé ferme aussi la capture, sans fermer le détail.
  it("ferme la capture avec son bouton Fermer", async () => {
    await userEvent.click(await openThumbnail());

    await userEvent.click(within(zoomDialog()).getByRole("button", { name: "Fermer" }));

    expect(screen.queryByRole("dialog", { name: /capture/i })).toBeNull();
    expect(screen.getAllByRole("dialog")).toHaveLength(1);
  });
});
