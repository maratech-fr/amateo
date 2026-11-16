import { beforeEach, describe, expect, it } from "vitest";

import { useWizardStore } from "./store";

describe("wizard store — period mode", () => {
  beforeEach(() => {
    useWizardStore.setState({ mode: "season", calendarEntryId: null, stepId: "teams" });
  });

  it("startPeriodMode enters period mode on the Contraintes step", () => {
    useWizardStore.getState().startPeriodMode("entry-1");
    const s = useWizardStore.getState();
    expect(s.mode).toBe("period");
    expect(s.calendarEntryId).toBe("entry-1");
    expect(s.stepId).toBe("constraints");
  });

  it("exitPeriodMode returns to season mode", () => {
    useWizardStore.getState().startPeriodMode("entry-1");
    useWizardStore.getState().exitPeriodMode();
    const s = useWizardStore.getState();
    expect(s.mode).toBe("season");
    expect(s.calendarEntryId).toBeNull();
    expect(s.stepId).toBe("teams");
  });
});
