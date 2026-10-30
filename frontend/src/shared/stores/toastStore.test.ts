import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { toast, useToastStore } from "./toastStore";

describe("toastStore", () => {
  beforeEach(() => {
    useToastStore.setState({ toasts: [] });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("auto-dismisses after the delay", () => {
    vi.useFakeTimers();
    useToastStore.getState().push("Boom", "error");
    expect(useToastStore.getState().toasts).toHaveLength(1);
    vi.advanceTimersByTime(7000);
    expect(useToastStore.getState().toasts).toHaveLength(0);
  });

  it("cancels the auto-dismiss timer on manual dismiss (no leak/double-fire)", () => {
    vi.useFakeTimers();
    const clear = vi.spyOn(globalThis, "clearTimeout");
    const id = useToastStore.getState().push("Boom");
    useToastStore.getState().dismiss(id);
    expect(clear).toHaveBeenCalled();
    // Advancing past the delay must not throw or resurrect anything.
    vi.advanceTimersByTime(10000);
    expect(useToastStore.getState().toasts).toHaveLength(0);
  });

  it("pushes a toast with the given variant and a unique id", () => {
    const a = useToastStore.getState().push("Boom", "error");
    const b = useToastStore.getState().push("Ok", "success");

    const { toasts } = useToastStore.getState();
    expect(toasts).toHaveLength(2);
    expect(a).not.toBe(b);
    expect(toasts[0]).toMatchObject({ message: "Boom", variant: "error" });
    expect(toasts[1]).toMatchObject({ message: "Ok", variant: "success" });
  });

  it("defaults to the error variant", () => {
    useToastStore.getState().push("Oops");
    expect(useToastStore.getState().toasts[0].variant).toBe("error");
  });

  it("dismisses a toast by id", () => {
    const id = useToastStore.getState().push("Boom");
    useToastStore.getState().dismiss(id);
    expect(useToastStore.getState().toasts).toHaveLength(0);
  });

  it("exposes an imperative helper", () => {
    toast.success("Saved");
    const { toasts } = useToastStore.getState();
    expect(toasts[0]).toMatchObject({ message: "Saved", variant: "success" });
  });

  it("un toast ne remplace que ceux de sa propre clé", () => {
    toast.success("Sans clé");
    toast.success("Gymnase A, premier", { replaces: "a" });
    toast.success("Gymnase B", { replaces: "b" });
    toast.success("Gymnase A, second", { replaces: "a" });

    expect(useToastStore.getState().toasts.map((t) => t.message)).toEqual(["Sans clé", "Gymnase B", "Gymnase A, second"]);
  });
});
