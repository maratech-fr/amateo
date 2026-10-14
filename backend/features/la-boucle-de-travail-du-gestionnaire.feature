# language: fr
Fonctionnalité: La boucle de travail du gestionnaire tient à la régénération
  Le vrai geste de retouche : le gestionnaire pose une contrainte d'équipe, réserve un créneau
  libre pour une occupation du club, verrouille une séance bien placée, puis régénère. La
  nouvelle version honore les trois à la fois — ce qu'on saisit, le planning le respecte, et un
  verrou est souverain. Sur un petit club réaliste (palier 60 s).

  Scénario: Je contrains, je réserve, je verrouille, je régénère : tout tient
    Étant donné un petit club réaliste dont le planning de saison est en vigueur, puis rouvert pour y retravailler
    Et j'interdis à SM1 de s'entraîner avant 20h30
    Et je réserve un créneau libre sur une case occupée du socle
    Et je verrouille une autre séance du socle
    Quand je régénère le planning de saison du petit club
    Alors la régénération aboutit : le verrou tient sa case, la case réservée reste vide, et SM1 n'a aucune séance avant 20h30
