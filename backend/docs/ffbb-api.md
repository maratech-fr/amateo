# API FFBB — routes consommées (lot C : auto-alimentation club)

Last verified @ 2026-09-10 (P4-194 + P4-195, `documentation-update`) : § « Réconciliation FBI, canal API »
étendu à la création de compétition depuis le libellé fédéral et à la règle de l'amical
(`FfbbRencontreReconciler::resolveOrCreateCompetition`, `isFriendlyLabel`) ; § Engagements recalé sur
l'inférence de type et l'absence de journées attendues d'une coupe (`FfbbEngagementsController`,
`inferCompetitionType`). Hosts SSRF et routes re-confrontés au code, inchangés.

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
  UNPLACED, seule visible de `VENUE_OVERLAP`/`VENUE_UNAVAILABLE`. Détail :
  [`module-matchs.md`](../../specs/courantes/module-matchs.md) § « Gymnase depuis le libellé ».
- **Filtre strict serveur** (`FfbbApiClient::searchRencontres`) : la recherche plein texte sur le
  code club rend du bruit (un hit « AMICAL PNM » ne concernant pas le club, mesuré) — ne sont
  gardés que les hits où le code club apparaît sur `idOrganismeEquipe1.code` OU
  `idOrganismeEquipe2.code`. Le filtre saison est appliqué en aval par `FfbbRencontreReader`
  (`saison.code` du hit).
- **Ce canal ne touche jamais la fraîcheur xlsx** (`GET /api/fbi-ingestions/latest` ne lit que les
  dépôts `source=FBI_XLSX`) — son propre dépôt `FbiIngestion` est stampé `source=FFBB_API`,
  compteurs seuls. La trace des écarts, elle, n'est plus portée par `FbiIngestion` du tout depuis
  PR-3a (D7) : elle vit sur `Fixture.pendingDeviations`, commune aux deux canaux.

Détail produit complet (appariement 3 étages, front) : [`../../specs/courantes/module-matchs.md`](../../specs/courantes/module-matchs.md) § « Réconciliation FBI (RMM-4) ».

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

## Ce qui est disponible et NON exploité

La reconnaissance P2-19 a mesuré ce que la même clé `key_ms` rend **en plus** — les index restants
(salles, organismes détaillés…) restent non exploités.

→ Inventaire complet, route par route, avec les mesures : [`../../docs/archive/api-ffbb-app-reconnaissance.md`](../../docs/archive/api-ffbb-app-reconnaissance.md)
