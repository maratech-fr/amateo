# Import du parc par IA (chantier G) — analyse et import réel

> Un gestionnaire colle, dans un chatbot externe (GPT / Gemini / Claude / Copilot / DeepSeek /
> Mistral…), un prompt qui lui fait rendre tout son parc d'entraînement au format `parc-v2` :
> **une ligne par CASE** du planning (gymnase, jour, heure, durée, équipes, groupe optionnel).
> **Analyse (dry-run, §3).** L'app DÉRIVE de ces cases les sections qu'elle sait déjà traiter
> (gymnases, équipes, créneaux, mutualisations, réservations) et rend, ligne par ligne, un aperçu
> cochable de ce qu'elle ferait — sans jamais écrire. **Import réel (§6).** Le gestionnaire a
> tranché cet aperçu (coché/décoché, ré-apparié, corrigé) ; l'app bascule les lignes cochées en
> écriture, en une seule transaction tout-ou-rien. **L'écran (§7)** : `ParcImportModal`, ouverte
> depuis les étapes Équipes et Gymnases du wizard (mode saison), porte les quatre temps — prompt à
> copier, collage, aperçu cochable, import + récap. **Couverture Behat (§8)** prouve le parcours
> bout en bout sur la stack réelle.
>
> **Format recalé le 2026-10-25 (lot correctif import IA, « G2 »).** Le format `parc-v1` (sections
> structurées `equipes`/`creneaux`/`mutualisations`/`reservations`, jamais déployé) est **remplacé**
> par `parc-v2` : plusieurs chatbots réels ne rendaient pas de façon fiable des sections déjà
> structurées, mais rendaient fidèlement les cases telles qu'elles apparaissent sur le document
> source — le serveur dérive désormais lui-même le reste depuis cette structure plus simple. Les
> **coachs ne sont plus extraits** (D11, saisis à la main). Backend (dérivation, validateur, prompt,
> PR1) **et** écran `ParcImportModal` (PR2, §7) sont désormais recalés sur `parc-v2`.

## 1. Format `parc-v2`

Le corps de la requête est le texte JSON brut rendu par l'assistant — l'opération API Platform
est déclarée `deserialize: false` (`ClubResource`) pour que le contrôleur lise lui-même
`$request->getContent()` : un JSON illisible donne le 422 nommé du validateur, jamais le 400
générique de désérialisation d'API Platform.

`App\Service\ParcImport\ParcPayloadValidator` est le foyer unique de la forme :

- `format` est un **verrou de version** : seule la valeur `"parc-v2"` est lue. Absent ou une
  autre valeur (`"parc-v1"`, l'ancien format jamais déployé, ou toute autre) → refus nommé,
  jamais une lecture « au mieux ».
- `cases` est **requise** (liste, vide acceptée) : chaque élément porte `gymnase` (texte non
  vide), `jour` (entier 1-7, lundi = 1), `debut` (`HH:MM`), `duree` optionnelle (entier de
  minutes ≥ 1, défaut 90 déduit en aval), `equipes` optionnelle (liste de noms, ≤ 6 par case —
  plafond anti-bombe, une case sans équipe porte une liste vide) et `groupe` optionnel (libellé
  texte d'un bloc mutualisé, ex. « CEC »). Un motif de refus **nomme le rang** de la case
  (« case n°3 : le jour doit être un entier de 1 à 7 ») pour que le gestionnaire corrige sans
  ouvrir le code.
- `gymnases` est **optionnelle** (plusieurs chatbots réels l'omettent, l'app déduit alors les
  gymnases des cases) ; présente, chaque élément porte un `nom` non vide.
- Un champ ou une section hors du format (une vieille section `coachs` d'un ancien chatbot, une
  clé inconnue par case) est **ignoré en silence**, jamais une erreur.

**Récupération (D4)** : le parse décode d'abord le JSON brut ; si (et seulement si) ce premier
parse échoue, une passe de récupération dé-habille un bloc Markdown ```` ```json … ``` ```` et
convertit les guillemets typographiques (« “ ” ‘ ’ ») avant de re-tenter — un JSON déjà valide
n'est **jamais** retouché, ses valeurs (guillemets typographiques compris) survivent intactes.

Le validateur est pur (aucun accès base) : il ne sait dire que « la forme est lisible », pas
« ça correspond à quelque chose ». La dérivation (§3) et l'appariement aux entités existantes
sont le rôle de l'analyzer/l'executor.

### Dérivation des sections — `App\Service\ParcImport\ParcCaseDeriver`

Foyer **unique** (service pur, aucune DB) appelé à l'identique par l'analyzer (§3) et l'executor
(§6) — le contrat de décisions `{section, index}` désigne donc les mêmes lignes des deux côtés.
Pour chaque case :

- le **groupe** et les vraies **équipes** de la case : un `groupe` explicite l'emporte (toutes les
  `equipes` sont alors des équipes) ; sinon, dans une case à ≥ 2 équipes, un unique libellé
  non-équipe (acronyme court tout en capitales, non reconnu comme équipe) est pris pour le
  groupe, les autres restant les équipes ; toute autre configuration n'a aucun groupe ;
- une case est **mutualisée** si elle porte ≥ 2 équipes et que `terrains_separes` n'est pas vrai ;
- **gymnases** : les gymnases déclarés (section `gymnases`, si présente) puis ceux des cases,
  dédupliqués par nom normalisé ;
- **équipes** : une entrée par équipe distincte (clé d'identité insensible à la casse/aux
  accents/à l'espacement, mais le marqueur de genre conservé — « U15M 1 » ≡ « U15-1 »), `genre`/
  `categorie` déduits du nom par `TeamNameInterpreter` (ci-dessous), `seances` = nombre de cases
  où elle apparaît ;
- **créneaux** : un par case ayant un `jour`, durée transportée, `equipes_en_parallele` = le
  nombre d'équipes si `terrains_separes` est vrai, sinon 1 ;
- **réservations** : une par équipe des cases **non mutualisées** (les membres d'une case
  mutualisée reçoivent leur réservation par le bloc, à l'import, §6) ;
- **mutualisations** : une par case mutualisée, étiquetée par son `groupe` ; `seances_communes`
  = le nombre de cases portant exactement le même ensemble d'équipes (mutualisation récurrente) ;
- **alertes transverses**, pures fonctions des cases, toujours présentes dans la réponse
  d'analyse (jamais bloquantes) :
  - `equipe_deux_endroits` — une équipe est placée dans deux gymnases au même jour+heure ;
  - `heure_implausible` — un créneau commence avant 07:00, après 22:00, ou finit après 23:30 ;
  - `gymnase_sans_case` — un gymnase déclaré (section `gymnases`) n'a aucune case.

### `TeamNameInterpreter` — genre et catégorie déduits du nom

Service pur qui déduit, du seul nom d'une équipe lu dans une case, son **genre** (M/F/MIXTE) et sa
**catégorie** d'âge : un token jeune FFBB `U<n>` suivi d'un marqueur de genre (`U18F3` → U18/F),
sans marqueur (`U13` → U13/MIXTE) ; un préfixe senior gendré `SM`/`SF` (`SM2` → Senior/M) ; un
libellé senior/vétéran sans marqueur de genre → MIXTE ; tout le reste (activité, section, nom de
groupe) → **« À classer »**, MIXTE — un repère explicite à reclasser à la main, jamais une
catégorie devinée qui mentirait sur l'âge ou le genre. La catégorie rendue est le nom canonique du
catalogue club (`CategoryCatalog`) quand le token y correspond, sinon le token brut ou le repère
« À classer ».

### `TeamLabelMatcher` — correcteur de nommage, jamais filtre

Service pur qui apparie un libellé d'équipe lu dans une case aux équipes **déjà saisies** du club
(cadrage G2 D5-D7, consigne fondateur « correcteur jamais filtre ») :

- une distance de Levenshtein ≤ 2 à **une seule** équipe → `suggere` (à confirmer), **jamais**
  appliqué d'office ; une égalité **canonique** (accents/casse/espacement repliés, marqueur de
  genre ignoré — « U15M 1 » ≡ « U15-1 ») vise directement la bonne équipe en `apparie` ; **deux**
  équipes dans le seuil → `ambigu` (décoché côté analyse, `cibleId` null) ; aucune → `aCreer` (le
  libellé reste proposé en création, jamais filtré) ;
- une résolution **automatique** (sans décision humaine — contexte des blocs/réservations, §3/§6)
  n'accepte **que** l'appariement exact : un candidat flou (« suggéré ») ne s'applique jamais
  d'office, seule la confirmation du gestionnaire (via le `cibleId` de sa décision) le fait.

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
7. forme invalide (`format`/`cases`/`gymnases`/éléments) → **422**, motifs du validateur (§1) ;
8. au-delà de **500 cases** (bombe) → **422** nommé « Trop de cases : 500 au maximum par
   import. » — le plafond de **6 équipes par case** (§1) est, lui, une erreur de FORME (point 7).

Succès → **200**, le corps rendu par l'analyzer (§3) ou par l'executor (§6).

## 3. Analyse (dry-run) — `App\Service\ParcImport\ParcImportAnalyzer`

L'analyzer n'écrit rien. Il DÉRIVE les sections (`ParcCaseDeriver`, §1) puis rend, par section
(`gymnases`, `equipes`, `creneaux`, `mutualisations`, `reservations` — **plus de section
`coachs`**), une liste de lignes `{index, libelle, statut, coche, cibleId, raison}` plus, selon la
section, `candidats` (équipes/gymnases — les candidats `{id, nom}` d'un verdict `suggere`/`ambigu`,
pour le sélecteur de la ligne, §7), `seances`/`seancesActuelles` (équipes — ci-dessous),
`extra.miseAJour`/`extra.groupe`/`extra.gymnaseId`/`extra.position` (une mutualisation ou une
réservation porte `position` : le créneau de **sa case dérivée**, gymnase/jour/heure/durée + le
gymnase résolu — le front place la case de la grille et amorce l'éditeur **exclusivement** d'après
cette position, jamais `payload.cases[index]`, car l'index d'une ligne de ces deux sections est son
rang dans la liste dérivée, décalé du rang des cases du parc) plus un récap chiffré par section
(`{total, apparie, suggere, aCreer, ambigu, decoche, alerte}`), un bloc `fiabilite`, les `alertes`
transverses (§1) et en tête `{format: "parc-v2"}`.

La réponse porte en plus `payload` : le parc **re-tendu** tel que `ParcPayloadValidator::parse()`
l'a relu (même passe de récupération que §1 D4 — bloc Markdown, guillemets typographiques), pas le
texte brut collé. Le front l'embarque pour amorcer ses corrections sur place (jour/heure/durée
d'un créneau, D12) et le reposte **tel quel** comme `payload` à l'import (§6) plutôt que de
re-décoder le texte collé lui-même — un collage qui n'a été lisible QUE grâce à la récupération
serveur reste ainsi exploitable à l'import (`ParcImportModal.tsx`, lot correctif « G2 » PR 2/2 D4,
2026-10-25).

### Statuts d'une ligne

- `apparie` — un unique existant correspond (exact) : cochée, `cibleId` posé.
- `suggere` — un unique candidat flou (équipes : Levenshtein ≤ 2 via `TeamLabelMatcher`,
  §1 ; gymnases : candidat flou via `VenueAliasResolver`) : cochée, `cibleId` posé, le
  gestionnaire confirme.
- `aCreer` — aucun existant, exact ou flou : cochée, `cibleId` nul, l'élément naîtra à l'import.
- `ambigu` — ≥ 2 candidats dans le seuil (équipes/gymnases) : décochée, `cibleId` nul, `raison`
  posée, et **porte ses `candidats`** (`{id, nom}`) pour le sélecteur « À trancher… » de la ligne
  (§7) — un correcteur qui refuse de choisir à la place du gestionnaire, jamais un `decoche` muet.
- `decoche` — idempotence (déjà présent) ou cap d'offre dépassé — décochée, `raison` posée.
- `alerte` — collision à trancher à la main (deux équipes sur la même case non déclarée,
  chevauchement de créneaux, capacité ≥ 2 sur un gymnase existant non divisible, Σ séances
  communes dépassant une équipe membre) — décochée, jamais un 500.

### Fiabilité (D10)

`fiabilite.taux` = part des lignes **appariables** (équipes + gymnases, ni créneaux ni
mutualisations ni réservations) rendues `apparie` ou `suggere` (1.0 si aucune ligne appariable) ;
`fiabilite.anomalies` = nombre d'alertes transverses (§1) ; `fiabilite.bandeau` (jamais
bloquant) est vrai si `taux < 0.5` **ou** `anomalies ≥ 3`.

### Par section

- **Équipes** — appariement par `TeamLabelMatcher` (§1), verdict `ambigu` tel quel (ci-dessus).
  Chaque ligne porte `seances` : le nombre de séances **lu** dans le parc (dérivé du nombre de
  cases) — amorce le champ « Séances » éditable de l'aperçu (ce qui *sera* importé, corrigeable
  avant import, aussi sur une ligne « Créer », §7). Une ligne **appariée** porte en plus
  `seancesActuelles` (les séances actuelles de l'équipe visée) pour l'indication « actuellement N »
  et la base de comparaison : le champ ne part en `corrections.seances` à l'import (§6) que s'il
  diffère de `seancesActuelles`. Le **cap payant** de l'offre
  (`App\Service\PlanEntitlements::remainingTeamSlots`/`teamCapExceededMessage`, même foyer que le
  refus à l'écriture ailleurs) s'applique seulement aux lignes `aCreer` : au-delà des places
  restantes, les lignes en surplus sont **décochées**, `raison` = le message de l'offre. Une
  offre illimitée (`remainingTeamSlots` null) ne borne rien ; sans saison résolue, le cap n'est
  pas appliqué.
- **Gymnases** — appariement seul (exact/flou/à créer/ambigu, `VenueAliasResolver::exactMatch`/
  `candidates`), verdict `ambigu` portant ses `candidats` comme pour une équipe.
- **Créneaux** — `aCreer` par défaut ; `alerte` si le créneau chevauche un autre créneau du
  **même gymnase le même jour** dans ce parc, ou si `equipes_en_parallele ≥ 2` vise un gymnase
  **existant** dont `canSplit` est faux (garde de capacité de
  `VenueTrainingSlotStateProcessor` rejouée au dry-run) — un gymnase **à créer** n'est jamais
  alerté sur ce point : il naîtra divisible, dérivé de la capacité demandée.
- **Mutualisations** — un groupe dont l'ensemble d'équipes (toutes résolues **exactement**, une
  suggestion non confirmée ne compte pas) égale exactement l'ensemble d'un bloc
  `SharedTrainingBlock` du **socle** existant s'apparie à ce bloc ; sinon `aCreer`. La garde
  Σ séances communes ≤ séances hebdomadaires d'un membre
  (`App\Service\SoloReservationBudget::forTeamsWithBlockSubstituted`, même foyer que
  `SharedTrainingBlockStateProcessor`) est rejouée : une violation passe en `alerte`. Le libellé
  de la ligne est la liste des équipes jointes par « + » ; `extra.groupe` porte le `groupe` de la
  case (affichage, jamais redérivé).
- **Réservations** — `alerte` si deux réservations ou plus visent la **même case** (gymnase +
  jour + heure) sans qu'un créneau divisible (`equipes_en_parallele ≥ 2`) ou une mutualisation du
  parc ne la couvre ; `decoche` (« Réservation déjà présente dans le planning. ») si une
  `Reservation` du **socle** (`schedulePlanId` null) existe déjà pour la même équipe, le même
  gymnase, le même jour et la même heure de début ; `aCreer` sinon.

### Appariement des gymnases

`VenueAliasResolver::exactMatch`/`candidates` : égalité normalisée stricte sur le nom ou un alias
confirmé (exact) puis ressemblance floue (candidats) — `exactMatch` rend `null` si zéro ou ≥ 2
égalités strictes, `candidates` rend la liste complète pour que l'analyzer distingue « aucun » (à
créer) de « plusieurs » (ambigu, décoché). L'étanchéité tenant vient de Doctrine/RLS — les
dépôts ne voient que le club+saison résolus, un homonyme d'un autre club ne s'apparie jamais.

## 4. Anti-abus

Limiteur dédié `parc_import` (`backend/config/packages/rate_limiter.yaml`), **par utilisateur**
(JWT) : 30/heure en prod, patron `xlsx_import` (même ordre de grandeur de travail CPU/mémoire —
décoder et apparier tout un parc contre la base) ; consommé **après** la gate d'auth (403/404/409),
dans `ParcImportGate::gate`, **sur les deux routes** (analyse ET import réel partagent le même
compteur). Les bornes de taille/dénombrement (§2, points 6-8) sont vérifiées dans le contrôleur,
après la gate et le throttle.

## 6. Import réel (chantier G PR 2, recalé « G2 » PR1) — `App\Service\ParcImport\ParcImportExecutor`

Le gestionnaire a tranché l'aperçu de §3 ; `POST /api/clubs/{id}/parc-import` DÉRIVE à nouveau les
sections (`ParcCaseDeriver`, même contrat de décisions que l'analyse) et bascule les lignes
cochées en écriture. Corps `{payload, decisions}` :

- `payload` : l'objet `parc-v2`, **re-validé en forme** (`ParcPayloadValidator`, sur les mêmes
  octets que l'analyse) et en bornes (§2, points 7-8) — une analyse acceptée peut quand même être
  rejouée tard, l'exécuteur ne fait confiance à rien venu du client ;
- `decisions` : `[{section, index, coche, creer?, cibleId?, venueId?, corrections?}]`. Seules les
  lignes `coche: true` sont écrites. `cibleId` apparie à une entité **existante**, **re-vérifiée
  tenant** côté serveur (club ET saison, patron A10 `FbiFixtureImporter::ownedVenueForImport`) — un
  `cibleId` d'un autre club ou d'une autre saison échoue nommé, jamais une fuite tenant. Pour une
  ligne `mutualisations`, `cibleId` désigne le **BLOC** apparié — JAMAIS un gymnase ; le gymnase de
  la réservation de groupe de cette ligne voyage dans le canal **distinct** `venueId` (sélecteur de
  la ligne, §7), lui aussi re-vérifié tenant, vide/absent → résolution par le nom de la case.
  `corrections` remplace des champs simples : `seances` pour une équipe (y compris une ligne
  **créée**, §7), `jour`/`debut`/`duree` pour un créneau, une mutualisation (PLUS `groupe` — le
  libellé du bloc, D8, §7) ou une réservation. Sur une équipe **appariée**, seul le nombre de
  séances peut bouger (`corrections.seances`) — jamais le genre, la catégorie ou le niveau.
  `creer: true` (PR1 D5) signale une **création EXPLICITE** assumée par le gestionnaire sur une
  ligne équipe/gymnase **cochée sans `cibleId`** : il lève le refus d'ambiguïté ci-dessous. Une
  ligne équipe/gymnase cochée sans `cibleId` **ni** `creer` dont le libellé tombe dans le seuil
  flou de ≥ 2 candidats (ambiguïté jugée sur l'état **d'avant import** — un doublon créé plus tôt
  dans le même run ne peut jamais rendre une ligne suivante faussement ambiguë) est **refusée
  (422, rien écrit)**, nommant la ligne : le serveur ne crée ni n'apparie JAMAIS en silence un
  libellé ambigu, le gestionnaire doit trancher dans l'aperçu.

**Une seule transaction, tout ou rien** (`EntityManagerInterface::wrapInTransaction`) ; une ligne
cochée invalide au moment de l'écriture lève `UnprocessableEntityHttpException` nommant la ligne
(« équipe n°3 : … »), la transaction est annulée et le contrôleur rend **422** `{errors: […]}` —
rien n'est écrit. Succès → **200** `{recap: {<section>: {crees, apparies, misAJour, ignores}}}`.

Le **cap payant** (`PlanEntitlements::remainingTeamSlots`) est vérifié pour TOUTES les équipes
`aCreer` cochées **avant tout persist** — jamais « les premières lignes passent, les suivantes
échouent au milieu d'une écriture déjà commencée ».

### Ordre d'écriture

Gymnases → équipes → créneaux → blocs de mutualisation (+ réservation de groupe) → réservations.
**Les coachs ne sont plus extraits ni écrits** (D11) — une vieille section `coachs` d'un ancien
chatbot est ignorée en silence (le dériveur ne la lit pas).

- **Gymnases** — un gymnase créé naît **sans adresse** ; son `canSplit` est **dérivé** (vrai si un
  créneau du parc demande `equipes_en_parallele ≥ 2` sur ce même gymnase, sinon faux).
- **Équipes** — appariée par `cibleId` ou par appariement **exact** (`TeamLabelMatcher`, §1) :
  seul `sessionsPerWeek` peut encore changer (`corrections.seances`), tout le reste de l'équipe
  existante est intouché. Créée : la **catégorie** vient de la `categorie` déduite par
  `TeamNameInterpreter` (§1) — appariement par **nom normalisé** à une catégorie existante du
  club, sinon création via la maison partagée `App\Service\SportCategoryResolver::findOrCreate`
  (catalogue club d'abord, puis catalogue global `clubId = null`, sinon catégorie custom rangée
  après le catalogue existant). Sans catégorie déduite (repère « À classer »), l'équipe est
  classée sous ce même repère — jamais un rattachement muet à une catégorie du catalogue qui
  mentirait sur l'âge ou le genre.
- **Créneaux** — les gardes **existantes sont rejouées depuis leur maison**, jamais recopiées :
  capacité (`equipes_en_parallele ≥ 2` n'est permis que sur un gymnase `canSplit`, même règle que
  `VenueTrainingSlotStateProcessor::validateCapacityForVenue`) et chevauchement (même gymnase, même
  jour — `validateNoOverlap`). Une violation refuse la ligne, nommée. Idempotente par triplet
  (gymnase, jour, heure de début).
- **Mutualisations** — un groupe d'équipes dont l'ensemble égale **exactement** celui d'un
  `SharedTrainingBlock` du socle existant (`schedulePlanId` null) est apparié ; sinon la garde Σ
  séances communes ≤ séances hebdomadaires d'un membre (`SoloReservationBudget::
  forTeamsWithBlockSubstituted`, même foyer que `SharedTrainingBlockStateProcessor`) est rejouée
  **avant** tout persist, puis le bloc naît au socle avec ses membres. Son **label** : la
  `corrections.groupe` du gestionnaire (trim, non vide, D8, §7) l'emporte sur le `groupe` brut de
  la case, les deux bornés à `SharedTrainingBlock::LABEL_MAX_LENGTH` (40 caractères).
  **Réservation de groupe** : le gymnase vient du `venueId` **CHOISI** de la décision
  (re-vérifié tenant), sinon de la résolution par le **NOM** de la case — jamais du `cibleId`, qui
  désigne le bloc apparié, pas un gymnase (P4-363). Si ce gymnase et le jour/heure (`corrections`)
  se résolvent, chaque membre reçoit sa réservation co-localisée via la maison du
  rail `/api/reservations/group` (`GroupReservationController::
  writeBlockReservationsAtomically`), gardée par `ReservationGroupOccupancy::
  assertBlockReservationAllowed` ; idempotente (tous les membres déjà réservés sur cette case →
  rien) et sans effet si la case ne se résout pas — le bloc est alors déclaré sans sa réservation,
  à poser à la main.
- **Réservations** — une par équipe des cases **non mutualisées** (§1) ; idempotente par triplet
  (équipe + gymnase + jour + heure, `schedulePlanId` null) ; sinon la garde d'occupation
  (`ReservationGroupOccupancy::assertIndividualReservationAllowed`) est rejouée avant l'écriture,
  posée au socle.

### Idempotence et noms hostiles

Rejouer le même import **n'ajoute aucun doublon** : un gymnase/une équipe déjà présent (nom
normalisé/appariement exact) est réutilisé, un créneau/une réservation déjà présent (triplet case)
est réutilisé, un bloc portant exactement le même ensemble d'équipes est réutilisé.

Tout nom (gymnase, équipe) est **neutralisé** avant écriture : `=`, `+`, `-`, `@`, et toute
tabulation ou retour-ligne **en tête** sont retirés — le nom stocké ne peut jamais devenir une
formule vivante à l'ouverture de l'export XLSX (couplé au `StringValueBinder` de
`SpreadsheetGenerator`).

### Service partagé — `App\Service\SportCategoryResolver`

Le « trouver ou créer une catégorie de sport » vivait dans `FfbbExcelImporter`
(`findOrCreateSportCategory`) ; extrait en service PARTAGÉ (comportement inchangé : appariement
par nom exact club puis catalogue global, création custom rangée après le catalogue) pour que
l'import `.xlsx` FFBB et l'import de parc par IA le réutilisent sans le dupliquer.
`FfbbExcelImporter` délègue désormais entièrement à ce service.

### NR gatant — `CrossStack/ParcImportReservationSemanticsGateTest`

Axes *constraint semantics* + *generation pipeline* (`CLAUDE.md` §7.1) : une réservation **dérivée
d'une case** par l'import de parc est **honorée** par le vrai moteur `/generate` (contrat 1.6), de
bout en bout (texte `parc-v2` → `ParcCaseDeriver` → `ParcImportExecutor` →
`ScheduleConstraintBuilder` → moteur réel). Scénario falsifiable dans les deux sens — un gymnase a
deux cases (lundi 18:00 réservée par l'import, mercredi 18:00 libre), l'équipe préfère (souple) le
mercredi : avant l'import, le solve retombe sur le mercredi ; après, la réservation importée est un
verrou HARD qui pose la séance le lundi malgré la préférence contraire. Bloquant — step `ci.yml`
et ligne `docs/testing/blocking-tests.md`.

## 7. L'écran — `ParcImportModal` (refonte PR1)

Bouton « Importer mon parc avec l'IA » (`WIZARD_TEXTS.importParcWithAi`), affiché au-dessus du
formulaire d'ajout des étapes **Équipes** (`TeamsStep`/`TeamsEditor`) et **Gymnases**
(`VenuesStep`/`VenuesEditor`) du wizard — **mode saison seulement** (absent de `PeriodTeams`/
`PeriodVenues`, même porte que l'import FBI des équipes, P3-7). `ParcImportModal`
(`frontend/src/features/wizard/parcImport/ParcImportModal.tsx`) est le MÊME composant depuis les
deux entrées (prop `origin: "teams" | "venues"`, pas encore consommée pour varier le
comportement) ; il reçoit les équipes/gymnases déjà saisis (`{id, name}`) pour nourrir le prompt
et le ré-appariement (ci-dessous).

C'est un **assistant à 6 étapes** (`StepRail`, `ParcImportModal.tsx:56-58`) : **Prompt · Coller ·
Équipes · Gymnases · Mutualisations · Réservations** — « Ententes (CTC) » viendra après Équipes en
PR2. Les 4 étapes de contenu (Équipes/Gymnases/Mutualisations/Réservations) restent verrouillées
tant que l'analyse n'a pas réussi ; une analyse réussie ouvre et bascule sur Équipes. Les
Réservations viennent volontairement **en dernier** (« plus on découpe, plus c'est simple » —
décision fondateur) : les créneaux de gymnase (ouvertures) et les mutualisations ont chacun leur
étape dédiée avant d'arriver aux réservations individuelles.

1. **Prompt** — `buildParcPrompt` (`frontend/src/features/wizard/parcImport/promptTemplate.ts`)
   dérive le texte depuis `PARC_V2_SPEC` (version, champs d'une case, plafond d'équipes par case —
   source machine-lisible côté front, gardée en parité avec le validateur, §7.1) : les noms EXACTS
   déjà saisis (équipes, gymnases) sont embarqués pour que l'assistant les réutilise au lieu d'en
   inventer des variantes — **plus les coachs** (D11) — la règle « une équipe par entrée de
   `equipes` » et le champ `groupe` (mutualisation) sont explicites. Le **contexte du club de la
   session** (nom, ville — `useMe().club`, câblé depuis `TeamsStep`/`VenuesStep`) est embarqué dans
   le prompt, utile surtout quand rien n'est encore saisi (terrain vierge). `PRODUCT_NAME` (jamais
   en littéral) nomme l'outil. Bouton « Copier » (`navigator.clipboard`).
2. **Collage** — zone de texte, bouton « Analyser » qui appelle `useAnalyzeParc` (dry-run §3) ;
   succès → bascule automatique sur Équipes, échec → reste sur Coller, erreur près du champ.
3. **Équipes / Gymnases** — **une ligne = le libellé lu (donnée serveur, verbatim) + UN sélecteur
   de décision** (`Listbox`, plus de case à cocher ni de pastille de statut) : groupes « Suggestion
   de l'analyse » (apparier au candidat suggéré), « Candidats possibles » (les autres candidats du
   verdict), « Autre choix » (Créer « … » explicitement, Ignorer cette ligne), puis toutes les
   équipes du club rangées par rang / tous les gymnases. Une ligne **ambiguë** (`statut: "ambigu"`,
   §3) n'a **aucune présélection** (placeholder « À trancher… ») et bloque « Importer » tant
   qu'elle n'est pas tranchée — choisir un candidat, choisir « Créer » (qui part en `creer: true`,
   §6) ou « Ignorer ». Un libellé ambigu coché sans choix est **impossible à poster en doublon** :
   le serveur refuse (422) toute ligne équipe/gymnase ambiguë sans `cibleId` ni `creer` (§6). Sur
   une ligne équipe, un **champ « Séances »** numérique (`sessionsLabel`) est pré-rempli de ce qui
   *sera* importé (`line.seances`, ce qui est lu dans le fichier) et reste corrigeable avant import
   — y compris sur une ligne « Créer » (la création utilise la valeur corrigée) ; une ligne
   **appariée** affiche en plus « actuellement N » (`line.seancesActuelles`) et ne pose
   `corrections.seances` que si la valeur diffère de l'actuelle.
4. **Gymnases (grille)** — sous les lignes de décision, `ParcCaseGrid` (géométrie partagée
   `wizard/lib/weekGrid.ts`, patron `steps/VenueAvailabilityGrid.tsx` du wizard) rend, par gymnase,
   la grille de ses **ouvertures** (les créneaux dérivés des cases) : une case = une ouverture,
   libellée par sa plage horaire ; cliquer ouvre l'éditeur de créneau (point 6).
5. **Mutualisations** — étape dédiée : une ligne par mutualisation (picto lien + nom de groupe +
   équipes + sa position en clair « Lundi 18:00 · Gymnase X », raison ⚠ une seule fois si alerte) ;
   Garder/Ignorer (`SegmentedControl`, pas de case à cocher), un sélecteur de gymnase pour sa
   réservation de groupe (P4-363, → `venueId`), un champ pour le nom du groupe (D8, →
   `corrections.groupe`) et un accès à l'éditeur de créneau pour le jour/heure/durée. Vide →
   `EmptyHint`. Dans la grille des Réservations (point suivant), la case d'une mutualisation reste
   marquée (picto lien + groupe) et son clic mène à cette ligne.
6. **Réservations (grille)** — `ParcCaseGrid` rend, par gymnase, les réservations individuelles ET
   les cases mutualisées marquées ; une case en alerte porte ⚠ et la raison serveur dans son nom
   accessible (jamais la couleur seule). Cliquer une case ouvre un **éditeur de créneau imbriqué**
   (`Modal size="md"`, brouillon transactionnel, clé de case stable `case:${index}`) iso au patron
   du wizard : Gymnase (`Listbox`, absente pour une mutualisation — son gymnase se corrige à
   l'étape Mutualisations), Jour, Début, Durée, Fin calculée. Une réservation/une mutualisation
   s'amorce **exclusivement** d'après sa `position` servie par l'analyse (§3), jamais
   `payload.cases[index]` — son index est son rang dans la section dérivée, pas celui de la case du
   parc.

   Partout dans l'aperçu : un bandeau `NoticeBanner` (D10) s'affiche si `fiabilite.bandeau` est
   vrai (« résultat peu fiable, vérifiez chaque ligne de près ») — **jamais bloquant** ; les
   `alertes` transverses (§1) s'affichent verbatim dans un second bandeau (D9) ; un gymnase naissant
   porte « renseignez l'adresse ensuite ». Une correction (séances, créneau, gymnase, groupe) périme
   l'aperçu (bandeau « Vous avez modifié N lignes : les alertes affichées datent de l'analyse
   précédente », bouton « Revalider » qui reposte `{payload, decisions}` à `/parc-import/analyze`
   pour rejouer l'analyse avec les corrections — §3) ; l'import reste possible SANS revalider, seul
   un ambigu non tranché (ou aucune ligne cochée) bloque. Bouton « Importer N » en pied,
   `disabledReason` découvrable (A11Y-30) qui chiffre les lignes ambiguës en attente.

**Import + récap** — `useImportParc` poste `{payload, decisions}`, `payload` étant le parc
**normalisé** renvoyé par l'analyse (`data.payload`, §3, D4) — jamais le texte brut re-décodé côté
front ni l'aperçu reconstruit ; une erreur serveur (422 `{errors: […]}`, §6) s'affiche **dans la
modale** (`NoticeBanner tone="destructive" role="alert"`, jamais un toast) avec, par ligne fautive,
un bouton « Voir » qui mène à la 1ʳᵉ ligne à trancher et lui donne le focus ; au succès,
équipes/gymnases sont invalidés PARTOUT et le récap chiffré par section (§6) remplace le contenu de
la modale.

Décisions d'interaction (passe `ui-ux-pro-max`) : clôture jamais bloquée (dry-run, rien n'est
écrit avant l'import) sauf pendant un appel en cours (double-soumission désarmée) ; chaque état
nouveau s'annonce en `aria-live` (`role="status"`) plutôt que de voler le focus ; le toast
d'erreur du hook d'analyse (`mutateOnErrorToastGuard`) reste la conduite de l'ANALYSE, jamais
passé à `mutate()` — l'erreur d'IMPORT, elle, vit dans la bannière `role="alert"` ci-dessus.

### 7.1 Parité prompt ⇄ validateur

Le front n'invente aucune règle de format : `PARC_V2_SPEC` est gardée en parité avec
`ParcPayloadValidator` (§1) par `backend/tests/CrossStack/ParcPromptValidatorParityTest.php`
(groupe `contract`) — version, champs d'une case, plafond d'équipes par case, dans LES DEUX SENS :
une dérive d'un côté sans l'autre rougit avant qu'un chatbot ne produise un JSON que le serveur
refuse en silence (ou qu'une donnée se perde).

## 8. Couverture Behat (chantier G PR 4/4, recalée « G2 » PR1)

`backend/features/l-import-du-parc-en-un-geste.feature` (contexte `ParcImportContext`, suite
`import-parc` déclarée dans `backend/behat.dist.php`) prouve le parcours bout en bout sur la stack
réelle, au format `parc-v2`, en trois scénarios :

- analyser un parc de deux équipes, un gymnase, deux cases (dont une mutualisée) rend l'aperçu qui
  propose de tout créer ; importer fait naître les deux équipes et le gymnase, crée le bloc
  mutualisé nommé par le `groupe` de la case, et pose la réservation de la première équipe au
  socle sur sa case réservée du lundi ;
- importer est refusé (409, rien écrit) tant que le planning de saison est **en vigueur** — c'est
  un geste d'onboarding (§2, point 4) ;
- recoller le même parc (déjà importé une fois) fait reconnaître à l'aperçu les équipes et le
  gymnase déjà présents, et réimporter ne crée aucun doublon.

La génération elle-même (le vrai moteur `/generate` honore la réservation dérivée comme un verrou
HARD) n'est **pas** rejouée en Behat — un solve sur le club de démonstration serait lourd et
exigerait de rouvrir/restaurer le socle pour chaque scénario. C'est le NR bloquant
`CrossStack/ParcImportReservationSemanticsGateTest` (§6) qui porte ce smoke sémantique ; cette
feature prouve la jointure qu'il suppose — l'import réel pose bien la réservation au socle
(`schedulePlanId` null).
