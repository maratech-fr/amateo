import { Flag, LogOut, Menu as MenuIcon, Moon, Settings, Sparkles, Sun, User, ShieldCheck } from "lucide-react";
import { useState } from "react";
import { NavLink, Outlet, useNavigation } from "react-router";

import { useLogout } from "@/features/auth/queries";
import { useMe } from "@/shared/session/queries";
import { FeedbackDialog } from "@/features/feedback/FeedbackDialog";
import { WhatsNewModal } from "@/features/release-notes/WhatsNewModal";
import { BrandIcon } from "@/shared/components/ui/brand-icon";
import { Button } from "@/shared/components/ui/button";
import { Menu, MenuItem } from "@/shared/components/ui/menu";
import { CreditBadge } from "@/shared/credits/CreditBadge";
import { CreditsBanner } from "@/shared/credits/CreditsBanner";
import { useApplyClubTheme } from "@/shared/hooks/useApplyClubTheme";
import { useApplyDemoClock } from "@/shared/hooks/useApplyDemoClock";
import { PRODUCT_NAME } from "@/shared/lib/product";
import { cn } from "@/shared/lib/utils";
import { useThemeStore } from "@/shared/stores/themeStore";

import { ReadonlySeasonBanner } from "./ReadonlySeasonBanner";
import { DevClock } from "./DevClock";
import { SeasonSelector } from "./SeasonSelector";
import { SeasonTransitionBanner } from "./SeasonTransitionBanner";

function NavItem({ to, children }: { to: string; children: string }) {
  return (
    <NavLink
      to={to}
      end
      className={({ isActive }) =>
        cn("rounded-md px-3 py-1.5 text-sm transition-colors", isActive ? "bg-accent text-accent-foreground" : "text-muted-foreground hover:text-foreground")
      }
    >
      {children}
    </NavLink>
  );
}

export function AppLayout() {
  // `idle` = rien en vol ; toute autre valeur = une navigation attend (chunk lazy).
  const navigating = "idle" !== useNavigation().state;
  const { data } = useMe();
  const logout = useLogout();
  useApplyClubTheme();
  useApplyDemoClock();
  const mode = useThemeStore((state) => state.mode);
  const toggleMode = useThemeStore((state) => state.toggleMode);
  const [feedbackOpen, setFeedbackOpen] = useState(false);

  return (
    <div className="min-h-screen text-foreground">
      {/* En-tête OPAQUE (`bg-background`) posé sur le fond d'écran commun (P5-16) : le fond ne vit
          que dans les zones vides, jamais sous l'en-tête ni les cartes. La racine, elle, laisse
          passer le fond du `body`. */}
      <header className="border-b border-border bg-background">
        <div className="flex h-14 items-center justify-between gap-4 px-4 lg:px-6 xl:px-8">
          {/* L'en-tête = la marque PRODUIT, puis le club (décision fondateur DA) :
              [icône Amateo] · [blason du club, s'il existe] NOM DU CLUB. L'icône produit
              est TOUJOURS là ; le blason suit quand il existe. Le sens accessible du lien
              d'accueil vit sur son `aria-label` (nom du club sinon PRODUCT_NAME), à toutes
              les largeurs — icône + blason restent décoratifs. Sous `sm` (téléphone,
              desktop-first / mobile V2, P4-261) le MOT est masqué visuellement seulement
              (`hidden sm:inline`), pour ne plus se tronquer à « B… » ; le nom accessible ne
              bouge pas. La nav de droite ne se rétracte pas (décision fondateur). */}
          <div className="flex min-w-0 items-center gap-2">
            <NavLink
              to="/"
              aria-label={data?.club?.name ?? PRODUCT_NAME}
              title="Retour à l'accueil (tableau de bord)"
              className="flex min-w-0 items-center gap-2 rounded-md transition-opacity hover:opacity-80"
            >
              <BrandIcon className="size-6 shrink-0" />
              {data?.club?.logoUrl ? <img src={data.club.logoUrl} alt="" className="size-6 shrink-0 rounded-full object-cover" /> : null}
              <span className="hidden truncate text-sm font-semibold sm:inline">{data?.club?.name ?? PRODUCT_NAME}</span>
            </NavLink>
            {import.meta.env.DEV ? <DevClock /> : null}
          </div>
          <nav className="flex items-center gap-1">
            <CreditBadge />
            <SeasonSelector />
            {/* Matches stay locked until the season's plan points at a version —
                same condition the server enforces (SocleGuard). */}
            {null != data?.seasonPlan?.chosenScheduleId ? (
              <NavItem to="/matchs">Matchs</NavItem>
            ) : (
              <span
                aria-disabled="true"
                className="cursor-not-allowed rounded-md px-3 py-1.5 text-sm text-muted-foreground/40"
                title="Validez le planning principal pour débloquer les matchs"
              >
                Matchs
              </span>
            )}
            <Button
              variant="ghost"
              size="icon"
              aria-label={mode === "dark" ? "Activer le thème clair" : "Activer le thème sombre"}
              title={mode === "dark" ? "Thème clair" : "Thème sombre"}
              onClick={toggleMode}
            >
              {mode === "dark" ? <Sun /> : <Moon />}
            </Button>
            <Menu label="Menu du compte" trigger={<MenuIcon />}>
              <MenuItem to="/club" icon={<Settings />}>
                Club
              </MenuItem>
              <MenuItem to="/profile" icon={<User />}>
                Profil
              </MenuItem>
              <MenuItem to="/nouveautes" icon={<Sparkles />}>
                Nouveautés
              </MenuItem>
              <MenuItem icon={<Flag />} onSelect={() => setFeedbackOpen(true)}>
                Signaler un bug
              </MenuItem>
              <MenuItem to="/confidentialite" icon={<ShieldCheck />}>
                Confidentialité
              </MenuItem>
              <MenuItem icon={<LogOut />} className="text-destructive [&_svg]:text-destructive" onSelect={logout}>
                Se déconnecter
              </MenuItem>
            </Menu>
          </nav>
        </div>
      </header>
      <main className="px-4 py-8 lg:px-6 xl:px-8" aria-busy={navigating}>
        <ReadonlySeasonBanner />
        <SeasonTransitionBanner />
        <CreditsBanner />
        <Outlet />
      </main>
      {/* P5-12 — la modale « quoi de neuf » se décide elle-même (rien si pas de
          note fraîche) ; montée une fois pour tout l'espace authentifié. */}
      <WhatsNewModal />
      {/* P5-6 — porte libre du canal de signalement (topic au choix, contexte léger). */}
      {feedbackOpen ? <FeedbackDialog variant="free" onClose={() => setFeedbackOpen(false)} /> : null}
    </div>
  );
}
