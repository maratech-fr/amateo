# API FFBB — routes consommées (lot C : auto-alimentation club)

Last verified @ 2026-08-24 (rotation `documentation-update`, pas de sujet lié à cette passe — sondage
des stamps les plus anciens du dépôt). Re-confronté au code, tout juste : hosts en constantes dures
(`Service/Basketball/FfbbApiClient.php:24-25`, `CONFIG_URL`/`SEARCH_URL`) et index
`ffbbserver_organismes` ✓ · `POST /api/club/ffbb-import` (`Controller/Basketball/FfbbImportController.php:36`) ✓ ·
`PATCH /api/club/info` bien SUPPRIMÉ — zéro occurrence dans `src/` ✓. Non re-sondé cette passe
(déjà vérifié le 2026-08-22, zone non touchée depuis) : fallback `FFBB_MEILISEARCH_TOKEN`,
`FfbbClubPopulator::applyClub`, `FfbbEngagementsController`, le cadrage archivé.

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
- **Les calendriers de rencontres.** Mesuré le 2026-08-02 : l'index `ffbbserver_rencontres` existe, son schéma est complet (36 champs), mais il ne contient que **31 documents de TEST** au niveau national. Les matchs continuent de passer par l'**import FBI**.

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
  d'une poule** (le garde-fou d'import) ; taille de poule → `expectedMatchdays = 2×(N−1)`.

La jointure complète vit dans `FfbbEngagementReader` (filtre saison via `FfbbSeasonCode` — « 26-27 » ↔
`SeasonResolver::seasonYear` 2026 — et réparation du double encodage UTF-8 des libellés, mesuré :
`PrÃ© rÃ©gionale`). Consommée par `FfbbEngagementsController` (`GET /api/ffbb/engagements` +
`POST /api/ffbb/engagements/confirm`, SEC-07 + saison écrivable + socle pointé).

Re-test `ffbbserver_rencontres` du 2026-08-03 : toujours **32 documents de test** (`joue: false`), 0 hit
pour un code club réel — rien à récupérer, l'import FBI reste le chemin.

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
