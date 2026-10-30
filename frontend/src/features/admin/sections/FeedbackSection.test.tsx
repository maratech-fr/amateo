import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { AdminFeedbackAuto, AdminFeedbackDetail, AdminFeedbackItem, AdminFeedbackQos, AdminFeedbackResponse } from "../api";
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
  hasScreenshot: false,
  purgedAt: null,
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

  // P4-149 (option 1) — le vide « Aucun signalement » passe par EmptyHint variant="console"
  // (skin partagé `text-console-muted`), comme ses 14 voisins, jamais un <p> console fait main.
  it("rend l'état vide via le skin console partagé (EmptyHint)", async () => {
    mockList.mockResolvedValue({ items: [], pagination: { page: 1, limit: 25, total: 0, pages: 0 }, qos: qosBase });
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    const hint = await screen.findByText(ADMIN_SECTIONS_TEXTS.feedbackEmpty);
    expect(hint.className).toContain("text-console-muted");
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

  // Chantier F PR2 — le détail rend le CONTEXTE AUTOMATIQUE large (`context.auto`) + les
  // repères TEXTE du client en CINQ blocs titrés : Planning / Période / Écran / Dernier geste /
  // Technique. Falsification : le bloc plat actuel ne rend aucun de ces titres ni ces valeurs.
  it("rend le contexte large en 5 blocs titrés (context.auto + repères texte)", async () => {
    mockList.mockResolvedValue(listResponse());
    mockDetail.mockResolvedValue({
      ...detailBase,
      context: {
        screen: "wizard/venues",
        url: "http://app/wizard",
        userAgent: "UA-test",
        screenLabel: "Assistant · Étape 2/6 · Gymnases",
        activeFilters: "familles : Championnat",
        displayedRange: "week-end du 08/11",
        lastClick: "bouton « Ajouter un gymnase »",
        incidentStatus: "500",
        incidentUrl: "http://app/api/venues",
        incidentCode: "venue_conflict",
        auto: {
          role: "manager",
          season: { id: "se1", name: "Saison 2026-2027", startDate: "2026-09-01", endDate: "2027-06-30" },
          offer: { plan: "discovery", name: "Découverte" },
          counters: { teams: 14, venues: 3 },
          socle: { inForce: true, validatedAt: "2026-10-12T10:00:00+00:00" },
          period: { type: "holiday", name: "Vacances de la Toussaint", startDate: "2026-10-17", endDate: "2026-11-02", progress: 0.3 },
          versions: { displayed: { id: "sc3", number: 3, name: "v3" }, pointed: { id: "sc2", number: 2, name: "v2" } },
          generation: { running: false },
          simulatedToday: null,
        },
      },
    });
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await userEvent.click(await screen.findByRole("button", { name: /voir le signalement de basket club lyon/i }));
    const dialog = await screen.findByRole("dialog");

    // Les cinq blocs sont titrés (clés centralisées, jamais la phrase recopiée).
    expect(within(dialog).getByText(ADMIN_SECTIONS_TEXTS.feedbackBlockPlanning)).toBeInTheDocument();
    expect(within(dialog).getByText(ADMIN_SECTIONS_TEXTS.feedbackBlockPeriod)).toBeInTheDocument();
    expect(within(dialog).getByText(ADMIN_SECTIONS_TEXTS.feedbackBlockScreen)).toBeInTheDocument();
    expect(within(dialog).getByText(ADMIN_SECTIONS_TEXTS.feedbackBlockLastAction)).toBeInTheDocument();
    expect(within(dialog).getByText(ADMIN_SECTIONS_TEXTS.feedbackBlockTechnical)).toBeInTheDocument();

    // Des valeurs du contexte (données serveur / client) sont lisibles.
    expect(within(dialog).getByText(/Saison 2026-2027/)).toBeInTheDocument();
    expect(within(dialog).getByText(/Vacances de la Toussaint/)).toBeInTheDocument();
    expect(within(dialog).getByText(/Assistant · Étape 2\/6 · Gymnases/)).toBeInTheDocument();
    expect(within(dialog).getByText(/Ajouter un gymnase/)).toBeInTheDocument();
    expect(within(dialog).getByText(/Découverte/)).toBeInTheDocument();
  });

  // Rétro-compatibilité : un ANCIEN signalement sans `context.auto` (ni repères texte) reste
  // lisible — le détail ne plante pas et rend ses champs plats existants.
  it("rend un ancien signalement sans context.auto sans planter", async () => {
    mockList.mockResolvedValue(listResponse());
    mockDetail.mockResolvedValue({
      ...detailBase,
      context: { screen: "/planning", url: "http://app/planning", userAgent: "UA-old" },
    });
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await userEvent.click(await screen.findByRole("button", { name: /voir le signalement de basket club lyon/i }));
    const dialog = await screen.findByRole("dialog");

    expect(within(dialog).getByText(detailBase.message)).toBeInTheDocument();
    expect(within(dialog).getByText("/planning")).toBeInTheDocument();
  });

  // ⚠ Sécurité (revue PR1) : une URL de schéma `javascript:` jointe au contexte n'est JAMAIS
  // rendue en LIEN cliquable — texte pur seulement. Falsification : rendre l'URL en <a href>
  // crée une ancre `javascript:` et rend ce test rouge.
  it("ne rend jamais une URL javascript: comme lien (texte pur)", async () => {
    mockList.mockResolvedValue(listResponse());
    mockDetail.mockResolvedValue({
      ...detailBase,
      context: {
        screen: "/planning",
        url: "javascript:alert(1)",
        incidentUrl: "javascript:alert(2)",
        userAgent: "UA",
      },
    });
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await userEvent.click(await screen.findByRole("button", { name: /voir le signalement de basket club lyon/i }));
    const dialog = await screen.findByRole("dialog");

    expect(dialog.querySelector('a[href^="javascript:"]')).toBeNull();
    expect(within(dialog).getByText(/javascript:alert\(1\)/)).toBeInTheDocument();
  });

  // Chantier F PR2 — deep-link : `?feedback={id}` ouvre directement le détail (lien du digest,
  // PR4). Falsification : sans lecture du param, aucun dialogue ne s'ouvre au montage.
  it("ouvre le détail depuis le deep-link ?feedback={id}", async () => {
    mockList.mockResolvedValue(listResponse());
    mockDetail.mockResolvedValue(detailBase);
    renderWithProviders(<FeedbackSection />, { route: "/admin?feedback=fb1" });

    expect(await screen.findByRole("dialog")).toBeInTheDocument();
    await waitFor(() => expect(mockDetail).toHaveBeenCalledWith("fb1"));
  });

  // Chantier F PR2 — le filtre s'initialise depuis `?fstatus=untreated` (le digest pointera
  // les non-traités). Falsification : sans lecture du param, la liste part sur « Tous ».
  it("initialise le filtre depuis ?fstatus=untreated", async () => {
    mockList.mockResolvedValue(listResponse());
    renderWithProviders(<FeedbackSection />, { route: "/admin?fstatus=untreated" });

    await waitFor(() => expect(mockList).toHaveBeenCalledWith(expect.objectContaining({ status: "untreated" })));
    expect(screen.getByRole("button", { name: ADMIN_SECTIONS_TEXTS.feedbackFilterUntreated })).toHaveAttribute("aria-pressed", "true");
  });

  // Chantier F PR2 — la recherche par club ou mot du message passe `q` au serveur (le filtrage
  // ILIKE/échappement est prouvé côté backend, AdminFeedbackTest). Falsification : pas de champ
  // de recherche, ou `q` jamais transmis.
  it("passe le terme de recherche q au serveur", async () => {
    mockList.mockResolvedValue(listResponse());
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await screen.findByText("Basket Club Lyon");
    await userEvent.type(screen.getByRole("textbox", { name: ADMIN_SECTIONS_TEXTS.feedbackSearchLabel }), "lyon");

    await waitFor(() => expect(mockList).toHaveBeenCalledWith(expect.objectContaining({ q: "lyon" })));
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

  // P4-355 — la console affiche « App vX · API vY » et SIGNALE l'écart (bundle en cache) quand la
  // version du front déclarée diffère de celle de l'API résolue serveur. Falsification : sans le
  // rendu des versions, ni les valeurs ni la mention d'écart n'apparaissent.
  it("affiche les versions App/API et signale l'écart quand elles diffèrent", async () => {
    const auto = {
      role: "manager",
      season: { id: "se1", name: "Saison 2026-2027", startDate: "2026-09-01", endDate: "2027-06-30" },
      generation: { running: false },
      simulatedToday: null,
      apiVersion: "1.2.4",
    } as AdminFeedbackAuto;
    mockList.mockResolvedValue(listResponse());
    mockDetail.mockResolvedValue({
      ...detailBase,
      context: { screen: "/planning", url: "http://app/planning", userAgent: "UA", appVersion: "1.2.3", auto },
    });
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await userEvent.click(await screen.findByRole("button", { name: /voir le signalement de basket club lyon/i }));
    const dialog = await screen.findByRole("dialog");

    expect(within(dialog).getByText(/1\.2\.3/), "la version du front est lisible").toBeInTheDocument();
    expect(within(dialog).getByText(/1\.2\.4/), "la version de l'API est lisible").toBeInTheDocument();
    expect(within(dialog).getByText(/cache/i), "l'écart de version mentionne le cache du bundle").toBeInTheDocument();
  });

  it("ne signale aucun écart quand App et API coïncident", async () => {
    const auto = { role: "manager", generation: { running: false }, simulatedToday: null, apiVersion: "1.2.4" } as AdminFeedbackAuto;
    mockList.mockResolvedValue(listResponse());
    mockDetail.mockResolvedValue({
      ...detailBase,
      context: { screen: "/planning", url: "http://app/planning", userAgent: "UA", appVersion: "1.2.4", auto },
    });
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await userEvent.click(await screen.findByRole("button", { name: /voir le signalement de basket club lyon/i }));
    const dialog = await screen.findByRole("dialog");

    expect(within(dialog).getAllByText(/1\.2\.4/).length, "la version coïncidente reste lisible").toBeGreaterThan(0);
    expect(within(dialog).queryByText(/cache/i), "aucune mention d'écart quand les versions coïncident").toBeNull();
  });

  // P4-355 (amendement) — le résumé d'un échec de génération récent (`auto.generationFailure`) se lit
  // dans le bloc Planning : le 1ᵉʳ message d'erreur et le type du diagnostic, sans copier le JSON.
  // Falsification : sans rendu du résumé, ni le message ni le type n'apparaissent.
  it("rend le résumé d'un échec de génération récent dans le bloc Planning", async () => {
    const auto = {
      role: "manager",
      socle: { inForce: true, validatedAt: "2026-10-12T10:00:00+00:00" },
      generation: { running: false },
      simulatedToday: null,
      generationFailure: {
        status: "failed",
        kind: "technical",
        type: "engine_timeout",
        failedAt: "2026-10-25T08:00:00+00:00",
        version: { number: 4, name: "Planning v4" },
        firstError: "Schedule generation timed out.",
        countsByType: { engine_timeout: 1 },
      },
    } as AdminFeedbackAuto;
    mockList.mockResolvedValue(listResponse());
    mockDetail.mockResolvedValue({
      ...detailBase,
      context: { screen: "/planning", url: "http://app/planning", userAgent: "UA", auto },
    });
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await userEvent.click(await screen.findByRole("button", { name: /voir le signalement de basket club lyon/i }));
    const dialog = await screen.findByRole("dialog");

    expect(within(dialog).getByText(ADMIN_SECTIONS_TEXTS.feedbackBlockPlanning)).toBeInTheDocument();
    expect(within(dialog).getByText(/Schedule generation timed out\./), "le 1ᵉʳ message d'erreur est lisible").toBeInTheDocument();
    expect(within(dialog).getByText(/engine_timeout/), "le type du diagnostic est lisible").toBeInTheDocument();
  });

  // Chantier F PR4 — rétention : un signalement PURGÉ (texte + contexte détruits après 30 j,
  // marqueur `purgedAt`) rend « Contenu purgé (rétention) » (clé centralisée) à la place du
  // message, jamais un cadre vide. Falsification : sans lecture de `purgedAt`, le détail rend le
  // message vide et n'affiche jamais ce placeholder.
  it("affiche le placeholder de contenu purgé quand purgedAt est posé", async () => {
    mockList.mockResolvedValue(listResponse({ message: "", purgedAt: "2026-09-01T10:00:00+00:00" }));
    mockDetail.mockResolvedValue({ ...detailBase, message: "", context: null, purgedAt: "2026-09-01T10:00:00+00:00" });
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await userEvent.click(await screen.findByRole("button", { name: /voir le signalement de basket club lyon/i }));
    const dialog = await screen.findByRole("dialog");

    expect(within(dialog).getByText(ADMIN_SECTIONS_TEXTS.feedbackPurgedPlaceholder)).toBeInTheDocument();
  });

  // D15 (PR B « Signaler depuis une modale ») — l'état COMPLET de la modale ouvrante se lit dans un bloc
  // REPLIABLE « État de la modale », avec la mention « tronqué » (clé centralisée) quand le serveur a
  // coupé au plafond (`modalStateTruncated`). Le JSON brut est rendu en TEXTE PUR. Falsification : sans
  // rendu du bloc, ni le titre, ni la mention, ni le contenu n'apparaissent.
  it("rend le bloc « État de la modale » avec la mention tronqué quand modalStateTruncated", async () => {
    mockList.mockResolvedValue(listResponse());
    mockDetail.mockResolvedValue({
      ...detailBase,
      context: {
        screen: "/wizard",
        url: "http://app/wizard",
        userAgent: "UA",
        modalState: '{"step":"equipes","parc":"…"}',
        modalStateTruncated: true,
      },
    });
    renderWithProviders(<FeedbackSection />, { route: "/admin" });

    await userEvent.click(await screen.findByRole("button", { name: /voir le signalement de basket club lyon/i }));
    const dialog = await screen.findByRole("dialog");

    expect(within(dialog).getByText(ADMIN_SECTIONS_TEXTS.feedbackBlockModalState)).toBeInTheDocument();
    expect(within(dialog).getByText(ADMIN_SECTIONS_TEXTS.feedbackModalStateTruncated)).toBeInTheDocument();
    // Le JSON brut de l'état est lisible (texte pur dans un <pre>).
    expect(within(dialog).getByText(/"step":"equipes"/)).toBeInTheDocument();
  });
});
