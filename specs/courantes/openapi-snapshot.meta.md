Last verified @ 2026-08-31 (P2-51 PR-7, `documentation-update` — snapshot déjà régénéré par le
`coder` dans le même commit (54d13154), stamp resté en retard d'une entrée : recalé ici. **190 paths**
(`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) ✓, **−2 paths** (la ressource
`SharedTrainingGroup` — `/api/shared_training_groups` collection + `/{id}` item — retirée avec le
modèle groupe K) · SHA-256 `04db02e384e7c8fed094eb7b198bab38c4fc85c33a8b26e86909de1978d5edab`
(`sha256sum`, confirmé sur le fichier committé, aucun diff local). Reste du journal non re-confronté
au code cette passe.)

Changements récents (**les 8 dernières entrées seulement** — en ajouter une = supprimer la plus ancienne) :
- **P2-51 PR-7 — retrait de `SharedTrainingGroup` (2026-08-31)** : **−2 paths** — le modèle groupe
  {équipes, K} est retiré entièrement (backend/contrat/moteur/écran/seeder), `SharedTrainingBlock`
  devient la SEULE mutualisation. `GET/POST /api/shared_training_groups` et
  `GET/PUT/DELETE /api/shared_training_groups/{id}` disparaissent du snapshot. 192 → **190 paths**.
  Contrat backend⇄engine bumpé **2.19** (retrait de `sharedTrainings`/`SharedTrainingGroupSchema`
  des deux endpoints qui les portaient).
- **P2-51 PR-5b — `POST /api/schedule-slots/move-group` (2026-08-31)** : **+1 path** — le rail de
  DÉPLACEMENT de bloc atomique (D11) : déplace la séance d'un bloc (tous ses créneaux membres à la
  case source) vers une case cible, sous UN verdict et en une transaction (tout-ou-nothing). Corps
  `{scheduleId, blockId, source{venueId,dayOfWeek,startTime}, target{…}}` — le serveur résout
  lui-même les créneaux membres (jamais de slotIds clients). 200 (`movedSlotIds`) / 422 refus nommé
  (`shared_block_broken` si le geste casse le bloc) ou `slot_unavailable` / 409 génération ou plan
  choisi. Déclaré dans `PathContributor/ManualEditPaths.php`. 191 → **192 paths**. Backend + contrat
  backend⇄engine **bumpé 2.17 → 2.18** : `/validate-assignments` juge désormais N déplacements sous
  UN verdict (`candidates`/`references` LISTES remplacent le singulier — le déplacement de bloc les
  émet à N, le rail simple à 1).
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
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans le `CustomPathContributor` de son domaine
(`backend/src/OpenApi/PathContributor/`), composé par `CustomRoutesOpenApiFactory` — depuis
P4-138 (2026-08-30), **ajouter une entrée directement à la factory ne fait plus rien** : elle
ne fait que composer les contributeurs dans un ordre significatif (`backend/docs/backend-inventory.md`
§OpenAPI). Le journal ci-dessus est BORNÉ à 8 entrées (audit DOC-34, 2026-08-27) : chaque ajout
retire la plus ancienne — l'historique vit dans git, jamais ici.
