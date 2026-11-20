# language: fr
Fonctionnalité: Un club ne voit jamais les données d'un autre club
  Chaque club est cloisonné : un autre club ne liste ni ses équipes ni les occupations
  de ses gymnases, ne peut pas en lire une par son identifiant (elle est « introuvable », jamais « interdite » —
  on ne révèle même pas qu'elle existe), ni la supprimer. Et à l'intérieur d'un
  club, un membre sans rôle de gestion ne modifie rien : chaque geste du gestionnaire lui est
  refusé, avec son motif.

  Scénario: Un autre club ne voit, ne lit ni ne touche l'équipe et l'occupation du club de démonstration
    Étant donné le club de démonstration et un autre club, chacun connecté
    Et une équipe créée par le club de démonstration
    Et une occupation posée par le club de démonstration dans l'un de ses gymnases
    Alors l'autre club ne voit pas cette équipe dans sa liste
    Et l'autre club ne peut pas lire cette équipe : elle est introuvable
    Et l'autre club ne peut pas supprimer cette équipe : elle est introuvable
    Et l'autre club ne voit pas cette occupation dans la liste de ce gymnase
    Et l'autre club ne peut ni lire, ni modifier, ni supprimer cette occupation : elle est introuvable
    Et l'autre club ne peut ni poser ni déplacer une occupation dans ce gymnase : « Gymnase inconnu pour ce club. »

  Scénario: Un membre sans rôle de gestion ne modifie rien, et chaque refus dit pourquoi
    Étant donné le club de démonstration et l'un de ses membres sans rôle de gestion
    Alors chacun de ces gestes lui est refusé (403), avec ce message :
      | geste                                        | message                                      |
      | créer une équipe                             | Cette action est réservée aux gestionnaires. |
      | poser une occupation dans un gymnase du club | Cette action est réservée aux gestionnaires. |
      | générer un planning                          | Cette action est réservée aux gestionnaires. |
      | régénérer un planning                        | Cette action est réservée aux gestionnaires. |
      | combler un planning de période               | Cette action est réservée aux gestionnaires. |
      | valider un planning                          | Cette action est réservée aux gestionnaires. |
      | rouvrir un planning                          | Cette action est réservée aux gestionnaires. |
      | verrouiller une séance                       | Cette action est réservée aux gestionnaires. |
      | placer les matchs                            | Cette action est réservée aux gestionnaires. |
      | importer les rencontres                      | Cette action est réservée aux gestionnaires. |
      | importer des équipes                         | Cette action est réservée aux gestionnaires. |
      | envoyer les conflits aux coachs              | Cette action est réservée aux gestionnaires. |
      | lancer une collecte de doléances             | Cette action est réservée aux gestionnaires. |
      | régler l'horloge simulée                     | Cette action est réservée aux gestionnaires. |
      | modifier une équipe                          | Cette action est réservée aux gestionnaires. |
      | préparer la saison suivante                  | Cette action est réservée aux gestionnaires. |
      | désactiver un membre                         | Cette action est réservée aux gestionnaires. |
      | révoquer une invitation                      | Cette action est réservée aux gestionnaires. |
      | exporter les données du club                 | Cette action est réservée aux gestionnaires. |
