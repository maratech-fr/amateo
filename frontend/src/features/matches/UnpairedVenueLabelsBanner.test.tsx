import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { VenueLabelInventoryRow } from "./api";
import { HiddenHomesWeekNotice, PAIR_VENUES_PATH, UnpairedVenueLabelsBanner } from "./UnpairedVenueLabelsBanner";

// L'inventaire est le seul double du bandeau ; `useNavigate` est capturé pour prouver le renvoi.
const h = vi.hoisted(() => ({ inventory: undefined as VenueLabelInventoryRow[] | undefined, navigate: vi.fn() }));
vi.mock("./queries", () => ({ useVenueLabelInventory: () => ({ data: h.inventory }) }));
vi.mock("react-router", async (orig) => ({ ...(await orig<typeof import("react-router")>()), useNavigate: () => h.navigate }));

const row = (over: Partial<VenueLabelInventoryRow> = {}): VenueLabelInventoryRow => ({
  labelKey: "gymnase mateo",
  displayLabel: "GYMNASE MATEO",
  venueId: null,
  suggestedVenueId: null,
  homeCount: 3,
  placedCount: 1,
  unplacedCount: 2,
  ...over,
});

beforeEach(() => {
  h.inventory = undefined;
  h.navigate.mockClear();
});

describe("UnpairedVenueLabelsBanner (E2, P4-205)", () => {
  it("inventaire undefined (chargement/échec) ⇒ rendu NUL (jamais un faux calme)", () => {
    h.inventory = undefined;
    const { container } = render(<UnpairedVenueLabelsBanner />);
    expect(container).toBeEmptyDOMElement();
  });

  it("0 libellé non apparié (tout confirmé) ⇒ rendu NUL", () => {
    h.inventory = [row({ labelKey: "a", venueId: "v1" }), row({ labelKey: "b", venueId: "v2" })];
    const { container } = render(<UnpairedVenueLabelsBanner />);
    expect(container).toBeEmptyDOMElement();
  });

  it("≥ 1 non apparié ⇒ N libellés + M domiciles (somme des non placés), role=status", () => {
    h.inventory = [row({ labelKey: "a", venueId: null, unplacedCount: 2 }), row({ labelKey: "b", venueId: null, unplacedCount: 3 }), row({ labelKey: "c", venueId: "v3", unplacedCount: 9 })];
    render(<UnpairedVenueLabelsBanner />);
    const banner = screen.getByRole("status");
    expect(banner).toHaveTextContent(/2 libellés de salle non appariés/);
    expect(banner).toHaveTextContent(/5 domiciles n'apparaissent pas sur la grille/);
  });

  it("le bouton « Apparier les salles » renvoie vers l'écran d'appariement (deep-link)", async () => {
    const user = userEvent.setup();
    h.inventory = [row({ venueId: null })];
    render(<UnpairedVenueLabelsBanner />);
    await user.click(screen.getByRole("button", { name: /Apparier les salles/ }));
    expect(h.navigate).toHaveBeenCalledWith(PAIR_VENUES_PATH);
  });
});

describe("HiddenHomesWeekNotice (E2, P4-205)", () => {
  it("count = 0 ⇒ rendu NUL", () => {
    const { container } = render(<HiddenHomesWeekNotice count={0} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("count > 0 ⇒ « N domiciles de ce week-end sans gymnase, non affichés » + renvoi", async () => {
    const user = userEvent.setup();
    render(<HiddenHomesWeekNotice count={3} />);
    expect(screen.getByText(/3 domiciles de ce week-end sans gymnase, non affichés/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /Apparier les salles/ }));
    expect(h.navigate).toHaveBeenCalledWith(PAIR_VENUES_PATH);
  });

  it("count = 1 ⇒ singulier", () => {
    render(<HiddenHomesWeekNotice count={1} />);
    expect(screen.getByText(/1 domicile de ce week-end sans gymnase, non affiché\./)).toBeInTheDocument();
  });
});
