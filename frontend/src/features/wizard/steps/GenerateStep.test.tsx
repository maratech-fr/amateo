import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

type StubSchedule = { id: string; status: string; createdAt: string; planType: string | null; schedulePlanId: string | null };

const h = {
  status: null as string | null,
  diagnostics: [] as unknown[],
  diagLoading: false,
  // Mode d'entrée du wizard + résolution de l'ancre de période (calendarEntryId → plan).
  mode: "season" as "season" | "period",
  entryId: null as string | null,
  planId: null as string | null,
  // P2-44 PR-4 — le TYPE de la période (l'auto-transcription ne vise QUE les fermetures) et le
  // rôle courant (miroir d'affichage : une écriture vouée au 403 chez un Membre ne part pas).
  entryPeriodType: undefined as string | undefined,
  role: "admin" as string,
  // P2-44 PR-4 — la liste des versions en vol (l'auto-transcription attend qu'elle soit chargée).
  schedulesLoading: false,
  // La liste des versions (bug fondateur 2026-08-19 — `showPlanning` en dérive en période).
  schedules: [] as StubSchedule[],
  // Les blocages du Récap, tels que la porte les lit.
  recapErrors: [] as string[],
  setSelected: vi.fn(),
  // AUD-FRT-09 — spy STABLE : le harnais en recréait un à chaque rendu, donc l'argument
  // du lancement était inobservable. On ne pouvait pas voir ce que l'écran demandait.
  launch: vi.fn().mockResolvedValue("sched-1"),
  // P2-44 — la transcription depuis le socle (spy stable, même raison que `launch`).
  transcribe: vi.fn().mockResolvedValue({ id: "sched-t", versionNumber: 1, copiedCount: 5, toReplace: [{ teamId: "t1", dayOfWeek: 3, startTime: "18:00:00", venueId: "v1", reason: "venue_closed" }] }),
};

vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: { club: { name: "BCCL" }, role: h.role } }) }));
vi.mock("@/features/cockpit/queries", () => ({
  useCalendarEntry: () => ({ data: null === h.entryId ? null : { id: h.entryId, periodType: h.entryPeriodType } }),
  usePeriodAnchor: () => ("period" === h.mode && null !== h.planId ? { state: "period", planId: h.planId } : { state: "base", planId: null }),
  anchorIsWritable: () => true,
}));
vi.mock("@/features/planning/queries", () => ({
  useSchedules: () => ({ data: h.schedules, isLoading: h.schedulesLoading }),
  useDiagnostics: () => ({ data: h.diagnostics, isLoading: h.diagLoading }),
}));
vi.mock("@/features/planning/store", () => ({ usePlanningStore: (sel: (s: { setSelectedScheduleId: (id: string | null) => void }) => unknown) => sel({ setSelectedScheduleId: h.setSelected }) }));
// Le stub RECORD la portée reçue (data-scope) : c'est le CÂBLAGE GenerateStep→PlanningPage
// qu'on épingle ici (que la portée passée == le plan de période). Le RENDU scopé lui-même
// — atterrissage, titre, toolbar — est gardé sur le composant RÉEL dans PlanningPage.test.tsx.
// Le stub RECORD aussi le nombre d'entrées « à replacer » reçues (data-toreplace) : c'est le
// CÂBLAGE GenerateStep→PlanningPage de la transcription (P2-44) qu'on épingle ici.
vi.mock("@/features/planning/PlanningPage", () => ({ PlanningPage: (props: { embedded?: boolean; scopePlanId?: string | null; calendarEntryId?: string | null; toReplace?: unknown[] | null }) => <div data-testid="planning" data-scope={props.scopePlanId ?? ""} data-entry={props.calendarEntryId ?? ""} data-toreplace={String((props.toReplace ?? []).length)} /> }));
vi.mock("@/features/planning/GenerationWaiting", () => ({ GenerationWaiting: () => <div data-testid="generation-waiting" /> }));
// P5-14 PR-2 — l'écran « le service de calcul ne répond pas » (écran B). Mocké pour épingler
// l'AIGUILLAGE (quand GenerateStep y route + quel scheduleId il lui passe pour la corrélation).
vi.mock("@/features/planning/GenerationServiceDown", () => ({ GenerationServiceDown: (props: { scheduleId?: string | null }) => <div data-testid="service-down" data-schedule={props.scheduleId ?? ""} /> }));
// P2-44 — errorMessage mocké pour un motif SERVI observable (le 409 socle non pointé s'affiche).
vi.mock("@/shared/lib/errorMessage", () => ({ errorMessage: async (e: unknown) => (e as { reason?: string }).reason ?? "Une erreur est survenue" }));
vi.mock("../lib/useStepValidation", () => ({ useStepValidation: () => ({ errors: h.recapErrors, warnings: [], pending: false }) }));
vi.mock("../store", () => ({ useWizardStore: () => ({ mode: h.mode, calendarEntryId: h.entryId }) }));
vi.mock("../queries", () => ({
  // Le lancement rend un id → le composant poll useScheduleStatus, que le
  // harnais pilote via h.status (FAILED = échec de SOLVE).
  useLaunchGeneration: () => ({ isPending: false, isError: false, mutateAsync: h.launch, reset: vi.fn() }),
  useScheduleStatus: () => ({ data: null === h.status ? undefined : { status: h.status } }),
  // P2-44 — la transcription depuis le socle.
  useTranscribeFromSocle: () => ({ isPending: false, mutateAsync: h.transcribe, reset: vi.fn() }),
}));

import { WIZARD_TEXTS } from "../texts";
import { GenerateStep } from "./GenerateStep";

function renderStep() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <GenerateStep />
    </QueryClientProvider>,
  );
}

// Reset GLOBAL du harnais : la période / le plan / les versions ne doivent pas fuir d'un
// test à l'autre (les describes historiques posent leurs propres resets — celui-ci couvre
// les champs qu'ils ne touchent pas, pour tout cas d'ordre d'exécution).
beforeEach(() => {
  h.status = null;
  h.recapErrors = [];
  h.diagnostics = [];
  h.diagLoading = false;
  h.mode = "season";
  h.entryId = null;
  h.planId = null;
  h.entryPeriodType = undefined;
  h.role = "admin";
  h.schedulesLoading = false;
  h.schedules = [];
  h.setSelected.mockClear();
  h.launch.mockClear();
  h.transcribe.mockClear();
  h.transcribe.mockResolvedValue({ id: "sched-t", versionNumber: 1, copiedCount: 5, toReplace: [{ teamId: "t1", dayOfWeek: 3, startTime: "18:00:00", venueId: "v1", reason: "venue_closed" }] });
});

/**
 * NR (retour fondateur 2026-08-05, « en prod ça ne passera pas ») : un solve
 * FAILED a ses DIAGNOSTICS en base — l'écran d'échec doit les MONTRER, jamais
 * le générique « une erreur est survenue » qui laissait le gestionnaire sans
 * rien. ⚠ Le composant n'entre en échec de solve que si un scheduleId local
 * existe : on ne teste ici que le RENDU (les hooks sont mockés), pas le poll.
 */
describe("GenerateStep — écran d'échec expliqué", () => {
  beforeEach(() => {
    h.status = null;
    h.diagnostics = [];
    h.diagLoading = false;
    h.launch.mockClear();
  });

  it("le launcher s'affiche au repos (pas d'échec fantôme)", () => {
    renderStep();
    expect(screen.getByRole("button", { name: /Lancer la génération/i })).toBeInTheDocument();
    expect(screen.queryByText(/n'a pas abouti/)).not.toBeInTheDocument();
  });

  it("un solve FAILED montre le MOTIF du moteur et ses pistes — jamais le générique", async () => {
    h.status = "FAILED";
    h.diagnostics = [
      {
        id: "d1",
        severity: "ERROR",
        message: "Le planning n'a pas pu être généré : il faut placer 84 séances pour 82 créneaux.",
        suggestions: ["Ajoutez de la disponibilité de gymnase.", "Assouplissez une contrainte dure."],
      },
      { id: "d2", severity: "INFO", message: "détail secondaire", suggestions: null },
    ];
    // Le mock renvoie FAILED dès le montage : l'échec de solve s'affiche sans
    // passer par le bouton (c'est le RENDU qu'on épingle, pas le poll).
    renderStep();

    expect(await screen.findByText(/n'a pas abouti/)).toBeInTheDocument();
    expect(screen.getByText(/84 séances pour 82 créneaux/)).toBeInTheDocument();
    expect(screen.getByText("Ajoutez de la disponibilité de gymnase.")).toBeInTheDocument();
    // Le générique ne double jamais un motif réel, et l'INFO ne pollue pas.
    expect(screen.queryByText(/Une erreur est survenue/)).not.toBeInTheDocument();
    expect(screen.queryByText("détail secondaire")).not.toBeInTheDocument();
  });

  it("FAILED sans diagnostic lisible → repli générique (jamais un écran muet)", async () => {
    h.status = "FAILED";
    h.diagnostics = [];
    renderStep();

    expect(await screen.findByText(/n'a pas abouti/)).toBeInTheDocument();
    expect(screen.getByText(/Une erreur est survenue/)).toBeInTheDocument();
  });
});

/**
 * AUD-FRT-09 — « Réessayer » ne doit pas empiler une version de plus.
 *
 * Le mode période réutilisait déjà l'overlay en vol ; le mode saison créait une version
 * neuve à CHAQUE clic. Pas de corruption — l'unicité du socle est tenue par un 409
 * serveur — mais un historique de versions FAILED que le gestionnaire doit trier, et qui
 * grossit précisément quand ça va mal.
 *
 * ⚠ Le test vérifie les DEUX sens, parce qu'ils se contredisent : premier lancement =
 * version neuve (sinon on écraserait), reprise après échec = même version. Un test qui
 * n'épinglerait que la reprise laisserait passer « réutiliser toujours ».
 */
describe("GenerateStep — la reprise après échec ne crée pas de version (AUD-FRT-09)", () => {
  beforeEach(() => {
    h.status = null;
    h.diagnostics = [];
    h.diagLoading = false;
    h.launch.mockClear();
  });

  it("premier lancement : version neuve ; reprise après FAILED : la même", async () => {
    const user = userEvent.setup();
    const { rerender } = renderStep();

    await user.click(screen.getByRole("button", { name: /Lancer la génération/ }));
    expect(h.launch).toHaveBeenCalledWith({ existingScheduleId: undefined });

    // Le solve échoue : l'écran passe en échec, et le scheduleId local pointe la version.
    h.status = "FAILED";
    rerender(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <GenerateStep />
      </QueryClientProvider>,
    );

    await user.click(await screen.findByRole("button", { name: /Réessayer/ }));
    expect(h.launch).toHaveBeenLastCalledWith({ existingScheduleId: "sched-1" });
  });
});

/**
 * bug fondateur 2026-08-19 — l'étape Génération d'une ADAPTATION de période doit :
 *  1) montrer l'écran embarqué dès qu'une version de LA PÉRIODE existe (terminée OU en vol),
 *     même au RETOUR sur l'étape sans scheduleId local — sinon un écran « Générer » vierge
 *     masquait les versions déjà générées ;
 *  2) PORTER cet écran sur le plan de la période (`scopePlanId`), pas sur le socle.
 * On épingle le CÂBLAGE (portée passée + condition d'affichage) ; le rendu scopé lui-même
 * vit sur le composant réel dans PlanningPage.test.tsx.
 */
describe("GenerateStep — mode période : l'écran embarqué est scopé au plan de période", () => {
  const overlay = (id: string, status: string, createdAt: string): StubSchedule => ({ id, status, createdAt, planType: "CLOSURE", schedulePlanId: "plan-p" });

  beforeEach(() => {
    h.mode = "period";
    h.entryId = "entry-1";
    h.planId = "plan-p";
  });

  it("de retour sur l'étape avec 2 versions COMPLETED de la période (aucun scheduleId local) : l'écran embarqué s'affiche, porté sur le plan de période", () => {
    // RED avant le fix : `overlayDone` dérivait du statut LOCAL (nul ici) → showPlanning
    // faux → le lanceur restait affiché, l'écran embarqué absent.
    h.schedules = [overlay("ov-1", "COMPLETED", "2026-09-10T00:00:00Z"), overlay("ov-2", "COMPLETED", "2026-09-11T00:00:00Z")];
    renderStep();

    expect(screen.getByTestId("planning")).toHaveAttribute("data-scope", "plan-p");
    // P2-43 volet (v) — l'entrée de calendrier de la période est passée : PlanningPage y lit
    // l'état de fermeture des gymnases pour MARQUER les fenêtres vides fermées.
    expect(screen.getByTestId("planning")).toHaveAttribute("data-entry", "entry-1");
    expect(screen.queryByRole("button", { name: /Générer le planning de période/i })).not.toBeInTheDocument();
  });

  it("une version EN VOL de la période affiche aussi l'écran embarqué porté (retour mi-génération)", () => {
    h.schedules = [overlay("ov-1", "GENERATING", "2026-09-10T00:00:00Z")];
    renderStep();

    expect(screen.getByTestId("planning")).toHaveAttribute("data-scope", "plan-p");
  });

  it("plan de période SANS version : le lanceur reste, jamais l'écran embarqué (aucun repli saison)", () => {
    // Une version de SAISON existe (le repli fautif d'avant), mais pas de version de la période.
    h.schedules = [{ id: "season-v1", status: "COMPLETED", createdAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" }];
    renderStep();

    expect(screen.queryByTestId("planning")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Générer le planning de période/i })).toBeInTheDocument();
  });

  it("la portée ne dépend pas de la largeur : un enfant-SEGMENT scope au même plan qu'un mono-semaine", () => {
    // Enfant-segment (autre entryId) — l'ancre résout le MÊME type de plan de période :
    // la portée se dérive du plan, pas de la largeur de la fenêtre.
    h.entryId = "segment-3";
    h.schedules = [overlay("ov-seg", "COMPLETED", "2026-09-10T00:00:00Z")];
    renderStep();

    expect(screen.getByTestId("planning")).toHaveAttribute("data-scope", "plan-p");
  });
});

/**
 * bug fondateur 2026-08-19 (trou antérieur) — le VERDICT d'échec doit survivre au retour sur
 * l'étape. `failed`/`failedDiagnostics`/`waiting` dérivaient du `scheduleId` LOCAL, nul au
 * remontage : un run FAILED redevenait un lanceur MUET, le motif du moteur perdu. On les fait
 * dériver du dernier run FAILED du plan en portée, lu de la LISTE (saison comprise).
 */
describe("GenerateStep — un run FAILED survit au retour sur l'étape (dérivé de la LISTE)", () => {
  const errDiag = [{ id: "d1", severity: "ERROR", message: "Le planning n'a pas pu être généré : capacité insuffisante.", suggestions: ["Ajoutez de la disponibilité de gymnase."] }];

  beforeEach(() => {
    h.status = null; // aucun run LOCAL ce montage — c'est tout l'enjeu
    h.diagnostics = errDiag;
  });

  it("SAISON : un run FAILED en liste (sans scheduleId local) rouvre l'écran d'échec expliqué, pas le lanceur", () => {
    // RED avant le fix : failed dérivait du statut local (nul) → le lanceur restait, muet.
    h.mode = "season";
    h.schedules = [{ id: "s-fail", status: "FAILED", createdAt: "2026-02-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" }];
    renderStep();

    expect(screen.getByText(/n'a pas abouti/)).toBeInTheDocument();
    expect(screen.getByText(/capacité insuffisante/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Lancer la génération/i })).not.toBeInTheDocument();
  });

  it("PÉRIODE : le run FAILED de la période en liste rouvre l'écran d'échec au retour", () => {
    h.mode = "period";
    h.entryId = "entry-1";
    h.planId = "plan-p";
    h.schedules = [{ id: "p-fail", status: "FAILED", createdAt: "2026-09-10T00:00:00Z", planType: "CLOSURE", schedulePlanId: "plan-p" }];
    renderStep();

    expect(screen.getByText(/n'a pas abouti/)).toBeInTheDocument();
    expect(screen.getByText(/capacité insuffisante/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Générer le planning de période/i })).not.toBeInTheDocument();
  });
});

describe("GenerateStep — mode saison : rien ne change (NR)", () => {
  it("l'écran embarqué de saison n'a pas de portée (scopePlanId nul)", () => {
    h.mode = "season";
    h.schedules = [{ id: "s-v1", status: "COMPLETED", createdAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" }];
    renderStep();

    expect(screen.getByTestId("planning")).toHaveAttribute("data-scope", "");
    // En saison, aucune entrée de période n'est passée (pas de fermetures de période à lire).
    expect(screen.getByTestId("planning")).toHaveAttribute("data-entry", "");
  });

  it("mode saison : aucun bouton « Partir du planning de saison » (transcription = plans de période)", () => {
    h.mode = "season";
    h.schedules = [];
    renderStep();
    expect(screen.getByRole("button", { name: /Lancer la génération/i })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Partir du planning de saison/i })).not.toBeInTheDocument();
  });
});

/**
 * P2-44 (ADR-0004) — « Partir du planning de saison » : sur un plan de PÉRIODE VIERGE, un bouton
 * transcrit la version pointée du socle vers la V1, sans solveur. La donnée « à replacer » servie
 * par la route est passée à l'écran embarqué (le front ne redérive rien). Un plan déjà versionné
 * n'offre pas le bouton ; un socle non pointé (409) explique au lieu d'échouer en silence.
 */
describe("GenerateStep — transcription depuis le socle (P2-44)", () => {
  beforeEach(() => {
    h.mode = "period";
    h.entryId = "entry-1";
    h.planId = "plan-p";
    h.schedules = [];
  });

  it("plan de période VIERGE : le bouton s'affiche à côté de « Générer le planning de période »", () => {
    renderStep();
    expect(screen.getByRole("button", { name: /Générer le planning de période/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Partir du planning de saison/i })).toBeInTheDocument();
  });

  it("clic : transcrit le plan de période, puis l'écran embarqué reçoit la liste « à replacer »", async () => {
    const user = userEvent.setup();
    const { rerender } = renderStep();

    await user.click(screen.getByRole("button", { name: /Partir du planning de saison/i }));
    expect(h.transcribe).toHaveBeenCalledWith("plan-p");

    // La V1 transcrite apparaît dans la liste des versions (refetch) → l'écran embarqué s'affiche,
    // porté sur le plan de période ET porteur de la liste « à replacer » servie par la route.
    h.schedules = [{ id: "sched-t", status: "COMPLETED", createdAt: "2026-09-10T00:00:00Z", planType: "CLOSURE", schedulePlanId: "plan-p" }];
    rerender(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <GenerateStep />
      </QueryClientProvider>,
    );

    const planning = screen.getByTestId("planning");
    expect(planning).toHaveAttribute("data-scope", "plan-p");
    expect(planning).toHaveAttribute("data-toreplace", "1");
  });

  it("plan de période DÉJÀ versionné : pas de bouton de transcription (backend refuserait de toute façon)", () => {
    h.schedules = [{ id: "ov-1", status: "COMPLETED", createdAt: "2026-09-10T00:00:00Z", planType: "CLOSURE", schedulePlanId: "plan-p" }];
    renderStep();
    expect(screen.queryByRole("button", { name: /Partir du planning de saison/i })).not.toBeInTheDocument();
  });

  it("socle non pointé (409) : le bouton EXPLIQUE la raison servie, jamais un échec muet", async () => {
    h.transcribe.mockRejectedValueOnce({ reason: "Choisissez d'abord un planning de saison en vigueur." });
    const user = userEvent.setup();
    renderStep();

    await user.click(screen.getByRole("button", { name: /Partir du planning de saison/i }));
    expect(await screen.findByText("Choisissez d'abord un planning de saison en vigueur.")).toBeInTheDocument();
  });
});

/**
 * P2-44 PR-4 (arbitrage fondateur 2026-08-20) — sur l'étape Génération d'un plan de FERMETURE
 * (`closure`) VIERGE, le planning de saison transcrit doit DÉJÀ être là, sans clic : la
 * transcription part AUTOMATIQUEMENT à l'arrivée (mutation front, jamais un GET qui écrit).
 *
 * Arbitrages FIGÉS gardés ici :
 *  · auto pour les FERMETURES seulement — les VACANCES (`holiday`) gardent le geste MANUEL ;
 *  · rôle Membre : rien ne part (miroir d'affichage, on n'envoie pas une écriture vouée au 403) ;
 *  · une version déjà présente ⇒ pas de tir (le plan n'est plus vierge) ;
 *  · liste en vol ⇒ on attend (pas de tir sur une vacuité crédible) ;
 *  · le 409 « déjà versionné » (StrictMode / remontage / second onglet) est BÉNIN : aucun bandeau
 *    rouge — le serveur est l'autorité, la liste se réconcilie.
 */
describe("GenerateStep — auto-transcription des FERMETURES vierges (P2-44 PR-4)", () => {
  beforeEach(() => {
    h.mode = "period";
    h.entryId = "entry-1";
    h.planId = "plan-p";
    h.entryPeriodType = "closure";
    h.role = "admin";
    h.schedules = [];
  });

  it("fermeture vierge, gestionnaire : transcrit AUTOMATIQUEMENT à l'arrivée (sans clic), une seule fois", async () => {
    renderStep();
    await waitFor(() => expect(h.transcribe).toHaveBeenCalledWith("plan-p"));
    // Ref one-shot par plan : un seul tir, jamais une rafale à chaque re-rendu.
    expect(h.transcribe).toHaveBeenCalledTimes(1);
  });

  it("VACANCE vierge : ne transcrit PAS (le geste reste MANUEL) — le bouton demeure", async () => {
    h.entryPeriodType = "holiday";
    renderStep();
    // Laisser les effets s'appliquer : rien ne doit partir.
    await Promise.resolve();
    expect(h.transcribe).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: /Partir du planning de saison/i })).toBeInTheDocument();
  });

  it("fermeture DÉJÀ versionnée : ne transcrit pas (le plan n'est plus vierge)", async () => {
    h.schedules = [{ id: "ov-1", status: "COMPLETED", createdAt: "2026-09-10T00:00:00Z", planType: "CLOSURE", schedulePlanId: "plan-p" }];
    renderStep();
    await Promise.resolve();
    expect(h.transcribe).not.toHaveBeenCalled();
  });

  it("fermeture vierge, rôle MEMBRE : ne transcrit pas (miroir d'affichage, on n'envoie pas un 403)", async () => {
    h.role = "member";
    renderStep();
    await Promise.resolve();
    expect(h.transcribe).not.toHaveBeenCalled();
  });

  it("liste des versions EN VOL : ne transcrit pas tant que la donnée charge", async () => {
    h.schedulesLoading = true;
    renderStep();
    await Promise.resolve();
    expect(h.transcribe).not.toHaveBeenCalled();
  });

  it("409 « déjà versionné » : BÉNIN — aucun bandeau d'erreur affiché", async () => {
    h.transcribe.mockRejectedValueOnce(
      new HTTPError(new Response("{}", { status: 409 }), new Request("http://t/api/schedule_plans/plan-p/transcribe-from-socle"), {} as never),
    );
    renderStep();
    await waitFor(() => expect(h.transcribe).toHaveBeenCalled());
    // Le rendu d'erreur de la transcription (bandeau rouge `transcribeReason`) ne s'affiche pas.
    expect(screen.queryByText(/Une erreur est survenue/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Conflit/)).not.toBeInTheDocument();
  });
});

/**
 * Lot C, PR-1 (défaut terrain fondateur 2026-08-21) — « quand je lance une génération sur un
 * overlay, un petit chargement mouline au lieu du MÊME écran qu'en saison ».
 *
 * Cause 2 : `showPlanning` inclut `localActive` (:99), donc dès que le poll local voit le run EN
 * VOL — mais AVANT que la liste des versions ne se rafraîchisse (la version fraîche pas encore
 * dedans) —, `showPlanning` gagne et GenerateStep délègue à l'embarqué, qui ne peut pas encore
 * voir la version (elle n'est pas dans sa portée) et flashe son voile « Chargement des créneaux… ».
 *
 * La règle de portée vit dans PlanningPage (`scopeInFlight`, gardée dans PlanningPage.test) ;
 * GenerateStep n'ajoute QUE la fenêtre LOCALE que lui seul connaît (POST→premier refetch).
 */
describe("GenerateStep — fenêtre locale POST→refetch : l'écran d'attente prime sur l'embarqué (lot C)", () => {
  const overlay = (id: string, status: string, createdAt: string): StubSchedule => ({ id, status, createdAt, planType: "CLOSURE", schedulePlanId: "plan-p" });

  beforeEach(() => {
    h.mode = "period";
    h.entryId = "entry-1";
    h.planId = "plan-p";
  });

  it("version fraîche EN VOL pas encore dans la liste → l'écran d'attente, jamais l'embarqué", async () => {
    // La liste ne contient pas encore la version fraîche (le refetch n'a pas eu lieu).
    h.schedules = [];
    const user = userEvent.setup();
    const { rerender } = renderStep();

    // Au repos : le lanceur. Le clic pose le scheduleId LOCAL (launch → "sched-1").
    await user.click(screen.getByRole("button", { name: /Générer le planning de période/i }));

    // Le poll local voit le run EN VOL — la version n'est TOUJOURS pas dans la liste.
    // RED avant le fix : `localActive` fait gagner `showPlanning` → l'embarqué (data-testid
    // "planning") s'affiche, l'écran d'attente jamais.
    h.status = "GENERATING";
    rerender(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <GenerateStep />
      </QueryClientProvider>,
    );

    expect(screen.getByTestId("generation-waiting")).toBeInTheDocument();
    expect(screen.queryByTestId("planning")).not.toBeInTheDocument();
  });

  it("NR : de retour avec 2 versions COMPLETED de la période (aucun run local) → l'embarqué, pas l'attente", () => {
    // Le correctif du 2026-08-19 : deux COMPLETED affichent le planning. La fenêtre locale ne
    // doit pas le faire régresser (aucun scheduleId local, aucun statut en vol).
    h.schedules = [overlay("ov-1", "COMPLETED", "2026-09-10T00:00:00Z"), overlay("ov-2", "COMPLETED", "2026-09-11T00:00:00Z")];
    renderStep();

    expect(screen.getByTestId("planning")).toBeInTheDocument();
    expect(screen.queryByTestId("generation-waiting")).not.toBeInTheDocument();
  });
});

/**
 * P5-14 PR-2 — l'AIGUILLAGE vers l'écran « le service de calcul ne répond pas » (écran B).
 * La branche `failed` route désormais :
 *   · `timedOut` (le service ne répond plus) → écran B ;
 *   · run FAILED dont les diagnostics ERROR sont TOUS des types de panne (`isServiceDown`) → écran B ;
 *   · sinon (planning infaisable, `launch.isError`) → l'affichage de causes EXISTANT, INCHANGÉ.
 *
 * ⚠ Falsification dans les deux sens : une panne classée « infaisable » (pas d'écran B) OU un
 * infaisable classé « panne » (l'écran de causes P4-99 disparaît) rougit. On n'ajoute AUCUN
 * second affichage de causes — l'existant reste seul.
 */
describe("GenerateStep — aiguillage panne du service vs planning infaisable (P5-14)", () => {
  beforeEach(() => {
    h.mode = "season";
    h.status = null;
    h.schedules = [];
    h.diagnostics = [];
  });

  it("run FAILED avec des diagnostics de PANNE → écran B, jamais l'écran de causes", () => {
    h.status = "FAILED";
    h.diagnostics = [{ id: "d1", severity: "ERROR", type: "engine_timeout", message: "Schedule generation timed out.", suggestions: [] }];
    renderStep();

    expect(screen.getByTestId("service-down")).toBeInTheDocument();
    // L'écran de causes existant (« n'a pas abouti ») ne s'affiche pas en parallèle.
    expect(screen.queryByText(/n'a pas abouti/)).not.toBeInTheDocument();
  });

  it("run FAILED INFAISABLE (diagnostic métier) → l'écran de causes EXISTANT, inchangé — pas d'écran B", () => {
    h.status = "FAILED";
    h.diagnostics = [
      { id: "d1", severity: "ERROR", type: "session_below_effective_min", message: "Le planning n'a pas pu être généré : il faut placer 84 séances pour 82 créneaux.", suggestions: ["Ajoutez de la disponibilité de gymnase."] },
    ];
    renderStep();

    expect(screen.getByText(/n'a pas abouti/)).toBeInTheDocument();
    expect(screen.getByText(/84 séances pour 82 créneaux/)).toBeInTheDocument();
    expect(screen.queryByTestId("service-down")).not.toBeInTheDocument();
  });

  it("run FAILED MÉLANGÉ (panne + infaisable) → écran de causes existant (un métier suffit à disculper le service)", () => {
    h.status = "FAILED";
    h.diagnostics = [
      { id: "d1", severity: "ERROR", type: "engine_timeout", message: "Schedule generation timed out.", suggestions: [] },
      { id: "d2", severity: "ERROR", type: "session_below_effective_min", message: "Capacité insuffisante.", suggestions: [] },
    ];
    renderStep();

    expect(screen.getByText(/n'a pas abouti/)).toBeInTheDocument();
    expect(screen.queryByTestId("service-down")).not.toBeInTheDocument();
  });

  it("TIMEOUT (le service ne répond plus) → écran B", async () => {
    vi.useFakeTimers();
    try {
      renderStep();
      fireEvent.click(screen.getByRole("button", { name: /Lancer la génération/ }));
      // Flush du mutateAsync (le scheduleId LOCAL est posé, l'effet arme le setTimeout de garde).
      await act(async () => {
        await Promise.resolve();
      });
      // 20 min sans réponse (TIMEOUT_MS) : le garde bascule `timedOut`.
      await act(async () => {
        vi.advanceTimersByTime(20 * 60 * 1000 + 1);
      });

      expect(screen.getByTestId("service-down")).toBeInTheDocument();
      expect(screen.queryByTestId("generation-waiting")).not.toBeInTheDocument();
    } finally {
      vi.useRealTimers();
    }
  });

  it("run FAILED en `engine_failed` (le moteur a RÉPONDU « failed ») → écran de causes, jamais B", () => {
    // `engine_failed` = planning infaisable renvoyé par le moteur, exclu à dessein de la liste
    // de panne : il route vers l'affichage de causes existant, comme n'importe quel infaisable.
    h.status = "FAILED";
    h.diagnostics = [{ id: "d1", severity: "ERROR", type: "engine_failed", message: "Schedule generation failed.", suggestions: [] }];
    renderStep();

    expect(screen.queryByTestId("service-down")).not.toBeInTheDocument();
    expect(screen.getByText(/n'a pas abouti/)).toBeInTheDocument();
  });
});

/** Promesse : un bouton de génération grisé dit pourquoi, au clavier aussi (le `disabled` seul se tait). */
describe("GenerateStep — porte fermée par un blocage du Récap", () => {
  it("le bouton est désactivé et dit la raison", () => {
    h.recapErrors = ["Groupe U15, U13 : aucun créneau."];
    renderStep();

    expect(screen.getByRole("button", { name: /Lancer la génération/ })).toBeDisabled();
    expect(WIZARD_TEXTS.generationBlocked).toBe("Des conditions bloquent la génération");
    expect(screen.getByText(WIZARD_TEXTS.generationBlocked)).toBeInTheDocument();
  });

  it("témoin : sans blocage, ni raison ni bouton grisé", () => {
    renderStep();

    expect(screen.getByRole("button", { name: /Lancer la génération/ })).toBeEnabled();
    expect(screen.queryByText("Des conditions bloquent la génération")).not.toBeInTheDocument();
  });
});
