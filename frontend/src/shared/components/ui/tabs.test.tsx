import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useEffect } from "react";
import { useSearchParams } from "react-router";
import { beforeEach, describe, expect, it } from "vitest";

import { renderWithProviders, expectNoA11yViolations } from "@/test/utils";

import { ADMIN_TABS, DEFAULT_SUBTAB, DEFAULT_TAB, STORAGE_KEY, resolveActiveSubTab, resolveActiveTab } from "@/features/admin/tabs/tabsConfig";
import { TabPanel, Tabs } from "./tabs";

const MAIN_TAB_IDS = ADMIN_TABS.map((t) => t.id);
const INCIDENTS_SUBTABS = ADMIN_TABS.find((t) => t.id === "incidents")?.subTabs ?? [];

/**
 * Test harness mirroring AdminDashboardPage's tab-state wiring (useSearchParams +
 * localStorage) without the admin query hooks — isolates Tabs + URL + persistence.
 */
function TabHarness() {
  const [searchParams, setSearchParams] = useSearchParams();
  const activeTab = resolveActiveTab(searchParams.get("tab"), localStorage.getItem(STORAGE_KEY));
  const activeSubTab = resolveActiveSubTab(activeTab, activeTab === "incidents" ? searchParams.get("sub") : null);

  useEffect(() => {
    localStorage.setItem(STORAGE_KEY, activeTab);
  }, [activeTab]);

  function handleTabChange(id: string) {
    setSearchParams((prev) => {
      prev.set("tab", id);
      if (id === "incidents") prev.set("sub", DEFAULT_SUBTAB);
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
          {tabId === "incidents" ? (
            <div>
              <Tabs
                variant="console"
                tabs={INCIDENTS_SUBTABS.map((s) => ({ id: s.id, label: s.label }))}
                activeTab={activeSubTab}
                onTabChange={handleSubTabChange}
                ariaLabel="Incidents"
                idPrefix="admin-incidents"
              />
              {INCIDENTS_SUBTABS.map((sub) => (
                <TabPanel variant="console" key={sub.id} tabId={sub.id} idPrefix="admin-incidents" active={activeSubTab === sub.id}>
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

  it("renders 5 tab buttons with correct ARIA roles", () => {
    renderHarness();
    const tablist = screen.getByRole("tablist", { name: "Sections admin" });
    expect(tablist).toBeInTheDocument();
    const tabs = screen.getAllByRole("tab");
    expect(tabs).toHaveLength(5);
    expect(screen.getByRole("tab", { name: /Clubs/ })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: /Santé/ })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: /Incidents/ })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: /Exploitation/ })).toBeInTheDocument();
  });

  it("click changes the active tab and shows the corresponding panel", async () => {
    renderHarness();
    expect(screen.getByText("clubs content")).toBeVisible();
    expect(screen.getByText("sante content")).not.toBeVisible();

    await userEvent.click(screen.getByRole("tab", { name: /Santé/ }));
    expect(screen.getByText("sante content")).toBeVisible();
    expect(screen.getByText("clubs content")).not.toBeVisible();
  });

  it("ArrowRight moves focus and activates the next tab (circular)", async () => {
    const user = userEvent.setup();
    renderHarness();
    const firstTab = screen.getByRole("tab", { name: /Clubs/ });
    firstTab.focus();
    expect(firstTab).toHaveFocus();

    await user.keyboard("{ArrowRight}");
    const secondTab = screen.getByRole("tab", { name: /Statistiques/ });
    expect(secondTab).toHaveFocus();
    expect(secondTab).toHaveAttribute("aria-selected", "true");
    expect(screen.getByText("statistiques content")).toBeVisible();
  });

  it("ArrowLeft wraps from first to last tab", async () => {
    const user = userEvent.setup();
    renderHarness();
    const firstTab = screen.getByRole("tab", { name: /Clubs/ });
    firstTab.focus();

    await user.keyboard("{ArrowLeft}");
    const lastTab = screen.getByRole("tab", { name: /Exploitation/ });
    expect(lastTab).toHaveFocus();
    expect(lastTab).toHaveAttribute("aria-selected", "true");
  });

  it("Home moves to the first tab, End to the last", async () => {
    const user = userEvent.setup();
    renderHarness();
    const santeTab = screen.getByRole("tab", { name: /Santé/ });
    santeTab.focus();

    await user.keyboard("{End}");
    expect(screen.getByRole("tab", { name: /Exploitation/ })).toHaveFocus();

    await user.keyboard("{Home}");
    expect(screen.getByRole("tab", { name: /Clubs/ })).toHaveFocus();
  });

  it("Tab key exits the tablist to the active panel", async () => {
    const user = userEvent.setup();
    renderHarness();
    const firstTab = screen.getByRole("tab", { name: /Clubs/ });
    firstTab.focus();

    await user.tab();
    const panel = screen.getByRole("tabpanel", { name: /Clubs/ });
    expect(panel).toHaveFocus();
  });

  it("roving tabindex: only the active tab has tabIndex=0", () => {
    renderHarness();
    const tabs = screen.getAllByRole("tab");
    const active = tabs.filter((t) => t.getAttribute("tabindex") === "0");
    expect(active).toHaveLength(1);
    expect(active[0]).toHaveTextContent("Clubs");
  });

  it("URL ?tab=sante renders the Santé panel as active", () => {
    renderHarness("tab=sante");
    expect(screen.getByRole("tab", { name: /Santé/ })).toHaveAttribute("aria-selected", "true");
    expect(screen.getByText("sante content")).toBeVisible();
    expect(screen.getByText("clubs content")).not.toBeVisible();
  });

  it("invalid tab param falls back to the first tab", () => {
    renderHarness("tab=nonexistent");
    expect(screen.getByRole("tab", { name: /Clubs/ })).toHaveAttribute("aria-selected", "true");
    expect(screen.getByText("clubs content")).toBeVisible();
  });

  it("localStorage restores the last active tab when no URL param is present", () => {
    localStorage.setItem(STORAGE_KEY, "sante");
    renderHarness("");
    expect(screen.getByRole("tab", { name: /Santé/ })).toHaveAttribute("aria-selected", "true");
    expect(screen.getByText("sante content")).toBeVisible();
  });

  it("changing tab persists to localStorage", async () => {
    renderHarness();
    expect(localStorage.getItem(STORAGE_KEY)).toBe(DEFAULT_TAB);

    await userEvent.click(screen.getByRole("tab", { name: /Santé/ }));
    expect(localStorage.getItem(STORAGE_KEY)).toBe("sante");
  });

  it("Incidents sub-tabs render a nested tablist and switch on click", async () => {
    renderHarness("tab=incidents");
    const subTablist = screen.getByRole("tablist", { name: "Incidents" });
    expect(subTablist).toBeInTheDocument();
    const subTabs = screen.getAllByRole("tab", { name: /Signalements|Audit|Échecs async|Erreurs système/ });
    expect(subTabs).toHaveLength(4);

    await userEvent.click(screen.getByRole("tab", { name: /Erreurs système/ }));
    expect(screen.getByText("Erreurs système content")).toBeVisible();
    expect(screen.getByText("Signalements content")).not.toBeVisible();
  });

  it("has no axe accessibility violations", async () => {
    await expectNoA11yViolations(<TabHarness />, { route: `/admin?tab=${DEFAULT_TAB}` });
  });

  // A11Y-23 — un `aria-controls` ne doit JAMAIS pointer un panneau absent du DOM. La primitive
  // ne pose donc `aria-controls` que sur l'onglet ACTIF (attribut optionnel, APG). Ici un seul
  // panneau est rendu (le cas des consommateurs qui ne montent que l'actif : CoachWishesHub,
  // TypicalWeekendGrid) : chaque `aria-controls` présent doit résoudre vers un id existant.
  it("A11Y-23 — tout aria-controls d'onglet référence un panneau présent (seul l'actif le porte)", () => {
    render(
      <div>
        <Tabs
          tabs={[
            { id: "a", label: "A" },
            { id: "b", label: "B" },
          ]}
          activeTab="a"
          onTabChange={() => {}}
          ariaLabel="Deux onglets"
          idPrefix="solo"
        />
        <TabPanel tabId="a" idPrefix="solo" active>
          contenu A
        </TabPanel>
      </div>,
    );
    for (const tab of screen.getAllByRole("tab")) {
      const controls = tab.getAttribute("aria-controls");
      if (null !== controls) {
        expect(document.getElementById(controls), `aria-controls="${controls}" doit exister dans le DOM`).not.toBeNull();
      }
    }
  });

  // Le harnais reproduit AdminDashboardPage, qui passe `variant="console"` partout : sans
  // ça (revue #346), la seule suite d'onglets du dépôt exerçait une peau qu'aucune page ne
  // rend, et un `variant` oublié sur un site d'appel — ce qui venait d'arriver aux
  // sous-onglets Journaux — passait la CI en vert.
  it("garde la peau console sur les onglets de l'admin", () => {
    renderHarness();

    const active = screen.getByRole("tab", { name: /Clubs/ });
    expect(active.className).toContain("border-console-accent");
    expect(active.className).toContain("text-white");
    expect(active.className).not.toContain("border-accent");
  });

  // PR1 console superadmin — la pastille d'alerte d'un onglet est rendue par la primitive
  // PARTAGÉE, via la prop optionnelle `badge` sur l'item d'onglet : un point visuel
  // (aria-hidden, non annoncé deux fois) PLUS un nom accessible (sr-only) qui ENRICHIT le
  // nom de l'onglet, pour que « Santé — à vérifier » parvienne au lecteur d'écran.
  it("rend une pastille accessible quand l'item porte `badge`, et rien sans elle", () => {
    render(
      <Tabs
        variant="console"
        tabs={[
          { id: "sante", label: "Santé", badge: "à vérifier" },
          { id: "clubs", label: "Clubs" },
        ]}
        activeTab="clubs"
        onTabChange={() => {}}
        ariaLabel="Avec pastille"
        idPrefix="badge"
      />,
    );

    // L'onglet badgé enrichit son nom accessible ; l'onglet sans badge garde le sien nu.
    const sante = screen.getByRole("tab", { name: /Santé/ });
    expect(sante).toHaveAccessibleName(/à vérifier/i);
    const clubs = screen.getByRole("tab", { name: /Clubs/ });
    expect(clubs.textContent).not.toMatch(/à vérifier/i);
  });

  it("le marqueur visuel de la pastille est aria-hidden (jamais annoncé deux fois)", () => {
    const { container } = render(
      <Tabs
        variant="console"
        tabs={[{ id: "incidents", label: "Incidents", badge: "à vérifier" }]}
        activeTab="incidents"
        onTabChange={() => {}}
        ariaLabel="Pastille masquée"
        idPrefix="badge2"
      />,
    );

    // Le texte accessible « à vérifier » vit dans un nœud sr-only ; le POINT décoratif
    // qui l'accompagne est aria-hidden pour ne pas doubler l'annonce.
    const tab = screen.getByRole("tab", { name: /Incidents/ });
    expect(tab).toHaveAccessibleName(/à vérifier/i);
    expect(container.querySelector('[aria-hidden="true"]')).not.toBeNull();
  });

  // P4-265 — la peau `app` pose une BARRE OPAQUE (bg-card) sur le tablist pour ne plus laisser
  // traverser le fond à motifs ; la peau `console` garde son simple filet `border-b`, sans fond.
  it("peau app : barre opaque bg-card sur le tablist ; peau console : filet border-b sans fond", () => {
    const appTabs = [
      { id: "a", label: "A" },
      { id: "b", label: "B" },
    ];
    const { unmount } = render(<Tabs tabs={appTabs} activeTab="a" onTabChange={() => {}} ariaLabel="App" idPrefix="app" />);
    const appList = screen.getByRole("tablist", { name: "App" });
    expect(appList.className).toContain("bg-card");
    expect(appList.className).toContain("border-border");
    unmount();

    render(<Tabs variant="console" tabs={appTabs} activeTab="a" onTabChange={() => {}} ariaLabel="Console" idPrefix="con" />);
    const consoleList = screen.getByRole("tablist", { name: "Console" });
    expect(consoleList.className).not.toContain("bg-card");
    expect(consoleList.className).toContain("border-b");
  });
});
