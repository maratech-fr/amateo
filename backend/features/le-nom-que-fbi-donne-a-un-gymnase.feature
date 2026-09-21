# language: fr
Fonctionnalité: Le nom que FBI donne à un gymnase
  FBI nomme souvent une salle autrement que l'appli. Quand ce nom est un alias CONFIRMÉ
  du gymnase où la rencontre est placée, ce n'est pas un écart : le chemin « placé » lit
  l'alias comme le chemin « non placé ». Et comme la source atteste alors date, heure et
  salle, la rencontre passe enfin en « validée ». L'égalité reste STRICTE sur l'identité :
  un alias qui désigne un AUTRE gymnase lève toujours l'écart. Enfin, quand le gestionnaire
  garde l'appli, le registre « à corriger dans FBI » lui propose la GRAPHIE BRUTE que la
  source atteste pour ce gymnase — pas l'alias stocké normalisé, illisible à recopier.

  Scénario: L'alias confirmé du gymnase placé n'est pas un écart et la rencontre est validée
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM ALIAS BEHAT » rattaché à l'alias « SALLE DU 8 MAI »
    Quand je dépose puis place un domicile dans « GYM ALIAS BEHAT » sous l'alias « Salle du 8 Mai »
    Et la ligue re-dépose exactement la même rencontre
    Alors aucun écart de salle n'est ouvert et la rencontre est validée

  Scénario: Un alias qui désigne un AUTRE gymnase lève toujours l'écart
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM ALIAS BEHAT » rattaché à l'alias « SALLE DU 8 MAI »
    Et un deuxième gymnase jetable « GYM AUTRE BEHAT » rattaché à l'alias « SALLE RAPHAEL DE BARROS »
    Quand je dépose puis place un domicile dans « GYM ALIAS BEHAT » sous l'alias « Salle du 8 Mai »
    Et la ligue re-dépose la rencontre au libellé « SALLE RAPHAEL DE BARROS »
    Alors un écart de salle est ouvert et la rencontre n'est pas validée

  Scénario: Le registre propose la graphie brute qu'une rencontre sœur atteste
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe jetable et un gymnase jetable « GYM ALIAS BEHAT » rattaché à l'alias « SALLE DU 8 MAI »
    Et une rencontre sœur atteste la graphie brute « Salle du 8 Mai » pour ce gymnase
    Quand je dépose puis place un domicile dans « GYM ALIAS BEHAT » sous le libellé du gymnase
    Et la ligue re-dépose la rencontre au libellé « SALLE INCONNUE » et je garde l'appli
    Alors le registre « à corriger dans FBI » propose de taper « Salle du 8 Mai »
