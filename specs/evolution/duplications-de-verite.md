# Duplications de vérité — inventaire du 2026-08-08

> **Pourquoi ce fichier.** « Une vérité, deux endroits » est le motif le plus récurrent du dépôt :
> six éditions d'audit l'ont vu mordre sous des formes différentes (liste de tests dupliquée
> doc↔CI, `config.coachId` doublon du scope, famille de contraintes honorée par le moteur et
> créable par personne, stamps qui mentent). Le fondateur a demandé de le traiter **en
> profondeur** plutôt que cas par cas. Cet inventaire est le résultat : 3 agents de balayage
> (backend, frontend, cross-stack) + contre-vérification manuelle des cas graves.
>
> **Le critère n'est PAS « peut-on mutualiser ? »** — c'est **« si les deux copies divergent,
> est-ce bruyant ou silencieux ? »** Une divergence qui casse un test ou rend un 422 est
> tolérable : elle se signale. Une divergence qui change le comportement sans rien dire est le
> vrai danger. Seules les **silencieuses** sont listées comme chantiers.
>
> ⚠ **Toutes les duplications ne sont pas des défauts.** Voir §3 : certaines sont des frontières
> d'architecture voulues, et l'une d'elles (`JWT_COOKIE_SECURE`) est le modèle à imiter.

---

## 1. La doctrine — ce que le dépôt sait déjà faire

Le constat central de l'inventaire : **le dépôt sait déjà résoudre ce problème, il ne l'a pas
appliqué partout.** Quatre gardes exemplaires existent et fonctionnent :

| Garde | Ce qu'il tient |
|---|---|
| `TenantOwnedInterfaceCompletenessTest` | marqueur d'interface ⇄ colonne `club_id`, diff **bidirectionnel** |
| `RlsIsolationTest.php:39-46` | liste **dérivée de `pg_class`**, jamais recopiée (et `docs/security/rls.md:8` refuse explicitement de redonner un décompte) |
| `TeamTagScopeTest.php:202-221` | constante SQL `INSERT_COLUMNS` lue **par réflexion** et diffée contre les métadonnées Doctrine |
| `BlockingTestsListMatchesCiTest` | liste de `docs/testing/blocking-tests.md` (ex-`CLAUDE.md` §4, déménagée le 2026-08-27) ⇄ steps de `ci.yml`, **dans les deux sens** (ajouté le 2026-08-08) |

**La forme commune, à appliquer partout ailleurs :**

> **Ne pas synchroniser deux listes. Dériver la seconde de la première, et laisser un test faire
> le diff bidirectionnel.**

Corollaire observé sur presque tous les cas frontend : la source unique **existait déjà** et se
déclarait telle en commentaire (`reservationSlots.ts`, `activeLayer.ts` créé exprès par P2-15,
`teamTiers.ts` « THE canonical comparator », `clock.ts` et son override démo). Ce ne sont pas des
factorisations manquantes, ce sont des factorisations **contournées** — quelqu'un a réécrit la
règle à côté au lieu d'importer celle qui existait. C'est pourquoi le motif récidive : le problème
n'est pas l'absence de foyer unique, c'est que **rien n'empêche d'en ouvrir un second**.

---

## 2. Chantiers — silencieux, triés par gravité

Statut : ⬜ ouvert · ✅ traité (avec sa trace dans `../courantes/etat-des-lieux.md`).

### 2.1 Prioritaires — vérifiés à la main

| # | Sujet | Preuve | Ce qui se passe en silence | Geste |
|---|---|---|---|---|
| **D-01** ✅ | **Export RGPD incomplet** (art. 20) | `RgpdExportService.php:32-54` liste **21 tables en dur** ; 34 entités portent `TenantOwnedInterface` | **DÉJÀ DIVERGENT.** Absentes : `coach_wish`, `coach_wish_campaign`, `coach_wish_token`, `schedule_plan`, `solver_metrics`, `audit_log`, les 3 `*_period_override`, `team_tag_assignment`. `GET /api/club/export` rend **200, JSON valide, clé absente** — l'omission ne se voit pas. ⚠ `coach_wish` porte le **commentaire libre du coach** et ses indisponibilités (`Entity/CoachWish.php:66,79,82`) : donnée personnelle nominative non exportée. **Enjeu légal, pas technique.** | Dériver de `getAllMetadata()` filtré sur `TenantOwnedInterface` — la source est **déjà énumérable et déjà gardée** par `TenantOwnedInterfaceCompletenessTest`. Vérifier au passage le même trou côté purge |
| **D-02** ✅ | **Empreinte d'un match** (échauffement + jeu) | `MatchFootprint.php:27-28` = 30 + **105** (source, pilote le solveur) · `weekendGrid.ts:5-6` = 30 + 105 ✓ · `typicalWeekend.ts:11-12` = 30 + **135** ✗ | **DÉJÀ DIVERGENT.** Le « week-end type » dessine des blocs de **2h15** pour des matchs que le serveur traite comme **1h45** : le gestionnaire y lit des chevauchements et des saturations qui n'existent pas côté serveur. Le commentaire de `typicalWeekend.ts:7-8` affirme pourtant « same footprint geometry as the dated grid » — la doc et le code se contredisent dans le même fichier | Importer les constantes de `weekendGrid.ts` (déjà exportées), et faire dériver les deux du backend le jour où le contrat le permet. 2 lignes |
| **D-03** ✅ | **La porte du wizard compte autrement que son écran** | Source : `reservationSlots.ts:9` `slotKey` **normalisé** par `hhmm()` + `:38` `effectiveSlotCapacity` (gymnase inconnu ⇒ `capacity`) · Copie : `useStepValidation.ts:25` `slotKey` **non normalisé** + `:36` (gymnase inconnu ⇒ **1**) | **DÉJÀ DIVERGENT, deux fois.** Les réservations stockent `18:00`, les créneaux peuvent porter `18:00:00`/ISO — la source le documente (`:6-8`), la copie l'ignore : sa `Map` ne retrouve pas le créneau, `?? 1` s'applique, et l'étape Contraintes crie **« Créneau partagé par 2 équipes (max 1) »** pendant que le Récap annonce sereinement « 2 places ». C'est nommément le défaut « suivre les consommateurs » (review-response pt 2) que le commentaire de `useStepValidation.ts:176-180` prétend avoir corrigé | Supprimer les deux copies locales, importer `slotKey` + `effectiveSlotCapacity` |
| **D-04** ✅ | **A17 : le garde des en-têtes de sécurité ne tourne jamais** | `frontend/tests/e2e/security-headers.spec.ts:25,33` → `test.skip(isDevServer(baseURL))` ; la CI lance `E2E_BASE_URL=http://localhost:5173` (`ci.yml:480`), donc **toujours le dev server** | **Un garde qu'on croit avoir et qu'on n'a pas.** CSP, HSTS, X-Frame-Options, nosniff ne sont vérifiés sur **aucune** des deux confs nginx en CI. Retirer un en-tête de la prod seule passerait tous les checks. ⚑ **Le volet « deux copies » est CLOS le 2026-08-21 (P4-118)** : `nginx.prod.conf` était identique à `nginx.conf` aux commentaires près et a été supprimé — il n'y a plus qu'UNE conf, servie à l'identique par les e2e (`:8081`) et par la prod. C'est d'ailleurs ce qui rend ce garde-ci réparable : vérifier les en-têtes sur l'image nginx vérifie désormais, par construction, ce que les clubs reçoivent | Faire tourner ce spec contre l'image nginx (`:8081`) dans la CI, ou extraire les en-têtes en fragment partagé + tester le fragment |
| **D-05** ✅ | **TTL du verrou de génération** | `GenerateScheduleHandler.php:96` — `acquire(clubId, getTimeoutSeconds() + 60)` ; budget moteur = tier 600 + chaînage 10 (`engine/app/main.py:225,230-244`) | Le `+ 60` est un **littéral non dérivé**. S'il passe un jour sous le budget moteur, la clé Redis expire **pendant** que le worker est bloqué dans `EngineClient::solve()` → un 2ᵉ message acquiert le verrou → **deux solves concurrents sur le même club**, deux imports, dernier écrivain gagne. Le `release()` par token no-op correctement : **aucune exception, aucun log, aucun test rouge**. `ConcurrentGenerationTest` couvre la contention, **pas l'expiration** | Dériver le TTL du plafond moteur au lieu d'un littéral |

### 2.2 Backend — à traiter en lot

| # | Sujet | Preuve | En silence | Geste |
|---|---|---|---|---|
| **D-06** ✅ | `Assert\Choice` en dur vs enums | 12 DTO écrivent la liste à la main ; **5 utilisent déjà** `callback: [Enum::class, 'values']` (`FixtureInput:29,37`, `TeamInput:60`, `CompetitionInput:23`) | Enum élargi sans le DTO → une valeur légitime **rejetée en 422** : la capacité existe et est inatteignable, aucun test ne la couvre | Généraliser le `callback` (patron du dépôt) + **trait** `values()` — la méthode est aujourd'hui **recopiée 5 fois à l'identique**. Plus un test qui refuse un `Choice` en dur quand un enum porte exactement ces valeurs |
| **D-07** ✅ | `Season.status` : la seule string libre | `Entity/Season.php:44` `private string $status` ; valeurs en dur dans `SeasonInput.php:25` et `CoachWishSeasonGuard.php:36` | Tous les autres statuts du projet sont des enums (`ScheduleStatus`, `FixtureStatus`, `CalendarEntryStatus`). Un statut ajouté n'est verrouillé nulle part | Créer `SeasonStatus` et brancher les deux sites |
| **D-08** ✅ | Clés `config` portant un id d'entité, **3 listes manuscrites** | `SeasonTransitionService.php:42-48` `CONFIG_ID_KEYS` · `ScheduleConstraintBuilder.php:46` `VENUE_CONFIG_KEYS` (dont `setVenueId`, **clé fantôme** sans writer ni reader) · `ConstraintValidationService.php:79` | **DÉJÀ DIVERGENT.** `CONFIG_ID_KEYS` **oublie `forcedVenueId` et `minAtVenueId`** → une contrainte FACILITY permanente est recopiée en saison N+1 avec l'**uuid d'un gymnase mort**, `$configDangling` reste `false`, aucun skip. Elle liste encore `coachId`, supprimé depuis | Dériver du `SPEC` de `ConstraintConfigValidator` (clés de type `uuid`) — même correctif pour les trois |
| **D-09** ✅ | Familles structurelles (snapshot/restore) | `StructureSnapshotter.php:52-63` `FAMILIES` vs `StructureRestorer.php:46-57` + `:293-309` `wipeStructure()` — 3 énumérations, `:45` avoue « mirror of » | Famille présente au wipe mais absente du snapshot → `$data[$family] ?? []` rend `[]` **après** que `wipeStructure` a supprimé les lignes → **perte de données silencieuse** sur « Charger cette version » | Une classe `StructureFamilies` sur le modèle de `StructureAnchor.php:40` (match exhaustif qui throw) |
| **D-10** ✅ | Topic Mercure : 6 publishers, 1 sélecteur | `ScheduleProgressPublisher.php:31,72`, `ExportPdfHandler.php:57,92`, `ScheduleGenerationFailureListener.php:81`, `ReconcileStuckSchedulesCommand.php:168` vs `MercureAuthController.php:79` | Un publisher qui dérive → le hub ne matche plus → **la SSE meurt** → le front dégrade en polling **par conception** : l'UI marche, personne ne voit rien. `MercurePrivateUpdateTest.php:44` n'assert que le préfixe | Un `MercureTopic::for($clubId, $scheduleId)`. ⚑ Le **front est exemplaire** : il lit `topicTemplate` au runtime (`scheduleStream.ts:130`), zéro copie |
| **D-11** ⬜ | `matchDay` : convention de jour | `TeamInput.php:45` `Range(0,6)` « 0 = Monday », toujours en place — vs ISO 1..7 partout ailleurs (`VenueTrainingSlotInput.php:18`, `TeamMatchHabitInput.php:18` `Range(1,7)`, `MatchSlotRotationInput.php:25` `Range(1,7)`) et `objective.py:550` `match_day % 7 + 1` | **Le bug d'ÉMISSION est corrigé (RMM-5 PR-3, 2026-08-25)** : `ScheduleConstraintBuilder::deriveMatchDay` convertit désormais le champ déclaré 0-based en ISO (+1) avant de l'émettre en repli, et le chemin PRINCIPAL (habitudes/rotations, déjà ISO) le court-circuite. **Reste dormant** : `TeamInput.php` elle-même n'est pas alignée — un `matchDay` déclaré via l'API accepte encore 0-6 (0-based), jamais 7 (dimanche ISO rejeté en 422 si on tentait de le saisir en ISO). Sans effet observable aujourd'hui : aucun écran n'écrit ce champ, et même écrit, l'émission convertit désormais correctement | Aligner `TeamInput.php` sur ISO `Range(1,7)` avant d'exposer le champ à un écran — cosmétique tant que ça reste vrai |
| **D-12** ✅ | Rate limit prod jamais asserté | `rate_limiter.yaml:41` `limit: 300` ; `ApiRateLimitTest.php:27` ne garde que l'override de test (`:81`, 30) | Passer la valeur prod à 30000 → **tous les tests verts**, la borne SEC-11 s'évapore | Asserter la valeur prod, pas seulement l'override |
| **D-13** ✅ | Sémantique des contraintes implicites | `ImplicitConstraintConfig.php:40` « gets **at least** its minimum » vs `engine/implicit_rules.json:8` « **TARGETS** … soft bonus, **not a hard floor** » | **Inversé sémantiquement.** `POST /implicit-constraints` (`main.py:545-547`) ne compare que les **noms** — jamais les descriptions ni la version (`'2.1'` backend vs `"2.0"` json) : la commande de sync rend **`synchronized` sur une contradiction** | Comparer description + version. ⚠ anti-garde : `ImplicitConstraintConfigTest.php:69` épingle la mauvaise chaîne |
| **D-14** ✅ | Miroirs backend↔engine des règles coach | `CoachDoubleBookingDetector.php:282-309` (MAIN, gymnases **différents**, chevauchement d'intervalles) vs `result_builder.py:629-663` (clé `(coach, day, startTime)`, **début exact**) · fenêtres d'indispo : `:219-251` vs ``constraints/targeting.py` (`add_time_window_constraints`)` | **DIVERGENT dans les deux sens.** 17:00-18:30 vs 17:30-19:00 → le backend bloque en 422, le moteur ne voit rien. Même gymnase, 2 équipes, 1 coach → le backend autorise (mutualisation), le moteur émet une **ERROR**. Fenêtre inversée (20:00→08:00) → le moteur bloque **toute la journée**, le récap se tait | Le détecteur se déclare « miroir » (`:145-149`) sans garde : un test croisé backend⇄moteur |
| **D-15** ✅ (reformulé) | `MERCURE_PORT` | `.env:10` = **13009** vs `.env.dist:10` = **3000** et `vite.config.ts:51` défaut `127.0.0.1:3000` | **DÉJÀ DIVERGENT.** Un `npm run dev` hôte proxie vers un port mort → SSE morte → repli polling qui masque tout. La CI lit `.env.dist`, donc ne verra jamais la dérive | — |
| **D-16** ✅ | Colonnes d'INSERT manuscrites | `SchedulePlanProvisioner.php:783-785` (12 colonnes), `FfbbLeagueRepository.php:38`, `FfbbCommitteeRepository.php:35` | Nouvelle colonne **nullable** → les créneaux de période copiés la perdent en silence : la grille de période diverge de la grille saison qu'elle prétend copier | **Le patron existe** : `TeamTagService.php:40` + son diff bidirectionnel dans `TeamTagScopeTest.php:202-221` |
| **D-17** ✅ | `ContractSchemaTest` tautologique sur l'URL moteur | `EngineClient.php:17-18` vs `AdminHealthService.php:24`, `ExportImplicitConstraintsCommand.php:22` ; `ContractSchemaTest.php:39` compare l'URL **à sa propre constante** passée au `MockHttpClient` (`:49`) | Passer `EngineClient.php:17` en 8001 → **4 tests CrossStack verts**. Seul le smoke tombe. `ENGINE_URL` existe dans `.env.dist:16` et **n'est lu nulle part** | Un paramètre Symfony unique |
| **D-18** ✅ | Colonnes d'export XLSX | `SpreadsheetGenerator.php:25` `HEADERS` (7) vs tuples `:46-52`, `:59-63`, plage `'A1:G1'` `:75` | Insérer une colonne dans une seule branche → les lignes « fenêtre vide » s'affichent **sous les mauvais en-têtes**. Aucun test n'assert l'ordre | — |
| **D-19** ✅ | Statuts/sources `admin_job_run` | CHECK SQL `Version20260716120000.php:19` vs 4 services **+ `CustomRoutesOpenApiFactory.php:738-739`** | Le CHECK rattrape le code (bruyant), mais la copie **OpenAPI** dérive seule : la doc publique ment sur les valeurs | Créer `AdminJobStatus`/`AdminJobSource` |

### 2.3 Frontend — à traiter en lot

| # | Sujet | Preuve | En silence | Geste |
|---|---|---|---|---|
| **D-20** ✅ | `minutes → "HH:MM"` : clampé ou non | `weekendGrid.ts:9-14` (clampe modulo 1440) vs `planning/lib/grid.ts:46-50` et `wizard/lib/days.ts:24` (pas de clamp) | **DÉJÀ DIVERGENT.** Un dépassement de minuit s'affiche `01:15` côté matchs et **`25:15`** côté planning/wizard — l'incident nommément décrit dans `slotOverlap.ts:36-39`, corrigé par une garde de saisie, les formateurs restés discordants | Un `formatMinutes` clampé en `shared/lib/time.ts` |
| **D-21** ✅ | `"heure" → minutes` : **5 implémentations, 4 comportements d'échec** | `days.ts:18` → **NaN** · `grid.ts:40` → **0** · `envelope.ts:4` → **0** · `coachDoubleBooking.ts:30` → **null** · `typicalWeekend.ts:42` → **0**. Regex divergentes (`\d{2}` vs `\d{1,2}`) | Sur une heure illisible : le wizard **bloque la pose** (`slotOverlap.ts:83` s'appuie sur le NaN, commentaire explicite), le planning et les matchs la traitent comme **minuit** et posent le bloc en haut de grille sans un mot | Un `parseTime(): number \| null` partagé ; chaque appelant choisit son repli **explicitement** — ⚠ le NaN de `days.ts` est load-bearing, ne pas l'écraser en 0 |
| **D-22** ✅ | **Les 7 jours, déclarés 9 fois** | `wizard/lib/days.ts:2-10`, `planning/lib/grid.ts:18-26`, `PublicWishPage.tsx:13`, `useStepValidation.ts:9`, `matchAccess.ts:3`, `PeriodStructure.tsx:817`, `MatchWindowsEditor.tsx:9`, `HabitsLinksDialog.tsx:13`, `TypicalWeekendGrid.tsx:10` | Le précédent est écrit dans `grid.ts:9-16` : un de ces tableaux **s'arrêtait au samedi** pendant que le solveur plaçait des séances le dimanche — « un planning à six colonnes se donnait pour complet ». Corrigé sur le **troisième** miroir ; il en reste neuf | `shared/lib/days.ts` : `DAYS` + `DAY_LABEL_LONG`. Aucune logique à déplacer, que des littéraux |
| **D-23** ✅ | « Ce que la période contient » | `activeLayer.ts:23,34,39` (créé par P2-15 **précisément** pour être l'unique foyer) vs `useStepValidation.ts:182-185` (les 4 règles réécrites inline) et `PeriodStructure.tsx:908` | La **porte** compte avec sa copie, le **récap** avec `activeLayer`. Une règle ajoutée au foyer → le récap affiche « Gymnases 0 » et la porte reste verte, bouton « Générer » ouvert. C'est **exactement** le défaut #342 round 2 que `useStepValidation.ts:176-180` dit avoir corrigé | 4 appels à `activeLayer` (zéro dépendance React) |
| **D-24** ✅ | « Gymnase sans créneau ni fenêtre match » : **3 sites**, et le code le sait | `WizardLayout.tsx:168-169` écrit noir sur blanc « la règle vit à TROIS sites, ils bougent ensemble » : `useStepValidation.ts:71-74` (porte), `VenuesStep.tsx:330` (bandeau), `WizardLayout.tsx:200` (atterrissage) | Les libellés divergent déjà. Une exemption ajoutée à un seul site → soit bandeau rouge sans blocage, soit « Suivant » bloqué **sans aucun message** : l'écran ne montre rien à corriger | Exporter le prédicat, garder les 3 libellés |
| **D-25** ✅ | Clés react-query en collision / triplement | `planning/queries.ts:60,64,77,81` → `["teams"]`,`["venues"]`,`["coaches"]`,`["categories"]` ; `matches/queries.ts:28,36,40,44` → **les mêmes** ; `wizard/queries.ts:20` → **3ᵉ cache** `["wizard","teams"]` | (a) Une seule entrée de cache pour deux `queryFn` : marche par accident, le jour où l'une ajoute un filtre l'écran monté en second reçoit **silencieusement** les données de l'autre. (b) `wizard` n'invalide que `["wizard",…]` : après ajout d'un gymnase, l'écran Matchs ne le propose pas pendant **5 min** (`staleTime`), une équipe renommée garde son ancien nom | (b) invalidation croisée d'abord ; (a) supprimer les fetchers en double |
| **D-26** ✅ | Comparateur canonique d'équipes | `shared/lib/teamTiers.ts:52` (« THE canonical team comparator ») vs `wizard/lib/ranking.ts:15,24` (formule recopiée) | `TeamsStep.tsx:405` numérote avec `orderedTeams` et `:418` affiche avec `groupTeamsByTier` : une équipe au `priorityTierId` inconnu part dans « Autres » en bas **en gardant son n° 3** — la colonne se lit 1, 2, 4, 5… 3. Bonus : `ranking.ts:28` `usedTiers` n'a plus aucun appelant | Numéroter sur `groupTeamsByTier` (comme `RecapStep.tsx:157`) ; supprimer le mort |
| **D-27** ✅ | `assignLanes` (placement en couloirs) | `planning/lib/grid.ts:245-286` vs `matches/lib/weekendGrid.ts:105-143` — **copie caractère pour caractère** (mêmes noms de variables, même `flush`, même `clusterEnd = -1`) | Corriger un défaut de chevauchement sur une grille laisse l'autre : deux matchs se recouvrent sur un écran et se rangent côte à côte sur l'autre | `shared/lib/gridLayout.ts` générique sur `{startMin,endMin,cell}` |
| **D-28** ✅ | « Le socle est-il validé ? » | `cockpit/lib/socle.ts:9` (« Source UNIQUE … évite de retripler la dérivation ») vs ré-inliné dans `AppLayout.tsx:62`, `CockpitPage.tsx:59`, `MatchesPage.tsx:167`, `PlanningPage.tsx:98`, `ClubPage.tsx:397` | Elle **est** retriplée, hors du cockpit. Si la notion change, l'entrée de menu s'ouvre pendant que la page Matchs affiche « verrouillé » : un lien qui mène à un cadenas | Remonter le hook en `shared/` |
| **D-29** ✅ | « Aujourd'hui » | `shared/lib/clock.ts:21,66` (point de passage unique, override `demoToday` **actif en prod**) vs `app/seasonTransition.ts:10` `localIso` (copie) et `SeasonSelector.tsx:24` / `SeasonTransitionBanner.tsx:17` (`new Date()`, montés sans prop) | Sur un **club démo**, le cockpit vit à la date simulée pendant que la bannière « Préparer la saison suivante » lit l'horloge réelle : le nudge apparaît au mauvais moment, sans rien pour l'expliquer | Importer `toISODate`/`todayISO`, garder le paramètre pour les tests |
| **D-30** ⛔ **réfuté** (consolidé quand même) | Jour ISO d'une date : **3 stratégies de fuseau** | `envelope.ts:13` (`T00:00:00` local + `getDay()`) · `matchAccess.ts:6` (`T12:00:00Z` + `getUTCDay()`, commentaire « UTC noon so no TZ edge flips the day ») · `RadarPanel.tsx:347` (`T00:00:00Z` + `getUTCDay()`) | Deux d'entre elles sont dans la **même feature**. Alignées pour un navigateur français ; hors fuseau, `matchAccess` dit « pas d'accès le vendredi » sur un match qu'`envelope` valide comme samedi | Garder `isoDayOf` (le plus défensif), supprimer les deux autres |
| **D-31** ✅ | `IN_FLIGHT = [PENDING, GENERATING]` | `planning/queries.ts:13` vs `PlanningPage.tsx:36`, `lib/versions.ts:27`, `GenerateStep.tsx:22`, négation inline `scheduleStream.ts:52` | Un statut non terminal ajouté → certains écrans arrêtent de poller ou réactivent les boutons **en pleine génération**, d'autres non | — |
| **D-32** ✅ | Rôles de management exposés au front | `ClubUserRepository.php:22` `MANAGEMENT_ROLES` (mono-source côté backend ✓) vs `SeasonTransitionBanner.tsx:25` et `ClubPage.tsx:481` (réécrits inline) | Un rôle ajouté côté backend → les deux écrans continuent de **masquer** une capacité que le serveur autorise : la fonctionnalité existe et reste invisible, sans erreur | Exposer les rôles via l'API |
| **D-33** ✅ | Nom affiché d'un coach | `ranking.ts:50` (avec `.trim()`) vs `grid.ts:122` et `ConflictRadar.tsx:18` (**sans**), 3 replis différents (`"Coach ?"` / `"Coach"` / `null`) | Un coach sans nom de famille s'affiche `Emerick` dans le wizard et `Emerick ` (espace final, visible en badge) sur le planning | `shared/lib/coachName.ts` |
| **D-34** ✅ (garde posé) | Unions de valeurs recopiées entre features | `TeamLevel` : union stricte dans `wizard/api.ts:20`, **`level: string`** dans `matches/api.ts:191` · `ScheduleStatus` et `SchedulePlanType` déclarés deux fois · `matches.Team` **n'a pas `isActive`** | Un niveau ajouté côté serveur passe **muet** côté matchs ; l'écran Matchs ne peut pas filtrer les équipes inactives et rien ne le lui rappelle | Mettre les **unions** dans `shared/api/types.ts`. ⚠ Les projections **structurelles** par feature restent légitimes (voir §3) |
| **D-35** ✅ | Tranche d'âge → tag : règle vs libellés vs noms semés | Règle : `TeamTagService.php:321-332` + `CategoryCatalog.php:37-48` · Libellés : `tagLabels.ts:24,29,34` · Noms semés : `DefaultConstraintSeeder.php:51-54` | **S'est déjà produit deux fois**, documenté dans le code : `tagLabels.ts:19-23` « « Jeune (U13-U21) » MENTAIT jusqu'à P4-63 », `:26-29` « « EMB (U9-U11) » MENTAIT jusqu'à P4-42 ». Un dirigeant pose une règle HARD « Jeune » en croyant couvrir U21 : elle ne couvre pas. `TeamTagScopeTest` épingle la **règle**, rien ne la lie aux libellés | Dériver les libellés de la règle, ou les diffé par test |

### 2.4 Cross-stack — la frontière la moins gardée

> **Fait structurel qui décide de tout côté engine** : `input_schema.py:27` pose `extra="forbid"`
> sur les schémas d'enveloppe (venues/teams/coaches/slots) → renommer une clé = **422 bruyant**.
> Mais `:107` pose `extra="ignore"` sur `ConstraintV2Schema`, et `config` y est un
> `dict[str, object]` non validé (`:116`) : **tout le domaine des contraintes est silencieux par
> construction** — exactement là où vit la valeur métier.
>
> **Et le constat le plus lourd** : *aucun test ne traverse la frontière backend↔frontend*
> (vérifié dans les deux sens — aucun fichier de `backend/tests` ne lit `frontend/src`, aucun
> `.ts` ne lit `backend/src`). La frontière backend↔engine a **trois** gardes sémantiques réels ;
> la frontière backend↔frontend n'en a **aucun**.

| # | Sujet | Preuve | En silence | Geste |
|---|---|---|---|---|
| **D-36** ✅ | **Capacité 3 offerte à l'écran, refusée par l'API** | `slotFields.tsx:23` propose « 3 équipes (terrain divisé en 3) » · `VenueTrainingSlotInput.php:33` `Range(min:1, max:2)` — **seul** chemin d'écriture (vérifié : aucun autre DTO ne porte `capacity`) | **BUG LIVE, EN PRODUCTION.** Choisir « 3 » rend **422**. `frontend-wizard.md` affirme pourtant « 3 depuis le 2026-08-05 … toute la chaîne aval — backend, engine `ge=1`, récap, réservations — était déjà générique sur `capacity` » : la chaîne **aval** l'était, la **porte d'entrée** non. Le cas ADN nommément cité (terrain divisible en 3) est infaisable | Passer le `Range` à 3 + test. **Le geste le plus rentable de tout l'inventaire** |
| **D-37** ✅ | **`CONTRACT_VERSION` : 4 docs disent 2.1, le fichier dit 2.2** | `engine/CONTRACT_VERSION` = **2.2** · `engine/README.md:46`, `engine/docs/nominal-flow.md:7,9,128`, `docs/glossary.md:64`, `docs/project-map.md:136` disent **2.1** · côté code, `'2.2'` est écrit **deux fois** (`ScheduleConstraintBuilder.php:48`, `MatchPlacementPayloadBuilder.php:48`) et deux copies sont **périmées à 2.0** (`engine/app/core/config.py:17`, `input_schema.py:149`) | `main.py:509` ne compare que le **MAJEUR** : 2.0, 2.1, 2.9 passent tous. Le `.2` est décoratif — oublier de bumper une constante PHP ne produit rien. ⚑ **Le garde existe mais est trop étroit** : `test_contract_version_doc_sync.py` ne surveille qu'`engine-inventory.md`. Les 4 autres docs ont dérivé sous son nez — et `project-map.md` a reçu un stamp « vérifié » le 2026-08-08 sans que ce mensonge soit vu | Étendre le garde à **tout doc citant une version de contrat** (glob + regex), corriger les 4 |
| **D-38** ⛔ **réfuté** | **`LockLevel.SOFT` existe côté PHP, pas côté TS** | `Enum/LockLevel.php:10` a `SOFT` ; `planning/api.ts:56` ne l'a pas. Or `ScheduleResultImporter.php:129` fait `LockLevel::tryFrom()` sur la **réponse engine** → `SOFT` peut atterrir en base | `PlanningPage.tsx:645` bascule alors un `SOFT` vers `HARD`/`NONE` **sans le dire**. Cas déjà en écart, pas hypothétique | Fait partie du lot « unions partagées » (D-34) |
| **D-39** ✅ | **`X-Season-Rejected` : un producteur, un consommateur, zéro test** | `TenantFilterListener.php:157` (seul producteur) ⇄ `client.ts:51` (seul consommateur) | Le backend le renomme → le front perd son auto-réparation sur saison périmée. Or le backend 403 **toute** requête portant la saison morte, `/api/me` compris : **boucle 403 définitive**, l'app ne récupère jamais | Un test de contrat de chaque côté (trivial) |
| **D-40** ✅ | **Pivot de saison au 15 juillet** | `SeasonResolver.php:31` `TRANSITION_MONTH_DAY = '07-15'` ⇄ `app/seasonTransition.ts:4` `iso.slice(5,10) >= "07-15"` en dur | Le front et le serveur ne sont plus dans la même saison : le bandeau et le sélecteur pointent une saison, l'API en sert une autre | Servir le pivot, ou test de parité |
| **D-41** ✅ | **Codes `type` des diagnostics** — 3 zones, aucun enum | `result_builder.py` (émission) + `output_schema.py:36` (**un commentaire**, pas une contrainte) ⇄ `DiagnosticMessageBuilder.php:44-48` (`match`) ⇄ `DiagnosticsPanel.tsx:99` | Un renommage côté engine → le `match` PHP tombe en `default` : **« Diagnostic inconnu. »** affiché au gestionnaire, et le surlignage front cesse en silence | Typer `type` en `Literal[...]` côté engine |
| **D-42** ✅ | **Paliers solveur (60/180/600, seuils 50/200, 8 workers, cap 10 s)** | `engine/app/main.py:227,239-244,259` (le seul code) ⇄ **7 documents** : `CLAUDE.md:65`, `adr-0001:26,85`, `project-map.md:133`, `glossary.md:67`, `generation-flow.md:233,359`, `schedule-generation-guide.md:346` | Les 7 vieillissent ensemble ; un plan d'agent part sur des chiffres faux — le motif exact que `test_contract_version_doc_sync.py` combat, sur un seul doc | Même garde généralisé que D-37 |
| **D-43** ✅ | **Règle implicite jamais vérifiée** | `ImplicitConstraintConfig.php` ⇄ `engine/implicit_rules.json` — le contrôle 409 existe (`main.py:542-565`) mais n'est atteignable que par `app:constraint:export-implicit`, présent dans **aucun job CI ni cible Makefile** | Le garde **ne se déclenche jamais**, et il ne compare que les `name` (ni description, ni version) | Brancher la commande en CI + comparer description et version |
| **D-44** ⛔ **réfuté (partiellement)** | Sous-schémas de payload prétendument non couverts | `ContractSchemaTest.php:86-92` | **Vérifié le 2026-08-08 : les deux sous-schémas SONT couverts ailleurs.** `slotTemplates` par `ScheduleConstraintBuilderTest:260`, `ScheduleConstraintBuilderOverlayTest:172,587` ; `trainingSlots` par `ScheduleConstraintBuilderOverlayTest:710,790` (**blocking**), `OverlayGenerationTest:117`, `PeriodPlanBirthTest:445`, `CapacityMirrorParityTest`. Ce qui restait vrai : la branche `if` de `ContractSchemaTest` ne s'exécute **jamais** (le fixture bâtit le builder sans repository, donc sans créneau) et donnait l'illusion d'une couverture que ce test n'apporte pas. | non applicable | Branche conservée (elle documente la FORME d'un créneau) mais son inutilité **écrite**, avec le renvoi vers les tests qui gardent réellement le contenu |

> **Où se concentre le silence** — trois poches, pas une dispersion : (1) le domaine des contraintes
> côté engine (`extra="ignore"` + `config` libre), (2) les **149 types TS** écrits à la main sans
> aucune validation runtime (`client.ts` ne valide rien, `collection.ts` fait un `as T[]`, aucun
> zod/valibot au `package.json`), (3) les conventions numériques non bornées (`matchDay`, jours ISO).
> Le reste du contrat est déjà tenu par `extra="forbid"`.

---

## 3. Duplications DÉLIBÉRÉES — ne pas mutualiser

| Duplication | Pourquoi c'est assumé | Garde |
|---|---|---|
| **Contrat backend↔engine** (Pydantic ⇄ payload PHP) | Frontière de zone : le codegen créerait un couplage de build entre deux runtimes. Décision documentée (CLAUDE.md §6, « No codegen — synced manually ») | `ContractSchemaTest` + `MatchPlacementContractSchemaTest`. ⚠ **trou** : `main.py:509` ne compare que le **MAJEUR** (2.2 → 2.5 passe en silence), et `'2.2'` est écrit **deux fois** côté backend, plus deux copies **périmées à 2.0** (`engine/app/core/config.py:17`, `engine/.env:4`) |
| **Caps DoS backend vs engine** | Défense en profondeur voulue : le backend refuse tôt avec un message clair, le moteur se protège de tout appelant. Asymétrie **documentée** (`GenerationComplexityGuard.php:22-25`, `input_schema.py:16-18`) | Chaque côté a son test ; pas de test de parité — acceptable, la divergence est bruyante (422) |
| **`JWT_COOKIE_SECURE`** (5 copies) | La séparation par fichier **EST** le mécanisme de sécurité : `backend/.env` entre dans l'image de prod, donc le `false` de dev doit vivre ailleurs | ⭐ **Le modèle du dépôt** : défaut fail-closed `'true'`, bloc de commentaire expliquant l'omission (`backend/.env:51-58`), et `JwtCookieSecureDefaultTest` qui assert l'**absence** de la variable |
| **Liste des blocking-tests doc ⇄ CI** | Deux publics différents | ✅ Résolu le 2026-08-08 par `BlockingTestsListMatchesCiTest` (diff bidirectionnel) |
| **Projections structurelles par feature** (`planning.Team` à 4 champs) | Une interface étroite **documente** ce dont l'écran dépend. Ce qui doit être partagé, ce sont les **unions de valeurs** (D-34), pas les formes | — |
| **`coachDoubleBooking.ts` front ⇄ `CoachDoubleBookingDetector` back** | La modale doit répondre sans aller-retour réseau. Documenté (`coachDoubleBooking.ts:7-14`) | Cas de test identiques des deux côtés — mais voir D-14 : le miroir **moteur** diverge, lui |
| **`docker-compose.yml` vs `.prod.yml`** (Mercure `latest` vs `v0.19`) | Divergence voulue et commentée (`docker-compose.prod.yml:318-319`) | — |

---

## 4. Découpage proposé

| Lot | Contenu | Pourquoi ensemble |
|---|---|---|
| **0 — le bug du jour** ✅ | ~~D-36~~ (livré le 2026-08-08) | Une ligne. Un geste que le gestionnaire tente et qui échoue en 422 depuis le 2026-08-05 |
| **1 — RGPD** ✅ | ~~D-01~~ (livré le 2026-08-08) | Enjeu légal, isolé, source déjà énumérable et déjà gardée |
| **2 — divergences front live** ✅ | ~~D-02, D-03, D-20~~ (livrés le 2026-08-08) | Trois bugs visibles par le gestionnaire, chacun avec son test |
| **3 — gardes fantômes** ✅ | ~~D-04, D-12, D-17, D-37, D-43~~ (livrés le 2026-08-08) · D-44 **réfuté** (couvert ailleurs, mesuré) | Six gardes qu'on **croit** avoir : tant qu'ils ne sont pas réparés, on continue de s'y fier à tort. C'est le lot qui rend les autres fiables |
| **4 — enums backend** 🟡 | ~~D-06~~ (livré le 2026-08-08) · **restent D-07, D-19** Même geste (dériver de l'enum), un seul garde à écrire |
| **5 — listes manuscrites backend** ✅ | ~~D-05, D-08, D-09, D-16~~ (livrés le 2026-08-08) Même patron (`INSERT_COLUMNS` + diff par réflexion) |
| **6 — frontière backend↔front** ✅ | ~~D-34, D-39, D-40, D-41~~ (livrés le 2026-08-08) · D-38 **réfuté** La frontière **sans aucun garde** : à traiter comme un tout, en commençant par exposer les unions |
| **7 — consolidation front** ✅ | ~~D-22, D-23, D-29, D-31~~ (livrés le 2026-08-08) · **lot SOLDÉ** Mécanique, sans risque, à faire d'un bloc |
| **8 — miroirs de règles** | D-11, D-13, D-14, D-42 | Demandent une décision produit avant du code (quelle règle fait foi ?) |

> **Ordre conseillé** : 0 (bug live, une ligne) → 1 (légal) → 2 (visible du gestionnaire) → 3 (on
> se croit protégé et on ne l'est pas) → 4 → 5 → 6 → 7 → 8.

> ⚠ **Ce découpage ne couvrait pas les 44 findings, et je l'ai cru clos à tort le 2026-08-08.**
> Les huit lots sont soldés — mais **dix findings n'appartenaient à aucun lot** et restent
> ouverts (voir ⬜ ci-dessus). L'erreur est instructive et vaut d'être gardée : un plan de
> découpage se vérifie **contre l'inventaire**, pas contre lui-même. Le compte fait foi :
> `grep -c '^| \*\*D-[0-9]*\*\* ⬜' specs/evolution/duplications-de-verite.md` — **ancré sur la ligne
> de tableau** : un `grep -c '⬜'` nu compte aussi la légende et cette prose (6 au lieu de 3).
>
> **État au 2026-08-09 : 44 findings — 39 livrés · 4 réfutés · 1 ouvert.**
>
> **Les trois restants, et pourquoi ils attendent.** Aucun ne peut corrompre une donnée,
> franchir une frontière ni tromper un gestionnaire sur un flux critique — c'est ce qui les a
> mis en fin de file, et cela reste vrai.
>
> **D-14 a été tranché le 2026-08-09**, et l'arbitrage a désigné le coupable inverse de
> celui qu'on croyait. Le premier compte rendu concluait « un coach ne couvre jamais deux
> équipes, il faut durcir le backend » ; le fondateur a corrigé : « Matthieu coache les SM1
> et les SM2, on peut vouloir que les deux entraînements se passent en même temps. C'est de
> la responsabilité du gestionnaire, ce n'est pas une erreur. » Le backend et la modale
> avaient donc raison ; c'est le MOTEUR qui refusait ce que l'UI offrait.
>
> **D-07 a été tranché le 2026-08-09** (fondateur : « dans un enum ») — colonne typée
> `enumType`, comme `CalendarEntry`. À noter : **aucun test nouveau n'a été écrit**. Le garde
> de **D-06** (`EnumChoicesAreDerivedTest`) couvrait déjà le cas et l'a attrapé dès que l'enum
> a existé — falsifié : « SeasonInput::$status recopie SeasonStatus ». Un garde qui rend son
> service sur un finding écrit deux jours plus tard.
>
> | # | Sujet | Pourquoi il attend |
> |---|---|---|
> | D-11 | convention `matchDay` | **dormant** : `match_day` est NULL sur les 69 équipes, aucun écran ne l'expose |
>
> **D-13 est clos sans code** : le correctif de D-43 l'avait absorbé. `ImplicitRulesMatchEngineTest`
> compare désormais les descriptions ET les versions annoncées de part et d'autre
> (`ImplicitRulesMatchEngineTest.php:37,54`), les deux côtés disent « TARGETS … not a hard floor »
> et annoncent `2.1`, et l'anti-garde qui épinglait « gets at least » a été retourné
> (`ImplicitConstraintConfigTest.php:75-76`). Il ne restait qu'une ligne d'inventaire.
>
> **D-35 a été traité le 2026-08-08** (c'était le plus rentable : le seul qui avait déjà trompé
> un gestionnaire). Les autres n'ont, eux, jamais produit d'effet observé.

> **La leçon de méthode, pour la prochaine fois.** Sur les trois rapports d'agents, **quatre
> constats « graves » se sont révélés faux ou surévalués** à la contre-vérification :
> `matchDay` est dormant (0 donnée, aucun écran), `CoachWishSeasonGuard` combine bien ses deux
> règles (son docblock raconte la divergence qu'il a déjà corrigée), `BCK-12` de l'audit est
> inatteignable (`Assert\Choice` couvre les trois champs), et l'empreinte de match n'était pas
> celle que l'agent désignait comme fautive. **Aucun finding de ce fichier ne vaut sans sa
> vérification** — c'est la règle §7 étape 0, et elle s'applique d'abord aux rapports qu'on
> reçoit.
