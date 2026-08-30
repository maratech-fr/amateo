import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Route, Routes } from "react-router";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { PublicWishContext } from "./publicApi";

const h = { getContext: vi.fn(), submit: vi.fn() };
vi.mock("./publicApi", async (importOriginal) => ({
  ...(await importOriginal<typeof import("./publicApi")>()),
  getPublicWishContext: (token: string) => h.getContext(token),
  submitPublicWishes: (token: string, submissions: unknown) => h.submit(token, submissions),
}));

import { PublicWishPage } from "./PublicWishPage";

const context = (over: Partial<PublicWishContext> = {}): PublicWishContext => ({
  coachFirstName: "Maxime",
  periodTitle: "Vacances de février",
  deadline: "2027-06-30",
  weeks: ["2026-02-16"],
  teams: [{ id: "t1", name: "SM1" }],
  wishes: [{ teamId: "t1", weekStart: "2026-02-16", slotsWanted: 2, unavailableDays: [3], comment: "note manager" }],
  respondedAt: null,
  ...over,
});

const httpError = (status: number): HTTPError => new HTTPError(new Response(null, { status }), new Request("http://x/api/coach-wishes/public/tok"), {} as never);

// Clé COURANTE du brouillon (`wishDraft.ts`). Elle portait l'ancien nom de produit jusqu'au
// 2026-08-21 : le repli de lecture a été supprimé, ce test seede donc la vraie clé — sans quoi
// il vérifiait une restauration que le code ne fait plus.
const DRAFT_KEY = (token: string): string => `amateo:wish-draft:${token}`;

function renderAt() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={["/doleances/abc"]}>
        <Routes>
          <Route path="/doleances/:token" element={<PublicWishPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

/** Passe de l'intro à la première étape équipe. */
async function start() {
  await userEvent.click(await screen.findByRole("button", { name: /Commencer/ }));
}

describe("PublicWishPage — parcours en étapes", () => {
  beforeEach(() => {
    h.getContext.mockReset();
    h.submit.mockReset();
    sessionStorage.clear();
  });

  it("ouvre sur l'intro (le pourquoi), et pré-remplit l'étape équipe avec l'état courant", async () => {
    h.getContext.mockResolvedValue(context());
    renderAt();

    // Intro d'abord : le texte d'accueil, pas encore les champs de saisie.
    expect(await screen.findByText(/votre club prépare le planning/)).toBeInTheDocument();
    expect(screen.queryByLabelText(/Séances souhaitées/)).not.toBeInTheDocument();

    await start();
    // Pré-remplissage « au nom de » sur l'étape de l'équipe.
    expect(screen.getByLabelText(/Séances souhaitées — SM1/)).toHaveValue(2);
    expect(screen.getByLabelText(/Mer indisponible — SM1/)).toBeChecked();
  });

  it("NR — le payload d'envoi reste octet-identique et ne part QU'À la validation (dirty-tracking)", async () => {
    h.getContext.mockResolvedValue(context());
    h.submit.mockResolvedValue({ deadline: "2027-06-30" });
    renderAt();
    await start();

    // On modifie les séances sur l'étape équipe.
    const slots = screen.getByLabelText(/Séances souhaitées — SM1/);
    await userEvent.clear(slots);
    await userEvent.type(slots, "4");

    // Rien n'est parti pendant la traversée.
    await userEvent.click(screen.getByRole("button", { name: "Suivant" }));
    expect(h.submit).not.toHaveBeenCalled();

    // La validation, depuis le récap, envoie EXACTEMENT la section modifiée.
    await userEvent.click(screen.getByRole("button", { name: /Valider et envoyer/ }));
    await waitFor(() => expect(h.submit).toHaveBeenCalledTimes(1));
    expect(h.submit).toHaveBeenCalledWith("abc", [{ teamId: "t1", weekStart: "2026-02-16", slotsWanted: 4, unavailableDays: [3], comment: "note manager" }]);
  });

  it("« Rien à signaler » avance sans rien modifier ; le récap affiche « aucune modification »", async () => {
    h.getContext.mockResolvedValue(context());
    h.submit.mockResolvedValue({ deadline: "2027-06-30" });
    renderAt();
    await start();

    await userEvent.click(screen.getByRole("button", { name: /Rien à signaler/ }));
    // Au récap, l'équipe non touchée est annoncée sans modification.
    expect(screen.getByText(/aucune modification/)).toBeInTheDocument();
  });

  it("0 modification → « Confirmer sans modification » envoie un tableau vide", async () => {
    h.getContext.mockResolvedValue(context());
    h.submit.mockResolvedValue({ deadline: "2027-06-30" });
    renderAt();
    await start();
    await userEvent.click(screen.getByRole("button", { name: /Rien à signaler/ }));

    await userEvent.click(screen.getByRole("button", { name: /Confirmer sans modification/ }));
    await waitFor(() => expect(h.submit).toHaveBeenCalledTimes(1));
    expect(h.submit).toHaveBeenCalledWith("abc", []);
  });

  it("une modif à l'étape 2, un retour arrière, puis validation : la modif part quand même", async () => {
    h.getContext.mockResolvedValue(
      context({
        teams: [
          { id: "t1", name: "SM1" },
          { id: "t2", name: "U13" },
        ],
        wishes: [{ teamId: "t1", weekStart: "2026-02-16", slotsWanted: 2, unavailableDays: [3], comment: "note manager" }],
      }),
    );
    h.submit.mockResolvedValue({ deadline: "2027-06-30" });
    renderAt();
    await start(); // SM1

    await userEvent.click(screen.getByRole("button", { name: "Suivant" })); // U13
    const u13 = screen.getByLabelText(/Séances souhaitées — U13/);
    await userEvent.clear(u13);
    await userEvent.type(u13, "3");

    // Retour arrière sur SM1, puis on ré-avance jusqu'au récap.
    await userEvent.click(screen.getByRole("button", { name: "Précédent" })); // SM1
    expect(screen.getByLabelText(/Séances souhaitées — SM1/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Suivant" })); // U13 (valeur conservée)
    expect(screen.getByLabelText(/Séances souhaitées — U13/)).toHaveValue(3);
    await userEvent.click(screen.getByRole("button", { name: "Suivant" })); // récap

    await userEvent.click(screen.getByRole("button", { name: /Valider et envoyer/ }));
    await waitFor(() => expect(h.submit).toHaveBeenCalledTimes(1));
    expect(h.submit).toHaveBeenCalledWith("abc", [{ teamId: "t2", weekStart: "2026-02-16", slotsWanted: 3, unavailableDays: [], comment: null }]);
  });

  it("depuis le récap, « Modifier » saute à l'équipe puis revient au récap", async () => {
    h.getContext.mockResolvedValue(context());
    h.submit.mockResolvedValue({ deadline: "2027-06-30" });
    renderAt();
    await start();
    await userEvent.click(screen.getByRole("button", { name: "Suivant" })); // récap

    await userEvent.click(screen.getByRole("button", { name: "Modifier" }));
    expect(screen.getByLabelText(/Séances souhaitées — SM1/)).toBeInTheDocument();
    // Le bouton d'avance ramène droit au récap (pas de re-traversée).
    await userEvent.click(screen.getByRole("button", { name: /Revenir au récapitulatif/ }));
    expect(screen.getByRole("button", { name: /Confirmer sans modification|Valider et envoyer/ })).toBeInTheDocument();
  });

  it("respondedAt non-null → l'intro annonce « déjà répondu » et propose de réviser", async () => {
    h.getContext.mockResolvedValue(context({ respondedAt: "2026-02-01T10:00:00Z" }));
    renderAt();
    expect(await screen.findByText(/déjà répondu le 01\/02\/2026/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Réviser mes réponses/ })).toBeInTheDocument();
  });

  it("la progression marque l'étape courante avec aria-current=\"step\"", async () => {
    h.getContext.mockResolvedValue(context());
    const { container } = renderAt();
    await screen.findByText(/votre club prépare le planning/);
    expect(container.querySelector('[aria-current="step"]')).toHaveTextContent("Début");

    await start();
    expect(container.querySelector('[aria-current="step"]')).toHaveTextContent("SM1");
  });

  it("restaure le brouillon sessionStorage au montage et le purge après un envoi réussi", async () => {
    // Un onglet rechargé : brouillon local (section modifiée + étape équipe).
    sessionStorage.setItem(DRAFT_KEY("abc"), JSON.stringify({ sections: { "t1|2026-02-16": { slotsWanted: 5, days: [3], comment: "note manager" } }, stepIndex: 1 }));
    h.getContext.mockResolvedValue(context());
    h.submit.mockResolvedValue({ deadline: "2027-06-30" });
    renderAt();

    // On reprend directement sur l'étape équipe, valeur restaurée (pas l'intro).
    expect(await screen.findByLabelText(/Séances souhaitées — SM1/)).toHaveValue(5);

    await userEvent.click(screen.getByRole("button", { name: "Suivant" })); // récap
    await userEvent.click(screen.getByRole("button", { name: /Valider et envoyer/ }));
    await waitFor(() => expect(h.submit).toHaveBeenCalledTimes(1));
    // Purge du filet local au succès.
    await waitFor(() => expect(sessionStorage.getItem(DRAFT_KEY("abc"))).toBeNull());
  });

  // P4-150 — aucune équipe concernée : la copie d'écran de l'état vide est assertée.
  it("affiche « aucune de vos équipes n'est concernée » quand le contexte ne porte aucune équipe", async () => {
    h.getContext.mockResolvedValue(context({ teams: [] }));
    renderAt();
    expect(await screen.findByText(/Aucune de vos équipes n'est concernée par cette collecte pour le moment\. Rapprochez-vous de votre club\./)).toBeInTheDocument();
  });

  it("affiche « lien invalide » sur 404", async () => {
    h.getContext.mockRejectedValue(httpError(404));
    renderAt();
    expect(await screen.findByText(/Lien invalide/)).toBeInTheDocument();
  });

  it("affiche « lien expiré » sur 410", async () => {
    h.getContext.mockRejectedValue(httpError(410));
    renderAt();
    expect(await screen.findByText(/Lien expiré/)).toBeInTheDocument();
  });
});
