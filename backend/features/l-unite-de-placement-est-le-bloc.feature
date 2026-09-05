# language: fr
Fonctionnalité: Un entraînement mutualisé se réserve et se retire comme un bloc
  Quand des équipes s'entraînent ensemble, le groupe est l'unité : une équipe qui
  ne s'entraîne QU'en groupe ne se réserve pas seule, réserver le groupe pose la
  séance pour toutes ses équipes d'un coup, et retirer UNE séance du lot emporte
  le lot entier — on ne retire pas une équipe d'un groupe, on défait le groupe.

  Scénario: Une équipe qui ne s'entraîne qu'en groupe ne se réserve pas seule
    Étant donné le club de démonstration et son gestionnaire connecté
    Et un entraînement mutualisé de deux équipes qui ne s'entraînent qu'en groupe
    Quand je réserve une seule de ces équipes sur un créneau libre
    Alors la réservation individuelle est refusée en renvoyant vers le groupe

  Scénario: Réserver l'entraînement mutualisé pose la séance pour tout le groupe
    Étant donné le club de démonstration et son gestionnaire connecté
    Et un entraînement mutualisé de deux équipes qui ne s'entraînent qu'en groupe
    Quand je réserve l'entraînement mutualisé sur un créneau libre
    Alors la réservation mutualisée est acceptée pour les deux équipes du groupe

  Scénario: Retirer une séance du lot emporte tout le groupe
    Étant donné le club de démonstration et son gestionnaire connecté
    Et un entraînement mutualisé de deux équipes qui ne s'entraînent qu'en groupe
    Et l'entraînement mutualisé est réservé sur un créneau libre
    Quand je retire une seule séance du lot
    Alors tout le lot mutualisé a disparu du créneau
