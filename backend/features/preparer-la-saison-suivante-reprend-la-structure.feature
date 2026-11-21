# language: fr
Fonctionnalité: Préparer la saison suivante reprend la structure du club
  En fin de saison, le gestionnaire prépare la suivante d'un geste : il retrouve ses équipes,
  ses coachs et leurs affectations, ses gymnases et leurs créneaux, ses règles. Une règle qui
  nomme un gymnase nomme celui de la nouvelle saison, jamais l'ancien. Le geste ne se rejoue
  pas : la saison suivante existe déjà, on la désigne. Et la saison en cours n'est pas touchée.

  Scénario: La saison suivante reprend la structure, une seule fois, sans toucher la saison en cours
    Étant donné un petit club dont l'offre Essentiel règle aussi la saison suivante
    Et nous sommes le 15 mai de la saison en cours
    Et une règle qui impose le « Gymnase Nord » à l'équipe « U13M1 »
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
