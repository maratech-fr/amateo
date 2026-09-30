import { beforeEach, describe, expect, it } from "vitest";

import { type LoginSplashPhase, useLoginSplashStore } from "./loginSplashStore";

const store = useLoginSplashStore;
const setPhase = (phase: LoginSplashPhase) => store.setState({ phase });
const phase = () => store.getState().phase;

beforeEach(() => setPhase("idle"));

describe("loginSplashStore — grammaire des phases", () => {
  it("start : idle → intro", () => {
    store.getState().start();
    expect(phase()).toBe("intro");
  });

  it("start est un no-op si une session est déjà en cours", () => {
    setPhase("breathing");
    store.getState().start();
    expect(phase()).toBe("breathing");
  });

  it("beginBreathing : intro → breathing, sinon no-op", () => {
    setPhase("intro");
    store.getState().beginBreathing();
    expect(phase()).toBe("breathing");

    setPhase("outro");
    store.getState().beginBreathing();
    expect(phase()).toBe("outro");
  });

  it("beginOutro : depuis intro ET depuis breathing → outro", () => {
    setPhase("intro");
    store.getState().beginOutro();
    expect(phase()).toBe("outro");

    setPhase("breathing");
    store.getState().beginOutro();
    expect(phase()).toBe("outro");
  });

  it("beginOutro est un no-op au repos", () => {
    store.getState().beginOutro();
    expect(phase()).toBe("idle");
  });

  it("cancel : depuis intro ET breathing → cancelling", () => {
    setPhase("intro");
    store.getState().cancel();
    expect(phase()).toBe("cancelling");

    setPhase("breathing");
    store.getState().cancel();
    expect(phase()).toBe("cancelling");
  });

  it("cancel est un no-op une fois l'outro engagée (plus d'échec possible)", () => {
    setPhase("outro");
    store.getState().cancel();
    expect(phase()).toBe("outro");
  });

  it("reset ramène toujours au repos", () => {
    for (const p of ["intro", "breathing", "outro", "cancelling"] as const) {
      setPhase(p);
      store.getState().reset();
      expect(phase()).toBe("idle");
    }
  });
});
