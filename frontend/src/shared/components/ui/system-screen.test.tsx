import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import { clearLastIncident, recordIncident } from "@/shared/api/lastIncidentStore";

import { SystemScreen } from "./system-screen";

/**
 * P5-14 — la primitive PRÉSENTATIONNELLE des écrans système. Elle porte la FORME
 * seulement (titre, corps, un geste principal + un secondaire, ligne « Code
 * incident » discrète). Chaque écran (404, 403, 500, hors-ligne) en est un
 * consommateur qui apporte sa copie : pas de prop `variant`, pas de `switch`.
 *
 * Contrainte dure : elle rend SANS aucun provider (utilisée sous l'ErrorBoundary
 * React, monté HORS providers). Les tests la rendent donc à nu, sans QueryClient
 * ni Router.
 */
describe("SystemScreen — primitive présentationnelle", () => {
  const noop = () => {};

  afterEach(() => {
    clearLastIncident();
  });

  it("rend à nu (aucun provider), avec titre, corps et geste principal", () => {
    render(
      <SystemScreen title="Arrêt de jeu imprévu." primaryAction={{ label: "Réessayer", onClick: noop }}>
        Une erreur inattendue s'est produite.
      </SystemScreen>,
    );
    expect(screen.getByRole("heading", { name: "Arrêt de jeu imprévu." })).toBeInTheDocument();
    expect(screen.getByText(/une erreur inattendue/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Réessayer" })).toBeInTheDocument();
  });

  it("le geste secondaire n'apparaît que s'il est fourni", () => {
    const { rerender } = render(
      <SystemScreen title="T" primaryAction={{ label: "Principal", onClick: noop }}>
        corps
      </SystemScreen>,
    );
    expect(screen.queryByRole("button", { name: "Secondaire" })).toBeNull();

    rerender(
      <SystemScreen title="T" primaryAction={{ label: "Principal", onClick: noop }} secondaryAction={{ label: "Secondaire", onClick: noop }}>
        corps
      </SystemScreen>,
    );
    expect(screen.getByRole("button", { name: "Secondaire" })).toBeInTheDocument();
  });

  it("chaque geste appelle son handler", async () => {
    const primary = vi.fn();
    const secondary = vi.fn();
    const { getByRole } = render(
      <SystemScreen title="T" primaryAction={{ label: "P", onClick: primary }} secondaryAction={{ label: "S", onClick: secondary }}>
        corps
      </SystemScreen>,
    );
    getByRole("button", { name: "P" }).click();
    getByRole("button", { name: "S" }).click();
    expect(primary).toHaveBeenCalledOnce();
    expect(secondary).toHaveBeenCalledOnce();
  });

  it("le titre (h1) prend le focus au montage — jamais un bouton", () => {
    render(
      <SystemScreen title="Focus ici" primaryAction={{ label: "Réessayer", onClick: noop }}>
        corps
      </SystemScreen>,
    );
    expect(screen.getByRole("heading", { name: "Focus ici" })).toHaveFocus();
  });

  it("la ligne « Code incident » n'apparaît QUE si un incidentId est fourni", () => {
    const { rerender } = render(
      <SystemScreen title="T" primaryAction={{ label: "P", onClick: noop }}>
        corps
      </SystemScreen>,
    );
    expect(screen.queryByText(/code incident/i)).toBeNull();

    rerender(
      <SystemScreen title="T" primaryAction={{ label: "P", onClick: noop }} incidentId="11111111-2222-4333-8444-555566667777">
        corps
      </SystemScreen>,
    );
    expect(screen.getByText(/code incident/i)).toHaveTextContent("11111111-2222-4333-8444-555566667777");
  });

  it("n'expose AUCUN détail interne : ni stack, ni exception, ni SQL — juste le code de corrélation", () => {
    render(
      <SystemScreen title="T" primaryAction={{ label: "P", onClick: noop }} incidentId="abcd">
        Message rassurant.
      </SystemScreen>,
    );
    // Le TEXTE lu par l'utilisateur (pas le HTML, qui porte des classes utilitaires) :
    // aucun marqueur de fuite technique (stacktrace, exception, SQL).
    const text = (document.body.textContent ?? "").toLowerCase();
    for (const leak of ["error:", "exception", "componentstack", "sqlstate", "select * from", "\n    at "]) {
      expect(text, `détail interne « ${leak} » exposé`).not.toContain(leak);
    }
  });

  // P4-129 — le bloc « Détails techniques (dev) » apparaît dans le pied en DEV dès
  // qu'un incident serveur frais existe (SystemScreen ne lui passe pas de props écran).
  it("affiche le bloc « Détails techniques (dev) » dans le pied en DEV, sur incident frais", () => {
    recordIncident({ status: 502, url: "/api/generate", requestId: "req-abc" });
    render(
      <SystemScreen title="Arrêt de jeu imprévu." primaryAction={{ label: "Réessayer", onClick: noop }}>
        Une erreur inattendue.
      </SystemScreen>,
    );
    expect(screen.getByText("Détails techniques (dev)")).toBeInTheDocument();
    expect(screen.getByText(/Dernier incident serveur/i)).toBeInTheDocument();
  });

  it("passe axe (structure, rôles, noms)", async () => {
    const { container } = render(
      <SystemScreen title="Arrêt de jeu imprévu." primaryAction={{ label: "Réessayer", onClick: noop }} secondaryAction={{ label: "Retour à l'accueil", onClick: noop }} incidentId="abcd-1234">
        Une erreur inattendue s'est produite de notre côté.
      </SystemScreen>,
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});
