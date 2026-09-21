# language: fr
Fonctionnalité: Un club qui démarre en cours de saison valide ses matchs en lot
  Un club met l'application en route alors que la saison est déjà lancée : il importe
  son fichier FBI, où des rencontres à domicile portent déjà leur date, leur heure et
  leur gymnase — la fédération les connaît. Le gestionnaire n'a pas à les confirmer
  une à une : un geste chiffré et confirmé les fait toutes passer « validé ligue »
  d'un coup. Une rencontre sans heure n'est pas concernée, et rejouer le geste ne
  bascule plus rien.

  Scénario: D'un import daté au « validé ligue » en lot
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM BEHAT »
    Et un accès match le samedi sur « GYM BEHAT »
    Quand je dépose un fichier FBI avec un domicile daté à « GYM BEHAT » et un domicile sans heure
    Et j'apparie le libellé « GYM BEHAT » au gymnase jetable
    Alors 1 rencontre est validable « validé ligue »
    Quand je valide les placements côté ligue
    Alors 1 rencontre a basculé « validé ligue »
    Et la rencontre datée est « validé ligue »
    Et le domicile sans heure est resté « à placer »
    Et plus aucune rencontre n'est validable « validé ligue »
