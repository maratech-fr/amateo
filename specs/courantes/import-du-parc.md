# Import du parc par IA (chantier G) — analyse et import réel

> Un gestionnaire colle, dans un chatbot externe, un prompt qui lui fait rendre tout son parc
> (équipes, gymnases, créneaux, mutualisations, coachs, réservations) au format `parc-v1`.
> **Palier 1 (chantier G PR 1) : analyse.** L'app analyse ce texte et rend, ligne par ligne, un
> aperçu cochable de ce qu'elle ferait — sans jamais écrire (§3). **Palier 2 (chantier G PR 2) :
> import réel.** Le gestionnaire a tranché cet aperçu (coché/décoché, ré-apparié, corrigé) ; l'app
> bascule les lignes cochées en écriture, en une seule transaction tout-ou-rien (§6).
> L'écran qui pilote ces décisions depuis l'aperçu et le texte du prompt donné au gestionnaire
> restent le périmètre de PR suivantes du même chantier (§7).

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
- Une équipe peut porter `categorie` (déduite par le chatbot) — **optionnelle**, mais si présente
  doit être une chaîne non vide (sinon refus nommé « la catégorie doit être une chaîne non
  vide. ») : une chaîne vide n'est jamais lue « au mieux » puis mal classée à l'import.
- Tout champ ou toute section **hors du format** (niveau, adresse, effectif…) est **ignoré en
  silence**, jamais une erreur — le chatbot n'est pas sous contrôle de l'app et peut enrichir sa
  sortie ; seule la liste blanche est lue.

Le validateur est pur (aucun accès base) : il ne sait dire que « la forme est lisible », pas
« ça correspond à quelque chose ». L'appariement aux entités existantes est le rôle de
l'analyzer (§3).

## 2. Endpoint et gate

`POST /api/clubs/{id}/parc-import/analyze` (`App\Controller\ParcImportAnalyzeController`,
opération API Platform `parc_import_analyze` sur `ClubResource`) pour l'analyse (§3) ;
`POST /api/clubs/{id}/parc-import` (`App\Controller\ParcImportExecuteController`, opération
`parc_import`) pour l'import réel (§6). **Les deux routes partagent EXACTEMENT la même gate et le
même limiteur** — l'analyse n'est pas moins gardée que l'écriture.

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

Puis, dans le contrôleur (le corps n'est lu qu'une fois l'appelant autorisé et non throttlé) —
**mêmes bornes sur les deux routes**, appliquées au `payload` dans le cas de l'import réel :

6. corps au-delà de **256 Ko** → **413** ;
7. forme invalide (format/sections/éléments) → **422**, motifs du validateur ;
8. dénombrement au-delà de **60 équipes**, **20 gymnases**, ou **500** (créneaux + réservations
   cumulés) → **422** nommé par borne dépassée.

Succès → **200**, le corps rendu par l'analyzer (§3) ou par l'executor (§6).

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
dans `ParcImportGate::gate`, **sur les deux routes** (analyse ET import réel partagent le même
compteur). Les bornes de taille/dénombrement (§2, points 6-8) sont vérifiées dans le contrôleur,
après la gate et le throttle.

## 6. Import réel (chantier G PR 2) — `App\Service\ParcImport\ParcImportExecutor`

Le gestionnaire a tranché l'aperçu de §3 ; `POST /api/clubs/{id}/parc-import` bascule les lignes
cochées en écriture. Corps `{payload, decisions}` :

- `payload` : l'objet `parc-v1`, **re-validé en forme** (`ParcPayloadValidator`, sur les mêmes
  octets que l'analyse) et en bornes (§2, points 7-8) — une analyse acceptée peut quand même être
  rejouée tard, l'exécuteur ne fait confiance à rien venu du client ;
- `decisions` : `[{section, index, coche, cibleId?, corrections?}]`. Seules les lignes
  `coche: true` sont écrites. `cibleId` apparie à une entité **existante**, **re-vérifiée tenant**
  côté serveur (club ET saison, patron A10 `FbiFixtureImporter::ownedVenueForImport`) — un
  `cibleId` d'un autre club ou d'une autre saison échoue nommé, jamais une fuite tenant.
  `corrections` remplace des champs simples : `seances` pour une équipe, `jour`/`debut`/`duree`
  pour un créneau ou une réservation. Sur une équipe **appariée**, seul le nombre de séances peut
  bouger (`corrections.seances`) — jamais le genre, la catégorie ou le niveau.

**Une seule transaction, tout ou rien** (`EntityManagerInterface::wrapInTransaction`) ; une ligne
cochée invalide au moment de l'écriture lève `UnprocessableEntityHttpException` nommant la ligne
(« équipe n°3 : … »), la transaction est annulée et le contrôleur rend **422** `{errors: […]}` —
rien n'est écrit. Succès → **200** `{recap: {<section>: {crees, apparies, misAJour, ignores}}}`.

Le **cap payant** (`PlanEntitlements::remainingTeamSlots`) est vérifié pour TOUTES les équipes
`aCreer` cochées **avant tout persist** — jamais « les premières lignes passent, les suivantes
échouent au milieu d'une écriture déjà commencée ».

### Ordre d'écriture

Gymnases → équipes → créneaux → blocs de mutualisation (+ réservation de groupe) → coachs
(+ `TeamCoach` MAIN) → réservations.

- **Gymnases** — un gymnase créé naît **sans adresse** ; son `canSplit` est **dérivé** (vrai si un
  créneau du parc demande `equipes_en_parallele ≥ 2` sur ce même gymnase, sinon faux).
- **Équipes** — appariée par `cibleId` ou par nom normalisé : seul `sessionsPerWeek` peut encore
  changer (`corrections.seances`), tout le reste de l'équipe existante est intouché. Créée : la
  **catégorie** vient de `categorie` si le texte la porte — appariement par **nom normalisé** à une
  catégorie existante du club, sinon création via la maison partagée `App\Service\
  SportCategoryResolver::findOrCreate` (catalogue club d'abord, puis catalogue global
  `clubId = null`, sinon catégorie custom rangée après le catalogue existant). Sans `categorie`,
  l'équipe est classée **« À classer »** (repère explicite à reclasser à la main) — jamais un
  rattachement muet à une catégorie du catalogue qui mentirait sur l'âge ou le genre.
- **Créneaux** — les gardes **existantes sont rejouées depuis leur maison**, jamais recopiées :
  capacité (`equipes_en_parallele ≥ 2` n'est permis que sur un gymnase `canSplit`, même règle que
  `VenueTrainingSlotStateProcessor::validateCapacityForVenue`) et chevauchement (même gymnase, même
  jour — `validateNoOverlap`). Une violation refuse la ligne, nommée.
- **Mutualisations** — un groupe d'équipes dont l'ensemble égale **exactement** celui d'un
  `SharedTrainingBlock` du socle existant (`schedulePlanId` null) est apparié ; sinon la garde Σ
  séances communes ≤ séances hebdomadaires d'un membre (`SoloReservationBudget::
  forTeamsWithBlockSubstituted`, même foyer que `SharedTrainingBlockStateProcessor`) est rejouée
  **avant** tout persist, puis le bloc et ses membres naissent au socle. **Réservation de
  groupe** : si la case (gymnase/jour/heure, par `cibleId` ou `corrections`) se résout, chaque
  membre reçoit sa réservation co-localisée via la maison du rail `/api/reservations/group`
  (`GroupReservationController::writeBlockReservationsAtomically`), gardée par
  `ReservationGroupOccupancy::assertBlockReservationAllowed` ; idempotente (tous les membres déjà
  réservés sur cette case → rien) et sans effet si la case ne se résout pas — le bloc est alors
  déclaré sans sa réservation, à poser à la main.
- **Coachs** — apparié par `cibleId` ou par nom normalisé, sinon créé (prénom/nom scindés sur le
  premier espace). L'équipe du coach doit être résolue dans ce run (créée plus haut ou déjà
  existante) sinon refus nommé. Le lien `TeamCoach` rôle **MAIN** est posé s'il n'existe pas déjà
  (idempotent).
- **Réservations** — idempotente par triplet (équipe + gymnase + jour + heure, `schedulePlanId`
  null) ; sinon la garde d'occupation (`ReservationGroupOccupancy::
  assertIndividualReservationAllowed`) est rejouée avant l'écriture, posée au socle.

### Idempotence et noms hostiles

Rejouer le même import **n'ajoute aucun doublon** : un gymnase/une équipe/un coach déjà présent
(nom normalisé) est réutilisé, un créneau/une réservation déjà présent (triplet case) est
réutilisé, un bloc portant exactement le même ensemble d'équipes est réutilisé, un lien
`TeamCoach` MAIN déjà posé ne se double pas.

Tout nom (gymnase, équipe, coach) est **neutralisé** avant écriture : `=`, `+`, `-`, `@`, et toute
tabulation ou retour-ligne **en tête** sont retirés — le nom stocké ne peut jamais devenir une
formule vivante à l'ouverture de l'export XLSX (couplé au `StringValueBinder` de
`SpreadsheetGenerator`).

### Service partagé — `App\Service\SportCategoryResolver`

Le « trouver ou créer une catégorie de sport » vivait dans `FfbbExcelImporter`
(`findOrCreateSportCategory`) ; cette PR l'extrait en service PARTAGÉ (comportement inchangé :
appariement par nom exact club puis catalogue global, création custom rangée après le catalogue)
pour que l'import `.xlsx` FFBB et l'import de parc par IA le réutilisent sans le dupliquer.
`FfbbExcelImporter` délègue désormais entièrement à ce service.

### NR gatant — `CrossStack/ParcImportReservationSemanticsGateTest`

Axes *constraint semantics* + *generation pipeline* (`CLAUDE.md` §7.1) : une réservation saisie par
l'import de parc est **honorée** par le vrai moteur `/generate` (contrat 1.5), de bout en bout
(texte `parc-v1` → `ParcImportExecutor` → `ScheduleConstraintBuilder` → moteur réel). Scénario
falsifiable dans les deux sens — un gymnase a deux cases (lundi 18:00 réservée par l'import,
mercredi 18:00 libre), l'équipe préfère (souple) le mercredi : avant l'import, le solve retombe sur
le mercredi ; après, la réservation importée est un verrou HARD qui pose la séance le lundi malgré
la préférence contraire. Bloquant — step `ci.yml` et ligne `docs/testing/blocking-tests.md`.

## 7. Hors périmètre de ce chantier (pour l'instant)

Deux PR restent à venir : **l'écran** qui consomme l'aperçu cochable de §3 et pilote les décisions
de §6 (cocher/décocher, ré-apparier, corriger, déclencher l'import) et **le format du prompt**
donné au gestionnaire (le texte, collé dans un chatbot externe, qui produit le `parc-v1`).
