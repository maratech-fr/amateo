Last verified @ 2026-08-26 (P2-53 RMM-8 PR-1 ajoute **+4 paths** — la géo + le modèle de la matrice de trajet : `GET /api/geocode` (géocodage BAN, management), `POST /api/venue-travel-times/autofill` (remplissage des trajets par l'IGN, management) et le CRUD `VenueTravelTime` (`GET/POST /api/venue_travel_times` + `GET/PUT/DELETE /api/venue_travel_times/{id}`, lecture ouverte au Membre) : le compte passe de 179 à **183 paths** ; plus **2 champs ADDITIFS en LECTURE** : `Venue.address` (l'adresse géocodée) et `Coach.isVehicled` (véhiculé → barème voiture, sinon à pied). Backend PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.15, aucun appel moteur — le bloc payload et le moteur sont la PR-2). SHA-256 du snapshot : `61865990fbe457f93c8ecc820bfc1458eaa3efe10a073e3207a6236ca21a7672`.)

Changements récents :
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
- **RMM-3 PR-1 — le gardien à l'ouverture du module matchs (2026-08-24)** : **+1 path** —
  `POST /api/matches/module-visit` (200 : `{firstVisit, newFixturesCount, newConflictFingerprints[],
  planningChanged, referenceTakenAt}` — le delta « depuis ta dernière visite », stampe la référence
  en effet de bord, première visite muette ; 400 sans club ou sans saison ; 401 sans JWT). Route
  PAR UTILISATEUR, **ouverte au Membre** (aucune garde management, patron du signalement). **Champ
  ADDITIF** sur `GET /api/fixtures/conflicts` : chaque item porte désormais `fingerprint` (l'identité
  stable d'un conflit, propriété inline — aucun schéma nommé ajouté). 170 → **171 paths**. Contrat
  backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.14, aucun appel moteur — persistance légère et
  radar stateless recalculé).
- **P2-46 PR-2 — la réservation batch d'un groupe mutualisé (2026-08-23)** : **+1 path** —
  `POST /api/reservations/group` (201 : `{ids[], count}` — N réservations HARD, une par membre,
  écrites en UN flush ; 400 JSON/champ manquant ; 403 non-gestionnaire ; 404 groupe inconnu ou
  d'un autre club ; 409 saison archivée ; 422 portée du groupe ≠ planning, gymnase fermé, créneau
  occupé (exclusivité), plafond de séances communes atteint, membre au-delà de son volume
  hebdomadaire, ou plan inconnu). Corps :
  `{sharedTrainingGroupId, venueId, dayOfWeek, startTime, durationMinutes?, schedulePlanId?}`.
  169 → **170 paths**. Écriture management (SEC-07, parité stricte avec `POST /reservations`),
  garde de saison archivée via `SeasonScopedWriteInterface`. Contrat backend⇄engine **inchangé**
  (`CONTRACT_VERSION` 2.14, zéro appel moteur — la règle d'occupation exclusive vit à l'ÉCRITURE,
  le moteur lit ensuite les N verrous comme UNE séance commune, PR-1).
- **P2-38 prévention — les fenêtres déjà planifiées, servies (2026-08-22)** : **+1 path** —
  `GET /api/planned-windows` (200 : `windows[]` {entryId, title, startDate, endDate, `label` et
  `reason` — la PHRASE prête à afficher, composée serveur par le même helper que le refus 409}, les plages qu'un autre plan de période gouverne déjà dans `[start, end]` ;
  400 sans club ; 404 entryId/seasonId inconnu ou d'un autre club ; 422 start/end absents ou
  malformés, ou ni entryId ni seasonId). 168 → **169 paths**. Route de LECTURE (aucune écriture),
  **ouverte au Membre** — pas de gate management. Même prédicat que la garde d'écriture
  `window_already_planned` par CONSTRUCTION (foyer unique `PeriodWindowUniquenessGuard::governingWindows`).
  Contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.13, zéro appel moteur).

- **Passerelles PR-1 — intensité côté entraînement (2026-08-22)** : **0 path** — les schémas
  `TeamLink` (input `TeamLinkInput` + output `TeamLink`) gagnent la propriété optionnelle
  `trainingIntensity` (enum `PREFERRED`/`MANDATORY`, défaut `PREFERRED`) : l'intensité qui, en PR-2,
  gouvernera le solveur d'ENTRAÎNEMENT seul (le rail matchs garde sa pénalité SOFT). Ajout de
  propriété pur, aucun path modifié. Contrat backend⇄engine **2.13 → 2.14** (bloc optionnel
  `teamLinks` au payload `/generate` et au schéma `/validate-assignments`, **ACCEPTÉ mais non
  consommé** par le moteur — inertie prouvée : goldens et score de smoke inchangés).
- **Garde précoce de move sur la grille (2026-08-21)** : **0 path** — `POST /api/schedule-slots/{id}/move`
  refuse désormais AVANT le moteur un triplet sans fenêtre de gymnase (cible inexistante ou jour fermé)
  en **422 `slot_unavailable`**, miroir de `place-slot`. Le snapshot ne bouge que de deux descriptions
  du 422 existant : la phrase du `description` (ajout du cas `slot_unavailable`) et celle de la propriété
  `code` (`slot_unavailable, evict_target_mismatch or target_locked`). Aucun schéma de réponse modifié,
  aucun champ de requête ajouté (move ne reçoit AUCUNE durée du client — LA RÈGLE : la durée vient de
  l'emplacement). Contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.13, zéro appel moteur).
- **P2-44 PR-5 — les écarts NOMMÉS vs le socle (2026-08-20)** : **+1 path** —
  `GET /api/schedules/{id}/socle-deviation` (200 : `socleScheduleId` + `moved[]` {teamId, from, to}
  + `unplaced[]` {teamId, dayOfWeek, startTime, venueId, reason **nullable**} ; 422 plan SEASON ou
  période qui n'est pas une FERMETURE ; 409 version non COMPLETED ou socle non pointé ; 404/403
  tenant). 167 → **168 paths**. ⚑ `reason` est **nullable par conception** — la sélection de période
  n'explique pas toujours une absence (suppression manuelle, solve qui n'a pas replacé), et le
  serveur préfère un trou avoué à une cause fabriquée : un client qui type ce champ non-nullable
  code faux. Route de LECTURE (aucune écriture), **ouverte au Membre** — pas de gate management.
  Contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.13, zéro appel moteur).
- **P2-44 PR-3 — le comblement (2026-08-20)** : **+1 path** —
  `POST /api/schedules/{id}/fill` (202 : V+1 de la version de période mise en file, les placements
  de la source épinglés HARD dans le payload seul ; 409 pas une version de période / version en
  vigueur / génération en cours ; 422 problème trop complexe ou épingle orpheline ; 429 quota club).
  168 → **169 paths**. Contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.13, déjà en
  vigueur avant cette PR — aucun schéma Pydantic touché : le fill ne fait que réutiliser
  `slotTemplates` en HARD, un mécanisme déjà porté par le contrat).
  ⚑ **Drift antérieur ramassé au passage, hors scope de cette PR** : `ImplicitRuleSetting` (schéma
  de lecture ET `.html`) portait déjà `maxConsecutiveDays` et l'enum `intensity` gagnait `OFF` côté
  code depuis le commit `b28fdc94` (P2-42/ALIGN-08, « pas N entraînements d'affilée » — 5e règle de
  bien-être, réglable HARD/PREFERRED/OFF), fusionné sur `main` le 2026-08-19 **avant** cette
  branche, sans régénération du snapshot à l'époque. Capturé ici par nécessité (une régénération
  rend l'état RÉEL, pas un diff ciblé) — aucune trace/roadmap due à cette PR pour ce champ, la PR
  #659 est déjà livrée.
- **P2-44 PR-1 — naissance par copie du socle (2026-08-19)** : **+1 path** —
  `POST /api/schedule_plans/{id}/transcribe-from-socle` (201 : V1 COMPLETED transcrite de la
  version pointée du socle, filtrée des réglages de période ; liste `toReplace` en réponse ;
  409 : plan déjà versionné / plan SEASON / socle non pointé). 167 → **168 paths**.
- **Indisponibilité de gymnase informative — PR1 backend (2026-08-18)** : `VenuePeriodOverride`
  (schéma de lecture ET `VenuePeriodOverrideInput`) — `mode` devient **nullable** (perd `default: ""`
  côté lecture, quitte `required` côté écriture : une ligne peut n'exister que pour son masque) et
  gagne **`dayOverrides`** (array|null, masque manuel jour ISO 1..7 → OPEN|CLOSED, sparse — la
  composition avec le défaut dérivé de l'indisponibilité déclarée vit dans `PlanVenueClosures`,
  jamais redérivée côté front). Décision fondateur : l'indisponibilité déclarée ne verrouille plus
  le réglage, elle le pré-remplit. Set-diff : **0 path ajouté/retiré**, property-only sur les 4
  variantes du schéma (`VenuePeriodOverride`, `.jsonld`, `.html`, `.VenuePeriodOverrideInput`).
  Contrat backend⇄engine **inchangé** (2.12) — la fermeture reste hors payload solveur.
- **Impact de suppression d'un créneau (2026-08-18)** : `GET /api/venue_training_slots/{id}/deletion-impact`
  complète les trois routes du même nom. Particularité : les enfants d'un créneau ne citent jamais son
  id — ils s'y rattachent par le triplet (gymnase, jour, heure) ET par la couche —, donc les comptes
  sont bornés à la couche du créneau. Set-diff : **1 path ajouté**, aucun schéma modifié.
- **Suppression sûre (2026-08-18)** : trois routes de lecture entrent au contrat —
  `GET /api/{venues|teams|coaches}/{id}/deletion-impact` : ce qu'une suppression VA détruire,
  compté par le serveur en parcourant **le plan de cascade que la suppression exécute**
  (`App\Deletion\CascadePlan`), libellés compris. Portent aussi le refus du périmètre engagé
  (`blocked`/`reason`), les séances touchées vivant dans une version pointée (`slotsInForce`) et
  les matchs déjà déposés à la fédération qui perdront leur salle (`declaredFixtures`, annoncés
  jamais bloquants). Set-diff : **3 paths ajoutés**, aucun schéma modifié, aucune route retirée.
  Contrat backend⇄engine **inchangé** (2.12).
- **Bien-être par période — PR1 backend (2026-08-18)** : le DTO d'entrée
  `ImplicitRuleSetting.ImplicitRuleSettingInput` gagne `schedulePlanId` (uuid nullable, absent =
  la saison) — la portée du réglage (PUT dans le corps, DELETE en query `?schedulePlanId=`, GET en
  query). ADR-0002 inv. 5 : un plan de période reçoit sa COPIE des 4 règles à sa naissance, ne
  suit plus la saison ensuite. Set-diff : **0 path ajouté/retiré**, property-only sur le DTO
  d'entrée (`required` reste `intensity` seul). Contrat backend⇄engine **inchangé** (2.12).
- **Renommage produit (2026-08-17)** : le titre du document passe de « ClubScheduler API » à
  **« Amateo API »** (`config/packages/api_platform.yaml`). Set-diff : aucun path, aucun schéma —
  un seul champ `info.title`.
- **504 `engine_timeout` sur le rail de retouche (2026-08-17)** : `POST /api/schedule-slots/{id}/move`
  et `POST /api/schedules/{id}/place-slot` documentent un **504** distinct du 502 — le moteur
  fonctionne, il a simplement dépassé le plafond transport ; rien n'est écrit et réessayer est le
  bon geste. Le corps porte `code=engine_timeout`, que le front nomme au lieu d'afficher un
  nombre. Set-diff : **aucun path ajouté**, une réponse ajoutée sur chacune des 2 routes.
- **P2-27 PR A — mutualisation (2026-08-17)** : nouvelle ressource API `SharedTrainingGroup`
  (GET collection/item, POST, PUT, DELETE, scopée club+saison, filtre `schedulePlanId`) —
  déclarer que N équipes s'entraînent ENSEMBLE (EXACTEMENT `commonSessions` séances communes).
  Le DTO d'entrée porte `teamIds` (2..10), `commonSessions` (≥ 1), `schedulePlanId` (nullable =
  socle/période). Set-diff : **5 opérations ajoutées sur 2 paths** (`/api/shared_training_groups`,
  `/api/shared_training_groups/{id}` × méthodes). Contrat backend⇄engine bumpé **2.11 → 2.12** :
  nouveau bloc d'entrée `sharedTrainings` du payload `/generate` + `/validate-assignments`,
  diagnostic `shared_training_not_honored`.
- **P2-32 PR A — compromis nommés + dryRun (2026-08-16)** : les 200 de
  `POST /api/schedule-slots/{id}/move` et `POST /api/schedules/{id}/place-slot` portent désormais
  un bloc `compromises` (forme COMPLÈTE, pas un tableau nu : `family` [liste fermée de 8],
  `effect` `broken`/`gained`, `message` prêt à afficher SANS identifiant interne, + ids d'entités
  null-safe pour le surlignage) — le DELTA de confort d'un candidat ACCEPTÉ. Les deux request
  bodies gagnent `dryRun` (bool optionnel) : un ESSAI qui traverse le même chemin jusqu'au verdict
  inclus (gardes pré-moteur comprises) mais n'écrit RIEN ; la réponse est alors un 200 portant le
  verdict (`valid` peut être `false`), ses `violations` (si refusé) et `compromises`, `dryRun=true`,
  et sur `/move` l'`evicted` qui SERAIT libéré (sans suppression). En conséquence, le `valid` du 200
  n'est plus figé à `true` (un essai refusé rend `valid=false` en 200). Set-diff : **0 path ajouté /
  retiré**, property-only sur les deux opérations (schémas inline du contrôleur custom). Contrat
  backend⇄engine bumpé **2.9 → 2.10** : nouveau champ d'entrée `reference` du payload
  `/validate-assignments` (état « avant » du candidat pour le DELTA) + sortie `compromises` du
  verdict moteur.
- **P2-30 PR A — éviction + placement sous verdict (2026-08-16)** : nouveau path
  `POST /api/schedules/{id}/place-slot` (créer une séance à la dérive — surnuméraire /
  rattrapage — SOUS le verdict moteur : 200 `{valid, slotId}` d'une ligne DÉVERROUILLÉE,
  422 équipe inconnue ou verdict refusé, 409 génération/version choisie, 404 planning,
  502 moteur muet ; pas de garde de comptage). `POST /api/schedule-slots/{id}/move` gagne
  l'entrée optionnelle `evictSlotId` (libérer un occupant de la cible, supprimé dans la même
  transaction une fois le move accepté), un bloc `evicted` dans le 200 (état de l'occupant
  AVANT suppression) et deux refus 422 nommés au pré-check d'éviction (`code=evict_target_mismatch`,
  `code=target_locked` — D3, moteur jamais appelé). Sur `place-slot`, `durationMinutes` est
  **OPTIONNEL** (quitte la liste `required`) : la durée persistée est TOUJOURS celle de la fenêtre
  de gymnase visée, résolue côté serveur (même source que le solveur) ; le champ n'est qu'une
  ASSERTION du client — s'il contredit la fenêtre → 422 `code=duration_mismatch`, et aucune fenêtre
  à ce créneau → 422 `code=slot_unavailable`, tous deux avant tout appel moteur (durcissement
  revue sécurité : une durée client non validée écrivait sinon une occupation jamais jugée).
  Set-diff : **1 path ajouté, 0 retiré** ; le reste est property-only sur `/move` (schémas inline
  du contrôleur custom, pas de DTO). Contrat backend⇄engine **2.9 INCHANGÉ** (aucun schéma Pydantic
  touché : l'éviction supprime des lignes, le placement en crée, le payload `/validate-assignments`
  est inchangé).
- **Contrat 2.9 — nettoyage des champs morts + endpoint placebo (2026-08-16)** : le path
  `POST /api/schedule-slots/{id}/manual-edit/constraint` **quitte** le contrat (contrainte
  créée sur des clés config que le solveur ne lit jamais — placebo persisté en contournant le
  validateur) ; les deux autres actions du contrôleur (`manual-edit/lock`, `move`) restent. Le
  schéma de lecture `ScheduleSlotTemplate` perd `temporaryLock`/`temporaryLockFor`/
  `temporaryMinSessionsOverride` (jamais lus par le solveur, plus aucun writer). Set-diff :
  1 path retiré, 3 propriétés retirées, aucune addition. Contrat backend⇄engine bumpé **2.8 → 2.9**
  (retrait de champs sur un schéma `extra="forbid"` = rupture de recevabilité, bump requis).
- **Rail de retouche read-only — CRUD d'écriture des créneaux retiré (2026-08-16)** : le
  déplacement d'un créneau passe sous le verdict moteur (`POST /api/schedule-slots/{id}/move`)
  et les verrous/contraintes par `manual-edit` ; le CRUD API Platform brut des
  `ScheduleSlotTemplate` (qui mutait `lockLevel`/`temporaryLock`/placement sans jamais consulter
  le solveur) n'a plus de raison d'être. Set-diff : `POST /api/schedule_slot_templates`,
  `PUT` et `DELETE /api/schedule_slot_templates/{id}` retirés (les `GET`/`GetCollection` restent) ;
  schéma `ScheduleSlotTemplate.ScheduleSlotTemplateInput` retiré. Contrat backend⇄engine 2.8
  INCHANGÉ.
  Dans la MÊME PR, `MoveSlotService::namedViolations` (`/move`) gagne 6 champs
  (`teamId`/`coachId`/`venueId`/`dayOfWeek`/`startTime`/`conflictingTeamId`, pour le surlignage
  front) ; le schéma 422 déclaré dans `CustomRoutesOpenApiFactory.php` (chemin contrôleur custom,
  pas dérivé d'un DTO) a été aligné sur `MoveSlotService` et le snapshot régénéré — les six
  champs y figurent, nullable.
- **P4-101 — le snapshot cesse de mentir sur `causes` (2026-08-15)** : la propriété était un
  `array` nu, donc décrite `items: {type: object, additionalProperties: {type: [string, null]}}` —
  **deux mensonges** : `count` est un **entier**, et les noms de champs disparaissaient. Qui aurait
  généré ses types depuis ce snapshot aurait obtenu `Record<string, string|null>` ; le front de
  P4-99 PR 3 avait dû se typer d'après le contrat engine plutôt que d'après le snapshot. Corrigé
  par une **classe** `App\Dto\DiagnosticCause` (idiome `ScheduleCapabilities`) : le snapshot rend
  maintenant `$ref: DiagnosticCause` avec `kind: string`, `constraintId: string|null`,
  `label: string|null`, **`count: integer`**, descriptions comprises. Aucun changement de charge
  utile ni de contrat 2.8 — la forme sérialisée est identique, c'est sa DESCRIPTION qui devient
  vraie.
- **P4-99 PR 2 — la cause mesurée d'une séance manquante (2026-08-15)** : `ScheduleDiagnosticResource`
  gagne `causes` (liste de `{kind, constraintId, label, count}`, défaut `[]`) et `openCandidates`
  (int|null — `null` = non mesuré, `0` = aucun créneau resté ouvert), renseignés **seulement** par
  le type `session_below_effective_min` (les 10 autres restent `[]`/`null`). Contrat backend⇄engine
  **2.8 INCHANGÉ** (les champs, émis par l'engine en PR 1, n'étaient jusqu'ici pas importés). Le
  `constraintId` de chaque cause est normalisé côté backend (suffixe `:teamId` retiré) pour le
  deep-link wizard. Property-only sur les 3 endpoints portant la ressource : 0 path ajouté/retiré.
- **P2-28 PR 2 — règles implicites « bien-être » réglables (2026-08-14)** : nouvelle ressource
  `ImplicitRuleSetting` (identifiant = `ruleKey`) avec `GET /api/implicit_rule_settings`
  (collection RÉSOLUE, toujours 4), `GET/PUT/DELETE /api/implicit_rule_settings/{ruleKey}`
  (upsert par clé, DELETE = réinitialiser). `ScheduleDiagnosticResource` gagne `ruleKey`
  (string|null) — renseigné par le diagnostic moteur `implicit_rule_not_honored`. Contrat
  backend⇄engine 2.7 (bloc `implicitRules` livré en PR 1) : property-only côté diagnostic.
- **P4-95 — le créneau fautif d'un `conflict` (2026-08-14)** : `ScheduleDiagnosticResource` gagne
  `dayOfWeek` (int|null) et `startTime` (string|null) — renseignés **seulement** par le type
  `conflict` (les 10 autres restent `null`), pour que le front ouvre le créneau exact au lieu du
  seul rapprochement équipe/gymnase/coach. Contrat backend⇄engine INCHANGÉ (le schéma 2.6 portait
  déjà ces champs côté engine, seul l'import backend les perdait jusqu'ici). Property-only sur les
  3 endpoints qui exposent la ressource (`/api/schedule_diagnostics{,/{id}}`, embarqué dans les
  réponses de génération) : 0 path ajouté/retiré.
- ⚑ **Drift corrigé au passage, hors scope de cette PR** : `Venue.VenueInput` gagne
  `confirmSplitCascade` (bool, défaut `false`) — livré par #564 (« rendre un gymnase indivisible »,
  2026-08-14, *avant* la présente PR) sans régénération du snapshot à l'époque. Capturé ici en
  même temps par nécessité (une régénération rend l'état RÉEL du schéma, pas un diff ciblé) —
  aucune ligne de trace/roadmap n'est due à cette PR pour ce champ, la PR #564 est déjà livrée.
- **P2-22 PR 1 — visibilité des fermetures (2026-08-14)** : le path
  `GET /api/calendar-entries/{id}/conflicts` corrige son `summary`/`description` (l'ancien
  « Overlaps of a calendar period » était faux) — la route rend désormais un bloc `closures`
  (gymnase, titre, bornes, jours fermés) en plus des `conflicts`, servi même sans plan choisi.
  Set-diff : 0 path ajouté/retiré, seuls `summary` et la `description` du 200 diffèrent.
- **P2-17 PR 1 — libellé de groupe (2026-08-14)** : la ressource `VenueTrainingSlot` gagne
  `groupLabel` (string ≤40 nullable, read+write — posé = affichage fusionné demandé, vide =
  cartes séparées ; 422 sur capacité < 2). Property-only : aucun path ajouté/retiré.
- **P5-6 PR 2 — rail SA du signalement (2026-08-13)** : +4 paths `/api/admin/feedback` (liste
  cross-tenant paginée + bloc QoS, détail avec contexte lourd, `treat`/`untreat` — 409 double,
  CSRF). Set-diff : 4 paths ajoutés, 0 retiré.
- **P5-6 PR 1 — canal signalement, rail club (2026-08-13)** : +1 path `POST /api/feedback`
  (tout membre authentifié, limiteur dédié, 201 `{id}` ; le contexte lourd est copié côté
  serveur depuis un `scheduleId` — jamais fourni par le client). Set-diff : 1 path ajouté,
  0 retiré. Les routes SA `/api/admin/feedback` arriveront avec la PR 2.
- **P5-12 — journal de nouveautés (2026-08-13)** : +5 paths — `GET /api/release-notes` (tout
  membre, publiées seules, `{seenUpTo, items[]}` avec `publishedAt` par item — le garde de la
  modale se compare dessus), `POST /api/release-notes/seen` (self-only, 204), et le CRUD SA
  `/api/admin/release-notes` (+ `/{id}/publish`, 409 si déjà publiée). Set-diff : 5 paths
  ajoutés, 0 retiré. Entrées factory posées AVEC les routes.
- **P5-10 PR 2 — vue Capacité superadmin (2026-08-13)** : nouveau path `GET /api/admin/capacity`
  (lecture seule, agrégats de `solver_metrics` sur 90 j, firewall admin). Set-diff du regen :
  **1 path ajouté, 0 retiré** — l'entrée factory a été posée AVEC la route cette fois
  (la leçon du garde `EveryCustomRouteIsDocumentedTest` sur `register/config` a servi).
- **P5-3b — Turnstile sur le register (2026-08-13)** : nouveau path `GET /api/register/config`
  (public, rend `turnstileSiteKey` nullable — null tant que l'anti-robot est désactivé) ;
  `POST /api/register` gagne la propriété optionnelle `turnstileToken` et la réponse `403`
  (vérification anti-robot échouée, seulement quand Turnstile est actif). Set-diff du regen :
  **1 path ajouté, 0 retiré** — attrapé par `EveryCustomRouteIsDocumentedTest` en CI (le run
  local était passé sur métadonnées en cache : le piège `cache:pool:clear` + restart php-fpm
  documenté ici a encore frappé).
- **P4-87 — troisième marqueur de péremption (2026-08-13)** : le schéma de LECTURE `Schedule`
  gagne `resourcesChangedSinceGeneration` (bool, défaut false) — vrai quand une DONNÉE DU CLUB
  (gymnase, coach, créneau/grille de période, réservation, override, tag, calendrier) a changé
  depuis la génération. Aucune route ni opération changée (property-only). Le front lit ce
  marqueur pour la bannière unifiée de péremption (fusionnée avec `structureDiverged`).
- **P4-78/P4-79 — contrat 2.5 (2026-08-12)** : `Team.allowMultipleSessionsPerDay` **quitte** le
  contrat (levier mort retiré de bout en bout — le schéma engine le REFUSE désormais,
  `extra_forbidden`). ⚑ Rattrapage au passage : `Schedule.constraintsChangedSinceGeneration`
  (vivant depuis le marqueur de péremption) **entre** enfin au snapshot — il n'avait jamais été
  régénéré après sa livraison, et le garde ne surveille pas la direction « propriété manquante ».
- **P4-86 — suppression du path `manual-edit/one-time` (2026-08-12)** : le path
  `POST /api/schedule-slots/{id}/manual-edit/one-time` quitte le contrat (146 paths, un seul
  retiré, aucun autre changement). Depuis F2b le déplacement d'un créneau passe par
  `POST /api/schedule-slots/{id}/move` (verdict moteur) et plus aucun appelant applicatif ne
  touchait one-time — garder les deux chemins pour un même geste était le danger. Les deux
  autres actions du contrôleur (`manual-edit/constraint`, `manual-edit/lock`) restent.
- **F2b — déplacement de créneau sous le verdict du moteur (2026-08-12)** : nouveau path
  `POST /api/schedule-slots/{id}/move`. Le déplacement (jour/heure/gymnase) ne s'écrit QUE si
  le moteur l'accepte (200 + marqueur), sinon il est refusé avec les règles violées NOMMÉES
  (422 `{valid:false, violations:[{rule, message}]}`), et 409 si une génération tourne
  (`code=generation_in_progress`) ou si le planning est validé (lecture seule) ; 502 si le
  moteur ne répond pas. Le schéma de LECTURE `Schedule` gagne `manuallyEditedSinceGeneration`
  (bool, vrai ⇒ score périmé après un déplacement manuel ; remis à faux par une (re)génération).
- **F1 — origine d'un verrou de créneau (2026-08-12)** : `ScheduleSlotTemplate` (schéma de
  lecture) gagne `lockOrigin` (enum PHP `LockOrigin` : RESERVATION | MANUAL | UNKNOWN,
  nullable — NULL quand le créneau n'est pas verrouillé). En LECTURE seule : le champ est
  server-authoritative (posé à la source / par backfill), donc absent de
  `ScheduleSlotTemplateInput`. Aucun path ni opération ajouté.

Snapshot régénéré depuis le backend vivant le 2026-08-07 : `php bin/console api:openapi:export`.
En phase avec les ressources de `backend/src/ApiResource/` (chacune est représentée, aucun
path orphelin).
Changements récents :
- **P4-83 — purge des identifiants internes du contrat (2026-08-11)** : les jetons de suivi
  du dépôt (`Pn-x`, `SEC-n`, `ENG-n`, `ADR-nnnn`, `SAn`…) quittent TOUT texte lu par un
  consommateur de `/api/docs` — 45 `summary`/`description` (paths, réponses, propriétés de
  schéma, tags) perdent leur référence, la phrase reste (« Management role required (SEC-07) »
  → « Management role required »). La SUBSTANCE ne bouge pas, et **aucun path/schéma/opération**
  ne change (146 paths — set-diff : seuls des `description`/`summary` diffèrent). Décision
  fondateur : le contrat compte, le catalogue support compte, les descriptions CLI comptent ;
  les COMMENTAIRES de code restent. Gardé par `PublicTextIsFreeOfInternalIdentifiersTest`, qui
  walk le contrat **GÉNÉRÉ** (`OpenApiFactoryInterface`, pas le snapshot — « corrigé côté
  snapshot mais pas la source » ne le trompe pas) + le catalogue `AdminActionCatalog` + les
  `getDescription()`/`getHelp()` de toutes les commandes console, avec un contrôle positif
  embarqué qui prouve que le motif mord.
- **A3 — bouton « Offre » unique + rail SA4 à arguments BORNÉS (2026-08-11)** : le rail des
  actions support gagne des arguments RUNTIME, mais bornés par un **schéma fermé** (enum de
  valeurs seule, aucun texte libre représentable). `GET /api/admin/actions` expose désormais,
  par action, son schéma `arguments` (`key`, `label`, `required`, `choices[{value,label}]`, et
  `gate {argument, forbiddenValues}` pour un argument conditionnel) — la console rend ses pickers
  DEPUIS ce schéma, jamais d'une liste en dur. `POST /api/admin/clubs/{clubId}/actions/{key}`
  gagne un **requestBody** optionnel (objet `string→string`) et un **400 nommé** : clé inconnue,
  valeur hors enum, argument requis manquant, argument interdit présent, ou tout body sur une
  action SANS schéma. Fail-closed : rien ne s'exécute avant validation. Le catalogue passe de
  **12 à 7 entrées** — les 6 `set-plan-*` figées fusionnent en UNE « Offre » à schéma (`plan` +
  `paidSeason` conditionnel : requis pour toute offre payante, interdit sur Découverte). Aucun
  path nouveau (146). Gardé par `AdminClubActionTest` (schéma fail-closed) et
  `SetClubPlanCommandTest` (encaissement sur l'horloge démo, monotone).
- **A1 — le badge de la console dit l'offre EFFECTIVE (2026-08-11)** : `GET /api/admin/clubs`
  retire `planId` (annoncé `integer`, c'était en fait un uuid — type faux) et le remplace par
  trois champs : `plan { code, name } | null` (l'offre **STOCKÉE**, null en Découverte par
  défaut), `paidSeasonYear` (int|null) et `effectivePlan { code, name }` (l'offre **EFFECTIVE**
  calculée serveur). La règle pivot (payante/bêta dont `paidSeasonYear` < année-pivot de la
  saison courante → retombe sur Découverte) a une **maison unique**
  `PlanEntitlements::effectivePlanCode`, relayée par `AdminMonitoringService` sans re-dérivation
  SQL — gardée par `AdminMonitoringClubsPlanTest`. Aucun path touché (146). Motif : la console
  affichait un binaire Découverte/Payant sur l'offre stockée et **mentait** (une bêta non réglée
  s'affichait « Payant »).
- **P4-47 — solde de la dette (2026-08-11)** : **131 → 146 paths**. Les 15 routes `#[Route]`
  custom qui restaient hors contrat y entrent, en trois familles : la **console superadmin**
  (catalogue d'actions support + exécution par club, demandes de création de club et leur
  décision, adhésions en attente et leur activation, board de fraîcheur, et les trois journaux
  audit / échecs Messenger / erreurs système), les **pages publiques à token** (approbation de
  club, doléances coach — GET et POST chacune) et le **proxy FFBB** (logos ligue/comité,
  salles par code postal, salles proches). ⚑ Trois traits du contrat sont désormais ÉCRITS,
  parce qu'un client qui les ignore écrit du code faux : les 404 de la console sont
  **volontairement indistincts** (action inconnue, uuid malformé et club absent rendent la
  même réponse) ; sur les pages à token, **le token EST l'identité** (pas de JWT) et ce sont
  les CODES qui le portent — 404 byte-identique, rate-limit par IP avant toute résolution,
  410 sur expiration. ⚠ Ne le lisez pas dans l'absence de `security` : ce document déclare
  `security: []` au global et aucune opération ne porte de scheme, authentifiée ou non —
  l'accès réel se lit dans `backend/config/packages/security.yaml` ;
  la file d'échecs Messenger ne rend **jamais le body** d'un message (PII), seulement sa
  classe, son horodatage et son erreur. **`KNOWN_UNDOCUMENTED` est vide** : le cliquet de
  `EveryCustomRouteIsDocumentedTest` est devenu un mur, une route custom ajoutée sans son
  entrée factory ne peut plus passer.
- **P2-8 PR A (2026-08-10)** : `Schedule` gagne un objet `capabilities` (schéma
  `ScheduleCapabilities` : `canDelete`/`canValidate`/`canRegenerateFrom` + les compteurs
  `versionsDeletedOnValidate`/`overlaysDroppedOnValidate`). Additif, aucun path touché — le
  bloc est calculé serveur par le MÊME code que les gardes d'écriture
  (`ScheduleCapabilityResolver`) et sérialisé en lecture ; `null` sur le chemin `fromEntity`
  nu (réponse POST/PUT), comme `planType`/`isChosen`. Gardé par `ScheduleCapabilityParityTest`.
- **P4-51 volet écran (2026-08-09)** : `Coach.maxDaysOverrideConfirmed` **disparaît** du schéma
  (décision fondateur : un drapeau qui traversait tout le pipeline et n'était lu par personne).
  Paths inchangés (125) ; le schéma `Coach*` perd le champ. `CoachInput.maxDaysOverride` gagne
  ses bornes `Range(0,6)` — **0 = retirer** (le PUT partiel rend null inutilisable pour ça).
- **P4-47 (2026-08-09)** : **107 → 125 paths**. Dix-huit routes `#[Route]` custom étaient
  **invisibles** du contrat — elles portaient pourtant des gestes centraux : valider un
  planning, régénérer, réordonner les équipes, approuver une adhésion, réinitialiser la
  saison, le logo du club, les deux pages de mot de passe. ⚑ Le test de dérive de FRT-19 ne
  pouvait PAS les voir : une route absente de la factory manque des **deux** côtés, contrat
  et snapshot d'accord entre eux et faux tous les deux. Il fallait confronter le contrat au
  **routeur** — c'est `EveryCustomRouteIsDocumentedTest`, un **cliquet** : la dette restante
  (15 routes, dont 10 sur la console superadmin) est déclarée et ne peut que décroître, et
  une route documentée doit sortir de la liste sous peine de faire rougir le second sens.
  Les codes de réponse sont relevés DANS les contrôleurs, jamais devinés : une entrée qui
  annonce un 200 là où le serveur rend 409 est pire que pas d'entrée — elle est crue.
- **SEC-13 PR C (2026-08-08)** : la famille `FACILITY_CAPACITY` disparaît de l'enum exposée
  (`ConstraintInput.family` : TIME · DAY · FACILITY · COACH_AVAILABILITY). Aucun path touché
  (107) — c'est une valeur d'énumération qui sort, pas une route. Motif : aucun chemin UI ne
  la créait, zéro ligne en base ; la capacité se règle par CRÉNEAU. Détail :
  [`constraints.md`](../../backend/docs/constraints.md).
- **SEC-16 audit (2026-08-07)** : le JWT applicatif passe en **cookie httpOnly** —
  `POST /api/login` rend désormais **204 sans corps** (le `200 {token}` était écrit en dur par
  le décorateur OpenAPI de lexik ; `CustomRoutesOpenApiFactory` le RÉÉCRIT, d'où sa priorité
  de décoration négative — sans elle la correction était silencieusement écrasée),
  `POST /api/register/verify` perd `token` de sa réponse, et +`POST /api/logout`
  (106 → 107 paths). Contrat complet : [`jwt-cookie.md`](../../docs/security/jwt-cookie.md).
- **FRT-04 (2026-08-07)** : +`GET /api/mercure/auth` (route contrôleur
  `MercureAuthController`, **déclarée dans `CustomRoutesOpenApiFactory`** — 105 → 106 paths) —
  jeton de souscription Mercure en cookie httpOnly + `topicTemplate` dans le corps. Contrat
  et périmètre de sécurité : `docs/security/mercure.md` §Frontend consumption.
- **P1-4 PR F2 (2026-08-03)** : regen vérifiée, **JSON inchangé** (105 paths) — l'analyze/import
  FBI sont des opérations multipart dont l'export ne détaille pas le corps de réponse ; les
  nouveaux champs (`suggestedTeamId`, `pouleError`, `pouleUnknownOpponents`, `completeness`,
  warning `POULE_MISMATCH`, finding `COMPETITION_INCOMPLETE` du diagnostic) sont documentés dans
  [`module-matchs.md`](module-matchs.md) §Appariement FFBB (même gap connu que P4-47).
- **P1-4 PR F1 (2026-08-03)** : appariement FFBB — +`GET /api/ffbb/engagements` et
  +`POST /api/ffbb/engagements/confirm` (routes contrôleur, **déclarées dans
  `CustomRoutesOpenApiFactory`** — 103 → 105 paths) ; `Competition` expose les réfs FFBB en lecture
  (`ffbbCompetitionId`/`ffbbPouleId`/`ffbbPouleName`/`ffbbCompetitionName`/`expectedMatchdays` —
  écrites par le seul confirm, jamais par le CRUD). Détail :
  [`module-matchs.md`](module-matchs.md) §Appariement FFBB.
- **P1-4 PR E2 (2026-08-03)** : regen vérifiée, **JSON inchangé** (103 paths) — les deux routes
  qui évoluent sont des contrôleurs custom dont l'export ne porte pas le schéma de réponse :
  `GET /api/fixtures/conflicts` gagne `severity`/`coachRole` + 4 types de findings,
  `GET /api/league-match-windows` gagne `resolvedTeamWindows`. Contrat de réponse documenté dans
  [`module-matchs.md`](module-matchs.md) §Diagnostic gradué (même gap connu que P4-47).
- **P1-4 PR E1 (2026-08-03)** : boucle manuelle — `Fixture.FixtureInput` gagne `placementSource`
  (écriture : `SOLVER` = « rendre au solveur », accepté SEULEMENT sur un PUT à placement inchangé
  et statut PLACED, 422 sinon ; refusé au POST ; `MANUAL` = écho no-op). Aucun path nouveau — la
  boucle réutilise le CRUD `Fixture` existant. Détail : [`module-matchs.md`](module-matchs.md)
  §Boucle manuelle.
- **P1-4 PR D (2026-08-03)** : solveur de placement — +`POST /api/fixtures/place` (route
  contrôleur `PlaceMatchesController`, **déclarée dans `CustomRoutesOpenApiFactory`** — le
  déclencheur « route custom ⇒ entrée factory + regen » est appliqué) ; `Fixture` expose
  `placementSource` (lecture — `MANUAL`/`SOLVER`/null). ⚠ La première regen l'avait perdu :
  **cache Symfony périmé dans le conteneur** (gotcha 17 backend/AGENTS.md) — `cache:clear`
  puis re-export. Détail : [`module-matchs.md`](module-matchs.md) §Solveur de placement.
- **P1-4 PR C (2026-08-03)** : couche préférences matchs — +`/api/team_match_habits` et
  +`/api/team_links` (CRUD API Platform, 5-fichiers) ; l'enum du radar gagne `TEAM_LINK_OVERLAP`
  et les vues fixture du radar portent `estimatedKickoff` (heure empruntée à l'habitude).
  Détail : [`module-matchs.md`](module-matchs.md) §Habitudes + passerelles.
- **P1-4 PR B (2026-08-03)** : couche capacité matchs — +`/api/venue_match_windows` et
  +`/api/venue_unavailabilities` (CRUD API Platform, 5-fichiers), +`/api/venue-unavailability-impact`
  (route contrôleur, **déclarée dans `CustomRoutesOpenApiFactory`** — le déclencheur « route custom ⇒
  entrée factory + regen » est appliqué) ; l'enum du radar `/api/fixtures/conflicts` gagne
  `VENUE_UNAVAILABLE`. Détail : [`module-matchs.md`](module-matchs.md) §Couche capacité.
- **P1-4 PR A (2026-08-02)** : l'import FBI passe au **format réel, une passe** —
  `POST /api/teams/{id}/fixtures/import` **disparaît** (l'opération quitte `TeamResource`),
  remplacé par `POST /api/fixtures/import/analyze` (dry-run multipart `file`) et
  `POST /api/fixtures/import` (multipart `file` + `mappings` JSON) sur `FixtureResource`.
  `Fixture` expose `fbiVenueLabel` (libellé Salle FBI, domicile ET extérieur) et
  `Competition` expose `fbiTeamLabel` (désambiguïsation deux-équipes-une-division).
  Détail : [`module-matchs.md`](module-matchs.md) §Import FBI réel.
- **P4-41 (2026-07-31)** : `Schedule.ScheduleInput` — `name` **quitte les champs requis**
  (`required` ne garde que `status`). ADR-0002 inv. 12 : le nom vit sur le PLAN, une version
  n'a pas d'identité produit ; un POST sans nom laisse le serveur nommer la version d'après
  son plan. Une chaîne **vide ou blanche** reste refusée en 422 (`NotBlank(allowNull: true,
  normalizer: 'trim')`) — absent ≠ vide — et `maxLength: 180` borne le champ, aligné sur la
  colonne. ⚠ Le JSON a dû être **régénéré une seconde fois** : la première passe précédait
  l'ajout de `Assert\Length`, donc le contrat publié annonçait un `name` non borné alors que
  le serveur le refusait déjà (revue #339 round 3 — le déclencheur « changement d'API ⇒
  régénérer » vaut pour CHAQUE modification, pas une fois par PR).
- ⚠ **Dérive antérieure ramassée au passage** : la régénération a aussi fait apparaître les
  `format: uuid` + `externalDocs` de `Reservation.ReservationInput` (`teamId`, `venueId`,
  `schedulePlanId`) et de `Schedule.ScheduleInput.schedulePlanId`. Ils viennent des
  `#[Assert\Uuid]` posés par **P4-22a le 2026-07-26**, dont la PR n'avait pas régénéré le
  snapshot. Signal : le déclencheur « changement d'API ⇒ régénérer » n'avait pas été appliqué.
- **Feature #10 doléances coachs (C1/C2/C3, 2026-07-25)** : +`/api/coach_wishes` (CRUD todo-list),
  +`/api/coach_wish_campaigns` (CRUD + actions `POST /{id}/send-links` et `POST /{id}/remind`,
  exportées car déclarées comme opérations de la ressource). ⚠ La page **publique**
  `GET|POST /api/coach-wishes/public/{token}` (controller pur, PUBLIC_ACCESS) reste **hors export**
  (non déclarée dans `CustomRoutesOpenApiFactory`) — même gap que les autres routes controller.
- **Console super-admin onglets + monitoring (2026-07-25)** : `/api/admin/health` étendu
  (append-only : `containers[]`, `externalDependencies[]`). Les endpoints journaux
  (`/api/admin/audit-log`, `/api/admin/messenger/failed`, `/api/admin/system-errors`) sont des
  controllers purs → **hors export** (gap `CustomRoutesOpenApiFactory`, tracké roadmap §9).
- **#8 — la période POSSÈDE sa grille de gymnases (2026-07-24, RUPTURE)** : nouvelle ressource
  **`VenuePeriodOverride`** (`/api/venue_period_overrides` + `/{id}`) — réglage **épars** par
  (plan de période, gymnase) : `mode` `DISABLED`/`BLANK`, **pas de ligne = hériter** (le défaut).
  Plus deux opérations d'action déclarées sur la ressource, donc exportées :
  `POST /api/venue_period_overrides/reset-grid` (« reprendre la grille du planning principal »)
  et `POST /api/venue_period_overrides/clear-grid` (« vider »), chacune atomique et destructive.
  ⚠ C'est ici que le modèle **additif** meurt : les `VenueTrainingSlot` d'une période sont une
  **copie** ancrée `schedulePlanId`, prise à la naissance du plan et **jamais unie** aux créneaux
  de saison (`ScheduleConstraintBuilder::buildForOverlay`). Un épinglage HARD devenu orphelin
  **bloque la génération** (422 nommant le gymnase et le jour, `OrphanPinGuard`).
- **P2-5 E1 — plans de période à la semaine (2026-07-18)** : aucun path touché (82).
  `CalendarEntry` gagne **`parentEntryId`** (lecture + écriture au POST seulement) — une
  semaine ENFANT d'une période mère, qui naît avec son propre plan (rail 1 entrée = 1 plan).
  Gardes serveur : type hérité, un seul niveau, anti-doublon par lundi, exclusivité
  bloc/semaines (422 au POST enfant si le plan mère a des versions ; 409 au POST
  /api/schedules sur le plan d'une mère découpée).
- **ADR-0002 lot C3 — les calques s'ancrent au PLAN (2026-07-17, RUPTURE)** : aucun path
  touché (82). `VenueTrainingSlot` et `Reservation` remplacent **`calendarEntryId` par
  **`schedulePlanId`** (lecture, écriture, filtre `?schedulePlanId=`). L'ancre reste
  **nullable** et sa nullité garde son sens : **NULL = la structure PARTAGÉE** (créneau
  saisonnier, réservation de base — inv. 6), non-NULL = propre à ce plan.
  ⚠️ **`Constraint` ne change PAS** : les contraintes **datées** restent sur la
  `CalendarEntry`. Elles décrivent le FAIT (« Barros fermé »), et le radar de conflits les
  lit par l'entrée pour déclencher le geste « ajuster » — les ancrer au plan les rendrait
  illisibles tant qu'aucun plan n'existe (décision fondateur, l'invariant 5 corrigé).
- **ADR-0002 lot C2 — les deux jumeaux s'ancrent au PLAN (2026-07-17, RUPTURE)** : aucun
  path touché (82). `TeamPeriodOverride` et `ConstraintPeriodOverride` remplacent
  **`calendarEntryId` par `schedulePlanId`** — en lecture, en écriture et en filtre de
  collection (`?schedulePlanId=`). Inv. 5 : les réglages de période s'accrochent au Plan,
  pas au déclencheur calendrier. Sans effet fonctionnel aujourd'hui (un plan par période),
  c'est le découpage hebdomadaire (types-de-planning E1) que cela débloque : 2 semaines ⇒
  2 plans ⇒ 2 jeux de réglages sur le même déclencheur.
- **ADR-0002 lot C1 — LE PLAN NAÎT DU GESTE (2026-07-17)** : **aucun path touché**
  (82 avant, 82 après). `teamSelectionInitialized` quitte **`CalendarEntry`** pour
  **`SchedulePlan`** : le garde de seed est une propriété de la RÉPONSE (le plan), pas
  du FAIT (l'événement calendrier) — inv. 5, les réglages de période s'accrochent au
  plan. Corollaire côté serveur : un plan CLOSURE/HOLIDAY naît désormais à la création
  de sa `CalendarEntry` (le geste « ajuster »), plus à la première génération, donc
  `GET /api/schedule_plans?calendarEntryId=…` répond dès qu'une période existe.
- **Rattrapage au passage** : cette régénération fait aussi entrer
  **`currentStructureHash`** sur `GET /api/me` — champ livré par la **PR #243**
  (« disable regenerate when structure is unchanged »), qui avait modifié le contrat
  sans régénérer le snapshot. Il n'appartient pas au lot C1 ; il est simplement rendu
  au contrat ici. *(Le compte annoncé plus haut était resté à 80 alors que le snapshot
  en portait déjà 82 : corrigé.)*
- **ADR-0002 — LA BASCULE (2026-07-16, RUPTURE)** : le plan SEASON et sa version pointée
  sont LE calendrier de la saison, et le legacy meurt dans le même commit.
  - `GET /api/me` : `baselineScheduleId` / `socleValidatedAt` / `planningName`
    **supprimés** (ils n'étaient pas déclarés au contrat, seulement dans le payload).
    `seasonPlan { id, name, chosenScheduleId, hasFinishedVersion }` est la seule couture.
  - **`PUT /api/schedule_plans/{id}`** (nouveau, seul changement de path) : renomme le
    plan — le nom vit sur le plan (inv. 12), donc un seul écrivain. Gate management SEC-07.
  - `Schedule.status` perd **VALIDATED** et **ARCHIVED** : « validé » se dérive du pointeur
    et de rien d'autre. Nouveau champ de lecture **`Schedule.isChosen`** — le plan de cette
    version la pointe (vrai pour le calendrier de la saison comme pour l'overlay d'une
    période, dont le pointeur n'est pas visible depuis `/api/me`).
  - `POST /api/schedules/{id}/set-baseline` **supprimé** (inv. 18) — la route n'était pas
    documentée, donc aucun path ne disparaît du snapshot.
  - Créer un planning secondaire sans socle en vigueur : **409** (était 422). Les deux
    conditions legacy fusionnent en une seule, donc un seul code.
- **Santé technique superadmin SA2 (2026-07-16)** : `GET /api/admin/health`
  sonde DB, Redis, engine, heartbeat worker et Mercure, puis expose backlog,
  échecs et retries Messenger sans propager les pannes individuelles.
- **Supervision superadmin SA2 API (2026-07-16)** : `GET /api/admin/overview`
  expose les agrégats parc/solveur et `GET /api/admin/clubs` la liste transverse
  paginée/recherchable avec saison, volumétrie et métriques sur 30 jours.
- **ADR-0002 pattern « Plan » — Lot B1 (2026-07-16, ADDITIF)** : aucun path ni schéma ne
  bouge et **aucun comportement ne change** (le lot maintient le pointeur du plan sans que
  rien ne le lise). *Périmé par la bascule ci-dessus.*
- **SA1 métriques (2026-07-16)** : les métriques de génération sont persistées côté
  backend et `Club.lastActivityAt` est un champ de lecture pour les futurs agrégats.
- **Superadmin SA0 backend (2026-07-16)** : quatre routes custom sous
  `/api/admin/auth/{password,totp,me,logout}` documentent l'authentification séparée
  mot de passe + TOTP, la session admin et le token CSRF exigé au logout.
- **ADR-0002 pattern « Plan » — Lot A (2026-07-12)** : nouvelle ressource **`SchedulePlan`**
  (`/api/schedule_plans`, lecture seule) — le conteneur nommé des versions d'une saison/période
  (`type` SEASON/CLOSURE/HOLIDAY, `name`, `startDate`/`endDate`, `calendarEntryId?`,
  `chosenScheduleId?`). **`Schedule`** expose `schedulePlanId` + `versionNumber` (lecture).
  Le catalogue de facturation **`Plan`** est renommé **`SubscriptionPlan`**
  (`/api/plans` → `/api/subscription_plans`, lecture seule, SEC-14). Additif : aucun champ
  legacy retiré.
- **contraintes désactivables par période (2026-07-12)** : nouvelle ressource
  **`ConstraintPeriodOverride`** (`/api/constraint_period_overrides`) — surcharge sparse
  par (période CLOSURE, contrainte) : `isActive` (false = contrainte permanente
  désactivée pour la période). Le build overlay filtre les permanentes désactivées ;
  le socle (base plan) et le `isActive` propre de la `Constraint` ne sont jamais touchés.
  Défaut = toutes actives (aucun seed). Wizard : panneau « Contraintes » de la période.
- **période : flag d'initialisation (2026-07-12)** : `CalendarEntry` expose
  `teamSelectionInitialized` (read-only) — vrai dès la 1re surcharge d'équipe
  (`TeamPeriodOverride`). Le wizard ne pré-remplit « Fanion seul » que si faux →
  plus de re-seed après un reset « tout actif » ou un reload (survit au F5).
  ⚠ Dépassé sur deux points : le flag a migré sur **`SchedulePlan`** (lot C1, 2026-07-17,
  entrée ci-dessus) et le défaut de seed n'est plus « Fanion seul » mais **conscient du type
  de période** (E3, 2026-07-19) — reprise = Fanion + importantes (2 premiers rangs),
  fermeture = tout le club actif. Le mécanisme de garde, lui, est inchangé.
- **structure de période éditable (2026-07-12)** : `VenueTrainingSlot` gagne
  `calendarEntryId` (créneau scopé période, additif ; listing par défaut = saisonnier
  `IS NULL`, `?calendarEntryId=` liste ceux d'une période). Nouvelle ressource
  **`TeamPeriodOverride`** (`/api/team_period_overrides`) — surcharge sparse par
  (période, équipe) : `isActive` + `sessionsPerWeek?`. Le build overlay résout
  saisonnier→période (créneaux additifs, équipe off = 0 séance, séances override).
  ⚠ **Doublement dépassé** : l'ancre est passée à `schedulePlanId` (lot C2/C3, 2026-07-17)
  **et** le modèle **additif** a été abandonné (#8, 2026-07-24) — la période **possède** sa
  grille, copiée à la naissance du plan, jamais unie au saisonnier. Il n'y a plus de
  résolution « saisonnier→période » au build.
- **planning-versions étoile = contexte chargé (2026-07-11)** : `Schedule` expose
  `isLiveContext` (read-only, ★) — la version dont la structure est le contexte
  actuellement chargé (posé sur chaque plan de saison COMPLETED, re-pointé par
  « Charger cette version »). `Season.live_context_schedule_id` (migration). «
  Charger cette version » ne génère plus : elle restaure la structure et repointe
  le ★ sur la version source (200, aucune nouvelle version) ; « Régénérer » crée
  la nouvelle version.
- **planning-versions D3 gating (2026-07-11)** : `Schedule` expose `hasStructurePhoto`
  (read-only) — vrai seulement si la version porte une photo de structure (D2)
  restaurable. Le front n'offre « Charger cette version » que dans ce cas (un plan
  pré-D2 a un payload solveur mais pas de photo → l'action 409ait).
- **RGPD PR-5 consentement (2026-07-11)** : `/api/register` exige `consent: true` (400 sinon,
  validation payload-only — enumeration-safe A3) ; preuve stockée (`termsAcceptedAt` +
  `termsVersion`). Page publique `/confidentialite` côté frontend (placeholders juridiques).
- **RGPD PR-2 portabilité (2026-07-11)** : `GET /api/me/export` (self-only — compte + adhésions,
  jamais le hash) et `GET /api/club/export` (management SEC-07, tenant du JWT — workspace complet
  en lignes brutes par table), servis en téléchargement JSON (`Content-Disposition: attachment`).
- **RGPD PR-1 effacement (2026-07-11)** : `/api/me` gagne **DELETE** (`DeleteAccountController`,
  ajouté à `CustomRoutesOpenApiFactory`) — anonymisation immédiate self-only, confirmation =
  **ré-authentification par mot de passe** (revue sécurité : un JWT volé ne suffit pas) ; si
  plus aucun membre actif, purge du workspace club programmée à +30 j (`clubPurgeScheduled`/
  `gracePeriodDays` dans la réponse), auto-annulée si un membre revient. L'identité publique
  FFBB du club survit à la purge (win-back : ré-inscription sur l'ARA = reprise directe).
- **planning-versions D1 (2026-07-10)** : `ScheduleStatus` gagne `ARCHIVED` (posé serveur
  uniquement — jamais accepté d'un payload client) ; `Schedule` expose `generatedTeamCount`
  (read-only, bandeau divergence) ; `Season` gagne `planningName` (nom du planning de saison,
  écrit via PUT season, lu aussi dans `/api/me`).
- **SEC-14 tables globales en lecture seule (2026-07-10)** : `Plan`, `PriorityTier`, `Sport`
  perdent `Post/Put/Delete` (ne gardent que `GetCollection`/`Get`) — ce sont des tables
  globales (sans `club_id`) lues par le solveur/facturation de tous les clubs ; une écriture
  via l'API tenant les falsifiait cross-club. Leurs DTO d'input + processors write supprimés.
- **Inscription vérifiée par email (A3, 2026-07-09)** : `/api/register` passe d'un `201`+JWT à un
  **`202` générique** (anti-énumération : réponse identique pour un email neuf ou déjà inscrit, aucun
  token) ; nouvelle route custom `POST /api/register/verify` (`AuthController`, ajoutée à
  `CustomRoutesOpenApiFactory`) qui consomme le token du lien email et émet le JWT.
- **Export planning (2026-07-08)** : `POST /api/schedules/{id}/export-xlsx` (opération API Platform
  custom sur `ScheduleResource`, patron `export-pdf`) — export Excel synchrone (téléchargement direct).
  `export-pdf` accepte désormais un `venueId` optionnel (périmètre tous gymnases / un gymnase).
- **Module matchs palier A PR-4 (2026-07-07)** : `POST /api/teams/{id}/fixtures/import` (opération API
  Platform custom sur `TeamResource`, patron `clubs/{id}/import-teams`) — import FBI des rencontres,
  multipart. `FixtureResource.externalRef` exposé en lecture. Voir [`module-matchs.md`](module-matchs.md).
- **Module matchs palier A PR-2 (2026-07-07)** : route custom `GET /api/fixtures/conflicts`
  (`FixtureConflictsController`, ajoutée à `CustomRoutesOpenApiFactory`) — radar de conflits coach à la volée.
  Voir [`module-matchs.md`](module-matchs.md).
- **Module matchs palier A PR-1 (2026-07-06)** : ressources `/api/competitions` + `/api/fixtures`
  (API Platform, `CompetitionResource`/`FixtureResource`) et route custom `GET /api/league-match-windows`
  (`LeagueMatchWindowsController`, ajoutée à `CustomRoutesOpenApiFactory`). Voir
  [`module-matchs.md`](module-matchs.md).
- **Transition de saison (PR #68/69/70)** : `POST /api/seasons/{id}/transition` (custom, factory).
- **Calendriers (PR #53/#62/#63, rattrapage 2026-07-06)** : `GET /api/school-holidays` et
  `GET /api/public-holidays` (contrôleurs Symfony custom) ajoutés à
  `App\OpenApi\CustomRoutesOpenApiFactory` puis au snapshot — ils manquaient aux deux.
  ⚠ Le même gap valait alors pour la plupart des autres routes `#[Route]` custom — **soldé le
  2026-08-11** (entrée P4-47 en tête de cette liste).
- **G4/G5 (ex `backend-gaps`, livrés — cf. [`etat-des-lieux.md`](etat-des-lieux.md) §Réf historiques)** : les routes Symfony custom `/api/register`, `/api/me`
  (AuthController) et `/api/schedule-slots/{id}/manual-edit/{constraint,lock,one-time}`
  (ManualEditController) sont documentées dans l'OpenAPI via
  `App\OpenApi\CustomRoutesOpenApiFactory` (décorateur de `api_platform.openapi.factory`).
  QW-5 ajoute `PATCH /api/me` (édition profil) + `POST /api/me/password`
  (changement de mot de passe connecté).
- `Team.level` (TeamLevel) exposé en lecture (`TeamResource`) et écrit (`TeamStateProcessor`).
- `/api/users` (collection) retiré — ressource User self-only (SEC-02) ; opérations Club/User `Post`/`Delete` retirées (SEC-01/02).
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans `CustomRoutesOpenApiFactory`.
