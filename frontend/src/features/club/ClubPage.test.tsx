import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { MeResponse } from "@/shared/session/api";

type ClubMock = (Partial<NonNullable<MeResponse["club"]>> & { name: string }) | null;
type MeData = { role: string; club: ClubMock; seasonPlan?: MeResponse["seasonPlan"]; seasons?: MeResponse["seasons"]; currentSeasonId?: string | null };
const me: { data: MeData; isLoading: boolean } = {
  data: { role: "admin", club: { name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null }, seasons: [], currentSeasonId: null },
  isLoading: false,
};

vi.mock("@/shared/session/queries", () => ({
  useMe: () => me,
}));
vi.mock("@/features/auth/queries", () => ({
  usePendingMembers: () => ({ data: { members: [] }, isLoading: false }),
  useApproveMember: () => ({ mutate: vi.fn(), isPending: false }),
  useRejectMember: () => ({ mutate: vi.fn(), isPending: false }),
  useMembers: () => ({ data: { members: [{ id: "self", userId: "us", email: "boss@club.fr", firstName: "Grace", lastName: "Hopper", role: "admin", isSelf: true }], deactivated: [] }, isError: false }),
  useChangeMemberRole: () => ({ mutate: vi.fn(), isPending: false }),
  useDeactivateMember: () => ({ mutate: vi.fn(), isPending: false }),
  useReactivateMember: () => ({ mutate: vi.fn(), isPending: false }),
}));

const ffbbImport = vi.fn();

// Catalogue des offres (P1-3) — mutable pour piloter chargement/échec par test.
const plans: { data: unknown[] | undefined; isError: boolean } = {
  data: [
    { id: "p-dec", code: "decouverte", name: "Découverte", maxTeams: 0, maxVenues: 0, maxGenerations: 10 },
    { id: "p-ess", code: "essentiel", name: "Essentiel", maxTeams: 20, maxVenues: 0, maxGenerations: 0 },
    { id: "p-club", code: "club", name: "Club", maxTeams: 30, maxVenues: 0, maxGenerations: 0 },
    { id: "p-sl", code: "sans-limite", name: "Sans limite", maxTeams: 0, maxVenues: 0, maxGenerations: 0 },
  ],
  isError: false,
};

// Stats d'utilisation des gymnases (P3-22) — mutable pour piloter par test.
const venueStats: { data: unknown; isLoading: boolean; isError: boolean } = { data: undefined, isLoading: false, isError: false };

vi.mock("./queries", () => ({
  useUpdateAppearance: () => ({ mutate: vi.fn(), mutateAsync: vi.fn(), isPending: false }),
  useUploadLogo: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useDeleteLogo: () => ({ mutate: vi.fn(), isPending: false }),
  useFfbbImport: () => ({ mutate: ffbbImport, isPending: false }),
  useResetClub: () => ({ mutate: vi.fn(), isPending: false }),
  useDownloadClubExport: () => ({ mutate: vi.fn(), isPending: false }),
  useSubscriptionPlans: () => plans,
  useVenueUsageStats: () => venueStats,
}));

import { ClubPage } from "./ClubPage";

describe("ClubPage", () => {
  beforeEach(() => {
    me.data = { role: "admin", club: { name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null }, seasons: [], currentSeasonId: null };
    venueStats.data = undefined;
    venueStats.isLoading = false;
    venueStats.isError = false;
    plans.data = [
      { id: "p-dec", code: "decouverte", name: "Découverte", maxTeams: 0, maxVenues: 0, maxGenerations: 10 },
      { id: "p-ess", code: "essentiel", name: "Essentiel", maxTeams: 20, maxVenues: 0, maxGenerations: 0 },
      { id: "p-club", code: "club", name: "Club", maxTeams: 30, maxVenues: 0, maxGenerations: 0 },
      { id: "p-sl", code: "sans-limite", name: "Sans limite", maxTeams: 0, maxVenues: 0, maxGenerations: 0 },
    ];
    plans.isError = false;
  });

  it("shows both sections for an admin, Demandes open by default", () => {
    render(<ClubPage />);
    const demandes = screen.getByRole("button", { name: /Demandes/ });
    expect(demandes).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByText(/Aucune demande en attente/)).toBeInTheDocument();
    // Visuel section present but collapsed.
    expect(screen.getByRole("button", { name: /Visuel/ })).toHaveAttribute("aria-expanded", "false");
  });

  it("shows the FFBB contacts section: full names, no CLUB/COMITÉ/LIGUE labels, no club block", async () => {
    // Décision fondateur 2026-08-04 : la section ne montre que la hiérarchie
    // AU-DESSUS du club, et la raison sociale COMPLÈTE dit elle-même ce qu'elle
    // est — pas d'étiquette au-dessus d'un nom tronqué.
    me.data = {
      role: "admin",
      club: {
        name: "BC Test",
        accentColor: null,
        accentColorDark: null,
        accentPalette: null,
        logoUrl: null,
        address: "5 rue X",
        postalCode: "69100",
        city: "Villeurbanne",
        contactEmail: "contact@bccl.fr",
        ffbbCommittee: { name: "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL", email: "cdrbb@basketrhone.com", address: null, postalCode: null, city: null, phone: null, logoUrl: null, website: "http://www.basketrhone.com" },
        ffbbLeague: { name: "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL", email: null, address: null, postalCode: null, city: null, phone: null, logoUrl: null, website: null },
      },
    };
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Contacts FFBB/ }));
    // Noms complets, sans troncature CSS.
    const comite = screen.getByText("COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL");
    expect(comite).toBeInTheDocument();
    expect(comite).not.toHaveClass("truncate");
    expect(screen.getByText("LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL")).toBeInTheDocument();
    // Plus d'étiquettes de bloc.
    expect(screen.queryByText(/^Comité$/)).toBeNull();
    expect(screen.queryByText(/^Ligue$/)).toBeNull();
    // Le site web du comité (nouvelle donnée urlSiteWeb) est un lien.
    expect(screen.getByRole("link", { name: "http://www.basketrhone.com/" })).toBeInTheDocument();
    // Plus de bloc « Club » : son email n'apparaît pas ici.
    expect(screen.queryByRole("link", { name: "contact@bccl.fr" })).toBeNull();
  });

  it("club info: everything read-only and compact — no inputs, no save, no officer blocks", async () => {
    // Décision fondateur 2026-08-04 : la FFBB fait autorité ; correspondant/
    // président/salle principale SUPPRIMÉS (l'API ne les fournira jamais, la
    // saisie manuelle n'est pas voulue). Contact compact : les coordonnées
    // s'empilent nues — un téléphone se reconnaît sans sous-titre.
    me.data = {
      role: "admin",
      club: {
        name: "BC Test",
        accentColor: null,
        accentColorDark: null,
        accentPalette: null,
        logoUrl: null,
        committeeCode: "0069",
        contactPhone: "0643720140",
        contactEmail: "contact@bccl.fr",
        address: "5 RUE EMILE DUNIERE",
        postalCode: "69100",
        city: "VILLEURBANNE",
        website: "https://www.bccl.fr",
      },
    };
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Informations du club/ }));
    // Valeurs affichées, compactes : l'adresse est UNE ligne, tél sans étiquette.
    expect(screen.getByText("5 RUE EMILE DUNIERE, 69100 VILLEURBANNE")).toBeInTheDocument();
    expect(screen.getByText("0643720140")).toBeInTheDocument();
    expect(screen.queryByText("Téléphone")).toBeNull();
    expect(screen.getByRole("link", { name: "contact@bccl.fr" })).toHaveAttribute("href", "mailto:contact@bccl.fr");
    // AUCUN champ saisissable ni bouton Enregistrer dans la section.
    expect(screen.queryByRole("button", { name: "Enregistrer" })).toBeNull();
    expect(screen.queryByText("Correspondant")).toBeNull();
    expect(screen.queryByText("Président")).toBeNull();
    expect(screen.queryByText("Salle principale")).toBeNull();
    // Le geste de correction : le ré-import FFBB.
    await user.click(screen.getByRole("button", { name: "Actualiser depuis la FFBB" }));
    expect(ffbbImport).toHaveBeenCalledOnce();
  });

  it("hides the club-info section for a non-admin", () => {
    me.data = { role: "member", club: { name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null } };
    render(<ClubPage />);
    expect(screen.queryByRole("button", { name: /Informations du club/ })).toBeNull();
  });

  it("hides the Demandes section for a non-admin", () => {
    me.data = { role: "member", club: { name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null } };
    render(<ClubPage />);
    expect(screen.queryByRole("button", { name: /Demandes/ })).toBeNull();
    expect(screen.getByRole("button", { name: /Visuel/ })).toBeInTheDocument();
  });

  it("shows the Membres section for management (expand → active member list)", async () => {
    const user = userEvent.setup();
    render(<ClubPage />);
    const membres = screen.getByRole("button", { name: /^Membres$/ });
    await user.click(membres);
    expect(screen.getByText("Grace Hopper")).toBeInTheDocument();
    expect(screen.getByText("vous")).toBeInTheDocument();
  });

  it("hides the Membres section for a non-management member", () => {
    me.data = { role: "member", club: { name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null } };
    render(<ClubPage />);
    expect(screen.queryByRole("button", { name: /^Membres$/ })).toBeNull();
  });

  // --- P1-3 §4bis pt 5 — section « Offre » ---------------------------------

  const withEntitlements = (entitlements: Record<string, unknown>): ClubMock => ({
    name: "BC Test",
    accentColor: null,
    accentColorDark: null,
    accentPalette: null,
    logoUrl: null,
    entitlements: entitlements as never,
  });

  it("Offre (Découverte) : offre courante + solde de crédits + paliers « sur demande », aucun montant", () => {
    me.data = { role: "admin", club: withEntitlements({ planCode: "decouverte", planName: "Découverte", maxTeams: null, teamsUsed: 6, creditsMax: 10, creditsUsed: 3, canGenerate: true, canPlaceMatches: true, canExportPdf: true, seasonTransition: false }) };
    render(<ClubPage />);
    // Section ouverte par défaut : le solde de crédits (10 - 3 = 7) s'affiche.
    expect(screen.getByText(/Crédits gratuits : 7\/10/)).toBeInTheDocument();
    // Les paliers PAYANTS affichent « Sur demande » (aucun montant nulle part).
    expect(screen.getAllByText("Sur demande").length).toBeGreaterThanOrEqual(3);
    expect(screen.queryByText(/€|euro/i)).toBeNull();
    // Le gratuit n'a pas de tarif.
    expect(screen.getByText("Gratuit")).toBeInTheDocument();
  });

  it("Offre (payant) : « X/Y équipes », AUCUN compteur de crédits", () => {
    me.data = { role: "admin", club: withEntitlements({ planCode: "essentiel", planName: "Essentiel", maxTeams: 20, teamsUsed: 15, creditsMax: null, creditsUsed: 0, canGenerate: true, canPlaceMatches: true, canExportPdf: true, seasonTransition: true }) };
    render(<ClubPage />);
    expect(screen.getByText("15/20 équipes")).toBeInTheDocument();
    // Payant : jamais de mécanisme de crédits.
    expect(screen.queryByText(/Crédits gratuits/)).toBeNull();
    // Le palier courant est mis en avant.
    expect(screen.getAllByText("Votre offre").length).toBeGreaterThanOrEqual(1);
  });

  it("Offre : le chargement ne crie pas « échec » (readState à trois états)", () => {
    plans.data = undefined;
    plans.isError = false;
    me.data = { role: "admin", club: withEntitlements({ planCode: "decouverte", planName: "Découverte", maxTeams: null, teamsUsed: 0, creditsMax: 10, creditsUsed: 0, canGenerate: true, canPlaceMatches: true, canExportPdf: true, seasonTransition: false }) };
    render(<ClubPage />);
    expect(screen.getByText("Chargement des offres…")).toBeInTheDocument();
    expect(screen.queryByText(/n'ont pas pu être chargées/)).toBeNull();
  });

  it("hides the Offre section for a non-management member", () => {
    me.data = { role: "member", club: { name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null } };
    render(<ClubPage />);
    expect(screen.queryByRole("button", { name: /^Offre$/ })).toBeNull();
  });

  // --- P3-22 — stats d'utilisation des gymnases -----------------------------

  /** Un `me` avec un planning en vigueur + une saison courante (déverrouille la section). */
  const withSeasonPlan = (): void => {
    me.data = {
      role: "admin",
      club: { name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null },
      seasonPlan: { id: "sp", name: "Plan", chosenScheduleId: "sched", hasFinishedVersion: true, currentStructureHash: null },
      seasons: [{ id: "s1", name: "2026-2027", startDate: "2026-09-01", endDate: "2027-06-30", isCurrent: true, isReadonly: false }],
      currentSeasonId: "s1",
    };
  };

  it("Stats gymnases : deux tableaux (gymnase, niveau) + ligne TOTAL de négociation, tout venant du serveur", async () => {
    withSeasonPlan();
    venueStats.data = {
      range: { from: "2026-09-01", to: "2027-06-30", today: "2026-08-17" },
      zone: "A",
      venues: [{ venueId: "v1", name: "Gymnase Mateo", byDay: [{ day: 1, real: 0, projected: 8, total: 8 }], real: 0, projected: 8, total: 8 }],
      totalByDay: [{ day: 1, real: 0, projected: 8, total: 8 }],
      byLevel: [{ level: "REGIONAL", label: "Régional", byDay: [{ day: 1, real: 0, projected: 8, total: 8 }], real: 0, projected: 8, total: 8 }],
      grandTotal: { real: 0, projected: 8, total: 8 },
    };
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Statistiques des gymnases/ }));

    // Les deux tableaux et leurs lignes.
    expect(screen.getByText("Par gymnase")).toBeInTheDocument();
    expect(screen.getByText("Par niveau")).toBeInTheDocument();
    expect(screen.getByText("Gymnase Mateo")).toBeInTheDocument();
    // Le libellé de niveau vient du SERVEUR (le front ne l'invente pas).
    expect(screen.getByText("Régional")).toBeInTheDocument();
    // La ligne TOTAL (le chiffre de négociation) existe dans chaque tableau.
    expect(screen.getAllByText("TOTAL").length).toBe(2);
    // Colonne jour + heures serveur affichées (8 h le lundi).
    expect(screen.getAllByText("Lun").length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText("8 h").length).toBeGreaterThanOrEqual(1);
    // Plage utilisée affichée.
    expect(screen.getByText(/du 01\/09\/2026 au 30\/06\/2027/)).toBeInTheDocument();
  });

  // P4-150 — planning EN VIGUEUR mais aucune séance sur la plage : la copie d'écran de
  // l'état vide est assertée (distincte de « sans planning en vigueur » ci-dessous).
  it("Stats gymnases : planning en vigueur sans séance sur la plage → la copie d'écran de l'état vide", async () => {
    withSeasonPlan();
    venueStats.data = {
      range: { from: "2026-09-01", to: "2027-06-30", today: "2026-08-17" },
      zone: "A",
      venues: [],
      totalByDay: [],
      byLevel: [],
      grandTotal: { real: 0, projected: 0, total: 0 },
    };
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Statistiques des gymnases/ }));

    expect(screen.getByText("Le planning en vigueur ne place aucune séance sur cette plage.")).toBeInTheDocument();
    expect(screen.queryByText("Par gymnase")).toBeNull();
  });

  it("Stats gymnases : sans planning en vigueur, la section explique au lieu d'afficher des zéros", async () => {
    me.data = {
      role: "admin",
      club: { name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null },
      seasonPlan: { id: "sp", name: "Plan", chosenScheduleId: null, hasFinishedVersion: false, currentStructureHash: null },
      seasons: [{ id: "s1", name: "2026-2027", startDate: "2026-09-01", endDate: "2027-06-30", isCurrent: true, isReadonly: false }],
      currentSeasonId: "s1",
    };
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Statistiques des gymnases/ }));
    expect(screen.getByText(/validez d'abord le planning principal/)).toBeInTheDocument();
    expect(screen.queryByText("Par gymnase")).toBeNull();
  });
});

/**
 * P4-107 (3ᵉ tranche) — la fiche Club porte le cadre PARTAGÉ, pas une largeur à elle.
 * Elle vivait à `max-w-2xl` (672 px) sous un shell pleine largeur : sur 1920, la marge
 * dépassait l'utile. Falsifié dans les deux sens — le cadre attendu doit être là, et
 * aucune autre largeur ne doit subsister.
 */
describe("largeur de la fiche Club", () => {
  it("est cadrée par FichePage, sans largeur concurrente", () => {
    const { container } = render(<ClubPage />);
    const root = container.firstChild as HTMLElement;
    const widths = root.className.split(/\s+/).filter((token) => token.startsWith("max-w-"));

    expect(widths).toEqual(["max-w-fiche"]);
    expect(root.className).toContain("mx-auto");
  });
});
