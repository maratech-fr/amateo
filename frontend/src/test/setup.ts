import "@testing-library/jest-dom/vitest";

import { cleanup, configure } from "@testing-library/react";
import { afterEach, expect } from "vitest";
import * as axeMatchers from "vitest-axe/matchers";

// WCAG guardrail: register the `toHaveNoViolations` matcher (runtime). The type
// augmentation lives in vitest-axe.d.ts (vitest v3 types matchers via the
// `vitest` module, not vitest-axe's stale global `Vi.Assertion`).
expect.extend(axeMatchers);

// P4-116 (AUD-FRT-25) — **le plafond des attentes asynchrones, qui n'est PAS celui de Vitest.**
//
// `findBy*` et `waitFor` n'obéissent pas à `testTimeout` : ils ont leur propre budget,
// `asyncUtilTimeout`, dont le défaut est **1000 ms**. Il n'était pas configuré ici.
//
// ⚑ C'est ce qui a rendu le diagnostic de la ligne d'audit incomplet. Elle nommait le plafond
// Vitest de 5 s — vrai pour le cas le plus lourd (`PeriodStructure` › « déplacer un créneau
// réservé », 5,2 s de travail réel même sans charge). Mais les autres échecs mesurés sous
// contention tombaient à **1,3 s**, très loin de 5 s : ce n'étaient pas des timeouts de test,
// c'étaient des `findByRole` qui abandonnaient au bout d'une seconde et rapportaient « Unable
// to find an element » — un échec qui RESSEMBLE à une assertion fausse, et qu'on ne relie pas
// spontanément à la charge machine.
//
// 5 s laisse 4× la marge observée tout en restant bien SOUS le plafond de test (15 s) : un
// élément qui n'apparaît jamais échoue donc toujours ici, avec le message utile de
// testing-library (l'arbre DOM rendu), et non par un timeout de test qui ne dit rien.
configure({ asyncUtilTimeout: 5_000 });

// jsdom ships no ResizeObserver (used by the wizard's ScrollJumpButtons).
class ResizeObserverStub {
  observe() {}
  unobserve() {}
  disconnect() {}
}
globalThis.ResizeObserver ??= ResizeObserverStub as unknown as typeof ResizeObserver;

// jsdom ships no Range geometry: ProseMirror (éditeur de notes, `releaseNoteEditor/`) mesure la
// sélection pour la faire défiler à l'écran après une commande de liste — sans ces stubs, chaque
// geste de barre lève « getClientRects is not a function » avant de notifier l'éditeur. Valeurs
// nulles : jsdom n'a de toute façon aucun moteur de mise en page.
Range.prototype.getClientRects ??= () => ({ length: 0, item: () => null, [Symbol.iterator]: [][Symbol.iterator] }) as unknown as DOMRectList;
Range.prototype.getBoundingClientRect ??= () => ({ x: 0, y: 0, width: 0, height: 0, top: 0, left: 0, right: 0, bottom: 0, toJSON: () => ({}) }) as DOMRect;

afterEach(() => {
  cleanup();
});
