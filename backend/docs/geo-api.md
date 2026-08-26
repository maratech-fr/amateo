# API géo — routes externes consommées (P2-53 RMM-8)

Last verified @ 2026-08-26 (P2-53 RMM-8 PR-1 — première passe, écrite avec la PR qui crée les deux
clients). Confronté au code : hosts en constantes dures (`Service/Geo/BanGeocodingClient.php:24`,
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
directement api-adresse.data.gouv.fr (frontière §2 de `CLAUDE.md`). Consommateur écran : pas
encore câblé côté frontend au moment de PR-1 — la route naît testée, le câblage est PR-3.

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

## Ce que la matrice ne fait PAS (encore)

- **Le solveur ne la lit pas** — PR-1 pose la géo + le modèle + l'autofill, backend pur, **contrat
  backend⇄engine inchangé** (`CONTRACT_VERSION` 2.15, aucun appel moteur). Le bloc payload + la
  contrainte moteur (stub `travel_feasibility`) sont **PR-2**.
- **Aucun écran** ne l'exerce encore — PR-3.
- Détail produit (décisions fondateur : deux barèmes, `Coach.isVehicled`, défaut 20 min pour une
  paire jamais arbitrée, le trajet jamais dominant) : `specs/evolution/roadmap.md` ligne P2-53.
