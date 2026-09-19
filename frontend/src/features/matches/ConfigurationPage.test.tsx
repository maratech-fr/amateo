import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { RouterProvider, createMemoryRouter, useSearchParams } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { ConfigurationPage } from "./ConfigurationPage";

// PR 2a — la Configuration perd le gabarit + les créneaux (partis en Semaine type) et gagne la
// section « Accès match ». Défaut = tout replié. On mute la couche api (PROD) ; react-query tourne.
const state: Record<string, unknown[] | "pending" | "error"> = {
  teams: [],
  venues: [],
  competitions: [],
  durations: [],
  fixtures: [],
  travel: [],
  labelInventory: [],
  matchWindows: [],
};

function serve(key: string): Promise<unknown> {
  const value = state[key];
  if ("pending" === value) return new Promise<unknown>(() => {});
  if ("error" === value) return Promise.reject(new Error("boom"));
  return Promise.resolve(value);
}

vi.mock("./api", () => ({
  getTeams: () => serve("teams"),
  getVenues: () => serve("venues"),
  getCompetitions: () => serve("competitions"),
  getSportCategoryDurations: () => serve("durations"),
  getFixtures: () => serve("fixtures"),
  getOpponentTravel: () => serve("travel"),
  getVenueLabelInventory: () => serve("labelInventory"),
  getVenueMatchWindows: () => serve("matchWindows"),
  createVenueMatchWindow: vi.fn(),
  deleteVenueMatchWindow: vi.fn(),
  updateSportCategoryDuration: vi.fn(),
  setEntryDeadlines: vi.fn(),
  attachVenueLabel: vi.fn(),
  detachVenueLabel: vi.fn(),
  resolveOpponents: vi.fn(),
  resolveOpponentTravel: vi.fn(),
  setOpponentTravelAuto: vi.fn(),
  setOpponentTravelManual: vi.fn(),
}));

// Sonde d'URL : lit `?section=` pour prouver ce que la page écrit.
function SectionProbe() {
  const [params] = useSearchParams();
  return <span data-testid="section-param">{params.get("section") ?? "(absent)"}</span>;
}

function Harness() {
  return (
    <>
      <ConfigurationPage />
      <SectionProbe />
    </>
  );
}

// Router à DEUX routes pour éprouver la redirection des anciennes clés vers la Semaine type.
function renderWithRedirectRouter(initial: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const router = createMemoryRouter(
    [
      { path: "/matchs/configuration", element: <Harness /> },
      { path: "/matchs/semaine-type", element: <div>SEMAINE_TYPE_PAGE</div> },
      { path: "/matchs/adversaires", element: <div>ADVERSAIRES_PAGE</div> },
    ],
    { initialEntries: [initial] },
  );
  return render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

const venue = (id: string, name: string) => ({ id, name, color: "#00aa00", externalLabels: [] });
const matchWindow = (venueId: string) => ({ id: `${venueId}-w`, venueId, dayOfWeek: 6, startTime: "14:00", endTime: "22:00" });

beforeEach(() => {
  state.teams = [{ id: "team-1", name: "U13", sportCategoryId: "cat-1", level: null, gender: null, priorityTierId: 3, tierOrder: 0 }];
  state.venues = [venue("venue-1", "Gymnase Alpha")];
  state.competitions = [];
  state.durations = [];
  state.fixtures = [];
  state.travel = [];
  state.labelInventory = [];
  state.matchWindows = [];
});

describe("ConfigurationPage (PR 2a — défaut tout replié, gabarit/créneaux partis)", () => {
  it("à l'arrivée : AUCUNE section ouverte (les en-têtes sont là, tous repliés)", async () => {
    renderWithProviders(<Harness />);
    const echeances = await screen.findByRole("button", { name: /^Échéances de saisie/ });
    expect(echeances).toHaveAttribute("aria-expanded", "false");
    expect(screen.getByRole("button", { name: /^Accès match/ })).toHaveAttribute("aria-expanded", "false");
    // Le corps d'une section repliée n'est pas monté.
    expect(screen.queryByText(/Les créneaux que la mairie accorde/)).not.toBeInTheDocument();
    expect(screen.getByTestId("section-param")).toHaveTextContent("(absent)");
  });

  it("ne porte PLUS le gabarit ni les créneaux (partis en Semaine type)", async () => {
    renderWithProviders(<Harness />);
    await screen.findByRole("button", { name: /^Échéances de saisie/ });
    expect(screen.queryByRole("button", { name: /gabarit idéal/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Créneaux partagés/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Habitudes & passerelles/ })).not.toBeInTheDocument();
  });

  it("un seul ouvert à la fois : ouvrir « Durée des matchs » referme « Échéances de saisie »", async () => {
    const user = userEvent.setup();
    renderWithProviders(<Harness />);
    await user.click(await screen.findByRole("button", { name: /^Échéances de saisie/ }));
    expect(screen.getByRole("heading", { name: "Échéances de saisie", level: 3 })).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /^Durée des matchs/ }));
    // L'éditeur d'échéances a disparu (démontage au repli).
    expect(screen.queryByRole("heading", { name: "Échéances de saisie", level: 3 })).not.toBeInTheDocument();
  });

  it("replier la section ouverte SUPPRIME le param (le défaut n'a plus besoin d'être encodé)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<Harness />, { route: "/matchs/configuration?section=durees" });
    // Ouverte à l'arrivée.
    expect(await screen.findByRole("button", { name: /^Durée des matchs/ })).toHaveAttribute("aria-expanded", "true");
    await user.click(screen.getByRole("button", { name: /^Durée des matchs/ }));
    expect(screen.getByTestId("section-param")).toHaveTextContent("(absent)");
  });

  it("les en-têtes portent un résumé calculé des données servies", async () => {
    state.competitions = [
      { id: "c1", teamId: "team-1", name: "PNM", competitionType: "championnat", effectiveEntryDeadline: "2026-10-01" },
      { id: "c2", teamId: "team-1", name: "RM2", competitionType: "championnat", effectiveEntryDeadline: null },
    ];
    state.matchWindows = [matchWindow("venue-1")];
    renderWithProviders(<Harness />);
    expect(await screen.findByRole("button", { name: "Échéances de saisie · 1 renseignée sur 2 compétitions" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Accès match · 1 gymnase" })).toBeInTheDocument();
  });

  it("en chargement (accès match pendant) : l'en-tête « Accès match » n'affiche AUCUN compte", async () => {
    state.matchWindows = "pending";
    renderWithProviders(<Harness />);
    expect(await screen.findByRole("button", { name: "Accès match" })).toBeInTheDocument();
  });
});

describe("ConfigurationPage — redirection des anciennes clés vers la Semaine type (PR 2a)", () => {
  it("?section=gabarit ⇒ redirige vers /matchs/semaine-type", async () => {
    renderWithRedirectRouter("/matchs/configuration?section=gabarit");
    expect(await screen.findByText("SEMAINE_TYPE_PAGE")).toBeInTheDocument();
  });

  it("?section=creneaux ⇒ redirige vers /matchs/semaine-type", async () => {
    renderWithRedirectRouter("/matchs/configuration?section=creneaux");
    expect(await screen.findByText("SEMAINE_TYPE_PAGE")).toBeInTheDocument();
  });

  it("?section=adversaires ⇒ redirige vers /matchs/adversaires (C8 — déménagé dans son onglet)", async () => {
    renderWithRedirectRouter("/matchs/configuration?section=adversaires");
    expect(await screen.findByText("ADVERSAIRES_PAGE")).toBeInTheDocument();
  });
});

describe("ConfigurationPage — les adversaires ont quitté la Configuration (C8)", () => {
  it("ne porte PLUS la section « Adversaires à localiser » (partie dans son onglet)", async () => {
    renderWithProviders(<Harness />, { route: "/matchs/configuration" });
    await screen.findByRole("button", { name: /Échéances de saisie/ });
    expect(screen.queryByRole("button", { name: /Adversaires à localiser/ })).not.toBeInTheDocument();
  });
});

describe("ConfigurationPage — la section « Accès match » (PR 2a)", () => {
  it("deep-link ?section=reglages : la liste des gymnases + « Modifier » est ouverte", async () => {
    renderWithProviders(<Harness />, { route: "/matchs/configuration?section=reglages" });
    // findBy : attendre la résolution de la query venues avant de lire la liste.
    expect(await screen.findByRole("button", { name: "Modifier les accès match de Gymnase Alpha" })).toBeInTheDocument();
    // Aucun gymnase avec accès (venue-1 sans fenêtre) → invite + liste dépliée avec Modifier.
    expect(screen.getByText(/Les créneaux que la mairie accorde/)).toBeInTheDocument();
    expect(screen.getByText(/Aucun gymnase n'accueille de matchs/)).toBeInTheDocument();
  });

  it("gymnases AVEC et SANS accès : liste principale + disclosure « N sans accès match » repliée", async () => {
    state.venues = [venue("venue-1", "Gymnase Alpha"), venue("venue-2", "Gymnase Beta")];
    state.matchWindows = [matchWindow("venue-1")];
    renderWithProviders(<Harness />, { route: "/matchs/configuration?section=reglages" });

    // venue-1 (avec accès) est dans la liste principale, avec ses plages mises en forme.
    expect(await screen.findByRole("button", { name: "Modifier les accès match de Gymnase Alpha" })).toBeInTheDocument();
    expect(screen.getByText("sam. 14:00–22:00")).toBeInTheDocument();
    // venue-2 (sans accès) est sous une disclosure repliée.
    const disclosure = screen.getByRole("button", { name: "1 gymnase sans accès match" });
    expect(disclosure).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByRole("button", { name: "Modifier les accès match de Gymnase Beta" })).not.toBeInTheDocument();
    await userEvent.click(disclosure);
    expect(screen.getByRole("button", { name: "Modifier les accès match de Gymnase Beta" })).toBeInTheDocument();
  });

  it("« Modifier » ouvre la modale SUR ce gymnase, SANS sélecteur de gymnase", async () => {
    const user = userEvent.setup();
    renderWithProviders(<Harness />, { route: "/matchs/configuration?section=reglages" });
    await user.click(await screen.findByRole("button", { name: "Modifier les accès match de Gymnase Alpha" }));

    // Le nom accessible de la modale vient de son `label` (« Accès match ») ; le titre visible porte le gymnase.
    const dialog = await screen.findByRole("dialog", { name: "Accès match" });
    expect(within(dialog).getByRole("heading", { name: "Accès match · Gymnase Alpha" })).toBeInTheDocument();
    // Une modale = un gymnase : plus de VenueSelect (« Gymnase des accès match »).
    expect(within(dialog).queryByRole("button", { name: /Gymnase des accès match/ })).not.toBeInTheDocument();
    // L'éditeur de fenêtres est bien monté (son ajout de fenêtre).
    expect(within(dialog).getByRole("button", { name: "Ajouter la fenêtre match" })).toBeInTheDocument();
  });

  it("à la fermeture, le focus revient au « Modifier » de la ligne (refocus explicite)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<Harness />, { route: "/matchs/configuration?section=reglages" });
    const modifier = await screen.findByRole("button", { name: "Modifier les accès match de Gymnase Alpha" });
    await user.click(modifier);
    const dialog = await screen.findByRole("dialog", { name: "Accès match" });
    // Deux « Fermer » portent ce nom (la croix + le bouton du pied) : on ferme par le pied.
    await user.click(within(dialog.querySelector("footer") ?? dialog).getByRole("button", { name: "Fermer" }));
    await waitFor(() => expect(screen.getByRole("button", { name: "Modifier les accès match de Gymnase Alpha" })).toHaveFocus());
  });
});

describe("ConfigurationPage — les autres sections (rappel)", () => {
  it("ouvrir « Échéances de saisie » monte son éditeur", async () => {
    const user = userEvent.setup();
    renderWithProviders(<Harness />);
    await user.click(await screen.findByRole("button", { name: /^Échéances de saisie/ }));
    expect(await screen.findByRole("heading", { name: "Échéances de saisie", level: 3 })).toBeInTheDocument();
    expect(screen.getByText(/Aucune compétition/i)).toBeInTheDocument();
  });

  it("la section « Libellés FFBB des gymnases » existe, repliée à l'arrivée ; deep-link l'ouvre", async () => {
    renderWithProviders(<Harness />, { route: "/matchs/configuration?section=libelles" });
    expect(await screen.findByText(/pointe vers un gymnase du club/i)).toBeInTheDocument();
    expect(screen.getByText(/Aucun libellé de salle importé pour l'instant/i)).toBeInTheDocument();
  });

  it("ne porte PLUS les données FBI/FFBB (migrées dans Importer)", async () => {
    renderWithProviders(<Harness />);
    await screen.findByRole("button", { name: /^Échéances de saisie/ });
    expect(screen.queryByRole("button", { name: "Engagements FFBB" })).not.toBeInTheDocument();
    expect(screen.queryByText(/Dépôt saisonnier FBI/i)).not.toBeInTheDocument();
  });
});

describe("ConfigurationPage — UXS-08 : un échec de lecture fondatrice ne se rend pas comme vide", () => {
  it("useVenues en ÉCHEC → une alerte avec réessai, jamais un « Aucun … » crédible", async () => {
    // On mute la PROD (`getVenues` rejette), pas le composant : la page est gatée sur ses deux
    // lectures fondatrices (teams + venues). Un échec doit céder à `LoadErrorHint` (role=alert),
    // jamais fabriquer un écran vide (« aucune section », « Aucun gymnase ») qui pousse à re-saisir.
    state.venues = "error";
    renderWithProviders(<Harness />);
    expect(await screen.findByRole("alert")).toBeInTheDocument();
    // Aucun vide crédible n'est rendu.
    expect(screen.queryByText(/Aucun/i)).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Échéances de saisie/ })).not.toBeInTheDocument();
  });
});
