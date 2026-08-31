# API géo — routes externes consommées (P2-53 RMM-8)

Last verified @ 2026-08-31 (rotation `documentation-update`, hors sujet de la PR — sondage des
stamps les plus anciens du dépôt). Re-confronté au code : hosts BAN/IGN en constantes dures
(`Service/Geo/BanGeocodingClient.php` / `Service/Geo/IgnRoutingClient.php`) ✓ ·
`IgnRoutingClient::BATCH_BUDGET_SECONDS = 30.0` toujours vrai (`IgnRoutingClient.php:42`) ✓. Non
re-sondé cette passe : le détail du dispatch de budget, `OpponentTravelResolver::resolve`, les
plafonds prod (`docker/php/Dockerfile`, `docker/nginx/default.conf`), le cap dur 120 paires, le
rate-limit `venue_travel_time_autofill`.

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
directement api-adresse.data.gouv.fr (frontière §2 de `CLAUDE.md`). **Consommateur écran (PR-3,
livré)** : `VenueGeocodeField` (`frontend/src/features/wizard/steps/VenueGeocodeField.tsx`), sur la
fiche d'un gymnase de l'étape Gymnases — saisie ≥3 caractères → « Localiser » → liste de candidats
(`label`, sans le score chiffré : le premier porte « Recommandé », un score < 0.4 porte
« correspondance approximative ») → clic écrit `address`+`latitude`+`longitude` sur le gymnase
(PUT partiel). Un gymnase déjà géolocalisé (import FFBB ou géocodage antérieur) s'affiche
« Localisé » et ne réécrit rien tant que « Modifier l'adresse » n'est pas cliqué explicitement —
aucune écriture silencieuse. Détail écran : `frontend/docs/frontend-wizard.md` §Gymnases.

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
individuel exposé.

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
  `venueTravelTimes` du payload, contrat **`CONTRACT_VERSION` 2.16**. Sa présence (≥1 ligne) —
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
