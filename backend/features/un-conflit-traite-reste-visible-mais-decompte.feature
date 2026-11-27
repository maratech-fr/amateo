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

  Scénario: Le traitement d'hier ne colle pas à la règle d'aujourd'hui : le « hors fenêtre ligue » d'une coupe disparaît, son conflit neuf naît « à traiter »
    Une coupe était jugée contre la fenêtre de ligue de son équipe, et le gestionnaire avait traité son
    « hors fenêtre ligue ». Une coupe n'obéit plus qu'à son horaire : ce conflit-là n'existe plus, son
    statut reste en base sans s'afficher ni être réécrit. Si la coupe sort de 08:00-21:00, le conflit
    neuf est un autre conflit, qui naît « à traiter ».
    Étant donné le club de démonstration, connecté, dont le planning de saison est en vigueur
    Et une coupe à domicile d'une équipe jetable un samedi à 18h00, hors de la fenêtre de ligue de son équipe
    Et un statut « Dérogation demandée » posé autrefois sur le « hors fenêtre ligue » de cette coupe
    Quand je relis le radar des matchs
    Alors le « hors fenêtre ligue » traité de la coupe n'est plus sur le radar, et son statut reste en base
    Quand je repousse le coup d'envoi de la coupe à 21h30
    Alors la coupe porte « coupe hors horaires », « à traiter »
