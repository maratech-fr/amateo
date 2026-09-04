# language: fr
Fonctionnalité: Le placement des matchs honore les fenêtres d'accès et nomme l'impossible
  Quand le club place ses matchs, le solveur pose chaque rencontre à domicile
  dans une fenêtre d'accès de son gymnase, se laisse attirer sur un créneau de
  rotation partagé quand il en existe un, et NOMME la rencontre qu'il ne peut
  pas placer plutôt que de la laisser disparaître en silence. C'est le signal
  « demande ta dérogation tôt » rendu visible au gestionnaire.

  Scénario: Le samedi tombe dans sa fenêtre et sur la rotation, le dimanche reste sans créneau
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et deux équipes et un gymnase jetables
    Et une fenêtre d'accès le samedi de 14h00 à 18h00 sur ce gymnase
    Et un créneau de rotation partagé le samedi à 15h30 réunissant les deux équipes
    Et un match à domicile le samedi et un autre le dimanche
    Et plus aucune fenêtre d'accès le dimanche dans tout le club
    Quand je lance le placement des matchs
    Alors le match du samedi est placé par le solveur entre 14h30 et 16h15
    Et le match du samedi atterrit sur le créneau de rotation partagé, sur le gymnase à 15h30
    Et le match du dimanche reste sans créneau, faute de fenêtre d'accès ce jour-là
