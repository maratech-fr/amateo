# language: fr
Fonctionnalité: Un verrou est souverain à la régénération
  Une séance verrouillée en dur ne bouge pas quand on régénère : le solveur la
  replace à l'identique. Et un déplacement impossible — vers une case sans
  créneau ouvert — est refusé et nommé, jamais appliqué en silence.

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
