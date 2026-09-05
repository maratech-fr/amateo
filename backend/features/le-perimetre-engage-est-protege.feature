# language: fr
Fonctionnalité: Une équipe engagée en compétition est protégée
  Dès qu'une équipe a des matchs, elle est inscrite auprès de la fédération : on
  ne la supprime plus (ses matchs partiraient avec elle) et on ne change plus son
  niveau (c'est sous ce niveau qu'elle est inscrite). Une équipe qui ne joue pas
  reste une ligne de travail ordinaire, librement supprimable.

  Scénario: Une équipe engagée ne peut pas être supprimée
    Étant donné le club de démonstration et son gestionnaire connecté
    Et une équipe jetable engagée en compétition
    Quand je tente de supprimer cette équipe
    Alors la suppression est refusée parce que l'équipe joue

  Scénario: Une équipe engagée ne peut pas changer de niveau
    Étant donné le club de démonstration et son gestionnaire connecté
    Et une équipe jetable engagée en compétition
    Quand je tente de changer le niveau de cette équipe
    Alors le changement de niveau est refusé parce que l'équipe joue

  Scénario: Une équipe qui ne joue pas se supprime librement
    Étant donné le club de démonstration et son gestionnaire connecté
    Et une équipe jetable qui ne joue aucun match
    Quand je tente de supprimer cette équipe
    Alors la suppression est acceptée
