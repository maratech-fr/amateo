import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { DeploymentNotice } from "./api";

// On mocke le hook (patron CreditsBanner.test) : le bandeau se décide depuis `data`.
// `data` indéfini = rien de programmé OU lecture en échec → le bandeau ne rend rien.
const notice = vi.hoisted(() => ({ current: null as DeploymentNotice | null }));
vi.mock("./queries", () => ({ useDeploymentNotice: () => ({ data: notice.current }) }));

import { DeploymentNoticeBanner } from "./DeploymentNoticeBanner";

const SCHEDULED: DeploymentNotice = {
  scheduledAt: "2026-12-01T02:00:00+00:00",
  expectedReturnAt: "2026-12-01T02:30:00+00:00",
};

// Mêmes options que celles imposées au composant : le test les calcule dans le MÊME fuseau
// que le composant (celui de l'hôte), donc les deux chaînes coïncident quel que soit le fuseau.
const startFmt = new Intl.DateTimeFormat("fr-FR", { dateStyle: "long", timeStyle: "short" });
const timeFmt = new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", minute: "2-digit" });

beforeEach(() => {
  sessionStorage.clear();
});
afterEach(() => {
  notice.current = null;
});

describe("DeploymentNoticeBanner", () => {
  it("ne rend RIEN quand aucune mise en production n'est programmée", () => {
    notice.current = null;
    const { container } = render(<DeploymentNoticeBanner />);
    expect(container).toBeEmptyDOMElement();
  });

  it("ne rend rien et ne plante pas quand la lecture échoue (data indéfini)", () => {
    notice.current = null; // le hook sert data: undefined en cas d'erreur réseau
    const { container } = render(<DeploymentNoticeBanner />);
    expect(container).toBeEmptyDOMElement();
  });

  it("annonce l'heure de début et l'heure de retour prévue (format FR, fuseau local)", () => {
    notice.current = SCHEDULED;
    render(<DeploymentNoticeBanner />);

    const banner = screen.getByRole("status");
    expect(banner).toHaveTextContent(startFmt.format(new Date(SCHEDULED.scheduledAt)));
    expect(banner).toHaveTextContent(timeFmt.format(new Date(SCHEDULED.expectedReturnAt)));
  });

  it("fermable : reste masqué pour la même programmation, REVIENT pour une nouvelle (clé liée à scheduledAt)", async () => {
    const user = userEvent.setup();
    notice.current = SCHEDULED;
    const { rerender } = render(<DeploymentNoticeBanner />);

    await user.click(screen.getByRole("button", { name: /masquer/i }));
    expect(screen.queryByRole("status")).toBeNull();

    // Même programmation (une navigation) → toujours masqué pour la session.
    rerender(<DeploymentNoticeBanner />);
    expect(screen.queryByRole("status")).toBeNull();

    // NOUVELLE programmation (scheduledAt différent) → le bandeau réapparaît.
    notice.current = { scheduledAt: "2026-12-05T20:00:00+00:00", expectedReturnAt: "2026-12-05T20:20:00+00:00" };
    rerender(<DeploymentNoticeBanner />);
    expect(screen.getByRole("status")).toBeInTheDocument();
  });
});
