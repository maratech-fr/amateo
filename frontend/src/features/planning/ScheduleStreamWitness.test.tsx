import { act, render, screen } from "@testing-library/react";
import type { QueryClient } from "@tanstack/react-query";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { acquireScheduleStream } from "./lib/scheduleStream";
import { ScheduleStreamWitness } from "./ScheduleStreamWitness";

// La couche auth du flux est un module VOISIN — on la mocke pour piloter `topicTemplate`.
vi.mock("@/shared/api/client", () => ({ api: { get: vi.fn() } }));
const { api } = await import("@/shared/api/client");

const TEMPLATE = "club:c1:schedule:{id}";
const queryClient = { invalidateQueries: vi.fn() } as unknown as QueryClient;

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

const flush = async (): Promise<void> => {
  await act(async () => {
    await Promise.resolve();
    await Promise.resolve();
  });
};

/**
 * P4-168 — le témoin DOM (`data-schedule-stream` / `data-schedule-stream-events`) reflète
 * l'état du flux Mercure. On pilote le VRAI module singleton (prod, pas un double) via un
 * EventSource simulé, et on vérifie que l'attribut suit.
 */
describe("ScheduleStreamWitness — P4-168", () => {
  beforeEach(() => {
    FakeEventSource.instances = [];
    vi.stubGlobal("EventSource", FakeEventSource);
    vi.mocked(api.get).mockReset();
    vi.mocked(api.get).mockReturnValue({ json: () => Promise.resolve({ topicTemplate: TEMPLATE }) } as ReturnType<typeof api.get>);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("l'attribut DOM reflète le diagnostic : déconnecté au départ → connecté à l'ouverture → +1 par événement → déconnecté sur erreur", async () => {
    render(<ScheduleStreamWitness />);
    const witness = (): HTMLElement => screen.getByTestId("schedule-stream-witness");

    expect(witness()).toHaveAttribute("data-schedule-stream", "disconnected");
    const events0 = Number(witness().getAttribute("data-schedule-stream-events"));

    const release = acquireScheduleStream(queryClient);
    await flush();

    await act(async () => {
      FakeEventSource.instances[0]!.onopen?.();
    });
    expect(witness()).toHaveAttribute("data-schedule-stream", "connected");

    await act(async () => {
      FakeEventSource.instances[0]!.onmessage?.({ data: JSON.stringify({ scheduleId: "s1", status: "GENERATING" }) } as MessageEvent<string>);
    });
    expect(Number(witness().getAttribute("data-schedule-stream-events"))).toBe(events0 + 1);

    await act(async () => {
      FakeEventSource.instances[0]!.onerror?.();
    });
    expect(witness()).toHaveAttribute("data-schedule-stream", "disconnected");
    // Le témoin d'avancement survit à la fermeture (livraison SSE prouvée même flux coupé).
    expect(Number(witness().getAttribute("data-schedule-stream-events"))).toBe(events0 + 1);

    release();
  });
});
