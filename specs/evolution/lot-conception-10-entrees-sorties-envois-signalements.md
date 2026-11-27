# Lot 10 · Entrées, sorties, envois, signalements : plan d'exécution

**Statut : validé par le fondateur le 2026-11-27 (v2, contre-revue de `smith` intégrée ; décisions Q1 à Q3, §12).** Voie **Full**. Branche cible : `lot/conception` ; une PR vers elle ne joue que « Lot Gate » : avant chaque merge, `make -C backend tests-complete`, `/validation-runner`, et les features / specs de la zone sur la stack d'épreuve. Exécutant : `neo`, après les tests de `tank`. Pas d'`oracle` : la conception validée tient lieu de cadrage (décision du fondateur du 2026-11-20, `specs/evolution/lot-conception-decoupage.md` §5.3). La fiche du lot est dans `specs/evolution/lot-conception-decoupage.md`, § Lot 10 ; ce plan dit **comment**.

Les `fichier:ligne` ont été lus le 2026-11-26 sur `lot/conception` (tête `01cc38b18`) ; chaque point de la contre-revue a été relu dans le code avant d'être repris. **Ils sont à relire à l'ouverture de chaque PR** : les lots 5 (PR 4 à 7) et 7 (PR 3a et suivantes) réécrivent encore `Schedule.php`, `PersonEraser.php`, `CalendarEntryStateProcessor.php`, `ImportFixturesController.php`, les fichiers des doléances.

## 0. Ce qui est livré et ce qui ne l'est pas

- Le lot 10 dépend des lots 2 et 3, livrés sur `lot/conception`. Il lit du lot 2 : `#[ClubFunction]`, le contrôle central, `Output`, `OutputCreditLedger` ; du lot 3 : `PersonEraser` ; du lot 1 : `FamilyCatalog`.
- **La mesure du temps de rendu du PDF (réserve de la conception §21) n'a pas été jouée** : `lot-conception-filet.md` §8 est « une procédure à jouer ». Elle est la condition d'entrée de la PR 1.
- Une seule PR ouverte à la fois sur `lot/conception`. Ordre conseillé : PR 1, PR 2, PR 3a, PR 4 ; la PR 3b attend les PR 4 à 7 du lot 5, qui réécrivent la collecte des doléances (`lot-conception-5-le-socle-froid.md:255`, `:273`). Plus aucune PR n'attend le lot 7.

## 1. Besoin reformulé

1. **Exports** : les trois documents (PDF du planning, tableur du planning, PDF des matchs) suivent un seul chemin, « je demande, je reçois le fichier ». La file d'attente du PDF du planning, son état sur la version et le fichier gardé sur le serveur disparaissent.
2. **Imports** : un seul contrôle d'entrée pour les trois imports qui en ont un (équipes par fichier, matchs par fichier, parc), et la preuve que leur écriture est tout ou rien.
3. **Envois** : un seul journal des envois répond à « qu'a reçu ce club, et quand » ; les trois tables de rappel y entrent, puis les trois dates d'envoi d'une campagne de doléances.
4. **Signalements** : les types de diagnostic ont une liste déclarée et gardée ; la gravité « SUCCESS », que rien n'émet, part. Le reste de la forme commune sort du lot (écart E3).

Ambiguïtés identifiées, tranchées par le fondateur (§12) : la règle quand un rappel n'atteint qu'une partie des gestionnaires (Q1) ; le sort des trois préavis de suppression (Q2) ; la décision si la mesure du PDF est limite (Q3).

## 2. Constats vérifiés dans le code

Chemins abrégés : `B/` = `backend/src/`, `F/` = `frontend/src/features/`.

| # | Constat | Où |
|---|---|---|
| C1 | Le PDF du planning passe par la file : le contrôleur écrit « pending » sur la version, met un message en file et répond 202 | `B/Controller/ExportPdfController.php:56-70` ; `backend/config/packages/messenger.yaml:37` |
| C2 | Le worker rend sous un nom temporaire, prend le crédit en mode strict **sur la saison du planning**, renomme, écrit statut, adresse et identifiant de demande sur la version, puis publie sur Mercure | `B/MessageHandler/ExportPdfHandler.php:79-95`, `:109-140` (`:115`), `:154-172` |
| C3 | Exporter écrit donc trois fois la ligne de la version, qui porte un verrou optimiste, y compris pour une version non chargée ou validée | `B/Entity/Schedule.php:34-36`, `:158-169` ; `ExportPdfController.php:34-71` |
| C4 | L'écran demande, puis sonde la version toutes les 1,5 s pendant 60 s au plus, compare un suffixe de nom de fichier, puis télécharge | `F/planning/queries.ts:538-539`, `:566-588` ; `F/planning/lib/exportScopeToken.ts` |
| C5 | Un seul consommateur traite la file, partagée avec la génération : un PDF demandé pendant un calcul attend derrière lui | `docker-compose.prod.yml:213-214` ; `messenger.yaml:36-40` |
| C6 | Le fichier livré reste sur le disque, servi par une route de téléchargement à part, purgé à 90 jours ou quand son planning n'existe plus | `B/Controller/ExportPdfDownloadController.php:51-94` ; `B/Command/PurgeExportsCommand.php:51`, `:90-118` |
| C7 | Aucun écran ne retélécharge un PDF déjà produit : l'adresse n'est lue que par la boucle de sondage | `F/planning/queries.ts:581-583` (recherche sur `frontend/src`) |
| C8 | Le champ d'export de la saison n'a aucun écrivain ; il est servi par l'API, lu par la purge comme « épinglé », vidé par l'effacement d'une personne | `B/Entity/Season.php:51` ; `B/ApiResource/SeasonResource.php:59`, `:76` ; `PurgeExportsCommand.php:156` ; `B/Structure/PersonEraser.php:241-244` |
| C9 | Le tableur et le PDF des matchs rendent le fichier dans la réponse ; leur crédit se prend à la réponse 2xx, en mode non strict, sur la saison de la requête | `B/Controller/ExportXlsxController.php:56-80` ; `ExportMatchesPdfController.php:89-101` ; `B/Access/ClubAccessListener.php:167-195` ; `B/Access/Output.php:18-24` |
| C10 | Le PDF des matchs passe déjà par le même outil de rendu : fichier au nom aléatoire, lu puis supprimé | `B/Service/MatchesPdfGenerator.php:34-48` |
| C11 | L'appel à l'outil de rendu est coupé à 30 s, en file comme en direct ; l'outil lance un navigateur par demande, sans file interne | `B/Service/PdfGenerator.php:213-216` ; `frontend/worker.js:33-53`, `:167-203` |
| C12 | Une requête est coupée à 120 s et plafonnée à 192 Mo ; le serveur n'a que 4 processus ; le worker de file, lui, dispose de 512 Mo | `docker/php/Dockerfile:171-176`, `:186`, `:190` |
| C13 | L'effacement d'une personne supprime les PDF gardés et remet à zéro l'état d'export des versions | `PersonEraser.php:176-181`, `:235-240` |
| C14 | Le texte « crédits épuisés » est écrit dans le contrôle central | `ClubAccessListener.php:160-162` |
| C15 | Trois classes de contrôle d'entrée d'import, même suite (pas d'adhésion 404, Membre 403, saison archivée 409, club 404, plafond 429), trois différences : la condition de socle (aucune, en vigueur, PAS en vigueur), le club lu dans le chemin ou dans le jeton, le plafond | `B/Service/TeamImportGate.php:46-81` ; `B/Service/FixtureImportGate.php:45-86` (`:70`) ; `B/Service/ParcImport/ParcImportGate.php:52-88` (`:74`) |
| C16 | Le texte du 429 diffère : « Trop d'imports — réessayez plus tard. » et « Trop d'analyses de parc — réessayez plus tard. » | `TeamImportGate.php:77` ; `FixtureImportGate.php:82` ; `ParcImportGate.php:84` |
| C17 | Six contrôleurs appellent ces classes (aperçu et écriture de chaque import) | `ImportController.php:44-52` ; `ImportTeamsAnalyzeController.php:48-56` ; `ImportFixturesController.php:84-88` ; `ImportFixturesAnalyzeController.php:54-58` ; `ParcImportExecuteController.php:56` ; `ParcImportAnalyzeController.php:50` |
| C18 | Les deux imports par la liaison fédérale n'ont pas de classe de contrôle, et **relisent la fédération pendant l'écriture** | `B/Controller/Basketball/FfbbRencontresController.php:57`, `:107-110` ; `B/Service/Basketball/FfbbRencontreReconciler.php:126-130` ; `FfbbEngagementsController.php:223-227`, `:242-247` |
| C19 | Écriture des imports : équipes par fichier, un seul flush ; parc, une transaction ; liaison fédérale, un seul flush chacun ; **matchs par fichier, deux flushs** : celui des correspondances et compétitions (`persistMappings`, appelé avant le reste), puis le principal. Un échec entre les deux laisse des compétitions créées. L'import par fichier n'appelle pas la fédération | `FfbbExcelImporter.php:192` ; `ParcImportExecutor.php:201` ; `FfbbRencontreReconciler.php:235` ; `FfbbEngagementsController.php:366` ; `B/Service/FbiFixtureImporter.php:307`, `:1667`, `:1741`, `:453` |
| C20 | Trois tables de rappel de même forme (sujet, palier, date), sans `club_id` ni RLS | `B/Entity/PeriodReminderLog.php`, `TransitionReminderLog.php`, `HolidayReminderLog.php` (en-têtes) ; `docs/security/rls.md:294` (« résiduel assumé ») ; `backend/tests/Security/ReadOnlyRoleTest.php:99-101` |
| C21 | La règle « noté envoyé » diverge : rappel de période et de vacances dès qu'un gestionnaire est atteint ; rappel de bascule seulement si tous le sont | `B/Command/PeriodReminderCommand.php:205-209`, `:235` ; `B/Command/TransitionReminderCommand.php:185-190` |
| C22 | Purge des trois tables : **à la suppression** de l'entrée de calendrier (re-dater une période ne purge rien) ; par la saison, à la main ; jamais | `B/State/Processor/CalendarEntryStateProcessor.php:1037-1040` (dans la suppression, `:1022`) ; `B/Service/SeasonDataPurger.php:78-85` ; `B/Structure/FamilyCatalog.php:53` |
| C23 | Trois dates d'envoi d'une campagne de doléances vivent en colonnes, jamais remises à nul : dernier récapitulatif matinal, dernière relance, récapitulatif final. Une est servie par l'API | `B/Entity/CoachWishCampaign.php:73-83` ; `B/Service/CoachWishCampaignPresenter.php:46` ; `B/Controller/CoachWishCampaignActionController.php:118-137` ; `B/Command/CoachWishDigestCommand.php:133`, `:182`, `:202`, `:240-257` |
| C24 | **La date du lien sur le jeton d'un coach est un état, pas une trace** : quand le coach répond pour une équipe de plus, elle est remise à nul (« à envoyer ») ; le bouton global n'envoie qu'aux jetons à nul | `B/Entity/CoachWishToken.php:59-61`, `:156-162` ; `B/Service/CoachWishCampaignTokenSync.php:43-45` ; `CoachWishCampaignActionController.php:203`, `:247` ; `CoachWishCampaignPresenter.php:88` |
| C25 | Trois autres dates sont des préavis qui décident d'une suppression et s'annulent : compte inactif, compte sans club, club à effacer | `B/Entity/User.php:79`, `:87` ; `B/Entity/Club.php:150` ; `B/EventListener/LoginSuccessListener.php:100` ; `B/Service/OrphanAccountNotifier.php:98`, `:159` ; `B/Command/PurgeInactiveUsersCommand.php:94`, `:155-156` ; `PurgeOrphanAccountsCommand.php:98`, `:143-144` ; `PurgeErasedClubsCommand.php:161-163` ; `AccountErasureService.php:205` ; `Registration/ClubWinBackService.php:68` ; `AuthController.php:318-319` |
| C26 | Les trois commandes de rappel posent le club sur la connexion avant de lire | `PeriodReminderCommand.php:136` ; `TransitionReminderCommand.php:130` ; `CoachWishDigestCommand.php:114` |
| C27 | Le journal d'audit est une liste fermée d'événements d'authentification et de destruction ; l'envoi des conflits aux coachs garde ce qui a été envoyé, conflit par conflit | `B/Enum/AuditAction.php:7-11` ; `B/Controller/ConflictCoachMailController.php:347-354` |
| C28 | Diagnostics : type en texte libre de 50 caractères ; gravité en quatre mots dont « SUCCESS », que rien n'émet (recherche sur `backend/src`, `engine/app`) mais que l'écran sait afficher | `B/Entity/ScheduleDiagnostic.php:51-55` ; `B/Enum/ScheduleDiagnosticSeverity.php:7-15` ; `F/planning/api.ts:74` ; `F/planning/lib/diagnosticsSummary.ts:4`, `:12` ; `F/planning/DiagnosticsPanel.tsx:19` |
| C29 | Le moteur contraint sa liste de types (dont `unplaced`, que le backend écrit aussi). Le backend en écrit **onze à lui**, par trois chemins : `engine_failed`, `internal_error`, `engine_timeout`, `engine_status` ; `club_mismatch`, `overlay_entry_missing`, `source_version_missing`, `engine_rejected`, `engine_error` ; `generation_failed`, `stuck_timeout`. Aucune garde ne les liste | `engine/app/schemas/output_schema.py:95-137` ; `B/Service/ScheduleDiagnosticsRecorder.php:68`, `:83`, `:93`, `:210` ; `B/MessageHandler/GenerateScheduleHandler.php:120`, `:214`, `:232`, `:241`, `:296`, `:310`, `:333`, `:412`, `:439-442` ; `B/EventListener/ScheduleGenerationFailureListener.php:74` ; `B/Command/ReconcileStuckSchedulesCommand.php:140` ; `backend/tests/CrossStack/DiagnosticCodesExistUpstreamTest.php:28-36` |
| C30 | Conflits de matchs : type dans une énumération (lot 7), gravité en rang de 1 à 7 ; l'écran traduit le rang en ton | `B/Matches/ConflictType.php:22-53` ; `B/Service/MatchConflictDetector.php:98-99` ; `F/matches/lib/diagnostic.ts:47-52` |
| C31 | Après un export en tableur, l'écran ne relit pas le solde de crédits ; après un PDF, si | `F/planning/queries.ts:561-565`, `:584-585` |
| C32 | Tout échec d'export s'affiche « Export impossible — réessayez. », message du serveur compris, alors que la décision du lot 8 est « au clic, le message de refus s'affiche » | `F/planning/queries.ts:589-590` ; `lot-conception-8-abonnement.md:339-340` ; `frontend/src/shared/lib/errorMessage.ts` |
| C33 | Un message qui a épuisé ses essais va dans le transport des échecs (`failed`), que la console affiche | `messenger.yaml:14`, `:33` ; `backend/.env:92` |
| C34 | Les vacances scolaires s'écrivent par mise à jour sur clé naturelle, jamais par suppression ; l'entrée de calendrier et la table de rappel les désignent par une référence déclarée « gardée », sans clé | `B/Service/HolidaySeeder.php:70-73` ; `B/Command/ImportSchoolHolidaysCommand.php:98-104` ; `B/Entity/HolidayReminderLog.php:35-37` ; `B/Entity/CalendarEntry.php:75` |
| C35 | Tests listés au step bloquant et dans `blocking-tests.md` (recherche par nom) : `ImportAuthorizationTest`, `ClubAccessListenerTest`, `AccessDeclarationSweepTest`, `PlanEntitlementsTest`, `MemberWriteSweepTest`, `RlsIsolationTest`, `ReadOnlyRoleTest`, `PurgeCompletenessTest`, `FamilyDeclarationSweepTest`, `ExportAuthenticationTest`, `ClubQuotaTest`, **`ExportPdfDownloadIsolationTest`**. N'y sont pas : `ImportFixturesAuthorizationTest`, `PdfCreditIsTakenOnDeliveryTest`, `ExportScheduleTest`, `DiagnosticCodesExistUpstreamTest` | `.github/workflows/ci.yml` ; `docs/testing/blocking-tests.md` |

**Non vérifié** : l'effet de C3 sur un geste d'édition concurrent ; le nombre de lignes des trois tables de rappel en production (illisibles au rôle de lecture, conception §27) ; la durée actuelle des suites ; si la stack d'épreuve tourne avec les réglages de production (4 processus, 192 Mo) ; si le dialogue d'export des matchs affiche le message du serveur ; qu'un test existant couvre déjà « équipes changées, le lien repart » (C24).

## 3. Conception : les cinq réponses par objet

| Objet | Le plus simple | Supprime | Existant semblable | Suppose en dur | Deuxième usage |
|---|---|---|---|---|---|
| `ExportDelivery` (service, PR 1) | Une fonction : fabriquer, prendre le crédit en mode strict, répondre le fichier. Les trois contrôleurs l'appellent | La file du PDF, le message, le worker d'export, la route de téléchargement, trois colonnes de la version, une colonne de la saison, le décompte « à la réponse » du contrôle central, la purge à 90 jours, le sondage de l'écran | Les en-têtes de `ExportXlsxController.php:58-80` et `ExportMatchesPdfController.php:96-101` ; `ClubAccessListener.php:167-195` | Un export tient dans une requête (30 s de rendu, 192 Mo) ; le fichier n'est gardé nulle part ; la saison qui paie est celle de la requête | Export des salariés : un contrôleur de plus. « Un planning d'essai ne sort pas » (§21.3) : une condition à cet endroit |
| `ImportGate` + `ImportKind` (PR 2) | Une classe, paramétrée par trois cas déclarés (condition de socle, plafond et son texte, club du chemin ou du jeton) | Trois classes de contrôle (la fiche dit deux sur trois : la troisième devient la classe commune) | C15 | Trois imports à contrôle d'entrée ; un import = un club, une saison | Import des salariés : un cas de plus |
| `send_log` + `SendJournal` (PR 3a, 3b) | Une table, une ligne par envoi noté (genre, sujet, palier, nombre de destinataires, date). Plus simple encore : garder les trois tables ; écarté, c'est l'exemple « à ne pas refaire » du principe 5 | Trois tables, trois entités, trois dépôts, le cas à part de la purge de saison (C22), l'exception de `FamilyCatalog.php:53`, trois colonnes de la campagne | C20 ; `AuditAction.php:7-11` (non étendu : autre usage) ; `ConflictMailing` et `ConflictMailReceipt` (non fusionnés : ils gardent le contenu envoyé) | Propriétaire : club et saison (RLS) ; une ligne meurt avec son sujet ; aucun contenu ni adresse stockés ; pas d'envoi sans club ; **une trace seulement, jamais un état** (C24, C25) | Un envoi de plus (invitation, conflits aux coachs) : un genre et un appel. Un écran de support : une lecture |
| `ScheduleDiagnostic::BACKEND_TYPES` (PR 4) | Une constante sur l'entité qui porte la colonne, lue par un test de parité. Une énumération lue à l'écriture serait plus forte ; écartée : elle changerait la chaîne de génération (axe §7.1) | La gravité « SUCCESS » si la mesure la confirme vide | `output_schema.py:95-137` (liste du moteur) ; `DiagnosticCodesExistUpstreamTest` | Deux sources de types : le moteur et le backend | La forme commune des signalements, le jour où un domaine est refondu : la liste existe déjà |

**Vraies clés étrangères** (règle du découpage §1) : `send_log` porte des colonnes typées, une par sujet, chacune avec sa clé en cascade (`club`, `season`, `calendar_entry`, `school_holiday_period`, puis `coach_wish_campaign` à la PR 3b). Pas de colonne « sujet » générique : elle interdirait les clés. **Conséquence à connaître** : la clé vers les vacances scolaires inverse la déclaration d'aujourd'hui, qui dit « gardée » (C34). Sur `send_log` elle devient « la ligne meurt avec sa cible » ; `FamilyDeclarationSweepTest` la lit (règles 3 et 4 : pas de cycle, ordre de purge) et `tank` vérifie qu'une cible de niveau plateforme y est admise. Aucun code ne supprime une vacance scolaire aujourd'hui (C34) : la cascade ne se déclenche pas. L'entrée de calendrier garde, elle, sa référence « gardée » : les deux déclarations vers la même cible diffèrent, et le plan le dit.

**Rangement par domaine** : `backend/src/Export/` (existant), `backend/src/Imports/` (neuf), `backend/src/Mail/` (existant, reçoit `SendJournal`). Les entités restent dans `Entity/`, comme aux lots 3 et 8. Pas de dossier `Signals/` : rien n'y vivrait.

### Écarts à la fiche du lot, validés avec le plan

- **E1 · Quatre dates restent en colonnes parce qu'elles sont des états, pas des traces.** Les trois préavis de suppression (C25, décision Q2) ; la date du lien sur le jeton d'un coach, remise à nul quand il doit recevoir le lien de nouveau (C24). Le journal ne note pas l'envoi des liens.
- **E2 · Les deux imports par la liaison fédérale et la création automatique des équipes d'un nouveau club ne passent pas par `ImportGate` et ne sont pas enveloppés dans une transaction** : ils n'ont pas de classe de contrôle à fusionner, et ils relisent la fédération pendant l'écriture (C18) : on n'ouvre pas une transaction pendant un appel sortant. Leur écriture tient déjà en un flush (C19).
- **E3 · La forme commune des signalements n'est pas livrée par ce lot.** La conception dit de la faire « quand chaque domaine est refondu, pas d'un coup » (`conception-cible.md:757-758`), et le lot 10 ne refond aucun domaine qui signale. Servir une gravité à côté du rang des conflits rangerait la même information à deux endroits. Le renvoi au lot 7 est écarté : son plan est validé en version finale et renvoie lui-même le sujet ici (son écart E7). Le lot 10 livre ce qui supprime et ce qui garde : la liste des types de diagnostic, et le retrait de « SUCCESS ». Le reste devient une ligne de roadmap, à ouvrir sur décision du fondateur.
- **E4 · La purge des rendus ne disparaît pas tout à fait** : l'outil de rendu écrit un fichier que le serveur lit puis supprime (C10) ; un fichier laissé par une panne part à la purge sous 1 h. La règle des 90 jours, l'épinglage et le rattachement au planning partent.
- **E5 · Le journal ne couvre que six genres d'envoi** (trois rappels ; relance, récapitulatif matinal et récapitulatif final des doléances). Liens des doléances, invitations, conflits aux coachs, demandes de rôle et échec de placement n'y entrent pas (§10).

## 4. Découpage : 5 PR, 4 chantiers

Raison de la scission du chantier « envois » : la PR 3b touche les fichiers de la collecte des doléances, que les PR 4 à 7 du lot 5 réécrivent ; elle doit pouvoir attendre derrière elles sans retenir les rappels (PR 3a).

### PR 1 · « Je demande, je reçois le fichier » (`neo`, backend et un hook d'écran)

**Condition d'entrée : la mesure** (§8), jouée avant tout code. Verdict « retenu » : la PR s'ouvre. Verdict « limite » : décision Q3 (§12), en direct jusqu'à 20 s, file à part au-delà. Verdict « écarté » : la PR ne s'ouvre pas.

**Change**
- `POST /api/schedules/{id}/export-pdf` rend le PDF dans la réponse. Les trois contrôleurs d'export appellent `ExportDelivery`.
- Le crédit se prend dans `ExportDelivery`, en mode strict, pour les trois exports, **sur la saison de la requête**. Pour le PDF du planning c'est un changement de lecture, pas de résultat : aujourd'hui le worker lit la saison du planning (C2), et le filtre de saison rend 404 un planning d'une autre saison que celle de la requête (`B/Doctrine/Filter/SeasonFilter.php:27-35`, constat C20 du plan du lot 8) : les deux saisons sont la même.
- Pool vidé entre la demande et la livraison : pas de fichier, refus 403 avec le texte habituel. Rendu en échec : 502 pour les deux PDF, sans crédit.
- Disparaissent : `ExportPdfMessage`, `ExportPdfHandler`, sa ligne de `messenger.yaml`, `ExportPdfDownloadController`, `ExportPdfDownloadPaths`, les colonnes `schedule.pdf_export_status`, `pdf_export_url`, `pdf_export_request_id` et `season.export_pdf_url` (migration), les deux champs de `ScheduleResource`, celui de `SeasonResource`, `Output::settledAtResponse()`, `ClubAccessListener::onKernelResponse`, `PdfGenerator::renderPending`, `deliver`, `discard`, `deleteRendersOf`, les lignes d'export de `PersonEraser` (C13), la publication Mercure de l'état d'export.
- `app:exports:purge` garde une règle : tout rendu de plus d'une heure part.
- Écran : `useScheduleExport` reçoit le fichier de la requête ; le sondage et `lib/exportScopeToken.ts` partent ; le solde se relit après **chaque** export, tableur compris (C31) ; un refus du serveur affiche son message (C32).

**Contrats**
- `POST /api/schedules/{id}/export-pdf` → `200`, corps = octets du PDF, `Content-Type: application/pdf`, `Content-Disposition: attachment` (nom vivant du plan, règle de `ExportXlsxController.php:67-74`), `Cache-Control: private, no-store`. Corps de la demande inchangé. Erreurs : `404 {error: "Planning introuvable."}`, `403 {error: "Accès refusé."}`, `404` gymnase, `502 {error: "Le document n'a pas pu être généré."}`, `403` crédits ou palier (corps `detail`, celui du contrôle central : même exception levée).
- `GET /api/schedules/{id}/export-pdf/{filename}` : supprimée (404 de routage).
- `GET /api/schedules…` perd `pdfExportStatus` et `pdfExportUrl` ; `GET /api/seasons…` perd `exportPdfUrl`.
- `App\Export\ExportedFile` (lecture seule) : `bytes: string`, `mimeType: string`, `filename: string`.
- `App\Export\ExportDelivery::deliver(Request $request, Closure $render): Response`, où `$render(): ExportedFile`. Club et saison lus sur la requête (`_club_id`, `_season_id`).
- `PdfGenerator::render(Schedule $schedule, ?string $venueId, bool $showCoaches, bool $showOccupancies): string` (octets).
- `PlanEntitlements::creditsExhaustedMessage(int $max): string`, seule maison du texte de C14.
- `PurgeExportsCommand::verdictFor(string $name, int $modifiedAt, int $cutoff): ?string` (`'expired'`, `'keep'`, `null`) ; l'option `--days` part.
- Écran : `exportSchedulePdf(id, venueId, showCoaches?, showOccupancies?): Promise<Blob>`, délai 60 000 ms (celui de `F/matches/api/fixtures.ts:346`) ; `useScheduleExport(scheduleId, exportName): { run, busy }` inchangé.

**Textes à l'écran, cas par cas**

| Cas | Texte |
|---|---|
| Crédits épuisés (403) | « Les 10 générations gratuites de votre club sont utilisées — l'offre complète les rend illimitées. » (texte du serveur, inchangé) |
| Au-dessus du palier (403) | Le message du serveur, celui du bandeau (plan du lot 8, PR 3) |
| Plafond horaire (429) | Le message du serveur |
| Rendu en échec (502), panne de réseau, délai dépassé | « Export impossible — réessayez. » (inchangé) |

**Section tests pour `tank`**
- Filet, vert avant `neo` : `l-export-des-matchs-pour-les-parents.feature`, `en-decouverte-une-sortie-coute-un-credit.feature`, `au-dessus-du-palier-plus-de-sorties.feature`, `un-membre-lit-le-gestionnaire-ecrit.feature`, `ClubQuotaTest`, `ExportScheduleTest`, `ExportMatchesTest`.
- Rouges avant `neo` : « je reçois le PDF dans la réponse » (`l-export-du-planning.feature`, scénario 1 réécrit) ; « jamais plus de fichiers que de crédits, pour les trois exports » (`tests/Integration/Export/ExportCreditIsStrictAtDeliveryTest.php`, le pool vidé entre le contrôle et la livraison) ; « un rendu en échec ne coûte rien » (cas repris de `PdfCreditIsTakenOnDeliveryTest`, supprimé) ; « rien ne reste sur le serveur » (`tests/Security/ExportLeavesNoFileTest.php`) ; écran, dans `queries.test.tsx` : le solde se relit après un tableur comme après un PDF ; un 403 affiche le message du serveur.
- **Ce qui gate** : `ExportPdfDownloadIsolationTest` est au step bloquant (C35) et disparaît avec sa route ; `ExportLeavesNoFileTest` prend sa place **dans le step de `ci.yml` et dans `docs/testing/blocking-tests.md`, même PR** (`BlockingTestsListMatchesCiTest` tient les deux sens). `ExportCreditIsStrictAtDeliveryTest` **ne gate pas**, comme `PdfCreditIsTakenOnDeliveryTest` qu'il remplace : il tourne dans `backend-tests` et dans `tests-complete`.
- NR d'axe (auth et memberships), déjà au step bloquant : `ClubAccessListenerTest`, `AccessDeclarationSweepTest` (la ligne de la route de téléchargement part : voulu), `PlanEntitlementsTest` (son cas d'abus de saison du lot 8 rejoué inchangé), `MemberWriteSweepTest`. NR isolation : `un-club-ne-voit-jamais-un-autre-club.feature`. NR chaîne de génération : `un-petit-club-se-genere-par-l-api.feature` rejouée inchangée.
- Supprimés avec leur objet : `ExportPdfHandlerRlsTest`, `ExportPdfMessageShowCoachesTest`, `exportScopeToken.test.ts`, les scénarios 3 et 4 de `l-export-du-planning.feature` (le refus sans session reste dans `ExportAuthenticationTest`, bloquant).
- Adaptation mécanique par `neo` (202 devient 200) : `ExportContext`, `CreditsContext`, `DiscoveryCreditContext`, `SubscriptionBySeasonContext`, `TeamCapContext`, `MemberReadOnlyContext`, `PersonErasureDecorTrait`, `PurgeExportsVerdictTest`, `PdfExportFilenameTest`, `PlanningPage.test.tsx`, `MessengerFailedSubtab.test.tsx`.

**Contrôle visuel** : aucune apparence ne change. Le geste réel (clic, attente, fichier reçu ; refus et son message) se joue sur la stack d'épreuve, gestionnaire et Membre.

### PR 2 · « Un seul contrôle d'entrée pour les imports » (`neo`, backend seul)

**Change**
- `ImportGate` remplace les trois classes ; les six contrôleurs de C17 l'appellent avec leur cas.
- Ordre unique des refus : pas d'adhésion 404, Membre 403, saison archivée 409, socle selon le cas 409, club 404, plafond 429, puis fichier ou corps. **Corps identiques à l'octet à ceux d'aujourd'hui, 429 compris** : chaque cas porte son texte.
- Tout ou rien : **un seul site à envelopper**, l'appel `importer->import(…)` de `ImportFixturesController.php:126-127`, pour que le flush des compétitions (`FbiFixtureImporter.php:1741`) et le flush principal (`:453`) tiennent dans une transaction. Transaction posée sur la connexion (ouverture, validation, retour arrière), pas `wrapInTransaction` : un refus métier ne doit pas fermer le gestionnaire d'entités. L'importeur n'est pas modifié. Les autres écritures tiennent déjà (C19) ; la liaison fédérale est exclue (E2).

**Contrats**
- `enum App\Imports\ImportKind: string { TEAMS_FILE = 'teams_file'; FIXTURES_FILE = 'fixtures_file'; PARC = 'parc' }` ; méthodes `socle(): SocleCondition`, `limiter(): string` (`'xlsx_import'` ou `'parc_import'`), `tooManyText(): string`, `clubFromPath(): bool`. La source et ce que l'import crée s'écrivent dans le commentaire du cas.
- `enum App\Imports\SocleCondition { ANY; IN_FORCE; NOT_IN_FORCE }`.
- `App\Imports\ImportGate::admit(Request $request, ImportKind $kind, ?string $pathClubId = null): Club|JsonResponse` ; `requireXlsxFile(Request $request): UploadedFile|JsonResponse` ; `requireMatchingSeasonId(Request $request): string|JsonResponse`.
- Double de test : `App\Tests\Double\FailingFlushSubscriber` (écouteur Doctrine `onFlush`, enregistré dans `config/services_test.yaml` comme le double du lot 8), `armOn(string $entityClass): void`, `disarm(): void` : armé, il lève une exception quand l'unité de travail porte l'insertion d'une entité de cette classe.

**Texte** : aucun ne change.

**Section tests pour `tank`**
- Filet, vert avant `neo` et rejoué inchangé : `ImportAuthorizationTest`, `ImportFixturesAuthorizationTest`, `ImportVenueMappingAbuseTest`, `XlsxImportRateLimitTest`, `ImportTeamsUploadGuardTest`, `ParcImportAnalyzeTest`, `ParcImportExecuteTest`, et les trois features d'import (§5).
- Rouges avant `neo` : `tests/Security/ImportEntryControlTest.php` (logique à cas : les six routes, chaque refus, dans l'ordre, corps compris) ; `tests/Integration/Api/ImportIsAllOrNothingTest.php`, trois écritures et trois aperçus. **Point d'injection** : `FailingFlushSubscriber`. Matchs par fichier : armé sur `Fixture`, il échoue au flush principal (`:453`), après le flush des compétitions (`:1741`) ; attendu : aucune compétition ni correspondance créée (rouge aujourd'hui). Équipes par fichier : armé sur `Team` ; parc : armé sur `Reservation`, après le flush des équipes ; attendu : rien d'écrit (verts aujourd'hui, filet). Aperçus : les trois routes d'analyse ne changent aucun compte de ligne. À vérifier par `tank` : la transaction de l'application sous celle du harnais de test (points de sauvegarde).
- **Ce qui gate** : `ImportEntryControlTest` **gate** (non-régression de l'axe auth et memberships sur les six routes) : step de `ci.yml` et ligne de `blocking-tests.md`, même PR. `ImportAuthorizationTest` gate déjà. `ImportFixturesAuthorizationTest` **ne gate pas** et n'y entre pas : ses refus sont repris par `ImportEntryControlTest`. `ImportIsAllOrNothingTest` ne gate pas.

**Contrôle visuel** : aucun.

### PR 3a · « Un seul journal des envois : les rappels » (`neo`, backend seul)

**Change**
- Migration (connexion propriétaire) : crée `send_log`, ses trois policies, le `GRANT SELECT` du rôle de lecture ; retire `UPDATE` au rôle applicatif (un journal ne se réécrit pas, précédent `migrations/Version20260828170000.php:35-40`) ; convertit les trois tables ; les supprime. Une ligne dont le sujet n'existe plus n'est pas convertie ; la migration écrit son décompte et ne s'arrête sur aucune donnée. Irréversible.
- Les deux commandes de rappel lisent et écrivent par `SendJournal`. Règle unique « noté envoyé » : noté dès qu'un gestionnaire a reçu le rappel (décision Q1), pour les trois rappels.
- Supprimer une période emporte ses lignes (par la clé, comme aujourd'hui par le code, C22). **Re-dater une période ne touche pas ses lignes, aujourd'hui comme après** : un palier déjà envoyé ne repart pas.
- Le journal suit la purge de saison et l'effacement du club par la déclaration de famille. **Écart de comportement** : après une remise à zéro de saison, un rappel de bascule déjà envoyé peut repartir (§11).

**Contrats**
- `App\Entity\SendLogEntry`, table `send_log` : `id`, `clubId`, `seasonId`, `kind: SendKind`, `calendarEntryId: ?string`, `schoolHolidayPeriodId: ?string`, `milestone: ?int`, `recipientCount: ?int` (nul pour une ligne convertie), `sentAt`. Un CHECK par genre sur la colonne de sujet ; unicité partielle par genre : (`calendar_entry_id`, `milestone`), (`season_id`, `school_holiday_period_id`, `milestone`), (`season_id`, `milestone`). `#[Family(Level::SEASON, cold: [], copiedToNextSeason: false)]` ; références déclarées « la ligne meurt avec sa cible », vacances scolaires comprises (§3).
- `enum App\Enum\SendKind: string { PERIOD_REMINDER = 'period_reminder'; HOLIDAY_REMINDER = 'holiday_reminder'; SEASON_TRANSITION_REMINDER = 'season_transition_reminder' }`, fermée par un CHECK.
- `App\Mail\SendSubject` (lecture seule), constructeurs `period(string $calendarEntryId, int $milestone)`, `holiday(string $seasonId, string $schoolHolidayPeriodId, int $milestone)`, `seasonTransition(string $seasonId, int $milestone)`.
- `App\Mail\SendJournal::record(SendKind $kind, string $clubId, string $seasonId, SendSubject $subject, int $recipientCount, ?DateTimeImmutable $sentAt = null): void` (insertion tolérante au doublon, patron `HolidayReminderLogRepository.php:21-44`) ; `alreadySent(SendKind $kind, SendSubject $subject): bool`.

**Section tests pour `tank`**
- Filet, vert avant `neo` : `PeriodReminderCommandTest` (38 cas), `TransitionReminderCommandTest` (12 cas ; `testPartialMailerFailureRetriesTheWholeMilestone` réécrit : la règle unique de Q1 est retenue).
- Rouges avant `neo` : `tests/Integration/Migration/SendLogMigrationTest.php` (palier de période, de vacances, de bascule ; sujet disparu ; après conversion, aucun rappel ne repart) ; `tests/Integration/Mail/SendJournalTest.php` (supprimer une période emporte ses lignes ; re-dater les garde ; la remise à zéro de saison vide le journal de la saison).
- NR isolation, déjà au step bloquant, ils prennent la table d'office : `RlsIsolationTest`, `ReadOnlyRoleTest` (les trois tables quittent la liste blanche), `PurgeCompletenessTest`, `FamilyDeclarationSweepTest` (la référence vers les vacances scolaires change de sens). Abus, cas nommés dans `RlsIsolationTest` : le rôle applicatif ne peut pas modifier une ligne, club posé compris ; le club A ne lit ni n'écrit une ligne du club B. Aucun step neuf.

**Contrôle visuel** : aucun.

### PR 3b · « Les envois d'une campagne lisent le journal » (`neo`, backend seul ; après les PR 4 à 7 du lot 5)

**Change**
- Les trois colonnes de la campagne (C23) partent ; chaque date non nulle devient une ligne du journal (migration, décompte écrit).
- La relance, le récapitulatif matinal et le récapitulatif final lisent `SendJournal`. L'API sert le même champ `lastReminderAt`.
- **La date du lien sur le jeton ne bouge pas** (C24, E1) : `CoachWishToken` et `CoachWishCampaignTokenSync` ne sont pas modifiés.

**Contrats**
- `SendKind` gagne `COACH_WISH_REMINDER = 'coach_wish_reminder'`, `COACH_WISH_DIGEST = 'coach_wish_digest'`, `COACH_WISH_FINAL_RECAP = 'coach_wish_final_recap'`.
- `SendLogEntry` gagne `coachWishCampaignId: ?string` (clé en cascade) ; `SendSubject::campaign(string $campaignId)`.
- `SendJournal::lastSentAt(SendKind $kind, SendSubject $subject): ?DateTimeImmutable`.
- `CoachWishCampaign` perd `markDigestSent`, `markReminderSent`, `markFinalRecapSent` et leurs accesseurs.

**Texte** inchangé : « Les coachs ont déjà été relancés aujourd'hui. » (`CoachWishCampaignActionController.php:126`).

**Section tests pour `tank`**
- Filet, vert avant `neo`, rejoué inchangé : `CoachWishDigestCommandTest`, `CoachWishCampaignActionsTest`, `voeux-des-coachs.feature`, `CoachWishSendAbuseTest`. **Filet de l'état du jeton** : un coach déjà servi à qui une équipe est ajoutée repasse « à envoyer », et le bouton global lui renvoie le lien ; si aucun test ne le couvre (non vérifié), `tank` l'écrit dans `CoachWishCampaignActionsTest`, vert avant `neo`.
- Rouges avant `neo` : conversion des trois dates (`SendLogMigrationTest`, cas : campagne relancée hier, récapitulatif final parti) ; supprimer une campagne emporte ses lignes.
- NR isolation : comme la PR 3a. Aucun step neuf.

**Contrôle visuel** : aucun.

### PR 4 · « Les types de diagnostic ont une liste gardée » (`neo`, backend et trois fichiers d'écran)

**Change**
- Les onze types que le backend écrit (C29) sont listés une fois, sur l'entité. **Rien n'est refusé à l'exécution** : aucun fichier de la chaîne de génération n'est modifié, l'axe n'est pas touché.
- Un test tient la liste : tout type écrit dans `backend/src` est dans la liste du moteur ou dans celle du backend ; les deux listes ne se recouvrent que pour `unplaced`, nommé.
- « SUCCESS » quitte l'énumération et l'écran **si** la mesure (§8) trouve zéro ligne en production ; sinon cette partie n'est pas faite, et le plan le note.

**Contrats**
- `ScheduleDiagnostic::BACKEND_TYPES: list<string>` (les onze de C29).
- `ScheduleDiagnosticSeverity` perd `SUCCESS` ; le type d'écran `DiagnosticSeverity` aussi (`F/planning/api.ts:74`).

**Section tests pour `tank`**
- Rouge avant `neo` : `DiagnosticCodesExistUpstreamTest`, un cas de plus : balayage de `recordSingle(`, `failSchedule(` et `setType(` d'un diagnostic dans `backend/src`, contre `BACKEND_TYPES` et la liste du moteur. Il **ne gate pas** (groupe `contract`, `backend-tests`), comme aujourd'hui.
- Mécanique : `TsUnionsMatchPhpEnumsTest` si la gravité y figure, `DiagnosticsPanel.test.tsx`, `diagnosticsSummary` (retrait d'une valeur).

**Contrôle visuel** : aucun (le groupe « OK » du panneau des diagnostics ne s'affichait jamais, C28).

## 5. Tableau comportement → scénario (brief de `tank`)

| # | PR | Décision | Scénario ou test |
|---|---|---|---|
| 1 | 1 | Exporter le planning en PDF rend le fichier dans la réponse | `l-export-du-planning.feature`, scénario 1 réécrit |
| 2 | 1 | À mon dernier crédit, le premier export livre un fichier, le suivant est refusé avec le message ; vrai pour les trois exports | `un-credit-se-prend-quand-la-sortie-est-obtenue.feature`, le plan de scénario « coup sur coup » réécrit, 3 lignes d'Exemples |
| 3 | 1 | Deux exports qui arrivent ensemble sur un seul crédit : un fichier, un refus, compteur à 10 sur 10 | `ExportCreditIsStrictAtDeliveryTest` (Behat ne joue pas deux requêtes en parallèle) |
| 4 | 1 | Un rendu en échec ne coûte rien et répond 502 | même test, cas |
| 5 | 1 | Offre payante, bêta, démonstration : jamais décomptés | scénario existant de la même feature, étapes adaptées |
| 6 | 1 | Membre : il exporte ; au-dessus du palier : refusé | `un-membre-lit-le-gestionnaire-ecrit.feature`, `au-dessus-du-palier-plus-de-sorties.feature`, rejouées |
| 7 | 1 | Après un export, aucun fichier ne reste et l'ancienne adresse de téléchargement répond 404 | `ExportLeavesNoFileTest` (bloquant) |
| 8 | 1 | Exporter ne modifie plus la version (C3) | `ExportScheduleTest`, cas |
| 9 | 1 | Le solde affiché se relit après un tableur comme après un PDF | `queries.test.tsx`, cas |
| 10 | 1 | Un refus du serveur à l'export affiche son message ; une panne affiche « Export impossible — réessayez. » | `queries.test.tsx`, deux cas |
| 11 | 2 | Les trois imports refusent dans le même ordre, avec les corps d'aujourd'hui ; le socle selon le cas | `ImportEntryControlTest` (bloquant) |
| 12 | 2 | Un import des matchs par fichier qui échoue en cours d'écriture ne laisse aucune compétition créée | `ImportIsAllOrNothingTest`, cas rouge |
| 13 | 2 | Équipes par fichier et parc : un échec n'écrit rien ; un aperçu n'écrit rien | même test, 2 et 3 cas (filet) |
| 14 | 3a | Un palier de rappel ne part qu'une fois ; un passage manqué se rattrape | `PeriodReminderCommandTest`, `TransitionReminderCommandTest`, inchangés |
| 15 | 3a | Rappel reçu par une partie des gestionnaires : noté dès qu'un gestionnaire l'a reçu (Q1) | `TransitionReminderCommandTest`, un cas réécrit |
| 16 | 3a | Supprimer une période emporte ses lignes ; la recréer relance les rappels (geste inverse). Re-dater une période garde ses lignes | `SendJournalTest`, deux cas |
| 17 | 3a | Remise à zéro de saison : les paliers de la saison repartent, bascule comprise | même fichier |
| 18 | 3a | La conversion ne fait repartir aucun rappel | `SendLogMigrationTest` |
| 19 | 3a | Le journal d'un club n'est ni lu ni écrit par un autre ; une ligne ne se modifie pas | `RlsIsolationTest`, cas nommés (bloquant) |
| 20 | 3b | Une relance par jour, la seconde refusée 422 ; le lendemain elle repart | `CoachWishCampaignActionsTest`, inchangé |
| 21 | 3b | Récapitulatif matinal seulement après une réponse nouvelle ; récapitulatif final une fois | `CoachWishDigestCommandTest`, inchangé |
| 22 | 3b | Un coach servi à qui une équipe est ajoutée reçoit le lien de nouveau par le bouton global (inchangé) | `CoachWishCampaignActionsTest`, filet |
| 23 | 4 | Tout type de diagnostic écrit par le backend figure dans une des deux listes | `DiagnosticCodesExistUpstreamTest`, cas |

**Preuve = captures, sans test** : aucune ; aucune apparence ne change dans le lot.

**Un scénario par export et par import** (preuve demandée par la fiche du lot)

| Chemin | Scénario | Limite |
|---|---|---|
| PDF du planning | `l-export-du-planning.feature`, scénario 1 | — |
| Tableur du planning | même feature, « J'exporte avec ou sans les occupations » | — |
| PDF des matchs | `l-export-des-matchs-pour-les-parents.feature`, deux scénarios | — |
| Équipes par fichier | `l-import-des-equipes-choisit-ses-lignes.feature`, trois scénarios | — |
| Matchs par fichier | `une-rencontre-importee-dit-si-elle-est-traitee.feature` | — |
| Parc | `l-import-du-parc-en-un-geste.feature`, quatre scénarios | — |
| Matchs par la liaison fédérale | `FfbbRencontresApiTest` | Pas de Behat : aucun scénario ne dépend de la fédération réelle (décision du fondateur du 2026-10-13, `etat-des-lieux.md:555`) |
| Appariement des engagements | `FfbbConfirmPerimeterTest` | Idem |
| Fiche et équipes d'un nouveau club | `FfbbTeamImporterTest` | Idem ; sans aperçu par conception (§22.1) |

**Tests d'abus** (pas de section « Signaux » d'`oracle` : chemins relevés par ce plan) : relire un PDF par son adresse (ligne 7) ; exporter le planning d'un autre club (`un-club-ne-voit-jamais-un-autre-club.feature`, `ExportScheduleTest`) ; servir une sortie d'une autre saison par l'en-tête (`PlanEntitlementsTest`, cas du lot 8) ; vider le pool d'un club par des exports de Membre (`ClubQuotaTest`) ; course sur le dernier crédit (ligne 3) ; importer dans un club dont on n'est pas membre ou comme Membre (`ImportAuthorizationTest`, ligne 11) ; viser un gymnase d'un autre club à l'import (`ImportVenueMappingAbuseTest`) ; lire ou réécrire le journal d'un autre club (ligne 19).

## 6. Carte des conséquences

| Comportement changé | Qui écrit | Qui lit | Garde avant | Garde après |
|---|---|---|---|---|
| Le PDF du planning se rend dans la requête | `ExportPdfController` par `ExportDelivery` | `F/planning/queries.ts:549-599`, appelé par `ExportMenu.tsx` et `F/cockpit/SeasonSchedulesModal.tsx` | Plafond horaire (`ClubQuotaSubscriber.php:84-88`), puis contrôle central : rôle, palier, crédit (`ClubAccessListener.php:153-163`) | Planning du club et de la saison (404, 403), gymnase du planning (`ResolvesExportScopeTrait.php:35-42`), crédit strict |
| Le crédit des trois exports se prend dans `ExportDelivery`, sur la saison de la requête | `OutputCreditLedger::settle` (`:43-71`) | `/api/me` (solde), `useCredits.ts`, relu par l'écran après chaque export | Contrôle central, même saison | Aucune : la réponse part |
| L'état d'export quitte la version et la saison | Migration | `ScheduleResource.php:140-143`, `SeasonResource.php:59`, `F/planning/api.ts:131-132`, `PersonEraser.php:235-244`, `PurgeExportsCommand.php:156`, `PersonErasureDecorTrait.php:395` | — | — |
| Plus de message d'export en file | — | `messenger.yaml:37` ; transport des échecs (`:14`, `:33`), lu par la console | — | À la mise en production : file `async` vidée **et** transport `failed` vidé de ses messages d'export (§9) |
| Un refus d'export affiche le message du serveur | `useScheduleExport` | Gestionnaire et Membre | — | — |
| Un seul contrôle d'entrée d'import | — | Les six contrôleurs de C17 | Contrôle central (rôle, sur le club du jeton) | Bornes du fichier (`XlsxUploadGuard`), puis l'importeur |
| L'import des matchs par fichier s'écrit en une transaction | `ImportFixturesController.php:126-127` | `FbiFixtureImporter` (`:307`, `:453`, `:1741`) ; le relais des erreurs du contrôleur (`:128-148`) | Fraîcheur du fichier (`:126`) | Localisation des adversaires, après validation (`:153`) |
| Les rappels notent dans `send_log` | `PeriodReminderCommand`, `TransitionReminderCommand` par `SendJournal` | Les mêmes (`:173`, `:279`, `:162`) | Club posé sur la connexion (C26) | RLS de la table, unicité partielle |
| Les lignes meurent avec leur sujet, la saison, le club | Clés en cascade ; `SeasonDataPurger` et `ErasedClubPurger` par `FamilyCatalog` | `PurgeCompletenessTest`, `FamilyDeclarationSweepTest` (référence vers les vacances scolaires : « gardée » devient « meurt avec », C34) | — | — |
| Trois dates de la campagne viennent du journal | `CoachWishCampaignActionController.php:134` ; `CoachWishDigestCommand.php:182`, `:202`, `:224` | `CoachWishCampaignActionController.php:124` ; `CoachWishDigestCommand.php:133`, `:240` ; `CoachWishCampaignPresenter.php:46` ; l'écran des doléances | Rôle gestionnaire, refus en démonstration (envoi à un tiers) | — |
| « SUCCESS » part | — | `ScheduleDiagnostic.php:54-55` (lecture d'une ligne), `FeedbackContextEnricher.php:405`, `DiagnosticsPanel.tsx:19`, `diagnosticsSummary.ts:4` | Mesure à zéro ligne | — |

À dire à `neo` : `make -C backend rector` avant push ; redémarrer `messenger-worker` puis `nginx` en dernier ; rebâtir `frontend-tooling` avant les tests d'écran ; exporter `specs/courantes/openapi-snapshot.json` après la PR 1 ; aucune table lue par le constructeur de demande ne change.

## 7. Parcours de bout en bout

1. **Exporter le planning en PDF.** `/planning`, bouton « Exporter » (`F/planning/texts.ts:169`), PDF, tous les gymnases. Aujourd'hui : la demande part, l'écran sonde, le fichier arrive ; si un calcul est en cours, l'export attend derrière lui et l'écran abandonne à 60 s (C4, C5). Après : le bouton tourne le temps du rendu, le fichier se télécharge, calcul en cours ou non. Pas de geste inverse. Variantes : version validée, version d'un plan de vacances, version non chargée : toutes exportables (`ExportPdfController.php:34-71`) ; un Membre exporte (`:22`) ; un seul gymnase ; avec « Afficher le nom des coachs ».
2. **Dernier crédit en Découverte.** 9 sorties sur 10. « Exporter » en Excel : fichier reçu, le badge passe à 10 sur 10 sans recharger la page (aujourd'hui il reste à 9 jusqu'au prochain rechargement, C31). Si le bouton est encore cliquable et qu'un second export part : « Les 10 générations gratuites de votre club sont utilisées — l'offre complète les rend illimitées. » (aujourd'hui « Export impossible — réessayez. », C32).
3. **Importer les équipes par fichier.** Wizard, « Importer vos équipes (export FBI) » (dialogue joué par `frontend/tests/e2e/journey.spec.ts:120`) : aperçu, cases cochées, import. Rien ne change. Variante parc (`F/wizard/parcImport/queries.ts`) : rien ne change, texte du plafond compris. Variante socle validé : le parc reste refusé 409 (`ParcImportGate.php:74`), les matchs restent refusés tant que le socle n'est pas validé (`FixtureImportGate.php:70`).
4. **Importer les matchs par fichier, et l'import casse.** Fichier de 3 divisions neuves ; l'écriture échoue sur un match. Aujourd'hui : 3 compétitions créées restent, sans match. Après : rien n'est écrit, le gestionnaire redépose.
5. **Rappel d'une fermeture.** Aucun geste d'écran : la commande quotidienne envoie à 14, 7 et 3 jours. Le gestionnaire supprime la période : ses lignes partent ; il la recrée : le rappel repart au prochain palier. Il la re-date : rien ne repart, comme aujourd'hui.
6. **Relancer les coachs.** Écran des doléances, « Relancer les silencieux » (`F/coach-wishes/texts/manager.ts:89`). Second clic le même jour : « Les coachs ont déjà été relancés aujourd'hui. ». Le lendemain : la relance repart. Variante : une équipe est ajoutée à un coach déjà servi ; le bouton d'envoi global lui renvoie le lien (C24). Rien ne change.

## 8. Mesures

**Mesure du PDF, avant la PR 1.** Procédure de `lot-conception-filet.md` §8 (club de 60 équipes), jouée avec les réglages de production : 4 processus, 192 Mo par requête (C12) ; si la stack d'épreuve ne les a pas, la série se joue avec l'image de production en local, et le relevé le dit. Séries : PDF du planning le plus lourd, seul ; 4 demandes simultanées ; pendant une génération. Relevés, écrits dans la description de la PR 1 :

| Relevé | Retenu | Limite : décision Q3 | Écarté |
|---|---|---|---|
| Durée du rendu, seul | 10 s au plus | De 10 à 30 s | Plus de 30 s (l'appel est coupé, C11) |
| Temps de réponse de `/api/me` pendant 4 rendus lourds simultanés (les 4 processus sont pris : il attend qu'un rendu finisse) | 10 s au plus | De 10 à 30 s | Plus de 30 s, ou une erreur 5xx |
| Pic de mémoire de la requête d'export | 128 Mo au plus | De 128 à 192 Mo | Plafond de 192 Mo atteint |
| De la demande au fichier, file vide (critère du filet) | 120 s au plus | — | Plus de 120 s |

Les seuils « retenu » et « limite » sont posés par ce plan (§11).

**Mesures de production à demander à `link`** (lecture seule, démonstrations exclues), maintenant puis avec la copie de production n° 2 :
- versions qui portent une adresse d'export ; saisons qui en portent une (attendu : 0, C8) ;
- diagnostics de gravité « SUCCESS » (attendu : 0 ; condition de la PR 4) ;
- campagnes de doléances qui portent une des trois dates (la conception §27 compte 1 campagne) ;
- les trois tables de rappel ne se lisent pas avec le rôle de lecture : leur nombre vient du décompte de la migration sur la copie de production.

## 9. Checklist de cadrage

- **Zones** : backend, frontend. Engine, landing, system-pages : aucun fichier. `frontend/worker.js` : non modifié.
- **Autorisés** : `backend/src/{Export,Imports,Mail,Access,Controller,MessageHandler/ExportPdfHandler.php,Message,Command,Entity,Enum,Repository,Service,ApiResource,OpenApi,Structure,State/Processor}`, `backend/config/packages/messenger.yaml`, `backend/config/services_test.yaml`, `backend/migrations`, `backend/tests`, `backend/features`, `.github/workflows/ci.yml` et `docs/testing/blocking-tests.md` (PR 1 et PR 2, une ligne chacune), `frontend/src/{features/planning,features/cockpit,features/admin}`.
- **Interdits** : `engine/`, `engine/CONTRACT_VERSION`, `Service/EngineClient.php`, `Service/EngineRequestComposer.php`, `Service/ScheduleConstraintBuilder.php`, `MatchPlacementPayloadBuilder.php`, **`MessageHandler/GenerateScheduleHandler.php`, `EventListener/ScheduleGenerationFailureListener.php`, `Command/ReconcileStuckSchedulesCommand.php`, `Service/ScheduleDiagnosticsRecorder.php`** (la PR 4 les lit, ne les modifie pas : l'énumération de gravité garde son nom et son cas `ERROR`), `Service/MatchConflictDetector.php`, `frontend/src/features/matches/`, `Entity/CoachWishToken.php`, `Service/CoachWishCampaignTokenSync.php`, `EventListener/TenantFilterListener.php`, `Doctrine/Filter/`, `security.yaml`, les importeurs (`FbiFixtureImporter.php`, `FfbbExcelImporter.php`, `ParcImportExecutor.php`, `FfbbRencontreReconciler.php`), `docker-compose.prod.yml`, `rate_limiter.yaml`.
- **Axes §7.1 et non-régression** : isolation entre clubs (PR 3a, 3b : `RlsIsolationTest`, `ReadOnlyRoleTest`, `PurgeCompletenessTest`, `FamilyDeclarationSweepTest`, bloquants) ; auth et memberships (PR 1 : `ClubAccessListenerTest`, `AccessDeclarationSweepTest`, `PlanEntitlementsTest`, bloquants ; PR 2 : `ImportAuthorizationTest`, bloquant, et `ImportEntryControlTest`, ajouté au step) ; chaîne de génération (PR 1, file partagée : `un-petit-club-se-genere-par-l-api.feature` rejouée ; PR 4 : non touchée). Contrat backend et moteur : non touché.
- **Contre-revue** : `smith` est passé sur la v1 ; la v2 est validée sans seconde passe.
- **`/security-review`** : systématique sur les PR 1, 2, 3a et 3b. Sujets : isolation entre clubs (table neuve sous RLS, export d'un autre club ou d'une autre saison), RGPD (PDF nominatifs qui ne sont plus gardés, changement de `PersonEraser`), memberships (contrôle d'entrée des imports), démonstration (rappels d'un club à horloge simulée, exports). PR 4 : aucun item de la liste fermée.
- **Behat de la zone** : `l-export-du-planning`, `l-export-des-matchs-pour-les-parents`, `un-credit-se-prend-quand-la-sortie-est-obtenue`, `en-decouverte-une-sortie-coute-un-credit`, `au-dessus-du-palier-plus-de-sorties`, `l-import-des-equipes-choisit-ses-lignes`, `l-import-du-parc-en-un-geste`, `une-rencontre-importee-dit-si-elle-est-traitee`, `voeux-des-coachs`, `effacer-une-personne-efface-tout`. Aucune feature neuve.
- **e2e impactés** : `journey.spec.ts` (import des équipes) et `member-read-only.spec.ts` (ouvre le menu Exporter, `:217`) rejoués ; aucun spec ne télécharge un PDF du planning (recherche sur `frontend/tests/e2e`) ; `visual-reference.spec.ts` : aucun écran ne change.
- **Plafonds de temps** (`test-time-ceiling.json` : backend 1000 s, Behat 950 s, e2e 635 s, frontend 230 s, engine 60 s). Estimations non mesurées : backend, de l'ordre de +15 s sur le lot (un test de migration sur base dédiée, deux tests d'import à cas) ; Behat, de l'ordre de −10 s (deux scénarios retirés, attente du PDF par sondage supprimée) ; frontend, +1 s (trois cas) ; e2e, engine : 0. Mesure par `make ci-mesure PART=origin/lot/conception` à chaque PR. Si le backend ne tient pas : les cas de conversion des PR 3a et 3b vivent dans un seul test de migration ; les six cas de `ImportIsAllOrNothingTest` partagent un décor.
- **Documentation** : fiche du lot 10 et §6 du découpage ; `etat-des-lieux.md` (livré, décisions, la ligne des crédits du 2026-11-21 à reprendre) ; `docs/security/rls.md` (`:230`, `:294` : les trois tables de rappel sortent du « résiduel assumé », `send_log` entre, la route de téléchargement part) ; `backend/docs/{commands,backend-controllers,backend-inventory,backend-auth-mercure,schedule-generation-guide,TENANT}.md` ; `docs/ops/prod-stack.md` ; `docs/ops/deploy.md` (ordre des migrations ; **avant le déploiement, file `async` vidée et transport des échecs `failed` vidé de ses messages d'export** ; `app:exports:purge` lancée juste après, pour supprimer les PDF gardés) ; `docs/testing/{test-coverage-map,blocking-tests,testing-strategy}.md` (le double `FailingFlushSubscriber`) ; `specs/courantes/module-matchs-consultation.md` ; `docs/project-map.md` ; `docs/glossary.md` (journal des envois) ; `docs/architecture/adr-0002-pattern-plan.md` ; `specs/courantes/openapi-snapshot.json`.
- **Retour au fondateur si** : la mesure du PDF est « limite » (Q3, file à part : la PR 1 se réduit alors à ce déplacement) ou « écartée » ; la mesure de production trouve une saison qui porte une adresse d'export ; un refus d'import existant change de code ou de corps ; la conversion du journal laisse de côté des lignes dont le sujet existe ; `FamilyDeclarationSweepTest` refuse une référence « meurt avec » vers une famille de plateforme ; un plafond de suite ne tient pas ; un fichier interdit doit être touché.
- **Aucun refactoring hors scope.** Laissés de côté : la construction des mails, la mécanique des jetons, les destinataires des alertes de plateforme, `ConflictMailing`, le contenu des importeurs, les préventions avant génération, le volume `exports` du worker de file, le dialogue d'export des matchs.

## 10. Limites écrites

- Un PDF du planning n'est plus gardé : le relire, c'est le refaire, et en Découverte chaque export coûte un crédit. À l'écran rien ne change (C7).
- Un export occupe un des 4 processus du serveur pendant son rendu, comme le PDF des matchs aujourd'hui ; quatre exports lourds en même temps font attendre tous les clubs le temps d'un rendu. Les plafonds horaires bornent, ils n'empêchent pas.
- Le journal ne dit pas à qui un envoi est parti, seulement combien de destinataires ; les lignes converties n'ont pas ce nombre.
- Le journal ne couvre pas les liens des doléances, les invitations, les conflits envoyés aux coachs, les demandes de rôle, l'échec de placement (E5).
- Aucun écran ne montre le journal ; le support le lit en base.
- Une ligne meurt avec son sujet : supprimer une campagne ou une période efface la trace de ses envois.
- La liaison fédérale et la création automatique d'un nouveau club restent hors du contrôle d'entrée commun et sans transaction (E2) ; leur écriture en un flush n'a pas de test neuf.
- La forme commune des signalements n'est pas livrée (E3) ; un type de diagnostic inconnu n'est pas refusé à l'écriture, seulement signalé par un test.
- Le dialogue d'export des matchs n'est pas relu : s'il avale le message du serveur, ce lot ne le corrige pas.

## 11. Tranché par le plan (contestable)

- Le crédit strict vaut pour les trois exports. Exemple : deux membres cliquent « Exporter » en Excel à la même seconde avec un seul crédit : aujourd'hui deux fichiers et un compteur à 11 sur 10 ; après, un fichier et un refus.
- Les seuils de la mesure du PDF : 10 s pour « retenu », 30 s pour « écarté », 128 Mo de mémoire.
- Pas de PR « filet » à part : chaque PR nomme ses tests existants, rejoués verts avant `neo`.
- Les trois classes de contrôle d'import deviennent une ; aucun texte d'import ne change.
- Le journal est sous RLS avec `club_id`, sans `UPDATE` pour le rôle applicatif.
- Le journal suit la purge de saison, rappel de bascule compris. Exemple : rappel « à 30 jours » envoyé le 15 juin, saison remise à zéro par le support le 20 juin ; le 21 juin, à 24 jours de la bascule, le même rappel repart. Aujourd'hui il ne repart pas, alors que les rappels de fermeture et de vacances repartent déjà après une remise à zéro (C22).
- La référence du journal vers les vacances scolaires est une vraie clé en cascade, à l'inverse de la déclaration actuelle (§3).
- La forme commune des signalements sort du lot ; la PR 4 est réduite à une liste gardée et au retrait d'une valeur morte (E3).
- La purge des rendus est réduite, pas supprimée (E4).
- `ExportLeavesNoFileTest` et `ImportEntryControlTest` gatent ; `ExportCreditIsStrictAtDeliveryTest`, `ImportIsAllOrNothingTest` et `ImportFixturesAuthorizationTest` ne gatent pas.

## 12. Décisions du fondateur (2026-11-27)

**Q1 · Un rappel reçu par une partie seulement des gestionnaires.** Club à trois gestionnaires, rappel « préparez la saison suivante » à 30 jours ; l'envoi échoue pour l'un des trois. Avant le lot : le rappel de bascule n'est pas noté, et chaque jour suivant les trois le reçoivent de nouveau, tant que l'adresse en panne échoue. Pour un rappel de fermeture ou de vacances, c'est l'inverse : il est noté dès qu'un gestionnaire l'a reçu, le troisième attend le palier suivant. Avec un seul journal, une seule règle. **Décision : noté dès qu'un gestionnaire l'a reçu**, pour les trois rappels. L'autre choix, écarté : noté seulement si tous l'ont reçu (personne n'est oublié, mais des doublons quotidiens tant qu'une adresse échoue).

**Q2 · Les trois préavis de suppression restent hors du journal.** La fiche du lot dit de supprimer « les dates d'envoi éparpillées ». Trois d'entre elles décident d'une suppression : le préavis d'un compte inactif (envoyé à 23 mois ; le compte est anonymisé à 24 mois si le préavis a au moins 14 jours ; il s'annule à la connexion), le préavis d'un compte sans club (suppression 30 jours après), le rappel d'un club sur le point d'être effacé. Deux concernent un compte, pas un club. **Décision : les laisser où elles sont.** L'autre choix, écarté : les faire entrer au journal, avec une notion d'envoi « annulé » et des lignes sans club ; plus lourd, et sur le chemin des suppressions de comptes.

**Q3 · Si la mesure du PDF est « limite ».** Exemple : le PDF d'un club de 60 équipes met 18 s à se rendre. En direct, quatre exports lancés ensemble (deux clubs, deux onglets chacun) figent l'application de tous les clubs pendant 18 s ; en échange, un export ne patiente plus derrière un calcul de planning, qui peut durer 10 minutes. L'autre voie : garder une file, mais à part de celle des calculs, avec son propre worker ; l'export n'attend plus un calcul et ne prend aucun processus du site, mais la file, l'état sur la version et le fichier gardé restent, et la PR 1 se réduit à ce déplacement. **Décision : export en direct jusqu'à 20 s, file à part au-delà**, et seulement si la mesure du PDF est « limite ». Sans mesure « limite », la question ne se pose pas.

Les cinq écarts E1 à E5 (§3) se valident avec le plan ; E1 porte la question Q2.

## 13. Fichiers à relire en premier

- `/home/marabou/projects/scheduler/specs/evolution/lot-conception-decoupage.md` (§ Lot 10)
- `/home/marabou/projects/scheduler/specs/evolution/conception-cible.md` (§21, §22, §23, §26)
- `/home/marabou/projects/scheduler/specs/evolution/lot-conception-filet.md` (§8)
- `/home/marabou/projects/scheduler/backend/src/Controller/ExportPdfController.php`
- `/home/marabou/projects/scheduler/backend/src/MessageHandler/ExportPdfHandler.php`
- `/home/marabou/projects/scheduler/backend/src/Controller/ExportPdfDownloadController.php`
- `/home/marabou/projects/scheduler/backend/src/Service/PdfGenerator.php`
- `/home/marabou/projects/scheduler/backend/src/Service/MatchesPdfGenerator.php`
- `/home/marabou/projects/scheduler/backend/src/Access/ClubAccessListener.php`
- `/home/marabou/projects/scheduler/backend/src/Service/OutputCreditLedger.php`
- `/home/marabou/projects/scheduler/backend/src/Command/PurgeExportsCommand.php`
- `/home/marabou/projects/scheduler/backend/src/Structure/PersonEraser.php`
- `/home/marabou/projects/scheduler/backend/src/Service/TeamImportGate.php`
- `/home/marabou/projects/scheduler/backend/src/Service/FixtureImportGate.php`
- `/home/marabou/projects/scheduler/backend/src/Service/ParcImport/ParcImportGate.php`
- `/home/marabou/projects/scheduler/backend/src/Controller/ImportFixturesController.php`
- `/home/marabou/projects/scheduler/backend/src/Service/FbiFixtureImporter.php` (`:307`, `:453`, `:1667-1743`)
- `/home/marabou/projects/scheduler/backend/src/Command/PeriodReminderCommand.php`
- `/home/marabou/projects/scheduler/backend/src/Command/TransitionReminderCommand.php`
- `/home/marabou/projects/scheduler/backend/src/Command/CoachWishDigestCommand.php`
- `/home/marabou/projects/scheduler/backend/src/Controller/CoachWishCampaignActionController.php`
- `/home/marabou/projects/scheduler/backend/src/Entity/CoachWishToken.php`
- `/home/marabou/projects/scheduler/backend/src/Entity/HolidayReminderLog.php`
- `/home/marabou/projects/scheduler/backend/src/Structure/FamilyCatalog.php`
- `/home/marabou/projects/scheduler/backend/tests/Security/FamilyDeclarationSweepTest.php`
- `/home/marabou/projects/scheduler/backend/src/Entity/ScheduleDiagnostic.php`
- `/home/marabou/projects/scheduler/backend/tests/CrossStack/DiagnosticCodesExistUpstreamTest.php`
- `/home/marabou/projects/scheduler/frontend/src/features/planning/queries.ts`
- `/home/marabou/projects/scheduler/.github/workflows/ci.yml` (step « Blocking suite »)
- `/home/marabou/projects/scheduler/docs/security/rls.md`

## 14. Changements depuis la version précédente

| Correction reçue | Section modifiée |
|---|---|
| B1 · la date du lien sur le jeton est un état « à renvoyer » | C23 scindé, C24 neuf ; §3 (« une trace, jamais un état ») ; E1 élargi ; PR 3b (le jeton n'est plus touché, filet de l'état) ; §5 ligne 22 ; §7 parcours 6 ; §9 interdits |
| B2 · types de diagnostic écrits par le backend ; `unplaced` côté moteur ; contradiction sur `GenerateScheduleHandler` | C29 réécrit (onze types, trois chemins) ; PR 4 (constante sur l'entité, l'énumération de gravité garde son nom) ; §9 interdits (les quatre fichiers d'écriture nommés) |
| B3 · refuser un type à l'écriture change la chaîne de génération | PR 4 (test de parité, aucun refus à l'exécution) ; §3 tableau ; §9 axes ; §10 |
| M1 · critères de mesure, variante « transport à part » | C12 ; §8 (tableau des seuils, réglages de production) ; PR 1 (trois verdicts) ; Q3 ; §11 |
| M2 · la PR 4 contredit la conception et range la gravité à deux endroits | E3 réécrit (tranché : réduite, pas renvoyée au lot 7, raison écrite) ; PR 4 réécrite ; §1 point 4 ; §0 (plus d'attente du lot 7) ; §11 |
| M3 · tout ou rien : un seul site, liaison fédérale exclue, point d'injection | C18, C19 ; E2 ; PR 2 (site `ImportFixturesController.php:126-127`, transaction sur la connexion, double `FailingFlushSubscriber`) ; §5 lignes 12 et 13 ; §6 ; §7 parcours 4 |
| M4 · « re-dater emporte ses lignes » était un comportement neuf | C22 ; PR 3a (re-dater garde les lignes, comme aujourd'hui) ; §5 ligne 16 ; §7 parcours 5 |
| M5 · la clé vers les vacances scolaires inverse la déclaration « gardée » | C34 neuf ; §3 (conséquence écrite) ; PR 3a (contrats, tests) ; §6 ; §9 (condition de retour) ; §11 |
| m1 · solde relu pour les deux formats | C31 ; PR 1 (change, tests) ; §5 ligne 9 ; §7 parcours 2 |
| m2 · afficher le message du serveur sur le 403 d'export | C32 ; PR 1 (tableau des textes) ; §5 ligne 10 ; §6 |
| m3 · le 429 du parc ne change pas | PR 2 (`ImportKind::tooManyText()`, aucun texte ne change) ; §3 ; §7 parcours 3 |
| m4 · crédit du PDF jugé sur la saison de la requête | C2, C9 ; PR 1 (change) ; §6 ; test d'abus de saison nommé |
| m5 · nommer le transport des échecs | C33 ; §6 ; §9 documentation |
| m6 · trancher ce qui gate | C35 ; PR 1 et PR 2 (« ce qui gate ») ; §9 autorisés ; §11 |
| m7 · correspondance export et import vers scénario | §5, second tableau, avec la limite de la liaison fédérale |

| Décision retirée | Raison |
|---|---|
| Faire entrer la date du lien du jeton au journal ; `SendKind::COACH_WISH_LINK`, `SendSubject::token`, `lastLinkSentAtByToken`, colonne `coachWishTokenId` | C'est un état remis à nul, pas une trace (B1) |
| `App\Signals\Gravity`, `DiagnosticType`, `SignalFamily`, le champ `gravity` des conflits, `toneOfGravity`, le dossier `Signals/` | Deux endroits pour une gravité ; contraire à « domaine par domaine » (M2) |
| Refus d'un type de diagnostic hors liste à l'écriture | Change la chaîne de génération (B3) |
| Suppression de `ScheduleDiagnosticSeverity` au profit d'une autre énumération | Touchait des fichiers interdits (B2) |
| Texte unique du 429 pour le parc | Rien ne l'exigeait (m3) |
| Envelopper « là où le test est rouge », cinq écritures testées | Un seul site réel ; pas de transaction pendant un appel sortant (M3) |
| « Re-dater une période emporte ses lignes » | Comportement neuf non décidé (M4) |
| « PR 4 après la PR 6b du lot 7 » et son contrôle visuel du radar | La PR 4 ne touche plus les conflits |

**Correction appliquée avec un écart de chiffre** : `smith` compte sept types de diagnostic écrits par le backend ; la relecture en trouve onze (`club_mismatch`, `overlay_entry_missing`, `source_version_missing`, `engine_rejected`, `engine_error` passent par `failSchedule`, `GenerateScheduleHandler.php:120`, `:232`, `:241`, `:310`, `:333`). Le test de parité fait foi, pas le chiffre.

## Recommandation

**Plan validé par le fondateur le 2026-11-27, décisions Q1 à Q3 comprises** (Q3 ne joue que si la mesure est « limite »). Une condition avant le code de la PR 1 : la mesure du PDF, jouée avec les réglages de production. Les PR 2, 3a et 4 n'attendent rien ; la PR 3b attend la fin du lot 5.

Laissé hors périmètre, volontairement : la forme commune des signalements (gravité des conflits, enveloppe « qui et quand »), les envois qui ne sont ni rappels ni envois de campagne, un écran du journal, les quatre dates qui sont des états, la liaison fédérale dans le contrôle d'entrée commun.

Ce que je n'ai pas pu établir et que le plan marque « non vérifié » : le volume des tables de rappel en production, la durée des suites, les réglages de la stack d'épreuve, l'existence d'un test sur l'état « à renvoyer » du jeton, le comportement du dialogue d'export des matchs sur un refus, et l'admission par `FamilyDeclarationSweepTest` d'une référence « meurt avec » vers une famille de niveau plateforme.