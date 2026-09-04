import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

// Le rail de réservation appelle la ky `api` : on la mocke pour éprouver la tolérance du DELETE.
const del = vi.hoisted(() => vi.fn());
vi.mock("@/shared/api/client", () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: del },
}));

import { deleteReservation } from "./api";

const httpError = (status: number): HTTPError =>
  new HTTPError(new Response(null, { status }), new Request("http://t/api/reservations/r1"), {} as never);

describe("deleteReservation — tolérance 404 du retrait (P2-62)", () => {
  beforeEach(() => {
    del.mockReset();
  });

  it("résout quand le DELETE réussit", async () => {
    del.mockReturnValue(Promise.resolve(new Response(null, { status: 204 })));

    await expect(deleteReservation("r1")).resolves.toBeUndefined();
  });

  it("RÉSOUT quand la sœur est DÉJÀ emportée (404) — la case bloc-complète a été vidée d'un coup", async () => {
    del.mockReturnValue(Promise.reject(httpError(404)));

    await expect(deleteReservation("r1")).resolves.toBeUndefined();
  });

  it("rejette tout AUTRE échec (500) — le retrait a vraiment échoué", async () => {
    del.mockReturnValue(Promise.reject(httpError(500)));

    await expect(deleteReservation("r1")).rejects.toBeInstanceOf(HTTPError);
  });
});
