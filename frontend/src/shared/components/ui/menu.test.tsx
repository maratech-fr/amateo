import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { Menu, MenuItem } from "./menu";

function setup(onSelect = vi.fn()) {
  render(
    <Menu label="Menu du compte" trigger={<span>burger</span>}>
      <MenuItem onSelect={onSelect}>Se déconnecter</MenuItem>
    </Menu>,
  );
  return onSelect;
}

describe("Menu", () => {
  it("is closed initially and opens on trigger click", async () => {
    const user = userEvent.setup();
    setup();
    const trigger = screen.getByLabelText("Menu du compte");
    expect(trigger).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByRole("menu")).toBeNull();
    await user.click(trigger);
    expect(trigger).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByRole("menu")).toBeInTheDocument();
  });

  it("closes on Escape", async () => {
    const user = userEvent.setup();
    setup();
    await user.click(screen.getByLabelText("Menu du compte"));
    await user.keyboard("{Escape}");
    expect(screen.queryByRole("menu")).toBeNull();
  });

  it("closes on an outside click", async () => {
    const user = userEvent.setup();
    render(
      <div>
        <button type="button">outside</button>
        <Menu label="Menu du compte" trigger={<span>burger</span>}>
          <MenuItem>Item</MenuItem>
        </Menu>
      </div>,
    );
    await user.click(screen.getByLabelText("Menu du compte"));
    expect(screen.getByRole("menu")).toBeInTheDocument();
    await user.click(screen.getByText("outside"));
    expect(screen.queryByRole("menu")).toBeNull();
  });

  it("invokes the item's onSelect then closes when clicked", async () => {
    const user = userEvent.setup();
    const onSelect = setup();
    await user.click(screen.getByLabelText("Menu du compte"));
    await user.click(screen.getByRole("menuitem", { name: "Se déconnecter" }));
    expect(onSelect).toHaveBeenCalledOnce();
    expect(screen.queryByRole("menu")).toBeNull();
  });

  it("restores focus to the trigger after selecting an item (APG menu button)", async () => {
    const user = userEvent.setup();
    setup();
    const trigger = screen.getByLabelText("Menu du compte");
    await user.click(trigger);
    await user.click(screen.getByRole("menuitem", { name: "Se déconnecter" }));
    expect(trigger).toHaveFocus();
  });

  it("restoreFocusOnSelect={false} leaves focus off the trigger after a selection", async () => {
    const user = userEvent.setup();
    render(
      <Menu label="Menu du compte" trigger={<span>burger</span>} restoreFocusOnSelect={false}>
        <MenuItem>Se déconnecter</MenuItem>
      </Menu>,
    );
    const trigger = screen.getByLabelText("Menu du compte");
    await user.click(trigger);
    await user.click(screen.getByRole("menuitem", { name: "Se déconnecter" }));
    expect(trigger).not.toHaveFocus();
  });

  it("focuses the first item on open and roams with arrow keys", async () => {
    const user = userEvent.setup();
    render(
      <Menu label="Menu du compte" trigger={<span>burger</span>}>
        <MenuItem>Un</MenuItem>
        <MenuItem>Deux</MenuItem>
      </Menu>,
    );
    await user.click(screen.getByLabelText("Menu du compte"));
    expect(screen.getByRole("menuitem", { name: "Un" })).toHaveFocus();
    await user.keyboard("{ArrowDown}");
    expect(screen.getByRole("menuitem", { name: "Deux" })).toHaveFocus();
    await user.keyboard("{ArrowDown}"); // wraps
    expect(screen.getByRole("menuitem", { name: "Un" })).toHaveFocus();
  });

  it("restores focus to the trigger on Escape", async () => {
    const user = userEvent.setup();
    setup();
    const trigger = screen.getByLabelText("Menu du compte");
    await user.click(trigger);
    await user.keyboard("{Escape}");
    expect(screen.queryByRole("menu")).toBeNull();
    expect(trigger).toHaveFocus();
  });
});
