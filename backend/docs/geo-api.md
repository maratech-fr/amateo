# API géo — routes externes consommées (P2-53 RMM-8)

Last verified @ 2026-08-26 (P2-53 RMM-8 PR-3 — l'écran câblé, câblage confronté au code :
`VenueGeocodeField.tsx`/`TravelMatrixModal.tsx` appellent bien `GET /api/geocode` et les routes
`venue_travel_times`/`venue-travel-times/autofill` ✓, score BAN non affiché en chiffre
[`LOW_SCORE = 0.4`] ✓, aucune écriture avant clic explicite — falsifié par les tests RTL des deux
composants ✓, `ScheduleConstraintBuilder.php:602-604` fixe toujours l'intensité `travelTime` à
PREFERRED en dur [aucun rail backend pour la régler] ✓). Backend non re-sondé cette passe (déjà
confronté PR-1/PR-2) : hosts en constantes dures (`Service/Geo/BanGeocodingClient.php:24`,
`Service/Geo/IgnRoutingClient.php:27`) ✓ · `max_redirects: 0` + timeout serré sur les deux clients
✓ · `GeocodeController.php:33-46` (management SEC-07, 422 requête invalide, 502 transport) ✓ ·
`VenueTravelTimeAutofillController.php:42-77` (management, saison écrivable, rate-limit dédié,
cap dur, 409 concurrent) ✓ · `rate_limiter.yaml:59-62` (`venue_travel_time_autofill`, 10/h,
sliding window) ✓ · `VenueTravelTimeAutofillService::MAX_AUTOFILL_PAIRS` = 120 ✓.

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

**Consommateur backend** : `VenueTravelTimeAutofillService` uniquement (pas de proxy `GET`
individuel exposé — l'itinéraire n'est utile qu'en lot, voir §3).

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
4. Écriture : minute + `source=AUTO`. Une paire dont un mode nécessaire ne résout pas (géo
   manquante ou échec de routage) revient `unresolved` avec sa raison (`missing_geo` |
   `routing_failed`), **jamais** un échec global du lot.
5. Réponse `{filled, unresolved[], skippedManual}`.

**Route** : management-gated (SEC-07) + saison écrivable (`SeasonAccessGuard::assertWritable` —
archivée → 409) + **rate-limit dédié PAR UTILISATEUR** `venue_travel_time_autofill` (10/h, sliding
window, `config/packages/rate_limiter.yaml`) consommé **après** la résolution du contexte
club/saison (un 400 de contexte ne brûle pas un jeton — revue sécurité 2026-08-26). **409** si un
autofill concurrent (ou un POST manuel du même couple) a créé la même ligne entre le pré-read et
l'écriture (`UniqueConstraintViolationException` nommée, idiome rejouable P4-67).

## Ce que la matrice alimente désormais (PR-2 + PR-3)

- **Le solveur d'ENTRAÎNEMENT la lit** — `POST /generate` seul (jamais `/place-matches`) :
  `ScheduleConstraintBuilder` sérialise la matrice club+saison (TRIÉE) dans le bloc
  `venueTravelTimes` du payload, contrat **`CONTRACT_VERSION` 2.16**. Sa présence (≥1 ligne) —
  ELLE SEULE — active la règle implicite `travelTime` côté moteur (opt-in au premier geste, jamais
  silencieux : un club sans matrice reçoit un payload byte-identique à avant). Détail du mécanisme
  moteur (départage « moindre trajet » + battement PREFERRED/MANDATORY, barème coach
  véhiculé/passerelle à pied, défaut 20 min) : `engine/docs/constraint-vocabulary.md` §Trajet entre
  gymnases. Gardé par `CrossStack/VenueTravelTimePayloadParityTest`.
- **L'écran (PR-3, livré)** : `TravelMatrixModal` (bouton footerExtra « Trajets entre gymnases » de
  l'étape Gymnases, offert dès ≥2 gymnases) — première ouverture (aucune ligne) = consentement
  passif à l'autofill, **jamais lancé sans clic** ; matrice groupée « Depuis {gymnase} », deux
  colonnes voiture/à pied, badge AUTO/MANUEL (icône+texte), couples non résolus « À saisir »
  + raison servie ; éditer une valeur la passe MANUEL côté serveur, « Recalculer » préserve les
  MANUEL. La case **« Véhiculé »** sur la fiche coach (`CoachesStep`) choisit le barème appliqué à
  ses enchaînements. **`TravelRuleNotice`** (onglet Base de l'étape Contraintes) affiche l'état de
  la règle — visible seulement si la matrice porte ≥1 ligne (même dérivation que
  `ScheduleConstraintBuilder`) — **en lecture seule** : aucun rail backend ne stocke l'intensité
  (PR-2 la fixe en dur à PREFERRED, `ScheduleConstraintBuilder.php:602-604`), donc l'écran
  n'invite pas à un réglage qui n'existe pas encore. Reste ouvert : le levier Obligatoire (rail de
  réglage PREFERRED↔MANDATORY) — arbitrage fondateur en attente, voir
  `specs/evolution/roadmap.md` P2-53. Détail écran complet :
  `frontend/docs/frontend-wizard.md` §Gymnases/§Coachs/§Contraintes.
- Décisions fondateur détaillées (deux barèmes, `Coach.isVehicled`, défaut 20 min pour une paire
  jamais arbitrée, le trajet jamais dominant) : **`specs/evolution/roadmap.md` P2-53** — l'item
  reste OUVERT (le levier Obligatoire manque), donc ces décisions n'ont pas encore gradué vers
  `etat-des-lieux.md` (elles y migreront avec le MOVE, à la clôture complète du lot).
