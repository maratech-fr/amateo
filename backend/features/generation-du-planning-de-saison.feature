# language: fr
Fonctionnalité: La génération du planning de saison aboutit
  Un club dont la saison est saisie obtient un planning : le solveur répond et
  le plan est complet. C'est le contrôle de bout en bout du rail de génération —
  de la demande jusqu'au planning terminé, en passant par le moteur de calcul.

  Scénario: Le club de démonstration génère son planning de saison
    Étant donné le club de démonstration et son gestionnaire connecté
    Et le planning de saison en vigueur est rouvert pour la durée du scénario
    Quand je lance la génération du planning de saison
    Alors la génération aboutit avec le statut « COMPLETED »
