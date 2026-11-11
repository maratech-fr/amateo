import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { ClubEntitlements, MeResponse } from "@/shared/session/api";
import { SESSION_TEXTS } from "@/shared/session/texts";

import { CLUB_TEXTS } from "./texts";

// FRT-42 — le fixture conforme au VRAI type `MeResponse["club"]` (plus un `Partial` qui laissait
// passer un ajout/retrait de champ sans que le test le voie). Chaque littéral de club part de
// `baseClub` (tous les champs requis) et ne surcharge que ce qui compte pour le cas.
type Club = NonNullable<MeResponse["club"]>;
type MeData = {
  role: MeResponse["role"];
  club: Club | null;
  seasonPlan?: MeResponse["seasonPlan"];
  seasons?: MeResponse["seasons"];
  currentSeasonId?: string | null;
  managerRequest?: MeResponse["managerRequest"];
};
const baseClub: Club = {
  id: "club-1",
  name: "BC Test",
  shortName: null,
  onboardingCompleted: true,
  weekendAlternates: false,
  logoUrl: null,
  accentColor: null,
  accentColorDark: null,
  accentPalette: null,
  schoolZone: null,
  isDemo: false,
  league: null,
  ffbbClubCode: null,
  committeeCode: null,
  contactPhone: null,
  contactEmail: null,
  address: null,
  postalCode: null,
  city: null,
  website: null,
  latitude: null,
  longitude: null,
  ffbbCommittee: null,
  ffbbLeague: null,
};
const me: { data: MeData; isLoading: boolean } = {
  data: { role: "admin", club: { ...baseClub, name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null }, seasons: [], currentSeasonId: null },
  isLoading: false,
};

// Chantier Membre : `useCanManage` (PR 2) décide de ce qu'un Membre voit ; la demande de rôle
// (PR 3) passe par deux mutations de la session — la carte « Mon rôle » et le bandeau de fermeture
// partagent le même geste.
const requestManagerRole = vi.fn();
const cancelManagerRequest = vi.fn();
vi.mock("@/shared/session/queries", () => ({
  useMe: () => me,
  // Chantier Membre (PR 2) : la question « peut-il modifier ? » suit le rôle du `me` mocké.
  useCanManage: () => "admin" === me.data.role || "owner" === me.data.role,
  useRequestManagerRole: () => ({ mutate: requestManagerRole, isPending: false }),
  useCancelManagerRequest: () => ({ mutate: cancelManagerRequest, isPending: false }),
}));
vi.mock("@/features/auth/queries", () => ({
  usePendingMembers: () => ({ data: { members: [] }, isLoading: false }),
  useApproveMember: () => ({ mutate: vi.fn(), isPending: false }),
  useRejectMember: () => ({ mutate: vi.fn(), isPending: false }),
  useMembers: () => ({ data: { members: [{ id: "self", userId: "us", email: "boss@club.fr", firstName: "Grace", lastName: "Hopper", role: "admin", isSelf: true }], deactivated: [] }, isError: false }),
  useChangeMemberRole: () => ({ mutate: vi.fn(), isPending: false }),
  useDeactivateMember: () => ({ mutate: vi.fn(), isPending: false }),
  useReactivateMember: () => ({ mutate: vi.fn(), isPending: false }),
  useRefuseManagerRequest: () => ({ mutate: vi.fn(), isPending: false }),
}));

const ffbbImport = vi.fn();

// Catalogue des offres (P1-3) — mutable pour piloter chargement/échec par test.
const plans: { data: unknown[] | undefined; isError: boolean } = {
  data: [
    { id: "p-dec", code: "decouverte", name: "Découverte", maxTeams: 0, maxVenues: 0, maxGenerations: 10 },
    { id: "p-ess", code: "essentiel", name: "Essentiel", maxTeams: 20, maxVenues: 0, maxGenerations: 0 },
    { id: "p-club", code: "club", name: "Club", maxTeams: 34, maxVenues: 0, maxGenerations: 0 },
    { id: "p-gc", code: "grand-club", name: "Grand club", maxTeams: 0, maxVenues: 0, maxGenerations: 0 },
  ],
  isError: false,
};

// Stats d'utilisation des gymnases (P3-22) — mutable pour piloter par test.
const venueStats: { data: unknown; isLoading: boolean; isError: boolean } = { data: undefined, isLoading: false, isError: false };

const updateSiege = vi.fn();
const updateShortName = vi.fn();

vi.mock("./queries", () => ({
  useUpdateAppearance: () => ({ mutate: vi.fn(), mutateAsync: vi.fn(), isPending: false }),
  useUploadLogo: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useDeleteLogo: () => ({ mutate: vi.fn(), isPending: false }),
  useFfbbImport: () => ({ mutate: ffbbImport, isPending: false }),
  useUpdateSiege: () => ({ mutate: updateSiege, isPending: false }),
  useUpdateShortName: () => ({ mutate: updateShortName, isPending: false }),
  useResetClub: () => ({ mutate: vi.fn(), isPending: false }),
  useDownloadClubExport: () => ({ mutate: vi.fn(), isPending: false }),
  useSubscriptionPlans: () => plans,
  useVenueUsageStats: () => venueStats,
  useInvitations: () => ({ data: { invitations: [] }, isError: false, refetch: vi.fn() }),
  useCreateInvitation: () => ({ mutate: vi.fn(), mutateAsync: vi.fn(), isPending: false }),
  useResendInvitation: () => ({ mutate: vi.fn(), isPending: false }),
  useRevokeInvitation: () => ({ mutate: vi.fn(), isPending: false }),
}));

// Le géocodage (AddressGeocodeField du siège) — mutation réelle sinon : on la neutralise.
vi.mock("@/shared/hooks/useGeocode", () => ({
  useGeocode: () => ({ mutate: vi.fn(), isPending: false }),
  useReverseGeocode: () => ({ data: null, isPending: false }),
}));

import { ClubPage } from "./ClubPage";

describe("ClubPage", () => {
  beforeEach(() => {
    me.data = { role: "admin", club: { ...baseClub, name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null }, seasons: [], currentSeasonId: null };
    venueStats.data = undefined;
    venueStats.isLoading = false;
    venueStats.isError = false;
    // Le catalogue tel que l'API le sert (grille 20 / 34 / 35+) : Grand club illimité (0), plus de « sans-limite ».
    plans.data = [
      { id: "p-dec", code: "decouverte", name: "Découverte", maxTeams: 0, maxVenues: 0, maxGenerations: 10 },
      { id: "p-ess", code: "essentiel", name: "Essentiel", maxTeams: 20, maxVenues: 0, maxGenerations: 0 },
      { id: "p-club", code: "club", name: "Club", maxTeams: 34, maxVenues: 0, maxGenerations: 0 },
      { id: "p-gc", code: "grand-club", name: "Grand club", maxTeams: 0, maxVenues: 0, maxGenerations: 0 },
    ];
    plans.isError = false;
    updateShortName.mockClear();
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
      club: { ...baseClub,
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

  it("club info: le SIÈGE est la seule saisie — le reste (identité, contact) demeure FFBB, lecture seule", async () => {
    // Décision fondateur : la FFBB fait autorité, SAUF le siège du club (adresse re-géocodée
    // côté serveur) — la seule saisie de la fiche. Le contact reste compact et nu.
    me.data = {
      role: "admin",
      club: { ...baseClub,
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
    // Le contact reste : tél sans étiquette, email lien. L'adresse a QUITTÉ le bloc contact.
    expect(screen.getByText("0643720140")).toBeInTheDocument();
    expect(screen.queryByText("Téléphone")).toBeNull();
    expect(screen.getByRole("link", { name: "contact@bccl.fr" })).toHaveAttribute("href", "mailto:contact@bccl.fr");
    expect(screen.queryByText("5 RUE EMILE DUNIERE, 69100 VILLEURBANNE")).toBeNull();
    // La SEULE saisie : le champ « Adresse du siège » (le reste demeure FFBB, sans « Enregistrer »).
    expect(screen.getByRole("textbox", { name: "Adresse du siège" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Enregistrer" })).toBeNull();
    expect(screen.queryByText("Correspondant")).toBeNull();
    expect(screen.queryByText("Président")).toBeNull();
    // Le geste de correction FFBB : le ré-import.
    await user.click(screen.getByRole("button", { name: "Actualiser depuis la FFBB" }));
    expect(ffbbImport).toHaveBeenCalledOnce();
  });

  // D1 — le NOM COURT (libellé d'e-mail) : la seule saisie d'identité, sous le nom officiel FFBB.
  it("nom court : champ saisissable sous le nom officiel FFBB, avec l'aide « e-mails aux coachs »", async () => {
    me.data = { role: "admin", club: { ...baseClub, name: "BASKET CLUB DE LA VALLÉE", ffbbClubCode: "ARA0690001" } };
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Informations du club/ }));

    // Le nom officiel FFBB est en lecture seule (il apparaît aussi en sous-titre d'en-tête).
    expect(screen.getByText("Nom officiel (FFBB)")).toBeInTheDocument();
    expect(screen.getAllByText("BASKET CLUB DE LA VALLÉE").length).toBeGreaterThanOrEqual(1);
    // Le champ nom court est saisissable, avec son aide.
    expect(screen.getByRole("textbox", { name: "Nom court" })).toBeInTheDocument();
    expect(screen.getByText(/Utilisé dans les e-mails envoyés aux coachs/)).toBeInTheDocument();
  });

  it("nom court : éditer affiche Enregistrer/Annuler en TEXTE et enregistre la valeur trimée", async () => {
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Informations du club/ }));

    const field = screen.getByRole("textbox", { name: "Nom court" });
    await user.type(field, "  BC Vallée  ");
    // N1 : Enregistrer ET Annuler, en texte.
    const save = screen.getByRole("button", { name: "Enregistrer" });
    expect(screen.getByRole("button", { name: "Annuler" })).toBeInTheDocument();
    await user.click(save);
    expect(updateShortName).toHaveBeenCalledWith("BC Vallée");
  });

  it("nom court : affiche la valeur existante et n'offre Enregistrer qu'après modification", async () => {
    me.data = { role: "admin", club: { ...baseClub, shortName: "BC Vallée" } };
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Informations du club/ }));

    expect(screen.getByRole("textbox", { name: "Nom court" })).toHaveValue("BC Vallée");
    // Tant que rien n'a bougé, pas de bouton Enregistrer.
    expect(screen.queryByRole("button", { name: "Enregistrer" })).toBeNull();
  });

  it("siège NON localisé (pas de coordonnées) : statut « Siège non localisé » + champ pré-rempli avec l'adresse FFBB", async () => {
    me.data = {
      role: "admin",
      club: { ...baseClub, name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null, address: "5 RUE EMILE DUNIERE", latitude: null, longitude: null },
    };
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Informations du club/ }));
    expect(screen.getByText(/Siège non localisé/)).toBeInTheDocument();
    // Champ ouvert, pré-rempli avec l'adresse FFBB.
    expect(screen.getByRole("textbox", { name: "Adresse du siège" })).toHaveValue("5 RUE EMILE DUNIERE");
  });

  it("siège localisé (coordonnées présentes) : statut « Siège localisé » + bouton « Modifier l'adresse »", async () => {
    me.data = {
      role: "admin",
      club: { ...baseClub, name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null, address: "5 RUE EMILE DUNIERE", latitude: 45.78, longitude: 4.88 },
    };
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Informations du club/ }));
    expect(screen.getByText("Siège localisé")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Modifier l'adresse" })).toBeInTheDocument();
    // Repliée : aucun champ tant qu'on ne clique pas « Modifier l'adresse ».
    expect(screen.queryByRole("textbox", { name: "Adresse du siège" })).toBeNull();
  });

  /**
   * INVERSÉ (chantier Membre, PR 2 — « un Membre lit tout, ne modifie rien ») : l'ancienne règle
   * MASQUAIT la fiche au Membre ; il la LIT désormais (identité FFBB, nom court, siège), sous la
   * bannière de consultation, sans aucun geste (ni ré-import FFBB, ni saisie active).
   */
  it("Membre : la fiche du club se lit, sous la bannière de consultation, sans aucun geste", async () => {
    me.data = { role: "member", club: { ...baseClub, name: "BASKET CLUB DE LA VALLÉE", ffbbClubCode: "ARA0690001", shortName: "BC Vallée", address: "5 RUE EMILE DUNIERE", latitude: null, longitude: null } };
    const user = userEvent.setup();
    render(<ClubPage />);
    expect(screen.getByText(SESSION_TEXTS.readOnlyNotice)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /Informations du club/ }));
    expect(screen.getByText("Nom officiel (FFBB)")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Actualiser depuis la FFBB" })).toBeNull();
    // Les champs restent lisibles (valeur affichée) mais ne se modifient pas.
    expect(screen.getByRole("textbox", { name: "Nom court" })).toHaveValue("BC Vallée");
    expect(screen.getByRole("textbox", { name: "Nom court" })).toBeDisabled();
    // Le siège : une adresse présente se LIT en texte — ni champ grisé, ni « Localiser ».
    expect(screen.getByText("5 RUE EMILE DUNIERE")).toBeInTheDocument();
    expect(screen.queryByRole("textbox", { name: "Adresse du siège" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Localiser" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Enregistrer" })).toBeNull();
    // Ni Visuel modifiable, ni remise à zéro.
    expect(screen.queryByRole("button", { name: CLUB_TEXTS.resetClubButton })).toBeNull();
  });

  /**
   * Un Membre ne voit pas de champ VIDE qu'il ne pourra jamais remplir : sans adresse de siège, ni
   * champ grisé, ni bouton « Localiser » (constaté à l'écran, captures SWITCH `16-membre-club-
   * informations.png`). Témoin gestionnaire : « siège NON localisé » plus haut (champ ouvert).
   */
  it("Membre, siège sans adresse : ni champ vide en lecture, ni « Localiser »", async () => {
    me.data = { role: "member", club: { ...baseClub, name: "BC Vallée", address: null, latitude: null, longitude: null } };
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Informations du club/ }));
    expect(screen.getByRole("heading", { name: CLUB_TEXTS.siegeTitle })).toBeInTheDocument();
    expect(screen.queryByRole("textbox", { name: "Adresse du siège" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Localiser" })).toBeNull();
  });

  it("témoin gestionnaire, siège sans adresse : le champ et « Localiser » restent offerts", async () => {
    me.data = { role: "admin", club: { ...baseClub, name: "BC Vallée", address: null, latitude: null, longitude: null } };
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Informations du club/ }));
    expect(screen.getByRole("textbox", { name: "Adresse du siège" })).toBeEnabled();
    expect(screen.getByRole("button", { name: "Localiser" })).toBeInTheDocument();
  });

  /**
   * UXS-18 (petites lignes) — le champ texte de la couleur d'accent se nomme par sa CLÉ
   * (`CLUB_TEXTS.colorFieldLabel`), en mots de gestionnaire : jamais « hexadécimal ».
   */
  it("le champ texte de chaque couleur d'accent porte le nom accessible de la clé, sans « hexadécimal »", async () => {
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Visuel/ }));
    expect(screen.getByRole("textbox", { name: CLUB_TEXTS.colorFieldLabel(CLUB_TEXTS.accentLight) })).toBeInTheDocument();
    expect(screen.getByRole("textbox", { name: CLUB_TEXTS.colorFieldLabel(CLUB_TEXTS.accentDark) })).toBeInTheDocument();
    expect(screen.queryByRole("textbox", { name: /hexadécimal/ })).not.toBeInTheDocument();
  });

  it("hides the Demandes section for a non-admin", () => {
    me.data = { role: "member", club: { ...baseClub, name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null } };
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
    me.data = { role: "member", club: { ...baseClub, name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null } };
    render(<ClubPage />);
    expect(screen.queryByRole("button", { name: /^Membres$/ })).toBeNull();
  });

  // P4-299 — la section « Invitations » (gate isAdmin), entre Demandes d'adhésion et Membres.
  it("shows the Invitations section for an admin (expand → the invite form)", async () => {
    const user = userEvent.setup();
    render(<ClubPage />);
    const invitations = screen.getByRole("button", { name: /^Invitations$/ });
    await user.click(invitations);
    expect(screen.getByRole("button", { name: /Inviter/ })).toBeInTheDocument();
    expect(screen.getByText(/Aucune invitation en cours/)).toBeInTheDocument();
  });

  it("hides the Invitations section for a non-admin member", () => {
    me.data = { role: "member", club: { ...baseClub, name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null } };
    render(<ClubPage />);
    expect(screen.queryByRole("button", { name: /^Invitations$/ })).toBeNull();
  });

  // --- P1-3 §4bis pt 5 — section « Offre » ---------------------------------

  const withEntitlements = (entitlements: ClubEntitlements): Club => ({ ...baseClub, entitlements });

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

  /** Grand club (illimité, maxTeams servi à null) : aucun compteur « X/Y équipes », sa carte est l'offre courante. */
  it("Offre (Grand club) : aucun compteur d'équipes, carte « Équipes illimitées » marquée comme l'offre du club", () => {
    me.data = { role: "admin", club: withEntitlements({ planCode: "grand-club", planName: "Grand club", maxTeams: null, teamsUsed: 51, creditsMax: null, creditsUsed: 0, canGenerate: true, canPlaceMatches: true, canExportPdf: true, seasonTransition: true }) };
    render(<ClubPage />);
    expect(screen.queryByText(/\d+\/\d* équipes/)).toBeNull();
    const grandClubCard = screen.getByText("Équipes illimitées").parentElement as HTMLElement;
    expect(within(grandClubCard).getByText(CLUB_TEXTS.yourOffer)).toBeInTheDocument();
  });

  it("Offre : le chargement ne crie pas « échec » (readState à trois états)", () => {
    plans.data = undefined;
    plans.isError = false;
    me.data = { role: "admin", club: withEntitlements({ planCode: "decouverte", planName: "Découverte", maxTeams: null, teamsUsed: 0, creditsMax: 10, creditsUsed: 0, canGenerate: true, canPlaceMatches: true, canExportPdf: true, seasonTransition: false }) };
    render(<ClubPage />);
    expect(screen.getByText("Chargement des offres…")).toBeInTheDocument();
    expect(screen.queryByText(/n'ont pas pu être chargées/)).toBeNull();
  });

  /**
   * INVERSÉ (chantier Membre, PR 2) : l'Offre se LIT par un Membre (avant : masquée). Demandes
   * d'adhésion, Invitations et Membres restent réservés (tests voisins, inchangés).
   */
  it("Membre : l'Offre du club se lit", () => {
    me.data = { role: "member", club: withEntitlements({ planCode: "essentiel", planName: "Essentiel", maxTeams: 20, teamsUsed: 15, creditsMax: null, creditsUsed: 0, canGenerate: true, canPlaceMatches: true, canExportPdf: true, seasonTransition: true }) };
    render(<ClubPage />);
    expect(screen.getByRole("button", { name: /^Offre$/ })).toBeInTheDocument();
    expect(screen.getByText("15/20 équipes")).toBeInTheDocument();
  });

  // --- P3-22 — stats d'utilisation des gymnases -----------------------------

  /** Un `me` avec un planning en vigueur + une saison courante (déverrouille la section). */
  const withSeasonPlan = (): void => {
    me.data = {
      role: "admin",
      club: { ...baseClub, name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null },
      seasonPlan: { id: "sp", name: "Plan", chosenScheduleId: "sched", hasFinishedVersion: true },
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
      club: { ...baseClub, name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null },
      seasonPlan: { id: "sp", name: "Plan", chosenScheduleId: null, hasFinishedVersion: false },
      seasons: [{ id: "s1", name: "2026-2027", startDate: "2026-09-01", endDate: "2027-06-30", isCurrent: true, isReadonly: false }],
      currentSeasonId: "s1",
    };
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Statistiques des gymnases/ }));
    expect(screen.getByText(/validez d'abord le planning de saison/)).toBeInTheDocument();
    expect(screen.queryByText("Par gymnase")).toBeNull();
  });
});

/**
 * Chantier Membre PR 3 — la carte « Mon rôle » d'un Membre dit OÙ en est sa demande à devenir
 * gestionnaire, dans l'état que le SERVEUR a calculé (`/api/me.managerRequest`, jamais redérivé) :
 * aucune demande → le bouton ; en cours auprès des gestionnaires ; transmise à l'adresse officielle
 * (club sans gestionnaire, avec ou sans adresse connue) ; expirée → renvoyable ; refusée → date de
 * renouvellement, sans bouton. Un gestionnaire n'a pas de carte.
 */
describe("ClubPage — carte « Mon rôle » (Membre)", () => {
  const SINCE = "2026-11-03T12:00:00+00:00";
  const asMember = (managerRequest: MeResponse["managerRequest"]) => {
    me.data = { role: "member", club: { ...baseClub }, seasons: [], currentSeasonId: null, managerRequest };
  };

  beforeEach(() => {
    requestManagerRole.mockClear();
    cancelManagerRequest.mockClear();
  });

  /**
   * Relevé SWITCH (PR 3) : club FERMÉ — le bandeau de fermeture (`AppLayout`, `ClubClosingBanner`)
   * porte déjà le geste et la phrase d'état. La carte de `/club` ne les répète pas : UN geste
   * « Demander à devenir gestionnaire », UNE phrase d'état à l'écran.
   */
  it("club fermé, aucune demande : la carte ne répète pas le bouton du bandeau", () => {
    me.data = { role: "member", club: { ...baseClub, closingOn: "2026-12-03" }, seasons: [], currentSeasonId: null, managerRequest: null };
    render(<ClubPage />);
    expect(screen.queryByRole("button", { name: CLUB_TEXTS.requestManagerRole })).not.toBeInTheDocument();
  });

  it("club fermé, demande transmise / expirée / refusée : la carte ne répète ni la phrase d'état ni le bouton", () => {
    const cases: [MeResponse["managerRequest"], string][] = [
      [{ status: "club_pending", since: SINCE, escalated: false, clubEmailKnown: true }, CLUB_TEXTS.managerRequestSentToOfficial("03/11/2026")],
      [{ status: "club_pending", since: SINCE, escalated: false, clubEmailKnown: false }, CLUB_TEXTS.managerRequestNoOfficialAddress],
      [{ status: "expired", since: SINCE, escalated: false }, CLUB_TEXTS.managerRequestExpired("03/11/2026")],
      [{ status: "refused", since: SINCE, escalated: false, until: "2026-12-03" }, CLUB_TEXTS.managerRequestRefused("03/11/2026", "03/12/2026")],
    ];
    for (const [managerRequest, sentence] of cases) {
      me.data = { role: "member", club: { ...baseClub, closingOn: "2026-12-03" }, seasons: [], currentSeasonId: null, managerRequest };
      const { unmount } = render(<ClubPage />);
      expect(screen.queryByText(sentence), `${managerRequest?.status} : phrase répétée`).not.toBeInTheDocument();
      expect(screen.queryByRole("button", { name: CLUB_TEXTS.requestManagerRole }), `${managerRequest?.status} : bouton répété`).not.toBeInTheDocument();
      unmount();
    }
  });

  // UXS-20 (petites lignes) : la demande se confirme — rien ne part avant « Envoyer la demande ».
  // L'annulation du dialogue est prouvée par `shared/session/RequestManagerRoleButton.test.tsx`.
  it("aucune demande : le bouton « Demander à devenir gestionnaire » ouvre une confirmation, la demande part une fois confirmée", async () => {
    asMember(null);
    render(<ClubPage />);
    await userEvent.click(screen.getByRole("button", { name: CLUB_TEXTS.requestManagerRole }));
    const dialog = screen.getByRole("dialog", { name: SESSION_TEXTS.requestManagerRoleConfirmTitle });
    expect(requestManagerRole).not.toHaveBeenCalled();
    await userEvent.click(within(dialog).getByRole("button", { name: SESSION_TEXTS.requestManagerRoleConfirmLabel }));
    expect(requestManagerRole).toHaveBeenCalledTimes(1);
  });

  // Témoin UXS-20 (déclaré au plan) : « Annuler ma demande » est le geste INVERSE, réversible (on peut
  // redemander) — il reste à un clic, sans confirmation.
  it("en cours auprès des gestionnaires : la date, et l'annulation possible en un clic, sans confirmation (plus de bouton de demande)", async () => {
    asMember({ status: "pending", since: SINCE, escalated: false });
    render(<ClubPage />);
    expect(screen.getByText(CLUB_TEXTS.managerRequestPending("03/11/2026"))).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: CLUB_TEXTS.requestManagerRole })).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: CLUB_TEXTS.cancelManagerRequest }));
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(cancelManagerRequest).toHaveBeenCalledTimes(1);
  });

  it("transmise à l'adresse officielle : la date ; sans adresse connue, « le support validera »", () => {
    asMember({ status: "club_pending", since: SINCE, escalated: false, clubEmailKnown: true });
    const { unmount } = render(<ClubPage />);
    expect(screen.getByText(CLUB_TEXTS.managerRequestSentToOfficial("03/11/2026"))).toBeInTheDocument();
    expect(screen.queryByText(CLUB_TEXTS.managerRequestNoOfficialAddress)).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: CLUB_TEXTS.cancelManagerRequest })).toBeInTheDocument();
    unmount();

    asMember({ status: "club_pending", since: SINCE, escalated: false, clubEmailKnown: false });
    render(<ClubPage />);
    expect(screen.getByText(CLUB_TEXTS.managerRequestNoOfficialAddress)).toBeInTheDocument();
  });

  it("escaladée (gestionnaires sans réponse 14 jours) : transmise à l'adresse officielle, annulable", () => {
    asMember({ status: "club_pending", since: SINCE, escalated: true, clubEmailKnown: true });
    render(<ClubPage />);
    expect(screen.getByText(CLUB_TEXTS.managerRequestEscalated("03/11/2026"))).toBeInTheDocument();
    expect(screen.queryByText(CLUB_TEXTS.managerRequestSentToOfficial("03/11/2026"))).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: CLUB_TEXTS.cancelManagerRequest })).toBeInTheDocument();
  });

  it("expirée : la date de la demande, et le bouton pour la renvoyer", async () => {
    asMember({ status: "expired", since: SINCE, escalated: false });
    render(<ClubPage />);
    expect(screen.getByText(CLUB_TEXTS.managerRequestExpired("03/11/2026"))).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: CLUB_TEXTS.requestManagerRole }));
    await userEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: SESSION_TEXTS.requestManagerRoleConfirmLabel }));
    expect(requestManagerRole).toHaveBeenCalledTimes(1);
  });

  it("refusée par le club : les deux dates, et AUCUN bouton tant que le délai court", () => {
    asMember({ status: "refused", since: SINCE, escalated: false, until: "2026-12-03" });
    render(<ClubPage />);
    expect(screen.getByText(CLUB_TEXTS.managerRequestRefused("03/11/2026", "03/12/2026"))).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: CLUB_TEXTS.requestManagerRole })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: CLUB_TEXTS.cancelManagerRequest })).not.toBeInTheDocument();
  });

  it("un gestionnaire n'a pas de carte « Mon rôle »", () => {
    me.data = { role: "admin", club: { ...baseClub }, seasons: [], currentSeasonId: null, managerRequest: null };
    render(<ClubPage />);
    expect(screen.queryByRole("button", { name: CLUB_TEXTS.requestManagerRole })).not.toBeInTheDocument();
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
