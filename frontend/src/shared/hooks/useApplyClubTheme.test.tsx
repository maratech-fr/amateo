import { renderHook } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { accentForMode, accentHoverForMode } from "@/shared/lib/color";
import { useThemeStore } from "@/shared/stores/themeStore";

import { useApplyClubTheme } from "./useApplyClubTheme";

type Club = { accentColor: string | null; accentColorDark: string | null; accentPalette: string[] | null };
let club: Club | null = null;

vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: club ? { club } : undefined }) }));

const accentVar = () => document.documentElement.style.getPropertyValue("--accent");
const accentHoverVar = () => document.documentElement.style.getPropertyValue("--accent-hover");

afterEach(() => {
  document.documentElement.removeAttribute("style");
  club = null;
});

describe("useApplyClubTheme — per-mode club accent", () => {
  it("applies the DARK accent (adjusted for legibility) in dark mode", () => {
    club = { accentColor: "#3b82f6", accentColorDark: "#f59e0b", accentPalette: null };
    useThemeStore.setState({ mode: "dark" });
    renderHook(() => useApplyClubTheme());
    expect(accentVar()).toBe(accentForMode("#f59e0b", "dark"));
  });

  it("lifts a too-dark explicit dark accent so it stays legible (never applied raw)", () => {
    club = { accentColor: "#3b82f6", accentColorDark: "#0a0a0a", accentPalette: null };
    useThemeStore.setState({ mode: "dark" });
    renderHook(() => useApplyClubTheme());
    // accentForMode lightens a luminance<0.22 colour → never the raw near-black.
    expect(accentVar()).toBe(accentForMode("#0a0a0a", "dark"));
    expect(accentVar()).not.toBe("#0a0a0a");
  });

  it("uses the LIGHT accent in light mode", () => {
    club = { accentColor: "#3b82f6", accentColorDark: "#f59e0b", accentPalette: null };
    useThemeStore.setState({ mode: "light" });
    renderHook(() => useApplyClubTheme());
    expect(accentVar()).toBe(accentForMode("#3b82f6", "light"));
  });

  it("falls back to the dark accent in light mode when only a dark colour is set", () => {
    club = { accentColor: null, accentColorDark: "#f59e0b", accentPalette: null };
    useThemeStore.setState({ mode: "light" });
    renderHook(() => useApplyClubTheme());
    expect(accentVar()).toBe(accentForMode("#f59e0b", "light"));
  });

  it("derives the dark accent from the light one when no dark colour is set", () => {
    club = { accentColor: "#3b82f6", accentColorDark: null, accentPalette: null };
    useThemeStore.setState({ mode: "dark" });
    renderHook(() => useApplyClubTheme());
    expect(accentVar()).toBe(accentForMode("#3b82f6", "dark"));
  });

  it("clears the accent when the club has none", () => {
    club = { accentColor: null, accentColorDark: null, accentPalette: null };
    useThemeStore.setState({ mode: "dark" });
    renderHook(() => useApplyClubTheme());
    expect(accentVar()).toBe("");
  });

  it("pose --accent-hover (survol dérivé du dérivé) à côté de --accent, dans chaque mode", () => {
    club = { accentColor: "#3b82f6", accentColorDark: "#f59e0b", accentPalette: null };
    useThemeStore.setState({ mode: "light" });
    renderHook(() => useApplyClubTheme());
    expect(accentHoverVar()).toBe(accentHoverForMode(accentForMode("#3b82f6", "light"), "light"));
  });

  it("efface --accent-hover quand le club n'a pas d'accent (repli sur le défaut d'index.css)", () => {
    club = { accentColor: null, accentColorDark: null, accentPalette: null };
    useThemeStore.setState({ mode: "dark" });
    renderHook(() => useApplyClubTheme());
    expect(accentHoverVar()).toBe("");
  });
});
