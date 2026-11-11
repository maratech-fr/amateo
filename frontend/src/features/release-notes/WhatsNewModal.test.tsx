import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, render, screen, waitFor, within } from "@testing-library/react";
import { createMemoryRouter, RouterProvider } from "react-router";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ActionVeil } from "@/app/ActionVeil";
import { renderWithProviders } from "@/test/utils";

import { frDateSlashed } from "@/shared/lib/date";
import { queryClient as appQueryClient } from "@/shared/lib/queryClient";
import { useToastStore } from "@/shared/stores/toastStore";

import type { ReleaseNoteItem, ReleaseNotesResponse } from "./api";
import { getReleaseNotes, markReleaseNotesSeen } from "./api";
import { RELEASE_NOTES_TEXTS } from "./texts";
import { WhatsNewModal } from "./WhatsNewModal";

vi.mock("./api", async (importOriginal) => {
  const original = await importOriginal<typeof import("./api")>();
  return { ...original, getReleaseNotes: vi.fn(), markReleaseNotesSeen: vi.fn() };
});

const mockGet = vi.mocked(getReleaseNotes);
const mockSeen = vi.mocked(markReleaseNotesSeen);

const NOTE: ReleaseNoteItem = {
  id: "n1",
  date: "2026-08-13",
  title: "Nouvelle vue planning",
  body: "Un récapitulatif plus lisible.",
  publishedAt: "2026-08-13T09:00:00+00:00",
  version: "1.3.5",
  bodyRich: null,
};

// Trois notes fraîches dont l'ordre de l'API (date éditoriale) N'EST PAS l'ordre de publication :
// la plus récemment PUBLIÉE est `n2`, au milieu de la liste — un affichage de `items[0]` se trompe.
const OLDEST: ReleaseNoteItem = { id: "n1", date: "2026-11-20", title: "Export PDF des matchs", body: "Corps 1", publishedAt: "2026-11-01T09:00:00+00:00", version: "1.3.3", bodyRich: null };
const NEWEST: ReleaseNoteItem = { id: "n2", date: "2026-11-15", title: "Nouvelle vue planning", body: "Corps 2", publishedAt: "2026-11-15T09:00:00+00:00", version: "1.3.5", bodyRich: null };
const MIDDLE: ReleaseNoteItem = { id: "n3", date: "2026-11-08", title: "Gymnases fermés", body: "Corps 3", publishedAt: "2026-11-08T09:00:00+00:00", version: null, bodyRich: null };

function response(overrides: Partial<ReleaseNotesResponse> = {}): ReleaseNotesResponse {
  return { seenUpTo: "2026-08-01T00:00:00+00:00", items: [NOTE], ...overrides };
}

describe("WhatsNewModal", () => {
  beforeEach(() => {
    mockGet.mockReset();
    mockSeen.mockReset();
    mockSeen.mockResolvedValue(undefined);
  });

  it("s'ouvre quand une note a été publiée APRÈS le filigrane", async () => {
    mockGet.mockResolvedValue(response());
    renderWithProviders(<WhatsNewModal />);

    expect(await screen.findByRole("dialog", { name: /quoi de neuf/i })).toBeInTheDocument();
    expect(screen.getByText("Nouvelle vue planning")).toBeInTheDocument();
  });

  it("ne s'ouvre PAS pour un nouvel inscrit (seenUpTo null) et pose le filigrane en silence", async () => {
    mockGet.mockResolvedValue(response({ seenUpTo: null }));
    renderWithProviders(<WhatsNewModal />);

    // Le POST /seen part tout seul au premier chargement…
    await waitFor(() => expect(mockSeen).toHaveBeenCalledTimes(1));
    // …mais aucune modale ne s'affiche.
    expect(screen.queryByRole("dialog", { name: /quoi de neuf/i })).not.toBeInTheDocument();
  });

  it("ne s'ouvre pas quand aucune note n'est postérieure au filigrane", async () => {
    mockGet.mockResolvedValue(response({ seenUpTo: "2026-09-01T00:00:00+00:00" }));
    renderWithProviders(<WhatsNewModal />);

    await waitFor(() => expect(mockGet).toHaveBeenCalled());
    expect(screen.queryByRole("dialog", { name: /quoi de neuf/i })).not.toBeInTheDocument();
  });

  // Bug e2e récurrent (#684/#687/#689/#694, diagnostiqué au trace le 2026-08-22) : le POST
  // silencieux est une MUTATION — sans exemption `meta.veil`, l'ActionVeil rendait l'app `inert`
  // à 0 ms (voile invisible sous 250 ms). Or ce POST part ~1,5 s après l'arrivée d'un nouvel
  // inscrit sur le wizard : exactement quand il tape le nom de sa première équipe. Ses frappes
  // étaient avalées SANS retour visuel — le mode d'échec que le fondateur a nommé « pire que pas
  // de voile » (en-tête d'ActionVeil.tsx). Un POST d'entretien ne protège aucun geste parti.
  it("le filigrane silencieux ne gèle JAMAIS l'écran — l'utilisateur est peut-être en train de taper", async () => {
    mockGet.mockResolvedValue(response({ seenUpTo: null }));
    // Le POST reste EN VOL pour toute la durée du test : c'est pendant ce vol que le voile mangeait.
    mockSeen.mockReturnValue(new Promise(() => {}));

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={qc}>
        <ActionVeil>
          <WhatsNewModal />
        </ActionVeil>
      </QueryClientProvider>,
    );

    await waitFor(() => expect(mockSeen).toHaveBeenCalledTimes(1));
    // Laisser la propagation react-query (mutation pending → useIsMutating) se régler : sans ce
    // battement, la version BOGUÉE passerait aussi — l'inert n'était pas encore posé.
    await act(async () => new Promise((resolve) => setTimeout(resolve, 20)));

    expect(screen.getByTestId("veil-content")).not.toHaveAttribute("inert");
  });

  it("« J'ai compris » marque le journal lu et ferme la modale", async () => {
    const user = userEvent.setup();
    mockGet.mockResolvedValue(response());
    renderWithProviders(<WhatsNewModal />);

    await screen.findByRole("dialog", { name: /quoi de neuf/i });
    await user.click(screen.getByRole("button", { name: /j'ai compris/i }));

    expect(mockSeen).toHaveBeenCalled();
    await waitFor(() => expect(screen.queryByRole("dialog", { name: /quoi de neuf/i })).not.toBeInTheDocument());
  });
});

/**
 * Promesse (A1) : **la modale montre UNE seule nouveauté — la dernière PUBLIÉE — et dit combien
 * d'autres attendent**, au lieu d'empiler toutes les notes manquées. L'ordre de l'API (date
 * éditoriale, antidatable) n'est pas celui de la publication : c'est `publishedAt` qui décide.
 */
describe("WhatsNewModal — une seule note à l'ouverture", () => {
  beforeEach(() => {
    mockGet.mockReset();
    mockSeen.mockReset();
    mockSeen.mockResolvedValue(undefined);
  });

  it("3 notes fraîches : affiche la plus récemment publiée seule, « et 2 autres nouveautés » et Voir tout", async () => {
    mockGet.mockResolvedValue(response({ items: [OLDEST, NEWEST, MIDDLE] }));
    renderWithProviders(<WhatsNewModal />);

    const dialog = await screen.findByRole("dialog", { name: RELEASE_NOTES_TEXTS.modalLabel });
    const titles = within(dialog).getAllByRole("heading", { level: 3 });
    expect(titles.map((title) => title.textContent)).toEqual([NEWEST.title]);
    expect(within(dialog).queryByText(OLDEST.title)).not.toBeInTheDocument();
    expect(within(dialog).queryByText(MIDDLE.title)).not.toBeInTheDocument();
    expect(within(dialog).getByText(RELEASE_NOTES_TEXTS.modalOthers(2))).toBeInTheDocument();
    expect(within(dialog).getByRole("link", { name: RELEASE_NOTES_TEXTS.modalSeeAll })).toHaveAttribute("href", "/nouveautes");
  });

  it("2 notes fraîches : « et 1 autre nouveauté » (singulier)", async () => {
    mockGet.mockResolvedValue(response({ items: [OLDEST, NEWEST] }));
    renderWithProviders(<WhatsNewModal />);

    const dialog = await screen.findByRole("dialog", { name: RELEASE_NOTES_TEXTS.modalLabel });
    expect(within(dialog).getByText(RELEASE_NOTES_TEXTS.modalOthers(1))).toBeInTheDocument();
  });

  it("1 note fraîche : aucun compteur, le lien Voir tout reste", async () => {
    mockGet.mockResolvedValue(response({ items: [NEWEST] }));
    renderWithProviders(<WhatsNewModal />);

    const dialog = await screen.findByRole("dialog", { name: RELEASE_NOTES_TEXTS.modalLabel });
    expect(within(dialog).queryByText(RELEASE_NOTES_TEXTS.modalOthers(0))).not.toBeInTheDocument();
    expect(within(dialog).queryByText(RELEASE_NOTES_TEXTS.modalOthers(1))).not.toBeInTheDocument();
    expect(within(dialog).getByRole("link", { name: RELEASE_NOTES_TEXTS.modalSeeAll })).toBeInTheDocument();
    expect(within(dialog).getByRole("button", { name: RELEASE_NOTES_TEXTS.modalGotIt })).toBeInTheDocument();
  });

  it("une note déjà lue (publiée avant le filigrane) n'entre ni dans l'affichage ni dans le compteur", async () => {
    // Filigrane au 10/11 : OLDEST (01/11) et MIDDLE (08/11) sont lues, seule NEWEST est fraîche.
    mockGet.mockResolvedValue(response({ seenUpTo: "2026-11-10T00:00:00+00:00", items: [OLDEST, NEWEST, MIDDLE] }));
    renderWithProviders(<WhatsNewModal />);

    const dialog = await screen.findByRole("dialog", { name: RELEASE_NOTES_TEXTS.modalLabel });
    expect(within(dialog).getByRole("heading", { level: 3, name: NEWEST.title })).toBeInTheDocument();
    expect(within(dialog).queryByText(RELEASE_NOTES_TEXTS.modalOthers(1))).not.toBeInTheDocument();
    expect(within(dialog).queryByText(RELEASE_NOTES_TEXTS.modalOthers(2))).not.toBeInTheDocument();
  });
});

/**
 * Promesse (A3) : **dans la modale, la note s'annonce « date - version », puis son TITRE (un vrai
 * titre `<h3>`, sous le `<h2>` de la modale), puis son corps** — dans cet ordre de lecture.
 * Jumeau page : `ReleaseNotesPage.test.tsx` (même ordre, titre `<h2>` sous le `<h1>` de la page).
 */
describe("WhatsNewModal — en-tête date-version, titre, corps", () => {
  beforeEach(() => {
    mockGet.mockReset();
    mockSeen.mockReset();
    mockSeen.mockResolvedValue(undefined);
  });

  it("ordre de lecture : en-tête « date - version » → titre h3 → corps", async () => {
    mockGet.mockResolvedValue(response({ items: [NEWEST] }));
    renderWithProviders(<WhatsNewModal />);

    const dialog = await screen.findByRole("dialog", { name: RELEASE_NOTES_TEXTS.modalLabel });
    const heading = within(dialog).getByText(RELEASE_NOTES_TEXTS.heading(frDateSlashed(NEWEST.date), "1.3.5"));
    const title = within(dialog).getByRole("heading", { level: 3, name: NEWEST.title });
    const body = within(dialog).getByText(NEWEST.body);

    expect(heading.compareDocumentPosition(title) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(title.compareDocumentPosition(body) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it("note sans version : l'en-tête est la date seule", async () => {
    mockGet.mockResolvedValue(response({ items: [MIDDLE] }));
    renderWithProviders(<WhatsNewModal />);

    const dialog = await screen.findByRole("dialog", { name: RELEASE_NOTES_TEXTS.modalLabel });
    expect(within(dialog).getByText(RELEASE_NOTES_TEXTS.heading(frDateSlashed(MIDDLE.date), null))).toBeInTheDocument();
  });
});

/**
 * Promesse (PR B, B3) : **dans la modale, une note riche se lit mise en forme — ses titres au niveau
 * 4 (sous le `<h3>` du titre de note), son gras en `<strong>`, une balise saisie en TEXTE** ; une note
 * texte (`bodyRich: null`, note d'avant l'éditeur) garde son rendu brut `whitespace-pre-line`.
 * Rendu unitaire : `shared/components/ui/rich-text.test.tsx` ; jumeau page : `ReleaseNotesPage.test.tsx`.
 */
describe("WhatsNewModal — corps riche", () => {
  beforeEach(() => {
    mockGet.mockReset();
    mockSeen.mockReset();
    mockSeen.mockResolvedValue(undefined);
  });

  it("note riche : titre du corps en <h4>, gras en <strong>, balise affichée littéralement", async () => {
    const rich = {
      ...NEWEST,
      body: "Export PDF\n<img src=x onerror=alert(1)>",
      bodyRich: {
        type: "doc",
        content: [
          { type: "heading", attrs: { level: 3 }, content: [{ type: "text", text: "Export PDF" }] },
          { type: "paragraph", content: [{ type: "text", text: "<img src=x onerror=alert(1)>", marks: [{ type: "bold" }] }] },
        ],
      },
    } as ReleaseNoteItem;
    mockGet.mockResolvedValue(response({ items: [rich] }));
    renderWithProviders(<WhatsNewModal />);

    const dialog = await screen.findByRole("dialog", { name: RELEASE_NOTES_TEXTS.modalLabel });
    expect(within(dialog).getByRole("heading", { level: 3, name: NEWEST.title })).toBeInTheDocument();
    expect(within(dialog).getByRole("heading", { level: 4, name: "Export PDF" })).toBeInTheDocument();
    const strong = within(dialog).getByText("<img src=x onerror=alert(1)>");
    expect(strong.closest("strong")).not.toBeNull();
    expect(dialog.querySelector("img")).toBeNull();
    // Le miroir texte n'est pas affiché EN PLUS du corps riche.
    expect(dialog.querySelector(".whitespace-pre-line")).toBeNull();
  });

  it("note texte (bodyRich null) : corps brut en whitespace-pre-line", async () => {
    mockGet.mockResolvedValue(response({ items: [{ ...NEWEST, bodyRich: null } as ReleaseNoteItem] }));
    renderWithProviders(<WhatsNewModal />);

    const dialog = await screen.findByRole("dialog", { name: RELEASE_NOTES_TEXTS.modalLabel });
    expect(within(dialog).getByText(NEWEST.body)).toHaveClass("whitespace-pre-line");
  });

  /**
   * Petites lignes (hiérarchie des titres, Q3) : dans la modale, le titre de note fait 16 px
   * (`text-base`, comme le titre de la fenêtre — jamais plus gros que lui) et reste PLUS GRAND que
   * l'intertitre du corps (14 px). Contrat de classes ; l'intertitre lui-même est gardé par
   * `shared/components/ui/rich-text.test.tsx`, la page par `ReleaseNotesPage.test.tsx`.
   */
  it("hiérarchie : titre de note en text-base, plus grand que l'intertitre du corps", async () => {
    const rich = {
      ...NEWEST,
      bodyRich: { type: "doc", content: [{ type: "heading", attrs: { level: 3 }, content: [{ type: "text", text: "Export PDF" }] }] },
    } as ReleaseNoteItem;
    mockGet.mockResolvedValue(response({ items: [rich] }));
    renderWithProviders(<WhatsNewModal />);

    const dialog = await screen.findByRole("dialog", { name: RELEASE_NOTES_TEXTS.modalLabel });
    const title = within(dialog).getByRole("heading", { level: 3, name: NEWEST.title });
    const intertitle = within(dialog).getByRole("heading", { level: 4, name: "Export PDF" });

    expect(title.className.split(/\s+/)).toContain("text-base");
    expect(textSizeRank(title)).toBeGreaterThan(textSizeRank(intertitle));
  });
});

/** Rang de la classe de taille Tailwind d'un élément (−1 si aucune) — compare deux titres. */
function textSizeRank(element: HTMLElement): number {
  const scale = ["text-xs", "text-sm", "text-base", "text-lg", "text-xl", "text-2xl"];
  const classes = element.className.split(/\s+/);
  return scale.findIndex((size) => classes.includes(size));
}

/**
 * Promesse (A2, décision fondateur) : **TOUTE sortie de la modale enregistre la lecture** — croix,
 * Échap, clic sur le fond, « Voir tout », comme « J'ai compris » (test ci-dessus) : la modale ne
 * revient pas au prochain chargement, sur aucun appareil (horodatage serveur). Un POST par sortie.
 */
describe("WhatsNewModal — toute sortie enregistre la lecture", () => {
  beforeEach(() => {
    mockGet.mockReset();
    mockSeen.mockReset();
    mockSeen.mockResolvedValue(undefined);
  });

  async function openModal(): Promise<HTMLElement> {
    mockGet.mockResolvedValue(response({ items: [NEWEST] }));
    renderWithProviders(<WhatsNewModal />);
    return screen.findByRole("dialog", { name: RELEASE_NOTES_TEXTS.modalLabel });
  }

  async function expectSeenOnceAndClosed(): Promise<void> {
    await waitFor(() => expect(screen.queryByRole("dialog", { name: RELEASE_NOTES_TEXTS.modalLabel })).not.toBeInTheDocument());
    expect(mockSeen).toHaveBeenCalledTimes(1);
  }

  it("la croix enregistre la lecture et ferme", async () => {
    const user = userEvent.setup();
    const dialog = await openModal();

    // Le bouton de fermeture appartient à la primitive `Modal` (libellé non migré).
    await user.click(within(dialog).getByRole("button", { name: "Fermer" }));

    await expectSeenOnceAndClosed();
  });

  it("Échap enregistre la lecture et ferme", async () => {
    const user = userEvent.setup();
    await openModal();

    await user.keyboard("{Escape}");

    await expectSeenOnceAndClosed();
  });

  it("le clic sur le fond enregistre la lecture et ferme", async () => {
    const user = userEvent.setup();
    const dialog = await openModal();

    // Le fond est le voile `aria-hidden` frère du panneau (modal.tsx) : seul moyen de le viser.
    const backdrop = dialog.previousElementSibling;
    expect(backdrop).not.toBeNull();
    await user.click(backdrop as HTMLElement);

    await expectSeenOnceAndClosed();
  });

  it("« Voir tout » enregistre la lecture et ferme", async () => {
    const user = userEvent.setup();
    const dialog = await openModal();

    await user.click(within(dialog).getByRole("link", { name: RELEASE_NOTES_TEXTS.modalSeeAll }));

    await expectSeenOnceAndClosed();
  });

  it("un échec du POST après la croix : la modale se ferme quand même et le filet global toaste UNE fois", async () => {
    const user = userEvent.setup();
    mockGet.mockResolvedValue(response({ items: [NEWEST] }));
    mockSeen.mockRejectedValue(new Error("réseau coupé"));
    useToastStore.setState({ toasts: [] });
    appQueryClient.clear();

    // Le VRAI client de l'app : c'est son `MutationCache.onError` (le filet global) qui doit toaster,
    // ce que le client nu de `renderWithProviders` ne porte pas.
    const router = createMemoryRouter([{ path: "*", element: <WhatsNewModal /> }], { initialEntries: ["/"] });
    render(
      <QueryClientProvider client={appQueryClient}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    );

    const dialog = await screen.findByRole("dialog", { name: RELEASE_NOTES_TEXTS.modalLabel });
    await user.click(within(dialog).getByRole("button", { name: "Fermer" }));

    await waitFor(() => expect(screen.queryByRole("dialog", { name: RELEASE_NOTES_TEXTS.modalLabel })).not.toBeInTheDocument());
    await waitFor(() => expect(useToastStore.getState().toasts).toHaveLength(1));
    // Laisser un éventuel second toast arriver avant de conclure (un `onError` local en plus du filet).
    await act(async () => new Promise((resolve) => setTimeout(resolve, 20)));
    expect(useToastStore.getState().toasts).toHaveLength(1);
    expect(useToastStore.getState().toasts[0]?.variant).toBe("error");

    appQueryClient.clear();
    useToastStore.setState({ toasts: [] });
  });
});
