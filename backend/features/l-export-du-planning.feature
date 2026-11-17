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

  Scénario: J'exporte avec ou sans les occupations
    Une occupation nomme un moment où le gymnase n'est pas au club. L'export la montre si le
    gestionnaire le demande, jamais sinon : sans l'option, le fichier est celui d'aujourd'hui.
    Étant donné le club de démonstration et son gestionnaire connecté
    Et l'occupation « École » posée sur une plage libre d'un gymnase du planning en vigueur
    Quand je demande l'export Excel avec les occupations
    Alors le fichier nomme « Occupation — École »
    Quand je le demande sans l'option
    Alors il ne la nomme pas

  Scénario: L'ancienne adresse statique ne sert plus le rendu
    Étant donné le club de démonstration et son gestionnaire connecté
    Quand je demande l'export PDF du planning en vigueur
    Alors l'ancienne adresse statique du rendu ne répond plus

  Scénario: Le rendu ne se télécharge pas sans session
    Étant donné le club de démonstration et son gestionnaire connecté
    Quand je demande l'export PDF du planning en vigueur
    Alors le rendu n'est pas servi sans session
