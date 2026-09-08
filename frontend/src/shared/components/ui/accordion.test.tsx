import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { AccordionSection } from "./accordion";

describe("AccordionSection", () => {
  it("is collapsed by default (body hidden)", () => {
    render(
      <AccordionSection title="Visuel">
        <p>body content</p>
      </AccordionSection>,
    );
    expect(screen.getByRole("button", { name: /Visuel/ })).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByText("body content")).toBeNull();
  });

  it("renders the body when defaultOpen", () => {
    render(
      <AccordionSection title="Demandes" defaultOpen>
        <p>body content</p>
      </AccordionSection>,
    );
    expect(screen.getByRole("button", { name: /Demandes/ })).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByText("body content")).toBeInTheDocument();
  });

  it("toggles on header click", async () => {
    const user = userEvent.setup();
    render(
      <AccordionSection title="Visuel">
        <p>body content</p>
      </AccordionSection>,
    );
    const header = screen.getByRole("button", { name: /Visuel/ });
    await user.click(header);
    expect(screen.getByText("body content")).toBeInTheDocument();
    await user.click(header);
    expect(screen.queryByText("body content")).toBeNull();
  });

  // ── Controlled mode (opt-in, PR-3b — `?equipe=` mirrors the open section) ────
  it("controlled: reflects `open` and reports intent through `onToggle`, holding no state", async () => {
    const user = userEvent.setup();
    const onToggle = vi.fn();
    const { rerender } = render(
      <AccordionSection title="SM1" open={false} onToggle={onToggle}>
        <p>body content</p>
      </AccordionSection>,
    );
    const header = screen.getByRole("button", { name: /SM1/ });
    expect(header).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByText("body content")).toBeNull();

    // Clicking does NOT open on its own (controlled) — it asks the parent to.
    await user.click(header);
    expect(onToggle).toHaveBeenCalledWith(true);
    expect(screen.queryByText("body content")).toBeNull();

    // The parent flips `open` → the body appears.
    rerender(
      <AccordionSection title="SM1" open onToggle={onToggle}>
        <p>body content</p>
      </AccordionSection>,
    );
    expect(header).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByText("body content")).toBeInTheDocument();
  });
});
