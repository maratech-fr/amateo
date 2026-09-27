import { useMutation, useQueryClient } from "@tanstack/react-query";

import { errorMessage } from "@/shared/lib/errorMessage";
import { toast } from "@/shared/stores/toastStore";

import type { ChangePasswordPayload, UpdateProfilePayload } from "./api";
import * as profileApi from "./api";

export function useUpdateProfile() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: UpdateProfilePayload) => profileApi.updateProfile(body),
    onSuccess: () => {
      toast.success("Profil mis à jour.");
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    },
  });
}

/**
 * P4-74 — demander un changement d'e-mail. Succès = lien envoyé à la nouvelle
 * adresse ; l'adresse actuelle reste active. On invalide `me` pour afficher le
 * pending. Les erreurs serveur (409 adresse prise, 400 invalide) sont restituées.
 */
export function useRequestEmailChange() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ email, currentPassword }: { email: string; currentPassword: string }) =>
      profileApi.requestEmailChange(email, currentPassword),
    onSuccess: (result) => {
      toast.info(`Un lien de confirmation a été envoyé à ${result.pendingEmail} — votre adresse actuelle reste active.`);
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    },
    onError: (err) => void errorMessage(err).then((message) => toast.error(message)),
  });
}

/** P4-74 — annuler la demande de changement d'e-mail en attente. */
export function useCancelEmailChange() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => profileApi.cancelEmailChange(),
    onSuccess: () => {
      toast.success("Changement d'e-mail annulé.");
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    },
  });
}

export function useChangePassword() {
  return useMutation({
    mutationFn: (body: ChangePasswordPayload) => profileApi.changePassword(body),
    onSuccess: () => toast.success("Mot de passe modifié."),
  });
}

export function useDeleteAccount() {
  return useMutation({
    mutationFn: (password: string) => profileApi.deleteAccount(password),
    // Pas de toast succès ici : l'appelant déconnecte immédiatement (le compte
    // n'existe plus) et affiche la conséquence club si elle s'applique.
  });
}

/** RGPD portabilité — export JSON de mes données de compte. */
export function useDownloadMyData() {
  return useMutation({
    mutationFn: () => profileApi.downloadMyDataExport(),
    onSuccess: () => toast.success("Export téléchargé."),
  });
}
