# language: fr
Fonctionnalité: Un verrou de plan de période survit à la régénération, sur le petit club
  Le gestionnaire adapte une période (fermeture) : il ouvre un plan de période, en génère une
  version en overlay — sur la grille PROPRE de la période —, verrouille des séances bien placées,
  puis régénère. Les verrous HARD d'une période survivent à « Régénérer » exactement comme ceux de
  la saison : la version nouvelle repart des DONNÉES EN COURS et les réépingle aux mêmes cases. Sur
  un petit club réaliste (palier 60 s), là où ces promesses tenaient sur le BCCL complet.

  Scénario: Deux séances verrouillées d'un plan de période reviennent verrouillées après régénération
    La génération overlay aboutit sur la grille propre de la période ; deux séances qu'on y verrouille
    en dur reviennent VERROUILLÉES aux mêmes cases après « Régénérer » — le verrou de période est
    souverain. Sans le réépinglage des verrous HARD des données en cours, elles reviendraient
    déverrouillées, replacées ailleurs.
    Étant donné un petit club réaliste dont le planning de saison est en vigueur, avec un plan de période portant une version overlay terminée
    Et deux séances libres de cette version du petit club verrouillées en dur
    Quand je régénère le plan de période du petit club
    Alors la régénération aboutit et les deux séances reviennent verrouillées, aux mêmes cases

  Scénario: Régénérer repart des données en cours (ses verrous compris), même à la deuxième régénération
    « Régénérer » repart TOUJOURS de la dernière version terminée : une deuxième régénération repart
    de la version née de la première (devenue la version en cours), verrous compris — ils ne se
    perdent pas d'une génération à l'autre.
    Étant donné un petit club réaliste dont le planning de saison est en vigueur, avec un plan de période portant une version overlay terminée
    Et deux séances libres de cette version du petit club verrouillées en dur
    Quand je régénère le plan de période du petit club
    Et je régénère encore le plan de période du petit club
    Alors la régénération aboutit et les deux séances reviennent verrouillées, aux mêmes cases

  Scénario: Charger les verrous d'une version plus ANCIENNE, puis régénérer, repart de SES verrous
    « Charger » fait des verrous d'une version de période les données EN COURS de son plan, MÊME quand
    une version plus RÉCENTE existe : la régénération qui suit repart de la version CHARGÉE, ses
    verrous compris, et non de la plus récente. Sans « Charger », on repartirait des données en cours
    (la version récente) et les verrous de l'ancienne se perdraient.
    Étant donné un petit club réaliste dont le planning de saison est en vigueur, avec un plan de période jetable portant une version transcrite plus ancienne que les données en cours
    Et deux séances libres de cette version du petit club verrouillées en dur
    Quand je charge les verrous de cette version du petit club
    Et je régénère le plan de période du petit club
    Alors la régénération aboutit et les deux séances reviennent verrouillées, aux mêmes cases
