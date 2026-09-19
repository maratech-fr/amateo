# API géo — routes externes consommées (P2-53 RMM-8)

Last verified @ 2026-09-19 (`documentation-update`, PR H « onglet Adversaires » — cache de trajets
club-scoped + calcul asynchrone). Re-confronté au code cette passe : `App\Entity\ClubTravelCache`
(`ClubTravelCache.php`, tenant RLS, clé `(club, profile, origin_lat, origin_lon, dest_lat,
dest_lon)`, `minutes` NON NULL) ✓ · `App\Service\Geo\TravelTimeCache` (`TravelTimeCache.php`,
`INSERT … ON CONFLICT DO NOTHING`, clé `%.5f` canonique) ✓ · migration
`Version20260920120000::seedStatements()` (seed one-shot avant RLS) ✓ · `IgnRoutingClient::pace`/
`MIN_INTERVAL_SECONDS = 1.0`/`MAX_ATTEMPTS = 3` (`IgnRoutingClient.php:55,58,223-234`) ✓ ·
`App\Message\ComputeTravelTimesMessage` + `App\MessageHandler\ComputeTravelTimesHandler`
(`WORKER_BUDGET_SECONDS = 180`, `PROGRESS_STEP = 5`) ✓ · `App\Service\TravelComputeLock` (clé
`travel_compute:club:{clubId}`, patron `MatchPlacementLock`) ✓ · `OpponentTravelController::resolve`
dispatche `ComputeTravelTimesMessage` au lieu de router en ligne (`OpponentTravelController.php:227`)
✓ · `ClubSiegeController::coordinatesChanged` (invalidation + dispatch, `ClubSiegeController.php:89,
103-112`) ✓. Reste confronté à la passe précédente (2026-09-19, PR F « retours de tests du
18-19/09 ») : `BanGeocodingClient::geocodeTop` (`BanGeocodingClient.php:70`) ✓ ·
`ClubSiegeController` SEC-15 ✓. Reste confronté à la passe d'avant (2026-09-18, PR E — recalage du
contrat 2.23) : hosts en constantes dures, `BATCH_BUDGET_SECONDS = 30.0`, `MAX_AUTOFILL_PAIRS = 120`,
rate-limit `venue_travel_time_autofill` 10/h.

> Répertoire des endpoints externes **géo** utilisés par le backend — deuxième famille de sorties
> non-FFBB après `ffbb-api.md` (même patron : liste blanche de hosts codés en dur, SSRF-safe,
> confinés). Ces deux fournisseurs sont **publics, gratuits, sans clé, et 🇫🇷** (cohérence RGPD).
> Toute route ajoutée ici doit rester dans la liste blanche de hosts des clients.

## Hosts (liste blanche — aucun autre host autorisé)

| Host | Rôle | Client |
|------|------|--------|
| `https://api-adresse.data.gouv.fr` | Base Adresse Nationale (BAN) — géocodage adresse → lat/long | `App\Service\Geo\BanGeocodingClient` |
| `https://data.geopf.fr` | Géoplateforme IGN — itinéraires (temps de trajet) | `App\Service\Geo\IgnRoutingClient` |

> ⚠️ Ces deux hosts sont **codés en dur**, jamais dérivés d'un input utilisateur. Redirects
> désactivés (`max_redirects: 0`) sur les deux clients : un endpoint compromis ne peut pas rebondir
> vers une adresse interne. Timeout serré (5 s) par appel.

## 1. Géocoder une adresse (BAN)

```
GET https://api-adresse.data.gouv.fr/search/?q={adresse}&limit={1..5}
Headers:
  Accept: application/json
```

- Gratuit, public, sans clé. Requête validée AVANT tout appel (3 à 200 caractères, sinon `[]` sans
  réseau — `BanGeocodingClient::isValidQuery`).
- Réponse GeoJSON : coordonnées en `[longitude, latitude]` (ordre inversé, mappé explicitement —
  `BanGeocodingClient::mapFeature`).
- Champs re-servis au frontend, **jamais le hit brut** : `{label, latitude, longitude, score}`.

**Proxy backend** : `GET /api/geocode?q=` (`GeocodeController`) — management-gated (SEC-07,
`ManagementAccessGuard::assertManager`), 422 si la requête est vide/malformée, 502 nommé si le
service est indisponible (best-effort : jamais un formulaire cassé). Le frontend n'appelle jamais
directement api-adresse.data.gouv.fr (frontière §2 de `CLAUDE.md`). **Primitive front partagée
(retours de tests, 2026-09-19)** : `AddressGeocodeField` (`frontend/src/shared/components/ui/
address-geocode-field.tsx`, `frontend/AGENTS.md` §Primitives) — saisie ≥3 caractères → « Localiser »
(consomme `GET /api/geocode`) → liste de candidats (`label`, sans le score chiffré : le premier
porte « Recommandé », un score < 0.4 porte « correspondance approximative ») → clic remonte le
candidat FÉDÉRAL choisi au caller via `onPick`, jamais d'écriture avant le clic. Deux consommateurs :
- **`VenueGeocodeField`** (`frontend/src/features/wizard/steps/VenueGeocodeField.tsx`, PR-3 P2-53,
  wrapper mince depuis l'extraction) — fiche d'un gymnase de l'étape Gymnases, écrit
  `address`+`latitude`+`longitude` sur le gymnase (PUT partiel, coordonnées du candidat client).
  Détail écran : `frontend/docs/frontend-wizard.md` §Gymnases.
- **`ClubSiegeSubsection`** (`frontend/src/features/club/ClubPage.tsx`) — section « Siège du club »
  de la page Club, écrit via `PATCH /api/club/siege` (`ClubSiegeController`, § ci-dessous) qui
  RE-géocode côté serveur au lieu de faire confiance aux coordonnées du candidat (patron SEC-15,
  divergent de `VenueGeocodeField`).

Les deux gardent le même comportement « jamais d'écrasement silencieux » : un point déjà géolocalisé
s'affiche « Localisé »/« Siège localisé » et ne réécrit rien tant que « Modifier l'adresse » n'est
pas cliqué explicitement.

### 1bis. Poser le siège du club (`PATCH /api/club/siege`, retours de tests 2026-09-19)

Le corps ne porte **que du texte d'adresse** (`address`/`postalCode`/`city`, concaténés) —
`ClubSiegeController` re-géocode via `BanGeocodingClient::geocodeTop` (le MEILLEUR candidat
structuré : `{label, postalCode, city, latitude, longitude}`) et écrit adresse/CP/ville/lat/lon
depuis **SON** hit fédéral, jamais depuis une latitude/longitude que le corps porterait (patron
SEC-15, comme `OpponentTravelResolver::accountManualChoice`) : le front n'envoie que le `label` du
candidat choisi dans `AddressGeocodeField`, mais une requête forgée directement sur la route ne
pourrait de toute façon pas imposer de coordonnées. Management-gated (SEC-07) ; 422 « adresse
introuvable » (aucun candidat), 502 (BAN muette). Réponse `{address, postalCode, city, geolocated}`
— `geolocated` reflète l'état réel (`latitude`/`longitude` non nuls), jamais recalculé côté front.
`GET /api/opponents/travel` (module matchs) sert un champ additif `clubGeolocated` dérivé de la même
vérité, consommé par `useClubGeolocated()` pour le bandeau « Trajets indisponibles » de l'onglet
« Adversaires » (`/matchs/adversaires`, sorti de Configuration le 2026-09-19) — voir
`specs/courantes/module-matchs.md` § Écran Adversaires.

## 2. Itinéraire (temps de trajet) — IGN Géoplateforme

```
GET https://data.geopf.fr/navigation/itineraire
  ?resource=bdtopo-osrm&profile={car|pedestrian}&optimization=fastest
  &start={lon},{lat}&end={lon},{lat}
Headers:
  Accept: application/json
```

- Gratuit, sans clé. Mesuré en vie le 2026-08-26 : ~140-230 ms par appel.
- Profils utilisés : **`car`** et **`pedestrian`** seulement. ⚠ **`bike` rend 400** — ne jamais
  l'utiliser (`IgnRoutingClient::PROFILE_CAR`/`PROFILE_PEDESTRIAN`, aucune troisième constante).
- Coordonnées **validées en plage** (lat ∈ [-90,90], lon ∈ [-180,180]) et **formatées
  serveur-side** (`sprintf('%.6F,%.6F', ...)` — jamais une chaîne utilisateur dans l'URL, jamais un
  séparateur décimal locale-dépendant).
- `duration` de la réponse (en **secondes**) → arrondie **au-dessus** à la minute
  (`IgnRoutingClient::readMinutes`) ; `distance` en **mètres** (non consommée par PR-1).
- Best-effort par appel : une coordonnée hors plage, une réponse sans `duration` numérique, ou un
  échec de transport rendent `null` — jamais une exception qui casserait le lot.

**Consommateurs backend** : `VenueTravelTimeAutofillService` (matrice ENTRAÎNEMENT gym→gym, en lot)
**et**, depuis P2-54 PR-3, `OpponentTravelResolver` (trajet MATCHS siège club ↔ lieu adverse, table
tenant `opponent_travel`) — même client `IgnRoutingClient`, même confinement SSRF. Pas de proxy `GET`
individuel exposé. Depuis C4/C6 (ci-dessous), les deux passent d'abord par le cache club-scoped et
le calcul quitte le rail synchrone.

**PACING (mesuré, C6 2026-09-19)** : l'endpoint rend `x-ratelimit-limit-second: 1` et un **429** au
bout de ~9 appels rapprochés — `IgnRoutingClient` PACE désormais chaque requête à **1/s** sur
l'horloge injectée (`ClockInterface`, `IgnRoutingClient::pace`, dernier dispatch mémorisé) : un
appel trop tôt `sleep` la différence. Un 429 est rejoué (`Retry-After` sinon 1 s) jusqu'à **3**
tentatives avant de rendre `null`, chaque échec journalisé (`logger->warning`, statut + tentative) —
l'ancien code lisait `toArray(false)` AVANT le statut et avalait le 429 en `null` muet, sans log. Le
lot (`travelMinutesBatch`) est désormais SÉRIEL (la fenêtre de 8 requêtes concurrentes a disparu :
le quota 1/s rend la concurrence contre-productive, elle ne gagnait que des 429), même budget mural
(`BATCH_BUDGET_SECONDS`) et même distinction `budgetExceededKeys` qu'avant.

### Cache de trajets club-scoped (`ClubTravelCache`, C4, 2026-09-19)

Un trajet routier est une **CONSTANTE** : deux coordonnées (arrondies à 5 décimales, ~1 m) et un
profil (voiture/à pied) ne changent jamais de durée. `App\Entity\ClubTravelCache` (table
`club_travel_cache`, **tenant, RLS FORCE** — les coordonnées croisées trahiraient le siège d'un club
précis, même raison que `OpponentTravel`) tient donc, **club-scoped mais SANS saison**, la clé
`(club, profil, origin_lat, origin_lon, dest_lat, dest_lon)` → minutes. `minutes` est NON NULL : un
échec IGN n'entre jamais au cache (il pourrait réussir plus tard).

`App\Service\Geo\TravelTimeCache` (lookup/store) est branché **cache-first** sur les 4 consommateurs
IGN : `OpponentTravelResolver`, `carMinutesFromClub`, `OpponentVenueAutoLocator`,
`VenueTravelTimeAutofillService`. `lookup()` formate la clé canonique `%.5f` des deux côtés (lecture
et écriture) ; `store()` écrit en `INSERT … ON CONFLICT (club_id, profile, origin_lat, origin_lon,
dest_lat, dest_lon) DO NOTHING` — **jamais d'écrasement**, deux sources qui convergent sur la même
clé fusionnent silencieusement. Le service est optionnel (défaut `null`) pour ne pas casser les
sites de test qui l'omettent ; le conteneur l'autowire en prod.

**Seed one-shot** (migration `Version20260920120000`, avant l'armement RLS — les migrations tournent
sous `amateo_owner`, qui contourne la RLS des tables sources) : rétro-alimente le cache depuis (1)
`opponent_travel.travel_minutes` (siège ACTUEL du club → lieu adverse effectif, override sinon
annuaire) et (2) `venue_travel_time` conduite/marche entre deux gymnases, **dans les deux sens** (le
cache est directionnel, la matrice non) — profils `car`/`pedestrian`. Les instructions SQL vivent
dans `Version20260920120000::seedStatements()`, rejouées à l'identique par `ClubTravelCacheSeedTest`
(aucune dérive possible entre la migration et ce que le test vérifie).

**Complétude RGPD** : `ErasedClubPurger::PURGED_BY_CLUB` (effacement d'un compte → purge par
`clubId`), `SeasonDataPurger::EXCLUDED_FROM_SEASON_PURGE` (club-scoped SANS saison, une purge de
saison ne le touche pas), `RgpdExportService::EXCLUDED_FROM_EXPORT` (donnée d'établissement
RECOMPUTABLE, sans PII — retirée de l'export de portabilité). Détail : `docs/security/rgpd.md` §2.

⚠ **Dette connue** : un déménagement de siège (`PATCH /api/club/siege`, ci-dessous) invalide
`opponent_travel` et relance un calcul, mais ne PURGE PAS les anciennes lignes du cache dont
l'origine était l'ancien siège — elles ne sont plus jamais lues (nouvelle origine = nouvelle clé)
mais restent en base indéfiniment. Assumé pour l'instant (croissance non bornée, jamais mesurée en
pratique) — `roadmap.md` P4-249.

### Calcul asynchrone (C6, 2026-09-19) — le calcul quitte le rail synchrone

La rafale IGN pacée à 1/s dépasserait le plafond HTTP prod (`max_execution_time`/
`fastcgi_read_timeout` = 60 s chacun) dès qu'un club a plus d'une poignée d'adversaires ou de
gymnases à router. Le calcul (adversaires ET matrice de gymnases) part donc au **worker**
(`messenger-worker`, patron `GenerateScheduleHandler`) :

- **`App\Message\ComputeTravelTimesMessage`** `{clubId, seasonId, scope}` (`TravelComputeScope`
  `OPPONENTS`|`VENUE_MATRIX`) — dispatché par `POST /api/opponents/travel/resolve`,
  `POST /api/venue-travel-times/autofill`, la passe (c) de `POST /api/opponents/refresh`, et
  `PATCH /api/club/siege` quand le siège bouge réellement.
- **`App\MessageHandler\ComputeTravelTimesHandler`** : pose le GUC tenant lui-même (aucune requête
  HTTP dans le worker → aucun listener pour le faire), acquiert `App\Service\TravelComputeLock`
  (Redis, `SETEX NX` + compare-and-delete par token, patron `MatchPlacementLock`, clé
  `travel_compute:club:{clubId}`) — verrou déjà tenu ⇒ `RecoverableMessageHandlingException` (le
  message repart en file, un seul calcul de trajets à la fois par club). Budget mural
  **~180 s** (`WORKER_BUDGET_SECONDS`), TTL du verrou = budget + 60 s de marge. Un événement
  **terminal est toujours publié**, même sur exception (best-effort : le front doit cesser
  d'attendre), l'erreur elle-même est seulement journalisée.
- **Progression** : `App\Service\TravelProgressPublisher` pousse `{scope, done, total, terminal,
  verdict?}` par paliers de **5** trajets + le terminal, sur le topic Mercure FIXE
  `club:{clubId}:travel` (`docs/security/mercure.md` § async travel-time computation).
- **`travelStatus` servi par `GET /api/opponents/travel`** (par entrée) : `done` (minutes
  présentes) · `pending` (`TravelComputeLock::isHeld($clubId)` — un calcul tourne pour ce club) ·
  `unavailable` (tenté sans résultat, ou pas de lieu à router).
- **`resolve()` ne re-route plus TOUT** — un trajet est une constante : une ligne AUTO qui porte
  déjà un trajet est sautée. Cibles = les MANQUES seuls (code sans ligne, ligne AUTO au trajet
  null, ligne ÉQUIPE AUTO sans trajet mais avec des coordonnées d'override, MANUAL null avec
  override) — club et équipe forment un seul ensemble routé en un seul lot. « Réessayer les
  manquants » côté écran (`specs/courantes/module-matchs.md` § Écran Adversaires) est donc
  littéralement ce même `POST /resolve`.

### Poser le siège invalide les trajets dérivés (`PATCH /api/club/siege`, C6)

Si l'adresse re-géocodée diverge de plus de ~1 m de l'ancienne (`ClubSiegeController::
coordinatesChanged`, comparaison à 5 décimales — un re-géocodage de la MÊME adresse ne doit rien
invalider), le contrôleur met `opponent_travel.travel_minutes` à `NULL` pour tout le club (le
gymnase épinglé d'un MANUAL reste, seul son trajet repart) puis dispatche
`ComputeTravelTimesMessage(scope: OPPONENTS)` sur la saison courante — le cache, lui, n'a rien à
purger : la nouvelle origine est une nouvelle clé, les anciennes lignes ne sont simplement plus
jamais lues (dette ci-dessus).

## 3. L'autofill de la matrice de trajet (`POST /api/venue-travel-times/autofill`)

Le geste qui remplit la matrice `venue_travel_time` (barème voiture + à pied par couple de
gymnases du club+saison, entité `App\Entity\VenueTravelTime`) sans que l'utilisateur ne
renseigne les paires à la main :

1. Le serveur relit **venues + géolocalisations EN BASE** (jamais les valeurs du client) et forme
   les paires non ordonnées de gymnases géolocalisés (`latitude`/`longitude` non nuls).
2. **Cap dur : 120 paires** (`VenueTravelTimeAutofillService::MAX_AUTOFILL_PAIRS`) — au-delà, 422
   nommé (`AutofillCapExceededException`), rien n'est appelé côté IGN. Pour 16 gymnases
   (16×15/2=120), c'est la limite ; au-delà, saisie manuelle.
3. Pour chaque paire, chaque mode (voiture/à pied) **déjà `MANUAL`** est SAUTÉ — le cœur de la
   feature : une correction gestionnaire n'est **jamais** écrasée par un re-calcul. Seuls les modes
   `AUTO` ou jamais renseignés partent en requête IGN, par lots multiplexés
   (`IgnRoutingClient::travelMinutesBatch`, fenêtres de 8 requêtes concurrentes).
4. **Budget mural GLOBAL sur tout le lot** (BCK-22, 2026-08-28) : `IgnRoutingClient::BATCH_BUDGET_SECONDS`
   = **30 s** (`IgnRoutingClient.php`) — sans lui, le cap de 120 paires × 2 profils = jusqu'à 240
   appels en fenêtres de 8 × 5 s de timeout PAR APPEL pouvait tenir la requête ~150 s. La 1ʳᵉ fenêtre
   part toujours ; au-delà de la 2ᵉ, une fois le budget consommé, les fenêtres suivantes ne sont plus
   dispatchées et leurs clés reviennent dans `budgetExceededKeys`. Valeur adossée aux plafonds prod
   réels : `max_execution_time = 60` (`docker/php/Dockerfile:105`) et `fastcgi_read_timeout 60s`
   (`docker/nginx/default.conf:46`) — 30 s = la moitié, marge pour la dernière fenêtre bloquante + le
   flush + la sérialisation.
5. Écriture : minute + `source=AUTO`. Une paire dont un mode nécessaire ne résout pas revient
   `unresolved` avec sa raison — `missing_geo` (géo manquante), `routing_failed` (IGN a répondu sans
   durée exploitable ou le transport a échoué) ou **`budget_exceeded`** (le lot s'est arrêté avant
   d'atteindre cette paire — pas un échec, un « relancez pour continuer ») —, **jamais** un échec
   global du lot.
6. Réponse `{filled, unresolved[], skippedManual}`. `OpponentTravelResolver` (trajet adverse, §
   ci-dessous) consomme le même `travelMinutesBatch`, mais **distingue les deux sens de `null`** : une
   clé jamais atteinte par le budget (`budgetExceededKeys`) laisse la ligne `opponent_travel` INTACTE
   — ni écriture, ni création — et revient seulement `unresolved` ; seule une clé RÉELLEMENT tentée,
   dont l'IGN n'a rendu aucune durée, écrase la valeur. Sans cette distinction, une relance sur un IGN
   dégradé effaçait des trajets adverses déjà bons et sortait les rencontres concernées du radar de
   conflits spatiaux (régression BCK-22, gardée par
   `OpponentTravelResolverTest::testABudgetSkippedCodeKeepsItsExistingAutoValue`).

**Route** : management-gated (SEC-07) + saison écrivable (`SeasonAccessGuard::assertWritable` —
archivée → 409) + **rate-limit dédié PAR UTILISATEUR** `venue_travel_time_autofill` (10/h, sliding
window, `config/packages/rate_limiter.yaml`) consommé **après** la résolution du contexte
club/saison (un 400 de contexte ne brûle pas un jeton — revue sécurité 2026-08-26). **409** si un
autofill concurrent (ou un POST manuel du même couple) a créé la même ligne entre le pré-read et
l'écriture (`UniqueConstraintViolationException` nommée, idiome rejouable P4-67).

## 4. Le levier d'intensité (`GET`/`PUT /api/venue_travel_rule_settings/travelTime`, PR-4)

Le réglage qui décide si la règle implicite `travelTime` (§ ci-dessous) est une préférence souple
ou une contrainte dure — vocabulaire des passerelles (PREFERRED|MANDATORY), store DÉDIÉ
`VenueTravelRuleSetting` (singleton club+saison, `App\Entity\VenueTravelRuleSetting`) plutôt qu'une
6ᵉ clé d'`ImplicitRuleSetting` : la colonne `intensity` de ce dernier est typée
`enumType: ImplicitRuleIntensity` (HARD/PREFERRED/OFF), incapable de porter MANDATORY sans altérer
les 5 règles de bien-être. Décision consignée `etat-des-lieux.md` §2.

- **Identifiant fixe** : `travelKey` **toujours** `travelTime` (le nom de la règle gouvernée) —
  toute autre valeur de chemin rend **404** côté `GET` et `PUT` (le provider et le processor
  vérifient tous les deux `VenueTravelRuleSettingResource::RULE_KEY`, revue sécurité 2026-08-26
  F-1 : aucun alias silencieux sur l'unique réglage le jour où une 2ᵉ clé existera).
- **`GET`** résout : la ligne stockée du club+saison, ou `PREFERRED` (défaut) si rien n'a jamais
  été réglé — `{ruleKey, intensity, isDefault}`. Lecture ouverte (pas de garde management).
- **`PUT`** upserte l'intensité — **management** (SEC-07, avant le 409 de saison archivée) ; seul
  `PREFERRED`|`MANDATORY` est accepté, un vocabulaire bien-être (HARD/OFF) rend **422**
  (`VenueTravelRuleSettingInput`, `Assert\Choice` dérivé de `TeamLinkIntensity::values()`).
- **Recopie N+1** (`SeasonTransitionService`) et **purge** (`SeasonDataPurger`) suivent le même
  patron que la matrice qu'il gouverne.
- Absence de ligne = défaut `PREFERRED`, reproduisant le comportement d'avant PR-4 : un club qui
  n'a jamais touché le levier garde un payload byte-identique.

## Ce que la matrice + le levier alimentent désormais (PR-2 → PR-4)

- **Le solveur d'ENTRAÎNEMENT la lit** — `POST /generate` seul (jamais `/place-matches`) :
  `ScheduleConstraintBuilder` sérialise la matrice club+saison (TRIÉE) dans le bloc
  `venueTravelTimes` du payload, contrat **`CONTRACT_VERSION`** (`engine/CONTRACT_VERSION`, bumpé
  depuis sans rapport avec ce bloc — voir le fichier pour la valeur courante). Sa présence (≥1 ligne) —
  ELLE SEULE — active la règle implicite `travelTime` côté moteur (opt-in au premier geste, jamais
  silencieux : un club sans matrice reçoit un payload byte-identique à avant) ; l'INTENSITÉ émise
  est le réglage stocké **?? PREFERRED** (`resolveTravelRuleIntensity`, § ci-dessus). Détail du
  mécanisme moteur (départage « moindre trajet » + battement PREFERRED/MANDATORY, barème coach
  véhiculé/passerelle à pied, défaut 20 min) : `engine/docs/constraint-vocabulary.md` §Trajet entre
  gymnases. Gardé par `CrossStack/VenueTravelTimePayloadParityTest`.
- **L'écran (PR-3 la matrice, PR-4 le levier — les deux livrés)** : `TravelMatrixModal` (bouton
  footerExtra « Trajets entre gymnases » de l'étape Gymnases, offert dès ≥2 gymnases) — première
  ouverture (aucune ligne) = consentement passif à l'autofill, **jamais lancé sans clic** ; matrice
  groupée « Depuis {gymnase} », deux colonnes voiture/à pied, badge AUTO/MANUEL (icône+texte),
  couples non résolus « À saisir » + raison servie ; éditer une valeur la passe MANUEL côté
  serveur, « Recalculer » préserve les MANUEL. La case **« Véhiculé »** sur la fiche coach
  (`CoachesStep`) choisit le barème appliqué à ses enchaînements. **`TravelRuleNotice`** (onglet
  Base de l'étape Contraintes) — visible seulement si la matrice porte ≥1 ligne (même dérivation
  que `ScheduleConstraintBuilder`) — offre désormais un **vrai sélecteur** Préféré/Obligatoire
  (patron exact de l'intensité des passerelles) : la copie dit le risque d'Obligatoire (« peut
  rendre le planning infaisable »), toujours visible même en Préféré, pour être lu AVANT de
  basculer ; désactivé (lecture seule) sur une saison archivée. Détail écran complet :
  `frontend/docs/frontend-wizard.md` §Gymnases/§Coachs/§Contraintes.
- Décisions fondateur détaillées (deux barèmes, `Coach.isVehicled`, défaut 20 min pour une paire
  jamais arbitrée, le trajet jamais dominant, le store dédié du levier) : le lot **P2-53 est
  ENTIÈREMENT livré (4 PR)** et a quitté la roadmap — trace datée : `etat-des-lieux.md` §3.
