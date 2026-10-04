import type { QueryClient } from "@tanstack/react-query";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { acquirePlacementStream, parsePlacementEvent, PLACEMENT_INVALIDATION_KEYS } from "./placementStream";

// Couche API mockée = module VOISIN (le mock ESM n'intercepte pas l'intra-module).
vi.mock("@/shared/api/client", () => ({ api: { get: vi.fn() } }));
const { api } = await import("@/shared/api/client");

const PLACEMENT_TOPIC = "club:c1:placement";

class FakeEventSource {
  static instances: FakeEventSource[] = [];
  onopen: (() => void) | null = null;
  onmessage: ((event: MessageEvent<string>) => void) | null = null;
  onerror: (() => void) | null = null;
  closed = false;
  url: string;

  constructor(url: string) {
    this.url = url;
    FakeEventSource.instances.push(this);
  }

  close(): void {
    this.closed = true;
  }
}

const authResolvesWith = (placementTopic: string): void => {
  vi.mocked(api.get).mockReturnValue({ json: () => Promise.resolve({ placementTopic }) } as ReturnType<typeof api.get>);
};

const queryClient = { invalidateQueries: vi.fn() } as unknown as QueryClient;

describe("parsePlacementEvent", () => {
  it("rend null pour tout ce qui n'est pas un objet JSON", () => {
    expect(parsePlacementEvent("pas du json")).toBeNull();
    expect(parsePlacementEvent("42")).toBeNull();
    expect(parsePlacementEvent("[1]")).toBeNull();
  });

  it("parse une bascule COMPLETED (terminal) avec son runId", () => {
    expect(parsePlacementEvent(JSON.stringify({ runId: "run-1", status: "COMPLETED" }))).toEqual({ runId: "run-1", status: "COMPLETED", terminal: true });
  });

  it("parse une bascule FAILED (terminal)", () => {
    expect(parsePlacementEvent(JSON.stringify({ runId: "run-2", status: "FAILED" }))?.terminal).toBe(true);
  });

  it("un statut non terminal n'est pas marqué terminal", () => {
    expect(parsePlacementEvent(JSON.stringify({ runId: "run-3", status: "RUNNING" }))?.terminal).toBe(false);
  });
});

describe("acquirePlacementStream — abonnement + invalidation à la bascule", () => {
  beforeEach(() => {
    FakeEventSource.instances = [];
    vi.stubGlobal("EventSource", FakeEventSource as unknown as typeof EventSource);
    vi.mocked(api.get).mockReset();
    vi.mocked(queryClient.invalidateQueries).mockReset();
    authResolvesWith(PLACEMENT_TOPIC);
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("s'abonne au topic FIXE du placement et invalide run + rencontres à réception", async () => {
    const release = acquirePlacementStream(queryClient);
    await vi.waitFor(() => expect(FakeEventSource.instances).toHaveLength(1));
    const source = FakeEventSource.instances[0];
    expect(source.url).toContain(encodeURIComponent(PLACEMENT_TOPIC));

    source.onmessage?.({ data: JSON.stringify({ runId: "run-1", status: "COMPLETED" }) } as MessageEvent<string>);
    expect(queryClient.invalidateQueries).toHaveBeenCalledTimes(PLACEMENT_INVALIDATION_KEYS.length);

    release();
    expect(source.closed).toBe(true);
  });

  it("ignore un payload illisible (aucune invalidation)", async () => {
    const release = acquirePlacementStream(queryClient);
    await vi.waitFor(() => expect(FakeEventSource.instances).toHaveLength(1));
    FakeEventSource.instances[0].onmessage?.({ data: "pas du json" } as MessageEvent<string>);
    expect(queryClient.invalidateQueries).not.toHaveBeenCalled();
    release();
  });
});
