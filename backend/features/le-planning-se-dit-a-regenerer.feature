# language: fr
Fonctionnalité: Le planning se dit à régénérer quand une contrainte change
  Modifier une donnée du club après coup ne détruit pas le planning déjà généré :
  il n'est pas faux, il est PÉRIMÉ. L'application le dit — elle marque le planning
  à régénérer sans en effacer un seul créneau — pour que le gestionnaire régénère
  et sache, plutôt que de lire un planning qui ne décrit plus les données courantes.

  Scénario: Ajouter une contrainte marque le planning en vigueur, sans le détruire
    Étant donné le club de démonstration, connecté, dont le planning de saison en vigueur n'est pas marqué
    Quand j'ajoute une contrainte au club
    Alors le planning en vigueur est marqué à régénérer
    Et le planning en vigueur est intact, mêmes créneaux et même statut

  Scénario: Le cockpit le sait : le plan sert lui-même sa péremption
    Étant donné le club de démonstration, connecté, dont le planning de saison en vigueur n'est pas marqué
    Quand j'ajoute une contrainte au club
    Alors le cockpit le sait : le plan de saison sert lui-même sa péremption
