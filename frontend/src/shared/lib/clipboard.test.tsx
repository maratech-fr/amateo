import { afterEach, describe, expect, it, vi } from "vitest";

import { copyToClipboard } from "./clipboard";

describe("copyToClipboard", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("utilise navigator.clipboard quand il est disponible", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal("navigator", { clipboard: { writeText } });

    await expect(copyToClipboard("https://x/doleances/abc")).resolves.toBe(true);
    expect(writeText).toHaveBeenCalledWith("https://x/doleances/abc");
  });

  it("retombe sur execCommand si clipboard échoue", async () => {
    vi.stubGlobal("navigator", { clipboard: { writeText: vi.fn().mockRejectedValue(new Error("denied")) } });
    const execCommand = vi.fn().mockReturnValue(true);
    // jsdom fournit document ; on greffe execCommand.
    (document as unknown as { execCommand: typeof execCommand }).execCommand = execCommand;

    await expect(copyToClipboard("texte")).resolves.toBe(true);
    expect(execCommand).toHaveBeenCalledWith("copy");
  });
});
