import { fireEvent, render, screen } from "@testing-library/react";
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
// (ils tirent chacun react-query / des stores testés ailleurs). Les deux PASTILLES sont réduites à
// un marqueur repérable pour vérifier LEUR PLACEMENT (grappe de marque, hors de la nav de droite).
vi.mock("@/shared/credits/CreditBadge", () => ({ CreditBadge: () => <span data-testid="credit-badge" /> }));
vi.mock("./BetaBadge", () => ({ BetaBadge: ({ onReport }: { onReport: () => void }) => <button data-testid="beta-badge" onClick={onReport} type="button" /> }));
vi.mock("@/shared/credits/CreditsBanner", () => ({ CreditsBanner: () => null }));
vi.mock("./SeasonSelector", () => ({ SeasonSelector: () => null }));
vi.mock("./ReadonlySeasonBanner", () => ({ ReadonlySeasonBanner: () => null }));
vi.mock("./SeasonTransitionBanner", () => ({ SeasonTransitionBanner: () => null }));
vi.mock("./DevClock", () => ({ DevClock: () => null }));
vi.mock("@/features/release-notes/WhatsNewModal", () => ({ WhatsNewModal: () => null }));
vi.mock("@/shared/feedback/FeedbackDialog", () => ({ FeedbackDialog: () => <div data-testid="feedback-dialog" /> }));

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

  it("place les deux pastilles dans la grappe de marque, APRÈS le lien d'accueil, hors de la nav", () => {
    meData = { club: club({ name: "BCCL", logoUrl: null }) };
    const { container } = renderLayout();
    const nav = container.querySelector("header nav") as HTMLElement;
    const beta = screen.getByTestId("beta-badge");
    const credit = screen.getByTestId("credit-badge");
    // Ni l'une ni l'autre dans la nav de droite (elles ont quitté ~L79).
    expect(nav.contains(beta)).toBe(false);
    expect(nav.contains(credit)).toBe(false);
    // Toutes deux dans la grappe de marque (le parent du lien d'accueil), APRÈS ce lien.
    const home = screen.getByRole("link", { name: "BCCL" });
    const cluster = home.parentElement as HTMLElement;
    expect(cluster.contains(beta)).toBe(true);
    expect(cluster.contains(credit)).toBe(true);
    expect(home.compareDocumentPosition(beta) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(home.compareDocumentPosition(credit) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it("« BÊTA » → onReport ouvre le canal de signalement (FeedbackDialog)", () => {
    meData = { club: club({ name: "BCCL", logoUrl: null }) };
    renderLayout();
    expect(screen.queryByTestId("feedback-dialog")).toBeNull();
    fireEvent.click(screen.getByTestId("beta-badge"));
    expect(screen.getByTestId("feedback-dialog")).toBeInTheDocument();
  });

  // Régression CI (#1022, width-calibration.spec 360 px) : les pastilles `shrink-0` ajoutées dans
  // la grappe écrasaient le lien d'accueil `min-w-0` à largeur NULLE → lien HIDDEN à 360 px. Le lien
  // doit garder un PLANCHER (l'icône, `shrink-0`) : il ne porte donc PAS `min-w-0` (qui supprime son
  // plancher de contenu), et c'est le SPAN du nom qui porte `min-w-0` (troncature au bureau sans
  // écraser l'icône) tout en se masquant sous `sm`. jsdom n'a pas de layout : on garde le CONTRAT de
  // classes, la mesure pixel à 360 px est faite en vrai navigateur (harnais).
  it("lien d'accueil : plancher icône (pas de min-w-0 sur le lien), nom en min-w-0 masqué sous sm", () => {
    meData = { club: club({ name: "B CHARPENNES CROIX LUIZET", logoUrl: null }) };
    const { container } = renderLayout();
    const home = container.querySelector("header a[href='/']") as HTMLElement;
    // Le lien ne s'écrase plus : pas de `min-w-0` sur le lien lui-même.
    expect(home.className).not.toContain("min-w-0");
    // L'icône produit est le plancher visible (shrink-0), toujours présente.
    expect(brandIcon(container)?.getAttribute("class") ?? "").toContain("shrink-0");
    // Le nom : masqué sous sm (`hidden sm:inline`) ET `min-w-0` pour tronquer au bureau.
    const span = home.querySelector("span") as HTMLElement;
    expect(span.className).toContain("hidden");
    expect(span.className).toContain("sm:inline");
    expect(span.className).toContain("min-w-0");
  });
});
