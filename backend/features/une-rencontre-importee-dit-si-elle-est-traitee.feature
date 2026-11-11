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
    Et un accès match le samedi sur « GYM BEHAT »
    Quand je dépose un fichier FBI avec un match à domicile au gymnase « GYM BEHAT » à 15h30
    Alors la rencontre importée est « à traiter »
    Quand je place la rencontre dans le gymnase « GYM BEHAT » à 15h30
    Alors la rencontre est « traitée »
    Quand je re-dépose le même fichier
    Alors la rencontre est « attestée FBI »
    Quand je re-dépose le fichier avec une date différente, sans trancher
    Alors la rencontre est « déphasée » et sa date d'origine est intacte
    Quand je tranche l'écart de date en adoptant la source
    Alors la rencontre a pris la date de FBI, reste « attestée FBI » dans son gymnase et de nouveau « traitée »
    Et une rencontre absente du dépôt reste intouchée

  Scénario: Championnat commencé, rencontre attestée : FBI la déplace sans la dé-placer
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Et un accès match le samedi sur « GYM BEHAT »
    Quand je dépose un fichier FBI avec un match à domicile au gymnase « GYM BEHAT » à 15h30
    Et je place la rencontre dans le gymnase « GYM BEHAT » à 15h30
    Et je re-dépose le même fichier
    Alors la rencontre est « attestée FBI »
    Quand le championnat a commencé : un match déjà joué de la même division est déposé
    Et je re-dépose le fichier avec une date différente, sans trancher
    Alors la rencontre a pris la date de FBI, reste « attestée FBI » dans son gymnase et de nouveau « traitée »

  Scénario: Championnat commencé, rencontre seulement placée : l'écart reste à trancher
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Et un accès match le samedi sur « GYM BEHAT »
    Quand je dépose un fichier FBI avec un match à domicile au gymnase « GYM BEHAT » à 15h30
    Et je place la rencontre dans le gymnase « GYM BEHAT » à 15h30
    Et le championnat a commencé : un match déjà joué de la même division est déposé
    Et je re-dépose le fichier avec une date différente, sans trancher
    Alors la rencontre est « déphasée » et sa date d'origine est intacte
    Et la rencontre est toujours placée dans le gymnase « GYM BEHAT »

  Scénario: Un export de plus de 24 heures est refusé en nommant sa date
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Quand je dépose un export FBI créé il y a plus de 24 heures
    Alors le dépôt est refusé et le message dit la date d'export du fichier
    Et aucune rencontre n'est importée

  Scénario: Un match à l'extérieur naît déjà traité
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Quand je dépose un fichier FBI avec un match à l'extérieur
    Alors la rencontre importée est « traitée »

  Scénario: Un match déjà passé naît déjà traité
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Quand je dépose un fichier FBI avec un match à domicile déjà passé
    Alors la rencontre importée est « traitée »

  Scénario: Un extérieur resté « à traiter » d'un ancien dépôt est rattrapé au re-dépôt
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Quand je dépose un fichier FBI avec un match à l'extérieur
    Et je force la rencontre importée à « à traiter » en base
    Et je re-dépose le même extérieur
    Alors la rencontre est « traitée »

  Scénario: Un écart sur un extérieur est pris en compte, sans arbitrage
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Quand je dépose un fichier FBI avec un match à l'extérieur
    Et je re-dépose l'extérieur à une autre date, sans trancher
    Alors la rencontre est « traitée » et porte une alerte de déplacement
    Quand je valide la rencontre d'un geste
    Alors la rencontre est « traitée » et l'alerte de déplacement a disparu

  Scénario: Les changements à l'extérieur se marquent vus d'un seul geste
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Quand je dépose un fichier FBI avec un match à l'extérieur
    Et je re-dépose l'extérieur à une autre date, sans trancher
    Alors la rencontre est « traitée » et porte une alerte de déplacement
    Quand je marque comme vues toutes les rencontres à l'extérieur
    Alors la rencontre est « traitée » et l'alerte de déplacement a disparu

  Scénario: Un extérieur qui garde un écart hérité de son passé de domicile est laissé de côté
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Et un accès match le samedi sur « GYM BEHAT »
    Quand je dépose un fichier FBI avec un match à domicile au gymnase « GYM BEHAT » à 15h30
    Et je place la rencontre dans le gymnase « GYM BEHAT » à 15h30
    Et je re-dépose le fichier avec une date différente, sans trancher
    Alors la rencontre est « déphasée » et sa date d'origine est intacte
    Quand je re-dépose le match devenu extérieur à sa date d'origine
    Et je marque comme vues toutes les rencontres à l'extérieur
    Alors la rencontre est laissée de côté, nommée, et son écart de date reste à trancher

  Scénario: Un écart pris en compte s'acquitte avec toute l'équipe
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Quand je dépose un fichier FBI avec un match à l'extérieur
    Et je re-dépose l'extérieur à une autre date, sans trancher
    Alors la rencontre est « traitée » et porte une alerte de déplacement
    Quand je marque comme vues toutes les rencontres de l'équipe
    Alors la rencontre est « traitée » et l'alerte de déplacement a disparu

  Scénario: Le 2ᵉ tour réutilise un numéro — l'historique de la 1ʳᵉ phase est intact
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Et un accès match le samedi sur « GYM BEHAT »
    Quand je dépose un fichier FBI avec un match à domicile au gymnase « GYM BEHAT » à 15h30
    Et je place la rencontre dans le gymnase « GYM BEHAT » à 15h30
    Et je dépose un second championnat qui réutilise le même numéro de match
    Alors les deux rencontres coexistent et la première garde sa date, son adversaire et son placement

  Scénario: Un adversaire différent sous le même numéro rétablit la rencontre d'après FBI
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Quand je dépose un fichier FBI avec un match à domicile déjà passé
    Alors la rencontre est « Déjà dans FBI »
    Quand je re-dépose ce match passé contre un autre adversaire
    Alors le rapport d'import annonce 1 rencontre rétablie d'après FBI
    Et la rencontre porte l'adversaire du fichier et est de nouveau « Déjà dans FBI »
    Quand je re-dépose le même fichier rétabli
    Alors le rapport d'import n'annonce aucune rencontre rétablie

  Scénario: Le suffixe FFBB « (n) » est retiré du libellé de l'adversaire
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Quand je dépose un fichier FBI dont l'adversaire porte un suffixe numéroté
    Alors le libellé de l'adversaire importé est sans suffixe
