import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { Button } from "./button";

describe("Button", () => {
  it("renders its children", () => {
    render(<Button>Valider</Button>);
    expect(screen.getByRole("button", { name: "Valider" })).toBeInTheDocument();
  });

  it("fires onClick", async () => {
    const onClick = vi.fn();
    render(<Button onClick={onClick}>Go</Button>);
    await userEvent.click(screen.getByRole("button", { name: "Go" }));
    expect(onClick).toHaveBeenCalledOnce();
  });

  it("applies the accent background for the default variant", () => {
    render(<Button>x</Button>);
    expect(screen.getByRole("button")).toHaveClass("bg-accent");
  });
});

/**
 * P4-127 (e) — décision fondateur 2026-08-23 : `disabled:pointer-events-none` QUITTE la
 * primitive. Il rendait MORTES les infobulles d'explication des boutons désactivés — mesuré :
 * ~9 `title={lockTitle}` vivants dans le cockpit (« validez le socle d'abord ») qu'aucun survol
 * ne pouvait déclencher. Un bouton désactivé natif reste inerte au clic SANS pointer-events ;
 * ce que la classe supprimait, c'était uniquement l'information.
 *
 * Les DEUX contreparties, gardées ici :
 *  - le curseur dit l'état (`disabled:cursor-not-allowed` — possible seulement une fois
 *    pointer-events retiré) ;
 *  - les styles de survol sont bornés aux boutons ACTIFS (`hover:enabled:`) — sans quoi le
 *    survol d'un bouton mort afficherait l'affordance d'un vivant, un mensonge pire que
 *    l'infobulle morte qu'on répare.
 */
describe("Button désactivé — l'information vit, l'affordance meurt (P4-127 e)", () => {
  it("ne porte plus pointer-events-none, et dit son état au curseur", () => {
    render(<Button disabled>Adapter</Button>);
    const btn = screen.getByRole("button", { name: "Adapter" });

    expect(btn.className).not.toContain("pointer-events-none");
    expect(btn.className).toContain("disabled:cursor-not-allowed");
  });

  it("un clic sur bouton désactivé reste inerte — c'est le natif qui garde, pas la classe", async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    render(<Button disabled onClick={onClick}>Adapter</Button>);

    await user.click(screen.getByRole("button", { name: "Adapter" })).catch(() => undefined);
    expect(onClick).not.toHaveBeenCalled();
  });

  it("chaque style de survol est borné aux boutons ACTIFS (hover:enabled:)", () => {
    for (const variant of ["default", "outline", "ghost", "destructive"] as const) {
      const { unmount } = render(<Button variant={variant}>x</Button>);
      const btn = screen.getByRole("button", { name: "x" });
      const hovers = btn.className.split(" ").filter((c) => c.startsWith("hover:"));
      for (const c of hovers) {
        expect(c, `variant ${variant} : « ${c} » s'appliquerait au survol d'un bouton désactivé`).toContain("hover:enabled:");
      }
      unmount();
    }
  });
});
