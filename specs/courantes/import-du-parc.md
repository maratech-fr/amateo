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
>
> **Refonte PR2 (D9, 2026-10-26) : une règle UNIQUE de création d'équipe.** `App\Service\
> Basketball\TeamCreationSuggester` (§1) est désormais la maison unique du rang/genre/entente
> suggérés à la création — partagée par les TROIS imports (parc IA ici, FBI Excel et API FFBB,
> `specs/courantes/module-matchs-import-fbi.md`/`backend/docs/backend-controllers.md`). Le genre
> est désormais **OBLIGATOIRE** à la création (plus de « MIXTE » d'office) : `TeamNameInterpreter`
> ne rend plus qu'un genre lu sur un motif clair ou `null`, et une équipe « à créer » sans genre
> connu (ni déduit ni corrigé) refuse l'import (422 nommant la ligne).
>
> **Lot correctif « PR A » (incident ALAP).** Une case « SF1 + SM1 + SM2 » dont le libellé de
> groupe se lisait comme une troisième équipe a révélé trois manques : rien ne croisait la
> composition d'une séance commune/réservation avec les décisions prises à l'étape Équipes, le
> refus d'une entité introuvable à l'écriture sortait une ligne à la fois (« mutualisation n°2 :
> … »), et l'écran parlait de « Mutualisation » là où le gestionnaire lit « séance commune ». Ce
> lot (1) croise l'analyse des séances communes/réservations avec les décisions Équipes et alerte,
> nommée et codée (`alerteCode`), une équipe ignorée ou introuvable (§3) ; (2) permet de REDÉFINIR
> la composition d'une séance commune (`corrections.equipes`, §6) quand la case a été mal lue ;
> (3) agrège en un seul refus toutes les entités introuvables d'un même import plutôt que
> d'arrêter au premier rang (§6) ; (4) nomme chaque entité en cause au lieu d'un rang (« l'équipe
> « X » est introuvable », plus jamais « n°3 ») ; (5) renomme l'écran et le vocabulaire serveur
> « Mutualisation » → **« Séance commune »** (§6/§7).

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

Service pur qui déduit, du seul nom d'une équipe lu dans une case, son **genre** (`M`/`F`/`null`)
et sa **catégorie** d'âge : un token jeune FFBB `U<n>` suivi d'un marqueur de genre (`U18F3` →
U18/F), sans marqueur (`U13` → U13/`null`) ; un préfixe senior gendré `SM`/`SF` (`SM2` →
Senior/M) ; un libellé senior/vétéran sans marqueur de genre → genre `null` ; tout le reste
(activité, section, nom de groupe) → **« À classer »**, genre `null` — un repère explicite à
reclasser à la main, jamais une catégorie devinée qui mentirait sur l'âge, ni un genre deviné
(**refonte PR2, D9, « plus MIXTE d'office »** : le genre est désormais **OBLIGATOIRE** à la
création, tranché par le gestionnaire quand le nom ne porte aucun motif clair — §1/§7). La
catégorie rendue est le nom canonique du catalogue club (`CategoryCatalog`) quand le token y
correspond, sinon le token brut ou le repère « À classer ».

### `TeamCreationSuggester` — la règle UNIQUE de création d'équipe (D9)

`App\Service\Basketball\TeamCreationSuggester` est la maison **unique** du rang/genre/entente
suggérés à la création d'une équipe, partagée par les TROIS imports (parc IA ici — l'analyzer
l'appelle pour CHAQUE ligne équipe quel que soit son statut d'appariement, pas seulement
`aCreer` : le gestionnaire peut faire basculer n'importe quelle ligne sur « Créer » — ; FBI Excel ;
API FFBB, détail `backend/docs/backend-controllers.md`/`module-matchs-import-fbi.md`) :

- **rang** par catégorie + numéro — seniors n°1/2/3/4+ → S/A/B/C, jeunes (toute catégorie d'âge)
  n°1/2/3+ → A/B/C, loisir → D, **sans numéro → C** (jamais le défaut D) ; le numéro est lu dans le
  **nom** quand la colonne/donnée dédiée est absente (le groupe de chiffres qui suit le marqueur de
  genre, « U18F3 » → 3) ; labels résolus en ids via le repository `PriorityTier` (jamais un id en
  dur) ;
- **genre** lu au nom sur un motif clair (`U13F1`/`SF2` → F, `U18M1`/`SM1` → M), `null` sinon —
  **OBLIGATOIRE** à la création (ci-dessus) ;
- **entente (CTC)** suggérée quand le libellé porte « CTC » ou « ENTENTE » (casse indifférente),
  sans jamais rien décider.

Suggestions servies sur chaque ligne équipe de l'analyse (`suggestedTierId`, `suggestedGender`,
`suggestedCtc`) : elles pré-remplissent les contrôles de création de l'aperçu (§7) dès que le
gestionnaire choisit « Créer », quelle que soit la ligne de départ.

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
`extra.groupe`/`extra.gymnaseId`/`extra.position`/`extra.equipes`/`extra.seancesCommunes`/
`extra.alerteCode` (une séance commune ou une réservation porte `position` : le créneau de **sa
case dérivée**, gymnase/jour/heure/durée + le gymnase résolu — le front place la case de la grille
et amorce l'éditeur **exclusivement** d'après cette position, jamais `payload.cases[index]`, car
l'index d'une ligne de ces deux sections est son rang dans la liste dérivée, décalé du rang des
cases du parc) plus un récap chiffré par section
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
  communes dépassant une équipe membre, équipe citée ignorée ou introuvable d'une séance
  commune/réservation — ci-dessous) — décochée, jamais un 500. Une ligne `alerte` de séance
  commune/réservation porte en plus `extra.alerteCode` : un code MACHINE stable (`equipe_ignoree`,
  `budget_groupe`) que l'écran (§7) lit pour bloquer l'import tant que la ligne reste cochée —
  jamais une re-dérivation de la phrase FR serveur.

### Fiabilité (D10)

`fiabilite.taux` = part des lignes **appariables** (équipes + gymnases **dont le statut n'est pas
`aCreer`** — une ligne « à créer » n'avait rien à apparier, elle n'entre pas au dénominateur, sinon
un club VIERGE afficherait un taux 0 trompeur) rendues `apparie` ou `suggere` (1.0 si aucune ligne
appariable) ; `fiabilite.anomalies` = nombre d'alertes transverses (§1) ; `fiabilite.bandeau`
(jamais bloquant) est vrai si (`taux < 0.5` **et** au moins une ligne appariable existe) **ou**
`anomalies ≥ 3` — un parc neuf, sans aucun existant à apparier, ne lève donc jamais le bandeau
« lecture incertaine » sur ce seul critère.

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
- **Séances communes** (ex-« Mutualisations » — l'écran et les messages serveur disent désormais
  « Séance commune », jamais « Mutualisation », incident ALAP) — un groupe dont l'ensemble
  d'équipes (toutes résolues **exactement**, une suggestion non confirmée ne compte pas) égale
  exactement l'ensemble d'un bloc `SharedTrainingBlock` du **socle** existant s'apparie à ce bloc ;
  sinon `aCreer`. **D'abord**, une équipe citée par la case mais **ignorée** à l'étape Équipes
  (décision `coche: false`) ou **introuvable** des lignes dérivées fait passer la ligne en
  `alerte` (`extra.alerteCode = "equipe_ignoree"`, raison nommant l'équipe en cause — c'est le
  défaut ALAP : « SM shooting » était le libellé du GROUPE, pas une équipe) ; sinon la garde
  Σ séances communes ≤ séances hebdomadaires d'un membre
  (`App\Service\SoloReservationBudget::forTeamsWithBlockSubstituted`, même foyer que
  `SharedTrainingBlockStateProcessor`) est rejouée : une violation passe en `alerte`. Le libellé
  de la ligne est la liste des équipes jointes par « + » ; `extra.groupe` porte le `groupe` de la
  case (affichage, jamais redérivé) ; `extra.equipes` porte la **composition lue**
  (`{id résolu|null, nom}[]`, amorce du sélecteur multiple de l'étape Séances communes, §7) et
  `extra.seancesCommunes` le nombre de séances communes partageant exactement cet ensemble
  d'équipes (recalculé si la composition a été redéfinie — ci-dessous).
- **Réservations** — **d'abord**, une équipe citée **ignorée** à l'étape Équipes ou
  **introuvable** fait passer la ligne en `alerte` (`alerteCode = "equipe_ignoree"`, même garde que
  les séances communes ci-dessus) ; **puis**, une équipe dont tout son budget de séances est déjà
  pris par ses groupes (bloc du socle, résidu individuel nul) ne peut pas poser de réservation
  individuelle : `alerte` (`alerteCode = "budget_groupe"`, message nommant l'équipe et invitant à
  augmenter son nombre de séances) — cette garde (`SoloReservationBudget`/
  `ReservationGroupOccupancy`) est désormais rejouée **dès l'analyse**, pas seulement à l'écriture ;
  sinon `alerte` si deux réservations ou plus visent la **même case** (gymnase + jour + heure) sans
  qu'un créneau divisible (`equipes_en_parallele ≥ 2`) ou une mutualisation du parc ne la couvre ;
  `decoche` (« Réservation déjà présente dans le planning. ») si une `Reservation` du **socle**
  (`schedulePlanId` null) existe déjà pour la même équipe, le même gymnase, le même jour et la même
  heure de début ; `aCreer` sinon.

### Redéfinir la composition d'une séance commune (`corrections.equipes`)

Quand une case a été mal lue (le libellé du groupe pris pour une équipe, une équipe manquante),
le gestionnaire redéfinit la composition depuis l'étape Séances communes (§7, sélecteur multiple) :
la décision porte alors `corrections.equipes`, une liste d'**au moins deux** entrées — chacune soit
l'UUID d'une équipe **existante du club** (re-vérifiée tenant à l'écriture, même garde `ownedTeam`
que partout ailleurs — un UUID d'un autre club est refusé 422, rien écrit, NR gatant
`ParcImportCorrectionTenantNR`, `docs/testing/blocking-tests.md`), soit `import:<index>` qui vise
une ligne Équipes **gardée** de ce même run (créée à l'import) — jamais un gymnase ni une
mutualisation. Rejouée par l'analyse (`/parc-import/analyze`, bouton « Revalider ») ET par
l'import réel (mêmes règles) : les deux substituent les équipes **lues** par la composition
**redéfinie**, puis RECOMPTENT `seances`/`seances_communes` depuis le nouvel ensemble
(`ParcCaseDeriver::recomputeDerivedSessions`, pur) — une équipe ajoutée à la séance commune naît
donc avec le bon nombre de séances (individuelles + communes), sans quoi la garde de budget
individuel la refuserait à tort. Une composition à moins de deux équipes résolues est un abus du
canal : 422 fail-fast avant tout persist (« une séance commune doit regrouper au moins deux
équipes »), jamais une résolution agrégée.

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
  **créée**, §7), `jour`/`debut`/`duree` pour un créneau, une séance commune (PLUS `groupe` — le
  libellé du bloc, §7 — et PLUS `equipes`, la composition REDÉFINIE — « Redéfinir la composition
  d'une séance commune » ci-dessus) ou une réservation. Sur une équipe **appariée**, seul le nombre de
  séances peut bouger (`corrections.seances`) — jamais le genre, la catégorie ou le niveau. Sur une
  équipe **créée** (refonte PR2, D9), `corrections.{tierId, genre, ctc, ctcPartner}` remplace les
  suggestions de `TeamCreationSuggester` (§1) : `tierId` inconnu ou de type aberrant (flottant,
  négatif, chaîne non numérique) → 422 avant persist ; `genre` hors `Gender::cases()` → 422 ; le
  genre est **OBLIGATOIRE** — ni corrigé ni déduit du nom → 422 nommant la ligne (jamais une équipe
  créée sans genre, « plus MIXTE ») ; `ctcPartner` (≤ 120 caractères, au-delà → 422) n'est posé que
  si `ctc` est cochée.
  `creer: true` (PR1 D5) signale une **création EXPLICITE** assumée par le gestionnaire sur une
  ligne équipe/gymnase **cochée sans `cibleId`** : il lève le refus d'ambiguïté ci-dessous. Une
  ligne équipe/gymnase cochée sans `cibleId` **ni** `creer` dont le libellé tombe dans le seuil
  flou de ≥ 2 candidats (ambiguïté jugée sur l'état **d'avant import** — un doublon créé plus tôt
  dans le même run ne peut jamais rendre une ligne suivante faussement ambiguë) est **refusée
  (422, rien écrit)**, nommant la ligne : le serveur ne crée ni n'apparie JAMAIS en silence un
  libellé ambigu, le gestionnaire doit trancher dans l'aperçu.

**Une seule transaction, tout ou rien** (`EntityManagerInterface::wrapInTransaction`). Deux régimes
de refus, jamais confondus : une garde d'ÉCRITURE qui échoue (chevauchement, capacité, Σ séances,
cap payant, composition de séance commune invalide) lève `UnprocessableEntityHttpException`
**fail-fast**, au premier rang fautif ; une entité **introuvable** d'une ligne cochée (équipe/
gymnase qu'aucune décision ne résout) est au contraire **collectée en une passe, toutes sections**
(`ParcImportResolutionException`), puis levée **agrégée** en fin de transaction — plusieurs
entités introuvables dans un même import sortent donc toutes d'un coup, jamais une à la fois au
fil de relances successives (incident ALAP). Dans les deux cas, rien n'est écrit, et chaque message
**nomme la LIGNE** (type + sa case : équipes/gymnase/jour/heure, jamais un rang « mutualisation n°2 :
… ») puis le détail de l'échec — ex. « Séance commune SF1 + SM1 + SM2 · lundi 20:30 · Léon Jouhaux :
l'équipe « SM shooting » est introuvable. », « Réservation U13M1 · mardi 18:00 · Léon Jouhaux :
l'équipe « SM shooting » est introuvable. » : le contrôleur rend **422** `{errors: […]}`. Succès →
**200** `{recap: {<section>: {crees, apparies, misAJour, ignores}}}`.

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
- **Séances communes** — **avant toute écriture**, si la décision porte `corrections.equipes`
  (composition redéfinie, ci-dessus), les équipes **lues** de la case sont substituées par la
  composition **redéfinie** et `seances`/`seances_communes` sont RECOMPTÉS
  (`ParcCaseDeriver::recomputeDerivedSessions`) — une équipe ajoutée naît ainsi avec le bon nombre
  de séances avant que son écriture ne soit tentée. Puis : un groupe d'équipes dont l'ensemble
  égale **exactement** celui d'un `SharedTrainingBlock` du socle existant (`schedulePlanId` null)
  est apparié ; sinon la garde Σ séances communes ≤ séances hebdomadaires d'un membre
  (`SoloReservationBudget::forTeamsWithBlockSubstituted`, même foyer que
  `SharedTrainingBlockStateProcessor`) est rejouée **avant** tout persist, puis le bloc naît au
  socle avec ses membres. Son **label** : la `corrections.groupe` du gestionnaire (trim, non vide,
  §7) l'emporte sur le `groupe` brut de la case, les deux bornés à
  `SharedTrainingBlock::LABEL_MAX_LENGTH` (40 caractères).
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
  posée au socle. Son refus (résidu individuel nul, équipe membre d'un bloc) est **factuel** : il
  dit le budget de séances de l'équipe, son occupation nommée par les groupes, et une action qui
  dépend du contexte — « Passez l'équipe à N+1 séances, ou ignorez cette réservation. » à l'import,
  « Augmentez son nombre de séances dans sa fiche. » ailleurs dans l'app (`actionContext`,
  `ReservationGroupOccupancy::soloOnlyBlockMessage`) — jamais « s'entraîne uniquement en groupe ».

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

## 7. L'écran — `ParcImportModal` (refonte PR1 + PR2)

Bouton « Importer mon parc avec l'IA » (`WIZARD_TEXTS.importParcWithAi`), affiché au-dessus du
formulaire d'ajout des étapes **Équipes** (`TeamsStep`/`TeamsEditor`) et **Gymnases**
(`VenuesStep`/`VenuesEditor`) du wizard — **mode saison seulement** (absent de `PeriodTeams`/
`PeriodVenues`, même porte que l'import FBI des équipes, P3-7). `ParcImportModal`
(`frontend/src/features/wizard/parcImport/ParcImportModal.tsx`) est le MÊME composant depuis les
deux entrées (prop `origin: "teams" | "venues"`, pas encore consommée pour varier le
comportement) ; il reçoit les équipes/gymnases déjà saisis (`{id, name}`) pour nourrir le prompt
et le ré-appariement (ci-dessous).

C'est un **assistant à 7 étapes** (`StepRail`, `ParcImportModal.tsx:59-61`) : **Prompt · Coller ·
Équipes · Ententes (CTC) · Gymnases · Séances communes · Réservations** — « Séances communes »
(ex-« Mutualisations », incident ALAP : l'écran et les messages serveur disent désormais « Séance
commune », jamais « Mutualisation ») et « Ententes (CTC) » (refonte PR2, D9, vient juste après
Équipes, car elle dépend des suggestions de `TeamCreationSuggester`, §1). Les 5 étapes de contenu
restent verrouillées tant que l'analyse n'a pas réussi ; une analyse réussie ouvre et bascule sur
Équipes.
Les Réservations viennent volontairement **en dernier** (« plus on découpe, plus c'est simple » —
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
   `corrections.seances` que si la valeur diffère de l'actuelle. **Refonte PR2 (D9)** — sur une
   ligne équipe passée à « Créer », une **2ᵉ rangée** porte un **Select Rang** et un **Select
   Genre** (pré-remplis par `suggestedTierId`/`suggestedGender`, §1, édités en
   `corrections.tierId`/`corrections.genre`, §6) : le genre est **OBLIGATOIRE**, l'option vide
   porte « À choisir » et déclenche ⚠ « Genre requis » (`aria-invalid`, `border-warning`) — elle
   compte dans le `disabledReason` d'« Importer » (comptée SÉPARÉMENT des lignes ambiguës) tant
   qu'elle n'est pas tranchée. La case Entente (CTC) de cette même ligne a migré vers l'étape
   dédiée (point 4) à la refonte PR2. **Ligne « Ignorer »** (incident ALAP) : le libellé se rend
   **barré et grisé** (jamais seulement une ligne qui disparaît visuellement), et le champ
   « Séances » (comme le Select Rang/Genre d'une ligne « Créer ») **disparaît** — une ligne
   ignorée n'a plus rien à corriger. La raison d'une ligne NON « à trancher » (déjà appariée,
   candidat suggéré…) se lit dans une **infobulle ⓘ** à côté du nom (nom accessible = la raison
   servie par le serveur), plutôt qu'un bloc de texte gris permanent — le ⚠ visible reste réservé
   à une ligne encore « à trancher ».
4. **Ententes (CTC)** (refonte PR2, D9) — une ligne par équipe « à créer » (celles qui portent des
   suggestions, `suggestedTierId` défini) : case Entente pré-cochée si `TeamCreationSuggester` la
   suggère (libellé portant « CTC »/« ENTENTE ») → `corrections.ctc`, décochable ; cochée, un champ
   « club partenaire » (texte libre, ≤ 120 caractères, §6) → `corrections.ctcPartner`, avec le
   repère `CtcMark` devant le nom dès que la case est cochée. Les équipes **déjà au club**
   (appariées/suggérées) s'affichent en **lecture seule** — l'import ne touche jamais l'entente
   d'une équipe existante. Aucune équipe à créer → `EmptyHint`, étape jamais bloquante (pas de
   décision en attente comptée).
5. **Gymnases (grille)** — sous les lignes de décision, `ParcCaseGrid` (géométrie partagée
   `wizard/lib/weekGrid.ts`, patron `steps/VenueAvailabilityGrid.tsx` du wizard) rend, par gymnase,
   la grille de ses **ouvertures** (les créneaux dérivés des cases) : une case = une ouverture,
   libellée par sa plage horaire ; cliquer ouvre l'éditeur de créneau (point 7).
6. **Séances communes** — étape dédiée : une ligne par séance commune (picto lien + nom de groupe +
   équipes + sa position en clair « Lundi 18:00 · Gymnase X », raison ⚠ une seule fois si alerte,
   **barrée/grisée si « Ignorer »**) ; Garder/Ignorer (`SegmentedControl`, pas de case à cocher), un
   sélecteur de gymnase pour sa réservation de groupe (P4-363, → `venueId`), un champ pour le nom du
   groupe (§6, → `corrections.groupe`), un **sélecteur MULTIPLE** (`Listbox mode="multiple"`,
   incident ALAP) pour redéfinir la **composition d'équipes** (→ `corrections.equipes`, §6 — amorcé
   des équipes résolues lues, options = équipes du club + lignes Équipes gardées « à créer » de ce
   run, référencées `import:<index>`) et un accès à l'éditeur de créneau pour le jour/heure/durée.
   Une ligne qui cite une équipe **ignorée ou introuvable** à l'étape Équipes (`alerteCode =
   "equipe_ignoree"`, §3) et restée **cochée/« Garder »** **bloque « Importer »** (pied de modale,
   ci-dessous) tant qu'elle n'est pas tranchée (composition redéfinie, ou ligne passée à
   « Ignorer ») ; une composition redéfinie à moins de deux équipes affiche sa propre raison visible
   (⚠ « Une séance commune regroupe au moins deux équipes — choisissez-les. ») et bloque de même.
   Vide → `EmptyHint`. Dans la grille des Réservations (point suivant), la case d'une séance
   commune reste marquée (picto lien + groupe) et son clic mène à cette ligne.
7. **Réservations (grille)** — `ParcCaseGrid` rend, par gymnase, les réservations individuelles ET
   les cases de séance commune marquées ; une case en alerte porte ⚠ et la raison serveur dans son
   nom accessible (jamais la couleur seule) — une alerte « équipe ignorée ou introuvable »
   (`alerteCode`) et cochée bloque « Importer » au même titre qu'une séance commune (point 6).
   Cliquer une case ouvre un **éditeur de créneau imbriqué** (`Modal size="md"`, brouillon
   transactionnel, clé de case stable `case:${index}`) iso au patron du wizard : Gymnase
   (`Listbox`, absente pour une séance commune — son gymnase se corrige à l'étape Séances
   communes), Jour, Début, Durée, Fin calculée. Une réservation/une séance commune s'amorce
   **exclusivement** d'après sa `position` servie par l'analyse (§3), jamais `payload.cases[index]`
   — son index est son rang dans la section dérivée, pas celui de la case du parc.

   Partout dans l'aperçu : un bandeau `NoticeBanner` (D10) s'affiche si `fiabilite.bandeau` est
   vrai (« résultat peu fiable, vérifiez chaque ligne de près ») — **jamais bloquant** ; les
   `alertes` transverses (§1) s'affichent verbatim dans un second bandeau (D9) ; un gymnase naissant
   porte « renseignez l'adresse ensuite ». Une correction (séances, créneau, gymnase, groupe,
   composition) **ou** le changement d'une décision Équipes (ex. ignorer une équipe citée par une
   séance commune — incident ALAP) périme l'aperçu (bandeau « Vous avez modifié N lignes : les
   alertes affichées datent de l'analyse précédente », UN seul bouton « Revalider » (celui de l'en-tête d'étape ; le bandeau n'en porte pas) qui reposte
   `{payload, decisions}` à `/parc-import/analyze` pour rejouer l'analyse avec les corrections —
   §3) ; l'import reste possible SANS revalider — bloquent seulement un ambigu non tranché, une
   création sans genre tranché (point 3, refonte PR2), une alerte « équipe ignorée/introuvable »
   cochée (points 6-7, incident ALAP) ou une composition à moins de deux équipes, ou aucune ligne
   cochée.

   **Règle des étapes** (finitions PR D, 2026-10-30) : une étape de contenu ne passe au vert qu'APRÈS
   avoir été visitée (même vide) et sans point en attente ; « Revalider » conserve les étapes déjà
   visitées. Une ligne mise en « Ignorer » n'attend plus rien (son alerte passe au vert sans geste) ;
   une alerte gardée (cochée / « Garder ») bloque, et tant qu'une étape n'est pas vue ses alertes
   restent signalées. « Importer » n'est actif que si toutes les étapes de contenu sont vertes. Le nom
   d'un groupe corrigé à l'étape Séances communes est repris à l'étape Réservations. Dans les grilles
   de cases, la pause 12:00-14:00 est teintée (décor) et l'infobulle d'une case dit « Nom · début →
   fin ».

   **Pied de modale** (incident ALAP) : Précédent/Suivant **groupés à gauche**, l'action primaire
   (Analyser/Importer) à droite — plus de bouton TEXTE « Fermer » (la croix de l'en-tête suffit).
   La **raison du blocage est VISIBLE en permanence** au pied (ton `warning`, patron
   `ImportFbiDialog`, pas seulement dans `disabledReason` au survol) : `importBlocked` chiffre
   SÉPARÉMENT les lignes à trancher, les équipes sans genre et les alertes « équipe ignorée/
   introuvable » cochées, avec un bouton « Voir » qui mène à la 1ʳᵉ ligne concernée (genre manquant
   d'abord, puis une alerte gardée).

**Import + récap** — `useImportParc` poste `{payload, decisions}`, `payload` étant le parc
**normalisé** renvoyé par l'analyse (`data.payload`, §3) — jamais le texte brut re-décodé côté
front ni l'aperçu reconstruit ; une erreur serveur (422 `{errors: […]}`, §6) s'affiche **dans la
modale** (`NoticeBanner tone="destructive" role="alert"`, jamais un toast), amenée à l'écran dès
qu'elle apparaît (`scrollIntoView({block: "nearest", behavior: "smooth"})`, sans voler le focus —
elle vivait en tête d'une zone défilante que rien ne ramenait, incident ALAP sur Safari) avec, par
ligne fautive, un bouton « Voir » qui mène à la 1ʳᵉ ligne à trancher et lui donne le focus ; au
succès, équipes/gymnases **et** séances communes/réservations/créneaux de gymnase sont invalidés
PARTOUT (pas seulement équipes/gymnases — un import en crée aussi, incident ALAP) et le récap
chiffré par section (§6) remplace le contenu de la modale.

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
