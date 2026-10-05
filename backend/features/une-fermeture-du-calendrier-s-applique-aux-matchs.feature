# language: fr
Fonctionnalité: Une fermeture de gymnase du calendrier s'applique aussi aux matchs
  Une fermeture de gymnase posée dans le calendrier ferme la salle AUX MATCHS autant
  qu'aux entraînements : le solveur ne pose aucun domicile dans un gymnase fermé à la
  date du match, le gestionnaire ne peut pas l'y placer à la main, et un match déjà posé
  avant la fermeture est signalé par le radar. C'est la promesse « ferme une fois, c'est
  fermé partout » rendue vraie côté matchs.

  Scénario: Le solveur laisse sans créneau un domicile dont le seul gymnase est fermé ce jour-là
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et deux équipes et un gymnase jetables
    Et une fenêtre d'accès le samedi de 14h00 à 18h00 sur ce gymnase
    Et le club n'offre aucune autre fenêtre d'accès le samedi
    Et une fermeture du calendrier couvrant le samedi sur ce gymnase
    Et un match à domicile de la première équipe le samedi à placer
    Quand je lance le placement des matchs
    Alors le match du samedi reste sans créneau, faute d'un gymnase fermé par le calendrier

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
