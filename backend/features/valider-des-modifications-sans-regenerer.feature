# language: fr
Fonctionnalité: Valider des modifications sans régénérer
  Un changement fait hors du planning, comme le coach principal d'une équipe, se fait
  valoir sur le planning en vigueur sans régénérer : aucune séance ne bouge, aucune
  version ne naît, les séances que tenait l'ancien coach principal sont tenues par le
  nouveau. Avant de confirmer, le système dit ce que le changement casse sur le
  planning en vigueur, sans bloquer, et quels plannings de période à venir seront
  supprimés, puisque du froid change.

  Dans le petit club, Eden entraîne les U13F1, réservés le lundi à 18:00 au Gymnase
  Nord ; les U13M1, tenus par Camille, sont réservés à la même heure au Gymnase Centre.

  Scénario: Valider ces modifications applique le nouveau coach principal sans déplacer aucune séance
    Étant donné un petit club dont le planning de saison est en vigueur, Eden coach principal des U13F1
    Et un planning de période à venir
    Et le coach principal des U13M1 confié à Eden, sans valider
    Quand je demande ce que change « Valider ces modifications »
    Alors l'annonce nomme le planning de période à venir, qui sera supprimé
    Quand je valide ces modifications
    Alors les séances du planning en vigueur sont à la même place, et aucune version n'est née
    Et les séances des U13M1 sont tenues par Eden, que les conflits du planning en vigueur comptent
    Et le planning de période à venir a disparu
    Et rien n'attend plus la validation
    Quand je retravaille le planning de saison
    Alors la copie de travail est à jour

  Plan du scénario: Le contrôle dit les incohérences avec le planning en vigueur, et laisse valider
    L'annonce dit aussi qui tiendra les séances d'une équipe dont le coach principal change, ou que plus personne ne les tient.
    Étant donné un petit club dont le planning de saison est en vigueur, Eden coach principal des U13F1
    Et <modification>, sans valider
    Quand je demande ce que change « Valider ces modifications »
    Alors le contrôle annonce <annonce>
    Et l'annonce <coach>
    Et la validation reste possible

    Exemples:
      | modification                                         | annonce                                                                            | coach                                                          |
      | le coach principal des U13M1 confié à un coach libre | aucune incohérence                                                                 | dit que les séances des U13M1 seront tenues par le coach libre |
      | le coach principal des U13M1 confié à Eden           | une incohérence sur la séance des U13M1 au même moment qu'Eden, qui nomme sa règle | dit que les séances des U13M1 seront tenues par Eden           |
      | le coach principal des U13M1 retiré                  | aucune incohérence                                                                 | dit que les U13M1 n'auront plus de coach principal             |
      | les U13M1 passés à 3 séances                         | 1 séance manquante pour les U13M1                                                  | ne nomme aucun changement de coach                             |
