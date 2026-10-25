# language: fr
Fonctionnalité: L'export du planning en vigueur
  Le gestionnaire exporte son planning en vigueur en PDF pour l'afficher au
  gymnase et le remettre aux familles. Le document produit est un vrai PDF, non
  vide, servi par une route authentifiée confinée au club (P4-357). Le refus du
  DÉCLENCHEMENT d'un export sans session (PDF ET XLSX, chemin d'abus « export
  anonyme ») est prouvé au plus bas par Security/ExportAuthenticationTest, qui
  couvre aussi le refus 401 du TÉLÉCHARGEMENT sans session.

  Scénario: J'exporte le planning en vigueur et je le télécharge, connecté
    Étant donné le club de démonstration et son gestionnaire connecté
    Quand je demande l'export PDF du planning en vigueur
    Alors je reçois un fichier PDF non vide

  Scénario: L'ancienne adresse statique ne sert plus le rendu
    Étant donné le club de démonstration et son gestionnaire connecté
    Quand je demande l'export PDF du planning en vigueur
    Alors l'ancienne adresse statique du rendu ne répond plus

  Scénario: Le rendu ne se télécharge pas sans session
    Étant donné le club de démonstration et son gestionnaire connecté
    Quand je demande l'export PDF du planning en vigueur
    Alors le rendu n'est pas servi sans session
