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

  Scénario: Compléter les coachs ne trompe pas — ajouter et rattacher n'alerte pas, passer véhiculé oui
    Le club déclare ses coachs après coup : ajouter un coach neuf et le rattacher à une équipe ne
    doit pas afficher un « à régénérer » trompeur (rien de placé n'a changé). Mais renseigner qu'il
    est véhiculé change son barème de trajet, donc ce que le solveur placerait : là, le planning se
    dit périmé.
    Étant donné le club de démonstration, connecté, dont le planning de saison en vigueur n'est pas dit périmé
    Quand j'ajoute un coach au club et le rattache à une équipe
    Alors le planning en vigueur n'est pas dit périmé
    Quand je renseigne que ce coach est véhiculé
    Alors le planning en vigueur est dit périmé
