import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ReactElement } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { ImplicitRuleSetting } from "../api";
import * as wizardApi from "../api";
import { WELLBEING_RULES } from "../lib/implicitRules";
import { WellbeingRulesPanel } from "./ImplicitRulesPanel";

vi.mock("../api", () => ({
  listImplicitRuleSettings: vi.fn(),
  updateImplicitRuleSetting: vi.fn(() => Promise.resolve({})),
  resetImplicitRuleSetting: vi.fn(() => Promise.resolve()),
}));

const workingSeason = vi.hoisted(() => ({ value: { isReadonly: false } as { isReadonly: boolean } | null }));
vi.mock("@/shared/session/queries", () => ({ useWorkingSeason: () => workingSeason.value }));

const RESOLVED = (over: Partial<Record<ImplicitRuleSetting["ruleKey"], Partial<ImplicitRuleSetting>>> = {}): ImplicitRuleSetting[] =>
  [
    { ruleKey: "coachRestDay", intensity: "HARD", minRestDays: 1, maxConsecutive: null, maxConsecutiveDays: null, isDefault: true },
    { ruleKey: "salarieDistribution", intensity: "HARD", minRestDays: null, maxConsecutive: null, maxConsecutiveDays: null, isDefault: true },
    { ruleKey: "maxConsecutiveSessions", intensity: "HARD", minRestDays: null, maxConsecutive: 3, maxConsecutiveDays: null, isDefault: true },
    { ruleKey: "ageAscending", intensity: "HARD", minRestDays: null, maxConsecutive: null, maxConsecutiveDays: null, isDefault: true },
  ].map((s) => ({ ...s, ...over[s.ruleKey as ImplicitRuleSetting["ruleKey"]] }) as ImplicitRuleSetting);

function renderPanel(ui: ReactElement) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>);
}

const REST_TITLE = "Chaque coach garde un jour de repos";
const CONSEC_TITLE = "Jamais trop de créneaux dos-à-dos";

/**
 * P2-28 — les règles bien-être RÉGLABLES. « Muter la PROD, pas le mock » : on mocke la couche
 * réseau (`../api`) et on garde les vrais hooks react-query, si bien que l'invalidation se PROUVE
 * par un refetch (le GET rappelé), pas par une assertion sur un double.
 */
describe("ImplicitRulesPanel — règles de bien-être réglables", () => {
  beforeEach(() => {
    vi.mocked(wizardApi.listImplicitRuleSettings).mockResolvedValue(RESOLVED());
    vi.mocked(wizardApi.updateImplicitRuleSetting).mockClear().mockResolvedValue({} as ImplicitRuleSetting);
    vi.mocked(wizardApi.resetImplicitRuleSetting).mockClear().mockResolvedValue(undefined);
    workingSeason.value = { isReadonly: false };
  });
  afterEach(() => vi.mocked(wizardApi.listImplicitRuleSettings).mockReset());

  it("rend les règles réglables une fois la collection résolue", async () => {
    renderPanel(<WellbeingRulesPanel />);

    expect(await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` })).toBeInTheDocument();
    for (const title of [REST_TITLE, CONSEC_TITLE, "Les jeunes avant les grands"]) {
      expect(screen.getByRole("group", { name: `Intensité — ${title}` })).toBeInTheDocument();
    }
  });

  it("« Objectif » PUT PREFERRED en préservant le seuil, puis refetch (invalidation)", async () => {
    const user = userEvent.setup();
    renderPanel(<WellbeingRulesPanel />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    await user.click(screen.getByRole("button", { name: `Objectif — ${REST_TITLE}` }));

    await waitFor(() => expect(wizardApi.updateImplicitRuleSetting).toHaveBeenCalledWith("coachRestDay", { intensity: "PREFERRED", minRestDays: 1 }));
    // L'effet de l'invalidation : le GET est rappelé (au moins 2 fois).
    await waitFor(() => expect(vi.mocked(wizardApi.listImplicitRuleSettings).mock.calls.length).toBeGreaterThanOrEqual(2));
  });

  it("changer le seuil PUT la nouvelle valeur avec l'intensité courante", async () => {
    const user = userEvent.setup();
    renderPanel(<WellbeingRulesPanel />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    await user.selectOptions(screen.getByRole("combobox", { name: `Jours de repos minimum — ${REST_TITLE}` }), "3");

    await waitFor(() => expect(wizardApi.updateImplicitRuleSetting).toHaveBeenCalledWith("coachRestDay", { intensity: "HARD", minRestDays: 3 }));
  });

  it("le seuil est BORNÉ : repos 1-4, consécutifs 2-6 (le sélecteur n'offre que ça)", async () => {
    renderPanel(<WellbeingRulesPanel />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    const rest = screen.getByRole("combobox", { name: `Jours de repos minimum — ${REST_TITLE}` });
    expect(Array.from(rest.querySelectorAll("option")).map((o) => o.textContent)).toEqual(["1", "2", "3", "4"]);

    const consec = screen.getByRole("combobox", { name: `Créneaux consécutifs maximum — ${CONSEC_TITLE}` });
    expect(Array.from(consec.querySelectorAll("option")).map((o) => o.textContent)).toEqual(["2", "3", "4", "5", "6"]);
  });

  it("« Réinitialiser » n'apparaît qu'hors défaut et DELETE la règle, puis refetch", async () => {
    vi.mocked(wizardApi.listImplicitRuleSettings).mockResolvedValue(RESOLVED({ coachRestDay: { isDefault: false, intensity: "PREFERRED" } }));
    const user = userEvent.setup();
    renderPanel(<WellbeingRulesPanel />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    // Une seule règle hors défaut → un seul bouton « Réinitialiser ».
    const resetButtons = screen.getAllByRole("button", { name: "Réinitialiser" });
    expect(resetButtons).toHaveLength(1);

    await user.click(resetButtons[0]);
    await waitFor(() => expect(wizardApi.resetImplicitRuleSetting).toHaveBeenCalledWith("coachRestDay"));
    await waitFor(() => expect(vi.mocked(wizardApi.listImplicitRuleSettings).mock.calls.length).toBeGreaterThanOrEqual(2));
  });

  it("aucune règle hors défaut → aucun bouton « Réinitialiser »", async () => {
    renderPanel(<WellbeingRulesPanel />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });
    expect(screen.queryByRole("button", { name: "Réinitialiser" })).toBeNull();
  });

  it("saison archivée : intensité, seuil et réinitialiser sont DÉSACTIVÉS", async () => {
    workingSeason.value = { isReadonly: true };
    vi.mocked(wizardApi.listImplicitRuleSettings).mockResolvedValue(RESOLVED({ coachRestDay: { isDefault: false } }));
    renderPanel(<WellbeingRulesPanel />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    expect(screen.getByRole("button", { name: `Objectif — ${REST_TITLE}` })).toBeDisabled();
    expect(screen.getByRole("combobox", { name: `Jours de repos minimum — ${REST_TITLE}` })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Réinitialiser" })).toBeDisabled();
  });

  it("un échec de lecture le DIT (pas un panneau faussement vide)", async () => {
    vi.mocked(wizardApi.listImplicitRuleSettings).mockRejectedValue(new Error("réseau"));
    renderPanel(<WellbeingRulesPanel />);
    expect(await screen.findByText(/Impossible de lire les réglages des règles de bien-être/)).toBeInTheDocument();
  });
});

/**
 * P2-28 — l'atterrissage d'un diagnostic : `ruleTarget` surligne la règle et l'amène à l'écran
 * (patron `data-*` + `scrollIntoView` en `requestAnimationFrame`, comme P4-95). L'ouverture de
 * l'onglet Bien-être est gérée par `ConstraintsStep` ; ici on garde le surlignage + le scroll.
 */
describe("ImplicitRulesPanel — atterrissage ciblé (ruleTarget)", () => {
  let scrolled: Element[];
  let originalScroll: typeof Element.prototype.scrollIntoView;

  beforeEach(() => {
    vi.mocked(wizardApi.listImplicitRuleSettings).mockResolvedValue(RESOLVED());
    workingSeason.value = { isReadonly: false };
    scrolled = [];
    originalScroll = Element.prototype.scrollIntoView;
    Element.prototype.scrollIntoView = function (this: Element) {
      scrolled.push(this);
    };
  });
  afterEach(() => {
    Element.prototype.scrollIntoView = originalScroll;
    vi.mocked(wizardApi.listImplicitRuleSettings).mockReset();
  });

  it("surligne la règle ciblée et la scrolle (block center)", async () => {
    const raf = vi.spyOn(window, "requestAnimationFrame").mockImplementation((cb) => (cb(0), 0));
    const { container } = renderPanel(<WellbeingRulesPanel ruleTarget="maxConsecutiveSessions" />);
    await screen.findByRole("group", { name: `Intensité — ${CONSEC_TITLE}` });

    const row = container.querySelector('[data-rule-key="maxConsecutiveSessions"]');
    expect(row?.className).toContain("ring-accent");
    expect(scrolled).toContain(row);
    raf.mockRestore();
  });

  it("ruleTarget inconnu → rien de surligné, aucun scroll, aucun crash", async () => {
    const { container } = renderPanel(<WellbeingRulesPanel ruleTarget="pasUneRegle" />);
    await screen.findByRole("group", { name: `Intensité — ${CONSEC_TITLE}` });

    expect(container.querySelector('[data-rule-key="maxConsecutiveSessions"]')?.className).not.toContain("ring-accent");
    expect(scrolled).toEqual([]);
  });
});

/**
 * PR2 — « les règles bien-être réglables PAR PÉRIODE ». En période, le MÊME panneau porte sur la
 * COPIE du plan (`schedulePlanId`) : la portée entre dans le GET, le PUT (corps) et le DELETE, et
 * chaque portée a sa propre clé de cache. En repère, la valeur de la SAISON s'affiche à côté de
 * chaque règle. Hors période : rien de tout ça (non-régression du panneau saison).
 *
 * SEASON et PERIOD divergent volontairement sur coachRestDay : c'est ce qui rend observable qu'on
 * lit la bonne couche (et que les deux caches ne se contaminent pas).
 */
const SEASON = (): ImplicitRuleSetting[] => [
  { ruleKey: "coachRestDay", intensity: "PREFERRED", minRestDays: 2, maxConsecutive: null, maxConsecutiveDays: null, isDefault: true },
  { ruleKey: "salarieDistribution", intensity: "HARD", minRestDays: null, maxConsecutive: null, maxConsecutiveDays: null, isDefault: true },
  { ruleKey: "maxConsecutiveSessions", intensity: "HARD", minRestDays: null, maxConsecutive: 3, maxConsecutiveDays: null, isDefault: true },
  { ruleKey: "ageAscending", intensity: "HARD", minRestDays: null, maxConsecutive: null, maxConsecutiveDays: null, isDefault: true },
];
const PERIOD = (): ImplicitRuleSetting[] => [
  { ruleKey: "coachRestDay", intensity: "HARD", minRestDays: 4, maxConsecutive: null, maxConsecutiveDays: null, isDefault: false },
  { ruleKey: "salarieDistribution", intensity: "HARD", minRestDays: null, maxConsecutive: null, maxConsecutiveDays: null, isDefault: true },
  { ruleKey: "maxConsecutiveSessions", intensity: "HARD", minRestDays: null, maxConsecutive: 3, maxConsecutiveDays: null, isDefault: true },
  { ruleKey: "ageAscending", intensity: "HARD", minRestDays: null, maxConsecutive: null, maxConsecutiveDays: null, isDefault: true },
];

describe("ImplicitRulesPanel — portée par période", () => {
  beforeEach(() => {
    // Le mock HONORE la portée : rows du plan pour un uuid, socle saison pour null (le repère).
    vi.mocked(wizardApi.listImplicitRuleSettings).mockImplementation((planId?: string | null) => Promise.resolve(planId ? PERIOD() : SEASON()));
    vi.mocked(wizardApi.updateImplicitRuleSetting).mockClear().mockResolvedValue({} as ImplicitRuleSetting);
    vi.mocked(wizardApi.resetImplicitRuleSetting).mockClear().mockResolvedValue(undefined);
    workingSeason.value = { isReadonly: false };
  });
  afterEach(() => vi.mocked(wizardApi.listImplicitRuleSettings).mockReset());

  it("mode période : phrase de portée + repère « Saison : … » par règle", async () => {
    renderPanel(<WellbeingRulesPanel schedulePlanId="plan-1" />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    expect(screen.getByText(/Ces réglages ne valent que pour cette période/)).toBeInTheDocument();
    // La règle affiche l'état de la PÉRIODE (HARD → « Obligatoire » enfoncé)…
    expect(screen.getByRole("button", { name: `Obligatoire — ${REST_TITLE}` })).toHaveAttribute("aria-pressed", "true");
    // …et en repère la valeur de la SAISON (PREFERRED, 2 jours), jamais celle de la période.
    expect(screen.getByText(/Saison : Objectif, 2 jours/)).toBeInTheDocument();
  });

  it("mode saison (schedulePlanId absent) : NI phrase de portée NI repère « Saison : … »", async () => {
    renderPanel(<WellbeingRulesPanel />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    expect(screen.queryByText(/Ces réglages ne valent que pour cette période/)).toBeNull();
    expect(screen.queryByText(/^Saison :/)).toBeNull();
  });

  it("le GET part avec la portée du plan", async () => {
    renderPanel(<WellbeingRulesPanel schedulePlanId="plan-1" />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    await waitFor(() => expect(vi.mocked(wizardApi.listImplicitRuleSettings).mock.calls.some((c) => "plan-1" === c[0])).toBe(true));
  });

  it("le PUT porte schedulePlanId dans le corps + refetch de la portée", async () => {
    const user = userEvent.setup();
    renderPanel(<WellbeingRulesPanel schedulePlanId="plan-1" />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    await user.click(screen.getByRole("button", { name: `Objectif — ${REST_TITLE}` }));

    await waitFor(() => expect(wizardApi.updateImplicitRuleSetting).toHaveBeenCalledWith("coachRestDay", { intensity: "PREFERRED", minRestDays: 4, schedulePlanId: "plan-1" }));
    await waitFor(() => expect(vi.mocked(wizardApi.listImplicitRuleSettings).mock.calls.filter((c) => "plan-1" === c[0]).length).toBeGreaterThanOrEqual(2));
  });

  it("le DELETE (Réinitialiser) part avec la portée du plan", async () => {
    const user = userEvent.setup();
    renderPanel(<WellbeingRulesPanel schedulePlanId="plan-1" />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    // coachRestDay est hors défaut côté période → « Réinitialiser » visible.
    await user.click(screen.getAllByRole("button", { name: "Réinitialiser" })[0]);
    await waitFor(() => expect(wizardApi.resetImplicitRuleSetting).toHaveBeenCalledWith("coachRestDay", "plan-1"));
  });

  it("les deux portées ne partagent PAS leur cache (saison ↔ période)", async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    const season = render(
      <QueryClientProvider client={client}>
        <WellbeingRulesPanel schedulePlanId={null} />
      </QueryClientProvider>,
    );
    const period = render(
      <QueryClientProvider client={client}>
        <WellbeingRulesPanel schedulePlanId="plan-1" />
      </QueryClientProvider>,
    );

    await within(season.container).findByRole("group", { name: `Intensité — ${REST_TITLE}` });
    await within(period.container).findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    // Chaque écran affiche SA couche : saison → PREFERRED (Objectif), période → HARD (Obligatoire).
    // Un cache partagé ferait afficher à l'un les valeurs de l'autre.
    expect(within(season.container).getByRole("button", { name: `Objectif — ${REST_TITLE}` })).toHaveAttribute("aria-pressed", "true");
    expect(within(period.container).getByRole("button", { name: `Obligatoire — ${REST_TITLE}` })).toHaveAttribute("aria-pressed", "true");
  });
});

/**
 * La règle des salariés est toujours un objectif pour le moteur (retours du 31/10) : l'écran n'offre
 * aucun réglage sans effet, quel que soit ce qui est stocké. ROUGE option A, contrat
 * `shared/lib/implicitRules.ts::isAlwaysObjective`.
 */
describe("ImplicitRulesPanel — une règle toujours objectif n'a aucun réglage", () => {
  const SALARIE_TITLE = WELLBEING_RULES.find((r) => "salarieDistribution" === r.ruleKey)?.title ?? "";

  beforeEach(() => {
    workingSeason.value = { isReadonly: false };
  });
  afterEach(() => vi.mocked(wizardApi.listImplicitRuleSettings).mockReset());

  it("la règle reste présentée, sans crans ni « Réinitialiser », même avec un réglage stocké hors défaut", async () => {
    vi.mocked(wizardApi.listImplicitRuleSettings).mockResolvedValue(RESOLVED({ salarieDistribution: { intensity: "PREFERRED", isDefault: false } }));
    renderPanel(<WellbeingRulesPanel />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    expect(screen.getByText(SALARIE_TITLE)).toBeInTheDocument();
    expect(screen.queryByRole("group", { name: `Intensité — ${SALARIE_TITLE}` })).toBeNull();
    expect(screen.queryByRole("button", { name: "Réinitialiser" })).toBeNull();
  });

  it("en période, elle ne porte pas de repère « Saison : », les autres règles le gardent", async () => {
    vi.mocked(wizardApi.listImplicitRuleSettings).mockImplementation((plan?: string | null) =>
      Promise.resolve("plan-1" === plan ? RESOLVED({ salarieDistribution: { intensity: "PREFERRED", isDefault: false } }) : RESOLVED()),
    );
    renderPanel(<WellbeingRulesPanel schedulePlanId="plan-1" />);
    await screen.findByRole("group", { name: `Intensité — ${REST_TITLE}` });

    await waitFor(() => expect(screen.getAllByText(/^Saison :/)).toHaveLength(RESOLVED().length - 1));
  });
});
