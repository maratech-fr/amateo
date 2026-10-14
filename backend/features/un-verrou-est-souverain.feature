# language: fr
Fonctionnalité: Un verrou est souverain à la régénération
  Une séance verrouillée en dur ne bouge pas quand on régénère : le solveur la
  replace à l'identique. Un déplacement impossible — vers une case sans créneau
  ouvert — est refusé et nommé, jamais appliqué en silence. Et une règle qui
  contredit un verrou ne le déplace pas : le créneau reste, la règle violée est
  signalée. Un verrou est une vérité absolue.

  # « Une séance verrouillée reste à sa case après régénération » (verrou de SAISON souverain) est
  # désormais prouvée, sur un décor léger et dans un enchaînement complet, par
  # `la-boucle-de-travail-du-gestionnaire.feature` (contrainte + réservation + verrou + régénération).
  # « Déplacer une séance vers une case sans créneau ouvert est refusé » est replié sur son filet au
  # plus bas `SlotMoveVerdictTest::testMoveToCaseWithNoOpenSlotIsRejectedWithoutCallingEngine`
  # (jumeau MOVE de `SlotPlacementVerdictTest::testPlacementWithNoWindowIsRejectedBeforeEngine`).
  # Restent ici les promesses DISTINCTES : le verrou qui satisfait un jour imposé, le verrou souverain
  # face à une règle contraire, et la survie des verrous à travers la régénération d'un PLAN DE PÉRIODE.

  Scénario: Un jour imposé couvert par une séance verrouillée ne fait pas échouer la régénération
    Une séance verrouillée le jour imposé SATISFAIT « au moins une séance ce jour-là » : le verrou
    tient la promesse à la place d'un créneau libre, et la régénération aboutit, le créneau
    verrouillé intact. Sans cela, le jour imposé viderait le modèle et la génération échouerait.
    Étant donné le club de démonstration, connecté, avec une version de saison rouverte
    Et une séance de cette version verrouillée en dur
    Et une règle « au moins une séance ce jour-là » pour cette équipe
    Quand je régénère le planning de saison
    Alors la régénération aboutit, le verrou satisfaisant le jour imposé

  # « Un créneau libre réservé retire la place au solveur » est désormais prouvé, sur un décor léger
  # et dans un enchaînement complet, par `la-boucle-de-travail-du-gestionnaire.feature` (la case
  # réservée reste vide après régénération).

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

  Scénario: Charger les verrous d'une version de période plus ANCIENNE, puis régénérer, repart de SES verrous
    « Charger » fait des verrous d'une version de période les données EN COURS de son plan, MÊME quand
    une version plus RÉCENTE existe : la régénération qui suit repart de la version CHARGÉE, ses verrous
    compris, et non de la plus récente. C'est la promesse de la confirmation de « Régénérer » (« chargez
    d'abord ses données avec Charger ») rendue VRAIE pour une période. Sans « Charger », on repartirait
    des données en cours (la version récente) et les verrous de l'ancienne se perdraient.
    Étant donné le club de démonstration, connecté, avec un plan de période jetable portant une version terminée plus ancienne que les données en cours
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
