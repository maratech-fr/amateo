import { useEffect } from "react";
import { Navigate, Outlet, useLocation } from "react-router";

import { OfflineScreen } from "@/app/OfflineScreen";
import { ServerErrorScreen } from "@/app/ServerErrorScreen";
import { useMe } from "@/shared/session/queries";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { useOnline } from "@/shared/lib/online";
import { useAuthStore } from "@/shared/stores/authStore";
import { toast } from "@/shared/stores/toastStore";

// During onboarding — until the season has a main plan (baseline, set by the
// first generation) — the app is locked to the wizard, except the account-menu
// (burger) destinations, which stay reachable.
// This list MUST mirror the account menu in AppLayout: an entry shown there but
// missing here is a visible link that bounces back to /wizard. `/confidentialite`
// was exactly that — and it is the page a user is most likely to want BEFORE
// finishing signup.
const ONBOARDING_ALLOWED = ["/wizard", "/profile", "/club", "/confidentialite", "/boite-aux-lettres"];

/**
 * Gate for authenticated routes:
 * - pas de session      -> /login
 * - session + loading   -> spinner
 * - session + active    -> render app
 * - session + pending/none-> /waiting
 * - session + auth error-> /login (cookie expiré ou invalide)
 *
 * SEC-16 : « session » = le drapeau d'UI du store, PAS une autorisation — le JWT
 * est un cookie httpOnly que ce code ne voit pas. Un drapeau menteur ne donne
 * accès à rien : `useMe` répond 401 et renvoie ici sur /login.
 */
export function AuthGuard() {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated);
  const { data, isLoading, isError, refetch } = useMe();
  const location = useLocation();
  // Source de vérité UNIQUE de l'état réseau (P5-14), appelée inconditionnellement (hook) AVANT les
  // sorties anticipées. Après le seed du boot, équivaut à `navigator.onLine` à l'instant de décision.
  const online = useOnline();

  // First-time club: locked to the wizard until a main plan exists (baseline),
  // but the account-menu routes (profile, club) stay reachable. Landing on the
  // cockpit home gets an ephemeral hint on the redirect — only for an ACTIVE
  // member (pending users are sent to /waiting by the guard below).
  // Onboarding phase = the club has never generated a plan (single source of
  // truth; the legacy club.onboardingCompleted flag is no longer read for
  // routing). Keyed on "has a finished version", NOT on the plan's pointer:
  // reopening a plan must not send an established club back to onboarding.
  const membershipActive = "active" === data?.membershipStatus;
  const onboardingLocked = Boolean(data) && !data?.seasonPlan?.hasFinishedVersion;
  const showCockpitHint = membershipActive && onboardingLocked && "/" === location.pathname;
  useEffect(() => {
    if (showCockpitHint) {
      toast.info("Lancez votre première génération d'abord.");
    }
  }, [showCockpitHint]);

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }
  if (isLoading) {
    return <FullPageSpinner />;
  }
  if (isError || !data) {
    // P5-14 — un VRAI 401 (session périmée) a DÉJÀ vidé l'auth dans client.ts :
    // il est capté par `!isAuthenticated` plus haut → /login. Atteindre ici avec
    // `isError` signifie un échec NON-401 : 5xx, réseau coupé, timeout. Ne JAMAIS
    // rediriger vers /login (le mensonge « reconnectez-vous » quand le serveur
    // tombe). Réseau coupé → écran hors-ligne ; sinon → écran 500 ; « Réessayer »
    // relance le fetch de /api/me.
    if (!online) {
      return <OfflineScreen onRetry={() => void refetch()} />;
    }
    return <ServerErrorScreen onRetry={() => void refetch()} />;
  }
  if (data.membershipStatus !== "active") {
    return <Navigate to="/waiting" replace />;
  }
  if (onboardingLocked && !ONBOARDING_ALLOWED.includes(location.pathname)) {
    return <Navigate to="/wizard" replace />;
  }

  return <Outlet />;
}
