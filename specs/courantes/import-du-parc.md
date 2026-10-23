# Import du parc par IA (chantier G) — dry-run

> **Palier 1 (chantier G PR 1) : analyse seulement, aucune écriture.** Un gestionnaire colle, dans
> un chatbot externe, un prompt qui lui fait rendre tout son parc (équipes, gymnases, créneaux,
> mutualisations, coachs, réservations) au format `parc-v1` ; l'app analyse ce texte et rend,
> ligne par ligne, un aperçu cochable de ce qu'elle ferait — sans jamais écrire. L'import RÉEL
> (bascule des cases cochées en écriture transactionnelle) et l'écran qui consomme cet aperçu sont
> le périmètre de PR suivantes, pas encore livrées.

## 1. Format `parc-v1`

Le corps de la requête est le texte JSON brut rendu par l'assistant — l'opération API Platform
est déclarée `deserialize: false` (`ClubResource`) pour que le contrôleur lise lui-même
`$request->getContent()` : un JSON illisible donne le 422 nommé du validateur, jamais le 400
générique de désérialisation d'API Platform.

`App\Service\ParcImport\ParcPayloadValidator` est le foyer unique de la forme :

- `format` est un **verrou de version** : seule la valeur `"parc-v1"` est lue. Absent ou une
  autre valeur (`"parc-v2"`, un ancien `"socle-v1"`) → refus nommé, jamais une lecture « au
  mieux ».
- Sections **requises**, chacune une liste (vide acceptée) : `equipes`, `gymnases`, `creneaux`,
  `mutualisations`, `reservations`. `coachs` est **optionnelle** (absente = aucun coach dans ce
  parc).
- Chaque élément est validé par forme (nom requis, genre parmi `M`/`F`/`MIXTE`, jour 1-7,
  heure `HH:MM`, durée entière ≥ 1 minute, `equipes_en_parallele` 1 à 3…) ; un motif de refus
  **nomme le rang** de l'élément dans sa section (« équipe n°3 : genre inconnu ») pour que le
  gestionnaire corrige sans ouvrir le code.
- Tout champ ou toute section **hors du format** (niveau, adresse, effectif…) est **ignoré en
  silence**, jamais une erreur — le chatbot n'est pas sous contrôle de l'app et peut enrichir sa
  sortie ; seule la liste blanche est lue.

Le validateur est pur (aucun accès base) : il ne sait dire que « la forme est lisible », pas
« ça correspond à quelque chose ». L'appariement aux entités existantes est le rôle de
l'analyzer (§3).

## 2. Endpoint et gate

`POST /api/clubs/{id}/parc-import/analyze` (`App\Controller\ParcImportAnalyzeController`,
opération API Platform `parc_import_analyze` sur `ClubResource`).

Les refus, dans l'ordre (`App\Service\ParcImport\ParcImportGate`, patron
`App\Service\TeamImportGate` — refus byte-identiques, keyés sur le `{id}` du chemin) :

1. pas d'adhésion active sur ce club → **404** `{"error":"Club not found."}` (jamais 403, qui
   fuiterait l'existence du club) ;
2. membre non gestionnaire → **403** `{"error":"Forbidden."}` ;
3. saison archivée → **409** (`SeasonAccessGuard::assertWritable`) ;
4. socle **EN VIGUEUR** → **409** (`SocleGuard::assertSeasonPlanNotChosen`) — importer un parc
   est un geste d'**onboarding**, avant qu'un plan de saison pointe une version (rouvrir d'abord).
   C'est l'inverse de `TeamImportGate`, qui ne porte pas cette garde ;
5. limiteur `parc_import` **par utilisateur** (JWT) → **429**, consommé après l'auth pour que
   403/404/409 gagnent d'abord.

Puis, dans le contrôleur (le corps n'est lu qu'une fois l'appelant autorisé et non throttlé) :

6. corps au-delà de **256 Ko** → **413** ;
7. forme invalide (format/sections/éléments) → **422**, motifs du validateur ;
8. dénombrement au-delà de **60 équipes**, **20 gymnases**, ou **500** (créneaux + réservations
   cumulés) → **422** nommé par borne dépassée.

Succès → **200**, le corps rendu par l'analyzer (§3).

## 3. Analyse (dry-run) — `App\Service\ParcImport\ParcImportAnalyzer`

L'analyzer n'écrit rien. Il rend, par section (`equipes`, `gymnases`, `creneaux`,
`mutualisations`, `coachs`, `reservations`), une liste de lignes `{index, libelle, statut,
coche, cibleId, raison}` plus un récap chiffré par section
(`{total, apparie, suggere, aCreer, decoche, alerte}`), et en tête `{format: "parc-v1"}`.

### Statuts d'une ligne

- `apparie` — un unique existant correspond (nom normalisé **exact**) : cochée, `cibleId` posé.
- `suggere` — un unique candidat **flou** (pas d'exact) : cochée, `cibleId` posé, le gestionnaire
  confirme.
- `aCreer` — aucun existant, exact ou flou : cochée, `cibleId` nul, l'élément naîtra à l'import.
- `decoche` — rien à faire sans décision humaine : ambiguïté (≥ 2 candidats flous) ou idempotence
  (déjà présent) — décochée, `raison` posée.
- `alerte` — collision à trancher à la main (deux équipes sur la même case non déclarée,
  chevauchement de créneaux, capacité ≥ 2 sur un gymnase existant non divisible, Σ séances
  communes dépassant une équipe membre) — décochée, jamais un 500.

### Appariement

Gymnases (`App\Service\Basketball\VenueAliasResolver::exactMatch`/`candidates`) : égalité
normalisée stricte sur le nom ou un alias confirmé (exact) puis ressemblance floue
(candidats) — `exactMatch` rend `null` si zéro ou ≥ 2 égalités strictes, `candidates` rend la
liste complète pour que l'analyzer distingue « aucun » (à créer) de « plusieurs » (ambigu,
décoché), deux cas que l'ancien `suggest()` confondait en `null`. Équipes et coachs : même
normalisation (`App\Service\Basketball\VenueLabelNormalizer`), appliquée à un pool d'entités
actives du club (équipes/coachs inactifs ignorés). L'étanchéité tenant vient de
Doctrine/RLS — les dépôts ne voient que le club+saison résolus, un homonyme d'un autre club ne
s'apparie jamais.

### Par section

- **Équipes** — appariement par nom ; si une ligne appariée porte un champ `seances` différent
  des séances hebdomadaires actuelles de l'équipe, la ligne porte en plus
  `extra.miseAJour = {champ: "seances", de, a}` — une mise à jour **proposée**, pas appliquée.
  Le **cap payant** de l'offre (`App\Service\PlanEntitlements::remainingTeamSlots`/
  `teamCapExceededMessage`, même foyer que le refus à l'écriture ailleurs) s'applique seulement
  aux lignes `aCreer` : au-delà des places restantes, les lignes en surplus sont **décochées**,
  `raison` = le message de l'offre. Une offre illimitée (`remainingTeamSlots` null) ne borne
  rien ; sans saison résolue, le cap n'est pas appliqué.
- **Gymnases** — appariement seul (exact/flou/à créer/ambigu).
- **Créneaux** — `aCreer` par défaut ; `alerte` si le créneau chevauche un autre créneau du
  **même gymnase le même jour** dans ce parc, ou si `equipes_en_parallele ≥ 2` vise un gymnase
  **existant** dont `canSplit` est faux (garde de capacité de
  `VenueTrainingSlotStateProcessor` rejouée au dry-run) — un gymnase **à créer** n'est jamais
  alerté sur ce point : il naîtra divisible, dérivé de la capacité demandée.
- **Mutualisations** — un groupe dont l'ensemble d'équipes (toutes résolues) égale exactement
  l'ensemble d'un bloc `SharedTrainingBlock` du **socle** existant s'apparie à ce bloc ; sinon
  `aCreer`. La garde Σ séances communes ≤ séances hebdomadaires d'un membre
  (`App\Service\SoloReservationBudget::forTeamsWithBlockSubstituted`, même foyer que
  `SharedTrainingBlockStateProcessor`) est rejouée : une violation passe en `alerte`.
- **Coachs** — appariement seul, même patron que les équipes, sans cap.
- **Réservations** — `alerte` si deux réservations ou plus visent la **même case** (gymnase +
  jour + heure) sans qu'un créneau divisible (`equipes_en_parallele ≥ 2`) ou une mutualisation du
  parc ne la couvre ; `decoche` (« Réservation déjà présente dans le planning ») si une
  `Reservation` du **socle** (`schedulePlanId` null) existe déjà pour la même équipe, le même
  gymnase, le même jour et la même heure de début ; `aCreer` sinon.

## 4. Anti-abus

Limiteur dédié `parc_import` (`backend/config/packages/rate_limiter.yaml`), **par utilisateur**
(JWT) : 30/heure en prod, patron `xlsx_import` (même ordre de grandeur de travail CPU/mémoire —
décoder et apparier tout un parc contre la base) ; consommé **après** la gate d'auth (403/404/409),
dans `ParcImportGate::gate`. Les bornes de taille/dénombrement (§2, points 6-8) sont vérifiées
dans le contrôleur, après la gate et le throttle.

## 5. Hors périmètre de cette PR

Aucune écriture : les cases cochées de cet aperçu n'ont pas encore d'écran pour les consommer, ni
de route pour les persister. Le format du prompt donné au gestionnaire (texte qui produit le
`parc-v1`) et l'écran de l'aperçu cochable suivent dans des PR ultérieures du même chantier.
