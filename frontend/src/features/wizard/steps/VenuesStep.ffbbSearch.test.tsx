import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { type ReactNode, useState } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { WizardFooterContext } from "../lib/footerSlot";
import { WIZARD_TEXTS } from "../texts";
import type { FfbbSalle, Venue, VenuePayload } from "../api";

/**
 * Retour Rillieux #20 (améliorations légères 6/6) — PROMESSE : depuis l'étape « Gymnases », quand
 * un gymnase est INTROUVABLE dans les listes de la commune / de proximité, le gestionnaire le
 * cherche par son NOM dans TOUTE la FFBB (route serveur `/api/ffbb/salles?q=`), et
 *   1. s'il est TROUVÉ, il le crée dans le MÊME GESTE AVEC son ancrage fédéral (numéro) ET son
 *      adresse/position FFBB — de sorte que les trajets se calculent (le gymnase « Mont Blanc »
 *      de Rillieux avait été créé à la main SANS adresse → 0 trajet) ;
 *   2. s'il n'est PAS trouvé (réellement absent de la FFBB), le chemin de création manuelle
 *      existant reste disponible et produit un gymnase SANS ancrage menteur.
 *
 * NIVEAU — le plus bas qui prouve VRAIMENT : composant Vitest (jsdom). Tout est visible sans
 * navigateur (présence du résultat de recherche, payload de création). La route `?q=` elle-même est
 * prouvée côté serveur (`backend/tests/Integration/Api/FfbbSallesTest.php:77`), le contrat de
 * réponse `{ postalCode, salles[] }` aussi — ce mock envoie EXACTEMENT cette forme. La confirmation
 * de recalcul des trajets (« Trajets recalculés. » au terminal `VENUE_MATRIX` du flux `useTravelStream`)
 * est DÉJÀ prouvée au même niveau par `VenuesStep.test.tsx:131` : on ne la duplique pas ; ici on
 * prouve seulement que la création porte la position (ce qui DONNE au serveur de quoi recalculer).
 *
 * On mute la PROD (le vrai `VenuesStep`), pas un double : seule `../queries` est stubée. La recherche
 * par nom est attendue via un hook `useFfbbSallesByName(query)` (que NEO câblera sur
 * `/api/ffbb/salles?q=`) — le mock enregistre qu'il a été CONSULTÉ et renvoie la forme réelle de l'API.
 *
 * ROUGE AVANT NEO : `VenuesStep` n'importe/consulte pas encore `useFfbbSallesByName` et n'affiche
 * aucun résultat de recherche par nom → le gymnase trouvé n'apparaît jamais (test 1) et
 * `h.nameSearch.used` reste faux (test 2). VERT une fois la recherche par nom câblée + le résultat
 * créé avec son ancrage. Option A : si NEO place la recherche derrière un affordance « Introuvable à
 * la FFBB ? » à cliquer, ou rend le résultat par un autre élément cliquable, il ajuste
 * MÉCANIQUEMENT le geste (clic/sélecteur) — l'ASSERTION protégée reste le payload de création.
 */

const FOUND: FfbbSalle = {
  name: "GYMNASE DU MONT BLANC",
  address: "10 RUE DU MONT BLANC",
  city: "Rillieux-la-Pape",
  postalCode: "69140",
  externalRef: "166900042",
  latitude: "45.81732",
  longitude: "4.89901",
};

type CreateBody = VenuePayload;

const h = vi.hoisted(() => ({
  venues: { data: [] as Venue[] },
  created: { value: null as Venue | null },
  createCalls: [] as CreateBody[],
  nameSearch: { used: false, lastQuery: "", salles: [] as FfbbSalle[] },
}));

vi.mock("@/shared/lib/travelStream", () => ({
  useTravelStream: () => ({ connected: false, latest: null }),
}));

vi.mock("../queries", () => ({
  useWizardVenues: () => ({ data: h.venues.data, isSuccess: true }),
  useWizardTeams: () => ({ data: [] }),
  useWizardCoaches: () => ({ data: [] }),
  useVenueSlots: () => ({ data: [] }),
  useReservations: () => ({ data: [] }),
  useVenueGeoCheck: () => ({ data: [] }),
  // La création mémorise le gymnase créé (exposé par `data`, comme l'API) ET consigne le payload
  // reçu : c'est lui qui prouve « créé AVEC son adresse/ancrage » (test 1) ou « sans ancrage » (test 2).
  useCreateVenue: () => ({
    mutate: (body: CreateBody, opts?: { onSuccess?: (created: Venue) => void }) => {
      h.createCalls.push(body);
      const created: Venue = {
        id: "created-id",
        name: body.name,
        color: body.color ?? "#3498DB",
        canSplit: body.canSplit ?? false,
        isActive: true,
        externalRef: body.externalRef ?? null,
        address: body.address ?? null,
        latitude: body.latitude ?? null,
        longitude: body.longitude ?? null,
      };
      h.created.value = created;
      opts?.onSuccess?.(created);
    },
    data: h.created.value,
    isPending: false,
  }),
  useUpdateVenue: () => ({ mutate: vi.fn(), isPending: false }),
  useLocateVenue: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteVenue: () => ({ mutate: vi.fn() }),
  useCreateSlot: () => ({ mutate: vi.fn() }),
  useUpdateSlot: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteSlot: () => ({ mutate: vi.fn() }),
  useDeletionImpact: () => ({ data: undefined, isPending: false, isError: false }),
  // Commune + proximité VOLONTAIREMENT vides : le gymnase cherché n'est PAS dans la commune (le cas
  // de #20) — seule la recherche par NOM peut le ramener.
  useFfbbSalles: () => ({ data: { salles: [] } }),
  useFfbbSallesProches: () => ({ data: { salles: [] } }),
  // Recherche par NOM dans toute la FFBB (`/api/ffbb/salles?q=`). Le mock consigne qu'il a été
  // CONSULTÉ (preuve que la PROD a câblé le hook) et renvoie la forme réelle `{ postalCode, salles }`.
  useFfbbSallesByName: (query: string) => {
    h.nameSearch.used = true;
    h.nameSearch.lastQuery = query;
    return { data: { postalCode: null, salles: h.nameSearch.salles }, isPending: false };
  },
}));

vi.mock("@/features/matches/queries", () => ({ useVenueMatchWindows: () => ({ data: [] }) }));
vi.mock("@/features/matches/MatchWindowsEditor", () => ({ MatchWindowsEditor: () => null }));
vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: { club: { id: "club-1", postalCode: "69140" } } }), useWorkingSeason: () => undefined }));
vi.mock("../parcImport/ParcImportModal", () => ({ ParcImportModal: () => null }));
vi.mock("@/shared/lib/socle", () => ({ useSocleValidated: () => false }));
vi.mock("@/shared/components/ui/venue-select", () => ({ VenueSelect: () => null }));
vi.mock("./slotFields", () => ({ CapacitySelect: () => null, DurationSelect: () => null, GroupLabelField: () => null, SharedSlotHint: () => null }));
vi.mock("./VenueGeocodeField", () => ({ VenueGeocodeField: () => null }));
vi.mock("./VenueAvailabilityGrid", () => ({ VenueAvailabilityGrid: () => null }));
vi.mock("./TravelMatrixModal", () => ({ TravelMatrixModal: () => null }));
vi.mock("../store", () => ({
  useWizardStore: (selector?: (s: { mode: string; calendarEntryId: string | null; clearDeepLinkOrigin: () => void }) => unknown) => {
    const state = { mode: "season", calendarEntryId: null, clearDeepLinkOrigin: () => {} };
    return selector ? selector(state) : state;
  },
}));

import { VenuesStep } from "./VenuesStep";

function Harness() {
  const [extra, setExtra] = useState<ReactNode>(null);
  return (
    <WizardFooterContext.Provider value={{ setFooterExtra: setExtra, setSuppressScrollJump: () => {} }}>
      <VenuesStep />
      <div data-testid="footer-slot">{extra}</div>
    </WizardFooterContext.Provider>
  );
}

beforeEach(() => {
  // Aucun gymnase au départ : l'accordéon « Ajouter des gymnases » est ouvert d'office (defaultOpen).
  h.venues.data = [];
  h.created.value = null;
  h.createCalls = [];
  h.nameSearch = { used: false, lastQuery: "", salles: [] };
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("VenuesStep — recherche d'un gymnase par son nom dans la FFBB (#20)", () => {
  it("une salle TROUVÉE par son nom se crée AVEC son ancrage fédéral et son adresse (même geste → trajets calculables)", async () => {
    const user = userEvent.setup();
    h.nameSearch.salles = [FOUND];
    renderWithProviders(<Harness />);

    // Le gymnase cherché n'est ni dans la commune ni à proximité : le gestionnaire tape son nom.
    await user.type(screen.getByRole("textbox", { name: WIZARD_TEXTS.venueNameLabel }), "MONT BLANC");

    // La recherche par nom le ramène : il apparaît à l'écran (preuve que la PROD consomme la
    // recherche par nom et en affiche les résultats — nom = DONNÉE, jamais un libellé centralisé).
    await user.click(await screen.findByRole("button", { name: new RegExp(FOUND.name, "i") }));

    // Promesse #20 : créé AVEC son numéro fédéral ET son adresse/position — sans quoi 0 trajet.
    await waitFor(() =>
      expect(h.createCalls).toContainEqual(
        expect.objectContaining({
          name: FOUND.name,
          externalRef: FOUND.externalRef,
          address: FOUND.address,
          latitude: FOUND.latitude,
          longitude: FOUND.longitude,
        }),
      ),
    );
  });

  it("nom INTROUVABLE à la FFBB → la recherche par nom est bien consultée, et la création manuelle reste le chemin (gymnase SANS ancrage)", async () => {
    const user = userEvent.setup();
    h.nameSearch.salles = []; // réellement absent de la FFBB (cas « Mont Blanc » neuf)
    renderWithProviders(<Harness />);

    await user.type(screen.getByRole("textbox", { name: WIZARD_TEXTS.venueNameLabel }), "GYMNASE NEUF INCONNU");

    // La PROD a bien INTERROGÉ la recherche par nom (câblage) — rouge tant que le hook n'est pas consommé.
    await waitFor(() => expect(h.nameSearch.used).toBe(true));

    // Rien de trouvé : le chemin manuel existant crée quand même le gymnase, mais SANS ancrage
    // fédéral (aucune salle FFBB ne lui correspond — pas d'externalRef/lat/long menteurs).
    await user.click(screen.getByRole("button", { name: WIZARD_TEXTS.addVenueButton }));

    const manual = h.createCalls.at(-1);
    expect(manual?.name).toBe("GYMNASE NEUF INCONNU");
    expect(manual?.externalRef ?? null).toBeNull();
    expect(manual?.latitude ?? null).toBeNull();
    expect(manual?.longitude ?? null).toBeNull();
  });
});
