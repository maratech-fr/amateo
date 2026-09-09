# language: fr
Fonctionnalité: Un domicile importé retrouve son gymnase depuis le libellé FBI/FFBB
  Un match à domicile importé porte le libellé de salle de la fédération, mais pas de
  gymnase du club : il est invisible de la collision de gymnase et de la fermeture. En
  rattachant une fois le libellé au bon gymnase, le domicile déjà déposé le récupère, et
  tout dépôt suivant au même libellé est rattaché d'office — sans jamais être placé.

  Scénario: Rattacher un libellé rend le domicile visible de la fermeture du gymnase
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable pour les alias
    Quand je dépose un fichier FBI dont la salle est « GYMNASE BEHAT ALIAS »
    Alors la rencontre importée n'a pas de gymnase
    Quand je rattache le libellé « GYMNASE BEHAT ALIAS » au gymnase jetable
    Alors la rencontre a son gymnase et reste à placer
    Quand je re-dépose un autre match au même libellé
    Alors le nouveau match est rattaché d'office
    Quand le gymnase jetable est fermé à la date des matchs
    Alors un conflit « gymnase indisponible » vise la rencontre
