# language: fr
Fonctionnalité: Charger une version ramène son état
  Le gestionnaire a généré une première version de son planning de saison, puis il a retravaillé ses
  réglages et généré une seconde version. « Charger » la première remet les réglages du planning tels
  qu'ils étaient à sa génération, et son planning s'affiche. Aucune équipe, aucun gymnase, aucun coach
  n'est créé ni supprimé ; leurs noms et ce qui vit hors du planning ne changent pas. Sur un petit club
  réaliste (palier 60 s).

  Scénario: Charger la version 1 remet la grille, les réservations, les règles et les séances par équipe
    Étant donné un planning de saison en version 2
    Quand je charge la version 1
    Alors la grille, les réservations et les règles sont celles de la version 1
    Et chaque équipe a les séances de la version 1

  Scénario: Charger la version 1 remet tout le froid du planning de saison, sans toucher aux noms ni à ce qui vit hors du planning
    Étant donné un planning de saison généré avec une passerelle, une règle, un groupe mutualisé, une règle de bien-être, une règle de trajet, un coach principal, un coach-joueur et un gymnase imposé
    Et une version 2 générée après avoir changé chacun d'eux
    Et une durée de match modifiée, une catégorie créée, une équipe renommée, un adjoint ajouté et un groupe renommé après la version 1
    Quand je charge la version 1
    Alors les passerelles, les règles, les groupes mutualisés, le bien-être, la règle de trajet, les coachs principaux, les coachs-joueurs et les gymnases imposés sont ceux de la version 1
    Et la durée de match, les catégories, les noms, les adjoints et les libellés sont inchangés
    Et aucune équipe, aucun gymnase, aucun coach ni aucune catégorie n'est créé ni supprimé
    Et les séances de la version 1 retrouvent ce qui les tient :
      | séance                |
      | réservée              |
      | libre                 |
      | d'un groupe mutualisé |
