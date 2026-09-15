# language: fr
Fonctionnalité: Les conflits d'un match disent la vérité
  Le radar de conflits d'un match lit le planning EFFECTIF à la date du match (ADR-0002). Quand
  une fermeture racine est découpée et que son enfant « milieu » pointe une version portant un
  entraînement, c'est cet entraînement qui compte — la période la plus ÉTROITE gagne, jamais un
  repli silencieux sur la racine sans plan (faux négatif P4-188). Et les bornes du conflit sont
  l'heure murale du club, sans décalage horaire (P4-191). Depuis D1 (2026-09-13) le radar dit AUSSI
  trois vérités de plus : deux matchs enchaînés dans un même gymnase ne se chevauchent que sur leur
  fenêtre SALLE (échauffement exclu), un match posé sur le créneau de SA PROPRE équipe n'est pas un
  conflit, et un match déjà joué ne porte plus aucun conflit. L'horloge de l'app est épinglée le
  temps du scénario pour que le décor daté reste stable quelle que soit la date du jour.

  Scénario: Le conflit d'entraînement d'un enfant « milieu » remonte, en heure murale
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et une fermeture racine découpée en milieu et fin, la racine et le milieu partageant leur date de départ
    Et le plan du milieu pointe une version portant un entraînement de l'équipe sœur le jeudi à 20h45 sur ce gymnase
    Et un match à domicile de l'équipe ce jeudi, coup d'envoi à 20h45, sur ce gymnase
    Quand je demande les conflits des matchs
    Alors un conflit d'entraînement porte ce match et sa borne de début est l'heure murale sans décalage

  Scénario: Un match posé sur le créneau de sa PROPRE équipe n'est jamais un conflit (D1)
    Un match d'une équipe et un entraînement de CETTE MÊME équipe ne se superposent pas : les
    joueurs qui jouent ne s'entraînent pas en même temps. Le radar ne DOIT rien signaler.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et une fermeture racine découpée en milieu et fin, la racine et le milieu partageant leur date de départ
    Et le plan du milieu pointe une version portant un entraînement de sa propre équipe le jeudi à 20h45 sur ce gymnase
    Et un match à domicile de l'équipe ce jeudi, coup d'envoi à 20h45, sur ce gymnase
    Quand je demande les conflits des matchs
    Alors aucun conflit d'entraînement ne porte ce match

  Scénario: Deux matchs enchaînés à deux heures dans le même gymnase ne se chevauchent pas (D1)
    La fenêtre de collision d'un gymnase est le match seul (sans échauffement) : deux rencontres
    espacées de deux heures dans la même salle ne se marchent pas dessus.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et deux matchs à domicile enchaînés à deux heures dans ce gymnase, un samedi à venir
    Quand je demande les conflits des matchs
    Alors le radar ne signale aucune collision de gymnase pour ces deux matchs

  Scénario: Un match déjà joué ne porte aucun conflit (D1)
    Un match dont la date est passée sort du radar : deux rencontres qui se chevauchaient dans le
    même gymnase hier ne sont plus signalées.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et deux matchs à domicile qui se chevauchaient dans ce gymnase, mais joués hier
    Quand je demande les conflits des matchs
    Alors le radar ne signale aucune collision de gymnase pour ces deux matchs

  Scénario: Un amical posé un week-end de match est signalé, jamais bloqué (P4-193)
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et une rencontre de championnat le samedi et un amical placé le dimanche du même week-end
    Quand je demande les conflits des matchs
    Alors le radar signale l'amical sur un créneau de match, pour cause de week-end de match

  Scénario: Une joueuse qui coache une autre équipe voit son conflit en rouge
    Une même personne coache une équipe et JOUE dans une autre : deux matchs qui se chevauchent la
    mettent en double, exactement comme un coach sur deux équipes. Le radar le dit en rouge
    (gravité 3), et chaque côté porte son rôle — coach d'un côté, joueuse de l'autre.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une personne qui coache une équipe et joue dans une autre, leurs deux matchs se chevauchant
    Quand je demande les conflits des matchs
    Alors un conflit de personne en double porte ses deux matchs, en gravité 3, coach d'un côté et joueuse de l'autre

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
