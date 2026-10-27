import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { fireEvent } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { PreviewTokenStaleError, WindowAlreadyPlannedError } from "./api";

/*
 * Tests ROUGES PR2 — « Modifier les dates d'une fermeture » (axe planning lifecycle).
 *
 * La PROMESSE protégée ici : le dialogue ANNONCE avant d'appliquer, et n'applique QUE l'aperçu
 * exact que le gestionnaire a vu — ni un aperçu périmé par une retouche de date, ni une réponse
 * arrivée en retard pour d'anciennes dates. Tout ce qu'il affiche (résumé, effets, labels) est
 * SERVI par le backend et rendu tel quel (règle d'or : le front n'invente aucune règle métier).
 *
 * Niveau : jsdom VOIT tout (état du bouton « Confirmer », péremption, course, branches 409/422,
 * variante destructive) sans traverser le navigateur — donc Vitest, pas E2E (charte §6). Le chemin
 * SERVEUR de la prolongation (4 plans, plancher, inv. 14) est prouvé par le NR bloquant
 * `backend/tests/Security/ClosureWindowExtendTest.php` et le Behat PR1 — on ne le double pas ici.
 *
 * Contrat défini pour NEO (option A) : le composant `ClosureWindowDialog` et les hooks
 * `useClosureWindowPreview` / `useApplyClosureWindow` (./queries) n'existent pas encore — ce fichier
 * est ROUGE tant que NEO ne les a pas créés, puis ces assertions pilotent leur comportement.
 */

const previewMutateAsync = vi.fn();
const applyMutateAsync = vi.fn();
const previewState = { isPending: false };
const applyState = { isPending: false };
const toastSuccess = vi.fn();
const toastError = vi.fn();

// On teste la PROD du dialogue : seuls les deux hooks de données et l'horloge/la saison sont doublés.
vi.mock("./queries", () => ({
  useClosureWindowPreview: () => ({ mutateAsync: previewMutateAsync, isPending: previewState.isPending }),
  useApplyClosureWindow: () => ({ mutateAsync: applyMutateAsync, isPending: applyState.isPending }),
}));
vi.mock("@/shared/session/queries", () => ({
  // La fin de saison borne le `max` du champ « Jusqu'au ».
  useWorkingSeason: () => ({ id: "sn1", name: "2026-2027", startDate: "2026-08-01", endDate: "2027-07-31", isCurrent: true, isReadonly: false }),
}));
vi.mock("@/shared/lib/clock", async (orig) => ({
  ...(await orig<typeof import("@/shared/lib/clock")>()),
  // « Aujourd'hui » figé : le plancher du début non commencé et le test « déjà commencée » sont déterministes.
  useTodayISO: () => "2026-11-01",
}));
vi.mock("@/shared/stores/toastStore", () => ({ toast: { success: (...a: unknown[]) => toastSuccess(...a), error: (...a: unknown[]) => toastError(...a), info: vi.fn() } }));

import { ClosureWindowDialog } from "./ClosureWindowDialog";

// Format API réel (gardé par FrontMockDateFormatMatchesContractTest) : *Date → Y-m-d.
const PLAN = {
  id: "clo-plan",
  // Fenêtre FUTURE (début > aujourd'hui) : début ÉDITABLE, plancher = aujourd'hui.
  startDate: "2026-11-16",
  endDate: "2026-11-27",
  minEndDate: "2026-11-02",
  closureTitle: "Gymnase Matéo indisponible",
};

// Un plan DÉJÀ COMMENCÉ (début < aujourd'hui) : son début est FIGÉ (le passé ne bouge pas).
const STARTED_PLAN = { ...PLAN, startDate: "2026-10-20", minEndDate: "2026-11-01" };

// L'aperçu SERVI (dates Y-m-d, labels français prêts à afficher — le front ne reformule rien).
const PREVIEW = {
  token: "tok-1",
  summary: "4 plannings, 1 pause de vacances.",
  futurePlansWarning: false,
  effects: [
    { kind: "convert", startDate: "2026-08-31", endDate: "2026-10-11", label: "Planning de la fermeture conservé du 31 août au 11 octobre." },
    { kind: "birth_copy", startDate: "2026-10-12", endDate: "2026-10-17", label: "Nouveau planning repris du 12 au 17 octobre." },
    { kind: "holiday_pause", startDate: "2026-10-19", endDate: "2026-10-31", label: "Pause pendant les vacances de la Toussaint." },
    { kind: "birth_copy", startDate: "2026-11-02", endDate: "2026-12-18", label: "Nouveau planning repris du 2 novembre au 18 décembre." },
  ],
};

const PREVIEW_WITH_WARNING = { ...PREVIEW, token: "tok-warn", futurePlansWarning: true };

// Raccourcissement : au moins un plan DISPARAÎT (kind vanish) → « Confirmer » devient destructif.
const PREVIEW_VANISH = {
  token: "tok-vanish",
  summary: "1 planning supprimé.",
  futurePlansWarning: false,
  effects: [
    { kind: "convert", startDate: "2026-08-31", endDate: "2026-10-11", label: "Planning de la fermeture conservé du 31 août au 11 octobre." },
    { kind: "vanish", startDate: "2026-11-02", endDate: "2026-12-18", label: "Le planning du 2 novembre au 18 décembre est supprimé." },
  ],
};

function open(over: Partial<React.ComponentProps<typeof ClosureWindowDialog>> = {}) {
  const onClose = vi.fn();
  const onApplied = vi.fn();
  const onOpenConflict = vi.fn();
  render(<ClosureWindowDialog plan={PLAN} onClose={onClose} onApplied={onApplied} onOpenConflict={onOpenConflict} {...over} />);
  return { onClose, onApplied, onOpenConflict };
}

/** Charge un aperçu VALIDE (clique « Voir les effets » après avoir changé la fin). */
async function loadPreview(user: ReturnType<typeof userEvent.setup>, result = PREVIEW, end = "2026-12-18"): Promise<void> {
  previewMutateAsync.mockResolvedValueOnce(result);
  fireEvent.change(screen.getByLabelText("Jusqu'au"), { target: { value: end } });
  await user.click(screen.getByRole("button", { name: "Voir les effets" }));
  await screen.findByText(result.effects[0].label);
}

beforeEach(() => {
  previewMutateAsync.mockReset();
  applyMutateAsync.mockReset();
  previewState.isPending = false;
  applyState.isPending = false;
  toastSuccess.mockClear();
  toastError.mockClear();
});

describe("ClosureWindowDialog — annonce avant d'appliquer", () => {
  it("rend un dialogue titré par la FERMETURE (son identité, servie)", () => {
    open();
    const dialog = screen.getByRole("dialog");
    expect(within(dialog).getByText(new RegExp(PLAN.closureTitle))).toBeInTheDocument();
  });

  // Plancher « le passé ne bouge pas » : une fermeture COMMENCÉE a son début FIGÉ et le dit.
  it("fige le début (readOnly) d'une fermeture déjà commencée et l'explique", () => {
    open({ plan: STARTED_PLAN });
    const start = screen.getByLabelText("Du") as HTMLInputElement;
    expect(start).toHaveAttribute("readOnly");
    expect(screen.getByText(/le début ne bouge plus/i)).toBeInTheDocument();
  });

  // Fenêtre FUTURE : début éditable au plancher « aujourd'hui » (useTodayISO), jamais dans le passé.
  it("laisse le début éditable mais jamais avant aujourd'hui quand la fermeture n'a pas commencé", () => {
    open();
    const start = screen.getByLabelText("Du") as HTMLInputElement;
    expect(start).not.toHaveAttribute("readOnly");
    expect(start).toHaveAttribute("min", "2026-11-01");
  });

  // La fin ne descend jamais sous le PLANCHER SERVI (minEndDate), ni au-delà de la saison.
  it("borne « Jusqu'au » au plancher SERVI (minEndDate) et à la fin de saison", () => {
    open();
    const end = screen.getByLabelText("Jusqu'au") as HTMLInputElement;
    expect(end).toHaveAttribute("min", PLAN.minEndDate);
    expect(end).toHaveAttribute("max", "2027-07-31");
  });

  it("« Voir les effets » rend la liste EXACTEMENT depuis les effets servis, dans l'ordre, label tel quel", async () => {
    const user = userEvent.setup();
    open();
    await loadPreview(user);

    // Un item par effet servi, labels rendus VERBATIM.
    const items = screen.getAllByRole("listitem");
    expect(items).toHaveLength(PREVIEW.effects.length);
    for (const effect of PREVIEW.effects) {
      expect(screen.getByText(effect.label)).toBeInTheDocument();
    }
    // Ordre chronologique SERVI préservé (pas de tri côté front).
    const rendered = items.map((li) => li.textContent ?? "");
    for (let i = 0; i < PREVIEW.effects.length; i += 1) {
      expect(rendered[i]).toContain(PREVIEW.effects[i].label);
    }
  });

  // La région live annonce le RÉSUMÉ servi SEUL ; la liste d'effets vit dans sa propre section repère.
  it("met le résumé servi (et lui seul) dans la région aria-live, la liste dans sa section « Ce qui va changer »", async () => {
    const user = userEvent.setup();
    const { container } = { container: document.body };
    open();
    await loadPreview(user);

    const live = container.querySelector("[aria-live]");
    expect(live, "une région aria-live doit exister").not.toBeNull();
    expect(live).toHaveTextContent(PREVIEW.summary);
    // La région live ne porte PAS les effets (ils sont dans la section repère, annoncés une fois).
    expect(live).not.toHaveTextContent(PREVIEW.effects[0].label);

    const section = screen.getByRole("region", { name: /Ce qui va changer/i });
    expect(within(section).getByText(PREVIEW.effects[0].label)).toBeInTheDocument();
  });

  it("affiche un bandeau quand des plans FUTURS seraient balayés (futurePlansWarning servi)", async () => {
    const user = userEvent.setup();
    open();
    await loadPreview(user, PREVIEW_WITH_WARNING);
    // NoticeBanner → un role status/alert OU un bandeau d'information ; on exige au moins qu'un avis
    // distinct du résumé apparaisse (le texte précis vient du serveur/texts, pas recopié ici).
    expect(screen.getByText(/futur|balay|valid|rouvr/i)).toBeInTheDocument();
  });
});

describe("ClosureWindowDialog — « Confirmer » n'applique QUE l'aperçu vu", () => {
  it("garde « Confirmer » aria-disabled avec une raison tant qu'aucun aperçu ne correspond", () => {
    open();
    const confirm = screen.getByRole("button", { name: "Confirmer" });
    expect(confirm).toHaveAttribute("aria-disabled", "true");
    expect(confirm).toHaveAttribute("aria-describedby");
  });

  it("une retouche de date PÉRIME l'aperçu : la liste disparaît et « Confirmer » se re-désactive", async () => {
    const user = userEvent.setup();
    open();
    await loadPreview(user);
    expect(screen.getByRole("button", { name: "Confirmer" })).not.toHaveAttribute("aria-disabled", "true");

    fireEvent.change(screen.getByLabelText("Jusqu'au"), { target: { value: "2026-12-11" } });
    expect(screen.queryByText(PREVIEW.effects[0].label)).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Confirmer" })).toHaveAttribute("aria-disabled", "true");
  });

  // Course : une réponse d'aperçu arrivée APRÈS une retouche (pour d'anciennes dates) est IGNORÉE.
  it("ignore une réponse d'aperçu arrivée pour des dates qui ne sont plus celles affichées", async () => {
    const user = userEvent.setup();
    open();

    let resolveStale: (v: unknown) => void = () => {};
    previewMutateAsync.mockImplementationOnce(() => new Promise((r) => { resolveStale = r; }));
    fireEvent.change(screen.getByLabelText("Jusqu'au"), { target: { value: "2026-12-18" } });
    await user.click(screen.getByRole("button", { name: "Voir les effets" }));

    // On RE-change la fin pendant que la 1ʳᵉ requête est en vol → son résultat devient caduc.
    fireEvent.change(screen.getByLabelText("Jusqu'au"), { target: { value: "2026-12-11" } });
    resolveStale(PREVIEW); // la réponse (dates du 18) arrive en retard

    await Promise.resolve();
    // La liste caduque ne s'affiche jamais, « Confirmer » reste verrouillé.
    expect(screen.queryByText(PREVIEW.effects[0].label)).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Confirmer" })).toHaveAttribute("aria-disabled", "true");
  });

  it("confirme l'aperçu vu : applique avec le jeton servi, toaste et ferme", async () => {
    const user = userEvent.setup();
    const { onApplied, onClose } = open();
    await loadPreview(user);

    applyMutateAsync.mockResolvedValueOnce({ id: PLAN.id });
    await user.click(screen.getByRole("button", { name: "Confirmer" }));

    await waitFor(() => expect(applyMutateAsync).toHaveBeenCalledTimes(1));
    expect(applyMutateAsync).toHaveBeenCalledWith(expect.objectContaining({ planId: PLAN.id, startDate: PLAN.startDate, endDate: "2026-12-18", previewToken: PREVIEW.token }));
    await waitFor(() => expect(toastSuccess).toHaveBeenCalled());
    expect(onApplied.mock.calls.length + onClose.mock.calls.length).toBeGreaterThan(0);
  });

  // 409 JETON : la fermeture a bougé depuis l'aperçu → on l'annonce et on REDEMANDE l'aperçu ;
  // la confirmation reste MANUELLE (jamais ré-appliquée en douce).
  it("sur un jeton périmé (409), annonce, re-charge l'aperçu et attend un nouveau clic — jamais d'apply auto", async () => {
    const user = userEvent.setup();
    open();
    await loadPreview(user);

    applyMutateAsync.mockRejectedValueOnce(new PreviewTokenStaleError("La fermeture a changé depuis l'aperçu : redemandez l'aperçu des effets."));
    previewMutateAsync.mockResolvedValueOnce({ ...PREVIEW, token: "tok-2" });
    await user.click(screen.getByRole("button", { name: "Confirmer" }));

    // Un avis (role status) porte le message serveur ; l'aperçu est re-demandé ; rien n'est appliqué.
    await screen.findByText(/La fermeture a changé depuis l'aperçu/i);
    await waitFor(() => expect(previewMutateAsync).toHaveBeenCalledTimes(2));
    expect(applyMutateAsync).toHaveBeenCalledTimes(1);
    expect(toastSuccess).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "Confirmer" })).not.toHaveAttribute("aria-disabled", "true");
  });

  // 409 FENÊTRE : une autre fermeture tient déjà ces dates → la proposition WindowAlreadyPlannedNotice,
  // dont l'action remonte au parent (ferme les DEUX modales).
  it("sur un chevauchement (409 window_already_planned), propose d'ouvrir le planning en place", async () => {
    const user = userEvent.setup();
    const { onOpenConflict } = open();
    await loadPreview(user);

    applyMutateAsync.mockRejectedValueOnce(new WindowAlreadyPlannedError("Ces dates sont déjà planifiées par « Autre ».", "e-conflit"));
    await user.click(screen.getByRole("button", { name: "Confirmer" }));

    const openBtn = await screen.findByRole("button", { name: /Ouvrir le planning en place/i });
    await user.click(openBtn);
    expect(onOpenConflict).toHaveBeenCalledWith("e-conflit");
  });

  it("sur un refus d'aperçu (422), affiche le message serveur dans la région live destructive", async () => {
    const user = userEvent.setup();
    open();
    previewMutateAsync.mockRejectedValueOnce(new Error("La fenêtre sort de la saison."));
    fireEvent.change(screen.getByLabelText("Jusqu'au"), { target: { value: "2028-01-01" } });
    await user.click(screen.getByRole("button", { name: "Voir les effets" }));

    const msg = await screen.findByText("La fenêtre sort de la saison.");
    expect(msg.closest("[aria-live]"), "le refus 422 s'annonce dans la région live").not.toBeNull();
  });

  // Raccourcir = au moins un plan DISPARAÎT (kind vanish) → « Confirmer » prend la variante destructive.
  it("rend « Confirmer » destructif quand l'aperçu supprime un plan (raccourcissement)", async () => {
    const user = userEvent.setup();
    open();
    await loadPreview(user, PREVIEW_VANISH, "2026-11-20");
    const confirm = screen.getByRole("button", { name: "Confirmer" });
    expect(confirm.className).toMatch(/destructive/);
  });
});
