# API FFBB — routes consommées (lot C : auto-alimentation club)

Last verified @ 2026-09-26 (`documentation-update`, passe « le présent seulement » frontend 2/3 —
édition de POINTEUR seule). La mention `OpponentLogo` pointait vers `frontend/AGENTS.md`
§Primitives, section déplacée (primitives UI partagées, maison unique) : recalée vers
[`frontend/docs/frontend-components.md`](../../frontend/docs/frontend-components.md) §3. Reste du
fichier non re-sondé cette passe, dernière vérification de fond : 2026-09-25 (rotation de
fraîcheur, hosts + `OpponentLogoController` + `FfbbSallesController.php:162`).

> Répertoire **exhaustif** des endpoints externes FFBB utilisés par le backend pour alimenter les données institutionnelles club/comité/ligue à la création d'un club. Toute route ajoutée ici doit rester dans la **liste blanche de hosts** du client (SSRF, A12). Vérifié le 2026-07-10 sur le code réel `ARA0069036` (BCCL).

## Hosts (liste blanche — aucun autre host autorisé)

| Host | Rôle |
|------|------|
| `https://api.ffbb.com` | Directus : config (token public) + service d'assets (logos) |
| `https://meilisearch-prod.ffbb.app` | Meilisearch : recherche des organismes |

> ⚠️ Ces deux hosts sont **codés en dur**. Aucune URL n'est dérivée d'un input utilisateur. Le seul paramètre variable est le **code club**, validé par format avant tout appel.

## 1. Récupérer le token public

```
GET https://api.ffbb.com/items/configuration
Headers:
  Origin: https://competitions.ffbb.com
  Referer: https://competitions.ffbb.com/
```

Réponse (extrait) :
```json
{ "data": { "key_ms": "<clé Meilisearch>", "key_dh": "<token Directus>" } }
```

- `key_ms` → Bearer pour Meilisearch (§2). **Clé publique** embarquée dans l'app FFBB (pas un secret ; ne jamais la committer en dur — la lire ici, la mettre en cache, fallback env var `FFBB_MEILISEARCH_TOKEN`).
- L'appel **échoue en 403 sans le header `Origin`** ci-dessus.

## 2. Rechercher un organisme (club / comité / ligue)

```
POST https://meilisearch-prod.ffbb.app/multi-search
Headers:
  Authorization: Bearer {key_ms}
  Content-Type: application/json
Body:
  { "queries": [ { "indexUid": "ffbbserver_organismes", "q": "ARA0069036", "limit": 3 } ] }
```

- Index : **`ffbbserver_organismes`**.
- `q` = **code club** (recherche), ou nom / code comité / code ligue pour résoudre les parents.
- Sur 401 → token périmé : re-fetch §1 puis retry une fois.

### Champs consommés du hit → mapping entités

| Champ JSON | Cible |
|------------|-------|
| `code` | `Club.ffbbClubCode` (déjà là) |
| `nom` | `Club.name` — **FFBB fait autorité** : le nom saisi au register n'est qu'un fallback, écrasé dès que la fédération répond (register ET re-import). Décision fondateur 2026-07-18 (`FfbbClubPopulator::applyClub`) |
| `adresse` | `Club.address` |
| `cartographie.codePostal` / `commune.codePostal` | `Club.postalCode` |
| `cartographie.ville` / `commune.libelle` | `Club.city` |
| `telephone` | `Club.contactPhone` |
| `mail` | `Club.contactEmail` |
| `urlSiteWeb` | `Club.website` — et, sur les hits comité/ligue du 2ᵉ `multi-search`, `FfbbCommittee.website` / `FfbbLeague.website` (2026-08-04 ; ⚠ trim — la ligue ARA rend une espace finale) |
| `logo.id` | uuid → logo réhébergé (§3) |
| `organisme_id_pere` (`id,nom,adresse,code`) | comité → `FfbbCommittee` |
| `organisme_id_pere.organisme_id_pere` (`id,nom,code`) | ligue → `FfbbLeague` |

> **La fiche club est 100 % FFBB, rien ne s'y saisit** (décision fondateur 2026-08-04) : tous les champs
> affichés sont en LECTURE SEULE, le seul geste est `POST /api/club/ffbb-import` (bouton « Actualiser
> depuis la FFBB »). `PATCH /api/club/info` **a été supprimé** — plus aucun consommateur. Les champs que
> l'index ne connaît pas (correspondant, président, salle principale — vérifié champ par champ) ont été
> **retirés de l'écran et de `/api/me`** : pas d'automatique possible + saisie manuelle non voulue = pas
> de champ (les colonnes restent en base, données intactes). Cadrage complet :
> [`api-ffbb-completion-club.md`](../../docs/archive/api-ffbb-completion-club.md).

Champs **ignorés** : `offresPratiques`, `labellisation`, `engagements_*`, `_geo`, `type_association`, `*ClubPro`, `saison`, `dateAffiliation`.

> Le hit club ne porte que l'adresse **partielle** du comité (sans CP/ville). Le comité et la ligue **complets** (CP+ville, tél, mail, logo) se résolvent par un **2ᵉ `multi-search`** filtré sur leur `code` (`0069`, `ARA`).

## 3. Logo d'un organisme

```
GET https://api.ffbb.com/assets/{uuid}?format=webp&height=220&fit=contain
```

- `{uuid}` = `logo.id` du hit.
- **Réhébergé** chez nous (pas de hotlink) : download → validation MIME/taille → stockage via le pipeline logo existant.

## 3bis. Logo d'un ADVERSAIRE (C7, 2026-09-19)

Même host, même asset, même re-hébergement paresseux que §3, mais un usage DIFFÉRENT : le logo
d'un organisme rencontré à l'extérieur, une donnée du **module matchs** plutôt que de la fiche club.

- **`OpponentDirectoryEntry.logoId`** (colonne `logo_id`, table GLOBALE fédérale partagée — voir
  `specs/courantes/module-matchs.md` § Modèle & données transverses) est posé par
  `App\Service\Basketball\OpponentLocationResolver` depuis les hits organismes qu'il tient DÉJÀ
  (`strictOrganismeMatch`/`resolveOrganismeByCode`, canal `search*`) — **zéro appel réseau de
  plus**. Upsert `COALESCE(EXCLUDED.logo_id, opponent_directory.logo_id)` : un logo déjà connu
  n'est **jamais** effacé par une résolution qui n'en porte pas. ⚠ **Le canal `directVenue`
  (rencontre API sans hit organisme) ne pose PAS de logo** — dette connue, `roadmap.md` P4-250.
  Whitelist du partage : `OpponentDirectoryShareTest`.
- **`GET /api/opponents/{code}/logo`** (`App\Controller\Basketball\OpponentLogoController`) — route
  **MEMBRE** (`IS_AUTHENTICATED_FULLY`), jamais publique : contrairement au logo club (§3, exposé
  sans authentification car institutionnel), le logo d'un adversaire de CE club est une donnée du
  module matchs. Sert les octets stockés sous `ffbb-opponent-{code}` (`LogoStorage`) ; absents →
  télécharge une fois depuis `logo_id` (`FfbbLogoFetcher`, même host `api.ffbb.com/assets/`, mêmes
  gardes que §3 : uuid validé, MIME réel vérifié, 500 KB, redirects désactivés), stocke, sert ;
  sans logo connu ou téléchargement en échec → **404**. `Cache-Control: private, max-age=86400`.
- **`GET /api/opponents/travel`** sert un booléen additif `hasLogo` par entrée (dérivé de la
  présence du `logo_id`, jamais l'uuid brut) — l'écran rend `<img>` ssi `hasLogo`, sinon des
  initiales (`shared/components/ui/opponent-logo.tsx`, [`frontend/docs/frontend-components.md`](../../frontend/docs/frontend-components.md) §3).
  Consommateurs : `AwayList` (16 px). **`ConflictLine` n'est PAS câblée** (décision de scope, C7) —
  le côté d'un conflit ne porte pas le code organisme adverse, il suivrait un décorateur backend
  dédié, hors scope.

## Ce que l'API NE fournit PAS

- **Président / correspondant nommé** (personne physique) : absent de l'index. Volontairement **hors scope** lot C (seul le contact institutionnel — mail secrétariat + tél — est exposé).
- **Le calendrier OFFICIEL de rencontres** (championnat, poule, dates officielles). Re-mesuré le
  2026-08-24 : l'index `ffbbserver_rencontres` existe, son schéma est complet (36 champs), et il
  porte désormais **1 052 documents** — mais pour le BCCL les **36 hits sont TOUS des AMICAUX,
  zéro rencontre de championnat**. La vérité du calendrier continue de passer par l'**import FBI** ;
  l'API ne remplace pas ce canal. **Depuis RMM-4 PR-3 (2026-08-24), ce que l'index PORTE bel et
  bien — les amicaux — EST exploité** en réconciliation (voir §ci-dessous) : un CONFORT qui
  propose ces rencontres à la création, jamais un remplacement de FBI.

## Réconciliation FBI, canal API (RMM-4 PR-3, 2026-08-24)

Deux routes, mêmes hosts, même confinement SSRF, gate **management (SEC-07) + socle pointé + tenant**
(écriture en plus : saison inscriptible) — `Controller/Basketball/FfbbRencontresController.php` :

- `GET /api/ffbb/rencontres` — récupère à la demande les rencontres publiées du club (aucun cache,
  aucun cron, même décision juridique que les autres canaux `search*`), les croise avec l'app et rend
  `{deviations[], creatable[], fetchedAt}` : `deviations` = même forme que l'analyse xlsx (les
  domiciles déjà placés dont date/heure/salle divergent) ; `creatable` = les rencontres publiées
  SANS fixture correspondante (mesuré : uniquement des amicaux), proposées à la création. **N'écrit
  rien** (comme `analyze()` côté xlsx) — le moteur ne fait que recalculer les écarts en lecture.
- `POST /api/ffbb/rencontres/apply` — RE-FETCHE côté serveur (jamais les valeurs du client), applique
  les décisions par écart via le MÊME moteur que l'import xlsx (`FbiFixtureImporter`, réutilisé
  verbatim) et crée les rencontres choisies (idempotent sur `Fixture.ffbbRencontreId` — index unique
  partiel `uniq_fixture_ffbb_rencontre`, une collision concurrente rend un 409 propre). **PR-3a
  (2026-09-08)** : `apply` pose désormais l'ÉTAT DE TRAITEMENT de la rencontre au même titre que
  l'import xlsx (`reviewState`/`pendingDeviations` sur `Fixture`, `processPerimeterFields`/
  `reconcileNoDivergence`) et applique **D9** — un domicile `PLACED`/`SUBMITTED` que l'API renvoie
  identique sur date + heure + salle passe `VALIDATED` + traité, exactement comme un dépôt xlsx.
  **P4-194 (2026-09-10)** : `apply` **résout ou crée la compétition** d'une rencontre dont la
  compétition fédérale n'est appariée à aucune équipe — `Competition` de type `CUP`, nommée d'après le
  libellé fédéral (clampé à la longueur de colonne), rattachée à l'équipe visée, portant
  `ffbbCompetitionId`, sans `expectedMatchdays`. Idempotence : couple (réf FFBB, équipe) + carte du run
  (deux rencontres d'une même coupe → UNE compétition), repli (équipe, nom exact) pour ré-adopter une
  compétition dont un réappariement a effacé les refs — jamais si elle porte déjà une AUTRE réf.
  **L'amical se reconnaît au LIBELLÉ** (premier token normalisé « amical » : « AMICAL PNM », repli
  « Amical » du lecteur), jamais à l'absence d'appariement : un amical porte lui aussi une réf de
  compétition (`FfbbHttpClientStub`, cas mesuré). Rattachage rétroactif : un ré-`apply` pose la
  compétition manquante sur une rencontre déjà créée — et rien d'autre (ni statut, ni date, ni état de
  traitement). Ambiguïté (deux équipes du club dans la même coupe) : l'équipe n'est plus suggérée.
- **P4-187a (2026-09-09)** : `apply` pose aussi `venueId` par ALIAS CONFIRMÉ
  (`FbiFixtureImporter::attachConfirmedVenue`, foyer partagé avec l'import xlsx) sur un domicile
  encore sans salle dont le libellé FBI/FFBB égale un alias que le gestionnaire a rattaché à un
  gymnase (`Venue.externalLabels`, `POST /api/venues/{id}/external-labels`) — la rencontre reste
  UNPLACED, seule visible de `VENUE_OVERLAP`/`VENUE_UNAVAILABLE`. **E1 (2026-09-14)** : le même POST
  accepte désormais `reassign: true` pour corriger un alias posé sur le MAUVAIS gymnase en un geste
  (retire l'alias de l'ancien porteur, re-pointe les domiciles NON PLACÉS du club au même libellé,
  épargne les domiciles déjà PLACÉS/SOUMIS/VALIDÉS) — sans effet sur `apply`/`FfbbRencontreReconciler`
  elle-même, qui continue de ne poser `venueId` que sur un domicile encore sans salle. Détail :
  [`module-matchs.md`](../../specs/courantes/module-matchs.md) §1 « Modèle & données transverses ».
  ⚠ **Pont par référence FFBB de salle — piste FERMÉE (2026-09-21, décision fondateur)** : l'objet
  `salle` d'un hit rencontres (`FfbbRencontreReader.php:112-121`, `:168-190`) ne porte que `{id,
  libelle, adresse, cartographie}`, jamais le `numero` de l'index salles (`Venue.externalRef`,
  exposé par le proxy salles — `FfbbSallesController.php:162`, qui n'expose aujourd'hui que
  `numero`, jamais l'`id`). Un pont EXACT par cet `id` (comparer le `salle.id` d'un hit rencontres
  à l'`id` de l'index `ffbbserver_salles`) a été **abandonné après trois sondes réseau réelles
  concordantes** (2026-09-14, 2026-09-15, 2026-09-21, détail `etat-des-lieux.md` §2) : le canal
  principal du fondateur (fichier Excel) ne porte aucun identifiant de salle non plus, le pont ne
  servirait donc qu'au canal API secondaire, alors que les alias de gymnase restent de toute façon
  obligatoires pour le xlsx.
  **2ᵉ sonde réseau réelle (2026-09-15, club BCCL, cadrage P2-54 PR-2)** : l'index `ffbbserver_salles`
  n'est **PAS queryable par `numero`** — ni en filtre, ni en plein texte — seule la voie `_geoRadius`
  rend des hits (`FfbbSalleResolver.php:15-23`, `FfbbApiClient::searchSallesNearby`). C'est
  pourquoi le pont retenu pour PR-2 (§ « Suggestions partagées de gymnases » de
  [`module-matchs.md`](../../specs/courantes/module-matchs.md)) n'est **PAS** par `id`, mais par
  **coordonnées-graine + égalité stricte du `numero`** parmi les hits proches
  (`FfbbSalleResolver::resolveByExternalRef`) — un mécanisme de VÉRIFICATION, pas de LOOKUP direct.
  Il ne répond pas à l'appariement d'un hit rencontres SANS coordonnées de départ : une ligne
  `FFBB_API` et une ligne `MANUAL` de la MÊME salle restent donc deux suggestions disjointes dans
  `opponent_venue_suggestion` — situation désormais définitive, le pont par `id` n'étant plus tenté.
- **P4-199 (2026-09-12)** : `apply` partage désormais aussi les règles de naissance/fenêtre du xlsx
  (`FfbbRencontreReconciler` appelle `FbiFixtureImporter::treatOnArrival`/`sourceIsAuthoritativeForWindow`,
  foyer unique) — un extérieur créé par ce canal naît `REVIEWED` d'office, un domicile PLACÉ
  déphasé dont la date app OU la date API tombe dans la fenêtre passé/semaine ISO en cours (fuseau
  club) est appliqué D'OFFICE plutôt que proposé à l'arbitrage ; les libellés `clubLabel`/
  `opponentLabel` perdent leur suffixe FFBB « (n) » à la lecture
  (`VenueLabelNormalizer::stripTeamNumberSuffix`). Détail :
  [`module-matchs.md`](../../specs/courantes/module-matchs.md) §7 « Écran Importer ».
- **Filtre strict serveur** (`FfbbApiClient::searchRencontres`) : la recherche plein texte sur le
  code club rend du bruit (un hit « AMICAL PNM » ne concernant pas le club, mesuré) — ne sont
  gardés que les hits où le code club apparaît sur `idOrganismeEquipe1.code` OU
  `idOrganismeEquipe2.code`. Le filtre saison est appliqué en aval par `FfbbRencontreReader`
  (`saison.code` du hit).
- **Ce canal ne touche jamais la fraîcheur xlsx** (`GET /api/fbi-ingestions/latest` ne lit que les
  dépôts `source=FBI_XLSX`) — son propre dépôt `FbiIngestion` est stampé `source=FFBB_API`,
  compteurs seuls. La trace des écarts, elle, n'est plus portée par `FbiIngestion` du tout depuis
  PR-3a (D7) : elle vit sur `Fixture.pendingDeviations`, commune aux deux canaux.

Détail produit complet (appariement 3 étages, front) : [`../../specs/courantes/module-matchs.md`](../../specs/courantes/module-matchs.md) §7 « Écran Importer ».

## Engagements + compétitions (P1-4 PR F, appariement)

Deux appels de plus, mêmes hosts, même confinement SSRF (`FfbbApiClient`) — **à la demande seulement**
(geste management), aucun cache global, aucun cron (décision juridique fermée) :

- `searchEngagements(clubCode)` — index `ffbbserver_engagements`. ⚠ **Sondé le 2026-08-03 : le champ
  `codeClub` n'est PAS filtrable, et `idOrganisme.code` (filtrable) est NULL dans les données** — le
  filtre Meilisearch est inutilisable. Repli : recherche plein texte du code (283 hits) puis **filtre
  STRICT serveur sur `codeClub`** (→ les 14 vrais). Jamais la pertinence.
- `searchCompetitionsByCode(code, saison)` — index `ffbbserver_competitions`. ⚠ `id` n'est pas filtrable ;
  `code` (« PRM », national — 27 hits) et `saison.code` le sont. L'appelant discrimine ensuite par `id`
  (porté par `engagement.idCompetition.id`). `poules[].engagements[].nom` = **la liste exacte des clubs
  d'une poule** (le garde-fou d'import) ; taille de poule → `expectedMatchdays = 2×(N−1)` — **sauf sur une
  COUPE** (P4-195, 2026-09-10) : le type se déduit du nom (« coupe » → `CUP`, avant « brassage ») et une
  coupe se joue par tours, donc `expectedMatchdays` reste **null** (l'alerte « calendrier incomplet » se
  tait, l'écran affiche « N journées importées » sans dénominateur). Un réappariement **répare** une
  compétition stockée `CHAMPIONSHIP` dont le nom infère une coupe.

La jointure complète vit dans `FfbbEngagementReader` (filtre saison via `FfbbSeasonCode` — « 26-27 » ↔
`SeasonResolver::seasonYear` 2026 — et réparation du double encodage UTF-8 des libellés, mesuré :
`PrÃ© rÃ©gionale`). Consommée par `FfbbEngagementsController` (`GET /api/ffbb/engagements` +
`POST /api/ffbb/engagements/confirm`, SEC-07 + saison écrivable + socle pointé).

**Pont xlsx → Engagements FFBB (P4-200 C1, 2026-09-12).** Chaque ligne de `GET /api/ffbb/engagements`
porte désormais `suggestionSource: "pairing"|"canonical"|"fbi"|null`, priorité inchangée sur les deux
premières sources (`pairing` = une `Competition` déjà appariée à cet id FFBB ; `canonical` = égalité
normalisée stricte du nom canonique) puis, troisième source, `fbi` : `App\Service\Basketball\FbiDivisionSignature`
parse le code de division FBI d'une `Competition` xlsx **non appariée** (`Competition::name`, ex. « PNM »,
« RF3 », « CRMLSM », « RFU13 Brassage » — la poule collée après un séparateur, « DFU15-2 », est ignorée,
c'est un n° de poule FBI, pas de division) en une signature `{level, division, gender, category, type}`,
et la ligne FFBB (`category`/`level`/`gender`/« Division n » du nom) en la signature équivalente
(`FfbbEngagementsController::bridgeSuggestion`). Coupes et brassages entrent dans le pont (décision
fondateur 2026-09-12, § « Décisions fermées »), un amical (type `FRIENDLY`) jamais. Suggestion
seulement si le pont désigne **une seule équipe** — plusieurs compétitions distinctes matchant la
même signature vers des équipes différentes → aucune suggestion (« DFU11 » ambigu entre deux équipes) ;
plusieurs compétitions vers la même équipe → la première par nom.
`POST /api/ffbb/engagements/confirm` accepte, par pairing, un `competitionId` optionnel (la
suggestion `fbi` acceptée) : honoré seulement si la compétition appartient à l'équipe choisie
(`FfbbEngagementsController::resolveCompetition` — filtre tenant/saison déjà porté par la lecture,
plus une garde équipe explicite), les réfs FFBB se posent alors SUR cette compétition xlsx (`name`
reste le code FBI, la clé du résolveur xlsx ; `ffbbCompetitionName` reçoit le nom canonique FFBB) au
lieu d'une compétition jumelle vide. Sans `competitionId` (ou id étranger à l'équipe) : repli sur le
comportement historique par `(teamId, nom canonique)`. Écrire sur la compétition xlsx n'engage
toujours pas l'équipe (`FfbbPairingAuthorizationTest`).

`ffbbserver_rencontres` (36 hits BCCL, tous des amicaux, zéro championnat) est un index DIFFÉRENT,
désormais exploité côté réconciliation — voir § « Réconciliation FBI, canal API » plus haut.

## Salles d'une commune (P2-20 — autocomplétion des gymnases du wizard)

- `searchSalles(postalCode)` — index **`ffbbserver_salles`**, filtre `commune.codePostal` (le seul axe :
  l'index n'est **pas** relié aux clubs — cadrage `api-ffbb-completion-club.md` §3). CP validé `^\d{5}$`
  avant interpolation dans le filtre (même règle anti-injection que les autres `search*`).
- Exposé par `GET /api/ffbb/salles?postalCode=` (SEC-07 management ; **défaut = CP du club**, surchargable
  — une salle peut être dans la commune voisine). Mapping serveur `{name, address, city, externalRef,
  latitude, longitude}` — jamais le hit brut ; lat/lng convertis en string (format `Venue`).
- Consommé par la combobox « Nom du gymnase » de l'étape Gymnases : choisir une suggestion crée le
  gymnase avec son **ancrage FFBB** (`Venue.externalRef` = numéro fédéral + GPS — colonnes préexistantes,
  zéro migration). La liste **propose, n'impose jamais** : saisie libre intacte, et tout changement
  manuel du nom efface l'ancre.
- **P2-21 lot D** — `searchSallesNearby(lat, lng, radiusMeters)` : `_geoRadius` + tri `_geoPoint`
  (bornes lat/lng et rayon validées avant interpolation), exposé par `GET /api/ffbb/salles-proches`
  (SEC-07 ; géoloc du club, posée par le populate). `radius` = palier manuel (3/5/10/20 km), absent =
  **AUTO** : 3 km élargi tant que < 5 salles — un défaut fixe montrait une liste vide à un club rural
  (Martiel : 0 salle à 3 ET 5 km, mesuré §6.9). Panneau « Gymnases à proximité » de l'étape 2 ;
  « déjà ajouté » reconnu au numéro fédéral, jamais au nom.
- **P2-54 PR-2b** — nouveau consommateur : `App\Service\Geo\OpponentVenueAutoLocator` (salles par
  CP `searchSalles` de l'annuaire adverse, sinon `searchSallesNearby` par rayon autour de ses
  coordonnées) — égalité STRICTE `normalize(libellé du fichier FBI) === normalize(salle.libelle)`
  pour poser un lien `OpponentVenueLink` TENANT vers ce gymnase FÉDÉRAL (source AUTO, jamais le
  partagé — amendement PR I 2026-09-20 : le lien remplace l'ancienne surcharge `opponent_travel`).
  **Lot K (2026-09-20)** : quand cette voie CP/rayon ne rend pas un match unique (0 candidat, ou
  0/≥2 égalités strictes), `OpponentVenueAutoLocator` retente par **NOM** (ci-dessous) avant de
  renoncer — le comptage `ambiguous`/`unmatched` de la voie commune ne bouge pas pour ce repli.

### Recherche de salle par NOM (lot K, 2026-09-20)

- `searchSallesByName(name)` (`FfbbApiClient`) — même index **`ffbbserver_salles`**, mais en
  **plein-texte** (`q`, borné 2..180 caractères, **jamais interpolé dans un `filter`** — même
  posture anti-injection que les autres `search*`). **Sondé en réel le 2026-09-20** : `libelle`
  EST plein-texte avec la clé search-only et Meilisearch classe les correspondances EXACTES en
  tête — `ASTROBALLE` → 1 hit, `SALLE TOLA VOLOGE` → 1, `GYMNASE JEAN GUIMIER` → 4, `limit: 50`
  les capte toutes. `estimatedTotalHits` est **trompeur** (jusqu'à 2859 pour une seule
  correspondance exacte) : l'appelant décide sur l'égalité STRICTE du libellé normalisé, jamais
  sur ce compte. ⚠ Ce résultat porte sur `libelle` seulement — l'index reste **non** queryable par
  `numero` (constat 2026-09-15 inchangé, § « Réconciliation FBI » ci-dessus — le pont par
  référence FFBB de salle est fermé, `etat-des-lieux.md` §2).
- Exposé par `GET /api/ffbb/salles?q=` — **alternative** à `?postalCode=` sur la même route
  (`FfbbSallesController`), seuil 3 caractères côté serveur ET front (en-dessous : liste vide,
  aucun appel réseau). Même mapping serveur que la voie CP (`{name, address, city, externalRef,
  latitude, longitude}`).
- Consommé par `LocateOpponentModal` (champ « Nom du gymnase », débounced 300 ms, prend la main
  sur le code postal dès qu'elle est active — détail produit :
  [`../../specs/courantes/module-matchs.md`](../../specs/courantes/module-matchs.md) §9 « Écran
  Adversaires ») et par `OpponentVenueAutoLocator` (repli automatique ci-dessus, ne retient qu'une
  égalité stricte et unique).

## Ce qui est disponible et NON exploité

La reconnaissance P2-19 a mesuré ce que la même clé `key_ms` rend **en plus** — les index restants
(salles, organismes détaillés…) restent non exploités.

→ Inventaire complet, route par route, avec les mesures : [`../../docs/archive/api-ffbb-app-reconnaissance.md`](../../docs/archive/api-ffbb-app-reconnaissance.md)
