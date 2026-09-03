import type { QueryClient } from "@tanstack/react-query";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { acquireScheduleStream, getScheduleStreamDiagnostics, invalidationKeysFor, isScheduleStreamConnected, parseScheduleEvent } from "./scheduleStream";

// Couche API mockée = module VOISIN (le mock ESM n'intercepte pas l'intra-module).
vi.mock("@/shared/api/client", () => ({ api: { get: vi.fn() } }));
const { api } = await import("@/shared/api/client");

const TEMPLATE = "club:c1:schedule:{id}";

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

const authResolvesWith = (topicTemplate: string): void => {
  vi.mocked(api.get).mockReturnValue({ json: () => Promise.resolve({ topicTemplate }) } as ReturnType<typeof api.get>);
};

const queryClient = { invalidateQueries: vi.fn() } as unknown as QueryClient;

describe("parseScheduleEvent — FRT-04", () => {
  it("rend null pour tout ce qui n'est pas un objet JSON", () => {
    expect(parseScheduleEvent("pas du json")).toBeNull();
    expect(parseScheduleEvent("42")).toBeNull();
    expect(parseScheduleEvent("[1]")).toBeNull();
  });

  it("un statut en vol n'est pas terminal", () => {
    expect(parseScheduleEvent(JSON.stringify({ scheduleId: "s1", status: "GENERATING" }))).toEqual({
      scheduleId: "s1",
      status: "GENERATING",
      terminal: false,
    });
  });

  it("COMPLETED et 'failed' (échec terminal du worker, minuscule) sont terminaux", () => {
    expect(parseScheduleEvent(JSON.stringify({ scheduleId: "s1", status: "COMPLETED" }))?.terminal).toBe(true);
    expect(parseScheduleEvent(JSON.stringify({ scheduleId: "s1", status: "failed" }))?.terminal).toBe(true);
  });

  it("sans statut, rien n'est déclaré terminal", () => {
    expect(parseScheduleEvent(JSON.stringify({ scheduleId: "s1" }))?.terminal).toBe(false);
  });
});

describe("invalidationKeysFor — FRT-04", () => {
  it("un événement d'avancement invalide la liste et le statut suivi par le wizard", () => {
    expect(invalidationKeysFor({ scheduleId: "s1", status: "GENERATING", terminal: false })).toEqual([
      ["schedules"],
      ["wizard", "schedule_status", "s1"],
    ]);
  });

  it("un statut terminal rend aussi périmés créneaux et diagnostics de CE planning", () => {
    expect(invalidationKeysFor({ scheduleId: "s1", status: "COMPLETED", terminal: true })).toEqual([
      ["schedules"],
      ["wizard", "schedule_status", "s1"],
      ["slots", "s1"],
      ["diagnostics", "s1"],
    ]);
  });

  it("sans scheduleId (défense), on élargit au préfixe plutôt que de figer un écran", () => {
    expect(invalidationKeysFor({ scheduleId: null, status: "COMPLETED", terminal: true })).toEqual([
      ["schedules"],
      ["wizard", "schedule_status"],
      ["slots"],
      ["diagnostics"],
    ]);
  });
});

describe("acquireScheduleStream — FRT-04", () => {
  // Relâchés en afterEach, PAS en fin de test : un expect qui échoue avant le
  // release fuirait une référence du singleton et ferait tomber les tests
  // suivants en cascade (constaté à la preuve de chute).
  let releases: Array<() => void> = [];
  const acquire = (): (() => void) => {
    const release = acquireScheduleStream(queryClient);
    releases.push(release);
    return release;
  };

  beforeEach(() => {
    vi.useFakeTimers();
    FakeEventSource.instances = [];
    vi.stubGlobal("EventSource", FakeEventSource);
    vi.mocked(api.get).mockReset();
    vi.mocked(queryClient.invalidateQueries).mockReset();
  });

  afterEach(() => {
    for (const release of releases) {
      release();
    }
    releases = [];
    vi.unstubAllGlobals();
    vi.useRealTimers();
  });

  const flush = async (): Promise<void> => {
    await Promise.resolve();
    await Promise.resolve();
  };

  it("authentifie PUIS s'abonne au template — une seule connexion, partagée, refermée au dernier release", async () => {
    authResolvesWith(TEMPLATE);

    const releaseA = acquire();
    await flush();
    const releaseB = acquire();
    await flush();

    expect(api.get).toHaveBeenCalledExactlyOnceWith("mercure/auth");
    expect(FakeEventSource.instances).toHaveLength(1);
    expect(FakeEventSource.instances[0]!.url).toBe(`/.well-known/mercure?topic=${encodeURIComponent(TEMPLATE)}`);

    expect(isScheduleStreamConnected()).toBe(false);
    FakeEventSource.instances[0]!.onopen?.();
    expect(isScheduleStreamConnected()).toBe(true);

    releaseA();
    expect(FakeEventSource.instances[0]!.closed).toBe(false);
    releaseB();
    expect(FakeEventSource.instances[0]!.closed).toBe(true);
    expect(isScheduleStreamConnected()).toBe(false);
  });

  it("chaque événement reçu invalide les caches react-query correspondants", async () => {
    authResolvesWith(TEMPLATE);
    const release = acquire();
    await flush();

    FakeEventSource.instances[0]!.onmessage?.({ data: JSON.stringify({ scheduleId: "s1", status: "COMPLETED" }) } as MessageEvent<string>);

    const keys = vi.mocked(queryClient.invalidateQueries).mock.calls.map(([filter]) => filter?.queryKey);
    expect(keys).toEqual([["schedules"], ["wizard", "schedule_status", "s1"], ["slots", "s1"], ["diagnostics", "s1"]]);
    release();
  });

  it("sur erreur du flux : fermeture (le polling 2,5 s reprend) puis RÉ-AUTH au retry — jamais la reconnexion native, qui rejouerait un cookie mort", async () => {
    authResolvesWith(TEMPLATE);
    const release = acquire();
    await flush();
    FakeEventSource.instances[0]!.onopen?.();

    FakeEventSource.instances[0]!.onerror?.();
    expect(FakeEventSource.instances[0]!.closed).toBe(true);
    expect(isScheduleStreamConnected()).toBe(false);

    vi.advanceTimersByTime(10_000);
    await flush();
    expect(api.get).toHaveBeenCalledTimes(2);
    expect(FakeEventSource.instances).toHaveLength(2);
    release();
  });

  it("échec d'auth : pas d'abonnement, nouvel essai plus tard — abandonné si tout le monde a relâché", async () => {
    vi.mocked(api.get).mockReturnValue({ json: () => Promise.reject(new Error("401")) } as unknown as ReturnType<typeof api.get>);
    const release = acquire();
    await flush();
    expect(FakeEventSource.instances).toHaveLength(0);

    release();
    vi.advanceTimersByTime(10_000);
    await flush();
    expect(api.get).toHaveBeenCalledTimes(1);
  });

  // P4-168 — le TÉMOIN qui prouve que le planning est livré par SSE, pas par le polling de secours.
  // `eventsReceived` est un compteur monotone qui SURVIT à la fermeture du flux (le flux se relâche
  // dès que la génération quitte l'état « en vol », GenerateStep.tsx:141) : au moment où l'écran
  // affiche le planning, `connected` est déjà retombé, mais `eventsReceived >= 1` prouve encore
  // qu'un événement Mercure a bien été reçu. C'est LUI le témoin robuste (D2), pas `connected`.
  it("ouverture → connected ; message → eventsReceived+1 (payload illisible non compté) ; erreur → connected false, compteur CONSERVÉ", async () => {
    authResolvesWith(TEMPLATE);
    const release = acquire();
    await flush();

    expect(getScheduleStreamDiagnostics().connected).toBe(false);
    const before = getScheduleStreamDiagnostics().eventsReceived;

    FakeEventSource.instances[0]!.onopen?.();
    expect(getScheduleStreamDiagnostics().connected).toBe(true);

    FakeEventSource.instances[0]!.onmessage?.({ data: JSON.stringify({ scheduleId: "s1", status: "GENERATING" }) } as MessageEvent<string>);
    expect(getScheduleStreamDiagnostics().eventsReceived).toBe(before + 1);

    // Un payload illisible n'est PAS un événement d'avancement : il ne fait pas bouger le témoin.
    FakeEventSource.instances[0]!.onmessage?.({ data: "pas du json" } as MessageEvent<string>);
    expect(getScheduleStreamDiagnostics().eventsReceived).toBe(before + 1);

    // Le flux tombe : `connected` retombe, mais le témoin d'avancement RESTE (livraison SSE prouvée).
    FakeEventSource.instances[0]!.onerror?.();
    expect(getScheduleStreamDiagnostics().connected).toBe(false);
    expect(getScheduleStreamDiagnostics().eventsReceived).toBe(before + 1);
    release();
  });
});
