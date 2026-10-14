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
  # La survie des verrous à travers la régénération d'un PLAN DE PÉRIODE (ex-scénarios :39/:49/:61,
  # sur le BCCL complet) est désormais prouvée sur un DÉCOR LÉGER (petit club, palier 60 s) par
  # `un-verrou-de-periode-survit-a-la-regeneration.feature` (verrou de période qui survit, « Régénérer »
  # qui repart des données en cours, « Charger » une version plus ancienne) — mêmes promesses, mêmes
  # assertions, sans la lenteur du BCCL. Restent ici les deux promesses de SAISON : le verrou qui
  # satisfait un jour imposé, et le verrou souverain face à une règle contraire.

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
