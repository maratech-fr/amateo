import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { TeamImportAnalysis, TeamImportResult } from "../api";

// On mocke la SEULE couture réseau (la ky `api`) — les fonctions d'API, les hooks de
// mutation ET `errorMessage` restent RÉELS : c'est la PROD qu'on éprouve, pas un double.
const post = vi.hoisted(() => vi.fn());
vi.mock("@/shared/api/client", () => ({
  api: { get: vi.fn(), post, put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

import { TeamsImportModal } from "./TeamsImportModal";

/** Un HTTPError réel + son `.data` (ky 2.x expose le corps parsé ainsi — cf. errorMessage). */
function httpError(status: number, body?: unknown): HTTPError {
  const response = new Response(body === undefined ? null : JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
  const error = new HTTPError(response, new Request("http://t/api/clubs/club-1/import-teams"), {} as never);
  (error as unknown as { data?: unknown }).data = body;
  return error;
}

const xlsx = (): File =>
  new File(["some xlsx bytes"], "equipes.xlsx", {
    type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  });

/** Route `api.post(...).json()` par le chemin : /analyze → analyse, sinon import. */
function wireApi(opts: {
  analysis?: TeamImportAnalysis | (() => Promise<never>);
  result?: TeamImportResult | (() => Promise<never>);
}): void {
  post.mockImplementation((path: string) => ({
    json: () => {
      const isAnalyze = path.endsWith("/analyze");
      const value = isAnalyze ? opts.analysis : opts.result;
      if (typeof value === "function") {
        return value();
      }
      return Promise.resolve(value);
    },
  }));
}

function renderModal() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const invalidateSpy = vi.spyOn(queryClient, "invalidateQueries");
  const onClose = vi.fn();
  const utils = render(
    <QueryClientProvider client={queryClient}>
      <TeamsImportModal clubId="club-1" seasonId="season-1" onClose={onClose} />
    </QueryClientProvider>,
  );
  return { ...utils, invalidateSpy, onClose };
}

const THREE_ROWS: TeamImportAnalysis = {
  rows: [
    { row: 3, name: "SM1", category: "Seniors", number: "1", alreadyPresent: false },
    { row: 4, name: "SF1", category: "Seniors", number: "2", alreadyPresent: true },
    { row: 5, name: "SM2", category: "Seniors", number: "3", alreadyPresent: false },
  ],
  errors: [],
  total: 3,
};

/** Dépose un fichier et attend que l'écran de sélection soit rendu. */
async function deposit(user: ReturnType<typeof userEvent.setup>): Promise<void> {
  await user.upload(screen.getByLabelText("Fichier FBI (.xlsx)"), xlsx());
  await screen.findByRole("checkbox", { name: /SM1/ });
}

describe("TeamsImportModal", () => {
  beforeEach(() => {
    post.mockReset();
  });

  it("dépose le fichier → analyse appelée avec un FormData file + seasonId, puis les lignes s'affichent", async () => {
    wireApi({ analysis: THREE_ROWS });
    const user = userEvent.setup();
    renderModal();

    await deposit(user);

    const call = post.mock.calls.find((c) => String(c[0]).endsWith("/import-teams/analyze"));
    expect(call, "analyze doit avoir été POSTé").toBeTruthy();
    expect(call?.[0]).toBe("clubs/club-1/import-teams/analyze");
    const form = (call?.[1] as { body: FormData }).body;
    expect(form).toBeInstanceOf(FormData);
    expect(form.get("seasonId")).toBe("season-1");
    expect((form.get("file") as File).name).toBe("equipes.xlsx");

    expect(screen.getByRole("checkbox", { name: /SM1/ })).toBeInTheDocument();
    expect(screen.getByRole("checkbox", { name: /SF1/ })).toBeInTheDocument();
    expect(screen.getByRole("checkbox", { name: /SM2/ })).toBeInTheDocument();
  });

  it("décoche par défaut les équipes déjà présentes, mais les laisse cochables", async () => {
    wireApi({ analysis: THREE_ROWS });
    const user = userEvent.setup();
    renderModal();
    await deposit(user);

    // SM1/SM2 (nouvelles) cochées ; SF1 (déjà présente) décochée.
    expect(screen.getByRole("checkbox", { name: /SM1/ })).toBeChecked();
    expect(screen.getByRole("checkbox", { name: /SM2/ })).toBeChecked();
    const sf1 = screen.getByRole("checkbox", { name: /SF1/ });
    expect(sf1).not.toBeChecked();
    // Le marqueur est du TEXTE (jamais couleur seule), lu dans le nom de la case.
    expect(sf1).toHaveAccessibleName(/déjà présente/);

    // …mais cochable à la main.
    await user.click(sf1);
    expect(sf1).toBeChecked();
  });

  it("l'import envoie EXACTEMENT les lignes cochées, et le bouton porte le compte vivant", async () => {
    wireApi({ analysis: THREE_ROWS, result: { message: "ok", created: 2, skipped: 0, errors: [] } });
    const user = userEvent.setup();
    renderModal();
    await deposit(user);

    // Défaut {3,5} → on retire SM2 (5). Reste {3}.
    await user.click(screen.getByRole("checkbox", { name: /SM2/ }));
    expect(screen.getByRole("button", { name: "Importer 1 équipe" })).toBeEnabled();

    await user.click(screen.getByRole("button", { name: "Importer 1 équipe" }));

    const call = post.mock.calls.find((c) => String(c[0]) === "clubs/club-1/import-teams");
    expect(call, "import doit avoir été POSTé").toBeTruthy();
    const form = (call?.[1] as { body: FormData }).body;
    expect(form.get("seasonId")).toBe("season-1");
    expect(form.get("rows")).toBe("[3]");
  });

  it("désarme le bouton d'import quand aucune ligne n'est cochée", async () => {
    wireApi({ analysis: THREE_ROWS });
    const user = userEvent.setup();
    renderModal();
    await deposit(user);

    // On décoche les deux seules cochées par défaut (SF1 est déjà décochée) → compte 0.
    await user.click(screen.getByRole("checkbox", { name: /SM1/ }));
    await user.click(screen.getByRole("checkbox", { name: /SM2/ }));
    expect(screen.getByRole("button", { name: "Importer 0 équipe" })).toBeDisabled();
  });

  it("affiche le rapport « N créées · M ignorées » et les erreurs 200 du serveur", async () => {
    wireApi({
      analysis: THREE_ROWS,
      result: { message: "ok", created: 13, skipped: 2, errors: ["Ligne 9 : catégorie inconnue."] },
    });
    const user = userEvent.setup();
    renderModal();
    await deposit(user);

    await user.click(screen.getByRole("button", { name: /^Importer/ }));

    expect(await screen.findByText(/13 équipes créées/)).toBeInTheDocument();
    expect(screen.getByText(/2 ignorées/)).toBeInTheDocument();
    expect(screen.getByText("Ligne 9 : catégorie inconnue.")).toBeInTheDocument();
    // La phrase de suite (complétion manuelle) est là.
    expect(screen.getByText(/Complétez maintenant chaque équipe/)).toBeInTheDocument();
  });

  it("invalide teams ET sport_categories après un import réussi", async () => {
    wireApi({ analysis: THREE_ROWS, result: { message: "ok", created: 2, skipped: 0, errors: [] } });
    const user = userEvent.setup();
    const { invalidateSpy } = renderModal();
    await deposit(user);

    await user.click(screen.getByRole("button", { name: /^Importer/ }));
    await screen.findByText(/équipes? créées?/);

    const keys = invalidateSpy.mock.calls.map((c) => JSON.stringify((c[0] as { queryKey: unknown }).queryKey));
    expect(keys).toContain(JSON.stringify(["teams"]));
    expect(keys).toContain(JSON.stringify(["sport_categories"]));
  });

  it.each([
    [413, "Fichier trop volumineux (max 2 Mo).", /trop volumineux/i],
    [422, "Ce fichier appartient à un autre club.", /autre club/i],
    [429, "Trop d'imports — réessayez plus tard.", /trop d'imports/i],
  ])("affiche tel quel le message serveur d'un %i, dans un encart d'alerte", async (status, serverMsg, expected) => {
    wireApi({ analysis: () => Promise.reject(httpError(status, { error: serverMsg })) });
    const user = userEvent.setup();
    renderModal();

    await user.upload(screen.getByLabelText("Fichier FBI (.xlsx)"), xlsx());

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent(expected);
  });

  it("traduit une panne réseau en « Problème de connexion »", async () => {
    wireApi({ analysis: () => Promise.reject(new TypeError("Failed to fetch")) });
    const user = userEvent.setup();
    renderModal();

    await user.upload(screen.getByLabelText("Fichier FBI (.xlsx)"), xlsx());

    expect(await screen.findByRole("alert")).toHaveTextContent(/problème de connexion/i);
  });

  it("se ferme par « Fermer »", async () => {
    wireApi({ analysis: THREE_ROWS });
    const user = userEvent.setup();
    const { onClose } = renderModal();

    // La primitive Modal porte aussi une croix « Fermer » (aria-label) : on cible le
    // bouton TEXTE du pied (le seul dont le contenu vaut « Fermer »).
    const footerClose = screen.getAllByRole("button", { name: "Fermer" }).find((b) => "Fermer" === b.textContent?.trim());
    await user.click(footerClose as HTMLElement);
    expect(onClose).toHaveBeenCalledOnce();
  });
});
