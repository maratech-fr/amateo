# Les tests par parcours — charte

> Ce que **chaque niveau de test doit prouver**, par parcours de gestionnaire. C'est le livre de
> règles de l'agent `tank` : il entre par une PROMESSE (jamais par le code), et ce fichier lui dit
> à quel étage la prouver, quand un doublon est voulu, et quand un test peut partir.
> Compagnon de [`testing-strategy.md`](testing-strategy.md) (le graphe CI, backend+engine),
> [`blocking-tests.md`](blocking-tests.md) (la liste canonique des tests qui gatent) et
> [`test-coverage-map.md`](test-coverage-map.md) (qui teste quoi, par axe, et les angles morts).

## 0. Règles fondateur

- **Behat = enchaînement fonctionnel de bout en bout**, calqué sur un geste réel de gestionnaire
  (je me connecte, j'importe, je déclenche, ça me donne ça). Un scénario qui ne prouve qu'UNE chose
  est proscrit — PHPUnit est là pour ça.
- **E2E = parcours navigateur + les contrôles que seul le navigateur peut faire** (contraste,
  reflow, en-têtes nginx, hit-testing, pixel-diff, flux SSE Mercure).
- **Réduire le nombre sans baisser la qualité.** Un scénario ne part que si un test plus bas,
  **cité `fichier:ligne`**, prouve la même promesse ; s'il n'existe pas, on l'écrit AVANT.
- **Décor réaliste** : la préparation passe par l'API (le geste réel du gestionnaire) ; le SQL
  direct est réservé au nettoyage et au pointage.
- **Les parcours décrivent comment PENSE le gestionnaire**, pas ce que fait Amateo : un écart entre
  le parcours et le code est soit un test à réécrire, soit un **manque produit** (ligne de roadmap).
- **Lisibilité** : chaque test énonce en UNE phrase — docblock ou nom — la promesse qu'il protège.
  Un test dont on ne sait pas dire ce qu'il garde est **candidat au retrait**. Les tests ne sont pas
  simples : leur lisibilité compte autant que leur couverture.
- **Les doublons sont ACCEPTÉS — voire voulus — sur les points sensibles** (plusieurs angles sur la
  même vérité) ; HORS de ces points, un test qui existe sous plusieurs formes sans plus-value part.

## 1. Points sensibles (où le doublon est voulu)

Un test à un second niveau, ou un angle de plus, ne se justifie que sur un **point sensible** :

- génération de saison · reprise · fermeture de gymnase · matchs · sécurité · tenant ·
  mutualisation · manipulation de planning · solveur ;
- cycle de vie du planning · pipeline de génération · périmètre engagé · export.

**Saisie — mitigée, par ordre d'importance** : **contraintes** (sensible, doublons tolérés) >
**équipes = gymnases** (un seul angle) > **coachs** (le moins important). Hors points sensibles, un
seul angle suffit, et un second est une dette à retirer.

## 2. La charte des niveaux

Pour chaque étage : ce qu'il **doit** prouver, ce qu'il **ne doit jamais** porter, un **exemple du
dépôt**.

### Behat (`backend/features/`, Gherkin FR, API réelle)
- **Doit** : rejouer un ENCHAÎNEMENT métier de bout en bout sur un décor réaliste préparé par l'API
  (connexion → saisie → déclenchement → vérité). Une feature = une promesse métier.
- **Ne doit jamais** : prouver une seule règle isolée (→ PHPUnit), ni préparer son décor par SQL
  direct (sauf nettoyage/pointage), ni dépendre d'une API externe réelle (FFBB).
- **Exemple** : `generation-du-planning-de-saison.feature` (le seul smoke plein format A→Z).

### E2E Playwright (`frontend/tests/e2e/`, vraie stack)
- **Doit** : rejouer un geste réel de bout en bout (données créées par le test via l'API, nettoyées
  en `finally`) OU mesurer ce que seul un navigateur voit (boundingBox/scroll, couleurs calculées,
  hit-testing, en-têtes nginx, pixel-diff, flux SSE).
- **Ne doit jamais** : porter une règle métier serveur (→ Behat/PHPUnit), la présence/le libellé
  d'un composant (→ Vitest), une parité miroir (→ test de parité).
- **Exemple** : `journey.spec.ts` (seule preuve navigateur du pipeline SSE Mercure) ;
  `a11y-contrast.spec.ts` (contraste calculé, invisible à jsdom).

### PHPUnit (`backend/tests/`)
- **Doit** : prouver UNE règle serveur (garde, invariant, calcul) au plus bas coût, témoin
  falsifiable ; un NR d'axe §7.1 y vit et GATE via `ci.yml` + `blocking-tests.md`.
- **Ne doit jamais** : rejouer tout un parcours (→ Behat), ni rester vert une garde désactivée (une
  garde doublée « en défense en profondeur » sur le même chemin d'écriture peut rendre le NR
  intestable — falsifier, pas lire).
- **Exemple** : `MatchConflictDetectorTest` (les vérités géométriques des conflits de matchs).

#### Patron — NR de migration de données (obligatoire JUSQU'À l'application en prod)
Un test qui rejoue le SQL d'une migration de données (`backend/tests/Integration/Migration*Test`,
purge/backfill/rename/DDL round-trip) est un NR **obligatoire tant que la migration n'a pas tourné
en prod** : il protège le run réel qui va modifier des données. Une fois la migration appliquée en
prod, elle ne rejouera jamais (une migration est jouée une seule fois) — le test ne garde alors plus
rien d'actionnable et part à la passe de réduction. La RÈGLE que la migration posait (l'invariant
d'état qu'elle établit, p. ex. « une équipe ne siège jamais deux fois à la même case ») doit, elle,
vivre dans un test de code VIVANT (garde API/service), jamais dépendre du test de migration pour sa
preuve.

### Vitest (`frontend/src/**`, jsdom + RTL)
- **Doit** : prouver un comportement de composant / un état (rendu, branche, redérivation interdite)
  en mutant la PROD, pas le mock.
- **Ne doit jamais** : prétendre tester le contraste ou le reflow (jsdom n'a aucun moteur de
  layout → vert par construction, il n'atteste rien ; un garde STATIQUE le remplace avant le scan
  Playwright) ni valider une version périmée dans l'image tooling (la rebâtir AVANT).
- **Exemple** : `EvictConfirmDialog.test.tsx` (le verdict d'un déplacement vers une case occupée).

### Pytest (`engine/tests/`, ruff + mypy strict)
- **Doit** : prouver une vérité du solveur — goldens, invariants, hypothesis, sémantique (une
  contrainte saisie est honorée, pas seulement COMPLETED).
- **Ne doit jamais** : dépasser la sémantique du moteur vers le métier backend (le moteur est
  réactif, il n'appelle jamais le backend).
- **Exemple** : les paires `racine` / `semantic/` qui se citent (angles VOULUS, pas des doublons).

### Contrats cross-stack (`backend/tests/**ContractSchemaTest`, groupe `contract`)
- **Doit** : garder le contrat Pydantic ⇄ payload et `CONTRACT_VERSION` (un seul contrat pour
  `/generate` · `/place-matches` · `/validate-assignments`), sync manuelle, pas de codegen.
- **Ne doit jamais** : être oublié quand l'axe backend↔engine est touché (NR d'axe).
- **Exemple** : les trois `*ContractSchemaTest`.

### Gardes doc / CI
- **Doit** : ancrer un précédent opposable (placement d'un doc, taille thématique, fraîcheur,
  présent sans historique, liste bloquante ⇄ `ci.yml`).
- **Ne doit jamais** : être affaibli (exemption élargie, motif réécrit) pour faire taire un rouge —
  on corrige la cause, l'exemption nouvelle exige sa raison structurelle.
- **Exemple** : `BlockingTestsListMatchesCiTest` (⇄ `ci.yml`, les deux sens),
  `DocPlacementTest`, `SpecsCarryNoHistoryTest`.

### Sélecteurs front (tout NOUVEAU test Vitest / E2E)
- Viser le **rôle + le nom accessible** (`getByRole('button', { name: … })`,
  `getByRole('heading', { name: … })`) plutôt que la phrase exacte (`getByText("…")`) quand c'est
  possible — robuste au changement de libellé, porteur de sens a11y.
- La vérification d'un TEXTE précis passe par les **clés de textes centralisées** (fondation PR7,
  cf. ci-dessous) : un test vérifie la CLÉ, jamais la phrase recopiée. Sur un écran pas encore
  migré, un libellé en dur recopié reste toléré là où le rôle ne discrimine pas — on n'en ajoute
  pas sans nécessité, et migrer l'écran est le bon réflexe.

### Textes centralisés (fondation PR7)

- **Maison unique par feature** : `frontend/src/features/<feature>/texts.ts` (scission
  `texts/<écran>.ts` au-delà de ~300 lignes), `export const <FEATURE>_TEXTS = { … } as const
  satisfies Record<string, string | ((...args: never[]) => string)>` ; clés camelCase par
  INTENTION ; interpolation par fonctions typées (pluriels compris). Pas de lib i18n (une typo de
  clé = erreur `tsc`). JAMAIS dans une valeur : libellé de jour (→ `shared/lib/days.ts`),
  heure/durée (→ `time.ts`/`duration.ts`), marque (→ `shared/lib/product.ts`), identifiant interne.
- **Côté test** : un test d'un écran migré assert la CLÉ — `getByText(PLANNING_TEXTS.evictTitle)`,
  ou `getByRole(…, { name: PLANNING_TEXTS.… })` quand c'est plus juste — **jamais** la phrase
  recopiée. Une clé dont la valeur = le texte actuel laisse le test VERT (byte-identique) : c'est
  normal, la valeur bouge, le test non.
- **Garde** : `frontend/src/test/hardcodedTextGuard.test.ts` (patron `timeFormatGuard`), liste
  nominative `MIGRATED_FILES`. Dans un écran listé, un nœud texte JSX littéral ≥ 3 lettres ou un
  littéral FR dans `title=`/`aria-label=`/`placeholder=`/`message=`/`label=` rougit. Largeur
  documentée (reliquat helper-returned / interpolation mêlée → revue) ; exemptions ≤ 5 motivées.
  `texts.ts` est hors portée (il DÉTIENT les littéraux). **Chaque PR qui touche un écran le migre et
  l'ajoute à `MIGRATED_FILES`** ; la migration ne recule jamais.
- **Les E2E gardent leurs littéraux** (`tests/e2e/` hors portée) : témoin indépendant des clés.
- **Suite progressive (reliquat de la vague 1, à ne pas perdre)** : deux clés de `COCKPIT_TEXTS` —
  `weeksCoveredCount` et `venueUnavailableTitle` — sont des **oracles de TEST simplifiés** (pluriel /
  format approchés) que la SOURCE ne consomme pas (NEO a gardé le gabarit inline réel) : à
  réconcilier quand le pluriel/format source sera figé, sans jamais toucher la valeur d'une clé que
  les tests référencent déjà. Le **reliquat NON policé** relevé par NEO (libellés retournés par un
  `switch` d'aide, texte mêlé à `{…}` sur une même ligne — hors de la largeur de la garde) peut
  rester en dur sur un écran migré sans rougir : il part au fil des PR, pas d'un coup.

## 3. Règle anti-doublon opérationnelle (les 4 étages)

1. **Grep la promesse dans les 4 étages** (Behat · E2E · unitaires backend/front/engine · contrats
   & gardes) **+ `blocking-tests.md` + `test-coverage-map.md`** — chaque preuve existante citée
   `fichier:ligne`.
2. **Déjà prouvée au MÊME niveau** → ne pas ajouter.
3. **À un AUTRE niveau** → seulement si le domaine est **sensible** (§1) OU si l'angle est **nommé
   dans le docblock AVEC citation croisée du test jumeau** — patron : les deux
   `test_coach_player_block_exemption.py`, qui se citent l'un l'autre.
4. **Niveau le plus bas d'abord** : la promesse descend au niveau le moins cher qui la prouve
   vraiment (décision du guide E2E ci-dessous : jsdom la voit ? → Vitest ; elle traverse le
   serveur ? → Behat/PHPUnit ; seul un navigateur la voit ? → E2E).
5. **Falsifier dans les DEUX sens** : un test neuf (ou une garde) n'est acquis qu'après l'avoir
   désactivé et constaté le ROUGE, puis le VERT une fois rétabli.
6. **Suppression = filet cité, vert AVANT** : un test ne part que si un test plus bas, **nommé et
   cité**, prouve la même promesse ET est vert sur la base réelle avant le retrait. **Jamais
   `cat >` / `Write` sur un fichier de test existant** (il détruit une suite qui reste verte) — on
   ÉDITE.

## 4. Décor de référence (petit club réaliste)

**« Travailler avec une équipe n'a aucun sens — aucun club n'a une équipe ; un petit club, c'est
10-12 équipes. »** Le décor de référence est un **petit club réaliste de 10-12 équipes / 3
gymnases**, préparé PAR L'API, qui tient le palier solveur 60 s. Pour la reprise, un décor plus
dense (~20 équipes / 6-7 gymnases) tient le palier 180 s (au lieu de 600 s). Un **décor partagé**
(équipes + gymnases + coachs + fichier FBI) se réutilise entre les parcours (naissance de club,
matchs, incident, reprise).

**Maison du décor** : le trait `SmallClubFixtureTrait` (`backend/tests/Behat/`) bâtit ce petit club
jetable PAR L'API (inscription → vérification → approbation, puis 11 équipes de rangs variés dont un
rang S, 3 gymnases avec leurs créneaux, des coachs dont un sur deux équipes), génère et VALIDE son
socle (palier 60 s, complexité équipes × gymnases ≤ 50, `engine/app/main.py::_adaptive_timeout`), et
le nettoie en `AfterScenario` par la purge canonique `app:clubs:purge-erased` (gardée par
`PurgeCompletenessTest`). Un context qui `use` ce trait appelle `teardownSmallClub()`. Son smoke :
`un-petit-club-se-genere-par-l-api.feature`. Réutilisé par la boucle de travail (P3) et, à venir
(PR4c), par la fermeture (P1) et la reprise (P2).

**Fichier FBI canonique** (via `support-xlsx.ts`) : **10 équipes**, import =
**6 équipes × 5 journées + 3 équipes × 10 journées + 1 équipe × 22 journées** (≈ 82 rencontres,
domicile/extérieur). C'est la matière de l'import réel qui alimente le parcours matchs et la
naissance de club (≥ 10 équipes importées, et non une seule).

## 5. Les parcours — promesses et niveau cible

### P1 — Incident de gymnase (fermeture annoncée)
La mairie prévient, je déclare la fermeture, je vois qui est touché, j'échange / mutualise / divise,
je génère, j'exporte en PDF. **Réussite** : les équipes majeures gardent leurs jours ; jusqu'au rang
B ≥ 80 % des séances ; aucune prioritaire ne perd.
- Déclarer → plan de fermeture en copie, séances « à replacer » : bloquants `PeriodCopyBirthTest`,
  `PeriodPlanBirthTest` ; Behat `plan-de-periode-en-overlay` ; le découpage début·milieu·fin d'une
  indisponibilité est replié au plus bas sur `SplitMotherRedateTest` / `WeekSegmentationMirrorParityTest`
  (feature `une-indisponibilite-se-decoupe` retirée, PR4a).
- 3→2 / mutualiser / diviser : overrides de période, gates `SharedBlock*`, capacité
  `VenueTrainingSlot`.
- Bilan par rang : **manque produit** (ligne de roadmap). Aperçu « qui est touché » dès la
  déclaration : **manque produit** (patron `DeletionImpactParityTest`).
- **Cible Behat → PR4c** (décor ~12 équipes / 3 gymnases, palier 60 s) : un enchaînement « une fermeture
  se déclare, montre les touchés, et le planning s'adapte » (rang S garde son jour, aucune séance posée
  au gymnase fermé). Reporté en PR4c (décision fondateur) car la fermeture d'UN gymnase est une contrainte
  datée FACILITY `venue_closed` (elle alimente `effectiveClosedWeekdaysByVenue`, le transcripteur SAUTE le
  couple gymnase/jour fermé en « à replacer », `OrphanPinGuard` interdit un épinglage orphelin) ET, à 3
  gymnases, fermer le plus occupé exige la MUTUALISATION pour rester faisable — le décor de fermeture
  (mutualisation + plus de gymnases) est à cadrer. `plan-de-periode-en-overlay:45` (re-datage découpé avec
  aperçu) gardé.
- **Cible E2E** : `club-life.spec.ts` (incident consulté), `redate-closure.spec.ts` (incident
  re-daté + contraste).

### P2 — Semaine de reprise
Créer la semaine → **collecte des doléances (obligatoire)** → transfert en critères → gymnases
laissés par la mairie → ~20 équipes (SM1/SF1/U21M1 prennent leur semaine) → contraintes d'équipe de
saison écartées du solveur → génération. **Réussite** : doléances appliquées, tenant dans les
gymnases laissés.
- Briques : `voeux-des-coachs`, bloquants `CoachWishTransferTest`, `SeasonSlotKeepSemanticsGateTest`.
- **Cible Behat → PR4c** (≈20 équipes / 6-7 gymnases, palier 180 s) : « la semaine de reprise se prépare
  comme sur le terrain, puis se génère » (aucune séance des équipes en pause, aucune sur un gymnase
  retiré, aucune des U13M le mardi, contraintes d'équipe de saison non parties au solveur). Reporté en
  PR4c (décision fondateur) — demande un mode ~20 équipes du `SmallClubFixtureTrait` et le flux doléances
  → transfert. Le filet PHPUnit `PeriodConstraintSelector` est écrit AVANT de retirer le scénario
  « contrainte d'équipe ignorée en vacances ».

### P3 — Planning de saison
Smoke A→Z, puis nominal : équipes + passerelles, gymnases, coachs, règles de club, préférences,
créneaux S réservés, indispos → génération → corrections (**verrouiller puis relancer le solveur**).
- **Fait (PR4b)** : le smoke `generation-du-planning-de-saison` reste (seul plein format) ; l'enchaînement
  `la-boucle-de-travail-du-gestionnaire.feature` (« je contrains, je réserve, je verrouille, je régénère :
  tout tient », sur `SmallClubFixtureTrait`, 2 solves) replace `une-contrainte-saisie-est-honoree:9` +
  `un-verrou-est-souverain:9` (verrou de saison après régénération) + `un-verrou:31` (créneau libre réservé).
  `une-contrainte-saisie:15` (impossible → FAILED diagnostiqué) gardée ; `un-verrou:15` (déplacer vers une
  case fermée) repliée au plus bas sur `SlotMoveVerdictTest::testMoveToCaseWithNoOpenSlotIsRejectedWithoutCallingEngine`
  (jumeau MOVE de `SlotPlacementVerdictTest::testPlacementWithNoWindowIsRejectedBeforeEngine`) ; `un-verrou:21/:40`
  (jour imposé satisfait par verrou, règle contraire signalée) et `:47/:57/:69` (régénérations de PLAN DE PÉRIODE)
  GARDÉS — promesses distinctes du cycle de vie des plans de période, sans filet plus bas. `une-personne-est-signalee-a-deux-endroits`
  et `l-import-des-equipes` gardés. Passerelles / repos / ≤ 3 séances à la suite = niveau engine, pas de Behat.

### P4 — Matchs
Erreurs de recopie FBI signalées (4a) ; conflits de coach remontés et traités (4b) ; puzzle par
vagues (4c) : le déjà placé ne bouge pas, tout placé, aucun conflit, **anticiper les vagues à venir**
(manque produit, roadmap).
- **Fait (PR4b)** : les 6 vérités GÉOMÉTRIQUES pures de `les-conflits` (D1/lot M — match sur sa propre
  équipe, deux matchs enchaînés 2 h même gymnase, match déjà joué, échauffement du 2ᵉ domicile même/deux
  gymnases, recouvrement réel qui maintient le conflit) sont repliées sur `Unit/Service/MatchConflictDetectorTest`
  (famille `testTwoHomeMatches*` / `testTwoMatchesChainedTwoHoursApart` / `testMatchOnItsOwnTeamTrainingStaysSilent`
  / `testPastMatchesAreDroppedFromEveryFamily…`) ; le chemin RADAR→DÉTECTION de bout en bout reste prouvé par les
  scénarios SERVEUR gardés (heure murale via plan de période pointé, conflit de personne joueuse/coache, enveloppe
  de ligue). `les-conflits:130` (fenêtre de ligue corrigée) repliée sur `Integration/Service/ConflictRadarLeagueWindowTest`.
- **Reste (non fait, ROI faible)** : `placement-des-matchs` demeure la colonne — l'élagage de ce que
  `MatchPlacementSemanticsGateTest` prouve n'a pas été fait (le gate est à MOTEUR réel, il ne couvre pas le chemin
  API→payload que le Behat prouve) ; la fusion `ce-que-fbi-doit-refleter` + `une-erreur-fbi` non faite (réorganisation,
  réduction faible).

### P5 — Mutualisation
Déclarée à l'étape Équipes (5a) ou en cours de route (5b, « on répartit, on ne crée rien »).
Toujours : total de séances par équipe conservé, **un coach du groupe suffit**, déplacement d'un
bloc, régénération en fin de travaux.
- **Cible** : solide — `l-unite-de-placement-est-le-bloc` + les 5 gates bloquants `SharedBlock*`.
  E2E : `mutualize` (mutualiser en cours de route).

### P6 — Retouche
Le plus fréquent : gymnase mal choisi → déplacement manuel ; geste coûteux → avertissement
franchissable (manque produit, roadmap) ; méthode : **verrouiller puis relancer**.
- **Cible** : gestes gardés par les bloquants `SlotMoveVerdictTest`, `SlotPlacementVerdictTest`,
  `SlotMoveGridParityTest`, `SlotRemovalTest` ; l'enchaînement « je déplace, je verrouille, je
  relance, ça tient » est couvert par la cible P3 fondue. Pas d'E2E dédié.

### P7 — Sécurité du tenant
Deux clubs de la même ville, même gymnase, mêmes adversaires : le partagé exprès (suggestions de
gymnase d'adversaire — un compte, jamais un « qui ») le reste, tout le reste est étanche.
- **Cible** : prouvé en PHPUnit bloquant (`TenantIsolationTest`, `RlsIsolationTest`,
  `MatchTenantIsolationTest`, `OpponentVenueSuggestionShareTest`). Pas d'E2E.
- **Écarté (décision fondateur 2026-10-14)** : PAS de nouvel enchaînement Behat « deux clubs ». L'invariant
  du partagé (suggestion de gymnase d'adversaire — « un compte, jamais un qui ») est DÉJÀ prouvé bloquant
  par `OpponentVenueSuggestionShareTest` (falsifié dans les deux sens), et un scénario Behat le dupliquerait
  sans plus-value (le gate couvre la vérité que le Behat illustrerait). `un-club-ne-voit-jamais-un-autre-club`
  reste en l'état.

### P8 — Inscription
Seul (compte → e-mail → code FFBB → approbation du contact officiel → gestionnaire) ou invité par un
membre. **Cible** : garder `inscription-et-premier-planning` (les deux portes) + UN scénario
d'invitation ; replier le reste sur `ClubInvitationTest`, `RegisterClubLookupTest`,
`LoginFailureCopyTest`.

### Parcours « oubliés » — GARDÉS
- **Démo prospect** : la fenêtre de connexion et la capture du code FFBB sont repliées au plus bas
  sur `DemoWindowTest` / `DemoClubCaptureTest` (feature `la-demo-ne-s-ouvre-que-pendant-sa-fenetre`
  retirée, PR4a) ; l'enchaînement démo → conservation → reprise reste porté par
  `le-club-demo-d-un-prospect-se-conserve` (à étoffer en PR4b si besoin).
- **Transition de saison** : E2E `season-transition.spec.ts`, fusionné en 1 parcours.
- **Démarrage en cours de saison** : `un-club-en-cours-de-saison-valide-ses-matchs-en-lot`, en
  l'état.

### Chemins d'abus — toujours gardés, nommés
Démo hors fenêtre (`DemoWindowTest`) · capture du code FFBB d'une démo (`DemoClubCaptureTest`) ·
export anonyme refusé (`ExportAuthenticationTest`) · lien d'invitation révoqué/expiré
(`ClubInvitationTest`) · purge à 30 j d'un compte sans club (`OrphanAccountLifecycleTest`). Un abus
ne part jamais sans remplaçant cité.

## 6. Guide E2E (décision et stabilité)

- **Décider le niveau** : jsdom peut-il le voir ? non → E2E. La promesse traverse le serveur ? →
  Behat/PHPUnit. Sinon → Vitest. Exemples : `veil-double-click` (hit-test, E2E seul) ; contraste
  (`textOpacityGuard.test.ts` statique AVANT, `a11y-contrast` mesure APRÈS).
- **Stabilité** : `test` importé de `./fixtures` ; `settleVeil` avant toute action pointeur
  post-navigation (axe saute un sous-arbre `inert` ; un `toBeVisible` ne voit pas l'`inert`) ;
  **témoin positif** qui ÉCHOUE en nommant le vide quand un scénario peut devenir vide ; données
  uniques par run + DELETE API en `finally` ; `storageState` superadmin unique par run (jamais de
  login par spec → quota `admin_auth`) ; jamais `networkidle` (SSE) ni `waitForTimeout` à sec ;
  re-baseline visuelle par `visual-baselines.yml`.
- **Pièges d'image** : l'image tooling COPIE le code (la rebâtir avant Vitest) ; le service
  `frontend` :8081 sert un `dist` CUIT (rebuild + recreate avant un e2e qui doit voir la modif).

### Les 10 parcours navigateur cibles
1. Naissance d'un club — 10 équipes importées par fichier FBI (journey ; **absorbe** l'ex-import des
équipes, `teams-import` retiré) · 2. Autres portes de l'entrée (auth fusionné) · 3. *(fondu dans 1 —
l'import des équipes FBI est le premier geste de journey)* · 4. Incident consulté (club-life) ·
5. Incident re-daté · 6. Mutualiser en cours de route (`mutualize`) · 7-8-9. Matchs : placer/traiter,
consulter/filtrer (+ FBI à faire), file d'import — réunis dans `matches.spec.ts` · 10. Transition de
saison. P2 / P6 / P7 n'ont pas d'E2E (aucun geste que seul le navigateur prouve).
