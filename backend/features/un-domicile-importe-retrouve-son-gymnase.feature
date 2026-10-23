# language: fr
Fonctionnalité: Pas de salle appariée, pas d'import — ou alors en terrain neutre
  Un match à domicile importé doit porter un gymnase du club AVANT d'entrer. Au dépôt, le
  gestionnaire apparie la salle du fichier à l'un de ses gymnases : le domicile naît rattaché
  (jamais placé), visible de la collision de gymnase et de la fermeture. Une salle laissée sans
  gymnase fait REFUSER le dépôt — rien n'entre à moitié. Seul le terrain neutre (« pas chez
  nous », coupe ou playoff hors de nos murs) entre sans gymnase : il reste un domicile, déjà
  traité, hors de la grille et du placement.

  Scénario: Apparier la salle au dépôt rattache le domicile sans le placer, visible de la fermeture
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable pour les alias
    Quand je dépose un fichier FBI en appariant la salle au gymnase jetable
    Alors la rencontre a son gymnase et reste à placer
    Quand le gymnase jetable est fermé à la date des matchs
    Alors un conflit « gymnase indisponible » vise la rencontre

  Scénario: Une salle laissée sans gymnase fait refuser le dépôt
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable pour les alias
    Quand je dépose un fichier FBI sans apparier sa salle
    Alors le dépôt est refusé et aucune rencontre n'est créée

  Scénario: Un terrain neutre entre sans gymnase, à domicile et déjà traité
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable pour les alias
    Quand je dépose un fichier FBI en déclarant sa salle « terrain neutre »
    Alors la rencontre est à domicile, sans gymnase, en terrain neutre et déjà traitée

  Scénario: Ré-affecter un libellé rattaché au mauvais gymnase corrige les non placés sans défaire un placement
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable pour les alias
    Et un deuxième gymnase jetable pour la ré-affectation
    Quand je dépose un fichier FBI en appariant la salle au gymnase jetable
    Et je re-dépose un autre match au même libellé, puis je le place sur le premier gymnase
    Et je ré-affecte le libellé « GYMNASE BEHAT ALIAS » au deuxième gymnase jetable
    Alors le domicile non placé bascule sur le deuxième gymnase
    Et le témoin déjà placé garde le premier gymnase
    Et l'alias a changé de porteur pour le deuxième gymnase
