# language: fr
Fonctionnalité: Le placement des matchs honore les fenêtres d'accès et nomme l'impossible
  Quand le club place ses matchs, le solveur pose chaque rencontre à domicile
  dans une fenêtre d'accès de son gymnase, se laisse attirer sur le créneau
  idéal de l'équipe quand il en existe un, et NOMME la rencontre qu'il ne peut
  pas placer plutôt que de la laisser disparaître en silence. C'est le signal
  « demande ta dérogation tôt » rendu visible au gestionnaire.

  Scénario: Le samedi tombe dans sa fenêtre et sur son créneau idéal, le dimanche reste sans créneau
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et deux équipes et un gymnase jetables
    Et une fenêtre d'accès le samedi de 14h00 à 18h00 sur ce gymnase
    Et un créneau idéal le samedi à 15h30 sur ce gymnase pour la première équipe
    Et un match à domicile le samedi et un autre le dimanche
    Et plus aucune fenêtre d'accès le dimanche dans tout le club
    Quand je lance le placement des matchs
    Alors le match du samedi est placé par le solveur entre 14h00 et 16h15
    Et le match du samedi atterrit sur son créneau idéal, sur le gymnase à 15h30
    Et le match du dimanche reste sans créneau, faute de fenêtre d'accès ce jour-là

  Scénario: Deux domiciles à deux heures d'écart tiennent dans le même gymnase
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et deux équipes et un gymnase jetables
    Et une fenêtre d'accès le samedi de 14h00 à 18h00 sur ce gymnase
    Et le club n'offre aucune autre fenêtre d'accès le samedi
    Et deux matchs à domicile le même samedi, un par équipe
    Quand je lance le placement des matchs
    Alors les deux matchs du samedi sont posés par le solveur dans ce gymnase

  Scénario: Un match extérieur ne repousse plus le domicile du coach partagé — le solveur place, le radar signale
    Le placement automatique IGNORE désormais l'empreinte personne d'un match EXTÉRIEUR (« c'est la
    vie ; on gère après ») : le domicile du coach partagé se pose librement, sans être décalé par
    l'extérieur. C'est le radar de conflits qui signale ensuite le chevauchement de personne, à gérer
    à la main.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et deux équipes et un gymnase jetables
    Et une large fenêtre d'accès le samedi de 14h00 à 23h30 sur ce gymnase
    Et un entraîneur qui partage les deux équipes
    Et un match extérieur de la seconde équipe le samedi à 14h00, à long trajet aller-retour
    Et un match à domicile de la première équipe le samedi à placer
    Quand je lance le placement des matchs
    Alors le match à domicile est placé par le solveur, sans être bloqué par l'extérieur
    Et le radar signale le conflit de personne entre le domicile et l'extérieur du coach partagé

  Scénario: Placer un seul week-end ne déplace pas un match déjà posé sur un autre week-end
    « Placer ce week-end » ne résout QUE la semaine affichée : un match déjà posé par le solveur
    sur un AUTRE week-end est une ancre — sa salle reste protégée, son coup d'envoi ne bouge pas.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et deux équipes et un gymnase jetables
    Et une fenêtre d'accès le samedi de 14h00 à 18h00 sur ce gymnase
    Et un match à domicile à placer le week-end prochain
    Et un match déjà posé par le solveur sur un autre week-end, à 20h00
    Quand je lance le placement du seul week-end prochain
    Alors le match du week-end prochain est placé par le solveur
    Et le match de l'autre week-end n'a pas bougé, toujours à 20h00 et posé par le solveur

  Scénario: Un amical n'est jamais proposé au solveur et se place à la main hors créneau
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et deux équipes et un gymnase jetables
    Et une fenêtre d'accès le samedi de 14h00 à 18h00 sur ce gymnase
    Et un amical à domicile le samedi sur ce gymnase, sans créneau posé
    Quand je lance le placement des matchs
    Alors l'amical n'est jamais proposé au solveur et reste sans créneau
    Et je peux le placer à la main hors de la fenêtre d'accès match
