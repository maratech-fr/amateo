import { renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { useApplySentryIdentity } from "./useApplySentryIdentity";

const setUser = vi.fn();
const setTag = vi.fn();
vi.mock("@sentry/react", () => ({
  setUser: (...args: unknown[]) => setUser(...args),
  setTag: (...args: unknown[]) => setTag(...args),
}));

type Me = { id: string; club: { ffbbClubCode: string | null } | null } | undefined;
let me: Me;
vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: me }) }));

beforeEach(() => {
  // Le SDK n'est touché que si un DSN est présent (cohérent avec main.tsx/client.ts).
  vi.stubEnv("VITE_SENTRY_DSN", "https://public@example.ingest.sentry.io/42");
  me = undefined;
  setUser.mockClear();
  setTag.mockClear();
});

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("useApplySentryIdentity", () => {
  it("pose l'id interne SEUL (aucun email/nom/ip) et le code FFBB du club en tag", () => {
    me = { id: "u-123", club: { ffbbClubCode: "ARA0069013" } };
    renderHook(() => useApplySentryIdentity());

    expect(setUser).toHaveBeenCalledWith({ id: "u-123" });
    // Un seul argument, et cet argument ne porte QUE la clé `id`.
    expect(Object.keys(setUser.mock.calls[0][0] as object)).toEqual(["id"]);
    expect(setTag).toHaveBeenCalledWith("club_ffbb", "ARA0069013");
  });

  it("tagge aussi un club de démo (son code FFBB est utile au diagnostic)", () => {
    me = { id: "u-demo", club: { ffbbClubCode: "ARA9999999" } };
    renderHook(() => useApplySentryIdentity());

    expect(setUser).toHaveBeenCalledWith({ id: "u-demo" });
    expect(setTag).toHaveBeenCalledWith("club_ffbb", "ARA9999999");
  });

  it("sans club (orphelin), pose l'id mais RETIRE le tag (undefined)", () => {
    me = { id: "u-orphan", club: null };
    renderHook(() => useApplySentryIdentity());

    expect(setUser).toHaveBeenCalledWith({ id: "u-orphan" });
    expect(setTag).toHaveBeenCalledWith("club_ffbb", undefined);
  });

  it("au logout (démontage), efface l'utilisateur et le tag", () => {
    me = { id: "u-123", club: { ffbbClubCode: "ARA0069013" } };
    const { unmount } = renderHook(() => useApplySentryIdentity());
    setUser.mockClear();
    setTag.mockClear();

    unmount();

    expect(setUser).toHaveBeenCalledWith(null);
    expect(setTag).toHaveBeenCalledWith("club_ffbb", undefined);
  });

  it("sans DSN, le SDK est inerte : rien n'est posé", () => {
    vi.stubEnv("VITE_SENTRY_DSN", "");
    me = { id: "u-123", club: { ffbbClubCode: "ARA0069013" } };
    renderHook(() => useApplySentryIdentity());

    expect(setUser).not.toHaveBeenCalled();
    expect(setTag).not.toHaveBeenCalled();
  });
});
