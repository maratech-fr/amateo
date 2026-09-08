# language: fr
Fonctionnalité: Une rencontre importée dit si elle est traitée
  Le gestionnaire importe ses rencontres FBI en plusieurs fois. Chaque rencontre
  doit dire, d'un coup d'œil, si elle est NOUVELLE (jamais examinée), DÉPHASÉE (la
  source diffère de ce qu'il a traité) ou TRAITÉE. Il tranche les écarts plus tard,
  sans re-déposer, et « valide » une ligne d'un geste. Une ligne absente d'un dépôt
  n'est jamais touchée (l'import est partiel par nature).

  Scénario: Du premier dépôt au traitement, en passant par le déphasage
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Quand je dépose un fichier FBI avec un match à domicile au gymnase « GYM BEHAT » à 15h30
    Alors la rencontre importée est « à traiter »
    Quand je place la rencontre dans le gymnase « GYM BEHAT » à 15h30
    Alors la rencontre est « traitée »
    Quand je re-dépose le même fichier
    Alors la rencontre est « validée ligue »
    Quand je re-dépose le fichier avec une date différente, sans trancher
    Alors la rencontre est « déphasée » et sa date d'origine est intacte
    Quand je tranche l'écart de date en adoptant la source
    Alors la rencontre est « à replacer » et de nouveau « traitée »
    Et une rencontre absente du dépôt reste intouchée
