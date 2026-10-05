# language: fr
Fonctionnalité: Une fermeture de gymnase du calendrier s'applique aussi aux matchs
  Une fermeture de gymnase posée dans le calendrier ferme la salle AUX MATCHS autant
  qu'aux entraînements : le solveur ne pose aucun domicile dans un gymnase fermé à la
  date du match, le gestionnaire ne peut pas l'y placer à la main, et un match déjà posé
  avant la fermeture est signalé par le radar. C'est la promesse « ferme une fois, c'est
  fermé partout » rendue vraie côté matchs.

  Scénario: Le solveur ne pose jamais un domicile dans un gymnase fermé ce jour-là, il le place ailleurs
    Le créneau idéal de l'équipe attire le domicile sur le gymnase jetable (sans la fermeture
    il s'y poserait, à 15h30) ; une fermeture du calendrier couvrant ce samedi retire pourtant
    ce gymnase du domaine du solveur, qui pose alors le match dans un AUTRE gymnase du club,
    jamais dans le gymnase fermé. C'est « ferme une fois, c'est fermé partout » côté solveur.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et deux équipes et un gymnase jetables
    Et une fenêtre d'accès le samedi de 14h00 à 18h00 sur ce gymnase
    Et un créneau idéal le samedi à 15h30 sur ce gymnase pour la première équipe
    Et une fermeture du calendrier couvrant le samedi sur ce gymnase
    Et un match à domicile de la première équipe le samedi à placer
    Quand je lance le placement des matchs
    Alors le match du samedi est placé par le solveur, hors du gymnase fermé

  Scénario: Placer un match à la main dans un gymnase fermé est refusé
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et deux équipes et un gymnase jetables
    Et une fenêtre d'accès le samedi de 14h00 à 18h00 sur ce gymnase
    Et un match à domicile de la première équipe le samedi à placer
    Et une fermeture du calendrier couvrant le samedi sur ce gymnase
    Alors poser ce match à la main dans le gymnase fermé est refusé, avec un message de fermeture

  Scénario: Une fermeture posée après coup fait signaler par le radar le match déjà dans ce gymnase
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et deux équipes et un gymnase jetables
    Et une fenêtre d'accès le samedi de 14h00 à 18h00 sur ce gymnase
    Et un match à domicile de la première équipe le samedi, déjà posé dans ce gymnase à 15h00
    Et une fermeture du calendrier couvrant le samedi sur ce gymnase
    Alors le radar signale la fermeture de ce gymnase pour le match du samedi
