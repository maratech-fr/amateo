/**
 * La SESSION — l'utilisateur courant, son club, sa saison, son plan — vit dans
 * `shared/`, pas chez `features/auth/`. Elle est consommée par `app/` (garde,
 * bandeaux, sélecteur de saison) ET par quatre modules `shared/` (`lib/socle`,
 * `hooks/useApplyClubTheme`, `hooks/useApplyDemoClock`, `credits/useCredits`) :
 * c'était la plus grosse remontée `shared/ → features/` de l'audit AUD-FRT-21
 * (P4-123). Ce qui est lu de partout est du SOCLE, pas d'une feature.
 *
 * Le module est scindé api / queries (couture de mock D-31) : les tests mockent
 * les HOOKS (`@/shared/session/queries`) sans jamais charger la couche réseau —
 * `getMe` et les types de réponse restent ici, isolés du hook qui les appelle.
 *
 * Ce module ne porte QUE la session (`me`). L'authentification proprement dite —
 * login / register / logout / memberships / approbations — reste chez
 * `features/auth/` : ce sont des gestes de la feature auth, pas du socle partagé.
 */
import { api } from "@/shared/api/client";
import type { MembershipStatus } from "@/shared/stores/authStore";

export interface MeSeason {
  id: string;
  name: string;
  startDate: string;
  endDate: string;
  isCurrent: boolean;
  isReadonly: boolean;
}

/** THE season's plan and where it stands (ADR-0002). See `MeResponse.seasonPlan`. */
export interface MeSeasonPlan {
  id: string;
  name: string;
  chosenScheduleId: string | null;
  hasFinishedVersion: boolean;
  currentStructureHash: string | null;
}

/**
 * P1-3 — droits de l'offre EFFECTIVE du club, calculés SERVEUR (PlanEntitlements)
 * et servis tels quels : le front n'en RECALCULE aucun (piège P2-8). `creditsMax`
 * n'est non-null qu'en Découverte bridée (pool de sorties actif) — null en
 * payant/bêta/démo, et c'est LE drapeau « faut-il afficher les mécanismes de
 * crédits ? ». Les booléens `can*` sont les seuls juges du « peut-on encore
 * sortir ? » ; `creditsMax - creditsUsed` n'est qu'un affichage.
 */
export interface ClubEntitlements {
  planCode: string;
  planName: string;
  /** Cap d'équipes de l'offre (null = illimité : Découverte, bêta, sans-limite, démo). */
  maxTeams: number | null;
  teamsUsed: number;
  /** Taille du pool de crédits ; null hors Découverte bridée. */
  creditsMax: number | null;
  creditsUsed: number;
  canGenerate: boolean;
  canPlaceMatches: boolean;
  canExportPdf: boolean;
  seasonTransition: boolean;
}

/** FFBB institutional contact block (lot C) — league or committee. */
export interface FfbbOrganisme {
  name: string;
  address: string | null;
  postalCode: string | null;
  city: string | null;
  phone: string | null;
  email: string | null;
  logoUrl: string | null;
  website: string | null;
}

export interface MeResponse {
  id: string;
  email: string;
  /** P4-74 — adresse en attente de confirmation (le compte garde son adresse actuelle). null = aucune. */
  pendingEmail: string | null;
  firstName: string;
  lastName: string;
  membershipStatus: MembershipStatus;
  role: string | null;
  /** P3-4 : la demande de CRÉATION du club (la plus récente) — null dès qu'un membership existe. */
  clubRequest: { status: "pending" | "approved" | "refused" | "expired"; clubName: string; ara: string; clubEmailKnown: boolean } | null;
  club: {
    id: string;
    name: string;
    onboardingCompleted: boolean;
    /** P4-271 — modèle de week-end sur deux semaines (A/B) : aide visuelle « Semaine type ». */
    weekendAlternates: boolean;
    logoUrl: string | null;
    accentColor: string | null;
    accentColorDark: string | null;
    accentPalette: string[] | null;
    schoolZone: string | null;
    /** P2-21 lot A — vérité serveur : les équipes viennent de l'import FFBB. */
    ffbbTeamsImported?: boolean;
    /** P4-16/P2-4 — « aujourd'hui » simulé d'un club démo (null = horloge réelle). */
    demoToday?: string | null;
    league: string | null;
    ffbbClubCode: string | null;
    committeeCode: string | null;
    contactPhone: string | null;
    contactEmail: string | null;
    address: string | null;
    // FFBB autofill (lot C): institutional club data + shared league/committee blocks.
    postalCode: string | null;
    city: string | null;
    website: string | null;
    latitude: number | null;
    longitude: number | null;
    ffbbCommittee: FfbbOrganisme | null;
    ffbbLeague: FfbbOrganisme | null;
    /** P1-3 — droits de l'offre effective ; absent tant qu'aucune saison n'est résolue. */
    entitlements?: ClubEntitlements;
  } | null;
  /**
   * THE season's plan (ADR-0002) for the SELECTED season (X-Season-Id), else the
   * current one — the single seam onto "where is this season at?".
   *
   * `chosenScheduleId` = the version the manager settled on; it IS the season's
   * calendar, and null means the plan is still an espace de travail.
   * `hasFinishedVersion` = the club has generated at least once, which is what
   * unlocks the cockpit — independent of the pointer, so reopening a plan does
   * not throw the manager back into the guided wizard.
   *
   * null only for a season with no plan row at all: every creation path provisions
   * one, so treat it as an empty plan rather than a special case.
   */
  seasonPlan: MeSeasonPlan | null;
  hasGenerated: boolean;
  /** All the club's seasons, startDate ASC. */
  seasons: MeSeason[];
  currentSeasonId: string | null;
}

export function getMe(): Promise<MeResponse> {
  return api.get("me").json();
}
