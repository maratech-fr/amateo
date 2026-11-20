# language: fr
Fonctionnalité: Un membre lit, le gestionnaire écrit
  Un membre du club sans rôle de gestion (un coach, un bénévole) consulte le planning en vigueur
  et les matchs, et repart avec le planning en tableur pour le gymnase. Il ne modifie rien : chaque
  geste d'écriture lui est refusé avec son motif, ce que prouve
  `un-club-ne-voit-jamais-un-autre-club.feature` (scénario du membre sans rôle de gestion).

  Scénario: Un membre consulte le planning et les matchs, et exporte le planning
    Étant donné le club de démonstration, son planning de saison en vigueur, et l'un de ses membres sans rôle de gestion
    Quand ce membre consulte les plannings du club
    Alors il y trouve le planning en vigueur, avec ses séances
    Quand ce membre consulte les matchs du club
    Alors la liste des matchs lui est servie
    Quand ce membre exporte le planning en vigueur en tableur
    Alors il repart avec un classeur Excel non vide
