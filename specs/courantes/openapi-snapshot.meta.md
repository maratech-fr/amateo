Last verified @ 2026-08-27 (P2-54 RMM-9 PR-1 — champs de durée de match sur `SportCategory` : **184 paths** INCHANGÉ (`grep -c '"/api/'`, ressource API Platform existante, aucune route neuve) · SHA-256 `eacccebabc2894654d7dc5a6a55e698455e82a8dda8d1729d0a2c951daa44b10` (`sha256sum` conforme, il BOUGE car les schémas `SportCategory` + `SportCategory.SportCategoryInput` gagnent des propriétés). Dernière évolution d'API : P2-54 RMM-9 PR-1, 2026-08-27 — voir la première entrée ci-dessous. Journal borné à 8 entrées depuis l'audit DOC-34 ; l'historique complet vit dans git, les livraisons dans `etat-des-lieux.md`.)

Changements récents (**les 8 dernières entrées seulement** — en ajouter une = supprimer la plus ancienne) :
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
- **RMM-6 PR-1 — échéances ligue/comité (2026-08-25)** : **+2 paths** —
  `POST /api/competitions/entry-deadlines` (200 : `{updated[], deadline|null}` — pose ou efface
  UNE échéance sur un lot de compétitions ; **management** SEC-07 ; 409 saison archivée ; 422 aucune
  compétition / date malformée / compétition inconnue-étrangère, rien écrit) et
  `GET /api/matches/deadline-outlook` (200 : `{windows[], guardianDelta?}` — chaque échéance
  effective encore due (valeur club, sinon défaut communautaire) avec ses compétitions, le nombre de
  domiciles restant à saisir et si la fenêtre J-7 est ouverte ; le bloc gardien n'est joint que
  fenêtre ouverte ET référence de visite existante, SANS stamper ; **ouvert au Membre** ; 400 sans
  club). **Champs ADDITIFS** en LECTURE sur le schéma `Competition` : `entryDeadline` (valeur club),
  `effectiveEntryDeadline` (club ?? défaut communautaire) et `deadlineSource` (`club`|`community`|`null`)
  — la règle « club gagne » servie par le backend. Défaut communautaire = table PARTAGÉE hors-tenant
  `shared_competition_deadline` (aucune donnée club-identifiante). 176 → **178 paths**. Backend PUR,
  contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.14, aucun appel moteur).
- **RMM-5 PR-1 — le modèle de la rotation A/B (2026-08-25)** : **+2 paths** — le CRUD API Platform
  `MatchSlotRotation` : `GET/POST /api/match_slot_rotations` (liste **ouverte au Membre**, création
  management) et `GET/PUT/DELETE /api/match_slot_rotations/{id}`. Un créneau de match partagé
  (gymnase + jour + heure, `venueId` NOT NULL) porté par N équipes ordonnées (A/B/C, position
  FICTIVE) qui l'occupent en alternance — schémas `MatchSlotRotation` + `MatchSlotRotation.MatchSlotRotationInput`
  (`venueId`, `dayOfWeek`, `kickoffTime`, `teamIds[]` ordonné). Écriture par REMPLACEMENT des membres,
  scopé club+saison, hors plans de période (patron `TeamMatchHabit`/`VenueMatchWindow`). 174 →
  **176 paths**. Backend PUR : rien ne consomme encore le modèle (payload/solveur = PR-2/3),
  contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.14, aucun appel moteur).
- **RMM-4 PR-3 — le canal API FFBB (2026-08-24)** : **+2 paths** —
  `GET /api/ffbb/rencontres` (200 : `{deviations[], creatable[], fetchedAt}` — les rencontres
  publiées par la FFBB croisées avec l'app : le diff des domiciles déjà placés qui divergent, PLUS
  les rencontres absentes de l'app (`creatable`, les amicaux) proposées à la création ; 403
  non-gestionnaire ; 409 socle non pointé ; 422 club sans code FFBB ; 502 FFBB injoignable) et
  `POST /api/ffbb/rencontres/apply` (200 : `{created, updated, unresolvedDeviations[], depositedAt}`
  — applique les décisions par écart via le MÊME moteur que l'import xlsx, crée les rencontres
  choisies de façon idempotente ; RE-FETCH SERVEUR, jamais les valeurs du client ; 409 socle/saison
  archivée/doublon concurrent). Écriture **management** (SEC-07) + socle pointé + saison écrivable.
  Ingestion `FFBB_API` datée (compteurs seuls, jamais la fraîcheur xlsx, jamais une trace). 172 →
  **174 paths**. Contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.14, zéro appel moteur —
  index Meilisearch `ffbbserver_rencontres` à la demande, filtre strict serveur).
- **RMM-4 PR-1 — réconciliation FBI (2026-08-24)** : **+1 path** —
  `GET /api/fbi-ingestions/latest` (200 : `{latest: {depositedAt, source, created, updated,
  unchanged, deviationsCount} | null}` — la fraîcheur « dernier dépôt FBI », `null` sans dépôt ;
  400 sans club ou sans saison ; 401 sans JWT). Lecture **ouverte au Membre** (aucune garde
  management, patron `GET /api/league-match-windows`), tenant+saison résolus côté serveur.
  Descriptions ADDITIVES sur les deux opérations d'import : l'analyze rend désormais `deviations[]`
  (état app VS état fichier des domiciles déjà placés), l'import accepte un champ multipart
  `decisions` (verdicts par écart keep_app|take_file) et rend `unresolvedDeviations[]` + `depositedAt`.
  171 → **172 paths**. Contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.14, aucun appel moteur).
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans `CustomRoutesOpenApiFactory`. Le journal
ci-dessus est BORNÉ à 8 entrées (audit DOC-34, 2026-08-27) : chaque ajout retire la plus
ancienne — l'historique vit dans git, jamais ici.
