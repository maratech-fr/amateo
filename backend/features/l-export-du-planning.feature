# language: fr
Fonctionnalité: L'export du planning en vigueur
  Le gestionnaire exporte son planning en vigueur en PDF pour l'afficher au
  gymnase et le remettre aux familles. Le document produit est un vrai PDF, non
  vide. Le refus d'un export sans session (PDF ET XLSX, chemin d'abus « export
  anonyme ») est prouvé au plus bas par Security/ExportAuthenticationTest.

  Scénario: J'exporte le planning en vigueur en PDF
    Étant donné le club de démonstration et son gestionnaire connecté
    Quand je demande l'export PDF du planning en vigueur
    Alors je reçois un fichier PDF non vide
