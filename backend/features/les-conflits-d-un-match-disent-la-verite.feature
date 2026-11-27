# language: fr
Fonctionnalité: Les conflits d'un match disent la vérité
  Le radar de conflits d'un match lit le planning EFFECTIF à la date du match (ADR-0002). Quand
  une fermeture racine est découpée et que son enfant « milieu » pointe une version portant un
  entraînement, c'est cet entraînement qui compte — la période la plus ÉTROITE gagne, jamais un
  repli silencieux sur la racine sans plan (faux négatif P4-188). Et les bornes du conflit sont
  l'heure murale du club, sans décalage horaire (P4-191). Depuis D1 (2026-09-13) le radar dit AUSSI
  trois vérités de plus : deux matchs enchaînés dans un même gymnase ne se chevauchent que sur leur
  fenêtre SALLE (échauffement exclu), un match posé sur le créneau de SA PROPRE équipe n'est pas un
  conflit, et un match déjà joué ne porte plus aucun conflit. Depuis le lot M, l'échauffement sort
  AUSSI de l'empreinte des conflits de PERSONNE, quel que soit le gymnase : une personne engagée
  deux fois n'est en conflit que si elle dépasse le COUP D'ENVOI du second engagement. L'horloge de
  l'app est épinglée le temps du scénario pour que le décor daté reste stable quelle que soit la date du jour.

  Scénario: Le conflit d'entraînement d'un enfant « milieu » remonte, en heure murale
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et une fermeture racine découpée en milieu et fin, la racine et le milieu partageant leur date de départ
    Et le plan du milieu pointe une version portant un entraînement de l'équipe sœur le jeudi à 20h45 sur ce gymnase
    Et un match à domicile de l'équipe ce jeudi, coup d'envoi à 20h45, sur ce gymnase
    Quand je demande les conflits des matchs
    Alors un conflit d'entraînement porte ce match et sa borne de début est l'heure murale sans décalage

  # Les VÉRITÉS GÉOMÉTRIQUES pures du radar (D1 / lot M) — match sur le créneau de sa propre équipe,
  # deux matchs enchaînés 2 h dans un gymnase, match déjà joué, échauffement du second domicile
  # (même gymnase ou deux gymnases), recouvrement RÉEL qui maintient le conflit de personne — sont
  # repliées sur leur filet au plus bas `Unit/Service/MatchConflictDetectorTest` (famille
  # testTwoHomeMatches* / testTwoMatchesChainedTwoHoursApart / testMatchOnItsOwnTeamTrainingStaysSilent
  # / testPastMatchesAreDroppedFromEveryFamily… — 84 tests verts). Le chemin RADAR→DÉTECTION de bout
  # en bout reste prouvé par les scénarios SERVEUR gardés ci-dessous (heure murale via plan de période
  # pointé, conflit de personne joueuse/coache, enveloppe de ligue).

  Plan du scénario: Un amical posé un week-end de match est signalé, jamais jugé contre la fenêtre de ligue (P4-193)
    La fenêtre de ligue borne le championnat, jamais l'amical : posé à la main dans « Amicaux » comme
    rangé dans une compétition amicale, il est signalé sur un créneau de match, jamais hors fenêtre.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et une fenêtre de ligue étroite le samedi matin cadre cette équipe
    Et une rencontre de championnat à domicile le samedi, coup d'envoi le soir hors de la fenêtre, et un amical <amical> placé le dimanche du même week-end
    Quand je demande les conflits des matchs
    Alors le radar signale le championnat hors fenêtre de ligue
    Et le radar signale l'amical sur un créneau de match, pour cause de week-end de match
    Et le radar ne juge jamais l'amical contre la fenêtre de ligue ni les règles du club

    Exemples:
      | amical                                     |
      | posé à la main dans « Amicaux »            |
      | de la compétition amicale « AMICAL PNM »   |

  Scénario: Une joueuse qui coache une autre équipe voit son conflit en rouge
    Une même personne coache une équipe et JOUE dans une autre : deux matchs qui se chevauchent la
    mettent en double, exactement comme un coach sur deux équipes. Le radar le dit en rouge
    (gravité 3), et chaque côté porte son rôle — coach d'un côté, joueuse de l'autre.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une personne qui coache une équipe et joue dans une autre, leurs deux matchs se chevauchant
    Quand je demande les conflits des matchs
    Alors un conflit de personne en double porte ses deux matchs, en gravité 3, coach d'un côté et joueuse de l'autre

  Scénario: Un conflit où la personne joue accepte le statut « Coache, ne joue pas »
    Les deux statuts « joue/coache » ne sont acceptés que si un côté du conflit porte le rôle
    joueuse : ici la personne coache une équipe et JOUE l'autre, donc le gestionnaire peut trancher
    qu'elle coache sans jouer. Le conflit reste rendu, portant sa résolution.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une personne qui coache une équipe et joue dans une autre, leurs deux matchs se chevauchant
    Quand je demande les conflits des matchs
    Et le gestionnaire pose « Coache, ne joue pas » sur ce conflit de personne
    Alors le statut est accepté et le conflit le porte

  Scénario: Un statut « joue/coache » est refusé sur un conflit où personne ne joue
    Le même geste, mais sur un conflit de DEUX équipes COACHÉES (aucun côté joueuse) : « Joue, ne
    coache pas » n'a pas de sens et le serveur le refuse (422), sans écrire de ligne.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et la même personne coache deux équipes dont les matchs à domicile se chevauchent vraiment, le second commençant avant la fin du premier
    Quand je demande les conflits des matchs
    Et le gestionnaire tente « Joue, ne coache pas » sur ce conflit de personne
    Alors le statut lui est refusé faute de joueur

  Scénario: Le coach principal indisponible au coup d'envoi est signalé en rouge (chantier H)
    Le radar lit l'indisponibilité DÉCLARÉE du coach principal : un match à domicile dont le coup
    d'envoi tombe dans cette fenêtre d'indisponibilité est signalé « coach principal indisponible »,
    en rouge — jamais bloquant pour le placement, mais dit au gestionnaire.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et une indisponibilité déclarée du coach principal couvre le coup d'envoi d'un match à domicile ce samedi
    Quand je demande les conflits des matchs
    Alors le radar signale le coach principal indisponible, en rouge

  Scénario: Un adjoint disponible adoucit le conflit de coach en orange (chantier H)
    Le même coup d'envoi dans l'indisponibilité du principal, mais l'équipe a un adjoint DISPONIBLE :
    factuellement quelqu'un peut couvrir, le radar adoucit l'alerte en orange — on alerte toujours,
    on ne bloque jamais.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et une indisponibilité déclarée du coach principal couvre le coup d'envoi d'un match à domicile ce samedi
    Et cette équipe a un adjoint disponible ce samedi
    Quand je demande les conflits des matchs
    Alors le radar signale le coach principal indisponible, en orange

  Scénario: Un second coach principal tient l'entraînement : le match ne crie plus
    Le coach d'un match a aussi un entraînement d'une autre équipe au même moment, mais cette équipe
    a un second coach principal, libre ce soir-là. Il tient la séance : le radar ne compte plus de
    conflit et nomme qui tient. Le coach affiché sur le planning ne bouge pas.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et l'équipe sœur a un second coach principal et un entraînement du vendredi soir, coach stocké le coach jetable
    Et un match à domicile de l'équipe ce vendredi soir, pendant cet entraînement
    Quand je demande les conflits des matchs
    Alors aucun conflit d'entraînement ne porte ce match, et une ligne tenue nomme le second coach

  Scénario: Un match un jour férié ne crie jamais hors fenêtre de ligue (PR2)
    Un match fixé par la ligue un jour férié n'est pas un week-end libre : la ligue a posé ce jour
    exprès. Le radar ne lève donc jamais de violation de fenêtre de ligue pour un match un jour
    férié, là où le même coup d'envoi un jour ordinaire serait hors de la fenêtre autorisée. La
    rencontre est un championnat, et elle tombe un jour férié officiel du territoire.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et une rencontre à domicile un jour férié, cadrée par une fenêtre de ligue que son coup d'envoi viole un jour ordinaire
    Quand je demande les conflits des matchs
    Alors le radar ne signale jamais ce match férié hors fenêtre de ligue

  Plan du scénario: Une coupe obéit à son horaire, jamais à la fenêtre de ligue ; le championnat reste borné par la ligue
    Une coupe n'a pas de fenêtre de ligue. Le week-end, le club choisit samedi ou dimanche ; en semaine la date
    est imposée ; dans tous les cas, férié compris, le coup d'envoi se joue entre 08:00 et 21:00, bornes
    comprises. Hors de ces heures, le radar dit « coupe hors horaires ». Une coupe reste un vrai match : elle
    n'est jamais signalée comme un amical. L'équipe n'a de fenêtre de ligue que le samedi de 10h00 à 10h30.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et une fenêtre de ligue étroite le samedi matin cadre cette équipe
    Et une rencontre <nature> à domicile <jour>, coup d'envoi à <heure>
    Quand je demande les conflits des matchs
    Alors le radar porte sur ce match <verdict>
    Et le radar ne signale jamais ce match comme un amical sur un créneau de match

    Exemples:
      | nature         | jour                                                   | heure | verdict                                                     |
      | de coupe       | un mercredi                                            | 20h00 | aucun conflit de règle                                      |
      | de coupe       | le samedi                                              | 18h00 | aucun conflit de règle                                      |
      | de coupe       | le samedi                                              | 21h30 | « coupe hors horaires », en gravité 3, entre 08:00 et 21:00 |
      | de coupe       | le dimanche                                            | 07h45 | « coupe hors horaires », en gravité 3, entre 08:00 et 21:00 |
      | de coupe       | un jour férié                                          | 21h30 | « coupe hors horaires », en gravité 3, entre 08:00 et 21:00 |
      | de coupe       | le samedi, déplacée au dimanche par « Modifier le match » | 15h00 | aucun conflit de règle                                   |
      | de championnat | un mercredi                                            | 20h00 | « hors fenêtre ligue »                                      |

  Scénario: Un entraînement dans le gymnase du match est signalé, le match reste placé
    Le radar lit le planning EFFECTIF ce jour-là : la séance d'une autre équipe au même gymnase, sur la
    plage du match, se signale sans rien déplacer ; la gravité reste celle d'une information.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et une fermeture racine découpée en milieu et fin, la racine et le milieu partageant leur date de départ
    Et le plan du milieu pointe une version portant un entraînement de l'équipe sœur le jeudi à 20h45 sur ce gymnase
    Et un match à domicile de l'équipe posé ce jeudi à 20h45 sur ce gymnase
    Quand je demande les conflits des matchs
    Alors le radar signale l'entraînement de l'équipe sœur dans le gymnase de ce match, en gravité 5

  Plan du scénario: Le radar lit le coach principal et le coach-joueur du planning en vigueur ; un adjoint compte tout de suite
    Changer le coach principal ou un coach-joueur ne vaut pour les matchs qu'à la validation du planning de saison. Un adjoint est libre : il compte dès qu'il est saisi.
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une équipe, un coach et un gymnase jetables
    Et <décor>
    Quand <geste>, sans valider le planning de saison
    Et je demande les conflits des matchs
    Alors <verdict>

    Exemples:
      | décor                                                                                                                      | geste                                                   | verdict                                                     |
      | un coach principal du planning en vigueur entraîne deux équipes dont les matchs à l'extérieur se chevauchent              | je confie la première équipe à un autre coach principal | le radar porte toujours le conflit de ce coach en double    |
      | un coach principal du planning en vigueur entraîne une équipe dont le match chevauche celui d'une autre équipe            | j'inscris ce coach comme joueur de l'autre équipe       | le radar ne porte aucun conflit entre ces deux matchs       |
      | le coach principal d'une équipe du planning en vigueur est indisponible au coup d'envoi de son match à domicile ce samedi | j'ajoute à cette équipe un adjoint disponible ce samedi | le radar signale ce coach principal indisponible, en orange |
