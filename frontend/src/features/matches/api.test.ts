import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Fixture, Venue } from "./api";
import { getFixtures, getVenues } from "./api";

// On n'exerce QUE la coercition de `api.ts` : le voisin `@/shared/api/collection` est le
// SEUL double. L'API Platform OMET les props nulles/vides du JSON — on prouve que les
// lectures les ramènent à `null`/`[]` pour que les gardes `null !==` et la logique de
// grille ne voient jamais `undefined`.
const { collectionAll } = vi.hoisted(() => ({ collectionAll: vi.fn() }));
vi.mock("@/shared/api/collection", () => ({ collectionAll, collection: vi.fn() }));

beforeEach(() => {
  vi.clearAllMocks();
});

describe("getFixtures — coercition de suggestedVenueId (P4-187b)", () => {
  it("ramène suggestedVenueId absent à null", async () => {
    // L'API omet le champ pour une rencontre sans proposition floue.
    collectionAll.mockResolvedValue([{ id: "f1", teamId: "t1", matchDate: "2026-11-07", homeAway: "HOME", opponentLabel: "BRON", status: "PLACED", reviewState: "NEW" }] as unknown as Fixture[]);
    const [f] = await getFixtures();
    expect(f.suggestedVenueId).toBeNull();
  });

  it("préserve une proposition présente", async () => {
    collectionAll.mockResolvedValue([{ id: "f1", teamId: "t1", matchDate: "2026-11-07", homeAway: "HOME", opponentLabel: "BRON", status: "PLACED", reviewState: "NEW", suggestedVenueId: "v9" }] as unknown as Fixture[]);
    const [f] = await getFixtures();
    expect(f.suggestedVenueId).toBe("v9");
  });
});

describe("getVenues — coercition de externalLabels (P4-187b)", () => {
  it("ramène externalLabels absent à []", async () => {
    collectionAll.mockResolvedValue([{ id: "v1", name: "Gymnase Alpha", color: null }] as unknown as Venue[]);
    const [v] = await getVenues();
    expect(v.externalLabels).toEqual([]);
  });

  it("préserve des alias présents", async () => {
    collectionAll.mockResolvedValue([{ id: "v1", name: "Gymnase Alpha", color: null, externalLabels: ["GYMNASE MATEO"] }] as unknown as Venue[]);
    const [v] = await getVenues();
    expect(v.externalLabels).toEqual(["GYMNASE MATEO"]);
  });
});
