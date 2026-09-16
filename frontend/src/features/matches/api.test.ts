import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Fixture, Venue, VenueLabelInventoryRow } from "./api";
import { attachVenueLabel, deleteConflictResolution, detachVenueLabel, getFixtures, getVenueLabelInventory, getVenueSuggestions, getVenues, putConflictResolution, refreshOpponents, resolveOpponents } from "./api";

// On n'exerce QUE la coercition de `api.ts` : le voisin `@/shared/api/collection` est le
// SEUL double. L'API Platform OMET les props nulles/vides du JSON — on prouve que les
// lectures les ramènent à `null`/`[]` pour que les gardes `null !==` et la logique de
// grille ne voient jamais `undefined`.
const { collectionAll } = vi.hoisted(() => ({ collectionAll: vi.fn() }));
vi.mock("@/shared/api/collection", () => ({ collectionAll, collection: vi.fn() }));

// Le client ky : on capture le CHEMIN du DELETE sans toucher le réseau (patron
// submitReopenFixture.test.ts). `getFixtures`/`getVenues` passent par `collectionAll`
// (mocké au-dessus), donc ce double du client ne les gêne pas.
const { del, post, get, put } = vi.hoisted(() => ({
  del: vi.fn<(url: string) => Promise<unknown>>(() => Promise.resolve()),
  post: vi.fn(() => ({ json: () => Promise.resolve({ venueId: "v1", label: "gymnase mateo", attached: 0 }) })),
  get: vi.fn(() => ({ json: () => Promise.resolve({ labels: [] as VenueLabelInventoryRow[] }) })),
  put: vi.fn(() => ({ json: () => Promise.resolve({ fingerprint: "fp-1", resolution: { status: "DEROGATION_REQUESTED", note: null, updatedAt: "2026-10-03T20:45:00+02:00" } }) })),
}));
vi.mock("@/shared/api/client", () => ({ api: { delete: del, post, get, put } }));

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

describe("getFixtures — coercition des clés de jointure adverse (P2-54 « adversaire multi-gymnases »)", () => {
  it("ramène opponentOrganismeCode/opponentTeamKey absents à null (l'API omet le champ nul)", async () => {
    collectionAll.mockResolvedValue([{ id: "f1", teamId: "t1", matchDate: "2026-11-07", homeAway: "AWAY", opponentLabel: "BRON", status: "UNPLACED", reviewState: "NEW" }] as unknown as Fixture[]);
    const [f] = await getFixtures();
    expect(f.opponentOrganismeCode).toBeNull();
    expect(f.opponentTeamKey).toBeNull();
  });

  it("préserve les clés présentes", async () => {
    collectionAll.mockResolvedValue([
      { id: "f1", teamId: "t1", matchDate: "2026-11-07", homeAway: "AWAY", opponentLabel: "BRON - 2", status: "UNPLACED", reviewState: "NEW", opponentOrganismeCode: "ARA0069123", opponentTeamKey: "BRON-2" },
    ] as unknown as Fixture[]);
    const [f] = await getFixtures();
    expect(f.opponentOrganismeCode).toBe("ARA0069123");
    expect(f.opponentTeamKey).toBe("BRON-2");
  });
});

describe("getVenueSuggestions — les gymnases connus d'un adversaire (PR-3)", () => {
  it("encode le code dans le chemin et déballe { suggestions }", async () => {
    get.mockReturnValueOnce({ json: () => Promise.resolve({ code: "ARA 69", suggestions: [{ label: "Halle" }] }) } as unknown as ReturnType<typeof get>);
    const out = await getVenueSuggestions("ARA 69");
    expect(get).toHaveBeenCalledWith("opponents/ARA%2069/venue-suggestions");
    expect(out).toEqual([{ label: "Halle" }]);
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

describe("detachVenueLabel — encodage du libellé dans le chemin (P4-196)", () => {
  it("encode l'espace de l'alias normalisé (jamais un chemin cassé)", async () => {
    await detachVenueLabel({ venueId: "v1", label: "gymnase mateo" });
    expect(del).toHaveBeenCalledWith("venues/v1/external-labels/gymnase%20mateo");
  });
});

describe("attachVenueLabel — drapeau reassign dans le corps (E2, P4-205)", () => {
  it("sans reassign ⇒ corps { label } SEUL (aucune clé reassign)", async () => {
    await attachVenueLabel({ venueId: "v1", label: "GYMNASE MATEO" });
    expect(post).toHaveBeenCalledWith("venues/v1/external-labels", { json: { label: "GYMNASE MATEO" } });
  });

  it("reassign: true ⇒ corps { label, reassign: true }", async () => {
    await attachVenueLabel({ venueId: "v2", label: "GYMNASE MATEO", reassign: true });
    expect(post).toHaveBeenCalledWith("venues/v2/external-labels", { json: { label: "GYMNASE MATEO", reassign: true } });
  });

  it("reassign: false ⇒ traité comme absent (corps { label } SEUL — jamais reassign:false)", async () => {
    await attachVenueLabel({ venueId: "v3", label: "X", reassign: false });
    expect(post).toHaveBeenCalledWith("venues/v3/external-labels", { json: { label: "X" } });
  });
});

describe("getVenueLabelInventory — déballe { labels } (E2, P4-205)", () => {
  it("rend le tableau labels tel quel (une ligne par libellé normalisé, champs du contrat)", async () => {
    const rows: VenueLabelInventoryRow[] = [
      { labelKey: "gymnase mateo", displayLabel: "GYMNASE MATEO", venueId: null, suggestedVenueId: "v9", homeCount: 3, placedCount: 1, unplacedCount: 2 },
    ];
    get.mockReturnValueOnce({ json: () => Promise.resolve({ labels: rows }) });
    const out = await getVenueLabelInventory();
    expect(get).toHaveBeenCalledWith("venues/fbi-labels");
    expect(out).toEqual(rows);
  });
});

describe("putConflictResolution — pose la résolution par empreinte (P4-207)", () => {
  it("PUT sur le chemin de l'empreinte avec { status } (note omise = vidée serveur)", async () => {
    const out = await putConflictResolution("fp-1", { status: "DEROGATION_REQUESTED" });
    expect(put).toHaveBeenCalledWith("fixtures/conflicts/fp-1/resolution", { json: { status: "DEROGATION_REQUESTED" } });
    expect(out.fingerprint).toBe("fp-1");
    expect(out.resolution.status).toBe("DEROGATION_REQUESTED");
  });

  it("resservit la note quand fournie (le PUT est un remplacement plein)", async () => {
    await putConflictResolution("fp-2", { status: "RESOLVED_INTERNALLY", note: "vu avec la ligue" });
    expect(put).toHaveBeenCalledWith("fixtures/conflicts/fp-2/resolution", { json: { status: "RESOLVED_INTERNALLY", note: "vu avec la ligue" } });
  });
});

describe("deleteConflictResolution — remet « à traiter » (P4-207)", () => {
  it("DELETE sur le chemin de l'empreinte", async () => {
    await deleteConflictResolution("fp-3");
    expect(del).toHaveBeenCalledWith("fixtures/conflicts/fp-3/resolution");
  });
});

describe("resolveOpponents — rattrapage des codes FFBB de l'annuaire (PR 2a)", () => {
  it("POST opponents/resolve et rend { resolved, unresolved, skipped, stamped }", async () => {
    post.mockReturnValueOnce({ json: () => Promise.resolve({ resolved: 12, unresolved: ["Perdu FC"], skipped: 3, stamped: 40 }) } as unknown as ReturnType<typeof post>);
    const out = await resolveOpponents();
    expect(post).toHaveBeenCalledWith("opponents/resolve");
    expect(out).toEqual({ resolved: 12, unresolved: ["Perdu FC"], skipped: 3, stamped: 40 });
  });
});

describe("refreshOpponents — l'orchestrateur en un appel (PR 2b)", () => {
  it("POST opponents/refresh et rend les trois passes { codes, autoLocated, travel }", async () => {
    const body = {
      codes: { resolved: 12, unresolved: ["Perdu FC"], skipped: 3, stamped: 40 },
      autoLocated: { located: 8, ambiguous: 1, unmatched: 2, skipped: 4 },
      travel: { resolved: 40, unresolved: ["ARA0069999"], skippedManual: 5 },
    };
    post.mockReturnValueOnce({ json: () => Promise.resolve(body) } as unknown as ReturnType<typeof post>);
    const out = await refreshOpponents();
    expect(post).toHaveBeenCalledWith("opponents/refresh");
    expect(out).toEqual(body);
  });
});
