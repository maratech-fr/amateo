import { beforeEach, describe, expect, it } from "vitest";

import { useThemeStore } from "./themeStore";

describe("themeStore", () => {
  beforeEach(() => {
    useThemeStore.setState({ mode: "dark", accent: null });
  });

  it("toggles mode", () => {
    useThemeStore.getState().toggleMode();
    expect(useThemeStore.getState().mode).toBe("light");
    useThemeStore.getState().toggleMode();
    expect(useThemeStore.getState().mode).toBe("dark");
  });

  it("sets a club accent and resets it", () => {
    useThemeStore.getState().setAccent("oklch(0.5 0.2 20)");
    expect(useThemeStore.getState().accent).toBe("oklch(0.5 0.2 20)");
    useThemeStore.getState().setAccent(null);
    expect(useThemeStore.getState().accent).toBeNull();
  });

  it("migrate returns a safe default on null persisted state", () => {
    const options = useThemeStore.persist.getOptions();
    const migrated = options.migrate?.(null, 1) as { mode: string; accent: string | null };
    expect(migrated.mode).toBe("dark");
    expect(migrated.accent).toBeNull();
  });
});
