import { screen, within } from "@testing-library/react";
import type { UserEvent } from "@testing-library/user-event";

/**
 * Test helpers for the `Listbox` primitive (P4-164), so migrating a consumer test off the old
 * native `<select>` (`userEvent.selectOptions`) is mechanical.
 *
 * A listbox trigger is a `<button>` whose accessible name is "<label> <selected value>", and its
 * options exist in the DOM only while it is open. `label` is matched as a SUBSTRING of the trigger
 * name (a plain string is escaped into a contains-regex), so callers pass the label they used to
 * pass to `getByLabelText`.
 */
function nameMatcher(label: string | RegExp): RegExp {
  return typeof label === "string" ? new RegExp(label.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")) : label;
}

/** Open the listbox whose trigger name contains `label`, and return its `role="listbox"` element. */
export async function openListbox(user: UserEvent, label: string | RegExp): Promise<HTMLElement> {
  await user.click(screen.getByRole("button", { name: nameMatcher(label) }));
  return screen.getByRole("listbox");
}

/** Open the listbox `label` and click the option named `option` — the `selectOptions` replacement. */
export async function pickListboxOption(user: UserEvent, label: string | RegExp, option: string | RegExp): Promise<void> {
  const list = await openListbox(user, label);
  await user.click(within(list).getByRole("option", { name: option }));
}

/** The listbox trigger button whose name contains `label` (for disabled/value assertions). */
export function listboxTrigger(label: string | RegExp): HTMLElement {
  return screen.getByRole("button", { name: nameMatcher(label) });
}
