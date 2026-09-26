# Frontend Strategy — TDD, Stack Fixée & Anti-patterns

Last verified @ 2026-09-26 (`documentation-update`, passe « le présent seulement », 2/3 frontend) —
versions du § Outils de test et § Stack Versions Fixed re-confrontées à `frontend/package.json`
(exactes), `act-warnings-ceiling.json` + `tooling/actWarningsRatchet.ts` existent, `msw` déclaré
(`^2.15.0`) mais zéro import dans `src`/`tests` (confirmé). Chronique des passes antérieures :
`git log -p --follow` ce fichier.

> Fixe le mandat de test, les versions de la stack, les anti-patterns et les règles de
> préservation d'infrastructure. Le détail fonctionnel (routes, composants, wizard) est dans
> `frontend-spec.md` et `frontend-wizard.md` — ce document ne les duplique pas.
>
> ⚠️ **`frontend/package.json` fait foi.** Ce fichier a laissé dériver ses tableaux de
> versions pendant des semaines tout en se présentant comme un verrou : il « figeait » des
> versions que personne n'utilisait. En cas de doute, lire le `package.json`, pas ce tableau.

---

## 1. Testing Strategy — TDD Mandatory

**TDD is MANDATORY for all frontend work. Write tests FIRST, watch them fail (RED), implement minimal to pass (GREEN), then refactor (REFACTOR).**

Aucune exception. Chaque composant, hook, store, route et intégration API doit suivre le
cycle RED → GREEN → REFACTOR avant d'être considéré livrable.

### Règles d'application

| Étape | Action | Critère de sortie |
|------|--------|-------------------|
| **RED** | Écrire le test unitaire / d'intégration AVANT toute implémentation. Lancer le test — il doit échouer pour la bonne raison (assertion manquante, import absent, type error). | Sortie console montre l'échec attendu, pas une erreur de compilation non liée. |
| **GREEN** | Implémenter le code minimal pour faire passer le test. Pas de code défensif non testé. Pas de feature non demandée. | Tous les tests du cycle passent (exit 0). |
| **REFACTOR** | Améliorer la structure (extraction, renommage, typage) sans changer le comportement. Re-lancer les tests après chaque refactor. | Tests toujours verts après refactor. |

### Périmètre de test obligatoire

- **Composants UI** : tests de rendu (React Testing Library) — props, états, accessibilité ARIA.
- **Hooks personnalisés** : `renderHook` + scénarios de cycle de vie.
- **Stores Zustand** : tests d'état, d'actions, de `persist`/`migrate`.
- **Queries TanStack Query** : tests avec un `QueryClient` de test **réel** (`renderHook`,
  jamais un double du hook lui-même) et le module `./api` **voisin** mocké — pas `ky`
  directement (patron vivant : `features/cockpit/queries.test.tsx`,
  `features/wizard/queries.test.tsx`, `features/matches/queries.test.tsx`,
  `features/planning/queries.test.tsx`, `features/auth/queries.test.tsx`). Un test de
  COMPOSANT, lui, mocke légitimement les hooks de sa propre feature (`vi.mock("./queries", …)`) :
  le composant n'est pas responsable d'aller chercher la donnée.
  ⚑ **Preuve d'EFFET, pas de l'appel** (lot AUD-FRT-20, clos le 2026-08-29 — trace
  `specs/courantes/etat-des-lieux.md` §3) : le test qui compte monte le **vrai** lecteur
  (`useConflicts`, `useLogout`, …) sur le même `QueryClient` que la mutation et constate que la
  DONNÉE bouge (refetch, ou cache vidé) — pas un espion sur `invalidateQueries`, qui prouve
  l'appel et ne voit **jamais** une invalidation absente. C'est cette exigence, et elle seule, qui
  a débusqué le seul défaut réel du lot (`matches/queries.ts` : une invalidation manquante
  laissait le radar de conflits en faux vert, cf. `frontend-spec.md` § « Radar de conflits »).
  Modules `queries.ts` restant sans test dédié (`club`, `coach-wishes`, `feedback`, `profile`,
  `release-notes`, `shared/session`) : hors scope de ce lot, à reprendre par le même risque si un
  incident les désigne. `admin/queries.ts` est un abandon délibéré (persona fondateur, décision
  `specs/courantes/etat-des-lieux.md` §2), pas un reliquat.
- **Routes** : tests de navigation (React Router memory router), guards d'auth, redirections.
- **Intégration API** : `vi.mock` du module `queries`/`api` de la feature — l'outil de mock EN
  SERVICE dans toute la suite —, vérification des payloads et headers. `msw` est RÉSERVÉ (voir la
  table d'outillage) : il n'est utilisé nulle part aujourd'hui.

⚑ **Piège du barrel `./api` — le mock suit le SPÉCIFICATEUR importé, pas le module « final »**
(`features/matches/api/`, barrel `index.ts` + 8 modules par domaine, FRT-33, 2026-09-23) : un
`vi.mock("./api")` (ou `vi.mock("@/features/matches/api")`) n'intercepte QUE l'import littéral
`from "./api"` — jamais un import direct d'un sous-module (`from "./api/fixtures"`), même si
`"./api"` n'est qu'un barrel de ré-exports vers ce même fichier. Aujourd'hui le risque est nul :
tous les appelants (`~163`) et toute la suite passent par le barrel, aucun import direct d'un
sous-module `api/*.ts` n'existe. Le jour où un import direct apparaîtra, un test qui mocke encore
`"./api"` ne l'interceptera plus — le sous-module réel s'exécutera (client `ky` compris) sans
qu'aucune assertion ne le signale forcément. Même famille de piège déjà payée dans l'AUTRE sens :
`features/planning/api.ts:96-97` documente un symbole (`isSeasonPlanType`) qui, tant qu'il vivait
DANS le module que plusieurs tests mockent via un `vi.mock("./api")` manuel, devenait `undefined`
sous ce mock (le factory ne le ré-exportait pas) — corrigé en le déplaçant hors de ce module
(`./lib/versions`, jamais mocké). Avant d'ajouter un import direct d'un sous-module `matches/api/*`
quelque part : vérifier tous les `vi.mock("./api")` / `vi.mock("@/features/matches/api")` des
fichiers de test concernés et mocker aussi le sous-module visé si l'import direct est vraiment
nécessaire.

### Extraction de hooks « par sujet » — le déplacement VERBATIM comme condition de sûreté sans test dédié

Motif reconnu à sa 3ᵉ occurrence (`wizard/lib/useStepValidation.ts`, `cockpit/lib/useWeekAdapt.ts`,
puis les six hooks de `matches/lib/` sortis de `CalendarPage.tsx` au lot 7 PR C, FRT-33,
2026-09-24 — trace `specs/courantes/etat-des-lieux.md` §3) : quand une page monolithe accumule des
`useMemo`/`useEffect` sur plusieurs sujets métier indépendants, chaque sujet devient un hook maison
dans `<feature>/lib/useXxx.ts` — **jamais** un découpage arbitraire par taille, un hook = un sujet
(la garde du geste, la chaîne de filtre, une vue temporelle…). ⚠ **Résidus délibérément NON
extraits** : une page garde ce qui n'a pas de sujet propre — un calcul dont le périmètre mentirait
s'il portait le nom d'une vue (`outOfEnvelope`, indépendant de la semaine), un sujet trop petit
pour justifier une interface (~15 lignes éclatées), ou des handlers qui ne prennent sens qu'ancrés
au JSX rendu (ids de cellules, focus, scroll). Chaque résidu **nomme sa raison en commentaire** —
un résidu sans raison écrite est juste un oubli.

**Ce déplacement ne réclame PAS de nouveau test par hook** (exception assumée à la règle RED du
§1 : un déplacement pur ne prouve rien de nouveau, il ne doit rien casser) — à une condition
stricte, le déplacement **VERBATIM** : chaque `useMemo`/`useEffect`/`useRef` migre caractère pour
caractère, tableau de dépendances **inchangé**, les hooks restent appelés **inconditionnellement,
dans le même ordre, avant tout early return**, et les entrées du hook extrait sont des **paramètres
individuels** (jamais un objet d'options qui changerait d'identité à chaque rendu et casserait la
mémoïsation). La preuve n'est pas un test neuf : c'est le test de la page **resté vert et intact**
(`CalendarPage.test.tsx`, 909 l., inchangé au diff) et une revue `git diff main --color-moved` qui
ne doit montrer que des signatures de hooks, des destructurations au call-site, des imports et des
docblocks — tout diff de CORPS invalide le verbatim et exige de repasser par RED → GREEN. Un test
neuf ne se justifie que si le déplacement révèle un trou réel du filet existant (précédent :
`usePlacementGuards.test.ts`, seul hook des six à en gagner un — le test de page ne couvrait qu'un
état d'échec sur trois du treillis chargement/erreur/prêt).

⚠ Cette discipline porte sur l'extraction de **logique** (hooks), pas sur le découpage de **JSX**
en composants — les deux se distinguent : sortir un `useMemo` ne change aucune interface visible,
sortir un bloc de JSX en fait naître une (props). `P4-255` (monolithes hors module matchs) suit sa
propre règle, distincte, posée par le fondateur : jamais de découpage sans filet de tests D'ABORD
— celle-là vise le second cas, pas celui-ci.

**Troisième forme, à ne pas confondre avec les deux précédentes : séparer des sujets qui
COHABITENT, pas en extraire une couche** (`matches/api.ts` → 8 fichiers par domaine, FRT-33,
2026-09-23 ; `wizard/steps/PeriodStructure.tsx` → `PeriodTeams.tsx`/`PeriodVenues.tsx`/
`PeriodConstraints.tsx`, P4-255, 2026-09-24 — 2ᵉ occurrence, pas encore une politique transverse au
sens du seuil des 3 occurrences ci-dessus, le critère est noté ici pour ne pas redécouvrir le
raisonnement à la 3ᵉ). **Le critère qui tranche laquelle des trois formes s'applique : le fichier
d'origine survit-il comme ORCHESTRATEUR de ce qu'il a laissé sortir ?** Extraction de hooks et
découpage de JSX en sous-composants (ci-dessus) répondent OUI — une page reste, elle appelle le
hook ou rend le sous-composant, une interface (paramètres/props) naît à la frontière qu'il faut
concevoir. Ici la réponse est NON : les sujets ne se citaient qu'en commentaire (aucun élément
réellement partagé, aucun tiroir « utils » n'a été créé), et chacun avait déjà son propre appelant
unique — `PeriodStructure.tsx` était importé sous trois angles différents par `TeamsStep.tsx`,
`VenuesStep.tsx` et `ConstraintsStep.tsx`, jamais par un chapeau commun qui les orchestrait
ensemble. Le fichier d'origine **disparaît**, remplacé par ses N enfants **à plat**, chacun nommé
comme son export ; **zéro nouvelle interface à concevoir**, chaque appelant existant repointe sans
changer sa propre forme. C'est le déplacement verbatim le plus sûr des trois : rien n'orchestre,
rien à recomposer — la preuve reste identique (`git diff --color-moved` sans diff de corps, suite
de test inchangée hors la ligne d'import scindée). Candidat visible pour la 3ᵉ occurrence :
`wizard/queries.ts` (940 l.), à confirmer au moment d'y toucher, pas avant.

**Quand un même monolithe réclame PLUS d'une PR : d'abord l'écrivain unique, les carrefours
ensuite** (motif posé sur `planning/PlanningPage.tsx`, P4-255 PR 1, 2026-09-24, 1 871 → 1 648 l.,
cinq hooks — `useVersionLanding`, `usePeriodClosures`, `useLockControls`, `useValidateReopen`,
`usePlanHeader`). Un sujet ne se qualifie pour une extraction verbatim (règle ci-dessus) que si son
état a un **écrivain unique** — un seul endroit qui le pose. Une page qui a grossi pendant des mois
porte aussi des **carrefours** : un état lu et écrit par PLUSIEURS sujets à la fois (exemples
laissés en page sur ce lot : `highlightSlotIds`, posé aussi bien par le déplacement simple, le
déplacement de groupe que le placement à la dérive — trois gestes distincts, chacun avec son propre
échec à surligner ; `diagnosticsCollapsed`, posé par l'arrivée d'un diagnostic, l'ouverture d'un
créneau et le panneau lui-même). Extraire un carrefour avec la même
mécanique reviendrait à choisir arbitrairement UN sujet comme propriétaire et à faire remonter
l'état aux autres par des paramètres — ce n'est plus un déplacement verbatim, c'est une décision de
conception qui engage une interface, et elle doit être prise consciemment, pas héritée de l'ordre
dans lequel les sujets ont été lus. **La bonne coupe entre deux PR n'est donc pas la taille, c'est
l'écrivain** : la première PR prend tous les sujets à écrivain unique (verbatim, sans risque) ; les
carrefours restent en page, chacun avec sa raison écrite (même exigence que les résidus
ci-dessus), jusqu'à ce qu'une décision explicite tranche qui les possède — alors seulement une PR
suivante les sort. Une ligne roadmap qui couvre un fichier à traiter en plusieurs PR reste
**ouverte** tant que ses carrefours n'ont pas de propriétaire décidé, même si chaque PR
individuelle est verte et mergée : « verbatim et sans risque » ne veut pas dire « fini ».

**Et un carrefour finit par avoir son ISSUE** (P4-255 PR 2, même page, 2026-09-24, 1 648 →
1 145 l.) : sur les deux exemples ci-dessus, `highlightSlotIds` a reçu sa décision — propriétaire
unique (`planning/lib/useSlotHighlight.ts`), exposé par **intentions nommées** plutôt que par un
setter (`highlightViolations`/`highlightSlots`/`clearHighlight`, aucune n'écrit l'état à la place
d'une autre). Le régime de preuve n'est PLUS celui du déplacement verbatim (§ ci-dessus) — c'est
**rouge→vert** : un filet d'EFFET posé d'abord sur le comportement ACTUEL (le sujet n'avait jamais
été exercé), puis le hook, puis des falsifications qui montrent que retirer une intention ou
recréer une identité à chaque rendu fait rougir le filet. `diagnosticsCollapsed` reste le seul
carrefour encore en page sur ce fichier, raison inchangée (trois écritures sans répétition — un
hook y serait de la cérémonie).

### Outils de test (versions fixées)

**Deux plafonds, pas un — et ils ne se recouvrent pas** (P4-116, 2026-08-21). Un test d'écran
peut rougir de deux façons qui n'ont pas la même cause et ne se règlent pas au même endroit :

| Plafond | Qui l'impose | Valeur | Ce qu'il garde |
|---|---|---|---|
| `testTimeout` | Vitest (`vitest.config.ts`) | **15 s** (défaut 5 s) | transformer un test **PENDU** en échec |
| `asyncUtilTimeout` | testing-library (`src/test/setup.ts`) | **5 s** (défaut **1 s**) | le budget de `findBy*` / `waitFor` |

⚠ **`findBy*` et `waitFor` n'obéissent PAS à `testTimeout`.** C'est le piège qui a rendu le
diagnostic d'origine incomplet : un `findByRole` qui abandonne au bout d'une seconde rapporte
« Unable to find an element », un échec qui RESSEMBLE à une assertion fausse — on ne le relie
pas spontanément à la charge de la machine. Les échecs mesurés sous contention tombaient à
**1,3 s**, très loin des 5 s qu'on croyait en cause.

⚑ **Pourquoi 15 s et pas 5 s.** Le cas le plus lourd du dépôt met **5,2 s sans aucune charge
concurrente** (`PeriodStructure.test.tsx` › « déplacer un créneau réservé » : une grille hebdo
entière, des centaines de cellules, re-rendue à chacun de ses quatre gestes). C'est du travail
réel. Un plafond qui rougit là-dessus ne mesure plus rien — il produit du bruit selon qui
tourne à côté. ⚠ **Le corollaire à ne pas perdre** : `slowTestThreshold` est posé à **3 s** pour
que « lent » veuille encore dire quelque chose maintenant que le plafond a bougé (le défaut de
300 ms surlignait à peu près tous les tests d'écran, donc ne signalait plus rien). Un test
au-delà de 3 s est colorié dans le rapport : c'est là qu'on regarde si le scénario a dérivé,
**avant** qu'il n'atteigne le plafond.


| Outil | Version (`frontend/package.json`) | Rôle |
|------|---------|------|
| Vitest | 4.x | Runner de test (config `vitest.config.ts` : jsdom, `globals`, setup `src/test/setup.ts`, exclut `tests/e2e/`) |
| @vitest/coverage-v8 · @vitest/ui | 4.x | Couverture (`vitest run --coverage`, `make coverage`, job CI `frontend-coverage` — mesure et cliquet détaillés dans `docs/testing/test-coverage-map.md`) · UI de debug |
| @testing-library/react | 16.x | Rendu et queries DOM |
| @testing-library/user-event | 14.x | Simulation d'interaction |
| @testing-library/jest-dom | 7.x | Matchers DOM |
| jsdom | 30.x | Environnement DOM |
| `vi.mock` (Vitest) | — | **Mock réseau EN SERVICE** : remplace NOTRE module (`queries`/`api` de la feature). C'est l'outil utilisé partout. |
| msw | 2.x | **DÉCLARÉ mais jamais importé — RÉSERVÉ.** Destiné aux tests ciblés d'erreurs HTTP RÉELLES (413, 422 avec `violations[]`, 429, 500 + `X-Request-Id`), là où `vi.mock` est structurellement aveugle : en remplaçant notre module, il court-circuite le client HTTP, la lecture du statut et la traduction d'une erreur en message affiché — jamais exercés. `msw` intercepte le réseau et exerce ce chemin. **Aucun test msw écrit à ce jour** (ex-roadmap P4-254, fermé sans correctif au triage du 2026-09-25 — dette de couverture assumée, msw reste dans l'outillage). |
| Cliquet act-warnings | — | `tooling/actWarningsRatchet.ts` (reporter Vitest) : compte les avertissements React « not wrapped in act » au processus principal, rougit le run dès qu'ils dépassent le plafond versionné `act-warnings-ceiling.json` (FRT-34, même patron que le plancher de couverture ; fil de détente si le compte tombe à 0 alors que le plafond > 0 = capture cassée). |
| @playwright/test | 1.x | E2E (`frontend/tests/e2e/`) |
| **vitest-axe** · **@axe-core/playwright** | 0.x · 4.x | **Assertions a11y** — suite unitaire (`src/test/a11y.test.tsx`) + spec de contraste e2e (`tests/e2e/a11y-contrast.spec.ts`) |
| storybook · @storybook/react-vite | 10.x | Atelier de composants (`npm run storybook`) |

> ⚑ **Ces tables portent la MAJEURE, jamais la mineure** (décision du 2026-08-19, rotation de
> fraîcheur). Elles donnaient `^4.1`, `^29.1`, `^10.68`… et **quatre avaient déjà dérivé** : une
> mineure bouge à chaque lot Dependabot, personne ne repasse ici, et le doc ment en silence. La
> majeure, elle, porte du SENS (React 19 → `createRoot`, Vite 8, Tailwind 4) et ne bouge qu'une
> fois par an. **La version exacte vit dans `frontend/package.json`** — cette table dit ce que
> `package.json` ne dit pas : à quoi sert chaque outil. On ne recopie plus, on pointe.
>
> Ces tableaux ne sont pas un verrou technique (rien ne les vérifie automatiquement) : ils ne
> valent que par leur exactitude.

### L'accessibilité est bloquante, pas indicative

`frontend/eslint.config.js` re-sévérise **tout le set `jsx-a11y` recommandé** en `error` via un
unique interrupteur `A11Y_LEVEL` (garde-fou WCAG 2.2 AA). Le remappage préserve les options
réglées de chaque règle et **laisse désactivées** celles que `recommended` désactive
délibérément (ex. `label-has-for`, qui double-signalerait des `label`/`id` correctement
associés). `label-has-associated-control` connaît les composants maison
(`Input`, `Select`, `TeamSelect`) pour ne pas crier au faux positif sur un
`<label>…<Input/></label>`. Repasser à `warn` ne se fait que pour débloquer temporairement un
gros refactor.

**Ce que le linter NE voit PAS : la taille des cibles.** WCAG 2.5.8 (AA) demande **24 × 24 px**
minimum, et aucune règle `jsx-a11y` ne mesure un rendu. Convention du dépôt, à appliquer à la
main sur tout bouton à **icône nue** : `rounded p-1` autour d'une icône `size-4` → 24 px.
Quand le padding casserait une densité voulue (pastille, poignée de tri), le compenser par une
**marge négative de même valeur** (`p-1 -m-1`, `p-1.5 -m-1.5` pour une icône `size-3`) : la
surface cliquable grandit, la mise en page ne bouge pas. Un `aria-label` ne dispense de rien —
il sert les lecteurs d'écran, pas la motricité (audit AUD-A11Y-12, 2026-08-08).

**Ce que le linter NE voit PAS non plus : une modale plus haute que l'écran.** WCAG 1.4.10
(reflow) exige que le contenu reste atteignable ; un panneau centré (`items-center`) qui
dépasse déborde **en haut ET en bas**, hors viewport, et sans zone défilante le seul recours
est de dézoomer le navigateur. **Le comportement vit dans les DEUX composants partagés**
(`shared/components/ui/modal.tsx` et `confirm-dialog.tsx` — deux copies du même markup) :
panneau `flex flex-col max-h-[calc(100dvh-2rem)]`, en-tête `shrink-0`, contenu enveloppé dans
`min-h-0 overflow-y-auto`. **Aucun écran ne doit re-borner sa hauteur localement** — c'est ce
que trois d'entre eux faisaient, avec trois valeurs arbitraires différentes, pendant que les
autres restaient cassés. `dvh` et non `vh` (sur mobile `vh` ignore la barre d'adresse), et
`min-h-0` est ce qui rend le défilement possible : sans lui un enfant flex refuse de rétrécir
sous son contenu et la zone « défilante » ne défile jamais. Gardé par
`modal-overflow.test.tsx` — jsdom n'ayant aucun moteur de mise en page, le test épingle les
classes qui portent le contrat, faute de pouvoir mesurer le débordement (même limite qu'A11Y-06
pour le contraste). Retour fondateur 2026-08-11.

### La passe de design — quand on invoque `ui-ux-pro-max`, et quand on s'en abstient

Le pack `ui-ux-pro-max` est installé (décision fondateur révisée le 2026-08-11, état des lieux
§2), et son usage est **borné**. Il ne s'invoque pas à chaque PR frontend : 5 packs de design en
contexte permanent, ce sont des doctrines contradictoires à chaque session — c'est le motif qui
avait fait écarter leur installation, et il tient toujours. Sa valeur est le **crible ponctuel**.

**Règle (élargie le 2026-08-21, décision fondateur) : une passe de design se lance quand un écran
naît, change d'APPARENCE, **ou qu'une décision d'INTERACTION est arrêtée**.**

L'énoncé d'origine ne parlait que d'apparence, et bornait la passe aux écrans **publics**. Les deux
limites sont tombées le 2026-08-21 sur le lot C (l'écran de chargement bloquant) : écran **interne**
au wizard, et défauts qui n'avaient rien de visuel — voir la ligne de résultats ci-dessous.

| Cas | Passe design |
|---|---|
| Nouvel écran, nouvelle page (publique **ou interne**), refonte visuelle | **oui** |
| Changement de mise en page, de couleurs, de typographie | **oui** |
| **Décision d'interaction** : ce qui bloque, ce qui attend, ce qui prend le focus, ce qui s'annonce à un lecteur d'écran, ce dont on peut sortir | **oui** |
| Correctif de comportement sans décision d'écran, test, renommage, refactor | non |
| Correction d'un bug UI **déjà identifié** | non — on corrige, et on pose un garde |

⚑ **La passe se lance AVANT que la décision soit figée**, pas en relecture après coup : sur le lot C
elle a renversé un choix déjà arbitré par le fondateur (voir ci-dessous). Passée après
l'implémentation, elle aurait coûté une réécriture.

**Dans un agent**, pas dans le fil principal : une passe sur plusieurs écrans consomme beaucoup
de contexte et ne doit remonter que ses findings. Les agents `general-purpose` et `coder` portent
l'outil `Skill` ; `Explore`, `planner` et les `cavecrew-*` ne l'ont pas.

⚠ **Le skill ne MESURE rien, et l'écrire ici évite de le croire.** Il lit du code et des décisions
de design ; il ne rend aucune page, ne calcule aucun contraste, ne mesure aucun débordement. Ce
qu'il sait faire, en revanche, c'est **confronter une décision à un corpus de règles** — et là il
tranche (lot C, ci-dessous). Distinguer les deux est ce qui rend la règle utilisable : on
l'interroge sur des CHOIX, jamais sur un RENDU. Mesuré le 2026-08-11 : sa base de
99 règles UX ne contient **rien** sur la hauteur d'une modale — sa seule règle « modale » porte
sur la confirmation d'un geste destructif, et sa ligne la plus proche dit « no horizontal
scroll », l'axe opposé. **Il n'aurait pas attrapé le défaut de reflow du même jour.** Ce qui
valide, ce sont les gardes : Vitest, l'e2e Playwright, les tests d'a11y.

Ce qu'il apporte quand on le sollicite, mesuré trois fois :

- **Landing, PR #502** (apparence) : 2 échecs WCAG de contraste invisibles aux gardes jsdom, 2 bugs
  de rendu, une rupture de ton, et 17 tirets cadratins de cadence IA.
- **Lot C, 2026-08-21** (interaction) : il a renversé **deux décisions déjà arbitrées**. (1) Le rôle
  ARIA du voile : `role="alertdialog"` écarté au profit de `role="status"` + `aria-live="polite"` —
  `alert`/`alertdialog` est réservé à ce qui exige une attention immédiate, vole le focus, et
  PROMET un dialogue dont on peut sortir. (2) La règle « on ne relâche jamais les clics » écartée :
  `progressive-loading` et `escape-routes` interdisent un blocage long sans issue — d'où l'abandon
  explicite qui annule la requête, seule sortie qui ne rouvre pas le trou de concurrence. Il a aussi
  listé sept manques (barème `z-index`, retour du focus, flou trompeur, contraste par thème, chemin
  d'échec, chiffres tabulaires, `inert`). **Aucun de ces défauts n'était visuel** — c'est pourquoi la
  règle ne parle plus seulement d'apparence.
- **Largeurs, 2026-08-21** (P4-107 3ᵉ tranche) : il a imposé **une borne à la décision** — une
  échelle qui grandit avec le viewport doit TERMINER sur un plafond fixe (`DON'T Full-width text on
  large screens`, `DON'T Let text span full viewport width`) — et rappelé la seule mesure chiffrée
  de son corpus, 65-75 caractères par ligne, **valable à l'intérieur d'un conteneur élargi** : c'est
  d'elle que vient le `[&_p]:max-w-prose` de `FichePage`. ⚑ **Et une de ses conclusions a été
  ÉCARTÉE, ce qui vaut d'être écrit** : il désignait le vrai coupable dans le shell pleine largeur
  (son corpus nomme `w-full (no max-width)` comme l'anti-pattern) et prescrivait de le re-capper à
  `max-w-7xl`. C'est la décision fondateur du 2026-08-18, prise sur usage réel d'écrans denses —
  **une règle générique de corpus ne renverse pas un retour terrain**. La passe TRANCHE contre un
  corpus ; elle ne connaît ni l'usage ni l'historique du produit.

---

## 2. Stack Versions Fixed

Les versions suivantes sont **figées**. Aucune mise à jour de version majeure ou mineure sans
décision explicite et re-vérification de compatibilité.

| Package | Version (`frontend/package.json`) | Rôle | Notes |
|---------|--------------|------|-------|
| react / react-dom | 19.x | Framework UI / rendu DOM | React 19 — pas de ReactDOM.render, createRoot obligatoire (voir §3) |
| vite | 8.x | Bundler / dev server | Plugin `@tailwindcss/vite` |
| typescript | 6.x | Typage | `~6.0` = patch libre, minor figée |
| tailwindcss | 4.x | CSS utility-first | Configuration via CSS `@theme`, pas `tailwind.config.js` (voir §3) |
| @tanstack/react-query | 5.x | Server state | v5 — pas de `onSuccess` (voir §3) |
| zustand | 5.x | Client state | v5 — `migrate()` requiert null check (voir §3) |
| ky | 2.x | Client HTTP | v2 — API fetch moderne |
| @dnd-kit/core + sortable + utilities | ^6.3 / ^10.0 / ^3.2 | Drag & drop | Accessible ; utilisé pour le tri des équipes (wizard) |
| react-router | 8.x | Routing | Data router (`createBrowserRouter`), **`lazy` par route** (P4-6), nested layouts. ⚠ paquet **`react-router`**, PAS `react-router-dom` |
| lucide-react | 1.x | Icônes | SVG tree-shakeable |
| @sentry/react | 10.x | Reporting d'erreurs | **Erreurs seules** — pas d'APM, pas de replay, `tracesSampleRate: 0` (quota free tier préservé). DSN absent → init sautée, SDK inerte. ⚠ **L'activer demande DEUX gestes** (P4-65) : poser `VITE_SENTRY_DSN` au build **ET** autoriser l'hôte d'ingestion du DSN dans `connect-src` (`docker/frontend/csp.conf`, qui n'autorise aucun tiers). Le DSN seul initialise le SDK et la CSP jette chaque envoi **en silence** ; un garde de build (`frontend/tooling/sentryCspGuard.ts`) refuse désormais cette combinaison. INF-01 |
| @radix-ui/react-label + react-slot | ^2.1 / ^1.1 | Primitives UI | Base des composants shadcn-style de `shared/components/ui/` |

> **FullCalendar n'est PAS installé** : la grille planning est un composant custom
> (`src/features/planning/WeekGrid.tsx`). La liste exhaustive des dépendances vit dans
> `frontend/package.json` — source de vérité.

### Règles de verrouillage

1. `package.json` utilise des plages `^`/`~` ; `package-lock.json` est la source de
   vérité effective des versions installées — tout changement de version doit être
   reflété dans le lockfile.
2. Une mise à jour de version majeure = un commit dédié + re-run complet des tests
   (`make -C frontend test`) + `make -C frontend lint` (`tsc -b --force` — jamais
   `tsc --noEmit`, voir §3) + `make -C frontend build`.

---

## 3. Anti-patterns Banned

Les patterns suivants sont **interdits** dans le code du rebuild. Tout PR les introduisant
est rejeté automatiquement.

| # | Anti-pattern | Pourquoi banni | Correct à la place |
|---|-------------|----------------|-------------------|
| 1 | `ReactDOM.render(...)` | Supprimé en React 19 — lance un avertissement puis casse en production. | `createRoot(container).render(...)` |
| 2 | `onSuccess` dans `useQuery` / `useMutation` (TanStack Query v5) | Supprimé en v5 — causait des effets de bord implicites et des fuites de state. | `useEffect` sur `data`/`isSuccess`, ou `select` pour transformer les données. |
| 3 | `migrate()` sans null check dans Zustand 5 | `persist` v5 passe `persistedState` potentiellement `null` — un `migrate` qui assume un objet non-null lance une `TypeError`. | `migrate: (persistedState: unknown, version: number) => { if (persistedState === null) return initialState; ... }` |
| 4 | `@apply` dans des composants Tailwind v4 | Tailwind v4 déprécie `@apply` dans les composants — casse l'extraction utility-first et le tree-shaking CSS. | Composer avec des classes utility directement, ou extraire un composant React réutilisable. |
| 5 | `tailwind.config.js` (fichier JS de config) | Tailwind v4 remplace la config JS par la directive CSS `@theme` dans le fichier CSS principal. Le fichier JS est ignoré ou cause des conflits. | Définir les tokens (couleurs, fonts, breakpoints) via `@theme { ... }` dans `src/index.css`. |
| 6 | Lire `error.response` dans un `catch` d'appel ky | ky 2.x **consomme lui-même** le corps de la réponse d'erreur et l'expose en `error.data` avant tout consommateur — re-lire la réponse lance `body stream already read`. C'est aussi pourquoi le client n'a **pas** de hook `beforeError`. | Lire **`error.data`** (`shared/api/errors.ts`, `errorMessage()`). |
| 7 | `data ?? []` sur une query en premier chargement | Fabrique un **vide crédible** (« aucun créneau », « aucun réglage ») qui pousse le gestionnaire à re-saisir (doublons) ou à valider une période qu'il croit vide. Symétriquement, traiter `isError` comme fatal détruit un écran qui fonctionne alors que seul un refetch d'arrière-plan a échoué. | `readState()` / `readFailed()` (`shared/lib/readState.ts`) — trois états sur le seul critère « a-t-on une donnée ? ». |

> L'anti-pattern historique « `eslint-config-prettier` pas en dernier » a été retiré : le
> projet n'utilise ni prettier ni `eslint-config-prettier` (aucun script `format`).

### Détection automatique

- **ESLint** (`frontend/eslint.config.js`) : `@eslint/js`, `typescript-eslint`,
  `eslint-plugin-react-hooks`, `eslint-plugin-react-refresh`, `@tanstack/eslint-plugin-query`,
  `eslint-plugin-jsx-a11y`. L'anti-pattern n°1 est **réellement bloqué** par une règle
  `no-restricted-syntax` qui interdit `ReactDOM.render`.
- **TypeScript** : `make -C frontend lint` (`tsc -b --force`) — piège `tsc --noEmit` et son
  pourquoi : maison unique [`.claude/rules/frontend.md`](../../.claude/rules/frontend.md).
- **Code review** : checklist obligatoire dans le template de PR.

---

## 4. Infrastructure Reuse

`docker/frontend/Dockerfile` (build multi-stage + Nginx) et `docker/frontend/nginx.conf`
(proxy `/api`, `/bundles/`, `/exports/` → backend, `/.well-known/mercure` → hub, en-têtes de
sécurité + CSP, SPA fallback) sont l'infrastructure de déploiement de la zone, distincte du code
source (`frontend/src/`) — **pas de `location /engine/`** (`nginx.conf:96-102`, frontière §2 de
`CLAUDE.md`).

---

## 5. Références croisées

| Document | Relation |
|----------|----------|
| `frontend-spec.md` | Spécification forward complète (routes, composants, stack) — ce document en est le complément stratégique. |
| `frontend-wizard.md` (T12) | Spécification du wizard d'onboarding — non dupliquée ici. |
| `backend-inventory.md` | Inventaire backward du backend — référencé par `frontend-spec.md`. |
| `openapi-snapshot.json` | Snapshot OpenAPI du backend — source de vérité pour les contrats API. |
| `AGENTS.md` | Contexte agent (commandes dev, architecture, gotchas). |
