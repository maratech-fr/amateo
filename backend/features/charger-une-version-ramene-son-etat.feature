# language: fr
Fonctionnalité: Charger une version ramène son état
  Le gestionnaire a généré une première version de son planning de saison, puis il a retravaillé ses
  réglages : un créneau ouvert le vendredi et réservé à une équipe, une réservation retirée, une règle
  changée, une autre ajoutée, une séance de plus pour une équipe. Il a généré une seconde version.
  « Charger » la première remet la grille, les réservations, les règles et les séances de chaque équipe
  telles qu'elles étaient à sa génération. Sur un petit club réaliste (palier 60 s).

  Scénario: Charger la version 1 remet la grille, les réservations, les règles et les séances par équipe
    Étant donné un planning de saison en version 2
    Quand je charge la version 1
    Alors la grille, les réservations et les règles sont celles de la version 1
    Et chaque équipe a les séances de la version 1
