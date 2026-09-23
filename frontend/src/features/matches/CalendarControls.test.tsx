import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it } from "vitest";

import { setTodayOverride } from "@/shared/lib/clock";

import { CalendarControls } from "./CalendarControls";
import { weekendKeyOf } from "./lib/weekendGrid";
import { useMatchesStore } from "./store";

// FRT-36 — FILET des contrôles du Calendrier (présentation PURE sur le store) AVANT le
// découpage (PR B). Aucune ligne de production ne bouge. Le composant ne tire ni réseau ni
// route : on le rend nu, on assiste sur `aria-pressed`/`aria-checked`/`disabled`/le store, jamais
// une classe (jsdom n'a aucune mise en page — `.claude/rules/frontend.md`).

type Props = Parameters<typeof CalendarControls>[0];

function baseProps(over: Partial<Props> = {}): Props {
  return {
    familyChips: [],
    familyCounts: new Map(),
    weekends: [],
    activeWeekend: null,
    weekendIndex: -1,
    months: [],
    activeMonth: null,
    monthIndex: -1,
    phases: [],
    activePhaseId: null,
    completeness: null,
    depositReminder: "",
    ...over,
  };
}

function renderControls(over: Partial<Props> = {}) {
  return render(<CalendarControls {...baseProps(over)} />);
}

beforeEach(() => {
  setTodayOverride(null);
  useMatchesStore.setState({
    consultKinds: null,
    consultFamilies: null,
    consultTypicalWeek: false,
    consultAway: false,
    consultTemporality: "semaine",
    consultMonth: null,
    consultPhaseId: null,
    selectedWeekend: null,
  });
});

describe("CalendarControls — invariant familles (forme canonique null)", () => {
  it("décocher réduit la liste ; recocher la dernière revient à null", async () => {
    const user = userEvent.setup();
    renderControls({ familyChips: ["VENUE_OVERLAP", "MATCH_MATCH"], familyCounts: new Map([["VENUE_OVERLAP", 2], ["MATCH_MATCH", 1]]) });
    // Défaut (consultFamilies null) : toutes les chips présentes sont cochées.
    expect(screen.getByRole("button", { name: /Collision de gymnase/ })).toHaveAttribute("aria-pressed", "true");
    await user.click(screen.getByRole("button", { name: /Collision de gymnase/ }));
    // Une famille manque → le store porte une liste EXPLICITE, jamais null.
    expect(useMatchesStore.getState().consultFamilies).not.toBeNull();
    expect(screen.getByRole("button", { name: /Collision de gymnase/ })).toHaveAttribute("aria-pressed", "false");
    await user.click(screen.getByRole("button", { name: /Collision de gymnase/ }));
    // Toutes cochées de nouveau → forme canonique null (dont dépendent l'URL et les compteurs).
    // (Falsif. : supprimer le ternaire qui rend null quand toutes sont cochées.)
    expect(useMatchesStore.getState().consultFamilies).toBeNull();
  });
});

describe("CalendarControls — navigation de semaine", () => {
  const weekends = ["2026-09-26", "2026-10-03", "2026-10-10"];

  it("prev/next posent la bonne clé", async () => {
    const user = userEvent.setup();
    useMatchesStore.setState({ selectedWeekend: "2026-10-03" });
    renderControls({ weekends, activeWeekend: "2026-10-03", weekendIndex: 1 });
    await user.click(screen.getByRole("button", { name: "Semaine précédente" }));
    expect(useMatchesStore.getState().selectedWeekend).toBe("2026-09-26");
    await user.click(screen.getByRole("button", { name: "Semaine suivante" }));
    expect(useMatchesStore.getState().selectedWeekend).toBe("2026-10-10");
  });

  it("prev désactivé à la première semaine, next à la dernière", () => {
    const { unmount } = renderControls({ weekends, activeWeekend: "2026-09-26", weekendIndex: 0 });
    expect(screen.getByRole("button", { name: "Semaine précédente" })).toBeDisabled();
    unmount();
    renderControls({ weekends, activeWeekend: "2026-10-10", weekendIndex: 2 });
    expect(screen.getByRole("button", { name: "Semaine suivante" })).toBeDisabled();
  });

  it("« Aujourd'hui » désactivé quand la semaine affichée est celle du jour, actif sinon", () => {
    setTodayOverride("2026-10-05"); // lundi → samedi (bucket) = 2026-10-10
    const today = weekendKeyOf("2026-10-05");
    const weeks = ["2026-10-03", today];
    useMatchesStore.setState({ selectedWeekend: today });
    const { unmount } = renderControls({ weekends: weeks, activeWeekend: today, weekendIndex: 1 });
    expect(screen.getByRole("button", { name: "Revenir à la semaine d'aujourd'hui" })).toBeDisabled();
    unmount();
    // Une autre semaine, une sélection posée → « Aujourd'hui » redevient actif.
    renderControls({ weekends: weeks, activeWeekend: "2026-10-03", weekendIndex: 0 });
    expect(screen.getByRole("button", { name: "Revenir à la semaine d'aujourd'hui" })).toBeEnabled();
  });
});

describe("CalendarControls — Réinitialiser", () => {
  it("absent quand tout est au défaut", () => {
    renderControls({});
    expect(screen.queryByRole("button", { name: "Réinitialiser" })).not.toBeInTheDocument();
  });

  it("clic → les 4 clés remises, temporalité et semaine INTACTES, focus → première puce Types", async () => {
    const user = userEvent.setup();
    useMatchesStore.setState({
      consultKinds: ["amical", "championnat", "coupe", "brassage"],
      consultAway: true,
      consultTypicalWeek: true,
      consultTemporality: "mois",
      selectedWeekend: "2026-10-03",
    });
    renderControls({});
    await user.click(screen.getByRole("button", { name: "Réinitialiser" }));
    const s = useMatchesStore.getState();
    expect(s.consultKinds).toBeNull();
    expect(s.consultFamilies).toBeNull();
    expect(s.consultTypicalWeek).toBe(false);
    expect(s.consultAway).toBe(false);
    // Ni la temporalité ni la semaine ne bougent. (Falsif. : réinitialiser aussi la temporalité.)
    expect(s.consultTemporality).toBe("mois");
    expect(s.selectedWeekend).toBe("2026-10-03");
    // Le focus est rendu à la première puce Types (« Amical »), via rAF → waitFor.
    await waitFor(() => expect(document.activeElement).toBe(screen.getByRole("button", { name: "Amical" })));
  });
});

describe("CalendarControls — puces Types", () => {
  it("toggle une puce → store via normalizeKinds, aria-pressed reflète l'état", async () => {
    const user = userEvent.setup();
    renderControls({});
    // Défaut (consultKinds null) : championnat/coupe/brassage cochés, amical décoché.
    expect(screen.getByRole("button", { name: "Championnat" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByRole("button", { name: "Amical" })).toHaveAttribute("aria-pressed", "false");
    await user.click(screen.getByRole("button", { name: "Amical" }));
    // Les 4 cochés ⇒ forme explicite (≠ défaut → jamais null), dans l'ordre de KINDS.
    expect(useMatchesStore.getState().consultKinds).toEqual(["amical", "championnat", "coupe", "brassage"]);
    expect(screen.getByRole("button", { name: "Amical" })).toHaveAttribute("aria-pressed", "true");
  });
});

describe("CalendarControls — Phase / complétude / Semaine type", () => {
  const phases = [{ competitionId: "c1", label: "Coupe — Seniors" }];

  it("complétude sans dénominateur : « N journée(s) importée(s) », pluriel", () => {
    useMatchesStore.setState({ consultTemporality: "phase" });
    const first = renderControls({ phases, activePhaseId: "c1", completeness: { imported: 1, expected: null } });
    expect(screen.getByText(/^\s*1 journée importée\s*$/)).toBeInTheDocument();
    first.unmount();
    renderControls({ phases, activePhaseId: "c1", completeness: { imported: 3, expected: null } });
    expect(screen.getByText(/^\s*3 journées importées\s*$/)).toBeInTheDocument();
  });

  it("complétude avec dénominateur : « N / M journées importées »", () => {
    useMatchesStore.setState({ consultTemporality: "phase" });
    renderControls({ phases, activePhaseId: "c1", completeness: { imported: 0, expected: 10 } });
    expect(screen.getByText(/0\s*\/\s*10\s+journées importées/)).toBeInTheDocument();
  });

  it("« Semaine type » rendue SEULEMENT en temporalité Semaine", () => {
    const { unmount } = renderControls({});
    expect(screen.getByRole("switch", { name: /Semaine type/ })).toBeInTheDocument();
    unmount();
    useMatchesStore.setState({ consultTemporality: "phase" });
    renderControls({});
    expect(screen.queryByRole("switch", { name: /Semaine type/ })).not.toBeInTheDocument();
  });
});
