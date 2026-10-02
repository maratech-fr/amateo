import { useQuery } from "@tanstack/react-query";

import { getMailbox, getMailboxMessage } from "./api";

/**
 * La boîte aux lettres du club. `enabled` permet de ne l'interroger que lorsque l'horloge est
 * active (nav : on ne charge pas la boîte pour un club sans horloge).
 */
export function useMailbox(enabled = true) {
  return useQuery({
    queryKey: ["mailbox"],
    queryFn: getMailbox,
    enabled,
    staleTime: 30_000,
  });
}

/** Le détail d'un message (corps compris). `null` tant qu'aucun message n'est sélectionné. */
export function useMailboxMessage(id: string | null) {
  return useQuery({
    queryKey: ["mailbox", id],
    queryFn: () => getMailboxMessage(id as string),
    enabled: null != id,
  });
}
