# language: fr
Fonctionnalité: Les conflits d'un match disent la vérité
  Le radar de conflits d'un match lit le planning EFFECTIF à la date du match (ADR-0002). Quand
  une fermeture racine est découpée et que son enfant « milieu » pointe une version portant un
  entraînement, c'est cet entraînement qui compte — la période la plus ÉTROITE gagne, jamais un
  repli silencieux sur la racine sans plan (faux négatif P4-188). Et les bornes du conflit sont
  l'heure murale du club, sans décalage horaire (P4-191).

  Scénario: Le conflit d'entraînement d'un enfant « milieu » remonte, en heure murale
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et une fermeture racine découpée en milieu et fin, la racine et le milieu partageant leur date de départ
    Et le plan du milieu pointe une version portant un entraînement de l'équipe le jeudi à 20h45 sur ce gymnase
    Et un match à domicile de l'équipe ce jeudi, coup d'envoi à 20h45, sur ce gymnase
    Quand je demande les conflits des matchs
    Alors un conflit d'entraînement porte ce match et sa borne de début est l'heure murale sans décalage
