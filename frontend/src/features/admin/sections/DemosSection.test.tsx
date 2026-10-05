import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { AdminDemosResponse } from "../api";
import { activateAdminDemo, deactivateAdminDemo, getAdminDemos, resetAdminDemoBccl, retainAdminDemoProspect, setAdminDemoClock } from "../api";
import { useAdminStore } from "../store";
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

    expect(await screen.findByText("Démo BCCL")).toBeInTheDocument();
    expect(screen.getByText("Démo prospect")).toBeInTheDocument();
    expect(screen.getByText("demo-bccl@amateo.fr")).toBeInTheDocument();
    expect(screen.getByText("Démo Basket Club")).toBeInTheDocument();

    // Fenêtre BCCL ouverte → rendue à l'heure de Paris (22:00) ; prospect fermé → Inactive.
    expect(screen.getByText(/22:00/)).toBeInTheDocument();
    expect(screen.getByText("Inactive")).toBeInTheDocument();

    // BCCL a « Réactiver » (ouvert) ; prospect a « Activer » (fermé).
    expect(screen.getByRole("button", { name: /Réactiver 4 h/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Activer 4 h" })).toBeInTheDocument();

    // L'horloge est désormais sur les DEUX cartes (capacité générique factorisée).
    expect((screen.getByLabelText(/Date simulée — Démo BCCL/) as HTMLInputElement).value).toBe("2026-01-15");
    expect((screen.getByLabelText(/Date simulée — Démo prospect/) as HTMLInputElement).value).toBe("");
  });

  it("active le compte prospect", async () => {
    mockGet.mockResolvedValue(demos());
    renderWithProviders(<DemosSection />);

    fireEvent.click(await screen.findByRole("button", { name: "Activer 4 h" }));
    await waitFor(() => expect(mockActivate).toHaveBeenCalledWith("prospect", "csrf-token"));
  });

  it("demande confirmation avant de réinitialiser la démo BCCL", async () => {
    mockGet.mockResolvedValue(demos());
    renderWithProviders(<DemosSection />);

    fireEvent.click(await screen.findByRole("button", { name: "Réinitialiser" }));
    // La confirmation nomme l'effet (données effacées + retour à aujourd'hui).
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(/sera effacé et la date simulée revient à aujourd/i)).toBeInTheDocument();

    fireEvent.click(within(dialog).getByRole("button", { name: "Réinitialiser" }));
    await waitFor(() => expect(mockReset).toHaveBeenCalledWith("csrf-token"));
  });

  it("montre « Réinitialisation en cours… » et bloque le bouton tant que le reset tourne", async () => {
    mockGet.mockResolvedValue(demos({ reset: { state: "running", at: "2026-10-05T10:00:00+00:00" } }));
    renderWithProviders(<DemosSection />);

    expect(await screen.findByText("Réinitialisation en cours…")).toBeInTheDocument();
    // Pendant le reset, le bouton porte aussi le Spinner (aria-label « Chargement ») : son nom
    // accessible contient « Réinitialiser » sans y être égal — d'où le match par regex.
    expect(screen.getByRole("button", { name: /Réinitialiser/ })).toBeDisabled();
  });

  it("applique puis efface la date simulée BCCL (cible bccl)", async () => {
    mockGet.mockResolvedValue(demos());
    renderWithProviders(<DemosSection />);

    const card = (await screen.findByText("Démo BCCL")).closest("article") as HTMLElement;
    const input = within(card).getByLabelText(/Date simulée — Démo BCCL/);
    fireEvent.change(input, { target: { value: "2026-03-03" } });
    fireEvent.click(within(card).getByRole("button", { name: "Appliquer" }));
    await waitFor(() => expect(mockClock).toHaveBeenCalledWith("bccl", { date: "2026-03-03" }, "csrf-token"));

    fireEvent.click(within(card).getByRole("button", { name: /Revenir à aujourd/ }));
    await waitFor(() => expect(mockClock).toHaveBeenCalledWith("bccl", { clear: true }, "csrf-token"));
  });

  it("applique la date simulée du compte prospect (cible prospect)", async () => {
    mockGet.mockResolvedValue(demos());
    renderWithProviders(<DemosSection />);

    const card = (await screen.findByText("Démo prospect")).closest("article") as HTMLElement;
    const input = within(card).getByLabelText(/Date simulée — Démo prospect/);
    fireEvent.change(input, { target: { value: "2026-05-05" } });
    fireEvent.click(within(card).getByRole("button", { name: "Appliquer" }));
    await waitFor(() => expect(mockClock).toHaveBeenCalledWith("prospect", { date: "2026-05-05" }, "csrf-token"));
  });

  it("conserve le club prospect 14 jours quand la fenêtre est fermée (avec confirmation)", async () => {
    mockGet.mockResolvedValue(demos());
    renderWithProviders(<DemosSection />);

    const card = (await screen.findByText("Démo prospect")).closest("article") as HTMLElement;
    const button = within(card).getByRole("button", { name: /Conserver 14 jours/ });
    expect(button).toBeEnabled();
    fireEvent.click(button);

    const dialog = await screen.findByRole("dialog");
    fireEvent.click(within(dialog).getByRole("button", { name: /Conserver 14 jours/ }));
    await waitFor(() => expect(mockRetain).toHaveBeenCalledWith("csrf-token"));
  });

  it("désactive « Conserver » tant que la fenêtre prospect est ouverte, avec explication", async () => {
    mockGet.mockResolvedValue(demos({ prospect: { email: "demo@amateo.fr", activeUntil: "2099-06-15T20:00:00+00:00", clubName: "Prospect FC", simulatedToday: null } }));
    renderWithProviders(<DemosSection />);

    const card = (await screen.findByText("Démo prospect")).closest("article") as HTMLElement;
    expect(within(card).getByRole("button", { name: /Conserver 14 jours/ })).toBeDisabled();
    expect(within(card).getByText(/Fermez d’abord l’accès de la démonstration/i)).toBeInTheDocument();
  });

  it("liste les clubs conservés avec leur échéance", async () => {
    mockGet.mockResolvedValue(demos({ retained: [{ name: "Club Conservé", retainedUntil: "2026-10-19" }] }));
    renderWithProviders(<DemosSection />);

    expect(await screen.findByText("Club Conservé")).toBeInTheDocument();
    expect(screen.getByText(/jusqu’au 19\/10\/2026/)).toBeInTheDocument();
  });

  it("affiche l'indisponibilité quand la lecture échoue", async () => {
    mockGet.mockRejectedValue(new Error("down"));
    renderWithProviders(<DemosSection />);

    expect(await screen.findByText(/comptes de démonstration sont indisponibles/i)).toBeInTheDocument();
  });
});
