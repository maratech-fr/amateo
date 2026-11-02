# language: fr
Fonctionnalité: L'export des matchs pour les parents
  Le gestionnaire choisit une plage de dates et repart avec un vrai PDF des matchs,
  rendu par le worker de la stack. Le contenu du document est prouvé plus bas
  par Unit/Service/MatchesPdfHtmlTest et Integration/Export/MatchesExportDataProviderTest,
  les refus sans session et entre clubs par Security/ExportAuthenticationTest et
  Security/ExportPdfDownloadIsolationTest.

  Scénario: J'exporte les matchs d'une plage et je repars avec le PDF
    Étant donné le club de démonstration et son gestionnaire connecté
    Et un match amical de mon club dans la plage exportée
    Quand j'exporte les matchs de cette plage
    Alors je repars avec le PDF des matchs
