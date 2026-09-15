# language: fr
Fonctionnalité: Un conflit traité reste visible, mais décompté
  Le gestionnaire pose sur un conflit du radar un statut de traitement (dérogation demandée, réglé
  en interne, sans solution) — le conflit n'est JAMAIS masqué : il reste rendu par le radar, avec sa
  résolution à côté. Le défaut « à traiter » n'est pas un statut stocké : c'est l'absence de ligne.
  Seul un gestionnaire écrit ; un membre simple lit mais ne pose rien (P4-207). L'horloge de l'app
  est épinglée le temps du scénario pour que les rencontres du décor restent futures.

  Scénario: Le gestionnaire pose « Dérogation demandée » — le conflit reste sur le radar, avec sa résolution
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et deux litiges distincts sur le radar des matchs
    Quand le gestionnaire pose « Dérogation demandée » sur le premier litige
    Alors le radar rend toujours ce litige, portant la résolution « Dérogation demandée »
    Et l'autre litige reste « à traiter »

  Scénario: Un membre sans rôle de gestion ne peut pas poser de statut
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et deux litiges distincts sur le radar des matchs
    Et un membre du club sans rôle de gestion
    Quand ce membre tente de poser « Réglé en interne » sur le premier litige
    Alors le statut lui est refusé
