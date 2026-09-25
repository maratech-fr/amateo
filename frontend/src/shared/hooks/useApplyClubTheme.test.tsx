import { renderHook } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { accentForMode, accentHoverForMode, readableForeground } from "@/shared/lib/color";
import { PRODUCT_ACCENT } from "@/shared/lib/product";
import { useThemeStore } from "@/shared/stores/themeStore";

import { useApplyClubTheme } from "./useApplyClubTheme";

type Club = { accentColor: string | null; accentColorDark: string | null; accentPalette: string[] | null };
let club: Club | null = null;

vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: club ? { club } : undefined }) }));

const accentVar = () => document.documentElement.style.getPropertyValue("--accent");
const accentForegroundVar = () => document.documentElement.style.getPropertyValue("--accent-foreground");
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

  it("pose --accent-hover (survol dérivé du dérivé) à côté de --accent, dans chaque mode", () => {
    club = { accentColor: "#3b82f6", accentColorDark: "#f59e0b", accentPalette: null };
    useThemeStore.setState({ mode: "light" });
    renderHook(() => useApplyClubTheme());
    expect(accentHoverVar()).toBe(accentHoverForMode(accentForMode("#3b82f6", "light"), "light"));
  });

  // DA « base chaude + accent produit » — un club SANS couleur ne retombe plus sur `removeProperty`
  // (le bleu froid d'`index.css`) : il DÉRIVE l'accent PRODUIT (`PRODUCT_ACCENT`, teal) par la MÊME
  // voie que n'importe quel club. Une seule voie de dérivation, pas de jeton `--accent-default`.
  it("dérive l'accent PRODUIT par défaut quand le club n'a pas de couleur (clair)", () => {
    club = { accentColor: null, accentColorDark: null, accentPalette: null };
    useThemeStore.setState({ mode: "light" });
    renderHook(() => useApplyClubTheme());
    expect(accentVar()).toBe(accentForMode(PRODUCT_ACCENT, "light"));
  });

  it("dérive l'accent PRODUIT par défaut quand le club n'a pas de couleur (sombre)", () => {
    club = { accentColor: null, accentColorDark: null, accentPalette: null };
    useThemeStore.setState({ mode: "dark" });
    renderHook(() => useApplyClubTheme());
    expect(accentVar()).toBe(accentForMode(PRODUCT_ACCENT, "dark"));
  });

  it("le défaut produit pose aussi --accent-foreground et --accent-hover (dérivés du dérivé)", () => {
    club = { accentColor: null, accentColorDark: null, accentPalette: null };
    useThemeStore.setState({ mode: "light" });
    renderHook(() => useApplyClubTheme());
    const derived = accentForMode(PRODUCT_ACCENT, "light");
    expect(accentForegroundVar()).toBe(readableForeground(derived));
    expect(accentHoverVar()).toBe(accentHoverForMode(derived, "light"));
  });

  it("une couleur de club l'emporte sur le défaut produit", () => {
    club = { accentColor: "#3b82f6", accentColorDark: null, accentPalette: null };
    useThemeStore.setState({ mode: "light" });
    renderHook(() => useApplyClubTheme());
    expect(accentVar()).toBe(accentForMode("#3b82f6", "light"));
    expect(accentVar()).not.toBe(accentForMode(PRODUCT_ACCENT, "light"));
  });
});
