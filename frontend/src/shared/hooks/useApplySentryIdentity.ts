import * as Sentry from "@sentry/react";
import { useEffect } from "react";

import { useMe } from "@/shared/session/queries";

/**
 * Pose sur le scope Sentry l'identité MINIMALE qui permet de rejouer un bug sans PII
 * (décision fondateur 2026-10-06) : l'identifiant INTERNE de l'utilisateur
 * (`Sentry.setUser({ id })` — JAMAIS email/nom/IP, cohérent avec `dataCollection.userInfo: false`
 * de `buildSentryOptions`) et le CODE FFBB du club courant en tag `club_ffbb` (ex. `ARA0069013`,
 * `me.club.ffbbClubCode`). Rien d'autre.
 *
 * Monté par `AppLayout` (l'appli CLUB), à côté de `useApplyClubTheme` : il réagit au `me` du
 * serveur, donc l'id et le tag se reposent au login ET à tout changement de club. Un club DÉMO a
 * aussi un code FFBB → il est taggé (utile au diagnostic). La console superadmin a son propre shell
 * (`AdminShell`), hors `AppLayout` : un superadmin n'a pas de club et ne pose jamais de tag ici.
 * Au logout, `AppLayout` se démonte → le nettoyage efface l'utilisateur (`setUser(null)`) et le tag.
 *
 * Gardé par `VITE_SENTRY_DSN` (cohérent avec `main.tsx`/`client.ts`) : sans DSN le SDK est inerte,
 * on n'appelle rien.
 */
export function useApplySentryIdentity(): void {
  const { data: me } = useMe();
  const userId = me?.id ?? null;
  const clubFfbbCode = me?.club?.ffbbClubCode ?? null;

  useEffect(() => {
    if (!import.meta.env.VITE_SENTRY_DSN) {
      return;
    }
    // Id INTERNE seul — pas d'email/nom, et `ip_address` reste absent (userInfo: false
    // n'ajoute aucune IP automatique, et on ne passe que `{ id }`).
    Sentry.setUser(null === userId ? null : { id: userId });
    return () => {
      Sentry.setUser(null);
    };
  }, [userId]);

  useEffect(() => {
    if (!import.meta.env.VITE_SENTRY_DSN) {
      return;
    }
    // Pas de club (orphelin, superadmin ne passe pas ici) → `undefined` RETIRE le tag.
    Sentry.setTag("club_ffbb", clubFfbbCode ?? undefined);
    return () => {
      Sentry.setTag("club_ffbb", undefined);
    };
  }, [clubFfbbCode]);
}
