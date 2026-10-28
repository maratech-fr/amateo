import { Flag, LogOut, Menu as MenuIcon, Moon, Settings, Sparkles, Sun, User, ShieldCheck } from "lucide-react";
import { useId, useState } from "react";
import { NavLink, Outlet, useNavigation } from "react-router";

import { useLogout } from "@/features/auth/queries";
import { MailboxNavItem } from "@/features/mailbox/MailboxNavItem";
import { useMe } from "@/shared/session/queries";
import { FeedbackDialog } from "@/shared/feedback/FeedbackDialog";
import { WhatsNewModal } from "@/features/release-notes/WhatsNewModal";
import { BrandIcon } from "@/shared/components/ui/brand-icon";
import { Button } from "@/shared/components/ui/button";
import { buttonVariants } from "@/shared/components/ui/button-variants";
import { Menu, MenuItem } from "@/shared/components/ui/menu";
import { CreditBadge } from "@/shared/credits/CreditBadge";
import { CreditsBanner } from "@/shared/credits/CreditsBanner";
import { DeploymentNoticeBanner } from "@/shared/deployment/DeploymentNoticeBanner";
import { useApplyClubTheme } from "@/shared/hooks/useApplyClubTheme";
import { useApplySentryIdentity } from "@/shared/hooks/useApplySentryIdentity";
import { useApplySimulatedClock } from "@/shared/hooks/useApplySimulatedClock";
import { PRODUCT_NAME } from "@/shared/lib/product";
import { cn } from "@/shared/lib/utils";
import { useThemeStore } from "@/shared/stores/themeStore";

import { BetaBadge } from "./BetaBadge";
import { DemoBadge } from "./DemoBadge";
import { DemoClockWidget } from "./DemoClockWidget";
import { ReadonlySeasonBanner } from "./ReadonlySeasonBanner";
import { DevClock } from "./DevClock";
import { SeasonSelector } from "./SeasonSelector";
import { SeasonTransitionBanner } from "./SeasonTransitionBanner";

/**
 * M13 (retours Nico, BCCL 2026-10-27) — « Matchs » était trop discret, caché dans la grappe de
 * droite (Nico ne savait pas que le module existait) : il SORT de cette grappe, prend l'ASPECT d'un
 * bouton SECONDAIRE (variante `outline` du système, aucune couleur neuve) et se place SEUL au CENTRE
 * du bandeau pour ressortir, sans monter au niveau visuel du sélecteur de saison (qui, lui, est
 * plein). Il GARDE son rôle de lien (NavLink → `<a>`) et son état actif (fond accent sur /matchs).
 *
 * Mise en page (passe `ui-ux-pro-max`, option B tranchée) : flex `justify-between` sous `lg`
 * (téléphone — pas de place pour centrer, le contenu garde sa largeur naturelle, la marque tronque
 * en premier, zéro débordement à 360 px) ; grille 3 colonnes `minmax(0,1fr) auto minmax(0,1fr)` à
 * partir de `lg` (1024-1920 px) — centrage géométrique réel, les colonnes latérales se rétractent
 * sans jamais forcer de scroll horizontal, le centre reste pile au milieu quelles que soient les
 * largeurs inégales gauche/droite. « Matchs » reste dans un wrapper SIMPLE, pas un 2ᵉ repère de
 * navigation (`<nav>`) : un lien unique n'est pas un repère, et deux repères sans nom distinct
 * nuiraient au lecteur d'écran — la grappe de droite reste l'unique `<nav>`.
 */
function NavItem({ to, children }: { to: string; children: string }) {
  return (
    <NavLink
      to={to}
      end
      className={({ isActive }) =>
        cn(buttonVariants({ variant: "outline", size: "sm" }), isActive ? "bg-accent text-accent-foreground hover:enabled:bg-accent-hover" : "text-muted-foreground")
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
  useApplySimulatedClock();
  useApplySentryIdentity();
  const mode = useThemeStore((state) => state.mode);
  const toggleMode = useThemeStore((state) => state.toggleMode);
  const [feedbackOpen, setFeedbackOpen] = useState(false);
  const matchsLockReasonId = useId();

  return (
    <div className="min-h-screen text-foreground">
      {/* En-tête OPAQUE (`bg-background`) posé sur le fond d'écran commun (P5-16) : le fond ne vit
          que dans les zones vides, jamais sous l'en-tête ni les cartes. La racine, elle, laisse
          passer le fond du `body`. */}
      <header className="border-b border-border bg-background">
        <div className="flex h-14 items-center justify-between gap-2 px-4 lg:grid lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] lg:gap-4 lg:px-6 xl:px-8">
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
              // PAS de `min-w-0` sur le lien : sinon les pastilles `shrink-0` de la grappe l'écrasent
              // à largeur NULLE et le lien devient HIDDEN à 360 px (régression e2e #1022). Sans
              // `min-w-0`, le plancher du lien est son contenu de largeur minimale = l'icône
              // (`shrink-0`), toujours visible ; c'est le SPAN qui porte `min-w-0` pour tronquer.
              className="flex items-center gap-2 rounded-md transition-opacity hover:opacity-80"
            >
              <BrandIcon className="size-6 shrink-0" />
              {data?.club?.logoUrl ? <img src={data.club.logoUrl} alt="" className="size-6 shrink-0 rounded-full object-cover" /> : null}
              <span className="hidden min-w-0 truncate text-sm font-semibold sm:inline">{data?.club?.name ?? PRODUCT_NAME}</span>
            </NavLink>
            {/* Pastilles d'OFFRE, juste après la marque/club : « BÊTA » (offre bêta) et
                « Découverte · N crédits » (offre Découverte bridée). Mutuellement exclusives en
                pratique — chacune ne se montre que pour son offre ; rien en payant/démo. */}
            <BetaBadge onReport={() => setFeedbackOpen(true)} />
            <CreditBadge />
            {/* Pastille « Démo » : s'ajoute aux deux précédentes pour un club is_demo, rien sinon. */}
            <DemoBadge />
            {/* Widget d'horloge du compte démo (prod) : pose/relâche la date simulée du club.
                Décide lui-même (null pour un vrai club). */}
            <DemoClockWidget />
            {import.meta.env.DEV ? <DevClock /> : null}
          </div>
          {/* M13 — « Matchs » SEUL au CENTRE (colonne auto de la grille à partir de `lg`), hors de
              la grappe de droite, dans un wrapper SIMPLE (pas un 2ᵉ repère de navigation). Matches
              stay locked until the season's plan points at a version — same condition the server
              enforces (SocleGuard). */}
          <div className="flex items-center">
            {null != data?.seasonPlan?.chosenScheduleId ? (
              <NavItem to="/matchs">Matchs</NavItem>
            ) : (
              // Désactivé mais DÉCOUVRABLE (A11Y-30) : focalisable (`tabIndex=0`), la raison
              // annoncée via `aria-describedby` vers un `<span sr-only>` frère — un `<span>` muet
              // avec seulement un `title` reste invisible au clavier/lecteur d'écran.
              <>
                <span
                  role="link"
                  aria-disabled="true"
                  tabIndex={0}
                  aria-describedby={matchsLockReasonId}
                  // M13 — même aspect de bouton secondaire (outline) que l'état débloqué, mais éteint
                  // (opacité réduite, curseur interdit) ; l'invariant A11Y-30 (focalisable + raison
                  // annoncée) est préservé.
                  className={cn(buttonVariants({ variant: "outline", size: "sm" }), "cursor-not-allowed text-muted-foreground opacity-50")}
                  title="Validez le planning de saison pour débloquer les matchs"
                >
                  Matchs
                </span>
                <span id={matchsLockReasonId} className="sr-only">
                  Validez le planning de saison pour débloquer les matchs
                </span>
              </>
            )}
          </div>
          <nav className="flex items-center justify-end gap-1">
            <SeasonSelector />
            {/* « Boîte aux lettres » : visible seulement si le club vit à une horloge simulée
                (P4-16), avec le compteur des e-mails interceptés. Décide elle-même (null sinon). */}
            <MailboxNavItem />
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
        <DeploymentNoticeBanner />
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
