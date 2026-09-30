import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { errorMessage } from "@/shared/lib/errorMessage";
import type { AssignableRole } from "@/shared/lib/roles";
import { useAuthStore } from "@/shared/stores/authStore";
import { toast } from "@/shared/stores/toastStore";

import * as authApi from "./api";

/**
 * Rejoue le message lisible du serveur (l'invariant « au moins un gestionnaire »
 * renvoie un 409 texte) plutôt qu'un « une erreur est survenue » générique. Le
 * serveur reste seul juge : l'UI n'anticipe pas le refus, elle le RESTITUE.
 */
function toastServerError(err: unknown): void {
  void errorMessage(err).then((message) => toast.error(message));
}

/**
 * ADR-0002 inv. 12 : renommer UN plan — celui de la saison comme celui d'une période.
 * L'endpoint (`PUT /schedule_plans/{id}`) l'a toujours été ; seuls ses appelants
 * visaient le plan de saison en dur.
 *
 * Deux caches à rafraîchir, et les deux comptent : `me` porte le nom du plan de
 * SAISON (en-tête, liste des plannings), `calendar-entries` porte la collection des
 * plans de PÉRIODE. N'invalider que `me` laissait un plan de période renommé
 * afficher son ancien nom pendant 30 s (staleTime de `useSchedulePlans`).
 */
export function useRenamePlanning() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ planId, name }: { planId: string; name: string }) => authApi.renamePlanning(planId, name),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["me"] });
      void queryClient.invalidateQueries({ queryKey: ["calendar-entries"] });
    },
  });
}

export function useLogin() {
  const setAuthenticated = useAuthStore((state) => state.setAuthenticated);
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: authApi.login,
    // P4-252 — la connexion a son PROPRE overlay (le splash « Signature », `app/LoginSplash.tsx`) :
    // il remplace le voile d'action générique pour ce geste. Sans `veil: false`, `ActionVeil`
    // afficherait EN PLUS son écran « Enregistrement » sous le splash (deux overlays, deux gérants
    // de focus) — incohérent. On l'exempte donc du voile générique.
    meta: { veil: false },
    // SEC-16 : la réponse ne porte plus de jeton (cookie httpOnly). On ne marque
    // que « une session est ouverte » — indice d'UI, l'autorisation reste au serveur.
    onSuccess: () => {
      setAuthenticated(true);
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    },
  });
}

/** Register no longer authenticates (A3): success just means "check your email".
 *  The token is issued by useVerifyEmail once the emailed link is followed. */
export function useRegister() {
  return useMutation({ mutationFn: authApi.register });
}

/**
 * P5-3b — config publique du register (sitekey Turnstile). Quasi-statique →
 * staleTime infini ; un fetch en échec laisse `data` indéfini, ce que la page
 * traite comme « Turnstile inactif » (écran actuel, sans widget). Publique, donc
 * toujours activée (pas de gate d'auth).
 */
export function useRegisterConfig() {
  return useQuery({
    queryKey: ["register-config"],
    queryFn: authApi.registerConfig,
    staleTime: Infinity,
    retry: false,
  });
}

export function useVerifyEmail() {
  const setAuthenticated = useAuthStore((state) => state.setAuthenticated);
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: authApi.verifyEmail,
    onSuccess: () => {
      setAuthenticated(true);
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    },
  });
}

/**
 * P2-4 — le raccourci démo du register. Miroir EXACT de useVerifyEmail : le serveur
 * pose un cookie JWT frais, on marque la session ouverte et on invalide `me` (le
 * club vient de naître). La page enchaîne ensuite la navigation dans l'app.
 */
export function useDevDemoRegister() {
  const setAuthenticated = useAuthStore((state) => state.setAuthenticated);
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: authApi.devDemoRegister,
    onSuccess: () => {
      setAuthenticated(true);
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    },
  });
}

/**
 * SEC-16 : la déconnexion passe désormais par le SERVEUR — lui seul peut effacer
 * un cookie httpOnly. L'état local est vidé quoi qu'il arrive (`finally`) : si le
 * réseau tombe, l'écran doit quand même se fermer, et le cookie expirera de
 * lui-même. L'inverse — garder l'UI ouverte parce que l'appel a échoué —
 * laisserait l'utilisateur croire qu'il est encore connecté.
 */
export function useLogout() {
  const clear = useAuthStore((state) => state.clear);
  const queryClient = useQueryClient();
  return async () => {
    try {
      await authApi.logout();
    } catch {
      // silencieux : voir plus haut
    } finally {
      clear();
      queryClient.clear();
    }
  };
}

/**
 * P4-74 — confirme un changement d'e-mail via le lien reçu. Le serveur repose un
 * cookie frais pour la nouvelle identité : on marque la session active et on
 * invalide `me` (l'ancien JWT ne résolvait plus l'adresse basculée).
 */
export function useConfirmEmailChange() {
  const setAuthenticated = useAuthStore((state) => state.setAuthenticated);
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: authApi.confirmEmailChange,
    onSuccess: () => {
      setAuthenticated(true);
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    },
  });
}

export function useForgotPassword() {
  return useMutation({ mutationFn: authApi.forgotPassword });
}

export function useResetPassword() {
  return useMutation({ mutationFn: authApi.resetPassword });
}

export function usePendingMembers(enabled: boolean) {
  return useQuery({
    queryKey: ["memberships", "pending"],
    queryFn: authApi.getPendingMembers,
    enabled,
  });
}

/** Membres actifs + désactivés du club (management) — l'écran de gestion. */
export function useMembers(enabled: boolean) {
  return useQuery({
    queryKey: ["memberships", "list"],
    queryFn: authApi.getMembers,
    enabled,
  });
}

/**
 * Invalide TOUTE la famille `["memberships", …]` : approuver/rétrograder/désactiver
 * déplace une adhésion d'une liste à l'autre (pending → actifs, actifs → désactivés),
 * donc les deux vues doivent se rafraîchir, pas seulement celle d'où part le geste.
 */
function useMembershipMutation<TArgs>(mutationFn: (args: TArgs) => Promise<unknown>) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["memberships"] }),
    onError: toastServerError,
  });
}

export function useApproveMember() {
  return useMembershipMutation(({ id, role }: { id: string; role: AssignableRole }) => authApi.approveMember(id, role));
}

export function useRejectMember() {
  return useMembershipMutation((id: string) => authApi.rejectMember(id));
}

export function useChangeMemberRole() {
  return useMembershipMutation(({ id, role }: { id: string; role: AssignableRole }) => authApi.changeMemberRole(id, role));
}

export function useDeactivateMember() {
  return useMembershipMutation((id: string) => authApi.deactivateMember(id));
}

export function useReactivateMember() {
  return useMembershipMutation((id: string) => authApi.reactivateMember(id));
}
