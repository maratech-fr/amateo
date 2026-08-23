import { createBrowserRouter, RouterProvider, type RouteObject } from "react-router";

import { AppLayout } from "@/app/AppLayout";
import { AuthGuard } from "@/app/AuthGuard";
import { NotFoundPage } from "@/app/NotFoundPage";
import { RootShell } from "@/app/RootShell";
import { RouteErrorBoundary } from "@/app/RouteErrorBoundary";
import { AdminGuard } from "@/features/admin/AdminGuard";
import { LoginPage } from "@/features/auth/LoginPage";
import { FullPageSpinner } from "@/shared/components/ui/spinner";

/**
 * Découpage du bundle (P4-6) — un chunk unique de 834 kB partait à CHAQUE
 * première visite : console superadmin et wizard compris, y compris pour un
 * coach qui n'ouvre qu'une page publique de doléances.
 *
 * Reste EAGER ce qui sert au premier rendu du chemin d'entrée : `LoginPage`, le
 * garde d'auth et le shell applicatif. Tout le reste part en `lazy` de route —
 * react-router gère l'attente (pas de Suspense à câbler), et le chunk d'une
 * route jamais visitée n'est jamais téléchargé.
 *
 * Les gros gains : `/admin` (console superadmin — un gestionnaire de club ne
 * l'ouvre jamais), `/wizard` (la plus grosse feature), `/doleances/:token`
 * (page publique sans login : le coach n'a besoin de rien d'autre).
 *
 * Découper impose trois filets, sans lesquels le gain se paie en pannes muettes :
 *  - `errorElement` — un chunk 404 (déploiement pendant la session) remplaçait
 *    TOUTE l'app par l'écran anglais non stylé du router, invisible de Sentry ;
 *  - `HydrateFallback` — sans lui, react-router rend `null` : page BLANCHE à
 *    chaque ouverture directe ou F5 d'une route lazy ;
 *  - un indicateur d'attente (`useNavigation`, cf. AppLayout) — sinon un clic de
 *    navigation ne produit AUCUN retour tant que le chunk n'est pas là.
 *
 * Les GARDES restent eager (`AuthGuard`, `AdminGuard`) : leur code doit être là
 * pour décider. ⚠ Cela n'empêche PAS le téléchargement des chunks enfants : le
 * data router résout le `lazy` de TOUTES les routes appariées avant d'en rendre
 * une seule, donc un visiteur anonyme sur `/planning` télécharge la page avant
 * d'être redirigé vers `/login`. Ce n'est pas une fuite (ce JS est public et sans
 * donnée), c'est de la bande passante pour un cas rare — l'éviter demanderait de
 * dupliquer la décision d'auth dans un `loader` par route, ce qui remettrait la
 * garde à deux endroits. Compromis assumé, pas propriété acquise.
 */
// Exporté pour le NR des filets (router.test.tsx) : la présence de `errorElement`
// et `HydrateFallback` ne casse aucun test de page si elle disparaît.
export const routes: RouteObject[] = [
  {
    // Route racine technique : aucune UI propre, elle porte les filets de TOUT
    // l'arbre — l'attente de navigation (RootShell) et l'erreur de route (une
    // erreur remonte au parent le plus proche qui en déclare une).
    element: <RootShell />,
    errorElement: <RouteErrorBoundary />,
    HydrateFallback: FullPageSpinner,
    children: [
      { path: "/login", element: <LoginPage /> },
      {
        path: "/admin/login",
        lazy: async () => ({ Component: (await import("@/features/admin/AdminLoginPage")).AdminLoginPage }),
      },
      {
        path: "/admin",
        element: <AdminGuard />,
        children: [
          {
            lazy: async () => ({ Component: (await import("@/features/admin/AdminShell")).AdminShell }),
            children: [
              {
                index: true,
                lazy: async () => ({ Component: (await import("@/features/admin/AdminDashboardPage")).AdminDashboardPage }),
              },
            ],
          },
          // Hors du shell LAZY : une URL admin inconnue ne doit pas télécharger la
          // console entière. Une vraie 404 (P5-14, EAGER, écran minuscule) plutôt
          // qu'une téléportation muette ; geste « Retour à la console » → /admin. Pas
          // de « Contacter le support » ici : l'admin a une identité séparée, sans
          // canal de signalement de club.
          { path: "*", element: <NotFoundPage homeTo="/admin" homeLabel="Retour à la console" showSupport={false} /> },
        ],
      },
      {
        path: "/register",
        lazy: async () => ({ Component: (await import("@/features/auth/RegisterPage")).RegisterPage }),
      },
      {
        path: "/forgot-password",
        lazy: async () => ({ Component: (await import("@/features/auth/ForgotPasswordPage")).ForgotPasswordPage }),
      },
      {
        path: "/reset-password/:token",
        lazy: async () => ({ Component: (await import("@/features/auth/ResetPasswordPage")).ResetPasswordPage }),
      },
      {
        path: "/verify-email/:token",
        lazy: async () => ({ Component: (await import("@/features/auth/VerifyEmailPage")).VerifyEmailPage }),
      },
      {
        // P4-74 — confirmer un changement d'e-mail (le token du lien reçu à la
        // nouvelle adresse est l'identité ; la bascule a lieu ici).
        path: "/confirm-email/:token",
        lazy: async () => ({ Component: (await import("@/features/auth/ConfirmEmailChangePage")).ConfirmEmailChangePage }),
      },
      {
        // P3-4 PR C — page PUBLIQUE d'approbation de création de club (le token
        // du mail officiel FFBB est l'identité, pas de compte).
        path: "/club-approval/:token",
        lazy: async () => ({ Component: (await import("@/features/auth/ClubApprovalPage")).ClubApprovalPage }),
      },
      {
        path: "/waiting",
        lazy: async () => ({ Component: (await import("@/features/auth/WaitingApprovalPage")).WaitingApprovalPage }),
      },
      {
        path: "/confidentialite",
        lazy: async () => ({ Component: (await import("@/features/legal/PrivacyPage")).PrivacyPage }),
      },
      // #10 C2 — page publique SANS login : le coach saisit ses disponibilités via son
      // lien personnel. Route plate, hors AuthGuard (aucune session requise).
      {
        path: "/doleances/:token",
        lazy: async () => ({ Component: (await import("@/features/coach-wishes/PublicWishPage")).PublicWishPage }),
      },
      {
        element: <AuthGuard />,
        children: [
          {
            element: <AppLayout />,
            // Filet IMBRIQUÉ : sans lui, l'échec du chunk d'UNE page démontait
            // l'en-tête, la navigation et les bandeaux — pour une panne réseau
            // passagère, avec le rechargement complet pour unique issue.
            errorElement: <RouteErrorBoundary />,
            children: [
          {
            path: "/",
            lazy: async () => ({ Component: (await import("@/features/cockpit/CockpitPage")).CockpitPage }),
          },
          {
            path: "/planning",
            lazy: async () => ({ Component: (await import("@/features/planning/PlanningPage")).PlanningPage }),
          },
          {
            // RMM-1 PR2 — « deux espaces ». `/matchs` est un LAYOUT (garde socle +
            // navigation) ; ses deux enfants sont deux ROUTES lazy : la boucle hebdo
            // (index) et la Configuration rare, qui ne charge donc pas avec la boucle.
            // Les filets (errorElement, HydrateFallback) du parent couvrent les deux.
            path: "/matchs",
            lazy: async () => ({ Component: (await import("@/features/matches/MatchesLayout")).MatchesLayout }),
            children: [
              {
                index: true,
                lazy: async () => ({ Component: (await import("@/features/matches/MatchesPage")).MatchesPage }),
              },
              {
                path: "configuration",
                lazy: async () => ({ Component: (await import("@/features/matches/ConfigurationPage")).ConfigurationPage }),
              },
            ],
          },
          {
            path: "/wizard",
            lazy: async () => ({ Component: (await import("@/features/wizard/WizardLayout")).WizardPage }),
          },
          {
            path: "/club",
            lazy: async () => ({ Component: (await import("@/features/club/ClubPage")).ClubPage }),
          },
          {
            path: "/profile",
            lazy: async () => ({ Component: (await import("@/features/profile/ProfilePage")).ProfilePage }),
          },
          {
            path: "/nouveautes",
            lazy: async () => ({ Component: (await import("@/features/release-notes/ReleaseNotesPage")).ReleaseNotesPage }),
          },
          // URL authentifiée inconnue → une vraie 404 (P5-14, EAGER), sous AppLayout
          // (en-tête et navigation conservés). Plus de téléportation muette vers
          // l'accueil : une URL inconnue le DIT. Cette même 404 sert au refus tenant
          // (ressource d'un autre club → 404, jamais 403) — copie muette sur les droits.
          { path: "*", element: <NotFoundPage /> },
        ],
      },
        ],
      },
    ],
  },
];

/**
 * Construit à la PREMIÈRE utilisation, pas à l'import : `createBrowserRouter`
 * s'initialise tout seul (il lit l'historique et lance une navigation). Au
 * niveau module, il s'exécutait donc dans chaque test important `routes`.
 */
let router: ReturnType<typeof createBrowserRouter> | null = null;

export function AppRouter() {
  router ??= createBrowserRouter(routes);

  return <RouterProvider router={router} />;
}
