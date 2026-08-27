Last verified @ 2026-08-28 (P2-54 RMM-9 PR-3 — le radar spatial : **+4 paths** → **189 paths** (`grep -c '"/api/'`, les routes custom `GET /api/opponents/travel` + `POST /api/opponents/travel/{manual,auto,resolve}` déclarées dans `CustomRoutesOpenApiFactory`, plus le champ `stamped` ajouté à `POST /api/opponents/resolve`) · SHA-256 `1ac366250705e260856965004d6e7c138c5af412eef1ecd3bd48689dbaff9bd4` (`sha256sum` conforme). Dernière évolution d'API : P2-54 RMM-9 PR-3, 2026-08-28 — voir la première entrée ci-dessous. Journal borné à 8 entrées depuis l'audit DOC-34 ; l'historique complet vit dans git, les livraisons dans `etat-des-lieux.md`.)

Changements récents (**les 8 dernières entrées seulement** — en ajouter une = supprimer la plus ancienne) :
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
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans `CustomRoutesOpenApiFactory`. Le journal
ci-dessus est BORNÉ à 8 entrées (audit DOC-34, 2026-08-27) : chaque ajout retire la plus
ancienne — l'historique vit dans git, jamais ici.
