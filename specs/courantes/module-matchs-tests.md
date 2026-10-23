# Module matchs (FFBB) — tests & gardes

> Pointeurs NR/Behat du module, par sujet. Découpé mécaniquement de
> [`module-matchs.md`](module-matchs.md) (ancien §12) — contenu déplacé VERBATIM, non réédité,
> plafond `DocSizeCeilingTest`.

## 12. Tests & gardes (pointeurs)

NR tenant isolation bloquant : `MatchTenantIsolationTest` (Competition/Fixture/fenêtres/indispos/
habitudes/passerelles/`opponent_venue_link`/`club_travel_cache`/`ConflictResolution` — étendu à chaque
table tenant du module). **NR tenant GATANT (step nommé `blocking-tests` de `ci.yml` +
`docs/testing/blocking-tests.md`)** : `MessageHandler/ComputeTravelTimesHandlerTest` (GUC posé/
clear, verrou, jamais la ligne d'un autre club). Cache de trajets : `TravelTimeCacheTest` (clé
arrondie, jamais recalculé, profils séparés), `ClubTravelCacheSeedTest` (rejoue le seed de la
migration verbatim, zéro dérive). Client IGN : `IgnRoutingClientTest` (pacing 1/s, 429 réessayé,
journalisé). Progression Mercure : `TravelProgressPublisherTest`. Logo fédéral :
`OpponentLogoApiTest` (401 anonyme, 404, MIME, Cache-Control, code invalide). Contrats cross-stack
(groupe `contract`) : `MatchPlacementContractSchemaTest`,
`ValidateAssignmentsContractSchemaTest`, `HabitPayloadParityTest`,
`MatchVisitDeltaParityTest`, `ClubRulePayloadParityTest` (P4-272 ③, NR bloquant — `MatchConstraint`
scope CLUB ⇄ bloc `clubRules`, falsifié dans les deux sens, RLS), `ForbiddenVenuePayloadParityTest`
(P4-272 ④, NR bloquant — `MatchConstraint` scope TEAM HARD ⇄ `teams[].forbiddenVenueIds`, sur la
BONNE équipe, falsifié dans les deux sens, un scope CLUB ne fuit pas dans le bloc TEAM, RLS).
Alerte de cohérence règle ⇄
créneau idéal : `Unit/Service/ClubRuleCoherenceCheckerTest`. Sémantique solveur `club_rule_no_slot`/
`W_CLUB_RULE`/`team_venue_forbidden` : `engine/tests/semantic/test_match_placement_semantics.py` +
feature Behat `backend/features/placement-des-matchs.feature` (scénarios règle CLUB HARD/PREFERRED,
interdiction de gymnase honorée, seul gymnase ouvert interdit). Tables partagées : un `*ShareTest` par table (`OpponentDirectoryShareTest`
— whitelist `logo_id` compris —, `OpponentVenueSuggestionShareTest`, `EntryDeadlineShareTest`).
Périmètre engagé : `EngagedTeamGuardTest`,
`DeletionImpactParityTest`. Détecteur/radar : `MatchConflictDetectorTest`,
`FixtureConflictsApiTest` + feature Behat `les-conflits-d-un-match-disent-la-verite.feature`
(`ConflictTruthContext`, D1/D1 étendu, statuts joue/coache). Solveur : `test_match_placement*.py` (unit, sémantique, golden épinglé)
+ feature Behat `backend/features/placement-des-matchs.feature`. Import/réconciliation :
`FbiFixtureImporterTest`, `FfbbRencontresApiTest`, `FixtureReviewApiTest`,
`Integration/Service/Geo/OpponentVenueAutoLocatorTest` (le gymnase du fichier localise l'adversaire
tout seul) et `Security/OpponentVenueSuggestionShareTest` (les gymnases d'un adversaire se partagent
en suggestions — promesses des deux features Behat FFBB réelles retirées 2026-10-13) + features Behat
dédiées (`un-domicile-importe-retrouve-son-gymnase` — rebaptisée « pas de salle appariée, pas
d'import — ou alors en terrain neutre » au chantier A PR 2 —, `un-conflit-traite-reste-visible-mais-decompte`,
`une-rencontre-importee-dit-si-elle-est-traitee`). « Pas de salles appariées, pas d'import » +
terrain neutre (chantier A PR 2) : `Integration/Service/ImportVenueRequiredTest` (dépôt refusé 422
AVANT écriture si un domicile créé reste sans gymnase/neutre, nommant les libellés manquants ;
appariement + backfill via `venueMappings`/`venueChoices` ; terrain neutre exclu du solveur et de
« validé ligue » ; mémoire dérivée d'un libellé déjà neutre) et `Security/ImportVenueMappingAbuseTest`
(le canal multipart ne peut jamais écrire un gymnase d'un autre club, un corps forgé, ou un neutre
sur un alias déjà confirmé — zéro écriture sur refus). « Validé ligue », auto au dépôt (chantier A) +
rattrapage en lot : **NR gatant** (step nommé `blocking-tests` de `ci.yml` +
`docs/testing/blocking-tests.md`, axe *constraint semantics*) `Integration/Service/
ImportLeagueAutoValidationTest` (domicile validable d'un championnat commencé basculé au dépôt,
byte-identique à l'ancre du geste manuel ; championnat non commencé/sans heure/dévié jamais validé ;
un terrain neutre (seul chemin restant « sans gymnase » depuis « pas de salles appariées, pas
d'import », chantier A PR 2) n'est jamais validé non plus ; `leagueValidated` exact ; re-dépôt
idempotent). Rattrapage :
`LeagueValidatedFixturesControllerTest` (prédicat, idempotence, guards, verrou
optimiste, `testAnOutOfAccessWindowHomeFixtureStaysEligibleAndTheRadarSignalsIt` — la divergence
ASSUMÉE avec le contrôle d'accès unitaire, figée — et
`testAFutureDeadlineCompetitionIsProposedNowhere`/`testConfirmSkipsAFutureDeadlineCompetition`
(un championnat à échéance future n'apparaît ni au GET ni au POST) et
`testACompetitionWithoutDeadlineIsNamedNotValidated`), `MatchTenantIsolationTest` (étendu — club B
éligible, club A lit 0 et bascule 0) + feature Behat
`un-club-en-cours-de-saison-valide-ses-matchs-en-lot.feature` (`LeagueValidationContext`, cinq
scénarios — échéance passée bascule au `POST` de rattrapage, **championnat déjà commencé validé
PAR LE DÉPÔT seul** [chantier A, sans aucun geste en lot], échéance non passée, premier match joué
sans échéance, amical passé auto-validé). Registre « à corriger dans FBI » :
`FbiCorrectionApiTest` (lecture/close/reopen, 404 cross-club, 403 membre sur close),
`MatchTenantIsolationTest` (étendu) + feature Behat `ce-que-fbi-doit-refleter.feature`
(`FbiCorrectionContext`, suite `fbi-a-corriger`). Front : suites Vitest sous
`frontend/src/features/matches/` (`lib/*.test.ts` pour chaque dérivation pure, `*.test.tsx` par
écran) + e2e `frontend/tests/e2e/matches*.spec.ts`.
