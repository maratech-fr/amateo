# language: fr
Fonctionnalité: L'abonnement se règle par saison
  L'offre d'un club se règle saison par saison, par le geste « Offre » du support. L'offre d'une
  saison ne dépend que de ce qui a été réglé pour elle : régler la saison suivante ne change rien
  à la saison en cours, et repasser le club en Découverte ne retire pas la saison suivante déjà
  réglée. Un club que le support n'a jamais réglé est en Découverte. Un club de démonstration n'est
  jamais bridé. Le geste « Marquer la saison suivante payée » n'existe plus : le geste « Offre »
  dit l'offre et la saison en un seul geste.

  Plan du scénario: L'offre de la saison en cours ne dépend que de ce qui a été réglé pour elle
    Étant donné un club inscrit par l'API, avec un planning de saison en brouillon
    Et <le geste du support>
    Quand j'exporte le planning en tableur
    Alors sur la saison en cours, mon offre est « <offre> » et <crédits>

    Exemples:
      | le geste du support                                            | offre       | crédits                                    |
      | aucun geste du support                                         | Découverte  | j'ai utilisé 1 crédit sur 10               |
      | le support règle la bêta pour la saison en cours               | Bêta        | aucun crédit n'est décompté, sans plafond  |
      | le support règle la bêta pour la saison suivante seulement     | Découverte  | j'ai utilisé 1 crédit sur 10               |
      | le club passe en démonstration, sans aucun geste du support    | Découverte  | aucun crédit n'est décompté, sans plafond  |

  Scénario: Le support renouvelle par le geste « Offre », et repasser en Découverte ne retire pas la saison suivante
    Étant donné un club inscrit par l'API, avec un planning de saison en brouillon
    Et le support règle la bêta pour la saison en cours
    Et le support règle la bêta pour la saison suivante seulement
    Quand le support repasse le club en Découverte
    Et j'exporte le planning en tableur
    Alors sur la saison en cours, mon offre est « Découverte » et j'ai utilisé 1 crédit sur 10
    Quand nous sommes le 1er mai de la saison en cours
    Et je prépare la saison suivante
    Alors la saison suivante naît
    Et sur la saison suivante, mon offre est « Bêta », sans plafond de crédits
    Et le support n'a plus de geste « Marquer la saison suivante payée »
