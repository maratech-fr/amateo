import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { MeResponse } from "@/shared/session/api";
import { PRODUCT_NAME } from "@/shared/lib/product";
import { AppLayout } from "./AppLayout";

let meData: Partial<MeResponse> | undefined;

vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: meData }),
}));
vi.mock("@/features/auth/queries", () => ({
  useLogout: () => vi.fn(),
}));
vi.mock("@/shared/hooks/useApplyClubTheme", () => ({ useApplyClubTheme: () => {} }));
vi.mock("@/shared/hooks/useApplyDemoClock", () => ({ useApplyDemoClock: () => {} }));
// `useNavigation` exige un data-router ; ici on n'exerce que l'en-tête, on le fige à `idle`.
vi.mock("react-router", async (importOriginal) => {
  const actual = await importOriginal<typeof import("react-router")>();
  return { ...actual, useNavigation: () => ({ state: "idle" }) };
});

// Enfants hors sujet de la barre de marque — réduits à un marqueur inerte pour isoler l'en-tête
// (ils tirent chacun react-query / des stores testés ailleurs).
vi.mock("@/shared/credits/CreditBadge", () => ({ CreditBadge: () => null }));
vi.mock("@/shared/credits/CreditsBanner", () => ({ CreditsBanner: () => null }));
vi.mock("./SeasonSelector", () => ({ SeasonSelector: () => null }));
vi.mock("./ReadonlySeasonBanner", () => ({ ReadonlySeasonBanner: () => null }));
vi.mock("./SeasonTransitionBanner", () => ({ SeasonTransitionBanner: () => null }));
vi.mock("./DevClock", () => ({ DevClock: () => null }));
vi.mock("@/features/release-notes/WhatsNewModal", () => ({ WhatsNewModal: () => null }));
vi.mock("@/features/feedback/FeedbackDialog", () => ({ FeedbackDialog: () => null }));

const club = (overrides: Record<string, unknown>): MeResponse["club"] =>
  ({ id: "c1", name: "BCCL", onboardingCompleted: true, logoUrl: null, ...overrides }) as unknown as MeResponse["club"];

function renderLayout() {
  return render(
    <MemoryRouter>
      <AppLayout />
    </MemoryRouter>,
  );
}

// L'icône produit = le SEUL svg au viewBox de la marque (les icônes lucide sont en 0 0 24 24).
const brandIcon = (container: HTMLElement) => container.querySelector('svg[viewBox="0 0 1000 1000"]');

describe("AppLayout — en-tête marque produit", () => {
  beforeEach(() => {
    meData = undefined;
  });

  it("rend TOUJOURS l'icône produit, même sans club chargé", () => {
    meData = {};
    const { container } = renderLayout();
    expect(brandIcon(container)).not.toBeNull();
  });

  it("sans club chargé : icône produit + nom produit", () => {
    meData = {};
    const { container } = renderLayout();
    expect(brandIcon(container)).not.toBeNull();
    expect(screen.getByText(PRODUCT_NAME)).toBeInTheDocument();
  });

  it("affiche le blason du club à côté de l'icône produit quand il existe", () => {
    meData = { club: club({ name: "BCCL", logoUrl: "https://cdn.example/logo.png" }) };
    const { container } = renderLayout();
    const blason = container.querySelector('header img[src="https://cdn.example/logo.png"]');
    expect(blason).not.toBeNull();
    expect(blason?.getAttribute("alt")).toBe(""); // décoratif — le nom du club est écrit à côté
    // L'icône produit reste présente à côté du blason (marque produit, PUIS club).
    expect(brandIcon(container)).not.toBeNull();
    expect(screen.getByText("BCCL")).toBeInTheDocument();
  });

  it("sans blason : aucune image de club, l'icône produit suffit", () => {
    meData = { club: club({ name: "BCCL", logoUrl: null }) };
    const { container } = renderLayout();
    expect(container.querySelector("header img")).toBeNull();
    expect(brandIcon(container)).not.toBeNull();
  });

  it("n'utilise plus l'icône de repli CalendarCheck2", () => {
    meData = { club: club({ name: "BCCL", logoUrl: null }) };
    const { container } = renderLayout();
    expect(container.querySelector('[class*="calendar-check"]')).toBeNull();
  });
});
