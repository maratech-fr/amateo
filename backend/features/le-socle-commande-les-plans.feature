# language: fr
Fonctionnalité: Le planning de saison commande les plannings de période
  Le planning de saison est la base. Le valider ou le rouvrir efface les
  plannings de période ENTIÈREMENT à venir — jamais un planning déjà commencé,
  qu'on ne détruit pas au milieu — et tant qu'aucun planning de saison n'est en
  vigueur, on ne peut pas ouvrir un planning de période : il n'y a pas de base
  sur laquelle l'adapter.

  Scénario: Valider le planning de saison efface les plannings de période à venir, garde ceux déjà commencés
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et un planning de période à venir et un planning de période déjà commencé
    Quand je valide le planning de saison
    Alors le planning de période à venir a disparu et celui déjà commencé subsiste

  Scénario: Rouvrir le planning de saison efface les plannings de période à venir, garde ceux déjà commencés
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et un planning de période à venir et un planning de période déjà commencé
    Quand je rouvre le planning de saison
    Alors le planning de période à venir a disparu et celui déjà commencé subsiste

  Scénario: Sans planning de saison en vigueur, ouvrir un planning de période est refusé
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et le planning de saison n'est plus en vigueur
    Quand je tente d'ouvrir le planning d'une période à venir
    Alors l'ouverture est refusée faute de planning de saison en vigueur
