import { describe, expect, it } from "vitest";

import { unplacedReasonLabel } from "./unplacedReasonLabel";

describe("unplacedReasonLabel (P2-52)", () => {
  it("nomme la raison persistante venue_lost", () => {
    expect(unplacedReasonLabel("venue_lost")).toBe("Le gymnase n'est plus affilié au club");
  });

  it("rend null quand il n'y a pas de raison persistante (le cas courant)", () => {
    expect(unplacedReasonLabel(null)).toBeNull();
  });
});
