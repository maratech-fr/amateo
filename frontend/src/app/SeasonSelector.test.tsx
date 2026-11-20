import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { MeResponse, MeSeason } from "@/shared/session/api";
import { useSeasonStore } from "@/shared/stores/seasonStore";
import { useTransitionUiStore } from "@/shared/stores/transitionUiStore";
import { useWizardStore } from "@/features/wizard/store";
import { SeasonSelector } from "./SeasonSelector";
import { localIso, seasonYearOf } from "./seasonTransition";

let meData: Partial<MeResponse> | undefined;
const transitionMock = vi.fn();

vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: meData }),
}));
vi.mock("@/features/auth/api", () => ({
  transitionSeason: (id: string) => transitionMock(id),
}));
// Toasts espionnés (P1-5) : on vérifie QUEL message part, pas son rendu DOM.
vi.mock("@/shared/stores/toastStore", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
// The re-dating step has its own tests; here we only assert WHEN it opens.
vi.mock("@/features/season-transition/RedateEventsDialog", () => ({
  RedateEventsDialog: ({ sourceSeasonId, targetSeasonId }: { sourceSeasonId: string; targetSeasonId: string }) => (
    <div data-testid="redate-dialog">{`${sourceSeasonId}->${targetSeasonId}`}</div>
  ),
}));

const season = (overrides: Partial<MeSeason>): MeSeason => ({
  id: "sN",
  name: "2025-2026",
  startDate: "2025-08-01",
  endDate: "2026-07-15",
  isCurrent: true,
  isReadonly: false,
  ...overrides,
});

let queryClient: QueryClient;

/** Local-time date (mirrors the component's localIso, no UTC parse). */
const day = (iso: string): Date => new Date(`${iso}T12:00:00`);

// Par défaut on se place DANS la fenêtre où « Préparer la saison suivante » est
// proposé (saison courante 2025-2026 → [1er mai 2026, 15 juil 2026]).
// Le serveur sert la date d'ouverture (`seasonPreparationOpensOn`) : le 1er mai qui suit le
// pivot du 15 juillet de la saison du jour. Le mock l'envoie comme l'API, sauf si le test la fixe.
function renderSelector(today: Date = day("2026-06-01")) {
  if (undefined !== meData && undefined === meData.seasonPreparationOpensOn) {
    meData = { ...meData, seasonPreparationOpensOn: `${seasonYearOf(localIso(today)) + 1}-05-01` };
  }
  queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <SeasonSelector today={today} />
    </QueryClientProvider>,
  );
}

describe("SeasonSelector", () => {
  beforeEach(() => {
    meData = undefined;
    transitionMock.mockReset();
    vi.clearAllMocks();
    useSeasonStore.getState().clear();
    useTransitionUiStore.setState({ confirmOpen: false });
  });

  it("renders nothing without seasons", () => {
    meData = { seasons: [], currentSeasonId: null };
    renderSelector();
    expect(screen.queryByText("Saison de travail")).not.toBeInTheDocument();
  });

  it("lists the seasons with their state badges", async () => {
    meData = {
      currentSeasonId: "sN",
      seasons: [
        season({ id: "sP", name: "2024-2025", isCurrent: false, isReadonly: true }),
        season({}),
        season({ id: "sD", name: "2026-2027", isCurrent: false }),
      ],
    };
    renderSelector();

    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    expect(screen.getByText("2024-2025 · lecture seule")).toBeInTheDocument();
    expect(screen.getByText("2025-2026 · en cours")).toBeInTheDocument();
    expect(screen.getByText("2026-2027 · brouillon")).toBeInTheDocument();
  });

  it("switching season sets the store, resets the wizard and clears the query cache", async () => {
    meData = { currentSeasonId: "sN", seasons: [season({}), season({ id: "sD", name: "2026-2027", isCurrent: false })] };
    useWizardStore.setState({ mode: "period", calendarEntryId: "ce1" });
    const clearSpy = vi.spyOn(QueryClient.prototype, "clear");
    renderSelector();

    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    await userEvent.click(screen.getByText("2026-2027 · brouillon"));

    expect(useSeasonStore.getState().selectedSeasonId).toBe("sD");
    expect(useWizardStore.getState().mode).toBe("season");
    expect(clearSpy).toHaveBeenCalled();
    clearSpy.mockRestore();
  });

  it("selecting the current season goes back to the default state (no header)", async () => {
    meData = { currentSeasonId: "sN", seasons: [season({}), season({ id: "sD", name: "2026-2027", isCurrent: false })] };
    useSeasonStore.getState().setSelectedSeasonId("sD");
    renderSelector();

    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    await userEvent.click(screen.getByText("2025-2026 · en cours"));

    expect(useSeasonStore.getState().selectedSeasonId).toBeNull();
  });

  it("lets the user switch into a read-only season for consultation (server guards writes)", async () => {
    meData = {
      currentSeasonId: "sN",
      seasons: [season({ id: "sP", name: "2024-2025", isCurrent: false, isReadonly: true }), season({})],
    };
    renderSelector();

    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    await userEvent.click(screen.getByText("2024-2025 · lecture seule"));

    expect(useSeasonStore.getState().selectedSeasonId).toBe("sP");
  });

  it("resets a stale persisted selection to the current season", () => {
    meData = { currentSeasonId: "sN", seasons: [season({}), season({ id: "sD", name: "2026-2027", isCurrent: false })] };
    useSeasonStore.getState().setSelectedSeasonId("purged-season");
    renderSelector();

    expect(useSeasonStore.getState().selectedSeasonId).toBeNull();
  });

  it("prepares the next season after confirmation and switches to it", async () => {
    // The freshly created season is already in me.seasons (refetched) so the
    // stale-selection guard does not reset it.
    meData = { currentSeasonId: "sN", seasons: [season({}), season({ id: "sD", name: "2026-2027", isCurrent: false })] };
    transitionMock.mockResolvedValue({ seasonId: "sD", name: "2026-2027", startDate: "2026-08-01", endDate: "2027-07-15", counts: {} });
    renderSelector();

    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    await userEvent.click(screen.getByText("Préparer la saison suivante…"));
    // Structural action → confirmation first, nothing fired yet.
    expect(transitionMock).not.toHaveBeenCalled();

    await userEvent.click(screen.getByRole("button", { name: "Préparer" }));
    expect(transitionMock).toHaveBeenCalledWith("sN");
    await waitFor(() => expect(useSeasonStore.getState().selectedSeasonId).toBe("sD"));
    // The re-dating step opens right after the switch, sourced from N.
    expect(screen.getByTestId("redate-dialog")).toHaveTextContent("sN->sD");
  });

  it("re-opens the re-dating step on the existing-successor path (409)", async () => {
    meData = { currentSeasonId: "sN", seasons: [season({}), season({ id: "sD", name: "2026-2027", isCurrent: false })] };
    const { HTTPError } = await import("ky");
    const response = new Response(JSON.stringify({ existingSeasonId: "sD" }), { status: 409 });
    const httpError = new HTTPError(response, new Request("http://t/api/seasons/sN/transition"), {} as never);
    // ky 2.x consumes the response stream and exposes the parsed body as
    // error.data — mirror that contract here.
    (httpError as unknown as { data: unknown }).data = { existingSeasonId: "sD" };
    transitionMock.mockRejectedValue(httpError);
    renderSelector();

    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    await userEvent.click(screen.getByText("Préparer la saison suivante…"));
    await userEvent.click(screen.getByRole("button", { name: "Préparer" }));

    await waitFor(() => expect(useSeasonStore.getState().selectedSeasonId).toBe("sD"));
    expect(screen.getByTestId("redate-dialog")).toHaveTextContent("sN->sD");
  });

  it("affiche TEL QUEL le motif d'un 409 sans successeur (P1-5 : saison non réglée)", async () => {
    // NR P1-5 : le gate de paiement rend un 409 qui PORTE son motif — l'avaler
    // dans le toast générique laisserait le gestionnaire croire à une panne.
    meData = { currentSeasonId: "sN", seasons: [season({})] };
    const { HTTPError } = await import("ky");
    const message = "La saison 2027-2028 n'est pas réglée — la bascule est réservée aux saisons payées.";
    const response = new Response(JSON.stringify({ error: message }), { status: 409 });
    const httpError = new HTTPError(response, new Request("http://t/api/seasons/sN/transition"), {} as never);
    (httpError as unknown as { data: unknown }).data = { error: message };
    transitionMock.mockRejectedValue(httpError);
    const { toast } = await import("@/shared/stores/toastStore");
    renderSelector();

    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    await userEvent.click(screen.getByText("Préparer la saison suivante…"));
    await userEvent.click(screen.getByRole("button", { name: "Préparer" }));

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith(message));
    // Et surtout PAS le message générique par-dessus.
    expect(toast.error).toHaveBeenCalledTimes(1);
  });

  it("shows the confirm when opened externally via the shared store (banner CTA)", async () => {
    meData = { currentSeasonId: "sN", seasons: [season({})] };
    renderSelector();

    expect(screen.queryByText("Préparer la saison suivante ?")).not.toBeInTheDocument();
    // The anticipation banner opens the SAME dialog through the store.
    act(() => useTransitionUiStore.getState().openConfirm());
    expect(await screen.findByText("Préparer la saison suivante ?")).toBeInTheDocument();
  });

  it("ignores a double-click on the confirm button (single transition request)", async () => {
    meData = { currentSeasonId: "sN", seasons: [season({}), season({ id: "sD", name: "2026-2027", isCurrent: false })] };
    let resolve: (v: unknown) => void = () => {};
    transitionMock.mockReturnValue(new Promise((r) => (resolve = r)));
    renderSelector();

    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    await userEvent.click(screen.getByText("Préparer la saison suivante…"));
    const confirm = screen.getByRole("button", { name: "Préparer" });
    await userEvent.click(confirm);
    await userEvent.click(confirm); // second click while the first is in flight

    resolve({ seasonId: "sD", name: "2026-2027", startDate: "2026-08-01", endDate: "2027-07-15", counts: {} });
    await waitFor(() => expect(transitionMock).toHaveBeenCalledTimes(1));
  });

  // PR D (retour fondateur 2026-07-19) : « Préparer la saison suivante » n'est
  // proposé qu'entre le 1er mai (année 2) et la fin de la saison courante.
  it("hides « Préparer la saison suivante » before May 1 of the season's second year", async () => {
    meData = { currentSeasonId: "sN", seasons: [season({})] }; // 2025-2026 → fenêtre [2026-05-01, 2026-07-15]
    renderSelector(day("2026-04-15"));
    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    expect(screen.queryByText("Préparer la saison suivante…")).not.toBeInTheDocument();
  });

  // Le menu s'ouvre à la date SERVIE (le serveur seul tient la borne basse), jamais à une date
  // recalculée à l'écran. Jumeau serveur : `la-saison-suivante-se-prepare-a-partir-du-1er-mai.feature`.
  it.each([
    ["2026-05-05", false],
    ["2026-05-10", true],
  ])("offers « Préparer la saison suivante » from the served opening date (served 2026-05-10, today %s → %s)", async (today, offered) => {
    meData = { currentSeasonId: "sN", seasons: [season({})], seasonPreparationOpensOn: "2026-05-10" };
    renderSelector(day(today));
    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    expect(screen.queryAllByRole("menuitem", { name: /Préparer la saison suivante/ })).toHaveLength(offered ? 1 : 0);
  });

  it("hides « Préparer la saison suivante » after the season has ended", async () => {
    meData = { currentSeasonId: "sN", seasons: [season({})] };
    renderSelector(day("2026-08-01")); // > endDate 2026-07-15
    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    expect(screen.queryByText("Préparer la saison suivante…")).not.toBeInTheDocument();
  });

  it("hides « Préparer la saison suivante » mid-season (bug fondateur : 2026-2027 au 19 juil. 2026)", async () => {
    meData = { currentSeasonId: "s2627", seasons: [season({ id: "s2627", name: "2026-2027", startDate: "2026-08-01", endDate: "2027-07-15" })] };
    renderSelector(day("2026-07-19"));
    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    expect(screen.queryByText("Préparer la saison suivante…")).not.toBeInTheDocument();
  });

  it("shows « Préparer la saison suivante » inside the window", async () => {
    meData = { currentSeasonId: "sN", seasons: [season({})] };
    renderSelector(day("2026-06-01"));
    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    expect(screen.getByText("Préparer la saison suivante…")).toBeInTheDocument();
  });

  // Le menu reste cliquable même si un successeur existe déjà (préparer 2× réutilise
  // le brouillon via un 409 gracieux — flux conçu, e2e). Seule la BANNIÈRE se masque.
  it("still shows « Préparer la saison suivante » in-window even when a successor exists", async () => {
    meData = {
      currentSeasonId: "s2627",
      seasons: [
        season({ id: "s2627", name: "2026-2027", startDate: "2026-08-01", endDate: "2027-07-15" }),
        season({ id: "s2728", name: "2027-2028", startDate: "2027-08-01", endDate: "2028-07-15", isCurrent: false }),
      ],
    };
    renderSelector(day("2027-06-01")); // dans la fenêtre de la saison 2026-2027
    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    expect(screen.getByText("Préparer la saison suivante…")).toBeInTheDocument();
  });
});
