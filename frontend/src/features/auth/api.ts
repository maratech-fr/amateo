import { api } from "@/shared/api/client";
import type { AssignableRole } from "@/shared/lib/roles";
import type { MembershipStatus } from "@/shared/stores/authStore";

export interface TransitionSeasonResponse {
  seasonId: string;
  name: string;
  startDate: string;
  endDate: string;
  counts: Record<string, number>;
}

/** Copy the current season's entries into a fresh N+1 draft (409 carries existingSeasonId). */
export function transitionSeason(sourceSeasonId: string): Promise<TransitionSeasonResponse> {
  return api.post(`seasons/${sourceSeasonId}/transition`).json();
}

/**
 * Rename THE season plan (ADR-0002 inv. 12: the name lives on the plan, not on
 * the season — one writer, so a season edit can never clobber it).
 */
export function renamePlanning(planId: string, name: string): Promise<unknown> {
  return api.put(`schedule_plans/${planId}`, { json: { name } }).json();
}

export interface RegisterPayload {
  email: string;
  password: string;
  firstName: string;
  lastName: string;
  ara: string;
  club_name: string;
  /** RGPD : acceptation CGU + politique de confidentialité (obligatoire). */
  consent: boolean;
  /** P5-3b — jeton Cloudflare Turnstile. Optionnel : ignoré tant que Turnstile est
   *  inactif côté serveur (aucune sitekey). Absent/invalide quand actif → 403. */
  turnstileToken?: string;
}

/**
 * P5-3b — config publique de la page d'inscription. `turnstileSiteKey` non-null
 * = Turnstile actif (le front rend le widget) ; null = inactif (écran actuel).
 * P2-4 — `demoShortcut` vrai en démo (kernel.debug serveur) : le front tente le
 * raccourci démo après le 202 du register ; faux en prod → écran actuel.
 * `demoEmail` = l'adresse démo, non-nulle SEULEMENT en debug : le front ne tente le
 * raccourci que si l'adresse SAISIE est celle-là (jamais le mdp d'un vrai prospect
 * vers la route dev). Nulle en prod → aucun oracle.
 */
export interface RegisterConfig {
  turnstileSiteKey: string | null;
  demoShortcut: boolean;
  demoEmail: string | null;
}

/** GET /api/register/config — publique (préfixe ^/api/register). */
export function registerConfig(): Promise<RegisterConfig> {
  return api.get("register/config").json();
}

/** P2-4 — le corps du raccourci démo : l'adresse démo + le code FFBB du prospect. */
export interface DevDemoRegisterPayload {
  email: string;
  password: string;
  ara: string;
  clubName: string;
}

/** P2-4 — la route démo pose un cookie JWT et rend le club matérialisé. */
export interface DevDemoRegisterResponse {
  membershipStatus: MembershipStatus;
  clubId: string;
}

/**
 * P2-4 — POST /api/dev/demo-register : le raccourci démo (gardé serveur par
 * kernel.debug ET l'adresse démo). Tenté par la page d'inscription APRÈS le 202
 * du register quand `demoShortcut` est vrai. 2xx → session ouverte (cookie posé) ;
 * 422/404 → à traiter comme un fallback silencieux vers l'écran d'e-mail ; 409 →
 * refus explicite (code FFBB d'un club réel).
 */
export function devDemoRegister(body: DevDemoRegisterPayload): Promise<DevDemoRegisterResponse> {
  return api.post("dev/demo-register", { json: body }).json();
}

/** Register never authenticates: it returns an identical neutral 202 for a fresh
 *  or an already-registered email (A3 anti-enumeration). The JWT is issued only by
 *  verifyEmail once the emailed link is followed. */
export interface RegisterResponse {
  status: string;
}

/** SEC-16 : plus de `token` — l'identité est posée en cookie httpOnly par le serveur. */
export interface VerifyEmailResponse {
  membershipStatus: MembershipStatus;
  user: { id: string; email: string };
}

export interface PendingMember {
  id: string;
  userId: string;
  email: string;
  firstName: string;
  lastName: string;
}

/** Un membre ACTIF du club (écran de gestion). `isSelf` : la ligne du gestionnaire courant. */
export interface ActiveMember {
  id: string;
  userId: string;
  email: string;
  firstName: string;
  lastName: string;
  role: string;
  isSelf: boolean;
}

/** Un membre DÉSACTIVÉ (réactivable) — même forme, sans geste sur soi. */
export interface DeactivatedMember {
  id: string;
  userId: string;
  email: string;
  firstName: string;
  lastName: string;
  role: string;
}

/**
 * `GET /api/memberships?includeDeactivated=1` : membres actifs + désactivés en une
 * lecture (un seul readState pour toute la section). `deactivated` peut manquer
 * (réponse sans le paramètre) — le traiter comme vide, jamais comme une erreur.
 */
export interface MembersResponse {
  members: ActiveMember[];
  deactivated?: DeactivatedMember[];
}

/**
 * SEC-16 : la réponse n'a PLUS DE CORPS — lexik retire le jeton du JSON dès qu'il
 * le pose en cookie (`remove_token_from_body_when_cookies_used`). Appeler `.json()`
 * ici jetterait sur un corps vide : le succès se lit sur l'absence d'erreur.
 */
export function login(body: { email: string; password: string }): Promise<unknown> {
  return api.post("login", { json: body });
}

/** SEC-16 : seul le serveur peut effacer un cookie httpOnly — sans cet appel, la
 *  session resterait valide jusqu'à son expiration malgré un « Se déconnecter ». */
export function logout(): Promise<unknown> {
  return api.post("logout");
}

export function register(body: RegisterPayload): Promise<RegisterResponse> {
  return api.post("register", { json: body }).json();
}

/**
 * P4-298 — l'identité du club derrière un code FFBB (AFFICHAGE SEUL : jamais de
 * pré-remplissage de `club_name`, jamais le mail du club). Trois états francs :
 * `found` (name + city nullable), `unknown` (format invalide ou inconnu), `unavailable`
 * (FFBB muette OU vérification désactivée côté serveur) → le front n'affiche alors rien.
 */
export interface ClubLookupResponse {
  status: "found" | "unknown" | "unavailable";
  name?: string;
  city?: string | null;
}

/** GET /api/register/club-lookup?code=… — publique (préfixe ^/api/register). */
export function clubLookup(code: string): Promise<ClubLookupResponse> {
  return api.get("register/club-lookup", { searchParams: { code } }).json();
}

export function verifyEmail(token: string): Promise<VerifyEmailResponse> {
  return api.post("register/verify", { json: { token } }).json();
}

/** SEC-16 : la confirmation repose un cookie httpOnly frais (nouvelle identité) — pas de jeton dans le corps. */
export interface ConfirmEmailChangeResponse {
  status: "email_confirmed";
  email: string;
}

/**
 * P4-74 — consommer le lien de confirmation de changement d'e-mail (PUBLIC : le
 * token EST l'identité). La bascule a lieu ici ; le serveur repose un cookie
 * pour la nouvelle adresse (le compte reste connecté).
 */
export function confirmEmailChange(token: string): Promise<ConfirmEmailChangeResponse> {
  return api.post("me/email/confirm", { json: { token } }).json();
}

export function forgotPassword(email: string): Promise<unknown> {
  return api.post("password/forgot", { json: { email } }).json();
}

export function resetPassword(body: { token: string; password: string }): Promise<unknown> {
  return api.post("password/reset", { json: body }).json();
}

export function getPendingMembers(): Promise<{ members: PendingMember[] }> {
  return api.get("memberships/pending").json();
}

/** Membres actifs + désactivés du club (management). Une lecture, un readState. */
export function getMembers(): Promise<MembersResponse> {
  return api.get("memberships", { searchParams: { includeDeactivated: "1" } }).json();
}

/** PR C : le rôle est REQUIS à l'approbation — le serveur refuse (422) un corps sans `role`. */
export function approveMember(id: string, role: AssignableRole): Promise<unknown> {
  return api.post(`memberships/${id}/approve`, { json: { role } }).json();
}

export function changeMemberRole(id: string, role: AssignableRole): Promise<unknown> {
  return api.post(`memberships/${id}/role`, { json: { role } }).json();
}

export async function deactivateMember(id: string): Promise<void> {
  await api.post(`memberships/${id}/deactivate`);
}

export async function reactivateMember(id: string): Promise<void> {
  await api.post(`memberships/${id}/reactivate`);
}

export async function rejectMember(id: string): Promise<void> {
  await api.post(`memberships/${id}/reject`);
}

/** P3-4 PR C — la page publique d'approbation (le token EST l'identité, pas de JWT). */
export interface ClubApprovalInfo {
  clubName: string;
  ara: string;
  requesterName: string;
  expiresAt: string;
}

export function getClubApproval(token: string): Promise<ClubApprovalInfo> {
  return api.get(`club-approvals/${encodeURIComponent(token)}`).json();
}

export function decideClubApproval(token: string, decision: "approve" | "refuse"): Promise<{ status: string }> {
  return api.post(`club-approvals/${encodeURIComponent(token)}`, { json: { decision } }).json();
}

/**
 * P4-299 — la page PUBLIQUE d'une invitation (le token EST l'identité, pas de JWT).
 * 404 byte-identique pour un lien inconnu, expiré OU révoqué (rien ne les distingue).
 */
export interface InvitationInfo {
  clubName: string;
  /** L'adresse invitée — affichée en lecture seule au formulaire de création. */
  email: string;
  /** `admin` | `member` (libellé via `roleLabel`). */
  role: string;
  /** Un compte VÉRIFIÉ existe pour cette adresse → « se connecter » plutôt que « créer ». */
  hasAccount: boolean;
}

export function getInvitation(token: string): Promise<InvitationInfo> {
  return api.get(`invitations/public/${encodeURIComponent(token)}`).json();
}

/** SEC : la réponse ne porte pas de jeton — le serveur pose le cookie httpOnly de la nouvelle identité. */
export interface AcceptInvitationResponse {
  membershipStatus: MembershipStatus;
  user?: { id: string; email: string };
  clubId?: string;
}

/**
 * Accepte SANS compte : crée le compte (né vérifié) et l'adhésion ACTIVE au rôle invité.
 * Consentement RGPD requis (même gate que l'inscription). 409 si un compte existe déjà.
 */
export function acceptInvitationWithoutAccount(
  token: string,
  body: { firstName: string; lastName: string; password: string; consent: boolean },
): Promise<AcceptInvitationResponse> {
  return api.post(`invitations/public/${encodeURIComponent(token)}/accept`, { json: body }).json();
}

/**
 * Accepte CONNECTÉ (un clic) : l'e-mail du compte DOIT être l'adresse invitée (403 sinon).
 * La route est authentifiée (cookie), aucun corps.
 */
export function acceptInvitationConnected(token: string): Promise<AcceptInvitationResponse> {
  return api.post(`invitations/${encodeURIComponent(token)}/accept`).json();
}
