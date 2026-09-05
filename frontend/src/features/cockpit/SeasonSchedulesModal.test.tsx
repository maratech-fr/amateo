import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Schedule } from "@/features/planning/api";

const setSelectedScheduleId = vi.fn();
const jumpTo = vi.fn();
const startPeriodMode = vi.fn();
const exitPeriodMode = vi.fn();
const navigate = vi.fn();
const run = vi.fn();

// Plans (entryByPlan + lignes 0 version B1). p1/p2 sans `type` → pas de ligne
// parasite pour les tests existants ; un test dédié y injecte un plan HOLIDAY.
const DEFAULT_PLANS = [
  { id: "p1", calendarEntryId: "entry-1", chosenScheduleId: null },
  { id: "p2", calendarEntryId: "entry-2", chosenScheduleId: null },
];
let plansMock: { id: string; type?: string; name?: string; calendarEntryId: string | null; chosenScheduleId: string | null; staleness?: unknown }[] = DEFAULT_PLANS;
let seasonIsReadonly = false;

vi.mock("@/features/planning/store", () => ({ usePlanningStore: (sel: (s: unknown) => unknown) => sel({ setSelectedScheduleId }) }));
vi.mock("@/features/wizard/store", () => ({ useWizardStore: { getState: () => ({ jumpTo, startPeriodMode, exitPeriodMode }) } }));
vi.mock("@/features/planning/queries", () => ({ useScheduleExport: () => ({ run, busy: null }), useSchedules: () => ({ data: [] }) }));
vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { seasonPlan: { name: "Planning de la saison 2026-2027" } } }),
  useWorkingSeason: () => ({ id: "sn1", name: "2026-2027", startDate: "2026-08-01", endDate: "2027-07-31", isCurrent: true, isReadonly: seasonIsReadonly }),
}));
vi.mock("./queries", () => ({
  useSchedulePlans: () => ({ data: plansMock }),
  useDeleteEntry: () => ({ mutate: vi.fn(), isPending: false }),
  useSchedulePlanForEntry: () => ({ data: undefined }),
}));
vi.mock("react-router", async (orig) => ({ ...(await orig<typeof import("react-router")>()), useNavigate: () => navigate }));

import { SeasonSchedulesModal } from "./SeasonSchedulesModal";
import { seasonPlanCounts } from "./seasonPlannings";

beforeEach(() => {
  setSelectedScheduleId.mockClear();
  jumpTo.mockClear();
  startPeriodMode.mockClear();
  exitPeriodMode.mockClear();
  navigate.mockClear();
  run.mockClear();
  plansMock = DEFAULT_PLANS;
  seasonIsReadonly = false;
});

const plan = (over: Partial<Schedule>): Schedule => ({ id: "id", name: "Plan", status: "COMPLETED", score: null, createdAt: "2026-07-01T10:00:00+00:00", updatedAt: "", planType: "SEASON", schedulePlanId: "season-plan", ...over });

// A season with TWO versions of the main plan + one period overlay (2 versions).
const schedules = [
  plan({ id: "v1", createdAt: "2026-07-01T10:00:00+00:00" }),
  plan({ id: "v2", createdAt: "2026-07-02T10:00:00+00:00" }),
  plan({ id: "o1", name: "Vacances Toussaint", planType: "CLOSURE", schedulePlanId: "p1", createdAt: "2026-07-03T10:00:00+00:00" }),
  plan({ id: "o2", name: "Vacances Toussaint", planType: "CLOSURE", schedulePlanId: "p1", createdAt: "2026-07-04T10:00:00+00:00" }),
];

function open(list: Schedule[]) {
  return render(
    <MemoryRouter>
      <SeasonSchedulesModal schedules={list} onClose={vi.fn()} />
    </MemoryRouter>,
  );
}

describe("SeasonSchedulesModal — plannings, not versions", () => {
  it("counts distinct plannings: 1 season plan + 1 overlay = 2 (not 4 versions)", () => {
    expect(seasonPlanCounts(schedules)).toEqual({ total: 2, overlays: 1, openOverlays: 0 });
  });

  it("counts OPEN periods too (a mid-generation planning is still a planning — founder 2026-07-18)", () => {
    // One finished period (p1) + one period (p2) still mid-first-generation → BOTH listed.
    const withInFlight = [...schedules, plan({ id: "o3", name: "Vacances Noël", status: "GENERATING", planType: "CLOSURE", schedulePlanId: "p2", createdAt: "2026-07-05T10:00:00+00:00" })];
    // openOverlays distingue le planning EN COURS (bannière « (1 en cours) »).
    expect(seasonPlanCounts(withInFlight)).toEqual({ total: 3, overlays: 2, openOverlays: 1 });
  });

  it("P4-173 — shows the « à régénérer » pill on the row whose plan is stale, next to its state label", () => {
    plansMock = [
      { id: "season-plan", calendarEntryId: null, chosenScheduleId: "v1", staleness: { manuallyEdited: false, constraintsChanged: true, resourcesChanged: false } },
      { id: "p1", calendarEntryId: "entry-1", chosenScheduleId: null, staleness: null },
    ];
    open(schedules);
    expect(screen.getByText("À régénérer — une contrainte a changé")).toBeInTheDocument();
    // Une seule pastille : l'overlay (p1) porte staleness null → pas de doublon.
    expect(screen.getAllByText(/À régénérer/)).toHaveLength(1);
  });

  it("P4-173 — no pill when no plan carries staleness", () => {
    plansMock = DEFAULT_PLANS;
    open(schedules);
    expect(screen.queryByText(/À régénérer/)).not.toBeInTheDocument();
  });

  it("lists one row per PLANNING (principal + overlay), each with view + export", () => {
    open(schedules);
    // Le socle porte le NOM de son plan (me.seasonPlan.name), pas un libellé générique.
    expect(screen.getByText("Planning de la saison 2026-2027")).toBeInTheDocument();
    expect(screen.getByText("Vacances Toussaint")).toBeInTheDocument();
    // Each row offers a consult (eye) + an export — icon-only (aria-label), no visible "Exporter" text.
    expect(screen.getAllByRole("button", { name: /^Consulter/ })).toHaveLength(2);
    expect(screen.getAllByRole("button", { name: /^Exporter/ })).toHaveLength(2);
    expect(screen.queryByText("Exporter")).not.toBeInTheDocument();
  });

  it("an OPEN overlay (no finished version) is listed with « Reprendre » and no export", async () => {
    open([plan({ id: "o3", name: "Vacances Noël", status: "GENERATING", planType: "CLOSURE", schedulePlanId: "p1", createdAt: "2026-07-05T10:00:00+00:00" })]);
    expect(screen.getByText("Vacances Noël")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Exporter/ })).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: /^Reprendre/ }));
    expect(startPeriodMode).toHaveBeenCalledWith("entry-1");
    expect(navigate).toHaveBeenCalledWith("/wizard");
  });

  it("represents each planning by its latest FINISHED version, never a failed/in-flight one", () => {
    // v2 is FAILED and v3 GENERATING → the principal row must fall back to the finished v1.
    open([plan({ id: "v1", status: "COMPLETED", createdAt: "2026-07-01T10:00:00+00:00" }), plan({ id: "v2", status: "FAILED", createdAt: "2026-07-02T10:00:00+00:00" }), plan({ id: "v3", status: "GENERATING", createdAt: "2026-07-03T10:00:00+00:00" })]);
    // v1's COMPLETED-not-validated state (« Terminé · à valider »), not FAILED/GENERATING.
    expect(screen.getByText("Terminé · à valider")).toBeInTheDocument();
    expect(screen.queryByText("Échec")).not.toBeInTheDocument();
    expect(screen.queryByText(/Génération/)).not.toBeInTheDocument();
  });

  // NR (défaut 1, axe planning lifecycle) : une LIGNE est un PLAN — son état lu du PLAN,
  // pas du statut brut de la version. Un plan validé (pointé) et un plan terminé-non-validé,
  // tous deux COMPLETED, doivent se lire DIFFÉREMMENT — le socle validé n'était plus
  // discernable d'un overlay simplement terminé.
  it("distingues à l'état un plan VALIDÉ d'un plan terminé-non-validé (même statut COMPLETED)", () => {
    open([
      plan({ id: "v1", status: "COMPLETED", isChosen: true }), // socle en vigueur
      plan({ id: "o1", name: "Vacances Toussaint", status: "COMPLETED", isChosen: false, planType: "CLOSURE", schedulePlanId: "p1" }),
    ]);
    expect(screen.getByText("Validé")).toBeInTheDocument();
    expect(screen.getByText("Terminé · à valider")).toBeInTheDocument();
  });

  it("eye on the planning in force opens the planning page", async () => {
    open([plan({ id: "v1", status: "COMPLETED", isChosen: true })]);
    await userEvent.click(screen.getByRole("button", { name: /^Consulter/ }));
    expect(setSelectedScheduleId).toHaveBeenCalledWith("v1");
    expect(navigate).toHaveBeenCalledWith("/planning");
  });

  it("eye on an in-progress SEASON planning opens the wizard's generation step, resetting a stale period mode", async () => {
    open([plan({ id: "v1", status: "COMPLETED" })]);
    await userEvent.click(screen.getByRole("button", { name: /^Consulter/ }));
    // Un mode période persisté générerait le plan de PÉRIODE — reset obligatoire.
    expect(exitPeriodMode).toHaveBeenCalled();
    expect(jumpTo).toHaveBeenCalledWith("generate");
    expect(navigate).toHaveBeenCalledWith("/wizard");
  });

  it("eye on a VALIDATED (pointed) PERIOD overlay opens it on the planning page (read-only)", async () => {
    // Plan POINTÉ = une version en vigueur → consultation lecture seule sur /planning.
    open([plan({ id: "o1", name: "Vacances Toussaint", status: "COMPLETED", isChosen: true, planType: "CLOSURE", schedulePlanId: "p1" })]);
    await userEvent.click(screen.getByRole("button", { name: /^Consulter/ }));
    expect(setSelectedScheduleId).toHaveBeenCalledWith("o1");
    expect(navigate).toHaveBeenCalledWith("/planning");
    expect(startPeriodMode).not.toHaveBeenCalled();
  });

  it("eye on a terminé-NON-validé PERIOD overlay resumes it in the wizard's period mode (never /planning)", async () => {
    // ADR-0002 (défaut 2) : plan NON pointé → wizard, mode période DÉCLARÉ ancré sur SON
    // entrée + étape Génération, comme la branche socle. Un overlay terminé mais pas encore
    // validé se FINIT/valide au wizard, il ne se consulte pas en lecture seule.
    open([plan({ id: "o1", name: "Vacances Toussaint", status: "COMPLETED", isChosen: false, planType: "CLOSURE", schedulePlanId: "p1" })]);
    await userEvent.click(screen.getByRole("button", { name: /^Consulter/ }));
    expect(startPeriodMode).toHaveBeenCalledWith("entry-1"); // p1 → entry-1 (mock useSchedulePlans)
    expect(jumpTo).toHaveBeenCalledWith("generate");
    expect(navigate).toHaveBeenCalledWith("/wizard");
    expect(navigate).not.toHaveBeenCalledWith("/planning");
  });

  // B1 (retour fondateur 2026-07-19) : un plan de période créé mais SANS version
  // générée est listé « en cours » avec Reprendre (navigue en mode période).
  it("lists a period plan with ZERO version as an OPEN « Reprendre » row and resumes it in period mode", async () => {
    plansMock = [...DEFAULT_PLANS, { id: "pl-tou", type: "HOLIDAY", name: "Vacances Toussaint — S1", calendarEntryId: "entry-tou", chosenScheduleId: null }];
    // Aucune version pour pl-tou : le socle seul a des versions.
    open([plan({ id: "v1", status: "COMPLETED" })]);

    expect(screen.getByText("Vacances Toussaint — S1")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Reprendre Vacances Toussaint — S1" }));
    expect(startPeriodMode).toHaveBeenCalledWith("entry-tou");
    expect(navigate).toHaveBeenCalledWith("/wizard");
  });

  // B2 (retour fondateur 2026-07-19) : un planning SECONDAIRE est supprimable ici ;
  // le socle (planning principal) ne l'est JAMAIS.
  it("offers « Supprimer » on a period overlay row but never on the season row", () => {
    open([
      plan({ id: "v1", status: "COMPLETED" }), // socle
      plan({ id: "o1", name: "Vacances Toussaint", status: "COMPLETED", planType: "CLOSURE", schedulePlanId: "p1" }),
    ]);
    // p1 → entry-1 (mock useSchedulePlans) : la ligne overlay porte Supprimer.
    expect(screen.getByRole("button", { name: "Supprimer Vacances Toussaint" })).toBeInTheDocument();
    // Le socle (« Planning de la saison … ») n'a pas de Supprimer.
    expect(screen.queryByRole("button", { name: /^Supprimer Planning de la saison/ })).not.toBeInTheDocument();
  });

  // Revue B2 F2 : saison archivée (readonly) → pas de suppression (éviterait un 409).
  it("hides « Supprimer » on overlays in a read-only (archived) season", () => {
    seasonIsReadonly = true;
    open([plan({ id: "o1", name: "Vacances Toussaint", status: "COMPLETED", planType: "CLOSURE", schedulePlanId: "p1" })]);
    expect(screen.queryByRole("button", { name: /^Supprimer/ })).not.toBeInTheDocument();
  });

  // Revue B2 : une version en vol (GENERATING) ne doit pas être supprimable (la
  // cascade emporterait le solve en cours).
  it("hides « Supprimer » on an in-flight (GENERATING) overlay row", () => {
    open([plan({ id: "o1", name: "Vacances Toussaint", status: "GENERATING", planType: "CLOSURE", schedulePlanId: "p1" })]);
    expect(screen.queryByRole("button", { name: /^Supprimer/ })).not.toBeInTheDocument();
  });

  // AUD-FRT-23 — la sonde est passée de "menuitem" à "button" + le GROUPE nommé. L'intention du
  // test n'a pas bougé (le sélecteur se déplie, les 3 formats sont atteignables, PDF déclenche
  // l'export) : c'est sa façon de la mesurer qui était fausse. Ces boutons s'annonçaient
  // "menuitem" dans un role="menu" sans aucune navigation aux flèches — un menu ARIA promet ce
  // clavier-là. On ne promet plus ce qu'on n'implémente pas.
  it("export expands an inline format picker (PDF / Excel), no clipped dropdown", async () => {
    open([plan({ id: "v1", status: "COMPLETED" })]);
    expect(screen.queryByRole("button", { name: "PDF" })).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: /^Exporter/ }));
    expect(screen.getByRole("group", { name: /^Formats d'export/ })).toBeInTheDocument();
    expect(screen.queryByRole("menu")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "PDF" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Excel" })).toBeInTheDocument();
    // Le PNG a été RETIRÉ du produit le 2026-08-21 : il ne doit plus être proposé nulle part.
    expect(screen.queryByRole("button", { name: "PNG" })).toBeNull();
    await userEvent.click(screen.getByRole("button", { name: "PDF" }));
    expect(run).toHaveBeenCalledWith("pdf", null);
  });
});
