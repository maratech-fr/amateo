# language: fr
Fonctionnalité: Un verrou de plan de période survit à la régénération, sur le petit club
  Le gestionnaire adapte une période (fermeture) : il ouvre un plan de période, en génère une
  version en overlay — sur la grille PROPRE de la période —, verrouille des séances bien placées,
  puis régénère. Les verrous HARD d'une période survivent à « Régénérer » exactement comme ceux de
  la saison : la version nouvelle repart des DONNÉES EN COURS et les réépingle aux mêmes cases. Sur
  un petit club réaliste (palier 60 s), là où ces promesses tenaient sur le BCCL complet.

  Scénario: Les séances verrouillées d'une période le restent à chaque nouvelle génération
    Étant donné un petit club réaliste dont le planning de saison est en vigueur, avec un plan de période portant une version overlay terminée
    Et deux séances libres de cette version du petit club verrouillées en dur
    Quand je régénère le plan de période du petit club
    Alors la régénération aboutit et les deux séances reviennent verrouillées, aux mêmes cases
    Quand je régénère encore le plan de période du petit club
    Alors la régénération aboutit et les deux séances reviennent verrouillées, aux mêmes cases

  Scénario: Charger une version plus ANCIENNE, puis régénérer, repart de SES verrous
    « Charger » fait d'une version de période les données EN COURS de son plan, verrous compris, MÊME quand
    une version plus RÉCENTE existe : la régénération qui suit repart de la version CHARGÉE, ses
    verrous compris, et non de la plus récente. Sans « Charger », on repartirait des données en cours
    (la version récente) et les verrous de l'ancienne se perdraient. Ces verrous chargés comptent comme des séances :
    une équipe réduite pour la période sous le nombre de ses séances verrouillées est bloquée, jusqu'à ce qu'on la rende.
    Une séance verrouillée ne se dit réservée que si une réservation de SON plan occupe sa case : la séance
    de SM1 posée sur une case réservée au planning de saison est, dans la période, épinglée à la main.
    Étant donné un petit club réaliste dont le planning de saison, où SM1 a une séance réservée, est en vigueur, avec un plan de période jetable portant une version transcrite plus ancienne que les données en cours
    Et je charge cette version du petit club
    Et deux séances libres de cette version du petit club verrouillées en dur
    Quand je réduis à une séance pour cette période une équipe dont les deux séances sont verrouillées
    Alors le récapitulatif de la période bloque et nomme ses deux séances
    Quand j'essaie de générer le plan de période du petit club
    Alors c'est refusé par le même motif, et la version créée est retirée sans rien mettre en file
    Quand je lui rends sa seconde séance pour cette période
    Alors le récapitulatif de la période ne bloque plus
    Quand je régénère le plan de période du petit club
    Alors la régénération aboutit et les deux séances reviennent verrouillées, aux mêmes cases
    Et la séance de SM1 posée sur la case réservée du planning de saison revient épinglée à la main, pas réservée
