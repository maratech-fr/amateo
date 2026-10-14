# language: fr
Fonctionnalité: Ce que le gestionnaire saisit, le planning le respecte
  Une contrainte saisie n'est pas décorative : le solveur l'honore. Si le
  gestionnaire interdit à une équipe de s'entraîner avant une heure donnée, aucune
  de ses séances n'est placée avant cette heure. Et quand une contrainte est
  impossible à satisfaire, la génération le DIT — elle échoue avec un diagnostic
  nommé, sans jamais produire un planning bricolé qui la contournerait en silence.

  # « Une contrainte d'équipe honorée » (SM1 pas avant 20h30) est désormais prouvée, sur un décor
  # léger et dans un enchaînement complet, par `la-boucle-de-travail-du-gestionnaire.feature`
  # (contrainte + réservation + verrou + régénération). Reste ici la promesse JUMELLE qu'elle ne
  # porte pas : l'impossible NOMMÉ (le solveur ne bricole jamais un planning qui contourne).

  Scénario: Une contrainte impossible fait échouer la génération avec un diagnostic
    Étant donné le club de démonstration, connecté, dont le planning de saison est rouvert
    Et un entraînement mutualisé qu'aucun créneau ne peut accueillir
    Quand je génère le planning de saison
    Alors la génération échoue avec un diagnostic nommé, sans planning produit
