# language: fr
Fonctionnalité: Supprimer emporte ce qui en dépend, et rien d'autre
  Quand le gestionnaire supprime une équipe, un gymnase ou un coach, tout ce qui ne vit que par
  lui part avec lui : ses séances, ses réservations, les règles qui le visent ou le nomment, ses
  liens ; la fenêtre de suppression l'annonce avant qu'il confirme. Le reste
  du club demeure tel quel ; le temps de trajet entre deux lieux du club ne dépend pas du gymnase
  d'une saison. Et la remise à zéro de la saison efface toute la structure, mais garde
  le club, son gestionnaire et la saison elle-même.

  Scénario: Supprimer une équipe, un coach, puis un gymnase ; puis remettre la saison à zéro
    Étant donné un petit club de trois équipes sans compétition, deux gymnases et deux coachs, dont le planning de saison est généré
    Et l'équipe « U13M1 » a une réservation au « Gymnase Nord » et une règle à elle
    Et l'équipe « U15M1 » a une réservation au « Gymnase Sud »
    Et le « Gymnase Nord » a une règle à lui
    Et le coach « Camille », qui entraîne « U13M1 » et « U15M1 », a une indisponibilité
    Et l'équipe « U15M1 » a une règle qui lui impose le « Gymnase Nord »
    Et le trajet entre le « Gymnase Nord » et le « Gymnase Sud » est saisi à 12 minutes en voiture
    Quand je supprime l'équipe « U13M1 »
    Alors l'équipe « U13M1 » n'a plus ni séance, ni réservation, ni règle, ni lien de coach
    Et « Camille » entraîne toujours « U15M1 », et les séances des autres équipes sont intactes
    Quand je supprime le coach « Camille »
    Alors « Camille » n'a plus ni lien d'équipe, ni indisponibilité
    Et l'équipe « U15M1 » garde ses séances, sans coach nommé
    # La fiche de la personne reste au club jusqu'à la purge de la saison : « Charger » une version d'avant la ramène.
    Et la fiche de « Camille » existe toujours dans le club
    Et la suppression du « Gymnase Nord » annonce 1 contrainte qui nomme ce gymnase
    # Le temps de trajet est celui des lieux, au club : supprimer le gymnase d'une saison ne l'annonce pas et ne l'emporte pas.
    Et la suppression du « Gymnase Nord » n'annonce aucun barème de trajet
    Quand je supprime le gymnase « Gymnase Nord »
    Alors le « Gymnase Nord » n'a plus ni créneau, ni séance, ni règle
    Et plus aucune règle ne nomme le « Gymnase Nord »
    Et le « Gymnase Sud » garde ses créneaux et ses séances
    # Le lieu du gymnase reste au club jusqu'à la purge de la saison : « Charger » une version d'avant le ramène avec son adresse.
    Et le lieu du « Gymnase Nord » existe toujours dans le club, avec son temps de trajet jusqu'au « Gymnase Sud »
    Quand je remets la saison à zéro
    Alors la saison n'a plus ni équipe, ni coach, ni gymnase, ni créneau, ni règle, ni réservation, ni séance
    Et le club n'a plus aucune fiche de personne
    Et le club n'a plus aucun lieu, ni temps de trajet
    Et le club existe toujours, avec sa saison, et j'en suis toujours le gestionnaire
