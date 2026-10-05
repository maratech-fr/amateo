import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { activateAdminDemo, activateAdminMembership, type AdminDemoTarget, type AdminFeedbackStatus, createAdminReleaseNote, deactivateAdminDemo, decideAdminClubRequest, deleteAdminReleaseNote, getAdminActions, getAdminAuditLog, getAdminCapacity, getAdminClubRequests, getAdminClubs, getAdminDemos, getAdminFeedback, getAdminFeedbackDetail, getAdminFreshness, getAdminHealth, getAdminJobs, getAdminMessengerFailed, getAdminOverview, getAdminPendingMemberships, getAdminReleaseNotes, getAdminSession, getAdminSystemErrors, publishAdminReleaseNote, type ReleaseNoteWritePayload, resetAdminDemoBccl, retainAdminDemoProspect, runAdminClubAction, runAdminJob, setAdminDemoClock, treatAdminFeedback, untreatAdminFeedback } from "./api";
import { useAdminStore } from "./store";

/** Lit le jeton CSRF de la session admin, ou rejette — patron des mutations admin. */
function requireCsrf(): string | null {
  return useAdminStore.getState().csrfToken;
}

export function useAdminSession() {
  return useQuery({
    queryKey: ["admin-session"],
    queryFn: async () => {
      const session = await getAdminSession();
      useAdminStore.getState().setSession({ id: session.id, email: session.email }, session.csrfToken);
      return session;
    },
    retry: false,
    staleTime: 5 * 60_000,
  });
}

export function useAdminOverview() {
  return useQuery({
    queryKey: ["admin-overview"],
    queryFn: getAdminOverview,
    refetchInterval: 60_000,
  });
}

export function useAdminHealth() {
  return useQuery({
    queryKey: ["admin-health"],
    queryFn: getAdminHealth,
    refetchInterval: 30_000,
  });
}

/** Capacité (P5-10) — la fenêtre est de 90 j : les agrégats bougent lentement, refetch 5 min. */
export function useAdminCapacity() {
  return useQuery({
    queryKey: ["admin-capacity"],
    queryFn: getAdminCapacity,
    refetchInterval: 5 * 60_000,
  });
}

export function useAdminClubs(page: number, limit: number, query: string) {
  return useQuery({
    queryKey: ["admin-clubs", { page, limit, query }],
    queryFn: () => getAdminClubs(page, limit, query),
    placeholderData: (previous) => previous,
  });
}

export function useAdminJobs() {
  return useQuery({
    queryKey: ["admin-jobs"],
    queryFn: getAdminJobs,
    refetchInterval: 60_000,
  });
}

export function useRunAdminJob() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (key: string) => {
      const csrfToken = useAdminStore.getState().csrfToken;
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return runAdminJob(key, csrfToken);
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: ["admin-jobs"] }),
  });
}

/** Data-freshness board — l'âge des référentiels bouge lentement : refetch 5 min. */
export function useAdminFreshness() {
  return useQuery({
    queryKey: ["admin-freshness"],
    queryFn: getAdminFreshness,
    refetchInterval: 5 * 60_000,
  });
}

/** SA4 — le catalogue fermé des actions support (stable : une seule lecture par session suffit). */
export function useAdminActions() {
  return useQuery({
    queryKey: ["admin-actions"],
    queryFn: getAdminActions,
    staleTime: 5 * 60_000,
  });
}

/** SA4 — exécute une action support sur un club (CSRF du store, comme useRunAdminJob). */
export function useRunAdminClubAction() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ clubId, key, args }: { clubId: string; key: string; args?: Record<string, string> }) => {
      const csrfToken = useAdminStore.getState().csrfToken;
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      // Pas de body pour une action sans schéma : on garde l'appel à 3 arguments
      // (le backend refuse tout body sur une action sans schéma).
      return args ? runAdminClubAction(clubId, key, csrfToken, args) : runAdminClubAction(clubId, key, csrfToken);
    },
    // Une action mute le club (quota, saison) : rafraîchir la liste ET l'overview.
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin-clubs"] });
      void queryClient.invalidateQueries({ queryKey: ["admin-overview"] });
    },
  });
}

/** Journal d'audit super-admin — refetch 60s comme les jobs. */
export function useAdminAuditLog(page: number, limit: number) {
  return useQuery({
    queryKey: ["admin-audit-log", { page, limit }],
    queryFn: () => getAdminAuditLog(page, limit),
    placeholderData: (previous) => previous,
    refetchInterval: 60_000,
  });
}

/** Messenger failed transport — refetch 60s. */
export function useAdminMessengerFailed(page: number, limit: number) {
  return useQuery({
    queryKey: ["admin-messenger-failed", { page, limit }],
    queryFn: () => getAdminMessengerFailed(page, limit),
    placeholderData: (previous) => previous,
    refetchInterval: 60_000,
  });
}

/** Erreurs système agrégées — refetch 60s. */
export function useAdminSystemErrors(page: number, limit: number) {
  return useQuery({
    queryKey: ["admin-system-errors", { page, limit }],
    queryFn: () => getAdminSystemErrors(page, limit),
    placeholderData: (previous) => previous,
    refetchInterval: 60_000,
  });
}

/** P3-4 PR B — les demandes de création (pending + expirées : la console garde la main). */
export function useAdminClubRequests() {
  return useQuery({
    queryKey: ["admin-club-requests"],
    queryFn: getAdminClubRequests,
    refetchInterval: 60_000,
  });
}

export function useDecideAdminClubRequest() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, decision }: { id: string; decision: "approve" | "refuse" }) => {
      const csrfToken = useAdminStore.getState().csrfToken;
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return decideAdminClubRequest(id, decision, csrfToken);
    },
    // Approuver crée un club → la liste des demandes ET le parc bougent.
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin-club-requests"] });
      void queryClient.invalidateQueries({ queryKey: ["admin-clubs"] });
      void queryClient.invalidateQueries({ queryKey: ["admin-pending-memberships"] });
    },
  });
}

/** P3-4 PR B — les adhésions en attente, tous clubs. */
export function useAdminPendingMemberships() {
  return useQuery({
    queryKey: ["admin-pending-memberships"],
    queryFn: getAdminPendingMemberships,
    refetchInterval: 60_000,
  });
}

export function useActivateAdminMembership() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => {
      const csrfToken = useAdminStore.getState().csrfToken;
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return activateAdminMembership(id, csrfToken);
    },
    onSettled: () => void queryClient.invalidateQueries({ queryKey: ["admin-pending-memberships"] }),
  });
}

/** P5-12 — le journal de nouveautés, brouillons inclus (atelier superadmin). */
export function useAdminReleaseNotes() {
  return useQuery({
    queryKey: ["admin-release-notes"],
    queryFn: getAdminReleaseNotes,
  });
}

export function useCreateAdminReleaseNote() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (body: ReleaseNoteWritePayload) => {
      const csrfToken = requireCsrf();
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return createAdminReleaseNote(body, csrfToken);
    },
    onSettled: () => void queryClient.invalidateQueries({ queryKey: ["admin-release-notes"] }),
  });
}

export function usePublishAdminReleaseNote() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => {
      const csrfToken = requireCsrf();
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return publishAdminReleaseNote(id, csrfToken);
    },
    onSettled: () => void queryClient.invalidateQueries({ queryKey: ["admin-release-notes"] }),
  });
}

export function useDeleteAdminReleaseNote() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => {
      const csrfToken = requireCsrf();
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return deleteAdminReleaseNote(id, csrfToken);
    },
    onSettled: () => void queryClient.invalidateQueries({ queryKey: ["admin-release-notes"] }),
  });
}

// P5-6 — inbox des signalements. La QoS voyage AVEC la liste (agrégat global renvoyé
// quel que soit le filtre) ; on la lit donc de la réponse courante, sans requête à part.
export function useAdminFeedback(status?: AdminFeedbackStatus) {
  return useQuery({
    queryKey: ["admin-feedback", status ?? "all"],
    queryFn: () => getAdminFeedback(status ? { status } : {}),
    placeholderData: (previous) => previous,
    refetchInterval: 60_000,
  });
}

export function useAdminFeedbackDetail(id: string | null) {
  return useQuery({
    queryKey: ["admin-feedback-detail", id],
    queryFn: () => getAdminFeedbackDetail(id as string),
    enabled: null !== id,
  });
}

export function useTreatAdminFeedback() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => {
      const csrfToken = requireCsrf();
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return treatAdminFeedback(id, csrfToken);
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin-feedback"] });
      void queryClient.invalidateQueries({ queryKey: ["admin-feedback-detail"] });
    },
  });
}

export function useUntreatAdminFeedback() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => {
      const csrfToken = requireCsrf();
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return untreatAdminFeedback(id, csrfToken);
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin-feedback"] });
      void queryClient.invalidateQueries({ queryKey: ["admin-feedback-detail"] });
    },
  });
}

// Démos — état des deux comptes + gestes (activation, désactivation, reset BCCL, horloge BCCL).

export function useAdminDemos() {
  return useQuery({
    queryKey: ["admin-demos"],
    queryFn: getAdminDemos,
    // BCK-35 — on sonde vite tant qu'un reset BCCL tourne (pour voir l'issue arriver), sinon
    // la cadence lente habituelle.
    refetchInterval: (query) => ("running" === query.state.data?.reset?.state ? 2_000 : 60_000),
  });
}

export function useActivateAdminDemo() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (target: AdminDemoTarget) => {
      const csrfToken = requireCsrf();
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return activateAdminDemo(target, csrfToken);
    },
    onSettled: () => void queryClient.invalidateQueries({ queryKey: ["admin-demos"] }),
  });
}

export function useDeactivateAdminDemo() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (target: AdminDemoTarget) => {
      const csrfToken = requireCsrf();
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return deactivateAdminDemo(target, csrfToken);
    },
    onSettled: () => void queryClient.invalidateQueries({ queryKey: ["admin-demos"] }),
  });
}

export function useResetAdminDemoBccl() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => {
      const csrfToken = requireCsrf();
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return resetAdminDemoBccl(csrfToken);
    },
    onSettled: () => void queryClient.invalidateQueries({ queryKey: ["admin-demos"] }),
  });
}

export function useSetAdminDemoClock() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ target, body }: { target: AdminDemoTarget; body: { date: string } | { clear: true } }) => {
      const csrfToken = requireCsrf();
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return setAdminDemoClock(target, body, csrfToken);
    },
    onSettled: () => void queryClient.invalidateQueries({ queryKey: ["admin-demos"] }),
  });
}

export function useRetainAdminDemoProspect() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => {
      const csrfToken = requireCsrf();
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return retainAdminDemoProspect(csrfToken);
    },
    onSettled: () => void queryClient.invalidateQueries({ queryKey: ["admin-demos"] }),
  });
}
