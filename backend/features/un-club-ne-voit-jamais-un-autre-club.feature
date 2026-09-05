# language: fr
Fonctionnalité: Un club ne voit jamais les données d'un autre club
  Chaque club est cloisonné : un autre club ne liste pas ses équipes, ne peut pas
  en lire une par son identifiant (elle est « introuvable », jamais « interdite » —
  on ne révèle même pas qu'elle existe), ni la supprimer. Et à l'intérieur d'un
  club, un membre sans rôle de gestion ne modifie rien.

  Scénario: Un autre club ne voit, ne lit ni ne supprime l'équipe du club de démonstration
    Étant donné le club de démonstration et un autre club, chacun connecté
    Et une équipe créée par le club de démonstration
    Alors l'autre club ne voit pas cette équipe dans sa liste
    Et l'autre club ne peut pas lire cette équipe : elle est introuvable
    Et l'autre club ne peut pas supprimer cette équipe : elle est introuvable

  Scénario: Un membre sans rôle de gestion ne modifie rien
    Étant donné le club de démonstration et l'un de ses membres sans rôle de gestion
    Quand ce membre tente de créer une équipe
    Alors la modification lui est refusée
