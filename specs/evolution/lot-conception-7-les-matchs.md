# Lot 7 · Les matchs : plan d'exécution

**Statut : version finale (v4 sur 4, après deux passes de `smith`). Plan validé par le fondateur le 2026-11-23 avec ses
décisions Q1 à Q10 (§13). PR 1 (le filet), PR 2 (une seule liste des types de conflit), PR 3a (une seule porte pour lire l'équipe d'une compétition, livrée le 2026-11-26), PR 3b (compétition et engagement séparés, livrée le 2026-11-27) PR 4a (la nature écrite une fois, amical compris ; seul le non-amical engage, livrée le 2026-11-27), PR 4b (la coupe a sa règle, livrée le 2026-11-27) PR 5 (le repli de ligue est dit, livrée le 2026-11-27) et PR 6a (une seule porte pour lire où en est un match, livrée le 2026-11-27) livrées sur `lot/conception`, non déployées ; le lot 7 s'exécute après le lot 4, désormais livré, et après les PR 1 à 3 du lot 5. Amendé le 2026-11-24 avec le plan du lot 5 : la Q10 est sans objet (§13), les PR 3b et 4a ne touchent plus `StructureRestorer` ni `RestoreFeasibility`, l'E3 pointe le lot 6.** Voie **Full**. Branche cible : `lot/conception` (seul « Lot Gate » y joue). Avant chaque merge : `/validation-runner`, `make -C backend tests-complete`, features et specs de la zone sur la stack d'épreuve. Exécutant : `neo`, après les rouges de `tank`. Pas d'`oracle` (`lot-conception-decoupage.md` §5.3). La fiche du lot est dans [`lot-conception-decoupage.md`](lot-conception-decoupage.md) § Lot 7 ; ce fichier dit **comment**.

**Les quatre dernières décisions (§13)** : Q7, les coupes ne sont plus proposées au système par « Placer » ; Q8, une compétition par équipe pour une division neuve ; Q9, un match connu de la fédération ne se range pas en amical à la main ; Q10, sans objet depuis le plan du lot 5 : « Charger » ne supprime plus d'équipe, il n'a donc plus de match à protéger.

Les `fichier:ligne` marqués « relu 11-23 » ont été relus le 2026-11-23 sur `lot/conception` (tête `47f578ea7`). Les autres viennent de la v2 et **sont à relire à l'ouverture de chaque PR**. `grep` fait foi.

## 0. Ce qui est livré, et l'ordre d'exécution

- Lots 0, 1, 2, 3, 4 et 8 livrés sur `lot/conception`, non déployés. Lot 7 : PR 1, 2, 3a, 3b, 4a, 4b, 5 et 6a livrées (§4, « Écarts au plan, tels que livrés »), la 6b reste à faire.
- **Ordre décidé (Q4, amendé le 2026-11-24) : après le lot 4, et après les PR 1 à 3 du lot 5** (qui supprime la photo de structure et le restaurateur, et fait de « Charger » un geste qui ne détruit rien : [`lot-conception-5-le-socle-froid.md`](lot-conception-5-le-socle-froid.md) §4). Lot 4 (PR 1 à 5) → lot 5 (PR 1 à 3) → lot 7 (neuf PR, les PR 1, 2, 3a, 3b, 4a, 4b, 5 et 6a sont livrées) → lot 5 (PR 4 à 7). Le code peut s'écrire en worktree entre-temps.
- Une seule PR ouverte à la fois sur `lot/conception` ; déploiement unique à la fin.

| PR du lot 7 | Fichiers partagés avec | Contrainte |
|---|---|---|
| 1 (filet) | Lot 4 PR 1 : `tests/Integration/EngineRequestReferenceTest.php`, `tests/EngineRequestDecorTrait.php` ; lot 0 : `Command/EngineRequestsRecordCommand.php` | Jamais préparée sur la PR 1 du lot 4 sans rebase |
| 2 (types de conflit) | Aucun | Libre |
| 3a (porte) | `Seed/LoadTestClubSeeder.php` | Après la PR 5 du lot 3 |
| 3b (structure) | `Deletion/CascadePlan.php`, seeders, `tests/Security/{RlsIsolationTest,ReadOnlyRoleTest,PurgeCompletenessTest}.php`, `docs/security/rls.md` | Après la PR 5 du lot 3 |
| 4a (nature, amical, périmètre engagé) | `Export/MatchesExportDataProvider.php`, `Service/SeasonTransitionService.php`, `Service/ClubProvisioner.php`, `Deletion/CascadePlan.php`, seeders | Après la PR 4 du lot 3 et la PR 3 du lot 8 |
| 4b (coupes) | Aucun fichier des autres lots | Après 4a |
| 5 (bandeau de ligue) | Aucun | Libre |
| 6a, 6b (suivi fédéral) | Seeders ; 6b : les trois tests de sécurité et `rls.md` | Après la PR 5 du lot 3 |

`openapi-snapshot.json`, `test-coverage-map.md`, `blocking-tests.md`, `etat-des-lieux.md` et la fiche du découpage sont partagés par toutes les PR : réglé au rebase.

## 1. Besoin reformulé

1. Une compétition n'appartient plus à une équipe : elle a des engagements. **La conversion garde une compétition par ligne existante, chacune avec un engagement ; rien n'est réuni** (Q5).
2. La nature (championnat, coupe, brassage, amical) s'écrit une fois, par un seul classifieur, **à la création seulement**. Les 5 coupes rangées en championnat et les 5 « Amical … » sont corrigées par la migration (Q2).
3. Un match amical est un match d'une compétition amicale ; chaque saison a sa compétition « Amicaux » ; le cas « sans compétition » disparaît. Un amical n'est jamais placé par le système (Q6).
4. **Seuls les matchs d'une compétition non amicale engagent l'équipe** (Q3).
5. **Une coupe n'a plus de fenêtre de ligue.** Le week-end, le club choisit samedi ou dimanche ; en semaine la date est imposée ; dans les deux cas le coup d'envoi se joue entre 08:00 et 21:00.
6. Un club dont la ligue n'est pas au catalogue le voit écrit. Aucune règle propre à une compétition n'est stockée ni saisie (Q1, option c).
7. Le suivi fédéral quitte la ligne du match ; un match hors FBI s'arrête à « Placé ».
8. La liste des types de conflit existe une fois, côté serveur.
9. La demande de placement est identique, sauf deux exceptions à la règle 4 : les amicaux (décidée, Q6) et les coupes (décidée, Q7).

**Quatre points tranchés le 2026-11-23 (§13)** : « Placer » ne propose plus les coupes au système (Q7) ; une division neuve jouée par deux équipes fait une compétition par équipe (Q8) ; un match connu de la fédération ne se range pas en amical à la main (Q9) ; la Q10 (« Charger » refuse tant qu'une équipe disparue porte un match) est sans objet depuis le plan du lot 5.

## 2. Constats vérifiés dans le code

Chemins relatifs à `backend/src/` sauf mention.

| # | Constat | Où |
|---|---|---|
| C1 | Une compétition porte une équipe, **obligatoire** ; unicité (club, saison, équipe, nom exact) ; elle porte poule, journées attendues, libellé FBI, échéance | `Entity/Competition.php:31`, `:55-57`, `:71-72`, `:86-111`, `:121-122` (relu 11-23) |
| C2 | Deux équipes dans la même division font deux lignes de même nom ; aucune unicité sur l'identifiant fédéral | `migrations/Version20260804170000.php:49-53` (relu 11-23) |
| C3 | Le match porte son équipe et une compétition facultative ; « nulle = amical » | `Entity/Fixture.php:72-79`, `:311` |
| C4 | La nature est écrite à quatre endroits par trois règles ; pas de valeur « amical » | `Service/FbiFixtureImporter.php:1732-1734` ; `Controller/Basketball/FfbbEngagementsController.php:531-542` ; `Service/Basketball/FfbbRencontreReconciler.php:533` ; `State/Processor/CompetitionStateProcessor.php:36-38` ; `Enum/CompetitionType.php:12-14` (relus 11-23) |
| C5 | La nature est relue dans un libellé à six endroits | `FbiFixtureImporter.php:1733` ; `FfbbEngagementsController.php:531-542` ; `FfbbRencontreReconciler.php:547-550` ; `Service/FriendlyAutoValidator.php:112-124` ; `Service/LeagueValidationOutlook.php:292` ; `Export/MatchesExportDataProvider.php:249-256` |
| C6 | Deux règles de l'amical en désaccord | `Service/Basketball/FbiDivisionSignature.php:46-51`, `:79` ; `FfbbRencontreReconciler.php:549` |
| C7 | Lectures de `getCompetitionId()` côté serveur (grep du 11-23) | `MatchPlacementPayloadBuilder.php:466` ; `FfbbRencontreReconciler.php:185` ; `FriendlyAutoValidator.php:66-67` ; `MatchConflictDetector.php:705`, `:717`, `:821` ; `Conflicts/VenueConflicts.php:105` ; `Conflicts/RuleWindowConflicts.php:54`, `:110` ; `FixtureStateProcessor.php:307` ; `LeagueValidationOutlook.php:83`, `:164`, `:338`, `:371` ; `EntryDeadlineOutlook.php:223` ; `MatchesExportDataProvider.php:80`. Écran : `PlacementPanel.tsx:179`, `CalendarPage.tsx:314`, `lib/consultFilter.ts:54`, `lib/phaseView.ts:82`, `FixtureFormDialog.tsx:43` |
| C8 | Une compétition « Amical … » est un championnat pour le placement et le radar, un amical pour la validation de ligue, l'export et le balayage des amicaux passés | `MatchPlacementPayloadBuilder.php:466` ; `LeagueValidationOutlook.php:292-300` ; `FriendlyAutoValidator.php:67`, `:112-124` |
| C9 | Import par fichier : recherche par (équipe, nom exact) ; un `competitionId` proposé n'est honoré que pour la même équipe, et la compétition est alors **renommée** au libellé de la division, sans relire sa nature | `FbiFixtureImporter.php:1703-1714` (relu 11-23) ; `Service/Fbi/FbiMappingGuards.php:66-73`, `:120-121` |
| C10 | Appariement : confirmer une ligne retire les références fédérales de toute autre compétition qui les portait ; sans match elle est supprimée | `FfbbEngagementsController.php:297-311` (relu 11-23) |
| C11 | Canal API : amical sans compétition ; sinon (1) recherche par (identifiant fédéral, équipe), (2) reprise par (équipe, nom exact), refusée si la ligne porte un autre identifiant fédéral, (3) création en coupe | `FfbbRencontreReconciler.php:487-489`, `:499-503`, `:505-525`, `:528-537` (relu 11-23) |
| C12 | La demande de placement sort de `MatchPlacementPayloadBuilder::build` ; aucun cache | `MatchPlacementPayloadBuilder.php:116` ; `Controller/PlaceMatchesController.php:176` ; `MessageHandler/PlaceMatchesHandler.php:151` |
| C13 | Le moteur reçoit fenêtres de ligue et durée par équipe, pas par match ; aucun champ du contrat ne porte une règle par match | `MatchPlacementPayloadBuilder.php:280`, `:336-345` ; `engine/app/schemas/match_input_schema.py:141-147` ; `engine/app/solver/match_placement/candidates.py:33-34` (relus 11-23) |
| C14 | Fenêtres de ligue : copie du club, clé (catégorie, niveau, sexe), jointe à l'équipe | `Entity/ClubLeagueWindow.php:34-40`, `:66-73` ; `Service/LeagueEnvelopeResolver.php:31-68` |
| C15 | Repli silencieux de ligue | `Repository/LeagueMatchWindowRepository.php:38-45` ; `Controller/LeagueMatchWindowsController.php:57-58`, `:93` ; `frontend/src/features/matches/ConstraintsPage.tsx:150-157` (relus 11-23) |
| C16 | Quatorze types de conflit en chaînes ; la documentation de l'API en liste douze | Émetteurs : `RuleWindowConflicts.php:72`, `:132` ; `VenueConflicts.php:113` ; `MatchConflictDetector.php:751`, `:860` ; empreinte `ConflictFingerprinter.php:40-97` ; doc `OpenApi/PathContributor/SeasonAndFixturePaths.php:151` ; écran `api/conflicts.ts:74`, `lib/conflictLabels.ts:21` |
| C17 | L'empreinte de « compétition incomplète » est l'identifiant de la compétition seul | `ConflictFingerprinter.php:94` (relu 11-23) |
| C18 | Le statut du match mélange placement et suivi fédéral ; dix sites l'écrivent | Relevé de la v1, non relu le 11-23 : à relire par `neo` avant la PR 6a |
| C19 | Le suivi et l'identité fédérale vivent sur la ligne du match | `Entity/Fixture.php:43`, `:48`, `:106-128`, `:179-180`, `:204-205`, `:217-218` |
| C20 | Un amical posé à la main et placé figure dans « à saisir dans FBI », puis passe « validé » une fois passé | `frontend/src/features/matches/FbiEntryList.tsx:106` ; `FriendlyAutoValidator.php:67-84` (relu 11-23) |
| C21 | Une équipe est engagée dès qu'elle porte un match, quel qu'il soit. Six lecteurs : refus de suppression, refus de changement de niveau, champ `isEngaged` (deux sites), décompte de suppression, faisabilité de « Charger » (lecteur supprimé avec `RestoreFeasibility` par la PR 3 du lot 5 ; le refus de « Charger » sur un changement de niveau d'une équipe engagée y est réécrit, à relire à la PR 4a) | `Service/TeamEngagementGuard.php:48-54`, `:78-81` ; `State/Processor/TeamStateProcessor.php:63`, `:160`, `:229` ; `State/Provider/TeamStateProvider.php:56` ; `Deletion/DeletionImpactCounter.php:46` ; `Service/RestoreFeasibility.php:90` (relus 11-23 ; supprimé par la PR 3 du lot 5) |
| C22 | Jusqu'à la PR 3 du lot 5, « Charger » efface puis réinsère les équipes, balaie les compétitions dont l'équipe a disparu, **suppose qu'une équipe balayée n'a aucun match**, et tient pour principe que l'exploration ne détruit pas. Supprimer une équipe emporte matchs puis compétitions | `Service/StructureRestorer.php:96`, `:283-300` ; `Deletion/CascadePlan.php:69-75` (relus 11-23). La PR 3 du lot 5, qui précède ce lot, supprime `StructureRestorer` : « Charger » ne crée ni ne supprime plus d'équipe, aucun match n'est menacé |
| C23 | Les purges suivent les déclarations ; une colonne peut porter plusieurs `#[RefersTo]` | `Structure/FamilyCatalog.php:66-80`, `:244-250` |
| C24 | Précédent du lot 3 : clé composée avec `club_id`, conversion sans fusion | `migrations/Version20261122140000.php:14`, `:20-23`, `:61` |
| C25 | Une saison naît à deux endroits, plus deux seeders | `Service/ClubProvisioner.php:96` ; `Service/SeasonTransitionService.php:187` ; `Seed/BcclSeeder.php:329` ; `Seed/LoadTestClubSeeder.php:284` |
| C26 | Trou du filet : les deux références de placement ont des fenêtres de ligue vides ; aucune ne couvre un amical, une coupe, un match saisi ou validé, ni deux équipes dans une division | `tests/Fixtures/engine-requests/placement-5-club-entier.json`, `placement-6-fenetre.json` ; `tests/EngineRequestDecorTrait.php:344-346` (fenêtre de ligue de catégorie « U13 » : vide, la catégorie du décor ne porte pas ce nom) |
| C27 | Deux équipes dans une division n'ont qu'un test d'intégration | `backend/features/placement-des-matchs.feature:196` (le scénario de l'amical ; aucun scénario Behat ne couvrait deux équipes dans une division avant la PR 1, qui ajoute `une-rencontre-importee-dit-si-elle-est-traitee.feature:55`) ; `tests/Integration/Service/FbiFixtureImporterTest.php:191` |
| C28 | Les gardes d'axe sont déjà au step bloquant, `EngagedTeamGuardTest` et `FfbbConfirmPerimeterTest` compris | `.github/workflows/ci.yml:682`, `:695`, `:721`, `:771`, `:775`, `:1339`, `:1351`, `:1445` ; `docs/testing/blocking-tests.md:136`, `:148`, `:157`, `:190`, `:191`, `:328`, `:330`, `:418` (relus 11-23) |
| C29 | L'écran lit une compétition par équipe ; le formulaire grise l'équipe en modification et laisse changer la date | `frontend/src/features/matches/FixtureFormDialog.tsx:37`, `:76`, `:86`, `:103-111` (relu 11-23) |
| C30 | `POST /api/competitions` pose `teamId` sans vérifier qu'il est du club. Ni `PUT` ni `DELETE` n'existent sur une compétition | `State/Processor/CompetitionStateProcessor.php:30-32` ; `ApiResource/CompetitionResource.php:26-32` (relus 11-23) |
| C31 | Créer ou modifier un match accepte n'importe quelle compétition du club pour n'importe quelle équipe | `State/Processor/FixtureStateProcessor.php:109-115`, `:163-170`, `:390-398` (relu 11-23) |
| C32 | « À placer » pour le moteur = non amical, dans la fenêtre, `UNPLACED` ou posé par le système ; le retour du moteur suit la même règle | `MatchPlacementPayloadBuilder.php:474`, `:497-499` ; `Service/MatchPlacementResultApplier.php:58-66` ; `ApiResource/FixtureResource.php:81` (relus 11-23) |
| C33 | Trois comparaisons de nom coexistent : exacte (import, canal API, unicité en base), normalisée (résolveur d'import, appariement) | `FbiFixtureImporter.php:1714`, `:1759-1762` ; `FfbbRencontreReconciler.php:508-511` ; `FfbbEngagementsController.php:498` (relus 11-23) |
| C34 | Re-dépôt : une division à une seule étiquette de club dans le fichier prend la **première** compétition de ce nom, sans regarder l'étiquette, que le fichier porte pourtant à chaque ligne | `FbiFixtureImporter.php:1764-1771`, `:1796`, `:1810-1818` (relu 11-23) |
| C35 | Côté serveur la nature n'est lue qu'à un endroit : une coupe n'a pas de journées attendues | `FfbbEngagementsController.php:321-325` ; `MatchConflictDetector.php:829-833` (relus 11-23) |
| C36 | Doctrine insère dans l'ordre des `persist` quand aucune association ne lie les entités | `State/Processor/CoachStateProcessor.php:69-81` ; `vendor/doctrine/orm/src/UnitOfWork.php:1292-1299` |
| C37 | La remise à zéro d'une saison purge ses données en gardant la saison | `Service/SeasonDataPurger.php:54` ; `Controller/ResetSeasonController.php:59` ; `Command/ResetClubSeasonCommand.php:95` |
| C38 | Lecteurs d'écran de l'équipe d'une compétition | `CalendarPage.tsx:527` ; `lib/exportRange.ts:10`, `:46` ; `EntryDeadlinesEditor.tsx:54`, `:158`, `:167` ; `lib/phaseView.ts:13-15`, `:28`, `:47-59`, `:69`, `:93` ; `lib/loopSteps.ts:133` |
| C39 | Le radar charge les compétitions ; « compétition incomplète » lit journées, adversaires et équipe sur la compétition | `Service/ConflictRadarLoader.php:174-196` ; `MatchConflictDetector.php:817-872` (relu 11-23) |
| C40 | Le moteur : une équipe **qui a des fenêtres de ligue** mais aucune ce jour-là n'a aucun coup d'envoi légal, motif « Les fenêtres de la ligue ne croisent aucune fenêtre d'accès ce jour-là. » ; une équipe **sans aucune fenêtre de ligue** est placée dans les seuls accès du gymnase, à toute heure qu'ils permettent | `engine/app/solver/match_placement/candidates.py:33-34`, `:88-92`, `:117-118` ; `reasons.py:8` (relus 11-23) |
| C41 | L'outil de preuve enregistre les demandes par club | `Command/EngineRequestsRecordCommand.php:35-38`, `:131` |
| C42 | Précédent d'écriture immédiate qui absorbe une course d'unicité | `Repository/OpponentDirectoryEntryRepository.php:49-73` |
| C43 | Un test bloquant affirme aujourd'hui le contraire de Q3 : « un amical créé via l'API FFBB engage l'équipe » | `tests/Security/EngagedTeamGuardTest.php:113-135` (relu 11-23) |
| C44 | Supprimer un match est libre, importé ou non : le périmètre engagé protège d'une cascade, pas d'un gestionnaire qui supprime ses matchs un par un | `ApiResource/FixtureResource.php:35` ; `FixtureStateProcessor.php:238-241` (relus 11-23) |
| C45 | Le radar n'exempte des fenêtres de ligue, des règles du club et des gymnases interdits que l'amical sans compétition : une coupe est jugée comme un championnat | `Conflicts/RuleWindowConflicts.php:50-56`, `:108-112` ; `Conflicts/VenueConflicts.php:103-107` (relus 11-23) |
| C46 | La pose à la main ne refuse jamais sur la fenêtre de ligue (elle avertit) ; elle refuse sur la fermeture et sur les accès match, amical exempté des accès | `FixtureStateProcessor.php:269`, `:306-341` ; `frontend/src/features/matches/PlacementPanel.tsx:86-109`, `:229-235` ; `lib/envelope.ts:62-96` ; `CalendarPage.tsx:311-323` ; `WeekWorkbench.tsx:194` (relus 11-23) |
| C47 | Aucune borne 08:00–21:00 n'existe pour un match | `engine/app/solver/model.py:228` (entraînement) ; `Service/PdfGenerator.php:813` (affichage) ; `frontend/src/features/matches/lib/matchAccess.ts:53` (ouverture de gymnase) (grep du 11-23) |
| C48 | Le moteur ne choisit jamais un jour, pour aucun match : il lit la date du match et cherche un gymnase et une heure. Un domicile de coupe y entre comme tout match de compétition | `MatchPlacementPayloadBuilder.php:426-430`, `:466-506` ; `candidates.py:25`, `:50-109` (relus 11-23) |
| C49 | Un statut de traitement dont le conflit a disparu reste en base, invisible ; il se rattache de nouveau si la même empreinte revient | `Controller/FixtureConflictsController.php:85-98` (relu 11-23) |
| C50 | Le catalogue des fenêtres est `league_match_window`, à clé interne (`AURA`) ; le club porte la clé interne (`ARA` → `AURA`, `BFC` → `BOFC`). `ffbb_league` est l'annuaire fédéral, à clé fédérale : une autre table. Seul le fichier `AURA` existe, et toutes ses fenêtres tiennent dans 08:00–21:00 ; les seniors n'y ont aucune fenêtre du lundi au jeudi | `Service/LeagueResolver.php:29-43`, `:54` ; `Entity/LeagueMatchWindow.php:40` ; `Entity/Basketball/FfbbLeague.php:15` ; `backend/data/league-match-windows.aura.json:4-25` (relus 11-23) |
| C51 | Le libellé FBI d'équipe n'est stocké que si la division porte plusieurs équipes du club dans le fichier | `FbiFixtureImporter.php:180`, `:357`, `:1095` (relu 11-23) |
| C52 | La date d'un match se change par « Modifier le match », sans contrôle ; le placement est gardé | `FixtureFormDialog.tsx:18-19`, `:86` ; `FixtureStateProcessor.php:171-173` (relus 11-23) |
| C53 | La gravité 2 porte le titre « Hors fenêtre ligue » ; la gravité 3 se lit par groupes nommés (« Personne », « Gymnase », « Règle ») | `frontend/src/features/matches/lib/diagnostic.ts:21-41` (relu 11-23) |
| C54 | **L'écran renvoie la compétition du match à chaque geste** (placer, marquer saisi, verrouiller) : un refus qui lirait la seule valeur envoyée frapperait chaque pose | `frontend/src/features/matches/api/fixtures.ts:280-307` (relu 11-23) |
| C55 | En `PUT`, une compétition absente ou nulle laisse le match tel quel ; seule une chaîne vide l'efface. En `POST`, l'écran envoie `null` par défaut | `FixtureStateProcessor.php:113`, `:166-170` ; `frontend/src/features/matches/api/fixtures.ts:273` (relus 11-23) |
| C56 | **Deux chemins réécrivent une compétition existante** : l'appariement repose la nature déduite du nom fédéral sur la compétition réutilisée ; l'import renomme la compétition désignée | `FfbbEngagementsController.php:478-483`, `:495-506` ; `FbiFixtureImporter.php:1706-1711` (relus 11-23) |
| C57 | Une entrée « à corriger dans FBI » ne part pas avec son match, sauf par la suppression du match à l'unité ; la cascade d'équipe supprime les matchs en lot, sans passer par là ; le compte « à corriger » compte toute entrée ouverte, sans vérifier que le match existe | `Entity/FbiCorrection.php:70` ; `FixtureStateProcessor.php:238-241` ; `Service/FbiCorrectionLedger.php:175-180` ; `Deletion/CascadePlan.php:74` ; `Service/EntryDeadlineOutlook.php:168` (relus 11-23) |
| C58 | **L'import refait la date depuis le fichier** : pour un extérieur ou un domicile non placé, la date du fichier s'écrit et le placement est annulé ; pour un domicile placé, l'écart de date est à trancher, sauf si l'une des deux dates tombe dans la semaine en cours, où le fichier s'applique d'office | `FbiFixtureImporter.php:1428-1438`, `:1465-1485` ; `Service/Fbi/FbiArrivalReview.php:95-104` (relus 11-23) |

**Non vérifié** : le calcul du compte global `fbiTodo.toEnter` au-delà de `EntryDeadlineOutlook.php:158-163` ; la durée actuelle de chaque suite ; un appelant du filtre `?status=` de `GET /api/fixtures` ; l'ordre des suppressions de Doctrine dans un même flush ; les types de conflit portés par `conflict_mail_receipt` ; les statuts de traitement offerts à un type neuf (`Enum/ConflictResolutionStatus.php:49`) ; **la date que le fichier FBI porte pour une coupe de week-end** (samedi, dimanche, ou celle saisie par le club recevant : donnée fédérale, pas du code) ; ce que la confirmation de « Charger » affiche.

## 3. Conception : les cinq réponses par objet

| Objet | Le plus simple | Supprime | Existant semblable | Suppose en dur | Deuxième usage |
|---|---|---|---|---|---|
| `Engagement` (table `engagement`) | **Le plus simple serait de ne pas la créer** : une compétition « Amicaux » sans équipe et trois gardes applicatives. Le plan fait plus pour trois raisons : la séparation est la cible validée (§18) et le fondateur l'a redite (« chacune avec son engagement », Q5) ; « Amicaux » est une compétition à plusieurs équipes sans cas particulier ; la base garantit que l'équipe d'un match est engagée dans sa compétition | `competition.team_id`, les cinq champs de poule et de libellé, l'unicité (équipe, nom) | La ligne `competition` (C1) | Au plus un engagement par (compétition, équipe) | Compétition saisie à la main : même table |
| `Competition` (transformée) | Nom, nature, dates, références fédérales, échéance. Même identifiant, même nom qu'aujourd'hui ; aucune unicité sur le nom | Deux des trois comparaisons de nom (C33) | Elle-même | Propriétaire : la saison | Règles propres (E1) |
| Nature | `competitionType` gagne `FRIENDLY` ; un classifieur, **appelé à la création seulement** | Cinq des six relectures (C5), le désaccord (C6), « sans compétition » (C7) | `Enum/CompetitionType.php` | Quatre valeurs ; une compétition ne devient jamais amicale et ne cesse jamais de l'être | Une valeur de plus |
| Compétition « Amicaux » | Une compétition marquée, une par saison, créée à la naissance et à la demande (C37, C42) | La branche « compétition nulle » | Aucune | Une par saison ; jamais balayée | D'autres compétitions amicales |
| Règle des coupes | Une constante (08:00, 21:00) et une fonction, côté serveur ; un miroir déclaré côté écran. Pas de table, pas de champ servi | L'application des fenêtres de ligue aux coupes | Aucune borne équivalente (C47) ; le prédicat d'intervalle fermé existe (`MatchConflictDetector.php:341`) | Deux bornes fixes, toute coupe, tout club | Bornes par compétition : c'est E1 |
| Périmètre engagé | La même requête, écrite « existe un match dont la compétition n'est pas amicale » | La protection d'une équipe qui n'a que des amicaux | `TeamEngagementGuard` (C21) : modifiée, pas doublée | « Non amical » = championnat, coupe, brassage ; le doute engage | Une nature de plus se range d'un côté ou de l'autre |
| Porte des règles propres | Non créée (Q1, c) | — | `LeagueEnvelopeResolver` | — | Avec E1 |
| `FederalTracking` (table `fixture_federal_tracking`) | Une ligne par match d'une compétition FBI | Deux valeurs du statut stocké et trois colonnes | Les colonnes du match (C19) | Au plus une ligne par match | Autre fédération |
| `ConflictType` | Une énumération PHP, 14 cas à la PR 2, un 15ᵉ à la PR 4b | Les chaînes recopiées | `HasValues` | Liste fermée | Forme commune des signalements (lot 10) |
| Vues de match enregistrées | Deux fichiers par club, écrits par la commande existante | Rien | `EngineRequestsRecordCommand` (C41) | Lecture seule | Preuve des lots 9 et 10 |

**Ce qui décide du modèle.** Le match garde `team_id` et `competition_id`. Une clé composée `(competition_id, team_id, club_id)` vers `engagement` garantit que l'équipe d'un match est engagée dans sa compétition. L'API garde `competitionId` et `teamId` partout. La clé n'arrive jamais sans son refus applicatif : les deux sont dans la PR 3b.

**Les PR 3a et 3b portent la table des engagements, et le lot garde ses neuf PR.** La PR 4a dépend de l'engagement (une compétition exige aujourd'hui une équipe, C1 : sans engagement, pas de compétition « Amicaux » unique par saison ni de refus « équipe non engagée »), et les PR 3 portent trois corrections : l'équipe contrôlée au `POST /api/competitions` (C30), le match refusé hors de la compétition de son équipe (C31), le re-dépôt qui ne prend plus le premier candidat (C34). Q8 est tranchée « deux » : une division neuve jouée par deux équipes donne une compétition par équipe, comme aujourd'hui ; la table est créée dans tous les cas.

**Écarts à la cible**

| # | Cible | Ce lot | Raison | Reprise |
|---|---|---|---|---|
| E1 | Une compétition porte ses jours, heures, durée, échauffement | Rien n'est stocké ni saisi (Q1, c). Seule règle par nature livrée : la borne des coupes, en constante | Le moteur lit ces règles par équipe (C13). 50 équipes sur 60 jouent au moins deux compétitions non amicales (6 au plus) | Ligne de roadmap après le lot conception |
| E2 | Niveau et phase en champs | Non créés | Aucun lecteur | Avec E1 |
| E3 | Vraies clés étrangères partout | Aucune vers l'équipe | « Charger » efface et réinsère les équipes (C22) | Lot 6 (le lot 5 ne pose aucune clé vers l'équipe ni le gymnase) |
| E4 | Suivi fédéral entièrement séparé | L'identité fédérale reste sur le match | Les deux index d'unicité portent l'équipe (C19) | Aucune |
| E5 | Le mot « nature » | Le champ garde le nom `competitionType` | Renommer touche 37 fichiers de test et l'API | Aucune |
| E6 | L'écran lit la liste des types | L'union TypeScript reste, tenue par un test de parité | TypeScript exige la liste à la compilation | Aucune |
| E7 | Forme commune des signalements (§23.2) | Non faite | Fiche du lot 10 | Lot 10 |
| E9 | Règle 4 : demande au moteur identique | À la PR 4a, les matchs des compétitions amicales sortent de la demande ou deviennent des ancres | **Décidé (Q6).** Effet nul sur les données du jour : 8 matchs amicaux, tous passés | — |
| E10 | Règle 4 | À la PR 4b, les domiciles de coupe sortent de la demande ou deviennent des ancres | **Décidé (Q7).** Le fondateur place les coupes à la main ; une coupe déjà placée par le système ne bouge pas | — |
| E11 | Une compétition, plusieurs engagements (§18) | **Décidé (Q8).** Vrai pour les compétitions amicales seulement ; une division fait toujours une compétition par équipe | Q5 : deux lignes de même nom peuvent être deux poules | Avec E1, ou un écran de saisie de compétition |

La conversion suit §27 de `conception-cible.md` à la lettre (aucune réunion par nom).

Nouvelles classes dans `backend/src/Matches/` (espace `App\Matches`). Aucune classe existante n'est déplacée.

## 4. Découpage : neuf PR, sept chantiers

Ordre : 1, 2, 3a, 3b, 4a, 4b, 5, 6a, 6b. **Raison de la scission 4a / 4b** : la PR serait trop grosse à relire, et l'exception des coupes à la règle 4 doit se prouver seule (une référence qui ne bouge qu'à la PR 4b).

### PR 1 · « Le filet des matchs » (`tank` pour les tests, `neo` pour l'outil de preuve)

**Change** : aucun comportement. Quatre demandes de référence, quatre scénarios de l'ACTUEL, et l'outil de preuve enregistre deux vues par club.

- `placement-16-deux-equipes-une-division.json` : deux équipes dans la même division, un domicile à placer chacune ; une fenêtre de ligue résolue pour une équipe au moins. Aucune coupe.
- `placement-17-amicaux.json` : amical sans compétition placé (ancre) ; non placé (absent) ; « Amical PNF » avec un domicile non placé et un domicile posé par le système ; un extérieur.
- `placement-18-suivi-federal.json` : domicile saisi avec gymnase et heure ; validé posé par le système ; saisi sans gymnase ; validé sans gymnase (absent) ; extérieur saisi ; extérieur validé sans gymnase (le cas mesuré : 5 chez le club A) ; domicile importé avec heure et gymnase.
- `placement-19-coupes.json` : une équipe à fenêtres de ligue le samedi seulement, **une équipe sans aucune fenêtre de ligue** ; pour la première : domicile de coupe non placé un samedi, non placé un mercredi, posé par le système, posé à la main, extérieur de coupe ; pour la seconde : domicile de coupe non placé un mercredi ; une « Coupe … » rangée en championnat avec un domicile non placé.
- Scénarios : deux équipes dans une division importée ; « Amical PNF » placé par le système ; amical posé à la main « à saisir » puis validé ; une coupe posée un mercredi est signalée « hors fenêtre ligue ».

**Contrats**
- `App\Matches\MatchViewRecorder::record(string $clubId, string $seasonId): array{fixtures: list<array<string, mixed>>, conflicts: list<array<string, mixed>>}` : ce que servent `GET /api/fixtures` et `GET /api/fixtures/conflicts`, triés par identifiant, sans balayage ni écriture.
- `app:engine-requests:record` écrit en plus `<out>/views/club-<id>/fixtures.json` et `conflicts.json`. Aucune option neuve.

**Section tests pour `tank`**
- Les quatre références passent par le handler réel. **Chaque référence vérifie d'abord ses témoins de décor en base** (16 : deux équipes portent un match d'une compétition de même nom ; 17 : un match d'une « Amical PNF » et un amical sans compétition ; 18 : un domicile validé sans gymnase, un extérieur saisi ; 19 : un domicile de coupe non placé un mercredi pour chacune des deux équipes, un posé par le système). Un témoin absent fait ÉCHOUER le test.
- Preuve du filet : une différence introduite exprès fait rougir chacune.
- Outil : deux enregistrements successifs sont identiques et aucune ligne n'est écrite.

**Écarts au plan, tels que livrés (PR 1)**
- (a) Quatre références de plus, 16 à 19 : deux équipes dans une division, amicaux, suivi fédéral, coupes (`tests/EngineRequestMatchesNetDecorTrait.php`, `EngineRequestReferenceTest`, bloquant d'office).
- (b) L'outil de preuve : `App\Matches\MatchViewRecorder::record(clubId, seasonId)` ; `app:engine-requests:record` écrit `views/club-<id>/fixtures.json` et `conflicts.json`. `fixtures` = `GET /api/fixtures` (même provider, même sérialisation), toutes les pages sans pagination, triées par `id` ; `conflicts` triés par empreinte, champ `resolution` compris, lignes `held` exclues. Le contenu est RENDU par `EngineRequestFingerprint::render` (sans nom en clair : `sha256:…`) malgré l'extension `.json`. Le recorder pose lui-même le club et ses filtres, relâche dans un `finally`, n'écrit rien et ne balaie aucun amical passé. Commande console seulement, refusée en production.
- (c) **Limite écrite** : les vues ne portent QUE la saison courante du club (`SeasonResolver::currentSeason`), alors que les demandes au moteur parcourent toutes les saisons. Condition ajoutée aux mesures de la copie de production (§9, mesure 22) : attendu 0, arrêt sinon.
- (d) La forme de `resolution` est recopiée de `FixtureConflictsController::resolutionView` (privée) ; gardée par un test de parité, note comprise (`Integration/Matches/MatchViewRecorderTest`).
- (e) Les quatre scénarios Behat de la PR 1 décrivent le comportement ACTUEL ; ils seront retournés aux PR 3b, 4a, 4b et 6b.
- (f) Pas de chaîne de conversion `Lot7` : le décor des références 16 à 19 dans le schéma d'avant le lot 7 ne se capture que maintenant, avant la PR 3b. **Décision ouverte, à prendre avant la PR 3b** : veut-on prouver la conversion à l'octet comme au lot 4 (`Lot4MigrationChainTest`) ?
- (g) Repères périmés du plan, corrigés au §2 : C26, C27, C41.
- (h) Décors qui casseront, à réécrire dans la PR concernée. PR 3b : `PlacementHoldingDecorTrait:222-281` (utilisé par `DayMatchesPayloadParityTest`, `TrainingOccupancyHoldingParityTest`, `MatchPlacementSemanticsGateTest`), `MatchPlacementContractSchemaTest:506-540`, `VenueClosureSemanticsGateTest:234` (son `competitionId` forgé ne pointe sur aucune ligne). PR 4a : `MatchPlacementContractSchemaTest:222`, `LeagueEnvelopeMirrorParityTest:21`. Hors CrossStack : `EngineRequestDecorTrait:307`, `EngineRequestMatchesNetDecorTrait` (`netCompetition`), `EngineRequestsRecordCommandTest::seedClubB` et `restoreFixture2`.

### PR 2 · « Une seule liste des types de conflit » (`neo`, backend et un fichier d'écran)

**Change** : émetteurs, empreinte, table des statuts et documentation de l'API lisent l'énumération ; la documentation passe de 12 à 14 valeurs. Aucun comportement.

**Contrats** : `enum App\Matches\ConflictType: string` (14 cas, `use HasValues`) : `VENUE_OVERLAP`, `LEAGUE_WINDOW_VIOLATION`, `CLUB_RULE_VIOLATION`, `TEAM_VENUE_FORBIDDEN`, `MATCH_MATCH`, `MATCH_TRAINING`, `MATCH_COACH_UNAVAILABLE`, `VENUE_UNAVAILABLE`, `ACCESS_WINDOW_LOST`, `VENUE_TRAINING_OVERLAP`, `COMPETITION_INCOMPLETE`, `AWAY_NO_FOOTPRINT`, `FRIENDLY_ON_MATCH_SLOT`, `TEAM_LEVEL_MISSING` ; `ConflictResolutionStatus::casesForFamily(ConflictType $type): list<self>` ; `GET /api/fixtures/conflicts` de forme inchangée.

**Section tests pour `tank`** : `tests/CrossStack/ConflictTypeListParityTest.php` (groupe `contract`) : énumération, union TypeScript, clés de `conflictLabels.ts`, `openapi-snapshot.json` ; entrée au registre de `FrontRederivationRegistryTest`. NR : `les-conflits-d-un-match-disent-la-verite.feature` et `un-conflit-traite-reste-visible-mais-decompte.feature` rejouées inchangées.

**Écarts au plan, tels que livrés (PR 2)**
- (a) `App\Matches\ConflictType` : 14 cas, valeur = nom, `HasValues`. `ConflictResolutionStatus::casesForFamily(ConflictType)` remplace la table `FAMILY_EXTRA` par un `match`. `FixtureConflictsController` convertit par `tryFrom` (même 422). `ConflictFingerprinter` : `tryFrom`, puis `InvalidArgumentException` sur un type inconnu (jamais un `ValueError`). Les émetteurs écrivent `ConflictType::X->value`. OpenAPI : `conflicts[].type` lit `ConflictType::values()` (14 valeurs : `MATCH_COACH_UNAVAILABLE` et `TEAM_LEVEL_MISSING` manquaient au contrat publié, corrigé) et `held[].type` lit trois cas (`MATCH_MATCH`, `MATCH_TRAINING`, `MATCH_COACH_UNAVAILABLE`) ; le snapshot est régénéré, ces deux seules valeurs changent.
- (b) Test de parité `ConflictTypeListParityTest` (groupe `contract`, job « Engine semantics », pas au step bloquant : aucun axe §7.1) : énumération, union TypeScript, clés de `conflictLabels.ts`, OpenAPI vivant, snapshot ; plus un garde « aucun fichier serveur n'écrit un type en chaîne », trois lecteurs exemptés un par un. Entrée au registre de `FrontRederivationRegistryTest`.
- (c) **Limites écrites** : trois lecteurs ne sont pas convertis (`ConflictCoachMailBuilder`, `ConflictCoachMailing`, `ConflictFeedAssembler`, exemptés dans `READERS_LEFT_OUT` du test ; une entrée qui ne recopie plus rien rougit). Le miroir front `frontend/src/features/matches/lib/conflictResolution.ts:41-44` (`FAMILY_EXTRA_STATUSES`) n'est gardé par aucun test de parité, et son commentaire (`:37`) renvoie à `ConflictResolutionStatus::FAMILY_EXTRA`, qui n'existe plus : à corriger au passage d'une PR qui touche ce fichier.
- (d) L'assertion `casesForFamily('UNKNOWN_FAMILY')` est supprimée : un type inconnu n'est plus exprimable. Le refus d'un type inconnu est tenu par `ConflictFingerprinterTest::testUnknownTypeIsRefused` ; la branche 422 du contrôleur sur type inconnu est inatteignable (l'empreinte lève avant).
- (e) Émetteurs absents de la liste C16, convertis : `PersonConflicts.php:134`, `:260`, `:362` ; `VenueConflicts.php:63`, `:113`, `:163`, `:238`, `:289` ; `MatchConflictDetector.php:663`, `:906`. L'union TypeScript commence à `api/conflicts.ts:72`. La documentation de l'API a un second enum, `held`, à `SeasonAndFixturePaths.php:~200`.

### PR 3a · « Une seule porte pour lire l'équipe d'une compétition, aucun comportement ne change » (`neo`, backend et écran)

**Change** : tout lecteur et tout écrivain de « l'équipe d'une compétition, son libellé FBI, sa poule, ses journées attendues » passe par une porte, qui lit encore les colonnes de `competition`. Aucune migration, aucun champ d'API, aucune règle de recherche modifiée (les comparaisons exactes restent exactes).

**Contrats**
- `App\Matches\EngagementView` (lecture seule) : `competitionId`, `teamId`, `fbiTeamLabel: ?string`, `ffbbPouleId: ?string`, `ffbbPouleName: ?string`, `ffbbPouleOpponents: ?list<string>`, `expectedMatchdays: ?int`.
- `App\Matches\CompetitionRegistry` : `isEngaged(Competition $c, string $teamId): bool` ; `competitionsOf(string $teamId): list<Competition>` ; `engagementsOf(Competition $c): list<EngagementView>` ; `engagementOf(Competition $c, string $teamId): ?EngagementView` ; `engage(Competition $c, string $teamId, ?string $fbiTeamLabel = null): EngagementView` ; `writePoule(Competition $c, string $teamId, ?string $pouleId, ?string $pouleName, ?array $opponents, ?int $expectedMatchdays): void` ; `writeFbiTeamLabel(Competition $c, string $teamId, ?string $label): void`.
- Sites déplacés : `FbiFixtureImporter.php:1706`, `:1714`, `:1721`, `:1730`, `:1735`, `:1773` ; `FbiMappingGuards.php:69`, `:73`, `:121` ; `FfbbRencontreReconciler.php:500`, `:508`, `:531` ; `FfbbEngagementsController.php:297-326`, `:479`, `:497`, `:516` ; `MatchConflictDetector.php:829-865` ; `CompetitionStateProcessor.php:30-32` ; `ApiResource/CompetitionResource.php`.
- Écran : `engagementsOf(competition: Competition): CompetitionEngagement[]` dans `api/competitions.ts` (un élément, bâti sur `teamId`) ; `CompetitionEngagement { teamId: string; fbiTeamLabel: string | null; ffbbPouleId: string | null; ffbbPouleName: string | null; expectedMatchdays: number | null }`. Les lecteurs de C38 et `FixtureFormDialog.tsx:37` passent par elle.

**Section tests pour `tank`** : aucun test neuf. Références 5, 6, 16 à 19 à l'octet ; scénarios de la PR 1, `une-rencontre-importee-dit-si-elle-est-traitee`, `FfbbConfirmPerimeterTest`, `FbiFixtureImporterTest`, `phaseView.test.ts` rejoués inchangés.

**PR 3a livrée le 2026-11-26** sur `lot/conception`, non déployée.

**Écarts au plan, tels que livrés (PR 3a)**
- (1) `CompetitionRegistry` est construit à la main dans `MatchConflictDetectorTest` : le détecteur reçoit la porte en 4e paramètre à valeur par défaut (`new CompetitionRegistry`) et le gestionnaire d'entités de la porte est nullable ; sans lui, seule `competitionsOf` lève une `LogicException`. Ce défaut et ce nullable sont un échafaudage de test : à retirer à la PR 3b, qui adapte le test.
- (2) Divergence théorique, `FbiFixtureImporter.php:~1714` : la recherche par nom exact passe par `FbiMappingGuards::exactNameCompetitionOf`, et le renommage compare le nom en mémoire au lieu d'un `findOneBy` en base. La différence n'existe que si deux mappings d'une même équipe se suivent dans une même importation, l'un renommant la compétition, l'autre portant l'ancien nom. Aucun test ne joue ce cas.
- (3) Lecteurs restés en place, parce qu'ils liront des colonnes supprimées à la PR 3b et seront réécrits avec elle : `FbiFixtureImporter.php:195`, `:381`, `:474`, `:1239` ; `FbiMappingGuards.php:187`, `:219`, `:293` ; `FfbbRencontreReconciler.php:449` ; `FfbbEngagementsController.php:391` ; `LoadTestClubSeeder.php:759` ; `ConflictRadarLoader.php:174-196` ; `LeagueEnvelopeResolver` ; les échéances.
- (4) `loopSteps.ts:133` : le repère du plan est périmé, rien à déplacer.
- (5) Écran : les fichiers qui lisent l'engagement importent `engagementsOf` directement depuis `./api/competitions`, pas depuis le barrel `./api`, parce que des `vi.mock('./api')` de tests existants remplaceraient le barrel entier.

### PR 3b · « Compétition et engagement séparés, une ligne pour une ligne » (`neo`, backend et écrans)

**Change**
- **Migration** (§9) : crée `engagement` avec RLS ; une ligne d'engagement par ligne de compétition (150 → 150 compétitions et 150 engagements) ; aucune compétition ne change d'identifiant ni de nom, aucun match n'est repointé ; six colonnes retirées ; clés posées. Irréversible.
- **Arrêts de la migration** : un match dont l'équipe n'est pas celle de sa compétition (C31) ; deux lignes d'une même équipe dont les noms ne diffèrent que par la casse ou les accents. Aucun engagement n'est fabriqué pour passer.
- **Unicité.** En base : un engagement par (compétition, équipe). Plus aucune unicité sur le nom d'une compétition : deux compétitions de même nom existent et restent légitimes (mesure : 2 groupes, 4 lignes, club C). « Pas deux compétitions de même nom pour une même équipe » est tenu par la porte : une seule comparaison, **normalisée**, pour l'import, le canal API et l'appariement (fin de C33).
- **Un match exige que son équipe soit engagée dans sa compétition** : hors engagement, créer ou modifier un match est refusé 422 (fin de C31). Un match sans compétition n'est pas contrôlé avant la PR 4a.
- `POST /api/competitions` refuse une équipe qui n'est pas du club et de la saison (fin de C30).
- **Re-dépôt** (fin de C34) : parmi les engagements des compétitions de ce nom, (1) un seul engagement et une seule étiquette dans le fichier : il est pris ; (2) sinon celui dont le libellé FBI est celui de la ligne ; (3) aucun : la division est rendue « à faire correspondre ».
- **Le nom ne fait jamais entrer une équipe dans une compétition existante** : une division connue par son nom mais pas pour cette équipe donne une compétition neuve pour l'équipe, comme aujourd'hui.
- Appariement : références sur la compétition, poule et journées sur l'engagement. La résorption des jumelles (C10) est inchangée. Canal API : inchangé dans ses trois étapes ; l'étape 2 compare par le nom normalisé et garde son refus.
- Suppression d'une équipe (« Charger » ne supprime plus d'équipe depuis la PR 3 du lot 5) : l'engagement part ; une compétition sans engagement ni match est supprimée.
- Purge : `Fixture::$competitionId` déclare aussi `#[RefersTo(Engagement::class, OnDelete::DELETE)]`.
- Ordre d'écriture : compétition, engagement, match, dans l'ordre des `persist` (C36) ; suppressions en lot dans l'ordre inverse.

**Une compétition par équipe, comme aujourd'hui (Q8)**
- Une division neuve jouée par deux équipes donne deux compétitions de même nom, une par équipe, distinguées par le libellé FBI.
- `engage()` refuse un second engagement dans une compétition (les compétitions amicales en seront exemptées à la PR 4a).
- Le renommage par une proposition (C9) reste celui d'aujourd'hui : la compétition n'a qu'une équipe.
- Écran : les lecteurs de C38 lisent le seul engagement de la compétition ; aucun écran ne change d'apparence. L'empreinte de « compétition incomplète » et `completeness[]` ne changent pas.

**Contrats**
- `App\Entity\Engagement` : `id`, `version`, `createdAt`, `updatedAt`, `clubId`, `seasonId`, `competitionId`, `teamId`, `fbiTeamLabel`, `ffbbPouleId`, `ffbbPouleName`, `ffbbPouleOpponents`, `expectedMatchdays`. `#[Family(Level::SEASON)]`. Unique (`competition_id`, `team_id`).
- Clés : `engagement (competition_id, club_id)` → `competition (id, club_id)` `ON DELETE CASCADE` ; `fixture (competition_id, team_id, club_id)` → `engagement (competition_id, team_id, club_id)` `ON DELETE RESTRICT`.
- `CompetitionRegistry` gagne : `findForTeam(string $teamId, string $label): ?Competition` (nom normalisé) ; `findForTeamByFederalId(string $teamId, string $ffbbCompetitionId): ?Competition` ; `resolveDivision(string $divisionKey, string $labelKey, bool $singleLabelInFile): ?EngagementView` ; `create(string $clubId, string $seasonId, string $name, CompetitionType $type): Competition` ; `pruneEmpty(string $seasonId): int`.
- `GET /api/competitions`, par élément : perd `teamId`, `fbiTeamLabel`, `ffbbPouleId`, `ffbbPouleName`, `expectedMatchdays` ; gagne `engagements: list<{teamId, fbiTeamLabel, ffbbPouleId, ffbbPouleName, expectedMatchdays}>`. `?teamId=` rend les compétitions où l'équipe est engagée.
- `POST /api/competitions {name, competitionType, teamId, startDate?, endDate?}` → `201` une compétition neuve, l'équipe engagée ; `422` si l'équipe a déjà une compétition de ce nom ; `422` si l'équipe est inconnue.
- `POST` et `PUT /api/fixtures` : `422` si l'équipe n'est pas engagée dans la compétition désignée.
- Écran : `Competition.engagements`.
- Tests : `App\Tests\CreatesCompetitionsTrait::competitionFor(string $teamId, string $name, CompetitionType $type = CompetitionType::CHAMPIONSHIP): Competition`.

**Textes**

| Cas | Texte |
|---|---|
| `POST /api/competitions`, l'équipe a déjà une compétition de ce nom | « L'équipe {équipe} a déjà une compétition « {compétition} ». » |
| `POST /api/competitions`, équipe inconnue | « Équipe inconnue pour ce club et cette saison. » |
| Match, équipe non engagée (jusqu'à la PR 4a) | « L'équipe {équipe} n'est pas engagée dans « {compétition} ». Choisissez une de ses compétitions, ou aucune pour un amical. » |
| Cascade de suppression (`CascadePlan.php:75`) | « engagement en compétition » / « engagements en compétition », inchangé |

**Section tests pour `tank`**
- Promesse : la conversion ne réunit rien et ne perd rien. `tests/Integration/Migration/CompetitionEngagementMigrationTest.php` : ligne seule ; deux lignes de même nom et d'équipes différentes (restent deux compétitions) ; poule, journées, libellé et échéance conservés ; match dont l'équipe n'est pas celle de sa compétition (arrêt) ; deux noms d'une même équipe égaux à la casse près (arrêt).
- Promesse : un dépôt range chaque match sous la bonne équipe. `FbiFixtureImporterTest` : division neuve à deux étiquettes (deux compétitions, une par équipe) ; re-dépôt du même fichier (rien n'est ajouté) ; re-dépôt d'un fichier à une seule étiquette quand deux engagements portent ce nom (l'étiquette décide ; sans étiquette stockée, « à faire correspondre ») ; division connue pour une autre équipe (compétition neuve).
- Promesse : l'ordre d'écriture tient. `tests/Integration/Matches/CompetitionWriteOrderTest.php` : compétition, engagement et match créés dans un même flush par le canal API ; équipe sans match supprimée avec son engagement. Si l'ordre des suppressions ne tient pas dans un flush, `neo` passe à deux flush et le dit.
- NR périmètre engagé : `FfbbConfirmPerimeterTest` adapté mécaniquement, falsifié dans les deux sens ; `EngagedTeamGuardTest` vert sans changement.
- NR isolation (bloquants) : `RlsIsolationTest`, `ReadOnlyRoleTest`, `PurgeCompletenessTest`, `FamilyDeclarationSweepTest` ; `MatchTenantIsolationTest` : la base refuse un engagement qui désigne la compétition d'un autre club.
- Abus, chaque garde falsifiée en la désactivant : `competitionId` d'un autre club à l'import (`MatchTenantIsolationTest`) ; `teamId` d'un autre club au `POST /api/competitions` (`MatchTenantIsolationTest`) ; compétition où l'équipe n'est pas engagée à l'import (`ImportFixturesApiTest`) ; match posé dans la compétition d'une autre équipe, et PUT qui change l'équipe en gardant la compétition (`FixtureApiTest`, 422 et non 500).
- Demande : références 5, 6, 16 à 19 à l'octet. Cette preuve ne voit pas le risque de cette PR (§8).
- Écran : `phaseView.test.ts` adapté.

**Contrôle visuel (`switch`)** : dialogue d'import.

**PR 3b livrée le 2026-11-27** sur `lot/conception`, non déployée.

**Écarts au plan, tels que livrés (PR 3b)**
- (1) `CompetitionRegistry` reçoit son `EntityManagerInterface` obligatoire (l'échafaudage de test de la PR 3a, détecteur à valeur par défaut et gestionnaire nullable, est retiré). `engagementsByCompetition()` (lecture groupée pour la liste des compétitions) est ajoutée hors du contrat annoncé.
- (2) `DeletionImpactParityTest::columnsHandledBy` a une branche `TeamEngagementStep`, et non `SILENT_STEPS` : l'étape est annoncée sous `team_competition`, elle compte donc dans l'aperçu de suppression.
- (3) `CreatesCompetitionsTrait::competitionFor` gagne un paramètre `?callable $shape` pour façonner la compétition avant son engagement.
- (4) Limite écrite : la PR ne mémorise pas le libellé FBI d'une correspondance sans libellé sur une division à une seule étiquette. Si une autre équipe a déjà un engagement de ce nom, chaque re-dépôt rend la division « à faire correspondre » (trou de produit, non étendu).
- (5) `FfbbEngagementsController::findOrCreateCompetition` garde sa propre copie de `normalize` (algorithme identique à `VenueLabelNormalizer`) : le plan annonçait une seule comparaison pour l'import, le canal API et l'appariement.
- (6) La normalisation SQL de la migration ne couvre pas les ligatures œ et æ (écart marginal avec la porte).
- (7) Limite écrite : `engage()` lève une `LogicException` (500) sur un `competitionId` valide déjà engagé par une autre équipe à l'import. À durcir en 422.
- (8) `TeamEngagementStep` instancie `CompetitionRegistry` en direct.
- (9) Non joués : les e2e `matches.spec.ts` et `a11y-contrast.spec.ts` ; au contrôle visuel, la vue Phase avec compétition appariée et le dialogue d'import avec fichier ne sont pas vus.

### PR 4a · « La nature écrite une fois, amical compris ; seul le non-amical engage » (`neo`, backend et écrans)

**Change**
- `CompetitionType` gagne `FRIENDLY`. Un classifieur, appelé par l'import par fichier, l'appariement et le canal API, **à la création d'une compétition seulement**.
- **Une compétition existante ne devient jamais amicale, et une compétition amicale ne change jamais de nature** (C56). À l'appariement, la nature déduite du nom fédéral ne se repose plus que de championnat vers coupe ou brassage, comme aujourd'hui ; jamais vers ou depuis « amical ». À l'import, une division « Amical … » proposée sur une compétition non amicale voit la proposition ignorée : elle suit son propre libellé.
- **Conversion (Q2)**, seule exception, faite une fois par la migration : les 5 « Amical … » deviennent amicales ; les 5 coupes rangées en championnat deviennent coupes. Aucune n'a de journées attendues ni d'alerte ouverte ; leurs règles changent à la PR 4b.
- Chaque saison a sa compétition « Amicaux » : créée à l'inscription, à la préparation de la saison suivante, par les seeders, et à la demande si elle manque (C37). Elle n'est jamais supprimée par `pruneEmpty`, ni par la suppression d'une équipe. Elle accepte plusieurs engagements ; elle n'apparaît ni dans l'éditeur d'échéances ni dans le sélecteur de phase.
- `fixture.competition_id` devient obligatoire. **À la création**, une compétition absente, nulle ou vide range le match dans « Amicaux » ; **en modification**, seule une chaîne vide explicite le fait, une compétition absente ou nulle laisse le match où il est (C55). L'engagement de l'équipe dans « Amicaux » est créé au passage, par écriture immédiate (C42).
- Un amical de la fédération entre dans la compétition amicale de son libellé (« AMICAL PNM »), jamais dans « Amicaux ».
- Les lecteurs de C7 lisent la nature par une seule porte. **Exception décidée à la règle 4 (Q6)** : un match d'une compétition amicale n'est plus confié au moteur, n'est plus jugé contre les fenêtres de ligue, les règles du club ni les accès match, et est signalé « amical sur un créneau de match ».
- **Périmètre engagé (Q3)** : une équipe est engagée dès qu'**il existe un match dont la compétition n'est pas amicale**. Un match dont la compétition est introuvable engage : le doute protège. Une équipe qui n'a que des amicaux, de la fédération ou non, se supprime et change de niveau.
- **Sort de l'amical déjà posé sur une équipe qui n'a que cela** : l'équipe devient supprimable. La confirmation annonce ses matchs (« 1 match », « N matchs », `CascadePlan.php:74`). **Elle n'annonce pas son engagement dans une compétition amicale** : la ligne « engagement en compétition » ne compte que les engagements dans une compétition non amicale. La suppression emporte les matchs, **leurs entrées « à corriger dans FBI »** (C57) et l'engagement ; « Amicaux » reste.
- **Garde contre le contournement (Q9, décidée : un match connu de la fédération ne se range pas en amical à la main)** : un match qui porte un numéro FBI ou un identifiant de rencontre fédéral, ou qui est déjà saisi ou validé, **ne peut pas changer de compétition vers une compétition amicale** (422). Renvoyer la compétition qu'il a déjà n'est jamais refusé : placer à la main un amical de la fédération passe (C54).
- **« Charger » (Q10, sans objet)** : voir ci-dessous.
- Le refus « équipe non engagée » de la PR 3b ne vaut plus que pour une compétition non amicale.

**Fichier sorti de la liste interdite** : `Service/TeamEngagementGuard.php` (Q3 change la règle, écrite à cet endroit seul). Les autres lecteurs de la garde (C21) ne sont pas modifiés ; `RestoreFeasibility` n'existe plus à cette PR (lot 5, PR 3).

**« Charger » (Q10, sans objet)** : le plan du lot 5 remplace la photo de structure et son restaurateur (PR 3, avant ce lot) : « Charger » ne crée ni ne supprime d'équipe, d'où aucun match laissé sans équipe. Cette PR ne touche ni `StructureRestorer` ni `RestoreFeasibility`, qui n'existent plus.

**Contrats**
- `enum CompetitionType { CHAMPIONSHIP, CUP, BRASSAGE, FRIENDLY }` ; `CompetitionType::isFriendly(): bool`.
- `App\Matches\CompetitionClassifier::classify(string $label, CompetitionType $default = CompetitionType::CHAMPIONSHIP): CompetitionType` : premier mot « amical » → `FRIENDLY` ; « coupe », ou début « crml » ou « cmrl » → `CUP` ; « brassage » → `BRASSAGE` ; sinon `$default` (`CUP` pour le canal API). `::reclassOf(Competition $existing, string $label): ?CompetitionType` : rend `CUP` ou `BRASSAGE` pour une compétition non amicale, `null` dans tous les autres cas.
- `App\Matches\FriendlyCompetitions::defaultFor(string $clubId, string $seasonId): Competition` et `::engageInDefault(string $clubId, string $seasonId, string $teamId): Competition` (`INSERT … ON CONFLICT DO NOTHING` puis relecture) ; `::friendlyIdsOf(string $seasonId): array<string, true>` ; `::isFriendly(Fixture $fixture): bool`.
- `competition.default_friendly BOOLEAN NOT NULL DEFAULT false`, unique partiel (`club_id`, `season_id`) où vrai.
- `TeamEngagementGuard::isEngaged(string $teamId): bool`, `::assertNotEngaged(string $teamId, string $message): void`, `::engagedTeamIds(list<string> $teamIds): array<string, true>` : signatures inchangées. Requête : `SELECT 1 FROM fixture f WHERE f.team_id = :tid AND NOT EXISTS (SELECT 1 FROM competition c WHERE c.id = f.competition_id AND c.competition_type = 'FRIENDLY')`. Pas de jointure interne. Le champ `isEngaged` de `GET /api/teams` garde nom et forme.
- `TeamEngagementGuard::teamIdsHoldingAFixture(list<string> $teamIds): array<string, true>` (la requête d'aujourd'hui, `:78-83`).
- `App\Deletion\TeamEngagementStep implements CascadeStep` : supprime tous les engagements de l'équipe ; `count()` ne compte que ceux d'une compétition non amicale. `App\Deletion\TeamFixtureCorrectionStep implements CascadeStep` : supprime les entrées `fbi_correction` des matchs de l'équipe, avant l'étape des matchs ; `label()` rend `null` (registre interne). `FbiCorrection::$fixtureId` passe à `#[RefersTo(Fixture::class, OnDelete::DELETE)]`.
- `GET /api/competitions` : `competitionType` peut valoir `FRIENDLY` ; gagne `defaultFriendly: boolean`.
- **`GET /api/fixtures` gagne un seul champ : `competitionType: 'CHAMPIONSHIP' | 'CUP' | 'BRASSAGE' | 'FRIENDLY'`** ; `competitionId` jamais nul. Aucun champ `friendly`, aucun champ `kickoffRule` : la PR 4b n'ajoute rien à cette ressource.
- `POST /api/fixtures` : `competitionId` absent, nul ou vide → « Amicaux ». `PUT /api/fixtures/{id}` : `competitionId` absent ou nul → inchangé ; `""` → « Amicaux » ; `422` si la compétition **change** vers une compétition amicale et que le match porte une identité fédérale ou est saisi ou validé.
- Écran : `Fixture.competitionType` ; `isFriendlyFixture(fixture: Fixture): boolean` et `isCupFixture(fixture: Fixture): boolean` dans `lib/envelope.ts`, miroir déjà déclaré, cas partagés dans `leagueEnvelope.parity.json` (clé `natureCases`). L'option « Amicaux » du formulaire envoie l'identifiant de la compétition d'office quand elle existe, sinon `""`, jamais `null`.

**Textes**

| Cas | Texte |
|---|---|
| Nom de la compétition d'office | « Amicaux » |
| Formulaire de match, libellé du sélecteur (remplace « Compétition (vide = amical) ») | « Compétition » |
| Formulaire de match, première option (remplace « Amical ») | « Amicaux » |
| Compétitions illisibles (`CalendarPage.tsx:631`) | « Les compétitions n'ont pas pu être chargées — la création d'un match est momentanément indisponible. » |
| Équipe non engagée (remplace le texte de la PR 3b) | « L'équipe {équipe} n'est pas engagée dans « {compétition} ». Choisissez une de ses compétitions, ou « Amicaux ». » |
| Match fédéral, ou déjà saisi, qu'on veut passer en amical | « Ce match est connu de la fédération : il reste dans sa compétition. » |
| Suppression et niveau d'une équipe engagée (`TeamStateProcessor.php:65`, `:162`) | Inchangés |
| Confirmation de suppression, équipe qui n'a que des amicaux | « 1 match » / « {n} matchs » ; aucune ligne « engagement en compétition » |
| Export PDF, mention | « (amical) », inchangée |

**Section tests pour `tank`**
- Promesse : un amical a une seule définition, quel que soit son chemin d'arrivée. Scénarios du §5, lignes 11 à 16.
- Promesse : la conversion pose la nature et ne laisse aucun match sans compétition. `tests/Integration/Migration/CompetitionNatureMigrationTest.php` : « Amical PNF » ; « AMICAL RM3 » ; « Parc Amical » (reste championnat) ; « Coupe du Rhône » stockée championnat ; match sans compétition ; saison sans match ; compétition déjà nommée « Amicaux ».
- Promesse : classifieur et conversion disent la même chose. `tests/Unit/Matches/CompetitionClassifierTest.php`, jeu de libellés partagé avec le test de migration.
- Promesse : « Amicaux » survit. `supprimer-emporte-ce-qui-en-depend.feature`, Exemples ; `engageInDefault` appelé deux fois donne une compétition et un engagement ; après remise à zéro, poser un amical rend 201.
- **NR périmètre engagé, dans `EngagedTeamGuardTest`** (déjà au step bloquant, `ci.yml:771`, `blocking-tests.md:190` ; la ligne de `blocking-tests.md` est réécrite dans cette PR ; aucun step neuf) :
  - une équipe qui n'a qu'un amical dans « Amicaux » se supprime (204), son amical part avec elle, « Amicaux » reste ; son niveau change (200) ;
  - le test `testAFixtureBornFromTheFfbbApiChannelEngagesTheTeam` (C43) est retourné : un amical de la fédération n'engage plus ; le docblock dit que la règle a changé ;
  - championnat, coupe et brassage, « à placer » compris, engagent toujours (409) ;
  - **un match dont la compétition a disparu engage** (409) : le cas qui fait rougir une jointure interne ;
  - falsification : la sous-requête retirée fait rougir le premier cas, la condition inversée le troisième.
- **Abus du périmètre, même fichier, chaque garde désactivée fait rougir** :
  - ranger par l'API un match importé dans « Amicaux », puis dans une compétition amicale créée par `POST /api/competitions`, rend 422 ; l'équipe reste engagée et le match intact ;
  - même geste sur un match créé à la main puis marqué « saisi » : 422 ;
  - **placer à la main un amical de la fédération en renvoyant sa compétition rend 200** : le test qui attrape une garde trop large.
- **Abus par reclassement** : `FfbbConfirmPerimeterTest` (bloquant, `ci.yml:775`), un cas : apparier une compétition de championnat qui porte des matchs à une ligne fédérale « Amical … » ne change pas sa nature, l'équipe reste engagée ; `FbiFixtureImporterTest`, un cas : une division « Amical PNF » proposée sur une compétition de championnat ne la renomme ni ne la reclasse.
- Cascade : `DeletionImpactParityTest` adapté (étape silencieuse nommée) ; un test d'intégration : supprimer une équipe qui n'a que des amicaux ne laisse aucune entrée « à corriger dans FBI », et le compte « à corriger » retombe.
- `PUT` sans `competitionId` sur un match de championnat : le match reste en championnat (`FixtureApiTest`).
- NR sens des contraintes : la fenêtre de ligue borne un match de championnat et pas un amical de la fédération (ligne 13).
- Abus : `competitionId` d'un autre club (`MatchTenantIsolationTest`) ; match posé dans une compétition non amicale d'une autre équipe (`FixtureApiTest`, rejoué).
- Demande : 5, 6, 16, 18, 19 à l'octet ; **la 17 change** : le domicile non placé de « Amical PNF » sort, celui posé par le système devient une ancre.
- Écran : `matchAccess.parity.json` et ses deux tests de parité ; `leagueEnvelope.parity.test.ts` lit `natureCases` ; `consultFilter.test.ts` ; `FixtureFormDialog` (geste : poser un amical).

**Contrôle visuel (`switch`)** : formulaire « Ajouter un match » ; panneau de placement d'un amical de la fédération ; filtre de consultation par nature ; confirmation de suppression d'une équipe qui n'a que des amicaux.

**PR 4a livrée le 2026-11-27** sur `lot/conception`, non déployée.

**Écarts au plan, tels que livrés (PR 4a)**
- (1) `CompetitionClassifier::classify` et `::reclassOf` sont statiques. `FriendlyCompetitions` gagne `engageIn(Competition $friendly, string $teamId)` et `ensureDefault(string $clubId, string $seasonId): string`, hors du contrat annoncé.
- (2) Toutes les écritures de « Amicaux » (compétition d'office, engagement) sont des `INSERT … ON CONFLICT DO NOTHING` suivis d'une relecture, pas seulement `engageInDefault`.
- (3) `Fixture::$competitionId` reste `?string` en PHP, avec un `@phpstan-ignore` : la colonne est `NOT NULL` en base (`Version20261204120000`), l'entité n'est pas retypée.
- (4) Côté écran, `competitionType` (sur `Fixture`) et `defaultFriendly` (sur `Competition`) sont optionnels dans les types TS.
- (5) La conversion de la migration : un championnat que son libellé classe en brassage devient brassage. Une compétition déjà nommée « Amicaux » reste championnat, et une « Amicaux » d'office distincte naît pour la saison.
- (6) `FbiDivisionSignature::isFriendlyCode` (`Service/Basketball/FbiDivisionSignature.php:79`, premier mot « amical » par préfixe) n'est pas touchée : sa règle diverge du classifieur, et « Amicaux » n'y est pas reconnu. Divergence écrite, non corrigée.
- (7) Une équipe inconnue est refusée 422 à la création et à la modification d'un match (`assertTeamInScope`) ; la création de « Amicaux » ne lit que `_club_id` et `_season_id`.
- (8) Le rattachement fédéral (`FfbbRencontreReconciler`) est élargi aux matchs rangés dans « Amicaux » d'office (`isFriendlyRow`). Le pont de suggestion de l'appariement exclut les compétitions amicales. `EntryDeadlineOutlook` ignore les compétitions amicales. `ACCESS_WINDOW_LOST` n'est pas retouché.
- (9) `TeamEngagementGuard::teamIdsHoldingAFixture` est ajoutée sans appelant. Le formulaire de match (`createFixture`) envoie encore `competitionId` nul par défaut ; le serveur le range dans « Amicaux ».
- (10) Demande au moteur : la référence 20 (« Amicaux » née à la bascule) est exclue du renumérotage de `EngineRequestSocleNetDecorTrait` ; références 1 à 21 à l'octet, sauf la 17 qui change comme annoncé.
- (11) `CompetitionNatureMigrationTest` est ajouté au step bloquant de `ci.yml` et à `docs/testing/blocking-tests.md`.
- (12) Un scénario Behat, `la-boucle-de-travail-du-gestionnaire.feature:19`, a rougi une fois au Behat complet (décor dépendant du placement du solveur d'entraînement, rail non touché par la PR) ; vert rejoué seul, et vert aux runs complets précédent et suivant. À surveiller : ne pas conclure « flaky » sans `make ci-releve`.
- (13) Non joués : Q9 au contrôle visuel (aucun fichier d'import disponible) ; les e2e. Captures dans `captures/lot7-pr4a/` (ignoré par git).

### PR 4b · « La coupe a sa règle » (`neo`, backend et écrans)

**La règle, décidée le 2026-11-23** : une coupe n'a plus de fenêtre de ligue. Le week-end, le club choisit samedi ou dimanche ; en semaine la date est imposée ; dans les deux cas le coup d'envoi se joue entre 08:00 et 21:00, bornes comprises. C'est une règle neuve (C47).

**Change**
- **Radar** : un domicile de coupe posé n'est plus jugé contre les fenêtres de ligue (`RuleWindowConflicts.php:54`). Hors de 08:00–21:00 il est signalé par un type neuf, « coupe hors horaires », gravité 3, groupe « Règle ». Le jour n'est jamais jugé pour une coupe. La borne vaut aussi un jour férié.
- **Ce qui ne change pas pour une coupe** : les règles du club (`RuleWindowConflicts.php:110`), les gymnases interdits (`VenueConflicts.php:105`) et les accès match du gymnase (`FixtureStateProcessor.php:307`).
- **Pose à la main** : rien n'est refusé de plus (C46). Le panneau remplace l'indication de ligue par celle de la coupe ; la grille marque une coupe hors horaires comme elle marquait un match hors fenêtre. **Choisir samedi ou dimanche = « Modifier le match », champ Date** (C52) : le geste existe, aucun écran neuf.
- **Le choix du jour peut être défait par l'import** (C58) : tant que FBI porte l'autre jour, un dépôt remet la date du fichier sur une coupe non placée, et sur une coupe placée dans la semaine en cours ; hors de cette semaine, l'écart de date d'une coupe placée est à trancher. C'est le comportement de tout match ; il n'est pas modifié.
- **Demande au moteur (décidé, Q7 : le fondateur place les coupes à la main)** : un domicile de coupe n'est plus confié au système. Non placé, il sort de la demande ; posé par le système, il devient une ancre ; posé à la main, il l'était déjà.
- **Statuts de traitement déjà posés sur un « hors fenêtre ligue » d'une coupe** : le conflit disparaît du flux, sa ligne reste en base, invisible (C49), purgée avec la saison. Aucune ligne n'est réécrite ni supprimée. Si la coupe est encore hors de 08:00–21:00, le conflit neuf naît « à traiter ».
- **Reclassement à l'appariement** : une compétition de championnat reconnue « coupe » par son nom fédéral (C56, comportement existant) change de règle à cet instant : ses matchs quittent les fenêtres de ligue et la demande de placement (Q7).

**Ce que cela change pour le moteur, chiffré par lecture du code.** Aujourd'hui un domicile de coupe entre comme tout match de compétition : « à placer » avec sa date s'il est non placé ou posé par le système (`MatchPlacementPayloadBuilder.php:474`), ancre sinon (`:497-505`). Le moteur ne choisit jamais un jour (C48) : « date imposée en semaine » est déjà son fonctionnement, et « samedi ou dimanche » n'est pas un choix qu'il sait faire. Il applique à la coupe les fenêtres de ligue de son équipe (C40). Deux cas aujourd'hui :
- équipe **avec** fenêtres de ligue : la coupe d'un jour sans fenêtre n'est jamais placée, motif « Les fenêtres de la ligue ne croisent aucune fenêtre d'accès ce jour-là. » ; la coupe d'un jour couvert est placée dans la fenêtre, plus étroite que 08:00–21:00 ;
- équipe **sans** fenêtre de ligue : la coupe est placée dans les accès du gymnase, à toute heure qu'ils permettent, après 21:00 compris.
- Sans cette décision, ces deux cas resteraient, et le second produirait une coupe posée par le système que le radar signalerait aussitôt. La décision est une **deuxième exception chiffrée à la règle 4**, à côté de celle des amicaux (Q6).
- **Une condition** change (`:466`), aucun champ, aucun fichier moteur, `CONTRACT_VERSION` inchangée. Bougent dans la demande : les lignes `matches[]` des domiciles de coupe (absentes ou `FIXED`), et par ricochet les ouvertures datées de leurs dates (`:221-251`), les occupations d'entraînement de leurs dates (`:533-536`) et le compte « à placer » (`:211-213`, lu par `PlaceMatchesController.php:177`). Le retour du moteur ne peut plus écrire sur une coupe (`MatchPlacementResultApplier.php:58-66`). Nombre de matchs concernés : **non mesuré** (mesure 17).
- Rendre le système capable de placer une coupe selon sa règle exigerait une règle par match dans le contrat : interdit par la règle 4, renvoyé à E1.

**Contrats**
- `App\Matches\CupKickoffRule` : `public const string KICKOFF_MIN = '08:00'`, `KICKOFF_MAX = '21:00'` ; `::accepts(string $kickoff): bool` (intervalle fermé).
- `ConflictType` gagne `CUP_KICKOFF_OUT_OF_HOURS` (15ᵉ cas) ; item : `{type, severity: 3, kickoffMin: '08:00', kickoffMax: '21:00', fixture}` ; empreinte `CUP_KICKOFF_OUT_OF_HOURS:{fixtureId}`.
- **Aucun champ neuf sur `GET /api/fixtures` ni sur `GET /api/league-match-windows`.** L'écran lit `Fixture.competitionType` (PR 4a) et porte les deux bornes en miroir déclaré : `CUP_KICKOFF_MIN`, `CUP_KICKOFF_MAX` et `cupKickoffInside(kickoff: string): boolean` dans `lib/envelope.ts`, cas partagés dans `leagueEnvelope.parity.json` (clé `cupCases`). `resolveEnvelope` (`lib/envelope.ts:62`) lit la nature au lieu de `competitionId` nul.
- `MatchPlacementPayloadBuilder` et `MatchPlacementResultApplier` lisent `App\Matches\HandPlaced::is(Fixture $fixture): bool` (amical ou coupe).

**Textes** (les heures viennent des deux constantes, formatées par `formatMinutes`)

| Cas | Texte |
|---|---|
| Libellé de famille et titre du conflit | « Coupe hors horaires » |
| Résumé du conflit | « Match {équipe} du {jour et date} à {heure} : une coupe se joue entre 08:00 et 21:00. Pose libre, à surveiller. » |
| Panneau, coupe un samedi ou un dimanche, heure dans la plage ou vide | « Coupe : samedi ou dimanche au choix, coup d'envoi entre 08:00 et 21:00. » |
| Panneau, coupe en semaine, heure dans la plage ou vide | « Coupe : date imposée, coup d'envoi entre 08:00 et 21:00. » |
| Panneau, heure hors plage | « Coupe : coup d'envoi hors 08:00–21:00. » |

**Section tests pour `tank`**
- Promesse : une coupe n'obéit plus à la ligue, seulement à son horaire. `les-conflits-d-un-match-disent-la-verite.feature`, Exemples du scénario de la PR 1 retourné : coupe mercredi 20:00 (rien) ; samedi 21:30 (« coupe hors horaires ») ; dimanche 07:45 (« coupe hors horaires ») ; déplacée du samedi au dimanche par changement de date (rien) ; championnat mercredi 20:00 (toujours « hors fenêtre ligue ») : NR sens des contraintes.
- Promesse : une coupe reste un vrai match pour le gymnase. `les-conflits-d-un-match-disent-la-verite.feature:112` rejoué ; `FixtureApiTest` : coupe posée hors accès match, toujours 422.
- Promesse : le traitement d'hier ne colle pas à la règle d'aujourd'hui. `un-conflit-traite-reste-visible-mais-decompte.feature`, étape : un « hors fenêtre ligue » traité sur une coupe disparaît ; la même coupe à 21:30 fait naître un conflit « à traiter ».
- Promesse : l'import garde la main sur la date. `une-rencontre-importee-dit-si-elle-est-traitee.feature`, Exemples : coupe non placée passée au dimanche puis re-déposée avec le samedi (revient au samedi) ; coupe placée, hors semaine en cours (écart à trancher).
- Parité : `LeagueEnvelopeMirrorParityTest` et `leagueEnvelope.parity.test.ts` lisent `cupCases` (08:00 et 21:00 dedans, 07:59 et 21:01 dehors) ; `ConflictTypeListParityTest` voit le 15ᵉ type.
- `placement-des-matchs.feature`, scénario neuf : « Placer » ne propose pas un domicile de coupe ; celui déjà posé par le système ne bouge pas. Demande : 5, 6, 16, 17, 18 à l'octet ; **la 19 change et elle seule**.
- Écran : un test Vitest sur `resolveEnvelope` (coupe, championnat, amical).

**Contrôle visuel (`switch`)** : panneau d'une coupe un samedi, un mercredi, et hors horaires ; ligne « Coupe hors horaires » dans l'onglet Conflits ; grille avec une coupe marquée.

**PR 4b livrée le 2026-11-27** sur `lot/conception`, non déployée.

**Écarts au plan, tels que livrés (PR 4b)**
- (1) **La référence 19 a deux formes.** Dérivée à la main d'abord (les trois domiciles de coupe non placés sortent, celui posé par le système devient une ancre, l'occupation d'entraînement du mercredi 11 mars part avec eux), puis confirmée par le code réel : `placement-19-coupes.json` (décor de `EngineRequestReferenceTest`, où « Coupe Territoriale U13 » reste championnat, la nature décide, jamais le nom, et garde son domicile à placer) et `placement-19-coupes-converties.json` (chaîne de `Lot7MigrationChainTest` : la migration 4a range « Coupe Territoriale U13 » en coupe, son domicile sort aussi). Les deux ne diffèrent que par ce match. Références 5, 6, 16, 17, 18 à l'octet.
- (2) **Un step bloquant neuf**, contre « aucun step neuf » : `Integration/Service/ConflictRadarLeagueWindowTest` (axe sens des contraintes) rejoint le step `Blocking suite` de `ci.yml` et `docs/testing/blocking-tests.md`. Raison : le Lot Gate ne joue pas Behat ; sans lui, une coupe rejugée contre la ligue passerait.
- (3) Les Exemples du scénario retourné portent aussi le jour férié, la pose libre hors horaires et « coupe samedi 18:00 » : trois cas repliés dans le même `Plan du scénario`, pas trois scénarios.
- (4) Côté écran, `resolveEnvelope` rend un amical sans enveloppe (`mapped: false`) et une coupe avec une enveloppe active (`mapped: true`, `cup: true`, `windows` vide, tout jour), `timeOk` = `cupKickoffInside`.
- (5) `HandPlaced::is` lit la compétition par `EntityManager::find` : un N+1 possible par match (une requête par fixture hors identité déjà chargée), sans enjeu de sécurité. Il remplace `FriendlyCompetitions::friendlyIdsOf` dans `MatchPlacementPayloadBuilder`.
- (6) Le retour du moteur ne peut plus écrire non plus sur un amical (`MatchPlacementResultApplier`, par `HandPlaced`) ; rien ne change en pratique, le moteur n'en reçoit plus.
- (7) Sans saison choisie, un amical n'est plus envoyé « à placer » : l'ancienne lecture passait par la saison, `HandPlaced` n'en dépend pas.
- (8) `MatchPlacementResultApplierTest` est neuf et **non bloquant** (groupe `integration`) : placer des matchs n'est pas un axe §7.1.
- (9) Le plafond `frontend/act-warnings-ceiling.json` passe de 27 à 23 (mesuré sur deux runs). `conflictLabels.test.ts` est adapté mécaniquement (le 15ᵉ type).
- (10) Contrôle visuel : les icônes de l'indication de coupe (`CupHint`, `PlacementPanel`) sont corrigées après les captures ; `matches.spec.ts` joué (7 passés). Captures dans `captures/lot7-pr4b/` (ignoré par git).
- (11) Observation antérieure à la PR, hors périmètre : le bouton « Rendre au système » déborde de quelques pixels dans la carte du panneau d'un match posé (`PlacementPanel.tsx`, vers la ligne 411). Limite écrite (§11), non corrigée.

**PR 5 livrée le 2026-11-27** sur `lot/conception`, non déployée.

**Écarts au plan, tels que livrés (PR 5)**
- (1) **Ton `accent`, pas « information »** : le `NoticeBanner` prend la couleur du club (rosé sur un club à accent rouge), texte en `text-foreground`, contraste correct, cohérent avec les autres bandeaux accent de l'app. Décision : gardé ; pas d'icône, comme ses voisins.
- (2) `role="status"` ; le bandeau est hors `ManagerOnly` : un Membre le voit aussi.
- (3) Le libellé de référence « Auvergne-Rhône-Alpes » est en dur (`LeagueMatchWindow::FEDERATION_DEFAULT_LEAGUE_LABEL`) et testé en clair.
- (4) Le serveur compare la ligue effective à `league_match_window`, jamais à `ffbb_league` ; le quatrième cas de `LeagueMatchWindowsApiTest` garde ce piège.
- (5) `resolvedTeamWindows` était absent du schéma OpenAPI de la route avant la PR et n'y est pas ajouté ; seul `leagueFallback` y entre (snapshot régénéré).
- (6) Contrôle visuel : aucune re-baseline de `matchs-contraintes-club-chromium-linux.png` (la section Ligue est fermée dans la capture). Captures dans `captures/lot7-pr5/` (ignoré par git).
- (7) Pas de scénario Behat : le plan n'en nomme pas. Le test Vitest rend l'écran deux fois (serveur en repli, puis au catalogue).

**PR 6a livrée le 2026-11-27** sur `lot/conception`, non déployée.

**Écarts au plan, tels que livrés (PR 6a)**
- (1) `stateOf` rend `FederalState`, non `?FederalState` : PHPStan (`return.unusedType`) refuse un `null` jamais rendu. La PR 6b l'élargira quand un match non suivi existera.
- (2) **Sept méthodes ajoutées hors contrat** pour que tout écrivain passe par la porte : `place`, `unplace`, `moveTo`, `fbiEchoOf`, `clearFbiEcho`, `keptVenueLabelOf`, `keepVenueLabel`. `reopen` n'a qu'un appelant (`moveTo`). Aucun code n'écrit aujourd'hui un `fbiEcho` non nul.
- (3) **`FederalFollowUp` est instancié en direct à quatre endroits** : `FixtureResource::fromEntity`, `ConflictMoments::bareFixtureView`, `VenueConflicts` (construit dans `MatchConflictDetector`), `OrphanedFixtureFinder` (construit à la main dans `ValidateImpactControllerGuardTest`). À passer par l'injection à la PR 6b si la porte lit la table de suivi.
- (4) **Lecteurs DQL de la colonne laissés pour la PR 6b** : `FixtureVenueLossMarker.php:49` (`UPDATE … status = UNPLACED`), `DeletionImpactCounter.php:205-227` (`status IN …`), le filtre de recherche `status` de `FixtureResource`.
- (5) `FixtureStateProcessor::assertMayBecomeFriendly` reçoit `bool $enteredBefore` au lieu de `FixtureStatus $statusBefore`.
- (6) `FixtureStateProcessor` reçoit la porte par un setter `#[Required]`.

### PR 5 · « Le repli de ligue est dit » (`neo`, backend et un bandeau)

**Change** : l'écran des contraintes de match, section « Ligue », affiche un bandeau permanent, ton information, quand la ligue du club n'est pas au catalogue. Aucun lecteur de règle ne bouge.

**Le bandeau s'allume-t-il chez tous ? Non, à condition de lire la bonne table.** La condition est `effectiveLeague(club.league) !== club.league` (`LeagueMatchWindowRepository.php:38-45`). Le club et le catalogue `league_match_window` parlent la même clé interne (C50) : un club `AURA` ne voit rien si le catalogue porte des lignes `AURA` ; un club `BOFC` voit le bandeau. La mesure rendue (« catalogue = ARA et BFC ») a lu `ffbb_league`, l'annuaire fédéral. **Piège pour `neo`** : comparer à `ffbb_league` allumerait le bandeau chez tous les clubs. À confirmer sur la production par la mesure 11.

**Contrats** : `GET /api/league-match-windows` gagne `leagueFallback: {clubLeague: string|null, referenceLeagueLabel: string} | null` ; `LeagueMatchWindow::FEDERATION_DEFAULT_LEAGUE_LABEL = 'Auvergne-Rhône-Alpes'` (aucun nom lisible de ligue n'existe dans le code : `ClubPage.tsx:404` affiche la clé) ; écran : `LeagueWindowsResponse.leagueFallback`.

**Texte** (marque par `shared/lib/product.ts`) : « {Produit} ne connaît pas les règles de votre ligue ; celles affichées sont celles de la ligue Auvergne-Rhône-Alpes, merci de les vérifier. »

**Section tests pour `tank`** : `LeagueMatchWindowsApiTest`, quatre cas : club `AURA` et catalogue `AURA` (`null`) ; club `BOFC` (repli, `clubLeague: "BOFC"`) ; code illisible (repli, `clubLeague: null`) ; club `AURA` quand l'annuaire fédéral porte `ARA` et le catalogue `AURA` (`null`), le cas qui attrape le piège. Un test Vitest : le bandeau apparaît quand le serveur sert `leagueFallback`.

**Contrôle visuel (`switch`)** : section « Ligue » en repli et au catalogue. Si le club des captures de référence est en repli, re-baseline de « matchs contraintes club ».

### PR 6a · « Une seule porte pour lire où en est un match, aucun comportement ne change » (`neo`, backend seul)

**Change** : tout lecteur et tout écrivain du stade d'un match (à placer, placé, saisi, validé) et d'une question fédérale passe par un service, qui lit encore les colonnes du match. Sont compris la demande au moteur (`MatchPlacementPayloadBuilder.php:468-474`), le retour du moteur (`MatchPlacementResultApplier.php:58-66`), le statut servi (`FixtureResource.php:245`) et le compte `fbiTodo.toEnter` (`EntryDeadlineOutlook.php:158-163`). Aucune migration, aucun champ d'API.

**Contrats**
- `enum App\Enum\FederalState: string { TO_ENTER = 'TO_ENTER'; SUBMITTED = 'SUBMITTED'; VALIDATED = 'VALIDATED' }`.
- `App\Matches\FederalFollowUp` : `stageOf(Fixture $f): FixtureStatus` ; `stateOf(Fixture $f): ?FederalState` ; `isEntered(Fixture $f): bool` ; `markSubmitted(Fixture $f, DateTimeImmutable $now): void`, `markValidated(...)`, `reopen(...)` ; `deviations(Fixture $f): FederalDeviations`, dont les méthodes reprennent celles de `Entity/Fixture.php:398-480`.

**Section tests pour `tank`** : aucun test neuf. Références 5, 6, 16 à 19 à l'octet ; `une-rencontre-importee-dit-si-elle-est-traitee`, `ce-que-fbi-doit-refleter`, `un-domicile-non-place-dont-la-ligue-change-la-salle-est-arbitre`, `un-championnat-commence-valide-seul-ses-matchs-a-domicile`, `une-erreur-fbi-alimente-le-registre` rejouées inchangées.

### PR 6b · « Le suivi fédéral quitte le match » (`neo`, backend et un filtre d'écran)

**Change**
- Migration (§9) : crée `fixture_federal_tracking` avec RLS ; pose la source de chaque compétition ; verse état, écarts, mémo, libellé gardé ; réduit le statut stocké à deux valeurs ; retire trois colonnes. Irréversible.
- Une compétition a une source, `FBI` ou `MANUAL`. Un match est suivi si sa compétition est de source FBI. « Amicaux » est `MANUAL`.
- **Le stade d'un match se calcule : validé si son suivi est « validé », saisi si son suivi est « saisi », sinon son placement.** Pour le moteur : à placer = placement « à placer » ET état fédéral ni saisi ni validé (ou posé par le système, mêmes conditions). Les deux conditions lisent `stageOf` et ne changent pas d'une lettre.
- L'API sert `status = stageOf`, à quatre valeurs : un match validé sans gymnase est servi `VALIDATED`, comme aujourd'hui, et reste absent de la demande.
- Un match non suivi s'arrête à « Placé » : absent de « à saisir dans FBI », plus validé par le balayage, « saisi » et « validé » refusés (fin de C20).
- Dé-placer un match remet son suivi à « à saisir ». Un match saisi ou validé ne quitte plus une compétition FBI pour une compétition hors FBI ; il ne passe pas davantage dans une compétition amicale, même de la fédération (garde de la PR 4a, gardée telle quelle).
- `status` quitte le filtre de recherche de `GET /api/fixtures` (`FixtureResource.php:81`) si aucun appelant n'existe ; sinon il filtre sur le stade.

**Contrats**
- `App\Entity\FederalTracking` (table `fixture_federal_tracking`, `#[Family(Level::SEASON)]`) : `fixtureId` (clé), `clubId`, `seasonId`, `version`, `createdAt`, `updatedAt`, `state: FederalState`, `pendingDeviations: list<PendingDeviation>`, `fbiEcho: ?FbiEcho`, `keptVenueLabel: ?string`. Clé `(fixture_id, club_id)` → `fixture (id, club_id)` `ON DELETE CASCADE`.
- `enum App\Enum\PlacementStatus: string { UNPLACED = 'UNPLACED'; PLACED = 'PLACED' }`, fermé par un CHECK. `Fixture::getPlacement(): PlacementStatus` remplace `getStatus()`.
- `enum App\Enum\CompetitionSource: string { FBI = 'FBI'; MANUAL = 'MANUAL' }` ; `competition.source` obligatoire.
- `GET /api/fixtures` : `status` inchangé ; gagne `fbiTracked: boolean`. `GET /api/competitions` : gagne `source: 'FBI' | 'MANUAL'`.
- `PUT /api/fixtures/{id}` : `status` `SUBMITTED` ou `VALIDATED` sur un match non suivi → `422`.

**Textes**

| Cas | Texte |
|---|---|
| « Saisi » ou « validé » demandé sur un match non suivi | « Ce match n'est pas suivi par la fédération : il s'arrête à « Placé ». » |
| Match saisi ou validé qu'on veut passer dans une compétition hors FBI | « Ce match est déjà saisi dans FBI : il ne peut pas quitter sa compétition. » |

**Section tests pour `tank`**
- Promesse : un match hors FBI s'arrête à « Placé », un match FBI garde tout son suivi. Scénarios du §5, lignes 28 à 33.
- Promesse : la conversion ne change le stade servi d'aucun match d'une compétition FBI. `tests/Integration/Migration/FederalTrackingMigrationTest.php` : un cas par statut d'origine, domicile et extérieur ; saisi sans gymnase et validé sans gymnase ; extérieur saisi ; extérieur validé sans gymnase d'une compétition amicale de la fédération (reste validé) ; extérieur placé sans gymnase ; validé posé par le système ; écart ouvert ; match d'une compétition sans trace fédérale.
- NR isolation (bloquants) : la table entre d'office dans `RlsIsolationTest`, `ReadOnlyRoleTest`, `PurgeCompletenessTest`, `FamilyDeclarationSweepTest` ; un cas dans `MatchTenantIsolationTest`.
- Abus : les deux refus (`FixtureApiTest`) ; le refus de la PR 4a rejoué après la conversion (`EngagedTeamGuardTest`).
- Demande : 5, 6, 16 à 19 à l'octet. La 18 est la preuve du point sensible.
- Écran : `FbiEntryList`, un test : un match placé non suivi n'est pas « à saisir ».

**Contrôle visuel (`switch`)** : liste « à saisir dans FBI » avec un amical posé à la main et un amical de la fédération ; fiche d'un amical placé ; semaine passée portant un amical jamais placé.

## 5. Tableau comportement → scénario (brief de `tank`)

| # | PR | Décision | Scénario |
|---|---|---|---|
| 1 | 1 | Actuel : deux équipes dans une division, chacune ses matchs | `une-rencontre-importee-dit-si-elle-est-traitee.feature`, scénario neuf |
| 2 | 1 | Actuel : « Amical PNF » importé est placé par le système | `placement-des-matchs.feature`, scénario neuf (retourné à la PR 4a) |
| 3 | 1 | Actuel : amical posé à la main, « à saisir » puis validé une fois passé | `un-championnat-commence-valide-seul-ses-matchs-a-domicile.feature`, scénario neuf (retourné à la PR 6b) |
| 4 | 1 | Actuel : une coupe posée un mercredi est « hors fenêtre ligue » | `les-conflits-d-un-match-disent-la-verite.feature`, scénario neuf (retourné à la PR 4b) |
| 5 | 3b | Division neuve à deux équipes : deux compétitions, une par équipe (Q8) ; re-dépôt sans effet | Le scénario 1, rejoué, Exemples |
| 6 | 3b | Deux engagements de même nom : le re-dépôt range chaque match par l'étiquette d'équipe ; sans étiquette, « à faire correspondre » | `FbiFixtureImporterTest` (logique à cas) |
| 7 | 3b | La conversion ne réunit aucune ligne | `CompetitionEngagementMigrationTest` |
| 8 | 3b | Compétition jumelle sans match supprimée ; avec matchs, jamais ; aucun match ne change d'équipe | `FfbbConfirmPerimeterTest` (NR d'axe, inchangé) |
| 9 | 3b | Supprimer une équipe sans match emporte son engagement ; la compétition vidée disparaît | `supprimer-emporte-ce-qui-en-depend.feature`, Exemples |
| 10 | 3b | Un match hors engagement est refusé, à la création comme au changement d'équipe ; une équipe d'un autre club est refusée | `FixtureApiTest`, `MatchTenantIsolationTest` (abus) |
| 11 | 4a | Créer un match sans compétition le range dans « Amicaux » ; modifier un match sans renvoyer sa compétition le laisse où il est ; une saison neuve a « Amicaux » ; après remise à zéro elle renaît | `placement-des-matchs.feature:196`, étape ; `FixtureApiTest` ; `preparer-la-saison-suivante-reprend-la-structure.feature`, étape |
| 12 | 4a | « Amical PNF » de la fédération : jamais proposé au système, placé à la main hors créneau | Le scénario 2, retourné |
| 13 | 4a | Une fenêtre de ligue borne un championnat, pas un amical de la fédération, signalé « sur un créneau de match » | `les-conflits-d-un-match-disent-la-verite.feature:33`, Exemples |
| 14 | 4a | « Coupe … » importée naît coupe ; « Brassage … », brassage ; « Amical … », amicale | `CompetitionClassifierTest` (logique à cas) |
| 15 | 4a | Un match créé à la main, non saisi, passe d'un championnat à « Amicaux » et revient | `placement-des-matchs.feature`, scénario neuf |
| 16 | 4a | Supprimer une équipe engagée dans « Amicaux » laisse « Amicaux » | `supprimer-emporte-ce-qui-en-depend.feature`, Exemples |
| 17 | 4a | Une équipe qui n'a que des amicaux se supprime et change de niveau ; ses amicaux et leurs entrées « à corriger » partent avec elle ; la confirmation annonce les matchs, pas d'engagement | `EngagedTeamGuardTest` (NR d'axe, bloquant) ; `supprimer-emporte-ce-qui-en-depend.feature`, Exemples |
| 18 | 4a | Championnat, coupe, brassage engagent, même non placés ; un match sans compétition retrouvable engage | `EngagedTeamGuardTest` |
| 19 | 4a | Un match connu de la fédération ne change pas de compétition vers une amicale ; le renvoyer tel quel passe | `EngagedTeamGuardTest` (abus) |
| 20 | 4a | Une compétition existante ne devient jamais amicale, par l'appariement ni par l'import | `FfbbConfirmPerimeterTest`, `FbiFixtureImporterTest` (abus) |
| 21 | 4a | (retirée : sans objet depuis le plan du lot 5, « Charger » ne détruit plus de match) | — |
| 22 | 4b | Coupe : mercredi 20:00 rien ; samedi 21:30 et dimanche 07:45 « coupe hors horaires » ; samedi → dimanche rien ; championnat mercredi toujours « hors fenêtre ligue » | Le scénario 4, retourné, Exemples |
| 23 | 4b | Une coupe hors accès match du gymnase reste refusée | `FixtureApiTest` ; `les-conflits-d-un-match-disent-la-verite.feature:112`, rejoué |
| 24 | 4b | Un « hors fenêtre ligue » traité sur une coupe disparaît ; le conflit neuf naît « à traiter » | `un-conflit-traite-reste-visible-mais-decompte.feature`, étape |
| 25 | 4b | Une coupe passée au dimanche revient au samedi si le fichier le dit et qu'elle n'est pas placée ; placée hors semaine en cours, l'écart est à trancher | `une-rencontre-importee-dit-si-elle-est-traitee.feature`, Exemples |
| 26 | 4b | « Placer » ne propose pas une coupe ; celle posée par le système ne bouge pas | `placement-des-matchs.feature`, scénario neuf ; référence 19 |
| 27 | 5 | Ligue hors catalogue : le repli est servi ; au catalogue : rien, même si l'annuaire fédéral porte une autre clé | `LeagueMatchWindowsApiTest` ; un test Vitest |
| 28 | 6b | Amical posé à la main et placé : absent de « à saisir », reste « Placé » | Le scénario 3, retourné |
| 29 | 6b | Amical de la fédération : « à saisir », puis validé seul une fois passé | `un-championnat-commence-valide-seul-ses-matchs-a-domicile.feature:59`, rejoué |
| 30 | 6b | Match créé à la main dans un championnat FBI : suivi, « à saisir » puis « saisi » | Même feature, scénario neuf |
| 31 | 6b | Dé-placer un match saisi le remet « à placer » et « à saisir » | Même feature, étape |
| 32 | 6b | Un match validé sans gymnase n'est jamais proposé au système | Référence 18 ; `FederalTrackingMigrationTest` |
| 33 | 6b | « Saisi » refusé sur un match non suivi ; changement de compétition refusé une fois saisi | `FixtureApiTest` (abus) |
| 34 | 3a, 6a | Aucun comportement ne change | Suites nommées, rejouées inchangées |
| 35 | 3a à 6b | La demande de placement ne change pas, sauf la 17 à la PR 4a et la 19 à la PR 4b (Q7) | `EngineRequestReferenceTest` |

**Preuve = captures, sans test** : libellés du formulaire de match, bandeau de ligue, indications de coupe du panneau, libellé « Coupe hors horaires », lignes de la confirmation de suppression.

## 6. Carte des conséquences

| Comportement changé | Qui écrit | Qui lit | Garde avant | Garde après |
|---|---|---|---|---|
| L'équipe d'une compétition vit sur l'engagement | `CompetitionRegistry` : import (`FbiFixtureImporter.php:1667`), appariement (`FfbbEngagementsController.php:287`), canal API (`FfbbRencontreReconciler.php:480`), `POST /api/competitions` | Résolveur (`:1756`), gardes (`FbiMappingGuards.php:66-73`, `:120-121`), radar (`ConflictRadarLoader.php:174-196`, `MatchConflictDetector.php:817-872`), validation de ligue, échéances ; écran : C38, `FixtureFormDialog.tsx:37` | Rôle gestionnaire et module matchs (`CompetitionResource.php:34`) ; équipe du club (neuve, C30) | Clé étrangère ; unicité (compétition, équipe) |
| Un match exige l'engagement de son équipe (3b) | `FixtureStateProcessor.php:105-145`, `:152-228` ; import ; canal API | La clé composée | `assertCompetitionInScope` (`:390-398`), puis le refus 422 neuf | Contrôle d'accès match (`:306-341`) |
| Le re-dépôt choisit par l'étiquette (3b) | `resolveDivision` | `FbiFixtureImporter.php:164`, `:347`, `:1226`, `:1628` | — | Division non résolue → « à faire correspondre » (`:355-357`) |
| La nature décide de l'amical (4a) | Classifieur, à la création ; migration | `MatchPlacementPayloadBuilder.php:466` ; radar (`RuleWindowConflicts.php:54`, `:110`, `VenueConflicts.php:105`, `MatchConflictDetector.php:705-717`) ; `FixtureStateProcessor.php:307` ; `FriendlyAutoValidator.php:67` ; `LeagueValidationOutlook.php:292`, `:372` ; `EntryDeadlineOutlook.php:223-225` ; `MatchesExportDataProvider.php:81` ; écran par `fixture.competitionType` : `PlacementPanel.tsx:179`, `CalendarPage.tsx:314`, `consultFilter.ts:54`, `lib/matchAccess.ts:153-198` | Refus du changement vers une amicale (Q9) | Le moteur ne reçoit jamais un amical à placer |
| Une compétition existante ne change pas vers ou depuis « amical » (4a) | `CompetitionClassifier::reclassOf` | `FfbbEngagementsController.php:478-483`, `:495-506` ; `FbiFixtureImporter.php:1706-1711` | — | Le périmètre engagé et la demande de placement ne bougent pas par un reclassement |
| « Amicaux » existe et survit (4a) | `FriendlyCompetitions` ; naissances de saison (C25) | Formulaire de match ; `pruneEmpty` ; `CascadePlan.php:74-75` ; remise à zéro (C37) ; éditeur d'échéances et sélecteur de phase (exclue) | — | Unique partiel ; exclusion de `pruneEmpty` |
| Seul le non-amical engage (4a) | `TeamEngagementGuard.php:48-54`, `:72-91` | Suppression (`TeamStateProcessor.php:63`), niveau (`:160`), `isEngaged` servi (`:229`, `TeamStateProvider.php:56`) lu par `wizard/steps/TeamsStep.tsx:113`, `:932` ; décompte (`DeletionImpactCounter.php:46`) | Rôle gestionnaire | La cascade emporte matchs amicaux, entrées de registre et engagement |
| La cascade d'équipe supprime des matchs pour de bon (4a) | `CascadePlan.php:74` (étape des matchs), précédée de `TeamFixtureCorrectionStep` | Registre « à corriger dans FBI » : `FbiCorrectionController.php:59` ; compte `EntryDeadlineOutlook.php:168` ; suivi fédéral après la PR 6b (clé `ON DELETE CASCADE`) ; `FamilyCatalog` par `FbiCorrection.php:70` | Garde du périmètre engagé | `PurgeCompletenessTest`, `FamilyDeclarationSweepTest` |
| La coupe n'a plus de fenêtre de ligue, elle a 08:00–21:00 (4b) | `CupKickoffRule` (constante) | Radar (`RuleWindowConflicts.php:54`) ; empreinte (`ConflictFingerprinter.php:73`) ; écran : `lib/envelope.ts:62-96`, `PlacementPanel.tsx:86-109`, `CalendarPage.tsx:311-323`, `WeekWorkbench.tsx:194`, `ConflictLine.tsx:63`, `:147`, `lib/diagnostic.ts:37-41`, `lib/conflictLabels.ts:21` | Aucune : la pose n'est pas refusée | Radar ; statuts de traitement par empreinte (`FixtureConflictsController.php:85-98`) ; l'import garde la main sur la date (C58) |
| Une coupe n'est plus confiée au système (4b, Q7) | — | `MatchPlacementPayloadBuilder.php:466-474`, `:221`, `:533` ; `MatchPlacementResultApplier.php:58-66` ; `PlaceMatchesController.php:177` | — | Le moteur ne reçoit jamais une coupe à placer |
| Le stade d'un match se calcule (6b) | `FederalFollowUp` | `MatchPlacementPayloadBuilder.php:468-474`, `:497-499` ; `MatchPlacementResultApplier.php:58-66` ; `FixtureResource.php:245` ; `FbiEntryList.tsx:106` ; `lib/loopSteps.ts:133` ; `EntryDeadlineOutlook.php:158-163` ; `FriendlyAutoValidator` ; `LeagueValidationOutlook.php:229-233` ; `PlaceMatchesController.php:117-124` ; `FixtureStateProcessor.php:199-205` ; l'import (`FbiFixtureImporter.php:1432`) | Rôle, saison écrivable | Refus 422 |
| Le repli de ligue est dit (5) | Rien | `ConstraintsPage.tsx:150` | — | — |

À dire à `neo` : `make -C backend rector` avant push ; redémarrer `messenger-worker` puis `nginx` en dernier ; rebâtir `frontend-tooling` avant les tests d'écran ; exporter `openapi-snapshot.json` ; les textes neufs vont dans `features/matches/texts.ts` ; les refus 422 passent par `$this->refuse(...)` ; le bandeau est un `NoticeBanner` ; **le refus de Q9 compare la compétition d'avant et celle demandée** (`FixtureStateProcessor.php:161`), jamais la seule valeur reçue.

## 7. Parcours de bout en bout

1. **Importer une division neuve jouée par deux équipes.** Matchs › Importer, dépôt du fichier FBI, dialogue de correspondance (`ImportFbiDialog.tsx:143-154`) : « DF2 » apparaît deux fois, une par libellé d'équipe. Deux compétitions « DF2 », comme aujourd'hui (Q8), deux lignes d'échéance. **Re-dépôt d'un fichier qui ne porte qu'une des deux équipes** : ses matchs retrouvent leur engagement par le libellé. Geste inverse : aucun geste ne défait une correspondance (`FbiMappingGuards.php:33-34`) ; l'engagement part avec l'équipe si elle n'a aucun match.
2. **Re-déposer chez un club qui a deux compétitions de même nom** (club C, « DMU11-3 »). Le fichier ne porte qu'une des deux équipes : si la ligne a son libellé FBI stocké, ses matchs s'y rangent ; sinon la division revient « à faire correspondre » une fois, le gestionnaire choisit l'équipe, le libellé est mémorisé (`FbiFixtureImporter.php:1720-1722`). État atteignable seulement par ces données anciennes ; le libellé stocké de ces 4 lignes n'est pas mesuré (mesure 20).
3. **Poser un amical à la main.** Matchs › Calendrier, « Ajouter un match » (`FixtureFormDialog.tsx:103-111`) : « Amicaux » en premier, puis les compétitions de l'équipe. Une fois placé : « Placé », absent de « à saisir dans FBI » (PR 6b). Geste inverse : modifier le match et choisir un championnat de l'équipe. Le retour vers « Amicaux » est accepté pour un match créé à la main et non saisi ; refusé pour un match importé ou déjà saisi : « Ce match est connu de la fédération : il reste dans sa compétition. »
4. **Placer à la main un amical de la fédération** (« AMICAL PNM »). Le gestionnaire choisit gymnase et heure ; l'écran renvoie la compétition du match (C54) ; la pose passe, parce que la compétition ne change pas.
5. **Supprimer une équipe qui n'a que des amicaux.** Wizard › Équipes : le bouton n'est plus désactivé (`TeamsStep.tsx:113`) ; la confirmation annonce « 1 match », sans ligne d'engagement ; la suppression emporte le match et ce qu'il restait à corriger dans FBI pour lui. Variante : l'équipe porte aussi un match de coupe non placé : refus, « Cette équipe joue en compétition : ses matchs sont engagés auprès de la fédération. Elle ne peut plus être supprimée. » Geste inverse : aucun.
6. **« Charger » une version d'avant cette équipe.** Plus de refus (ex-Q10) : depuis le lot 5, « Charger » ne supprime pas l'équipe ; elle garde sa ligne et ses matchs.
7. **Lancer le placement avec un « Amical PNF » non placé.** « Placer » (`PlaceMatchesController.php:102`) : il n'est pas proposé au système ; placé à la main hors créneau il affiche « Amical hors créneau match — placement libre. » (`lib/matchAccess.ts:180`). Variante : déjà posé par le système, il reste où il est.
8. **Une coupe tirée un samedi.** Le panneau dit « Coupe : samedi ou dimanche au choix, coup d'envoi entre 08:00 et 21:00. » Pour la jouer le dimanche : « Modifier le match », champ Date (`FixtureFormDialog.tsx:86`), puis gymnase et heure. À 21:30 : « Coupe : coup d'envoi hors 08:00–21:00. », la pose passe, l'onglet Conflits affiche « Coupe hors horaires ». Geste inverse : remettre la date au samedi, ou « à placer ». Variante : le gymnase n'a pas d'accès match ce jour-là : refus, comme aujourd'hui. **Variante, le dépôt suivant** : si le fichier FBI porte encore le samedi, la coupe non placée revient au samedi (« re-programmé du … au … ») ; placée, l'écart de date est proposé à trancher, sauf dans la semaine du match, où le fichier s'applique d'office. Le gestionnaire garde donc son dimanche en le saisissant dans FBI, ou en gardant la valeur de l'application à l'écart. La date que FBI exporte pour une coupe de week-end n'est pas vérifiée.
9. **Une coupe tirée un mercredi.** Le panneau dit « Coupe : date imposée, coup d'envoi entre 08:00 et 21:00. » ; à 20:00, aucun conflit. « Placer » ne la propose pas (Q7) : le gestionnaire choisit gymnase et heure à la main.
10. **Lire les règles de ligue d'un club hors catalogue.** Matchs › Contraintes, section « Ligue » (`ConstraintsPage.tsx:88`) : le bandeau s'affiche au-dessus du tableau, qui reste modifiable. Club `AURA` : pas de bandeau.
11. **Poser une échéance.** Éditeur d'échéances (`EntryDeadlinesEditor.tsx:88`) : une ligne par compétition ; « Amicaux » n'y figure pas. Geste inverse : effacer l'échéance.
12. **Marquer « saisi », puis dé-placer.** Liste FBI, « Marquer saisi », puis retour « à placer » : le match revient à « à placer » et « à saisir ». Variante : un match validé dont le gymnase a disparu reste « validé » et n'est pas proposé au système.

Variantes jouées à chaque PR : domicile et extérieur ; championnat, coupe, amical d'office, amical de la fédération ; importé par fichier, venu du canal API, posé à la main ; week-end et semaine ; équipe avec et sans fenêtre de ligue.

## 8. Preuves

**Ce que « à l'octet » prouve.** La demande de placement ne transporte aucun identifiant de compétition (`MatchPlacementPayloadBuilder.php:466`). La preuve vaut pour les PR 4a, 4b et 6b. **Elle ne voit pas le risque de la PR 3b**, qui est à l'import et au radar.

| Preuve | PR | Ce qu'elle couvre |
|---|---|---|
| `EngineRequestReferenceTest` (bloquant, `ci.yml:1445`), références 5, 6, 16 à 19, témoins de décor vérifiés | Toutes | À l'octet partout ; la 17 change à la PR 4a, la 19 à la PR 4b (Q7), rien d'autre |
| Scénarios d'import, `FfbbConfirmPerimeterTest`, tests de migration | 3b, 4a, 6b | Le risque que les octets ne voient pas |
| `EngagedTeamGuardTest` (bloquant, `ci.yml:771`) | 4a | Le périmètre engagé, falsifié dans les deux sens, et ses contournements |
| Copie de la production, à chaque migration (3b, 4a, 6b) et à la PR 4b, puis l'ensemble à la copie finale | 3b, 4a, 4b, 6b | Demandes ET vues servies, club par club |

**Sur une copie de la production** (`lot-conception-filet.md` §7, accord du fondateur à chaque restauration, « avant » et « après » le même jour).

| PR | `requests/` | `views/…/fixtures.json` | `views/…/conflicts.json` |
|---|---|---|---|
| 3b | Vide | Vide (aucun match repointé) | Vide |
| 4a | **Vide attendu** : 8 matchs amicaux, tous passés | `competitionType` apparaît | Pour les domiciles des 5 « Amical … » : fin des conflits de règle, apparition éventuelle de « amical sur un créneau de match » |
| 4b | Les seuls domiciles de coupe (mesure 17), les 5 coupes reclassées comprises | Vide | Pour les seuls matchs de coupe : fin de « hors fenêtre ligue », apparition éventuelle de « coupe hors horaires » |
| 6b | Vide | `status` identique pour tout match d'une compétition FBI ; `fbiTracked` apparaît | Identique |

Toute différence hors de ces listes arrête le lot. Limite de l'outil : le placement n'est enregistré que s'il reste un match à placer ; les vues le sont toujours.

## 9. Conversion et mesures

Trois migrations irréversibles (PR 3b, 4a, 6b). Chacune écrit son décompte, repris par `docs/ops/deploy.md`.

- **PR 3b** : une ligne d'engagement par ligne de compétition. Arrêts : match dont l'équipe n'est pas celle de sa compétition ; deux noms d'une même équipe égaux après normalisation. Décompte attendu : 150 compétitions, 150 engagements, 677 matchs, 0 match repointé.
- **PR 4a** : nature amicale sur les 5 compétitions dont le premier mot est « amical » ; nature coupe sur les 5 coupes rangées en championnat ; une compétition « Amicaux » par saison ; matchs sans compétition versés dedans avec l'engagement de leur équipe.
- **PR 4b** : aucune migration.
- **PR 6b** : source FBI si la compétition a un identifiant fédéral ou un match venu de la fédération, sinon `MANUAL` ; une ligne de suivi par match d'une compétition FBI. « À placer » reste « à placer », « placé » reste « placé » ; « saisi » et « validé » deviennent « placé » si gymnase et heure, sinon « à placer », l'état fédéral gardant « saisi » ou « validé ».

**Mesures rendues le 2026-11-23** (4 clubs réels, démonstrations exclues)

| Mesure | Valeur | Ce qu'elle décide |
|---|---|---|
| Compétitions et matchs | 150 et 677 | Décompte de la PR 3b |
| Groupes de même identifiant fédéral | 0 | §27 tient |
| Groupes de même nom | 2 (club C, deux noms de division portés chacun par deux lignes, 4 lignes sans identifiant fédéral, poules distinctes) | Q5 : rien n'est réuni |
| Compétitions « Amical … » | 5 (club A) | Conversion de la PR 4a |
| Matchs amicaux | 8, tous passés | Q6 : effet nul sur la demande |
| Coupes rangées en championnat | 5, sans journées attendues ni alerte ouverte | Q2 : reclassement |
| Équipes dans au moins deux compétitions non amicales | 50 sur 60, 6 au plus | E1 |
| Extérieurs amicaux validés sans gymnase | 5 (club A) | Référence 18 ; cas de `FederalTrackingMigrationTest` |
| Statuts de traitement sur « compétition incomplète » | 0 | Réécriture d'empreinte sans effet |
| Ligues | Clubs `AURA` et `BOFC` ; la table lue était l'annuaire fédéral | À refaire (mesure 11) |

**Mesures encore à faire par `link`**, avant la PR nommée, puis à chaque copie de production :

- 4 · Matchs dont l'équipe n'est pas celle de leur compétition, par club (attendu 0 ; sinon arrêt de la PR 3b).
- 7 · Matchs sans compétition, par statut (attendu 0).
- 8 · Compétitions sans aucune trace fédérale qui portent des matchs saisis ou validés. Si l'une des 5 « Amical … » est dans ce cas, ses extérieurs validés repasseraient « à placer » à la PR 6b : arrêt et retour au fondateur.
- 11 · `SELECT league, COUNT(*) FROM league_match_window GROUP BY league`, et la ligue de chaque club.
- 16 · Équipes dont tous les matchs sont amicaux : celles que Q3 rend supprimables.
- 17 · Domiciles de coupe non placés ou posés par le système, passés et futurs, par club, les 5 coupes reclassées comprises, en distinguant les équipes avec et sans fenêtre de ligue : le chiffre de Q7.
- 18 · Statuts de traitement posés sur un « hors fenêtre ligue » d'un match de coupe.
- 19 · Lignes d'une même équipe dont les noms ne diffèrent que par la casse ou les accents (attendu 0).
- 20 · Libellé FBI stocké ou non sur les 4 lignes du club C.
- 21 · Entrées ouvertes « à corriger dans FBI » portées par un match amical.
- 22 · Clubs portant des matchs dans plus d'une saison (attendu 0 ; sinon arrêt : les vues enregistrées ne portent que la saison courante, il faut alors des vues par saison sous `views/club-<id>/season-<s>/`, voir §4 PR 1).

## 10. Checklist de cadrage

- **Zones** : backend, frontend. **Engine : aucun fichier.** Landing, system-pages : aucun.
- **Autorisés** : `backend/src/{Matches,Entity,Enum,Repository,Service,Service/Basketball,Service/Fbi,Service/Conflicts,Controller,Controller/Basketball,ApiResource,Dto,State,Export,Deletion,OpenApi,Seed}`, `backend/src/Command/EngineRequestsRecordCommand.php`, `backend/src/Service/TeamEngagementGuard.php` (PR 4a seulement), `backend/migrations`, `backend/tests`, `backend/features`, `frontend/src/features/matches`, `frontend/tests/e2e/matches.spec.ts`.
- **Interdits** : `engine/`, `engine/CONTRACT_VERSION`, `Service/EngineClient.php`, `Service/EngineRequestComposer.php`, `Service/ScheduleConstraintBuilder.php`, `MessageHandler/PlaceMatchesHandler.php`, `Service/MatchPlacementLock.php`, `EventListener/TenantFilterListener.php`, `Doctrine/Filter/`, `Access/`, `security.yaml`, `State/Processor/TeamStateProcessor.php`, `Service/Fbi/FbiArrivalReview.php` et la règle de date de l'import (`FbiFixtureImporter.php:1428-1485`), le Wizard (`TeamsStep.tsx` lit `isEngaged`, non modifié), `landing/`. Aucune route nouvelle.
- **Axes §7.1** : isolation entre clubs (3b, 6b) ; **périmètre engagé (3b, 4a : la règle change)** ; contrat backend-moteur et chaîne de génération (3a à 6b ; demande modifiée à 4a et à 4b) ; sens des contraintes (4a, 4b). NR nommés par PR, tous dans des tests déjà au step bloquant (C28), sauf un : la PR 4b ajoute `ConflictRadarLeagueWindowTest` au step bloquant (écarts de la PR 4b). Les lignes `blocking-tests.md:190` et `:191` sont réécrites à la PR 4a.
- **Contre-revue** : `smith` est passé sur la v1 et sur la v3 ; cette v4 intègre ses deux verdicts, puis les décisions du fondateur du 2026-11-23. Plafond atteint : pas de v5.
- **`/security-review`** : systématique sur les PR 3b, 4a, 4b et 6b. Sujets : isolation entre clubs (deux tables neuves, RLS, clés composées, identifiants forgés) ; purge et RGPD (registre « à corriger », déclaration `OnDelete`) ; données de la fédération ; données lues par la demande de placement ; périmètre engagé contourné par changement de compétition ou par reclassement. PR 1, 2, 3a, 5 et 6a : hors liste.
- **Tests d'abus** : nommés par PR (3b : quatre ; 4a : six, dont trois du périmètre et deux du reclassement ; 6b : trois). Pas de cadrage `oracle`, donc pas de section « Signaux » héritée.
- **Behat de la zone** : `placement-des-matchs`, `les-conflits-d-un-match-disent-la-verite`, `un-conflit-traite-reste-visible-mais-decompte`, `une-rencontre-importee-dit-si-elle-est-traitee`, `un-championnat-commence-valide-seul-ses-matchs-a-domicile`, `ce-que-fbi-doit-refleter`, `un-domicile-importe-retrouve-son-gymnase`, `un-domicile-non-place-dont-la-ligue-change-la-salle-est-arbitre`, `une-erreur-fbi-alimente-le-registre`, `l-export-des-matchs-pour-les-parents`, `les-matchs-attendent-le-planning-de-saison`, `supprimer-emporte-ce-qui-en-depend`, `preparer-la-saison-suivante-reprend-la-structure`. Aucune feature neuve.
- **e2e** : `matches.spec.ts` et `a11y-contrast.spec.ts` (pose son propre amical par `POST` sans compétition : reste valable) rejoués aux PR 3b, 4a, 4b ; `visual-reference.spec.ts` selon le contrôle visuel ; `member-read-only.spec.ts` inchangé.
- **Plafonds de temps** (`test-time-ceiling.json` : backend 1000 s, Behat 950 s, e2e 635 s, frontend 230 s) : rien n'est mesuré, aucun chiffre avancé. Charge : neuf à dix scénarios Behat neufs, quatre retournés, des lignes d'Exemples ; trois tests de migration ; quatre références ; quatre tests d'intégration. Mesure par `make ci-mesure PART=origin/lot/conception` à chaque PR. Leviers : Exemples d'un même scénario, décor en une insertion. Repli si Behat ne tient pas : les lignes 15, 24 et 25 descendent en tests d'API.
- **Documentation** : fiche du lot 7 et §6 du découpage ; `lot-conception-decoupage.md` règle 4 (exception des amicaux, Q6 ; exception des coupes, Q7) ; `conception-cible.md` §18 (règle des coupes, « seul le non-amical engage », « une compétition ne devient jamais amicale ») ; `conception-cible.md` §27 non modifié ; `etat-des-lieux.md` ; `module-matchs-modele-donnees.md`, `module-matchs-import-fbi.md`, `module-matchs-radar-et-placement.md`, `module-matchs-perimetre-engage.md`, `module-matchs-reglages.md`, `module-matchs-consultation.md`, `module-matchs-tests.md` ; `backend/docs/{ffbb-api,backend-inventory,commands}.md` ; `lot-conception-filet.md` §7 ; `docs/glossary.md` ; `docs/security/rls.md` ; `docs/testing/{test-coverage-map,blocking-tests}.md` ; un ADR « modèle de la compétition » et `adr-index.md` ; `docs/ops/deploy.md` ; une ligne de roadmap pour E1 (sur le go du fondateur).
- **Retour au fondateur si** : une mesure sort de l'attendu ; une migration s'arrête ; une référence diffère hors du changement déclaré ; la comparaison sur copie de production montre une différence hors liste ; une règle exigerait de toucher au moteur ; une route s'ajoute ; un plafond ne tient pas.
- **Aucun refactoring hors scope.** Laissés de côté : `FbiIngestion`, la table des statuts de traitement recopiée à l'écran, les habitudes et règles de match du club (lot 9), les exports et envois (lot 10), l'importeur hors des points nommés, la résorption des jumelles (C10), la règle de date de l'import (C58).

## 11. Limites écrites

- Deux compétitions d'une même équipe ne peuvent pas avoir des règles différentes, hors la borne des coupes (E1).
- Les deux groupes de même nom du club C restent deux compétitions chacun ; aucun écran ne les réunit.
- La séparation « compétition et engagement » ne se voit que sur « Amicaux » (Q8).
- « Pas deux compétitions de même nom pour une même équipe » n'est plus garanti par la base, mais par la porte unique et son test.
- **Le périmètre engagé ne ferme que la cascade et le reclassement.** Supprimer ses matchs un par un, puis l'équipe, reste possible, comme aujourd'hui (C44).
- Le jour d'une coupe n'est jamais contrôlé par le radar : l'application ne connaît pas la date du tirage en dehors de la date du match.
- **Le choix samedi ou dimanche d'une coupe ne tient que si FBI le porte** : un dépôt le défait sur une coupe non placée, et dans la semaine du match sur une coupe placée (C58).
- Après « Placer », une coupe reste dans la liste « à placer » sans motif affiché, comme un amical (Q7).
- Le bouton « Rendre au système » déborde de quelques pixels dans la carte du panneau d'un match posé (`PlacementPanel.tsx`) : observé à la PR 4b, antérieur à elle.
- Le badge « nouveau conflit » peut s'allumer une fois après la PR 4b (coupe hors horaires) : la référence de visite n'est pas convertie.
- Une compétition « Amical … » déjà placée par le système garde son créneau, sans contrôle de fenêtre.
- Un amical posé à la main puis déclaré dans FBI arrive une seconde fois par l'import ; c'est déjà le cas.
- Le bandeau de repli de ligue ne disparaît pas quand le gestionnaire a vérifié ses fenêtres.
- Aucun écran ne crée une compétition ni ne défait une correspondance d'import.
- Un amical posé à la main, jamais placé et passé, reste « à placer » dans sa semaine passée après la PR 6b.
- Une compétition créée par erreur avec la mauvaise nature (amicale ou non) ne se corrige pas : aucune écriture ne franchit cette frontière après la création.

## 12. Tranché par le plan (contestable)

- Le match garde `team_id` et `competition_id` ; l'engagement est désigné par le couple.
- Aucune unicité en base sur le nom d'une compétition. L'autre choix : une clé de nom recopiée sur l'engagement, à tenir à chaque renommage.
- Les trois écrivains comparent les noms normalisés. Exemple : « df2 » déposé quand l'équipe a déjà « DF2 » réutilise la compétition.
- La migration de la PR 3b s'arrête plutôt que de fabriquer un engagement.
- Re-dépôt : l'étiquette d'équipe décide ; sinon « à faire correspondre ».
- L'amical se reconnaît au premier mot exact « amical ». Le canal API garde son défaut « coupe ».
- **Une compétition ne devient jamais amicale et ne cesse jamais de l'être**, hors la migration. Exemple : apparier un championnat « PNM » à une ligne fédérale nommée « Amical PNM » ne dégage pas l'équipe.
- **Le doute engage** : un match dont la compétition est introuvable protège son équipe.
- **Un seul champ dit la nature d'un match à l'écran**, `competitionType`. L'autre choix : deux champs calculés (`friendly`, puis `kickoffRule`), soit deux vérités à tenir.
- **Les bornes de la coupe sont un miroir déclaré à l'écran**, gardé par un test de parité, pas un champ servi.
- **« Coupe hors horaires » est un type neuf.** L'autre choix : réutiliser « hors fenêtre ligue », dont le texte parle de ligue et de dérogation, et dont les traitements déjà posés resteraient collés à la règle neuve.
- **« Coupe hors horaires » est de gravité 3, groupe « Règle », alors que « hors fenêtre ligue » est de gravité 2** (`diagnostic.ts:21-25`). Raison : la gravité 2 porte le titre « Hors fenêtre ligue » et appelle une dérogation à demander à la ligue ; une coupe hors horaires est une pose libre à surveiller, comme une règle du club (gravité 3). L'autre choix : gravité 2, avec un titre de groupe à réécrire pour les deux types.
- La borne 08:00–21:00 signale, elle ne refuse pas. Les bornes sont comprises. La borne vaut aussi un jour férié.
- Une coupe obéit toujours aux règles du club, aux gymnases interdits et aux accès match. Exemple : si le club s'interdit les matchs le dimanche après 18:00, une coupe posée le dimanche à 19:00 est signalée par la règle du club.
- Les statuts de traitement des anciens conflits de coupe ne sont ni réécrits ni supprimés ; ils deviennent invisibles.
- **La règle de date de l'import n'est pas modifiée pour les coupes.**
- Une équipe qui n'a que des amicaux emporte ses amicaux à la suppression, annoncés dans la confirmation ; **son engagement dans une compétition amicale n'y est pas annoncé**.
- Les entrées « à corriger dans FBI » partent avec leur match, dans la cascade aussi.
- Un match hors engagement est refusé (422) ; l'engagement n'est créé au passage que dans une compétition amicale.
- **En modification, une compétition absente ne veut rien dire** : le match reste où il est.
- « Amicaux » est créée par écriture immédiate, n'est jamais balayée, et n'apparaît ni dans l'éditeur d'échéances ni dans le sélecteur de phase.
- Clés étrangères immédiates, pas différées.
- Un match est suivi par la fédération selon la source de sa compétition.
- Conversion du statut à la PR 6b : le stade servi d'un match suivi ne change pas.
- Un amical posé à la main, jamais placé et passé, reste « à placer » (aujourd'hui il passe « validé » seul).
- Le filtre `?status=` de `GET /api/fixtures` disparaît s'il n'a aucun appelant.
- Le bandeau de repli est permanent, ton information ; le nom de la ligue de référence est une constante serveur.
- La PR 1 porte un outil de preuve écrit par `neo`. Références 16 à 19.

## 13. Décisions du fondateur

**Décidé le 2026-11-23** (Q1 à Q6 avant le plan, Q7 à Q10 en le validant)

| Sujet | Décision | Où dans le plan |
|---|---|---|
| Q1 · Règles propres à une compétition | Rien maintenant ; le lot livre le bandeau « ligue inconnue » | E1, PR 5 |
| Q2 · Natures déjà enregistrées | Corrigées : 5 coupes, 5 « Amical … » | PR 4a, §9 |
| Q3 · Périmètre engagé | Seuls les matchs d'une compétition non amicale engagent | PR 4a |
| Q4 · Calendrier | Après le lot 4 | §0 |
| Q5 · Réunir par nom | Non : une compétition par ligne existante | PR 3b, §9 |
| Q6 · Amicaux et placement automatique | Jamais placés par le système ; exception assumée à la règle 4 | PR 4a, E9 |
| Coupes | Plus de fenêtre de ligue ; week-end : samedi ou dimanche au choix ; semaine : date imposée ; coup d'envoi 08:00–21:00 | PR 4b |
| Q7 · « Placer » et les coupes | **Non** : « Placer » ne propose plus les coupes au système ; le fondateur place les coupes à la main ; une coupe déjà placée par le système ne bouge pas. Deuxième exception chiffrée à la règle 4, à côté de Q6 | PR 4b, E10 |
| Q8 · Division neuve jouée par deux équipes | **Deux** : une compétition par équipe, comme aujourd'hui, distinguées par le libellé FBI ; la table des engagements est créée dans tous les cas | PR 3b, E11 |
| Q9 · Match de la fédération rangé en amical à la main | **Non** : « Ce match est connu de la fédération : il reste dans sa compétition. » La garde ne vise que le changement de compétition vers une amicale ; renvoyer la compétition qu'il a déjà passe | PR 4a |
| Q10 · « Charger » et une équipe qui n'a que des amicaux | **Sans objet** depuis le plan du lot 5 (2026-11-24) : « Charger » ne supprime plus d'équipe ; il n'y a plus de refus à écrire ni de texte neuf | — |

**Ce que chaque décision écarte.** Q7 : le système ne connaît pas la règle des coupes (fenêtres de ligue de l'équipe appliquées ; une équipe sans fenêtre de ligue reçoit une coupe de semaine placée après 21:00, aussitôt signalée) et la lui apprendre exigerait de changer ce qu'il reçoit. Q8 : réunir deux équipes sur le seul libellé d'une division neuve ne gagnait qu'une ligne d'échéance de saisie, pour trois écrans changés, un identifiant de conflit réécrit et une règle de renommage. Q9 : la garde ferme le rangement à la main et le reclassement d'une compétition entière, pas la suppression d'un match, qui reste libre comme aujourd'hui (C44) : elle vise l'erreur, pas la volonté. Q10 : sans objet, le lot 5 fait de « Charger » un geste qui ne crée ni ne supprime d'équipe.

## 14. Fichiers clés

Chemins absolus sous `/home/marabou/projects/scheduler/`.

- `backend/src/Entity/Competition.php`, `backend/src/Entity/Fixture.php`, `backend/src/Entity/FbiCorrection.php`, `backend/src/Entity/LeagueMatchWindow.php`
- `backend/src/Enum/CompetitionType.php`, `backend/src/Enum/FixtureStatus.php`, `backend/src/Enum/ConflictResolutionStatus.php`
- `backend/src/Service/FbiFixtureImporter.php`, `backend/src/Service/Fbi/FbiMappingGuards.php`, `backend/src/Service/Fbi/FbiArrivalReview.php` (lu, non modifié)
- `backend/src/Service/Basketball/FbiDivisionSignature.php`, `FfbbRencontreReconciler.php`, `VenueLabelNormalizer.php`
- `backend/src/Controller/Basketball/FfbbEngagementsController.php`
- `backend/src/Service/MatchPlacementPayloadBuilder.php`, `MatchPlacementResultApplier.php`
- `backend/src/Service/ConflictRadarLoader.php`, `MatchConflictDetector.php`, `ConflictFingerprinter.php`
- `backend/src/Service/Conflicts/RuleWindowConflicts.php`, `VenueConflicts.php`
- `backend/src/Service/TeamEngagementGuard.php`, `SeasonDataPurger.php`
- `backend/src/Service/FbiCorrectionLedger.php`, `EntryDeadlineOutlook.php`, `FriendlyAutoValidator.php`, `LeagueValidationOutlook.php`, `LeagueResolver.php`
- `backend/src/Deletion/CascadePlan.php`, `CascadeStep.php`, `DeleteByFieldStep.php`, `DeletionImpactCounter.php`, `backend/src/Structure/FamilyCatalog.php`
- `backend/src/State/Processor/FixtureStateProcessor.php`, `CompetitionStateProcessor.php`, `TeamStateProcessor.php` (lu, non modifié)
- `backend/src/ApiResource/FixtureResource.php`, `CompetitionResource.php`
- `backend/src/Controller/LeagueMatchWindowsController.php`, `FixtureConflictsController.php`, `PlaceMatchesController.php`
- `backend/src/Repository/LeagueMatchWindowRepository.php`, `OpponentDirectoryEntryRepository.php`
- `backend/src/Command/EngineRequestsRecordCommand.php`
- `backend/migrations/Version20261122140000.php`, `Version20260804170000.php`
- `backend/data/league-match-windows.aura.json`
- `backend/tests/Security/EngagedTeamGuardTest.php`, `FfbbConfirmPerimeterTest.php`, `MatchTenantIsolationTest.php`
- `backend/tests/Integration/EngineRequestReferenceTest.php`, `backend/tests/EngineRequestDecorTrait.php`, `backend/tests/Integration/Service/DeletionImpactParityTest.php`
- `.github/workflows/ci.yml` (lu, non modifié), `docs/testing/blocking-tests.md`
- `frontend/src/features/matches/api/fixtures.ts`, `api/competitions.ts`, `api/conflicts.ts`
- `frontend/src/features/matches/lib/envelope.ts`, `lib/matchAccess.ts`, `lib/diagnostic.ts`, `lib/conflictLabels.ts`, `lib/leagueEnvelope.parity.json`, `lib/phaseView.ts`, `lib/consultFilter.ts`
- `frontend/src/features/matches/PlacementPanel.tsx`, `CalendarPage.tsx`, `WeekWorkbench.tsx`, `ConflictLine.tsx`, `FixtureFormDialog.tsx`, `EntryDeadlinesEditor.tsx`, `ConstraintsPage.tsx`, `FbiEntryList.tsx`, `ImportFbiDialog.tsx`
- `frontend/src/features/wizard/steps/TeamsStep.tsx` (lu, non modifié)
- `engine/app/solver/match_placement/candidates.py`, `reasons.py`, `engine/app/schemas/match_input_schema.py` (lus, jamais modifiés)
- `specs/evolution/conception-cible.md` (§18, §27), `specs/evolution/lot-conception-decoupage.md` (règles 1 et 4), `specs/evolution/lot-conception-filet.md` (§7)

## 15. Laissé hors périmètre

Volontairement : les règles propres à une compétition et tout changement du moteur (E1), le contrôle du jour d'une coupe et la règle de date de l'import, la réunion de lignes existantes, la version sans table d'engagement, l'écran de création d'une compétition, la forme commune des signalements (lot 10).