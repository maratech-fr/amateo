import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { AdminDemosResponse } from "../api";
import { activateAdminDemo, deactivateAdminDemo, getAdminDemos, resetAdminDemoBccl, setAdminDemoClock } from "../api";
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
  };
});

const mockGet = vi.mocked(getAdminDemos);
const mockActivate = vi.mocked(activateAdminDemo);
const mockDeactivate = vi.mocked(deactivateAdminDemo);
const mockReset = vi.mocked(resetAdminDemoBccl);
const mockClock = vi.mocked(setAdminDemoClock);

function demos(overrides: Partial<AdminDemosResponse> = {}): AdminDemosResponse {
  return {
    // 20:00 UTC en juin = 22:00 à Paris (CEST), et dans le futur → fenêtre ouverte.
    bccl: { email: "demo-bccl@amateo.fr", activeUntil: "2099-06-15T20:00:00+00:00", clubName: "Démo Basket Club", simulatedToday: "2026-01-15" },
    prospect: { email: "demo@amateo.fr", activeUntil: null, clubName: null, simulatedToday: null },
    ...overrides,
  };
}

describe("DemosSection", () => {
  beforeEach(() => {
    mockGet.mockReset();
    mockActivate.mockReset().mockResolvedValue({ target: "prospect", activeUntil: "2099-06-15T20:00:00+00:00" });
    mockDeactivate.mockReset().mockResolvedValue({ target: "bccl", activeUntil: null });
    mockReset.mockReset().mockResolvedValue({ status: "reset" });
    mockClock.mockReset().mockResolvedValue({ simulatedToday: null });
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

  it("affiche l'indisponibilité quand la lecture échoue", async () => {
    mockGet.mockRejectedValue(new Error("down"));
    renderWithProviders(<DemosSection />);

    expect(await screen.findByText(/comptes de démonstration sont indisponibles/i)).toBeInTheDocument();
  });
});
