# language: fr
Fonctionnalité: Le planning se dit à régénérer quand sa structure change
  Modifier une donnée du club après coup ne détruit pas le planning déjà généré :
  il n'est pas faux, il est PÉRIMÉ. Le signal « à régénérer » se DÉRIVE de l'empreinte
  de structure servie par plan : tant qu'elle vaut l'empreinte figée à la génération, rien
  à régénérer ; dès qu'elle diverge, l'écran le dit. Un aller-retour au résultat nul ne
  signale donc rien, et régénérer réaligne l'empreinte.

  Scénario: Rattacher un coach à une équipe fait diverger l'empreinte, la détacher la réaligne
    Rattacher un coach à une équipe change ce que le solveur placerait : l'empreinte de structure
    du plan de saison diverge de celle figée à la génération. Détacher le coach ramène la structure
    à l'identique : l'empreinte revient à son état initial, plus rien à régénérer.
    Étant donné le club de démonstration connecté, avec un coach jetable non rattaché
    Quand je rattache ce coach à une équipe
    Alors l'empreinte de structure du plan de saison a divergé
    Quand je détache ce coach de l'équipe
    Alors l'empreinte de structure du plan de saison est revenue à son état initial
