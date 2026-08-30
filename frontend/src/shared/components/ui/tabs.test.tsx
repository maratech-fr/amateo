import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useEffect } from "react";
import { useSearchParams } from "react-router";
import { beforeEach, describe, expect, it } from "vitest";

import { renderWithProviders, expectNoA11yViolations } from "@/test/utils";

import { ADMIN_TABS, DEFAULT_SUBTAB, DEFAULT_TAB, STORAGE_KEY, resolveActiveSubTab, resolveActiveTab } from "@/features/admin/tabs/tabsConfig";
import { TabPanel, Tabs } from "./tabs";

const MAIN_TAB_IDS = ADMIN_TABS.map((t) => t.id);
const JOURNAUX_SUBTABS = ADMIN_TABS.find((t) => t.id === "journaux")?.subTabs ?? [];

/**
 * Test harness mirroring AdminDashboardPage's tab-state wiring (useSearchParams +
 * localStorage) without the admin query hooks — isolates Tabs + URL + persistence.
 */
function TabHarness() {
  const [searchParams, setSearchParams] = useSearchParams();
  const activeTab = resolveActiveTab(searchParams.get("tab"), localStorage.getItem(STORAGE_KEY));
  const activeSubTab = resolveActiveSubTab(activeTab === "journaux" ? searchParams.get("sub") : null);

  useEffect(() => {
    localStorage.setItem(STORAGE_KEY, activeTab);
  }, [activeTab]);

  function handleTabChange(id: string) {
    setSearchParams((prev) => {
      prev.set("tab", id);
      if (id === "journaux") prev.set("sub", DEFAULT_SUBTAB);
      else prev.delete("sub");
      return prev;
    });
  }

  function handleSubTabChange(id: string) {
    setSearchParams((prev) => {
      prev.set("sub", id);
      return prev;
    });
  }

  return (
    <div>
      <Tabs variant="console" tabs={ADMIN_TABS} activeTab={activeTab} onTabChange={handleTabChange} ariaLabel="Sections admin" idPrefix="admin" />
      {MAIN_TAB_IDS.map((tabId) => (
        <TabPanel variant="console" key={tabId} tabId={tabId} idPrefix="admin" active={activeTab === tabId}>
          {tabId === "journaux" ? (
            <div>
              <Tabs
                variant="console"
                tabs={JOURNAUX_SUBTABS.map((s) => ({ id: s.id, label: s.label }))}
                activeTab={activeSubTab}
                onTabChange={handleSubTabChange}
                ariaLabel="Journaux"
                idPrefix="admin-journaux"
              />
              {JOURNAUX_SUBTABS.map((sub) => (
                <TabPanel variant="console" key={sub.id} tabId={sub.id} idPrefix="admin-journaux" active={activeSubTab === sub.id}>
                  <p>{sub.label} content</p>
                </TabPanel>
              ))}
            </div>
          ) : (
            <p>{tabId} content</p>
          )}
        </TabPanel>
      ))}
    </div>
  );
}

function renderHarness(initialRoute = `?tab=${DEFAULT_TAB}`) {
  return renderWithProviders(<TabHarness />, { route: `/admin?${initialRoute.replace("?", "")}` });
}

describe("Tabs", () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it("renders 7 tab buttons with correct ARIA roles", () => {
    renderHarness();
    const tablist = screen.getByRole("tablist", { name: "Sections admin" });
    expect(tablist).toBeInTheDocument();
    const tabs = screen.getAllByRole("tab");
    expect(tabs).toHaveLength(7);
    expect(screen.getByRole("tab", { name: /Vue d'ensemble/ })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: /Signalements/ })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: /Journaux/ })).toBeInTheDocument();
  });

  it("click changes the active tab and shows the corresponding panel", async () => {
    renderHarness();
    expect(screen.getByText("vue-densemble content")).toBeVisible();
    expect(screen.getByText("jobs content")).not.toBeVisible();

    await userEvent.click(screen.getByRole("tab", { name: /Jobs/ }));
    expect(screen.getByText("jobs content")).toBeVisible();
    expect(screen.getByText("vue-densemble content")).not.toBeVisible();
  });

  it("ArrowRight moves focus and activates the next tab (circular)", async () => {
    const user = userEvent.setup();
    renderHarness();
    const firstTab = screen.getByRole("tab", { name: /Vue d'ensemble/ });
    firstTab.focus();
    expect(firstTab).toHaveFocus();

    await user.keyboard("{ArrowRight}");
    const secondTab = screen.getByRole("tab", { name: /Infrastructure/ });
    expect(secondTab).toHaveFocus();
    expect(secondTab).toHaveAttribute("aria-selected", "true");
    expect(screen.getByText("infrastructure content")).toBeVisible();
  });

  it("ArrowLeft wraps from first to last tab", async () => {
    const user = userEvent.setup();
    renderHarness();
    const firstTab = screen.getByRole("tab", { name: /Vue d'ensemble/ });
    firstTab.focus();

    await user.keyboard("{ArrowLeft}");
    const lastTab = screen.getByRole("tab", { name: /Journaux/ });
    expect(lastTab).toHaveFocus();
    expect(lastTab).toHaveAttribute("aria-selected", "true");
  });

  it("Home moves to the first tab, End to the last", async () => {
    const user = userEvent.setup();
    renderHarness();
    const jobsTab = screen.getByRole("tab", { name: /Jobs/ });
    jobsTab.focus();

    await user.keyboard("{End}");
    expect(screen.getByRole("tab", { name: /Journaux/ })).toHaveFocus();

    await user.keyboard("{Home}");
    expect(screen.getByRole("tab", { name: /Vue d'ensemble/ })).toHaveFocus();
  });

  it("Tab key exits the tablist to the active panel", async () => {
    const user = userEvent.setup();
    renderHarness();
    const firstTab = screen.getByRole("tab", { name: /Vue d'ensemble/ });
    firstTab.focus();

    await user.tab();
    const panel = screen.getByRole("tabpanel", { name: /Vue d'ensemble/ });
    expect(panel).toHaveFocus();
  });

  it("roving tabindex: only the active tab has tabIndex=0", () => {
    renderHarness();
    const tabs = screen.getAllByRole("tab");
    const active = tabs.filter((t) => t.getAttribute("tabindex") === "0");
    expect(active).toHaveLength(1);
    expect(active[0]).toHaveTextContent("Vue d'ensemble");
  });

  it("URL ?tab=jobs renders the Jobs panel as active", () => {
    renderHarness("tab=jobs");
    expect(screen.getByRole("tab", { name: /Jobs/ })).toHaveAttribute("aria-selected", "true");
    expect(screen.getByText("jobs content")).toBeVisible();
    expect(screen.getByText("vue-densemble content")).not.toBeVisible();
  });

  it("invalid tab param falls back to the first tab", () => {
    renderHarness("tab=nonexistent");
    expect(screen.getByRole("tab", { name: /Vue d'ensemble/ })).toHaveAttribute("aria-selected", "true");
    expect(screen.getByText("vue-densemble content")).toBeVisible();
  });

  it("localStorage restores the last active tab when no URL param is present", () => {
    localStorage.setItem(STORAGE_KEY, "jobs");
    renderHarness("");
    expect(screen.getByRole("tab", { name: /Jobs/ })).toHaveAttribute("aria-selected", "true");
    expect(screen.getByText("jobs content")).toBeVisible();
  });

  it("changing tab persists to localStorage", async () => {
    renderHarness();
    expect(localStorage.getItem(STORAGE_KEY)).toBe(DEFAULT_TAB);

    await userEvent.click(screen.getByRole("tab", { name: /Référentiels/ }));
    expect(localStorage.getItem(STORAGE_KEY)).toBe("referentiels");
  });

  it("Journaux sub-tabs render a nested tablist and switch on click", async () => {
    renderHarness("tab=journaux");
    const subTablist = screen.getByRole("tablist", { name: "Journaux" });
    expect(subTablist).toBeInTheDocument();
    const subTabs = screen.getAllByRole("tab", { name: /Audit|Échecs async|Erreurs système/ });
    expect(subTabs).toHaveLength(3);

    await userEvent.click(screen.getByRole("tab", { name: /Erreurs système/ }));
    expect(screen.getByText("Erreurs système content")).toBeVisible();
    expect(screen.getByText("Audit content")).not.toBeVisible();
  });

  it("has no axe accessibility violations", async () => {
    await expectNoA11yViolations(<TabHarness />, { route: `/admin?tab=${DEFAULT_TAB}` });
  });

  // Le harnais reproduit AdminDashboardPage, qui passe `variant="console"` partout : sans
  // ça (revue #346), la seule suite d'onglets du dépôt exerçait une peau qu'aucune page ne
  // rend, et un `variant` oublié sur un site d'appel — ce qui venait d'arriver aux
  // sous-onglets Journaux — passait la CI en vert.
  it("garde la peau console sur les onglets de l'admin", () => {
    renderHarness();

    const active = screen.getByRole("tab", { name: /Vue d'ensemble/ });
    expect(active.className).toContain("border-console-accent");
    expect(active.className).toContain("text-white");
    expect(active.className).not.toContain("border-accent");
  });
});
