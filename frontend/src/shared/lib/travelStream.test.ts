import type { QueryClient } from "@tanstack/react-query";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { acquireTravelStream, parseTravelEvent, TRAVEL_INVALIDATION_KEYS } from "./travelStream";

// Couche API mockée = module VOISIN (le mock ESM n'intercepte pas l'intra-module).
vi.mock("@/shared/api/client", () => ({ api: { get: vi.fn() } }));
const { api } = await import("@/shared/api/client");

const TRAVEL_TOPIC = "club:c1:travel";

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

const authResolvesWith = (travelTopic: string): void => {
  vi.mocked(api.get).mockReturnValue({ json: () => Promise.resolve({ travelTopic }) } as ReturnType<typeof api.get>);
};

const queryClient = { invalidateQueries: vi.fn() } as unknown as QueryClient;

describe("parseTravelEvent — C6", () => {
  it("rend null pour tout ce qui n'est pas un objet JSON", () => {
    expect(parseTravelEvent("pas du json")).toBeNull();
    expect(parseTravelEvent("42")).toBeNull();
    expect(parseTravelEvent("[1]")).toBeNull();
  });

  it("parse un événement de progression (scope, done/total, non terminal, pas de verdict)", () => {
    expect(parseTravelEvent(JSON.stringify({ scope: "OPPONENTS", done: 5, total: 20, terminal: false }))).toEqual({
      scope: "OPPONENTS",
      done: 5,
      total: 20,
      terminal: false,
      verdict: null,
    });
  });

  it("parse un terminal de matrice avec verdict {filled, unresolved}", () => {
    const event = parseTravelEvent(JSON.stringify({ scope: "VENUE_MATRIX", done: 12, total: 12, terminal: true, verdict: { filled: 8, unresolved: [{ venueAId: "a", venueBId: "b", reason: "routing_failed" }] } }));
    expect(event?.terminal).toBe(true);
    expect(event?.scope).toBe("VENUE_MATRIX");
    expect(event?.verdict).toEqual({ filled: 8, unresolved: [{ venueAId: "a", venueBId: "b", reason: "routing_failed" }] });
  });

  it("un scope inconnu retombe sur null (jamais une valeur inventée)", () => {
    expect(parseTravelEvent(JSON.stringify({ scope: "NOPE", done: 1, total: 1, terminal: false }))?.scope).toBeNull();
  });
});

describe("acquireTravelStream — abonnement + invalidation debouncée", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    FakeEventSource.instances = [];
    vi.stubGlobal("EventSource", FakeEventSource as unknown as typeof EventSource);
    vi.mocked(api.get).mockReset();
    vi.mocked(queryClient.invalidateQueries).mockReset();
    authResolvesWith(TRAVEL_TOPIC);
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("s'abonne au topic FIXE des trajets et invalide les 3 caches (debounce) à réception", async () => {
    const release = acquireTravelStream(queryClient);
    await vi.waitFor(() => expect(FakeEventSource.instances).toHaveLength(1));
    const source = FakeEventSource.instances[0];
    expect(source.url).toContain(encodeURIComponent(TRAVEL_TOPIC));

    // Une rafale d'événements (paliers) → UN seul refetch (debounce 500 ms).
    source.onmessage?.({ data: JSON.stringify({ scope: "OPPONENTS", done: 5, total: 20, terminal: false }) } as MessageEvent<string>);
    source.onmessage?.({ data: JSON.stringify({ scope: "OPPONENTS", done: 10, total: 20, terminal: false }) } as MessageEvent<string>);
    expect(queryClient.invalidateQueries).not.toHaveBeenCalled(); // debouncé
    vi.advanceTimersByTime(500);
    expect(queryClient.invalidateQueries).toHaveBeenCalledTimes(TRAVEL_INVALIDATION_KEYS.length);

    release();
    expect(source.closed).toBe(true);
  });
});
