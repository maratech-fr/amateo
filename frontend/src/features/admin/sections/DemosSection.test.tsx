import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { AdminDemosResponse } from "../api";
import { activateAdminDemo, deactivateAdminDemo, getAdminDemos, resetAdminDemoBccl, retainAdminDemoProspect, setAdminDemoClock } from "../api";
import { useAdminStore } from "../store";
import { ADMIN_SECTIONS_TEXTS } from "../texts/sections";
import { DemosSection } from "./DemosSection";

vi.mock("../api", async (importOriginal) => {
  const original = await importOriginal<typeof import("../api")>();
  return {
    ...original,
    getAdminDemos: vi.fn(),
    activateAdminDemo: vi.fn(),
    deactivateAdminDemo: vi.fn(),
    resetAdminDemoBccl: vi.fn(),
    setAdminDemoClock: vi.fn(),
    retainAdminDemoProspect: vi.fn(),
  };
});

const mockGet = vi.mocked(getAdminDemos);
const mockActivate = vi.mocked(activateAdminDemo);
const mockDeactivate = vi.mocked(deactivateAdminDemo);
const mockReset = vi.mocked(resetAdminDemoBccl);
const mockClock = vi.mocked(setAdminDemoClock);
const mockRetain = vi.mocked(retainAdminDemoProspect);

function demos(overrides: Partial<AdminDemosResponse> = {}): AdminDemosResponse {
  return {
    // 20:00 UTC en juin = 22:00 à Paris (CEST), et dans le futur → fenêtre ouverte.
    bccl: { email: "demo-bccl@amateo.fr", activeUntil: "2099-06-15T20:00:00+00:00", clubName: "Démo Basket Club", simulatedToday: "2026-01-15" },
    prospect: { email: "demo@amateo.fr", activeUntil: null, clubName: null, simulatedToday: null },
    retained: [],
    reset: null,
    // PR1 console — date de la DERNIÈRE réinitialisation BCCL (ISO 8601) lue du journal
    // d'audit (202 + demoAction=reset) ; null quand aucune n'a jamais eu lieu.
    lastResetAt: null,
    ...overrides,
  };
}

describe("DemosSection", () => {
  beforeEach(() => {
    mockGet.mockReset();
    mockActivate.mockReset().mockResolvedValue({ target: "prospect", activeUntil: "2099-06-15T20:00:00+00:00" });
    mockDeactivate.mockReset().mockResolvedValue({ target: "bccl", activeUntil: null });
    mockReset.mockReset().mockResolvedValue({ status: "accepted" });
    mockClock.mockReset().mockResolvedValue({ simulatedToday: null });
    mockRetain.mockReset().mockResolvedValue({ retainedUntil: "2026-10-19" });
    useAdminStore.getState().setSession({ id: "sa", email: "sa@x" }, "csrf-token");
  });

  it("rend les deux comptes, la fenêtre à l'heure de Paris et l'horloge des DEUX cartes", async () => {
    mockGet.mockResolvedValue(demos());
    renderWithProviders(<DemosSection />);

    expect(await screen.findByText(ADMIN_SECTIONS_TEXTS.demosBcclTitle)).toBeInTheDocument();
    expect(screen.getByText(ADMIN_SECTIONS_TEXTS.demosProspectTitle)).toBeInTheDocument();
    expect(screen.getByText("demo-bccl@amateo.fr")).toBeInTheDocument();
    expect(screen.getByText("Démo Basket Club")).toBeInTheDocument();

    // Fenêtre BCCL ouverte → rendue à l'heure de Paris (22:00) ; prospect fermé → Inactive.
    expect(screen.getByText(/22:00/)).toBeInTheDocument();
    expect(screen.getByText(ADMIN_SECTIONS_TEXTS.demosInactive)).toBeInTheDocument();

    // BCCL a « Réactiver » (ouvert) ; prospect a « Activer » (fermé).
    expect(screen.getByRole("button", { name: ADMIN_SECTIONS_TEXTS.demosReactivate })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: ADMIN_SECTIONS_TEXTS.demosActivate })).toBeInTheDocument();

    // L'horloge est désormais sur les DEUX cartes (capacité générique factorisée).
    expect((screen.getByLabelText(ADMIN_SECTIONS_TEXTS.demosClockLabel(ADMIN_SECTIONS_TEXTS.demosBcclTitle)) as HTMLInputElement).value).toBe("2026-01-15");
    expect((screen.getByLabelText(ADMIN_SECTIONS_TEXTS.demosClockLabel(ADMIN_SECTIONS_TEXTS.demosProspectTitle)) as HTMLInputElement).value).toBe("");
  });

  it("active le compte prospect", async () => {
    mockGet.mockResolvedValue(demos());
    renderWithProviders(<DemosSection />);

    fireEvent.click(await screen.findByRole("button", { name: ADMIN_SECTIONS_TEXTS.demosActivate }));
    await waitFor(() => expect(mockActivate).toHaveBeenCalledWith("prospect", "csrf-token"));
  });

  it("demande confirmation avant de réinitialiser la démo BCCL", async () => {
    mockGet.mockResolvedValue(demos());
    renderWithProviders(<DemosSection />);

    fireEvent.click(await screen.findByRole("button", { name: ADMIN_SECTIONS_TEXTS.demosReset }));
    // La confirmation nomme l'effet (données effacées + retour à aujourd'hui).
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(ADMIN_SECTIONS_TEXTS.demosResetConfirmDescription)).toBeInTheDocument();

    fireEvent.click(within(dialog).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.demosReset }));
    await waitFor(() => expect(mockReset).toHaveBeenCalledWith("csrf-token"));
  });

  it("montre « Réinitialisation en cours… » et bloque le bouton tant que le reset tourne", async () => {
    mockGet.mockResolvedValue(demos({ reset: { state: "running", at: "2026-10-05T10:00:00+00:00" } }));
    renderWithProviders(<DemosSection />);

    expect(await screen.findByText(ADMIN_SECTIONS_TEXTS.demosResetRunning)).toBeInTheDocument();
    // Pendant le reset, le bouton porte aussi le Spinner (aria-label « Chargement ») : son nom
    // accessible contient « Réinitialiser » sans y être égal — d'où le match par regex.
    expect(screen.getByRole("button", { name: /Réinitialiser/ })).toBeDisabled();
  });

  it("applique puis efface la date simulée BCCL (cible bccl)", async () => {
    mockGet.mockResolvedValue(demos());
    renderWithProviders(<DemosSection />);

    const card = (await screen.findByText(ADMIN_SECTIONS_TEXTS.demosBcclTitle)).closest("article") as HTMLElement;
    const input = within(card).getByLabelText(ADMIN_SECTIONS_TEXTS.demosClockLabel(ADMIN_SECTIONS_TEXTS.demosBcclTitle));
    fireEvent.change(input, { target: { value: "2026-03-03" } });
    fireEvent.click(within(card).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.demosApply }));
    await waitFor(() => expect(mockClock).toHaveBeenCalledWith("bccl", { date: "2026-03-03" }, "csrf-token"));

    fireEvent.click(within(card).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.demosClockReset }));
    await waitFor(() => expect(mockClock).toHaveBeenCalledWith("bccl", { clear: true }, "csrf-token"));
  });

  it("applique la date simulée du compte prospect (cible prospect)", async () => {
    mockGet.mockResolvedValue(demos());
    renderWithProviders(<DemosSection />);

    const card = (await screen.findByText(ADMIN_SECTIONS_TEXTS.demosProspectTitle)).closest("article") as HTMLElement;
    const input = within(card).getByLabelText(ADMIN_SECTIONS_TEXTS.demosClockLabel(ADMIN_SECTIONS_TEXTS.demosProspectTitle));
    fireEvent.change(input, { target: { value: "2026-05-05" } });
    fireEvent.click(within(card).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.demosApply }));
    await waitFor(() => expect(mockClock).toHaveBeenCalledWith("prospect", { date: "2026-05-05" }, "csrf-token"));
  });

  it("conserve le club prospect 14 jours quand la fenêtre est fermée (avec confirmation)", async () => {
    mockGet.mockResolvedValue(demos());
    renderWithProviders(<DemosSection />);

    const card = (await screen.findByText(ADMIN_SECTIONS_TEXTS.demosProspectTitle)).closest("article") as HTMLElement;
    const button = within(card).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.demosRetain });
    expect(button).toBeEnabled();
    fireEvent.click(button);

    const dialog = await screen.findByRole("dialog");
    fireEvent.click(within(dialog).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.demosRetain }));
    await waitFor(() => expect(mockRetain).toHaveBeenCalledWith("csrf-token"));
  });

  it("désactive « Conserver » tant que la fenêtre prospect est ouverte, avec explication", async () => {
    mockGet.mockResolvedValue(demos({ prospect: { email: "demo@amateo.fr", activeUntil: "2099-06-15T20:00:00+00:00", clubName: "Prospect FC", simulatedToday: null } }));
    renderWithProviders(<DemosSection />);

    const card = (await screen.findByText(ADMIN_SECTIONS_TEXTS.demosProspectTitle)).closest("article") as HTMLElement;
    expect(within(card).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.demosRetain })).toBeDisabled();
    expect(within(card).getByText(ADMIN_SECTIONS_TEXTS.demosRetainBlocked)).toBeInTheDocument();
  });

  it("liste les clubs conservés avec leur échéance", async () => {
    mockGet.mockResolvedValue(demos({ retained: [{ name: "Club Conservé", retainedUntil: "2026-10-19" }] }));
    renderWithProviders(<DemosSection />);

    expect(await screen.findByText("Club Conservé")).toBeInTheDocument();
    expect(screen.getByText(ADMIN_SECTIONS_TEXTS.demosRetainedUntil("19/10/2026"))).toBeInTheDocument();
  });

  it("affiche la date de la dernière réinitialisation BCCL quand le journal en porte une", async () => {
    mockGet.mockResolvedValue(demos({ lastResetAt: "2026-10-05T08:30:00+00:00" }));
    renderWithProviders(<DemosSection />);

    const card = (await screen.findByText(ADMIN_SECTIONS_TEXTS.demosBcclTitle)).closest("article") as HTMLElement;
    expect(within(card).getByText(/Dernière réinitialisation lancée le/i)).toBeInTheDocument();
    expect(within(card).queryByText(ADMIN_SECTIONS_TEXTS.demosNeverReset)).not.toBeInTheDocument();
  });

  it("indique « Jamais réinitialisée » quand le journal n'en porte aucune", async () => {
    mockGet.mockResolvedValue(demos({ lastResetAt: null }));
    renderWithProviders(<DemosSection />);

    const card = (await screen.findByText(ADMIN_SECTIONS_TEXTS.demosBcclTitle)).closest("article") as HTMLElement;
    expect(within(card).getByText(ADMIN_SECTIONS_TEXTS.demosNeverReset)).toBeInTheDocument();
  });

  it("affiche l'indisponibilité quand la lecture échoue", async () => {
    mockGet.mockRejectedValue(new Error("down"));
    renderWithProviders(<DemosSection />);

    expect(await screen.findByText(ADMIN_SECTIONS_TEXTS.demosError)).toBeInTheDocument();
  });
});
