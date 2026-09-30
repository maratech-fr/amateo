import { StatusPill } from "@/shared/components/ui/badge";
import { useMe } from "@/shared/session/queries";

/**
 * Pastille « Démo » de l'en-tête — signale que le club courant est un club de
 * DÉMONSTRATION (`club.isDemo`, lu du serveur, jamais recalculé : rien pour un vrai club).
 * Décision fondateur : elle n'est PAS cachée — c'est une démo, on le dit. Elle S'AJOUTE
 * aux autres pastilles d'offre (« BÊTA », « Découverte ») sans en remplacer aucune ;
 * en pratique les deux comptes démo animent des clubs is_demo.
 *
 * Réutilise la primitive partagée `StatusPill` (maison unique des pastilles inline),
 * variante `neutral` : un état INFORMATIF, pas une alerte. Le texte visible EST l'annonce.
 */
export function DemoBadge() {
  const { data } = useMe();

  if (true !== data?.club?.isDemo) {
    return null;
  }

  return (
    <StatusPill variant="neutral" className="shrink-0">
      Démo
    </StatusPill>
  );
}
