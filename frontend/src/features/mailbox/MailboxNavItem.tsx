import { NavLink } from "react-router";

import { StatusPill } from "@/shared/components/ui/badge";
import { useMe } from "@/shared/session/queries";
import { cn } from "@/shared/lib/utils";

import { useMailbox } from "./queries";

/**
 * Entrée de nav « Boîte aux lettres » (P4-16), dans la barre du haut comme « Matchs ». Visible
 * UNIQUEMENT quand le club vit à une horloge simulée (`me.club.simulatedToday`) — un club réel
 * ne la voit jamais. Porte un compteur (nombre de messages interceptés).
 *
 * Le compteur vient du SERVEUR (la liste), jamais recalculé ; la requête n'est lancée que lorsque
 * l'entrée est visible (club à horloge).
 */
export function MailboxNavItem() {
  const { data: me } = useMe();
  const hasClock = null != me?.club?.simulatedToday;
  const { data } = useMailbox(hasClock);

  if (!hasClock) {
    return null;
  }

  const count = data?.count ?? 0;

  return (
    <NavLink
      to="/boite-aux-lettres"
      end
      className={({ isActive }) =>
        cn(
          "flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm transition-colors",
          isActive ? "bg-accent text-accent-foreground" : "text-muted-foreground hover:text-foreground",
        )
      }
    >
      Boîte aux lettres
      {count > 0 ? <StatusPill variant="accent-solid">{count}</StatusPill> : null}
    </NavLink>
  );
}
