# language: fr
Fonctionnalité: L'export du planning en vigueur
  Le gestionnaire exporte son planning en vigueur en PDF pour l'afficher au
  gymnase et le remettre aux familles. Le document produit est un vrai PDF, non
  vide. Rien ne s'exporte sans session : demander l'export sans être connecté est
  refusé.

  Scénario: J'exporte le planning en vigueur en PDF
    Étant donné le club de démonstration et son gestionnaire connecté
    Quand je demande l'export PDF du planning en vigueur
    Alors je reçois un fichier PDF non vide

  Scénario: L'export est refusé sans session
    Étant donné le club de démonstration et son gestionnaire connecté
    Quand je demande l'export du planning en vigueur sans session
    Alors la demande est refusée faute de session
