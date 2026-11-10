import { render } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { frDateSlashed } from "@/shared/lib/date";

import { ReleaseNoteHeading } from "./ReleaseNoteHeading";
import { RELEASE_NOTES_TEXTS } from "./texts";

/**
 * Promesse : **chaque note s'annonce par UNE ligne « date - version »** (« 13/10/2026 - v1.3.5 »,
 * ou la date seule quand la note n'a pas de version), la même dans la modale, sur la page et dans
 * la console — et la console la rend dans SA peau (jamais un jeton adaptatif de l'app, jamais
 * `console-muted`, sous 4,5:1 pour du texte).
 *
 * Le format exact (« jj/mm/aaaa - vX ») est témoigné en littéral par `tests/e2e/release-notes.spec.ts` ;
 * ici on vérifie la CLÉ `RELEASE_NOTES_TEXTS.heading`, jamais la phrase recopiée.
 */
describe("ReleaseNoteHeading — l'en-tête date-version d'une note", () => {
  it("rend la date jj/mm/aaaa suivie de la version, dans un paragraphe", () => {
    const { container } = render(<ReleaseNoteHeading date="2026-10-13" version="1.3.5" />);
    const node = container.firstElementChild;

    expect(node?.tagName).toBe("P");
    expect(node).toHaveTextContent(RELEASE_NOTES_TEXTS.heading(frDateSlashed("2026-10-13"), "1.3.5"));
  });

  it("rend la date seule quand la note n'a pas de version", () => {
    const { container } = render(<ReleaseNoteHeading date="2026-10-13" version={null} />);
    const node = container.firstElementChild;

    expect(node?.textContent).toBe(RELEASE_NOTES_TEXTS.heading(frDateSlashed("2026-10-13"), null));
  });

  it("porte la peau app par défaut (texte discret de l'app)", () => {
    const { container } = render(<ReleaseNoteHeading date="2026-10-13" version="1.3.5" />);
    const classes = (container.firstElementChild?.className ?? "").split(/\s+/);

    expect(classes).toContain("text-xs");
    expect(classes).toContain("text-muted-foreground");
  });

  it("porte la peau console en variante console, sans aucun jeton de l'app ni console-muted", () => {
    const { container } = render(<ReleaseNoteHeading date="2026-10-13" version="1.3.5" variant="console" />);
    const classes = (container.firstElementChild?.className ?? "").split(/\s+/);

    expect(classes).toContain("text-xs");
    expect(classes).toContain("text-console-text-dim");
    expect(classes).not.toContain("text-muted-foreground");
    expect(classes).not.toContain("text-console-muted");
  });
});
