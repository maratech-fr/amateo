# language: fr
Fonctionnalité: Préparer la saison suivante reprend la structure du club
  En fin de saison, le gestionnaire prépare la suivante d'un geste : il retrouve ses équipes,
  ses coachs et leurs affectations, ses gymnases et leurs créneaux, ses règles. Une règle qui
  nomme un gymnase nomme celui de la nouvelle saison, jamais l'ancien. Le geste ne se rejoue
  pas : la saison suivante existe déjà, on la désigne. Et la saison en cours n'est pas touchée.
  Les coachs des deux saisons sont les mêmes personnes : corriger le téléphone d'un coach dans
  une saison le corrige dans l'autre, mais le retirer d'une saison ne le retire pas de l'autre.
  Les gymnases des deux saisons sont aux mêmes lieux : corriger l'adresse d'un gymnase dans une
  saison la corrige dans l'autre, mais le dissocier de sa salle fédérale ne vaut que pour sa saison.

  Scénario: La saison suivante reprend la structure, une seule fois, sans toucher la saison en cours
    Étant donné un petit club dont l'offre Essentiel règle aussi la saison suivante
    Et nous sommes le 15 mai de la saison en cours
    Et une règle qui impose le « Gymnase Nord » à l'équipe « U13M1 »
    Et le « Gymnase Nord » est relié à sa salle fédérale, avec son adresse et sa position
    Et le trajet entre le « Gymnase Nord » et le « Gymnase Sud » est saisi à 12 minutes en voiture
    Quand je prépare la saison suivante
    # Règles : les cinq règles semées à l'inscription du club, plus celle qui impose un gymnase.
    Alors la saison suivante reprend la structure de la saison en cours :
      | ce qui est repris         | nombre |
      | équipes                   | 11     |
      | coachs                    | 4      |
      | affectations de coach     | 5      |
      | gymnases                  | 3      |
      | créneaux de gymnase       | 36     |
      | règles                    | 6      |
    Et la règle de la saison suivante impose le « Gymnase Nord » de la saison suivante, pas celui de la saison en cours
    Et la saison en cours a gardé toute sa structure
    Quand je prépare la saison suivante une seconde fois
    Alors c'est refusé : « La saison suivante existe déjà. », et la saison suivante déjà préparée est désignée
    Et le club n'a que deux saisons
    # Le nom, le mail et le téléphone d'un coach vivent une fois, au club : sa fiche de personne.
    # Le coach d'une saison est le rôle de cette personne cette saison-là.
    Et les 4 coachs de la saison suivante sont les mêmes personnes que ceux de la saison en cours
    Quand je corrige le téléphone de « Camille » dans la saison suivante
    Alors « Camille » a ce téléphone dans la saison en cours
    Quand je supprime le coach « Camille » de la saison suivante
    Alors « Camille » entraîne toujours ses équipes dans la saison en cours
    # L'adresse et la position d'un gymnase vivent une fois, au club : son lieu.
    # Le gymnase d'une saison est l'usage de ce lieu cette saison-là.
    Et les 3 gymnases de la saison suivante sont aux mêmes lieux
    Quand je corrige l'adresse du « Gymnase Nord » dans la saison suivante
    Alors le « Gymnase Nord » de la saison en cours a cette adresse
    # Dissocier ne vaut que pour la saison où on le fait : le gymnase y passe sur un lieu neuf, sans adresse.
    Quand je dissocie le « Gymnase Nord » de sa salle fédérale dans la saison suivante
    Alors le « Gymnase Nord » de la saison en cours garde son adresse et ses temps de trajet
    Et le « Gymnase Nord » de la saison suivante n'a plus d'adresse, sur un lieu distinct de celui de la saison en cours
