# language: fr
Fonctionnalité: Un verrou est souverain à la régénération
  Une séance verrouillée en dur ne bouge pas quand on régénère : le solveur la
  replace à l'identique. Un déplacement impossible — vers une case sans créneau
  ouvert — est refusé et nommé, jamais appliqué en silence. Et une règle qui
  contredit un verrou ne le déplace pas : le créneau reste, la règle violée est
  signalée. Un verrou est une vérité absolue.

  Scénario: Une séance verrouillée reste à la même case après régénération
    Étant donné le club de démonstration, connecté, avec une version de saison rouverte
    Et une séance de cette version verrouillée en dur
    Quand je régénère le planning de saison
    Alors la séance verrouillée occupe toujours la même case

  Scénario: Déplacer une séance verrouillée vers une case fermée est refusé sans la déplacer
    Étant donné le club de démonstration, connecté, avec une version de saison rouverte
    Et une séance de cette version verrouillée en dur
    Quand je tente de déplacer cette séance vers une case sans créneau ouvert
    Alors le déplacement est refusé et nommé, et la séance n'a pas bougé

  Scénario: Un jour imposé couvert par une séance verrouillée ne fait pas échouer la régénération
    Une séance verrouillée le jour imposé SATISFAIT « au moins une séance ce jour-là » : le verrou
    tient la promesse à la place d'un créneau libre, et la régénération aboutit, le créneau
    verrouillé intact. Sans cela, le jour imposé viderait le modèle et la génération échouerait.
    Étant donné le club de démonstration, connecté, avec une version de saison rouverte
    Et une séance de cette version verrouillée en dur
    Et une règle « au moins une séance ce jour-là » pour cette équipe
    Quand je régénère le planning de saison
    Alors la régénération aboutit, le verrou satisfaisant le jour imposé

  Scénario: Un créneau libre réservé retire la place au solveur
    Une case réservée en créneau libre n'accueille plus aucune équipe à la génération : la
    place est retirée, exactement comme une capacité en moins. Le créneau libre n'est pas une
    équipe — il n'épingle personne, il occupe la case.
    Étant donné le club de démonstration, connecté, avec une version de saison rouverte
    Et un créneau libre réservé sur une case occupée du socle
    Quand je régénère le planning de saison
    Alors aucune équipe n'est placée sur la case du créneau libre

  Scénario: Une règle qui contredit un verrou est signalée, le créneau reste
    Étant donné le club de démonstration, connecté, avec une version de saison rouverte
    Et une séance de cette version verrouillée en dur
    Et une règle qui interdit à cette équipe de s'entraîner ce jour-là
    Quand je régénère le planning de saison
    Alors le verrou reste à sa case et la règle violée est signalée

  Scénario: Deux séances verrouillées d'un plan de période reviennent verrouillées, aux mêmes cases, après régénération
    Un verrou de période SURVIT à « Régénérer », exactement comme un verrou de saison : la version
    nouvelle repart des DONNÉES EN COURS (la dernière version terminée du plan) et en réépingle les
    verrous HARD. Avant le correctif, une version nue n'héritait d'aucun verrou et les séances
    revenaient déverrouillées, replacées ailleurs.
    Étant donné le club de démonstration, connecté, avec un plan de période portant une version terminée
    Et deux séances libres de cette version verrouillées en dur
    Quand je régénère le plan de période
    Alors les deux séances reviennent verrouillées, aux mêmes cases

  Scénario: Charger les verrous d'une version de période, puis régénérer, repart de SES verrous
    « Charger » fait des verrous d'une version de période les données EN COURS de son plan : la
    régénération qui suit repart d'eux, verrous compris. C'est la promesse de la confirmation de
    « Régénérer » (« chargez d'abord ses données avec Charger ») rendue VRAIE pour une période.
    Étant donné le club de démonstration, connecté, avec un plan de période portant une version terminée
    Et deux séances libres de cette version verrouillées en dur
    Quand je charge les verrous de cette version
    Et je régénère le plan de période
    Alors les deux séances reviennent verrouillées, aux mêmes cases

  Scénario: Régénérer repart des données en cours (la dernière version terminée), ses verrous compris
    « Régénérer » repart TOUJOURS de la version en cours, même quand on en consultait une plus
    ancienne : une deuxième régénération repart de la version née de la première (devenue la version
    en cours), verrous compris — ils ne se perdent pas d'une génération à l'autre.
    Étant donné le club de démonstration, connecté, avec un plan de période portant une version terminée
    Et deux séances libres de cette version verrouillées en dur
    Quand je régénère le plan de période
    Et je régénère encore le plan de période
    Alors les deux séances reviennent verrouillées, aux mêmes cases
