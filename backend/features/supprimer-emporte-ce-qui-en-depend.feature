# language: fr
Fonctionnalité: Supprimer emporte ce qui en dépend, et rien d'autre
  Quand le gestionnaire supprime une équipe, un gymnase ou un coach, tout ce qui ne vit que par
  lui part avec lui : ses séances, ses réservations, les règles qui le visent ou le nomment, ses
  liens ; la fenêtre de suppression l'annonce avant qu'il confirme. Le reste
  du club demeure tel quel. Et la remise à zéro de la saison efface toute la structure, mais garde
  le club, son gestionnaire et la saison elle-même.

  Scénario: Supprimer une équipe, un coach, puis un gymnase ; puis remettre la saison à zéro
    Étant donné un petit club de trois équipes sans compétition, deux gymnases et deux coachs, dont le planning de saison est généré
    Et l'équipe « U13M1 » a une réservation au « Gymnase Nord » et une règle à elle
    Et l'équipe « U15M1 » a une réservation au « Gymnase Sud »
    Et le « Gymnase Nord » a une règle à lui
    Et le coach « Camille », qui entraîne « U13M1 » et « U15M1 », a une indisponibilité
    Et l'équipe « U15M1 » a une règle qui lui impose le « Gymnase Nord »
    Quand je supprime l'équipe « U13M1 »
    Alors l'équipe « U13M1 » n'a plus ni séance, ni réservation, ni règle, ni lien de coach
    Et « Camille » entraîne toujours « U15M1 », et les séances des autres équipes sont intactes
    Quand je supprime le coach « Camille »
    Alors « Camille » n'a plus ni lien d'équipe, ni indisponibilité
    Et l'équipe « U15M1 » garde ses séances, sans coach nommé
    Et la suppression du « Gymnase Nord » annonce 1 contrainte qui nomme ce gymnase
    Quand je supprime le gymnase « Gymnase Nord »
    Alors le « Gymnase Nord » n'a plus ni créneau, ni séance, ni règle
    Et plus aucune règle ne nomme le « Gymnase Nord »
    Et le « Gymnase Sud » garde ses créneaux et ses séances
    Quand je remets la saison à zéro
    Alors la saison n'a plus ni équipe, ni coach, ni gymnase, ni créneau, ni règle, ni réservation, ni séance
    Et le club existe toujours, avec sa saison, et j'en suis toujours le gestionnaire
