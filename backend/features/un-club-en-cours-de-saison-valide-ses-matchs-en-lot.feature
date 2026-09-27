# language: fr
Fonctionnalité: Un club qui démarre en cours de saison valide ses matchs en lot
  Un club met l'application en route alors que la saison est déjà lancée : il importe
  son fichier FBI, où des rencontres à domicile portent déjà leur date, leur heure et
  leur gymnase. La ligue fixe une ÉCHÉANCE DE SAISIE par championnat : à partir de cette
  date (jour inclus), toutes les dates du championnat peuvent être validées. Le
  gestionnaire n'a pas à les confirmer une à une : un geste chiffré et confirmé les fait
  toutes passer « validé ligue » d'un coup — mais seulement pour les championnats dont
  l'échéance est passée. Une rencontre sans heure n'est pas validée mais elle est nommée
  à traiter, et un championnat dont l'échéance n'est pas encore passée (nouvelle vague de
  matchs, dates provisoires) n'est proposé nulle part.

  Scénario: Échéance passée — d'un import daté au « validé ligue » en lot
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Et un accès match le samedi sur « GYM BEHAT »
    Quand je dépose un fichier FBI avec un domicile daté à « GYM BEHAT » et un domicile sans heure
    Et j'apparie le libellé « GYM BEHAT » au gymnase jetable
    Et l'échéance de saisie du championnat est déjà passée
    Alors 1 rencontre est validable « validé ligue »
    Et le domicile sans heure est nommé parmi les rencontres à traiter
    Quand je valide les placements côté ligue
    Alors 1 rencontre a basculé « validé ligue »
    Et la rencontre datée est « validé ligue »
    Et le domicile sans heure est resté « à placer »
    Et plus aucune rencontre n'est validable « validé ligue »

  Scénario: Échéance non passée — rien n'est proposé pour ce championnat
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Et un accès match le samedi sur « GYM BEHAT »
    Quand je dépose un fichier FBI avec un domicile daté à « GYM BEHAT » et un domicile sans heure
    Et j'apparie le libellé « GYM BEHAT » au gymnase jetable
    Et l'échéance de saisie du championnat n'est pas encore passée
    Alors aucune rencontre n'est validable « validé ligue »

  Scénario: Premier match joué, sans échéance — le championnat a démarré
    Un championnat sans échéance renseignée dont le premier match est déjà joué a DÉMARRÉ :
    il est proposé « validé ligue », sans qu'aucune échéance n'ait à être saisie.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Quand je dépose un fichier FBI avec un domicile daté dans le passé à « GYM BEHAT »
    Et j'apparie le libellé « GYM BEHAT » au gymnase jetable
    Alors 1 rencontre est validable « validé ligue » sans qu'aucune échéance ne soit renseignée

  Scénario: Un amical passé se valide tout seul et n'est jamais proposé au lot
    Un amical dont la date est passée n'a plus rien à confirmer : dès qu'un gestionnaire ouvre
    la vue, il bascule « validé ligue » tout seul — et il n'est JAMAIS proposé au lot des
    championnats (« validé ligue » n'a pas de sens pour un amical).
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Quand je dépose un fichier FBI avec un amical passé à « GYM BEHAT »
    Et un gestionnaire ouvre la vue « validé ligue »
    Alors l'amical passé est « validé ligue »
    Et aucune rencontre n'est validable « validé ligue »
