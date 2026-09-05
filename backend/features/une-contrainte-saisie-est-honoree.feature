# language: fr
Fonctionnalité: Ce que le gestionnaire saisit, le planning le respecte
  Une contrainte saisie n'est pas décorative : le solveur l'honore. Si le
  gestionnaire interdit à une équipe de s'entraîner avant une heure donnée, aucune
  de ses séances n'est placée avant cette heure. Et quand une contrainte est
  impossible à satisfaire, la génération le DIT — elle échoue avec un diagnostic
  nommé, sans jamais produire un planning bricolé qui la contournerait en silence.

  Scénario: Une équipe interdite d'entraînement avant 20h30 n'a aucune séance plus tôt
    Étant donné le club de démonstration, connecté, dont le planning de saison est rouvert
    Et une contrainte qui interdit à l'équipe SM1 de s'entraîner avant 20h30
    Quand je génère le planning de saison
    Alors la génération aboutit et aucune séance de SM1 n'est placée avant 20h30

  Scénario: Une contrainte impossible fait échouer la génération avec un diagnostic
    Étant donné le club de démonstration, connecté, dont le planning de saison est rouvert
    Et un entraînement mutualisé qu'aucun créneau ne peut accueillir
    Quand je génère le planning de saison
    Alors la génération échoue avec un diagnostic nommé, sans planning produit
