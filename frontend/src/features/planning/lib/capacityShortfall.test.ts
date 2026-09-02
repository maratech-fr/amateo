import { describe, expect, it } from "vitest";

import { capacityShortfallSentence } from "./capacityShortfall";

describe("capacityShortfallSentence", () => {
  it("nomme le manque quand la demande dépasse l'offre", () => {
    expect(capacityShortfallSentence(42, 30)).toBe("42 séances demandées pour 30 places disponibles — il manque 12 places.");
  });

  it("reste une phrase neutre, sans manque, quand l'offre suffit", () => {
    expect(capacityShortfallSentence(10, 10)).toBe("10 séances demandées pour 10 places disponibles.");
    expect(capacityShortfallSentence(3, 8)).toBe("3 séances demandées pour 8 places disponibles.");
  });

  it("accorde le singulier", () => {
    expect(capacityShortfallSentence(1, 0)).toBe("1 séance demandée pour 0 places disponibles — il manque 1 place.");
    expect(capacityShortfallSentence(2, 1)).toBe("2 séances demandées pour 1 place disponible — il manque 1 place.");
  });
});
