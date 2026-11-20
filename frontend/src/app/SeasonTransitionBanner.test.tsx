import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { MeResponse, MeSeason } from "@/shared/session/api";
import { useTransitionUiStore } from "@/shared/stores/transitionUiStore";

import { SeasonTransitionBanner } from "./SeasonTransitionBanner";
import { seasonYearOf } from "./seasonTransition";

let meData: Partial<MeResponse> | undefined;

vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: meData }),
}));

const season = (overrides: Partial<MeSeason>): MeSeason => ({
  id: "sN",
  name: "2025-2026",
  startDate: "2025-08-01",
  endDate: "2026-07-15",
  isCurrent: true,
  isReadonly: false,
  ...overrides,
});

/** Local-time date (never the Date(iso) UTC parse) — mirrors the component's localIso. */
const day = (iso: string): Date => new Date(`${iso}T12:00:00`);

/**
 * Le serveur sert la date d'ouverture (`seasonPreparationOpensOn`) : le 1er mai qui suit le pivot
 * du 15 juillet de la saison du jour. Le mock l'envoie comme l'API, sauf si le test la fixe.
 */
function renderBanner(todayIso: string) {
  if (undefined !== meData && undefined === meData.seasonPreparationOpensOn) {
    meData = { ...meData, seasonPreparationOpensOn: `${seasonYearOf(todayIso) + 1}-05-01` };
  }
  return render(<SeasonTransitionBanner today={day(todayIso)} />);
}

describe("seasonYearOf", () => {
  it("bins by the July-15 pivot", () => {
    expect(seasonYearOf("2025-08-01")).toBe(2025);
    expect(seasonYearOf("2026-07-14")).toBe(2025);
    expect(seasonYearOf("2026-07-15")).toBe(2026);
  });
});

describe("SeasonTransitionBanner", () => {
  beforeEach(() => {
    meData = undefined;
    useTransitionUiStore.setState({ confirmOpen: false });
  });

  it("shows inside the window when no successor exists", () => {
    meData = { role: "admin", seasons: [season({})] };
    renderBanner("2026-05-20");
    expect(screen.getByRole("status")).toHaveTextContent("préparez la saison suivante");
  });

  // Le bandeau garde son seuil du 15 mai, tiré de la date servie (même mois, jour 15) : du 1er
  // au 14 mai, le menu propose déjà de préparer, le bandeau ne relance pas encore.
  it.each(["2026-05-01", "2026-05-07", "2026-05-14"])("hides from the served opening date until May 14 (%s)", (today) => {
    meData = { role: "admin", seasons: [season({})], seasonPreparationOpensOn: "2026-05-01" };
    renderBanner(today);
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("shows from May 15 of the served opening month", () => {
    meData = { role: "admin", seasons: [season({})], seasonPreparationOpensOn: "2026-05-01" };
    renderBanner("2026-05-15");
    expect(screen.getByRole("status")).toBeInTheDocument();
  });

  // Le seuil se tire de la date SERVIE, jamais d'un « 05-15 » écrit à l'écran.
  it("takes its threshold from the served date (served 2026-06-01 → from 2026-06-15)", () => {
    meData = { role: "admin", seasons: [season({})], seasonPreparationOpensOn: "2026-06-01" };
    const { unmount } = renderBanner("2026-06-05");
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
    unmount();
    renderBanner("2026-06-15");
    expect(screen.getByRole("status")).toBeInTheDocument();
  });

  it("hides on the pivot day (July 15) and after", () => {
    meData = { role: "admin", seasons: [season({})] };
    renderBanner("2026-07-15");
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("hides when the next season is already prepared", () => {
    meData = { role: "admin", seasons: [season({}), season({ id: "sD", name: "2026-2027", startDate: "2026-08-01", isCurrent: false })] };
    renderBanner("2026-06-20");
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("hides for non-management members (the endpoint would 403 their CTA)", () => {
    meData = { role: "member", seasons: [season({})] };
    renderBanner("2026-06-20");
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  // PR D (retour fondateur 2026-07-19) : borne haute = FIN RÉELLE de la saison, pas
  // le pivot fixe du 15 juillet. Une saison qui finit tôt masque la bannière dès sa fin.
  it("hides once past the season's actual endDate, even before the July-15 pivot", () => {
    meData = { role: "admin", seasons: [season({ endDate: "2026-06-30" })] };
    renderBanner("2026-07-05"); // après le 30 juin, avant le 15 juil
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("still shows within the window up to the real endDate, with the real deadline in the copy", () => {
    meData = { role: "admin", seasons: [season({ endDate: "2026-06-30" })] };
    renderBanner("2026-06-25");
    // Revue D F2 : la deadline affichée est la fin RÉELLE (30 juin), pas le 15 juillet.
    expect(screen.getByRole("status")).toHaveTextContent("avant le 30 juin");
    expect(screen.queryByText(/15 juillet/)).not.toBeInTheDocument();
  });

  it("nudges a dormant club before EVERY upcoming pivot (anchored on today)", () => {
    // Current season is 2025-2026, never transitioned; in June 2027 the window
    // of the NEXT pivot (2027-07-15) must still show the banner.
    meData = { role: "admin", seasons: [season({})] };
    renderBanner("2027-06-20");
    expect(screen.getByRole("status")).toBeInTheDocument();
  });

  it("CTA opens the shared transition confirm", async () => {
    meData = { role: "admin", seasons: [season({})] };
    const user = userEvent.setup();
    renderBanner("2026-06-20");

    await user.click(screen.getByRole("button", { name: "Préparer la saison suivante" }));
    expect(useTransitionUiStore.getState().confirmOpen).toBe(true);
  });
});
