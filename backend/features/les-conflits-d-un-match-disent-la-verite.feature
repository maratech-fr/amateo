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

  Scénario: Un amical posé un week-end de match est signalé, jamais bloqué (P4-193)
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et une rencontre de championnat le samedi et un amical placé le dimanche du même week-end
    Quand je demande les conflits des matchs
    Alors le radar signale l'amical sur un créneau de match, pour cause de week-end de match

  Scénario: Une rencontre de coupe hors fenêtre de ligue est un vrai match, jamais un amical (P4-194)
    Une coupe PORTE une compétition (elle n'est jamais un amical à competitionId null) : le radar la
    soumet à l'enveloppe de ligue comme un championnat — hors de la fenêtre autorisée elle crie une
    violation de fenêtre —, et ne la signale JAMAIS comme un amical posé sur un créneau de match.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et une fenêtre de ligue étroite le samedi matin cadre cette équipe
    Et une rencontre de coupe à domicile ce samedi, coup d'envoi le soir hors de la fenêtre
    Quand je demande les conflits des matchs
    Alors le radar signale la coupe hors fenêtre de ligue, et jamais comme un amical sur créneau
