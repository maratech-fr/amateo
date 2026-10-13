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

  Scénario: Mutualiser une séance placée d'un plan de période garde la case, défaire le groupe l'emporte
    Étant donné le club de démonstration et un plan de période généré
    Et deux séances placées d'équipes distinctes, chacune sur sa propre case
    Quand je mutualise la première séance en y rattachant la seconde équipe
    Alors les deux séances partagent la même case, liées à un même bloc de mutualisation
    Quand je supprime le bloc de mutualisation
    Alors les deux séances du groupe ont disparu du plan

  Scénario: Mutualiser au planning de saison garde la case, le groupe naît à portée saison
    Étant donné le club de démonstration et son planning de saison
    Et deux séances JETABLES placées au socle, d'équipes distinctes, chacune sur sa propre case
    Quand je mutualise la première séance en y rattachant la seconde équipe
    Alors les deux séances partagent la même case, liées à un même bloc de mutualisation
    Et le bloc de mutualisation est à portée saison

  Scénario: Le groupe prend la place d'une équipe, qui passe à replacer
    Étant donné le club de démonstration et son planning de saison
    Et deux séances JETABLES placées au socle, d'équipes distinctes, chacune sur sa propre case
    Et une troisième équipe JETABLE occupant une case d'accueil ouverte
    Quand je mutualise la première séance en y rattachant la seconde équipe
    Et je déplace le groupe sur la case occupée par la troisième équipe
    Alors le groupe siège sur la case d'accueil et la troisième équipe est à replacer
