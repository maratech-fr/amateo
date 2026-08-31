Last verified @ 2026-08-31 (P2-51 PR-5, `documentation-update` — régénéré depuis le backend en
tournant : `docker compose exec php-fpm php bin/console api:openapi:export`, après
`docker compose restart php-fpm` (opcache). **191 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`)
✓, count INCHANGÉ (aucun path ajouté/retiré, le body du POST existant a changé) · SHA-256
`7cfb3662e1c3ed379d01b68c74e71ac217c7fadbd1dd6159be2fb00ce39ddda4` (`sha256sum`) · `TsFieldsMatchOpenApiSchemaTest`
et `CrossStack/OpenApiSnapshotMatchesTheLiveContractTest` verts sur ce snapshot. Reste du journal
non re-confronté au code cette passe.)

Changements récents (**les 8 dernières entrées seulement** — en ajouter une = supprimer la plus ancienne) :
- **P2-51 PR-5 — `POST /api/reservations/group` se ré-ancre sur le bloc (2026-08-31)** : **+0 path** —
  le corps du POST existant gagne `sharedTrainingBlockId` (résolu EN PREMIER, `SharedTrainingBlock`),
  `sharedTrainingGroupId` devient le repli legacy (transitoire jusqu'à la PR-6 frontend/PR-7
  nettoyage) ; aucun des deux n'est plus `required` isolément (au moins un doit être fourni, sinon
  400). Déclaré dans `PathContributor/UncoveredCustomPaths.php`. 191 → **191 paths**. Backend PUR,
  contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.17, ce rail n'appelle pas le moteur).
- **P2-51 PR-1 — le modèle du bloc de mutualisation (2026-08-31)** : **+2 paths** — CRUD API Platform
  de la ressource `SharedTrainingBlock` : `GET/POST /api/shared_training_blocks` (liste **scope
  club+saison**, filtrable par `schedulePlanId` — NULL = socle saison, UUID = plan de période) +
  `GET/PUT/DELETE /api/shared_training_blocks/{id}`. Corps : `teamIds` (2..10), `commonSessions`,
  `schedulePlanId`. Écriture management (`SharedTrainingBlockStateProcessor`) — voir
  `backend-inventory.md` pour les gardes. 189 → **191 paths**. Backend PUR, **modèle seul** (PR-1
  d'un lot à 4 PR — PR-2 émettra le bloc au payload moteur) : contrat backend⇄engine **inchangé**
  (`CONTRACT_VERSION` 2.16, aucun appel moteur à ce stade).
- **BCK-22 — le budget global de l'autofill de trajet (2026-08-28)** : **+0 path** — pas de route ni
  de DTO nouveau, un **ENUM inline** existant se complète (déclaré dans
  `PathContributor/OpponentTravelPaths.php:56` depuis P4-138, 2026-08-30 ; ex-`CustomRoutesOpenApiFactory.php:841`
  avant l'éclatement par domaine) : `POST /api/venue-travel-times/autofill`
  peut désormais rendre `unresolved[].reason = "budget_exceeded"` (lot interrompu par
  `IgnRoutingClient::BATCH_BUDGET_SECONDS`, distinct de `missing_geo`/`routing_failed` — « relancez
  pour continuer », pas un échec). 189 → **189 paths**. Backend + frontend (`reasonLabel` devient une
  table exhaustive côté écran), contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.16, aucun
  appel moteur).
- **P2-54 RMM-9 PR-3 — le radar de conflits devient SPATIAL (2026-08-28)** : **+4 paths** — les routes
  custom du trajet adverse (tenant `opponent_travel`, keyé sur le code organisme) :
  `GET /api/opponents/travel` (**ouvert au Membre**, affichage : par adversaire AWAY distinct, la
  précision du lieu `VENUE`|`CITY`|`null`, le nom du lieu, le trajet aller simple voiture nullable,
  le flag serveur `approximated` = ville, la source `AUTO`|`MANUAL` ; 400 hors contexte),
  `POST /api/opponents/travel/manual` (**management** SEC-07 : épingle un gymnase choisi via
  `/api/ffbb/salles` → surcharge MANUAL + recalcul du trajet ; 422 adversaire/gymnase invalide ou sans
  rencontre extérieure), `POST /api/opponents/travel/auto` (**management** : retour à l'AUTO ; 422 sans
  surcharge à rétablir) et `POST /api/opponents/travel/resolve` (**management** : recalcule TOUS les
  trajets AUTO — le MANUAL jamais écrasé ; cap dur 60 avant réseau IGN ; 429 rate-limit dédié
  `opponent_travel_resolve`). **Champ ADDITIF** sur `POST /api/opponents/resolve` : `stamped` (fixtures
  AWAY dont le code organisme a été posé — la clé de jointure). 185 → **189 paths**. Backend + frontend,
  contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.16, aucun appel moteur — itinéraire IGN
  `data.geopf.fr`, hôte déjà en place).
- **P2-54 RMM-9 PR-2 — l'annuaire adverse global (2026-08-27)** : **+1 path** — route custom
  `POST /api/opponents/resolve` (rattrapage **management** SEC-07 : localise les adversaires AWAY du
  club+saison dans la table PARTAGÉE `opponent_directory`, keyée sur le code organisme fédéral —
  salle exacte du hit FFBB, appariement franc par nom de salle, ou repli ville géocodé BAN, best-effort ;
  200 : `{resolved, unresolved[], skipped}` ; 422 au-delà de 60 adversaires distincts ; 429 rate-limit
  par utilisateur). Les hooks post-import xlsx et post-apply FFBB remplissent l'annuaire tout seuls
  (aucune route). Contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.16, aucun appel moteur —
  index Meilisearch `ffbbserver_salles`/`ffbbserver_organismes` + géocodage BAN).
- **P2-54 RMM-9 PR-1 — la durée de match devient un réglage par catégorie (2026-08-27)** : **+0 path**
  (ressource API Platform `SportCategory` existante). **Champs ADDITIFS en LECTURE** sur le schéma
  `SportCategory` : `matchMinutes`/`warmupMinutes` (l'override propre de la catégorie, `null` = héritée)
  et `defaultMatchMinutes`/`defaultWarmupMinutes` (le défaut de FAMILLE résolu SERVEUR par
  `MatchDurationResolver` — le front l'affiche, ne le recalcule pas). **Champs ADDITIFS en ÉCRITURE**
  sur `SportCategory.SportCategoryInput` : `matchMinutes` (borné 30–240, `Assert\Range`) et
  `warmupMinutes` (0–120) ; `null` = revient au défaut de famille. La douche/battement sortent de
  l'empreinte du radar (`MatchFootprint`, changement de comportement assumé). 184 → **184 paths**.
  Backend + frontend léger, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.16, aucun appel
  moteur — le radar recalcule côté serveur, le solveur de placement garde ses 105 min figés).
- **P2-53 RMM-8 PR-4 — le levier Obligatoire de la règle de trajet (2026-08-26)** : **+1 path** —
  ressource API Platform singleton `VenueTravelRuleSetting` : `GET /api/venue_travel_rule_settings/{ruleKey}`
  (200 : `{ruleKey, intensity, isDefault}` — résout l'intensité stockée OU le défaut `PREFERRED`) +
  `PUT` (upsert `PREFERRED`|`MANDATORY` ; **management** SEC-07 ; 409 saison archivée ; 422 sur un
  vocabulaire bien-être HARD/OFF). Identifiant FIXE `travelTime` (le nom de la règle gouvernée), scope
  club+saison. Store DÉDIÉ (vocabulaire des passerelles), PAS une 6ᵉ clé `implicit_rule_setting`.
  183 → **184 paths**. Backend + front léger ; contrat backend⇄engine **inchangé** (`CONTRACT_VERSION`
  2.16, le moteur consomme déjà MANDATORY depuis la PR-2).
- **P2-53 RMM-8 PR-1 — la géo + le modèle de la matrice de trajet (2026-08-26)** : **+4 paths** —
  `GET /api/geocode` (200 : `{candidates[]}` — candidats {label, latitude, longitude, score} de la
  Base Adresse Nationale pour poser la lat/long d'un gymnase ; **management** SEC-07 ; 422 requête
  vide/trop longue ; 502 service indisponible), `POST /api/venue-travel-times/autofill`
  (200 : `{filled, unresolved[], skippedManual}` — remplit AUTO les minutes voiture/à pied de chaque
  paire de gymnases géolocalisés via l'itinéraire IGN, **sans JAMAIS écraser une valeur MANUAL** ;
  **management** ; 409 saison archivée ; 422 au-delà du cap de paires ; 429 rate-limit) et le CRUD
  API Platform `VenueTravelTime` : `GET/POST /api/venue_travel_times` (liste **ouverte au Membre**,
  création management) + `GET/PUT/DELETE /api/venue_travel_times/{id}`. Un barème de trajet par couple
  de gymnases (`venueAId < venueBId`, `drivingMinutes`/`walkingMinutes` nullables + `drivingSource`/
  `walkingSource` `AUTO`|`MANUAL`), scopé club+saison. **Champs ADDITIFS** en LECTURE : `Venue.address`
  et `Coach.isVehicled` (défaut false). 179 → **183 paths**. Backend PUR : le bloc payload et le
  moteur sont la PR-2, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.15, aucun appel moteur).
- **P2-52 RMM-10 — un match déclaré ne perd plus sa salle en silence (2026-08-26)** : **+1 path** —
  `GET /api/schedules/{id}/validate-impact` (200 : `{orphanedFixtures, declaredOrphanedFixtures}` —
  combien de matchs domicile perdront leur salle si l'on valide ce planning, car ils pointent un
  gymnase qui n'est plus affilié au club, et combien parmi eux sont déjà déclarés à la fédération ;
  **ouvert au Membre**, lecture seule ; 403 club étranger, 404 inconnu). Le MÊME prédicat sert la
  VALIDATION, qui dépointe alors ces matchs (« à placer » + raison persistante `venue_lost`, heure
  conservée) — parité par construction. **Champ ADDITIF** en LECTURE sur le schéma `Fixture` :
  `unplacedReason` (`venue_lost` quand le gymnase n'est plus affilié, sinon `null` ; distinct de la
  raison volatile d'auto-placement, non exposée). 178 → **179 paths**. Backend + frontend, contrat
  backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.15, `unplacedReason` ne voyage jamais au moteur).
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans le `CustomPathContributor` de son domaine
(`backend/src/OpenApi/PathContributor/`), composé par `CustomRoutesOpenApiFactory` — depuis
P4-138 (2026-08-30), **ajouter une entrée directement à la factory ne fait plus rien** : elle
ne fait que composer les contributeurs dans un ordre significatif (`backend/docs/backend-inventory.md`
§OpenAPI). Le journal ci-dessus est BORNÉ à 8 entrées (audit DOC-34, 2026-08-27) : chaque ajout
retire la plus ancienne — l'historique vit dans git, jamais ici.
