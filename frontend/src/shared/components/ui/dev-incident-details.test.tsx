import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { clearLastIncident, recordIncident } from "@/shared/api/lastIncidentStore";

import { DevIncidentDetails } from "./dev-incident-details";

/**
 * P4-129 — le bloc « Détails techniques (dev) » : REPLIABLE, VISIBLE EN DEV UNIQUEMENT.
 * `import.meta.env.DEV` est lu AU RENDU — le test central prouve que le composant rend
 * `null` sous `vi.stubEnv("DEV", false)` MÊME avec un incident frais (absence en prod).
 */
describe("DevIncidentDetails", () => {
  afterEach(() => {
    clearLastIncident();
    vi.unstubAllEnvs();
  });

  it("rend le bloc en DEV avec un incident serveur frais (groupe « Dernier incident »)", () => {
    recordIncident({ status: 502, url: "/api/generate", requestId: "req-abc" });
    render(<DevIncidentDetails />);

    expect(screen.getByText("Détails techniques (dev)")).toBeInTheDocument();
    expect(screen.getByText(/Dernier incident serveur/i)).toBeInTheDocument();
    expect(screen.getByText("502")).toBeInTheDocument();
    expect(screen.getByText("/api/generate")).toBeInTheDocument();
    expect(screen.getByText("req-abc")).toBeInTheDocument();
  });

  // ⚠ Test CENTRAL de la fiche : la garde prod. Un incident frais est présent, et pourtant
  // sous DEV=false le composant ne rend RIEN — le bloc est absent du bundle de production.
  it("rend NULL sous vi.stubEnv('DEV', false), MÊME avec un incident frais", () => {
    recordIncident({ status: 502, url: "/api/generate", requestId: "req-abc" });
    vi.stubEnv("DEV", false);

    const { container } = render(<DevIncidentDetails />);
    expect(container).toBeEmptyDOMElement();
  });

  it("ne rend RIEN sans incident frais NI props écran", () => {
    const { container } = render(<DevIncidentDetails />);
    expect(container).toBeEmptyDOMElement();
  });

  it("rend le groupe « Cet écran » à partir des props, même sans incident serveur", () => {
    render(<DevIncidentDetails screenStatus={500} screenUrl="/api/teams" screenCode="boom" scheduleId="run-42" />);

    expect(screen.getByText("Cet écran")).toBeInTheDocument();
    expect(screen.getByText("500")).toBeInTheDocument();
    expect(screen.getByText("/api/teams")).toBeInTheDocument();
    expect(screen.getByText("boom")).toBeInTheDocument();
    expect(screen.getByText("run-42")).toBeInTheDocument();
    // Pas d'incident store → pas de second groupe.
    expect(screen.queryByText(/Dernier incident serveur/i)).not.toBeInTheDocument();
  });

  it("étiquette les DEUX groupes quand écran ET store apportent chacun de quoi", () => {
    recordIncident({ status: 503, url: "/api/generate", requestId: "req-xyz" });
    render(<DevIncidentDetails screenStatus={500} screenUrl="/api/teams" />);

    expect(screen.getByText("Cet écran")).toBeInTheDocument();
    expect(screen.getByText(/Dernier incident serveur \(peut être sans lien avec cet écran\)/i)).toBeInTheDocument();
  });

  it("est REPLIÉ par défaut (aucun `open`)", () => {
    recordIncident({ status: 502, url: "/api/generate" });
    const { container } = render(<DevIncidentDetails />);

    const details = container.querySelector("details");
    expect(details).not.toBeNull();
    expect(details).not.toHaveAttribute("open");
  });

  it("ne pose aucune live region (ni status, ni alert)", () => {
    recordIncident({ status: 502, url: "/api/generate" });
    render(<DevIncidentDetails />);

    expect(screen.queryByRole("status")).not.toBeInTheDocument();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });
});
