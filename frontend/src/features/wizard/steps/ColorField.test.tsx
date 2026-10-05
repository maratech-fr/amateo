import { fireEvent, render } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { Venue } from "../api";
import { ColorField } from "./VenuesStep";

const venue = (color: string | null): Venue => ({ id: "v1", name: "A", color, canSplit: false, isActive: true }) as Venue;

describe("ColorField — debounced colour write", () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it("debounces rapid changes into ONE onApply with the last colour (no @Version race)", () => {
    const onApply = vi.fn();
    const { getByLabelText } = render(<ColorField venue={venue("#111111")} onApply={onApply} />);
    const hex = getByLabelText("Couleur (code)");

    for (const c of ["#aa0000", "#bb0000", "#00cc00"]) {
      fireEvent.change(hex, { target: { value: c } });
    }
    expect(onApply).not.toHaveBeenCalled(); // nothing written mid-drag

    vi.advanceTimersByTime(300);
    expect(onApply).toHaveBeenCalledTimes(1);
    expect(onApply).toHaveBeenCalledWith("#00cc00");
  });

  it("flushes the pending colour on unmount so a last-second edit is never dropped", () => {
    const onApply = vi.fn();
    const { getByLabelText, unmount } = render(<ColorField venue={venue("#111111")} onApply={onApply} />);
    fireEvent.change(getByLabelText("Couleur (code)"), { target: { value: "#00ccff" } });
    expect(onApply).not.toHaveBeenCalled();

    unmount(); // leave the step within the 300ms debounce window
    expect(onApply).toHaveBeenCalledWith("#00ccff");
  });

  it("ignores an invalid hex (no write)", () => {
    const onApply = vi.fn();
    const { getByLabelText } = render(<ColorField venue={venue("#111111")} onApply={onApply} />);
    fireEvent.change(getByLabelText("Couleur (code)"), { target: { value: "#12" } });
    vi.advanceTimersByTime(300);
    expect(onApply).not.toHaveBeenCalled();
  });
});
