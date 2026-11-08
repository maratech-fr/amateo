# Fermeture de gymnase → matchs « à placer » (besoin B) et bac à sable (besoin A) — arrêtés, gardés pour une réouverture

> **Statut : arrêté, non planifié** (décision fondateur 2026-11-07, après cadrage, plan et deux contre-revues).
> Ce fichier garde le besoin, l'état vérifié du code, le plan final et les objections pour qu'une réouverture ne
> reparte pas de zéro. Décision fermée : [`../courantes/etat-des-lieux.md`](../courantes/etat-des-lieux.md) §2.
> Aucune ligne de roadmap n'ouvre le sujet ; la ligne qui en dépend (rattachement d'un domicile à un gymnase
> fermé) est P4-511 de [`roadmap.md`](roadmap.md). Contexte des fermetures :
> [`fermetures-de-gymnase-plan.md`](fermetures-de-gymnase-plan.md).
>
> Les `fichier:ligne` ci-dessous ont été relus sur `main` le 2026-11-07 (les écarts avec le plan d'origine sont
> signalés « ≠ plan »). Les fichiers cités sans dossier sont sous `backend/src/` ; une réouverture relit les
> lignes avant de s'y fier.

---

## Partie 1 — Besoin B : une fermeture met les matchs concernés « à placer »

### 1.1 Le besoin

À l'enregistrement d'une fermeture de gymnase (« Mistral fermé du 26 au 28/12 »), les matchs **à domicile**
déjà placés (PLACED / SUBMITTED / VALIDATED), **à venir**, dans ce gymnase, aux dates fermées, passent « à placer » :
la salle est vidée, la date et l'horaire sont gardés, `placementSource` est effacé, une raison persistante dédiée
dit « gymnase retiré par une fermeture ». L'aperçu **avant le OK** annonce l'effet : nombre, matchs nommés, « dont N
déjà déclarés à la fédération ».

Décisions du fondateur :

1. Un match **déjà déclaré à la fédération** (SUBMITTED / VALIDATED) passe aussi « à placer », avec l'avertissement
   « à re-déclarer ».
2. Fermeture **raccourcie ou supprimée** ensuite : les matchs **restent** « à placer », aucun retour automatique
   (aucun historique de l'état d'avant n'est rejoué).
3. Les **entraînements** ne sont pas replanifiés automatiquement (verrous retirés, plan périmé, « Adapter » : comportement
   existant inchangé).
4. **L'appli sert à anticiper, pas au jour J** : « à venir » = `matchDate >= aujourd'hui du club` (aujourd'hui
   inclus, horloge simulée respectée), **aucune comparaison d'heure de coup d'envoi**. Une fermeture déclarée le
   jour même dépointe aussi le match du jour, sous la responsabilité du gestionnaire.
5. « Charger » et « Valider » ne changent pas (voir partie 2).

### 1.2 L'existant vérifié (main au 2026-11-07)

- **L'annonce existe déjà** : `Service/VenueClosureImpact.php:136-153` liste `matchesToMove` (domiciles du gymnase,
  fenêtre `$from..$until`, `matchDate >= today` via `ClubDay::todayFor` :90, `status != UNPLACED`) sur TOUTE la
  fenêtre — alors que `toRemove` (épingles) ne porte que les jours nouveaux (`$newDates`, :161-166). Servi par
  `GET /api/venue-closure-impact` et `/api/calendar-entries/{id}/impact` (`VenueClosureImpactController.php:44-103`).
  Front : `ClosureImpactPanel.tsx:50-62` (5 matchs + « + n autres »).
- **Les matchs restent placés** : rien n'écrit sur `Fixture` à l'enregistrement. Les trois portes qui font naître des
  dates fermées passent toutes par `ClosurePinRemover::applyAll`, sous `lockClubWindows` : (1) POST de la contrainte
  `venue_closed` (`State/Processor/ConstraintStateProcessor.php:68-87`, `$before`/`$new`/`applyAll` :78-83 ; le front
  enchaîne entrée puis contrainte, `frontend/src/features/cockpit/queries.ts:312-330`) ; (2) re-datage
  `CalendarEntryStateProcessor::processPut` :319-342 (409 « aperçu requis » :336-338) ; (3) élargissement
  `Controller/ClosureWindowApplyController.php:125-132`. `ClosurePinRemover::apply` (`Service/ClosurePinRemover.php:113-167`)
  prend le verrou de génération du club (:118-121, 409 sinon), retire verrous + réservations, trace `ENTITY_DELETED`
  (:152-161) ; `preview`/`previewTotals`/`announcement` sont de la lecture pure.
- **Le radar signale** : `Service/ConflictRadarLoader.php:85-98` charge les fermetures `venue_closed` et signale un
  match posé dans un gymnase fermé à sa date (« jamais de dé-placement automatique »).
- **Replacer dans un gymnase fermé est refusé** : `State/Processor/FixtureStateProcessor.php:290-308` (422 « … est
  fermée du … au … : le match ne peut pas y être placé. Choisissez un autre gymnase. ») ; le placement solveur exclut
  le gymnase fermé (`Service/MatchPlacementPayloadBuilder.php:250-263`).
- **Mécanisme réutilisable** : `Service/FixtureVenueLossMarker.php` (`markWith`, :42 ; UPDATE DQL `venueId=NULL,
  status=UNPLACED, placementSource=NULL, unplacedReason=VENUE_LOST`, raison figée) — ≠ plan : la signature n'a pas
  encore de paramètre `$reason` (le plan l'ajoute). `OrphanedFixtureFinder::impact` compte SUBMITTED/VALIDATED et
  borne club+saison explicitement (« filtres Doctrine éteints en worker/CLI »). Seule gâchette actuelle :
  `Controller/ValidateScheduleController.php:252-262` (inchangée).
- **Raison** : enum `Enum/FixtureUnplacedReason.php` (`VENUE_LOST`, `NEUTRAL_GROUND`, :34-36) ; effacée par
  `Fixture::setVenueId` non nul (`Entity/Fixture.php:487-503`) ; servie par `ApiResource/FixtureResource.php:164-170`
  (chaîne libre) ; colonne `unplaced_reason` déjà libre (aucune migration). Front : `matches/api/fixtures.ts:53`,
  `matches/lib/unplacedReasonLabel.ts:12-17`, `UnplacedList.tsx:64-69`, `PlacementPanel.tsx:316-324` (condition
  `"venue_lost" ===`), `matches/texts.ts:308 panelVenueLostHint`.
- **Tests gatés concernés** (`ci.yml:735,741`) : `Security/ClosureRemovesPinsAtomicallyTest`,
  `Security/ClosureImpactSaysTheSameEverywhereTest` (assertion :69 `preview.matchesToMove === saved.matchesToMove`,
  contredite par le besoin), `Integration/Service/VenueClosureImpactTest` :48-66 ; Behat
  `backend/features/une-fermeture-du-calendrier-s-applique-aux-matchs.feature:31-37` (le scénario « le radar signale
  le match déjà dans ce gymnase » serait contredit). Rôle Membre : `AbstractStateProcessor.php:89 assertManager()`,
  `ClosureWindowApplyController.php:67` ; gymnase étranger : `assertClosedVenueBelongsToTheClub`
  (`ConstraintStateProcessor.php:229-238` → 422), NR `MatchTenantIsolationTest.php:240-271`.

### 1.3 Le plan final (plan d'origine + révision 1, tel que relu)

Lane Full, d'abord prévue en 1 PR (la contre-revue la recommande en 2, voir 1.4). Axes §7.1 : planning lifecycle +
isolation entre clubs. `/security-review` requise, renforcée (écriture système sur `Fixture` + chemin FFBB entrant +
audit). Interdits : engine, migrations, `ValidateScheduleController`, `StructureRestorer`, `VersionEditability`,
`MatchPlacementResultApplier` (le verrou rend sa relecture inutile), `ci.yml`.

**Conception**

- Foyer d'écriture unique : `ClosurePinRemover::apply` appelle un nouveau `ClosedVenueFixtureUnplacer` après les
  épingles (même transaction, même verrou, trois portes héritées) ; rien dans `CalendarEntryStateProcessor::processPost`.
- **Fenêtre ENTIÈRE** : l'effet porte sur toute la fenêtre de la fermeture ÉCRITE ∩ saison (même calcul que
  `VenueClosureImpact::compute` :79-89), pas sur les seuls jours nouveaux : annonce == effet == ce que `compute`
  nomme déjà. Pas l'union de toutes les fermetures du gymnase (sinon annonce ≠ effet). Le stock de matchs placés
  avant le lot dans un gymnase déjà couvert est repris au premier geste sur ce gymnase. Les épingles restent sur les
  jours nouveaux. `ClosurePinRemover::applyAll` gagne `array<string,array<string,true>> $closureWindowByVenue`
  à côté de `$newByVenue` ; fenêtre post-écriture de l'entrée racine par gymnase (création : `scopeTargetId` ; PUT :
  `closureConstraintIdByVenue($rootId)` `CalendarEntryStateProcessor.php:375-387` ; `/window` : contraintes de
  `$computed->rootEntryId`, `ClosureWindowApplier.php:127`).
- `ClosedVenueFixtureUnplacer` (patron `OrphanedFixtureFinder`) :
  `preview(clubId, seasonId, venueId, array $dates /* array<string,true> Y-m-d */)` →
  `list<{fixtureId, teamId, teamName, opponentLabel, matchDate, kickoffTime|null, declared}>` — HOME, `venueId = V`
  ET `EXISTS Venue(id=V, clubId, seasonId)`, `clubId/seasonId` explicites, `matchDate IN dates`, `matchDate >=
  ClubDay::todayFor(club)`, `status != UNPLACED`, tri date/heure/équipe, `declared` = SUBMITTED ou VALIDATED ;
  `apply(clubId, seasonId, venueId, dates, ?constraintId)` → `{matches, declaredMatches}`. Idempotent.
- `apply` écrit par match : `markWith(ids, VENUE_CLOSED)` ; pour les `declared`, un UPDATE DQL `SET f.fbiEcho` =
  `{field:'venue', value:<nom de l'ancienne salle>, at:now}` (affiché « FBI affiche {value} » une fois replacé,
  `FbiEntryList.tsx:322-324`, effacé au retour SUBMITTED/VALIDATED `Fixture::setStatus` :369-371 — `fbiEcho` doit
  porter `fbiVenueLabel`, pas le nom interne : voir 1.4 passe 2) ; une trace `AuditAction::FIXTURE_UNPLACED` par
  match, détails `{cause:'venue_closed', constraintId, fixtureId, previousVenueId, previousStatus,
  previousPlacementSource, declared}`.
- **Verrou de placement** : `ClosurePinRemover::apply` prend `MatchPlacementLock::acquire($clubId, 60)`
  (`Service/MatchPlacementLock.php:27`, patron `ClubGenerationLock`) juste après le verrou de génération, rendu en
  `finally` **avant le commit côté contrat** (voir 1.4 passe 2) ; échec → `MatchPlacementInProgressException` →
  `EventListener/ClosureWriteConflictListener.php` rend 409 `{code:'placement_in_progress', error}`. Prendre (pas
  seulement lire) empêche aussi qu'un run démarre pendant le dépointage → ferme la course de
  `MatchPlacementResultApplier.php:38-50`.
- **Garde synchro FFBB** : `Service/Fbi/FbiDeviationService.php:296-308` `attachConfirmedVenue` rend `false` sans
  écrire si une fermeture `venue_closed` du club+saison couvre `matchDate` pour le gymnase résolu — prédicat
  `PlanVenueClosures::closureIntervals` + `MatchConflictDetector::dateInsideClosure` (:379), le même que le refus
  manuel. `applyVenueTakeFile` (choix inline, :419-427) refuse nommé avant toute écriture (422 `ImportRejectedException`).
  Pas de garde dans `LeagueValidationOutlook::passesPredicate` (:331-337 exige déjà `venueId` non nul ; `NO_VENUE`
  :340-350).
- **Contrôle à la création** : `Dto/ConstraintInput::$expectedFixtureIds?: list<string>` ; dans la transaction, si
  l'ensemble effectif diffère → `ClosurePreviewStaleException` → 409 `{code:'closure_preview_stale', error,
  matches}`, rien d'écrit ; absent → aucun contrôle (scripts/Behat inchangés). Le 409 « aperçu requis » du re-datage
  s'étend aux matchs : seuil `locks>0 || reservations>0 || matches>0` (`CalendarEntryStateProcessor.php:336`).
- `VenueClosureImpact::compute` : `matchesToMove` via `fixtureUnplacer->preview` (dates pleines) avec `declared` ;
  champ `matchesUnplacedAtDeclaration: bool` (audit `fixture.unplaced` portant un `constraintId` de la famille) ;
  `removedAtDeclaration` garde son sens (épingles).

**Contrats nouveaux**

- `FixtureUnplacedReason::VENUE_CLOSED = 'venue_closed'` ; `AuditAction::FIXTURE_UNPLACED = 'fixture.unplaced'` ;
  `FixtureVenueLossMarker::markWith(EntityManagerInterface, array $fixtureIds, FixtureUnplacedReason $reason =
  VENUE_LOST)` et `mark(...)` (défaut inchangé).
- `apply`/`applyAll`/`previewTotals` rendent `{locks, reservations, matches, declaredMatches}` ; `announcement`
  gagne `matches[]`.
- Réponses : `POST /api/constraints` (201), `PUT /api/calendar_entries/{id}` (200), `POST /api/schedule_plans/{id}/window`
  (200) → `removed: {locks, reservations, matches, declaredMatches}` ; 409 `closure_pins_preview_required` →
  `toRemove` (4 nombres) + `pinRemoval.matches[]` ; `POST …/window-preview` → `pinRemoval.matches[]` ; `GET …impact` →
  `matchesToMove[].declared` + `matchesUnplacedAtDeclaration` ; `FixtureResource.unplacedReason` peut valoir
  `"venue_closed"` ; 409 `placement_in_progress` ; 409 `closure_preview_stale`. OpenAPI : `ClosureWindowPaths.php`
  (+ `matchesUnplacedAtDeclaration`, `removed`) et `specs/courantes/openapi-snapshot.json`.
- Front : `ClosureRemoved = {locks; reservations; matches; declaredMatches}` ; `PinRemoval.matches` ;
  `ClosureImpact.matchesUnplacedAtDeclaration` ; `Fixture.unplacedReason: "venue_lost"|"venue_closed"|"neutral_ground"|null` ;
  `unplacedReasonLabel("venue_closed")` ; `COCKPIT_TEXTS` : `closureMatchesUnplaced(n)`, `closureMatchesDeclared(n)`,
  `closureSavedToast(locks, reservations, matches, declaredMatches, venue)`, `removedSuffix`/`removedSentence(…,
  declaredMatches)`, `deleteClosureNothingRestored(pins, matches)`, `closurePreviewStale` ; `MATCHES_TEXTS.panelVenueClosedHint` ;
  composant partagé `MatchesToUnplaceList` (panneau + `PinRemovalNotice`) ; `PlacementPanel.tsx:319`
  `"venue_lost"===r || "venue_closed"===r` ; `useCreateVenueClosure` envoie `expectedFixtureIds` et, sur le 409
  `closure_preview_stale`, refetch l'aperçu, rouvre le formulaire avec `closurePreviewStale`, bouton inactif jusqu'à relecture.
- Invalidation du cache front après fermeture / re-datage / élargissement : `["fixtures"]` (préfixe : liste, radar,
  `["fixtures","league-validation"]`) et `["matches","deadline-outlook"]` (compteur « à saisir »).

**Textes FR exacts**

- Aperçu, en-tête : `1 match passera « à placer » (sa salle sera vidée, son horaire gardé) :` / `N matchs passeront
  « à placer » (leur salle sera vidée, leur horaire gardé) :` ; lignes `SM1 contre Les Loups, samedi 27/12 à 18:00`,
  5 puis `+ n autre(s)` ; déclarés : `dont 1 déjà déclaré à la fédération (à re-déclarer après replacement).` /
  `dont N déjà déclarés à la fédération (à re-déclarer après replacement).` ; aucun : `Aucun match placé à déplacer.`
- Badge « À placer » (vrai même après suppression/raccourcissement de la fermeture) : `Gymnase retiré par une
  fermeture` ; rappel du panneau de placement : `Gymnase retiré par une fermeture — choisissez-en un autre.`
- Toast création : rien → `Indisponibilité enregistrée.` ; matchs seuls → `Indisponibilité enregistrée — 3 matchs
  passés « à placer ».` ; avec déclarés → `… — 3 matchs passés « à placer », dont 1 déjà déclaré à la fédération.` /
  `… dont 2 déjà déclarés à la fédération.` ; épingles + matchs → `Indisponibilité enregistrée — 2 verrous et 1
  réservation retirés à Mistral ; 3 matchs passés « à placer », dont 1 déjà déclaré à la fédération.` Re-datage /
  élargissement : même suffixe après `Dates modifiées`.
- Confirmation de suppression (`VenueClosureCard.tsx:69-71`) : épingles seules → texte actuel ; matchs seuls →
  `Les matchs passés « à placer » à sa déclaration restent à replacer.` ; les deux → `Les verrous et réservations
  retirés à sa déclaration ne sont pas remis, et les matchs passés « à placer » restent à replacer.` ; aucun →
  aucune ligne.
- 409 placement : `Un placement de matchs est en cours pour ce club : réessayez quand il est terminé.` ; aperçu
  périmé : `L'aperçu a changé depuis sa lecture : relisez-le, puis enregistrez.` ; refus FFBB choix inline : `Ce
  gymnase est fermé à la date du match — choisissez-en un autre.`

**Tableau décision → test** (brief du rédacteur des tests rouges)

| # | Décision | Test |
|---|---|---|
| D1' | Fenêtre ENTIÈRE : un match sur un jour déjà fermé par une autre fermeture (stock) est annoncé et dépointé ; témoins intacts (extérieur, autre gymnase, passé, déjà à placer, hors fenêtre) ; preview == apply ligne à ligne | `Integration/Service/ClosedVenueFixtureUnplacerTest` (nouveau), `Security/ClosureRemovesPinsAtomicallyTest` |
| D2 | `declared` = SUBMITTED/VALIDATED comptés et dépointés ; `removed.declaredMatches` | mêmes fichiers |
| D3' | `removed.matches == count(preview.matchesToMove)` sur fenêtre pleine ; après le OK, `/impact` → `[]` et `matchesUnplacedAtDeclaration === true` | `Security/ClosureImpactSaysTheSameEverywhereTest` (gate ; réécriture de l'assertion :69) |
| D4 | Re-datage / élargissement `/window` qui dépointerait ≥1 match → 409 `closure_pins_preview_required` (`toRemove.matches` + `pinRemoval.matches`) ; `confirmPinRemoval` → appliqué | `ClosureRemovesPinsAtomicallyTest` |
| D5 | Raccourcir/supprimer : rien ne revient, raison persistante | `ClosedVenueFixtureUnplacerTest` + cas API de `ClosureRemovesPinsAtomicallyTest` |
| D6 | Génération en vol → 409, match intact ; échec injecté → rollback du match aussi | `ClosureRemovesPinsAtomicallyTest` |
| D7 | Idempotence (second passage = 0) | `ClosedVenueFixtureUnplacerTest` |
| D8 | Audit `fixture.unplaced` ; `matchesUnplacedAtDeclaration` vrai après dépointage seul | `ClosureRemovesPinsAtomicallyTest` + `Integration/Service/VenueClosureImpactTest` |
| D9 | Club A vise le gymnase de B → 422, match de B intact ; `apply(clubA,…,venueB)` → 0 | `MatchTenantIsolationTest::testAClosureAimedAtAForeignVenueNeverReachesTheOtherClub` (gate) + `ClosedVenueFixtureUnplacerTest` |
| D10 | Membre → 403, aucune écriture | `ClosureRemovesPinsAtomicallyTest` |
| D12 | Validation : garde `VENUE_LOST` inchangée | `DeletionImpactParityTest` reste vert |
| D13 | Panneau : en-têtes singulier/pluriel, « dont N déclarés », 5 + « + n autres », notice de re-datage | `ClosureImpactPanel.test.tsx` |
| D14 | Toasts (création, re-datage, élargissement) | `VenueClosureForm.test.tsx`, test de `ClosureWindowDialog` |
| D15/D28 | Confirmation de suppression, 4 combinaisons | `VenueClosureCard.test.tsx` |
| D16 | Badge/rappel `venue_closed` | `unplacedReasonLabel.test.ts`, `UnplacedList.test.tsx`, `PlacementPanel.test.tsx` |
| D17/D29 | Behat : scénario API « une fermeture déclarée par l'appli fait repasser à placer le match déjà posé, 15h00 gardé, gymnase retiré » (nouvelle étape qui POSTe l'entrée puis la contrainte, patron `MatchTenantIsolationTest.php:250-259`) ; le scénario 3 existant est retitré « Un match déjà posé dans un gymnase qu'une fermeture couvre sans être passée par l'appli est signalé par le radar » (Given SQL = cas stock) | `une-fermeture-du-calendrier-s-applique-aux-matchs.feature` + `tests/Behat/MatchPlacementContext.php` |
| D18 | OpenAPI (`declared`, `removed`, `unplacedReason`, `matchesUnplacedAtDeclaration`) | `OpenApiSnapshotMatchesTheLiveContractTest`, `TsFieldsMatchOpenApiSchemaTest` |
| D19 | Libellés purs | sans test, déclaré |
| D20 | Garde synchro, deux sens : alias → gymnase FERMÉ à la date → `attachConfirmedVenue` rend `false`, `venueId` reste NULL, `venue_closed` conservé ; gymnase OUVERT (ou fermé une autre date) → reposé comme avant ; via `FfbbRencontreReconciler` ET import xlsx | `Integration/Service/FbiDeviationServiceClosureGuardTest` (nouveau) + cas dans `FfbbRencontresApiTest` |
| D21 | Choix inline d'un gymnase fermé à la date → 422 nommé, zéro écriture ; ouvert → posé | `FbiDeviationServiceClosureGuardTest` |
| D22 | Un match dépointé n'est jamais auto-« validé ligue » au dépôt ; témoin : replacé ailleurs il l'est | `ImportLeagueAutoValidationTest` |
| D23 | Verrou de placement tenu → 409 `placement_in_progress`, rien d'écrit ; libre → le geste passe et le verrou est rendu | `ClosureRemovesPinsAtomicallyTest` |
| D24 | Trace complète par match ; `fbiEcho` posé sur les déclarés seulement, effacé au retour SUBMITTED | `ClosureRemovesPinsAtomicallyTest` + `ClosedVenueFixtureUnplacerTest` |
| D25 | Jour J inclus : match du jour à 20:00, horloge du club à 09:00 → dépointé ; match d'hier intact ; horloge simulée respectée | `ClosedVenueFixtureUnplacerTest` |
| D26 | Aperçu périmé : `expectedFixtureIds` ≠ effectif → 409, rien d'écrit ; égal → 201 ; absent → pas de contrôle | `ClosureRemovesPinsAtomicallyTest` + `VenueClosureForm.test.tsx` |
| D27 | Invalidation `["fixtures"]` et `["matches","deadline-outlook"]` sur les 3 mutations | `cockpit/queries.test.tsx` |

Non-régression FFBB : `ImportLeagueAutoValidationTest`, `FfbbRencontresApiTest`, `FbiFixtureImporterTest`,
`FbiArrivalReviewWindowTest` restent verts (aucun décor n'a de fermeture) ; `FbiDeviationService` gagne la
dépendance `PlanVenueClosures` (ses tests unitaires construits à la main la reçoivent). NR : cas ajoutés dans des tests
déjà listés (`ci.yml:511,735,741`), aucune modification de `ci.yml`.

**Parcours de bout en bout**

1. Créer : Mistral fermé 26→28/12 ; aperçu « 2 matchs passeront « à placer »… » dont 1 déclaré ; Enregistrer ; toast ;
   `/matchs` : les deux « À placer », badge, heure gardée ; Mistral grisé au placement ; replacé à Brel → badge disparaît.
2. Supprimer la fermeture : confirmation ; les matchs restent à placer, Mistral redevient choisissable.
3. Raccourcir : `removed = {0,0,0,0}`, rien ne revient ; élargir (→ 29/12, un match) : notice puis toast `Dates
   modifiées — 1 match passé « à placer ».`
4. Variantes : extérieur, passé, déjà à placer, autre gymnase, autre club intacts ; Membre → 403 ; génération en vol → 409.
5. Idempotence : second passage = 0.
6. Synchro FFBB après la fermeture : SM1 contre Les Loups (27/12, 18:00, Mistral) dépointé ; le libellé FBI
   « MISTRAL » a un alias confirmé → la synchro ne repose PAS Mistral ; le match reste « À placer », le bandeau
   « validé ligue » le liste « sans gymnase » ; placé à Brel → ligne « à saisir » `FBI affiche Mistral` ; après
   re-saisie FBI → SUBMITTED, mémo effacé. Témoin : alias vers un gymnase OUVERT → reposé comme aujourd'hui.
7. Un placement est en cours : Enregistrer → toast 409, rien d'écrit ; run fini → le geste passe.
8. Aperçu périmé : aperçu lu (2 matchs), un collègue place U13 le 26/12 à Mistral ; Enregistrer → 409, panneau relu
   (3 matchs) + message, Enregistrer → 3 passés.
9. Stock d'avant le lot : match placé le 27/12 à Mistral alors qu'une fermeture 26→28/12 existait déjà ; élargir au
   29/12 → l'aperçu nomme le match du 27 → il passe « à placer ».
10. Jour J : fermeture déclarée aujourd'hui couvrant aujourd'hui, match ce soir à 20:00 → dépointé aussi.

**Fichiers** (≈ 26 sources + ≈ 14 de tests au plan révisé)

Backend : `Enum/FixtureUnplacedReason.php`, `Enum/AuditAction.php`, `Service/FixtureVenueLossMarker.php`, NOUVEAU
`Service/ClosedVenueFixtureUnplacer.php`, `Service/ClosurePinRemover.php`, `Service/VenueClosureImpact.php`,
`Exception/ClosurePinsPreviewRequiredException.php`, NOUVEAU `Exception/MatchPlacementInProgressException.php`
(+ `ClosurePreviewStaleException`), `EventListener/ClosureWriteConflictListener.php`, `Dto/ConstraintInput.php`,
`ApiResource/ConstraintResource.php:89-95`, `CalendarEntryResource.php:90`, `FixtureResource.php:164-170`,
`OpenApi/PathContributor/ClosureWindowPaths.php:83,139-146`, `Service/Fbi/FbiDeviationService.php`,
`Service/ConflictRadarLoader.php:85-89` (commentaire), `specs/courantes/openapi-snapshot.json`. Front :
`cockpit/api.ts`, `cockpit/queries.ts`, `cockpit/texts.ts`, `ClosureImpactPanel.tsx`, `VenueClosureForm.tsx`,
`ClosureWindowDialog.tsx`, `VenueClosureCard.tsx`, `matches/api/fixtures.ts`, `matches/lib/unplacedReasonLabel.ts`,
`matches/PlacementPanel.tsx`, `matches/texts.ts`. Docs à recaler à la livraison : `docs/architecture/adr-0002-pattern-plan.md`
(§ « Enregistrer vaut geste » : + matchs), `backend/docs/backend-controllers.md:437`, `docs/testing/blocking-tests.md:260,262`,
`docs/testing/tests-par-parcours.md:189-204`, `specs/courantes/module-matchs-modele-donnees.md:39`,
`specs/courantes/accueil-cockpit-temporel.md`, `frontend/docs/frontend-features.md:137`, docblocks
`FixtureVenueLossMarker`, `FixtureUnplacedReason`, `ConflictRadarLoader`. Contrôle visuel nécessaire (formulaire avec
matchs / avec déclarés / 8 matchs « + 3 autres », toasts, notice de re-datage, confirmation de suppression, colonne
« À placer » avec badge, panneau de placement) ; aucun e2e n'asserte les textes touchés.

### 1.4 Les objections de la contre-revue

**Première passe** (a conduit à la révision 1 : fenêtre entière, garde FFBB, verrou, trace complète, pas d'heure)

- Le match dépointé pouvait **repartir en silence dans le gymnase fermé** par la synchro FFBB
  (`Service/Fbi/FbiDeviationService.php:296-308`, `attachConfirmedVenue` : tout domicile `venueId NULL` dont le
  libellé a un alias confirmé, appelé par `FfbbRencontreReconciler.php:178,322`, l'import xlsx
  `FbiFixtureImporter.php:429,1337,1426` et « prendre le fichier » `FbiDeviationService.php:444`), puis être **validé
  ligue** au dépôt suivant (`Service/FbiFixtureImporter.php:470-478`, via `LeagueValidationOutlook::fixturesToConfirm`) ;
  `setVenueId` efface alors `venue_closed` (`Fixture.php:494-495`).
- Fenêtre « jours nouveaux » **incohérente** avec l'annonce (qui nomme toute la fenêtre) et avec le stock d'avant le lot.
- **Course avec un run de placement** : `Service/MatchPlacementResultApplier.php:38-50` repose `venueId` sans relire
  les fermetures nées pendant le run.
- **Trace d'audit sans l'état d'avant** (gymnase, statut, source de placement, déclaré) : rien pour comprendre ni défaire.
- **Cache front non rafraîchi** : `cockpit/queries.ts` — `useCreateVenueClosure.onSuccess` (:356-361) n'invalide que
  `["fixtures","conflicts"]`, `["venue_closures"]`, `["venue-closure-impact"]` ; `useRedateEntry` (:577) et
  `useApplyClosureWindow` (:627) n'invalident aucune clé matchs ; `useDeleteEntry` (:553) rien.
- **Behat qui insérait en SQL** : l'étape « une fermeture du calendrier couvrant le samedi sur ce gymnase »
  (`tests/Behat/MatchPlacementContext.php:521-566`) n'appelle aucun processor, donc ne prouverait pas l'effet réel.

**Deuxième passe** (sur la révision 1 ; non intégrée — c'est elle qui fait passer le coût à L)

- **La garde ne couvrait qu'une porte sur cinq.** Trois autres chemins reposent un gymnase sur un domicile dépointé
  sans lire les fermetures : `Service/Basketball/VenueExternalLabelAttacher.php:87-99` (`attach`, boucle sur les
  domiciles `venueId NULL` dont le libellé égale l'alias), appelé par `POST /api/venues/{id}/external-labels`
  (`Controller/VenueExternalLabelController.php`, appel `attach` :125 — ≠ plan : :121-122), par l'import xlsx
  (`Service/FbiFixtureImporter.php:1147`, avant la passe « validé ligue »), et par le choix inline
  (`FbiDeviationService.php:422`) ; et `VenueExternalLabelController::reassignHomeFixtures` (:185-212, appelé :154), qui
  re-pointe tous les domiciles non placés du libellé, quel que soit leur gymnase actuel.
- **Annonce « fenêtre entière » mais effet filtré par les jours nouveaux** : les sorties anticipées de
  `ClosurePinRemover.php:115-117` (liste de dates nouvelle vide → rien), `applyAll` (:264) et
  `CalendarEntryStateProcessor.php:333` ne laissent pas passer le stock : la fenêtre pleine doit être portée jusqu'à l'effet.
- **La garde FFBB copiée du refus manuel ne ferait rien hors requête HTTP** : `FixtureStateProcessor.php:290-293`
  lit `_club_id` / `_season_id` dans la requête courante ; la synchro (worker, CLI) n'en a pas → il faut lire le club
  et la saison sur la rencontre elle-même.
- **`expectedFixtureIds`** : comparer des **ensembles**, sur **tous** les ids (pas les cinq affichés), et traiter le
  `catch` du front qui **supprime l'entrée** déjà créée sur 409 (sinon le geste laisse une entrée orpheline).
- **`fbiEcho` doit porter `fbiVenueLabel`** (le libellé fédéral que FBI affiche), pas le nom interne du gymnase.
- **Textes devenus faux après suppression de la fermeture** : « choisissez-en un autre » (le gymnase peut être
  redevenu libre) et « restent à replacer » (selon la combinaison épingles / matchs) → d'où les libellés « Gymnase
  retiré par une fermeture » et les quatre combinaisons de la confirmation de suppression.
- **Tests oubliés** : `ClosurePinRemoverTest` (nouvelle forme de retour) et `cockpit/queries.test.tsx`.
- **Verrou de placement rendu avant le commit** : le `finally` libère le verrou avant que la transaction englobante
  soit validée ; fenêtre de quelques millisecondes où un run démarre sur l'état d'avant. Même patron que la génération
  — à rendre après le commit (ou à documenter comme accepté).
- **Découpe recommandée en 2 PR** : **PR 1** = garde « gymnase fermé à cette date » sur les cinq portes d'écriture
  de salle d'un domicile dépointé (`attachConfirmedVenue`, `VenueExternalLabelAttacher::attach`,
  `reassignHomeFixtures`, le choix inline, l'import xlsx), avec sa propre `/security-review` ; **PR 2** = dépointage,
  verrou, annonce, `expectedFixtureIds`, front.

### 1.5 Pourquoi on s'est arrêté

Coût estimé S–M à l'origine, **réévalué L en 2 PR** (plus de 30 fichiers, deux revues de sécurité) pour un **gain
modeste** : aujourd'hui l'annonce nomme déjà « Matchs à déplacer », le radar signale chaque conflit et replacer
dans un gymnase fermé est refusé — le gestionnaire déplace ses matchs un par un. Décision du fondateur, 2026-11-07.

### 1.6 Conditions de réouverture et marche à suivre

Rouvrir si un fait nouveau pèse sur le gain : fermetures fréquentes touchant beaucoup de matchs d'un coup (une
fermeture longue de salle principale en pleine phase de championnat), ou plaintes de gestionnaires qui oublient de
déplacer des matchs que le radar signale pourtant. Marche à suivre : relire ce dossier en entier ; relire les
`fichier:ligne` (ils bougent) ; repartir de la **PR 1** (garde sur les cinq portes d'écriture de salle, utile même sans
le dépointage — elle ferme P4-511 de la roadmap) ; reprendre ensuite la PR 2 avec les corrections de 1.4 ; refaire un
cadrage `oracle` seulement si le besoin a changé.

---

## Besoin A abandonné : bac à sable

### A.1 Le besoin

« Rien n'est acté avant Valider », structure du club comprise : modifier les équipes, gymnases, coachs ou
contraintes permanentes en explorant une version ne devrait rien changer pour les matchs, le radar ni le reste tant que
le planning n'est pas validé.

### A.2 L'état réel vérifié

- La structure du club est **UN SEUL ensemble vivant par saison**, plus **une photo par version** :
  `docs/architecture/adr-0002-pattern-plan.md:199-202` (point 6 : structure partagée, indépendance des versions par la
  photo) ; l'alternative « chaque version possède ses équipes » est **écartée** (`:381-384` : explosion de données,
  ambiguïté « quelle version j'édite »).
- « Charger » **écrase** la structure vivante : `Service/StructureRestorer.php:93-130` (`apply`) et `:266-273`
  (`wipeStructure`) ; il **efface pour de bon** les lignes d'absence des coachs absents de la photo
  (`eraseStaffLinesOfCoachesAbsentFromPhoto`, :131-152) et **recrée les liens de collecte** (`coachWishTokenSync->syncSeason`,
  :126-129 : les anciens liens sont perdus). Sa confirmation front (`frontend/src/features/planning/PlanningPage.tsx:1609-1621`)
  ne le dit pas : P4-509 de la roadmap.
- « Valider » est le **seul geste** qui met « à placer » les matchs dont le gymnase n'existe plus
  (`Controller/ValidateScheduleController.php:252-262`, `OrphanedFixtureFinder` + `FixtureVenueLossMarker`).
- Supprimer à la main un gymnase qui a des matchs est **refusé** (`State/Processor/VenueStateProcessor.php:58-60`).

### A.3 Les trois directions chiffrées

| Direction | Contenu | Coût |
|---|---|---|
| A1 | Garder une seule structure vivante et rendre « Charger » **sans perte** (ne plus effacer les absences ni perdre les liens de collecte) | M |
| A2 | Une **structure de travail à part**, promue à « Valider » | L — amende l'ADR-0002 (point 6 et l'alternative écartée) + migration |
| A3 | Les matchs et le radar **lisent la photo** de la version validée plutôt que la structure vivante | M–L |

### A.4 Pourquoi abandonné

La structure d'un club ne change presque jamais en cours de saison ; on planifie la saison avant d'avoir des matchs ;
personne ne s'en plaint ; coût disproportionné. Décision fondateur 2026-11-07 : la structure reste un ensemble vivant,
« Charger » l'écrase, « Valider » reste le seul geste qui met des matchs « à placer ». La ligne de roadmap qui portait
ce besoin (matchs « à placer » quand la salle disparaît, Valider ne touche plus aux matchs) est fermée « non retenue ».
