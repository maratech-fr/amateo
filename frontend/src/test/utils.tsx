import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import { expect } from "vitest";
import type { ReactElement } from "react";
import { createMemoryRouter, RouterProvider } from "react-router";
import { axe } from "vitest-axe";

/**
 * Render a component with the providers pages depend on (Query + Router).
 * DATA router (createMemoryRouter), like the app (createBrowserRouter): required
 * by useBlocker (wizard period-abandon guard) — a plain MemoryRouter would throw.
 */
export function renderWithProviders(ui: ReactElement, { route = "/" }: { route?: string } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const router = createMemoryRouter([{ path: "*", element: ui }], { initialEntries: [route] });
  return render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

/**
 * Les éléments qui montrent `text` à l'écran, sans survol : ni un `title` (jamais lu par getByText)
 * ni le `sr-only` d'un `disabledReason` ne comptent. Pour la règle « un geste grisé dit son motif en
 * clair » : `getByText` seul serait vert sur le span masqué que rend `Button`.
 */
export function visibleTextOccurrences(text: string): HTMLElement[] {
  return screen.queryAllByText(text).filter((element) => null === element.closest(".sr-only"));
}

/**
 * WCAG 2.2 AA guardrail: render `ui` with the app providers and assert axe-core
 * finds no violations. jsdom has no layout engine, so axe auto-skips the
 * contrast check here — colour-contrast (WCAG 1.4.3) is enforced separately by
 * the Playwright/axe pass (PR2). This covers the structural rules: roles, names,
 * labels, aria validity, duplicate ids, keyboard semantics.
 */
export async function expectNoA11yViolations(ui: ReactElement, opts?: { route?: string }): Promise<void> {
  const { container } = renderWithProviders(ui, opts);
  expect(await axe(container)).toHaveNoViolations();
}
