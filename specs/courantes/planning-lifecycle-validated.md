# Cycle de vie des plannings — le pointeur du plan (N3)

Last verified @ 2026-08-31 (rotation de fraîcheur `documentation-update`, sans rapport avec le sujet
de la PR (P2-51 PR-2) — **drift trouvé et corrigé** : §4 et le stamp précédent affirmaient encore
`planning/lib/pickLandingSchedule.ts` en dette ouverte `P4-147` (copie littérale de `IN_FLIGHT`) ;
`P4-147` a été SOLDÉE le 2026-08-29 (`etat-des-lieux.md` §3, trace du jour même) et le fichier
importe désormais `IN_FLIGHT_STATUSES` depuis `shared/lib/scheduleStatus.ts`
(`pickLandingSchedule.ts:1,28`) — le stamp précédent citait la dette comme encore ouverte alors
qu'elle avait fermé le jour même, jamais recalé depuis. Reconfirmé sans écart : `onValidate` câblé
sur `useValidateSchedule()` (`PlanningToolbar.tsx:74,194`) ✓ · badge pastille
`STATUS_LABELS[selected.status]` (`PlanningToolbar.tsx:179`) ✓ · `SocleGuard::assertSeasonPlanChosen`
présent (`SocleGuard.php:26`) ✓. Historique des passes vit dans git :
`git log -p --follow specs/courantes/planning-lifecycle-validated.md`)

> **Bascule 2026-07-16 (ADR-0002, `docs/architecture/adr-0002-pattern-plan.md`)** : le **plan de
> type SEASON** (`schedule_plan`) et **la version qu'il pointe** (`chosen_schedule_id`) SONT le
> calendrier de la saison. **« Validé » n'est plus un statut** : ça se dérive du pointeur, et de
> rien d'autre — une version choisie reste `COMPLETED`. **Valider = POINTER + SUPPRIMER les
> versions sœurs** (inv. 1, plus d'archivage) ; **rouvrir = DÉPOINTER** (inv. 2, la version
> survit). Les statuts `VALIDATED`/`ARCHIVED`, `Season.baselineScheduleId`,
> `Season.socleValidatedAt`, `Season.planningName` et la route `set-baseline` **n'existent plus**.
> Les sections ci-dessous ont été corrigées ; ce qui décrit encore l'ancien modèle est daté et
> signalé comme tel.

> **But** : permettre au gestionnaire de **choisir** la version qui fait foi pour la saison, de la **rouvrir** pour l'éditer, et distinguer le **« Planning de la saison »** (le plan SEASON pointé) des **plannings secondaires** (les plans de période).
> **Source de vérité** : le **code** (confronté au besoin exprimé). La roadmap n'est qu'une base de discussion.

## 0. Machine à états accueil/onboarding (cockpit) — 2026-07-08

Le point d'entrée après login dérive de **deux critères portés par le plan SEASON**, exposés ensemble par `/api/me` dans `seasonPlan` : le plan **porte-t-il au moins une version terminée** (`hasFinishedVersion`, ADR-0002 inv. 8/16) et **pointe-t-il une version** (`chosenScheduleId`, inv. 13). Le flag legacy `club.onboardingCompleted` **n'est plus lu pour le routage**.

Les deux critères sont **indépendants** : `hasFinishedVersion` est dérivé des versions du plan et jamais retiré par un dépointage — **rouvrir ne re-verrouille donc pas le cockpit** et ne renvoie personne à l'onboarding.

| État | Condition | Landing | « Ouvrir » (carte planning principal) | Restrictions |
|---|---|---|---|---|
| **1. Jamais généré** | `hasFinishedVersion = false` | **/wizard** — étape **Récap** (ou 1ʳᵉ étape incomplète) | — | app verrouillée au wizard (`AuthGuard`, burger profil/club autorisé) |
| **2. A généré, rien de choisi** | `hasFinishedVersion = true`, `chosenScheduleId = null` | **cockpit** (déverrouillé) + bandeau « valider pour débloquer » | → **/wizard** étape **Génération** | **matchs** + **plannings secondaires** bloqués (front désactivé + `SocleGuard` 409) |
| **3. Une version choisie** | `chosenScheduleId ≠ null` | **cockpit** (tout ouvert) | → /planning | aucune |

Ancrages : `AuthGuard.tsx` (onboarding = `!seasonPlan.hasFinishedVersion`), `CockpitPage.tsx` (idem → /wizard), `WizardLayout.tsx` (guided = `!hasFinishedVersion`, landing Récap), `SeasonPlanBanner.tsx` (« Ouvrir » état 2→wizard génération), `AppLayout.tsx` + `MatchesLayout.tsx` (matchs verrouillés tant que `chosenScheduleId` est null — le garde a quitté `MatchesPage.tsx` pour `MatchesLayout.tsx` en RMM-1 PR2, 2026-08-23, pour couvrir les deux routes `/matchs` et `/matchs/configuration`), `App\Service\SocleGuard::assertSeasonPlanChosen` (409 création match/import/overlay tant qu'aucune version n'est pointée, appliqué dans `FixtureStateProcessor`, `ImportFixturesController`, `ScheduleStateProcessor` et `GenerateScheduleController`). Fixtures (P5-17, 2026-08-17) : le club BCCL **dev** est seedé en **état 3** — `BcclSeeder` transcrit le planning réel du club (90 créneaux) dans une version `COMPLETED` et pointe le plan SEASON dessus (`linkSchedule` puis `choose`) ; le club de **DÉMONSTRATION** reste seedé en **état 1** (données saisies, aucune génération — drapeau `BcclSeedProfile::transcribeRealSchedule` à `false`).

## 1. Modèle produit (validé avec le gestionnaire)

- **Valider = POINTER** (inv. 1) : le plan nomme la version qui fait foi (`schedule_plan.chosen_schedule_id`). **« Validé » n'est pas un statut** — ça se dérive du pointeur, et de rien d'autre ; la version choisie reste `COMPLETED`. Un plan pointe **au plus une** version.
- **Valider SUPPRIME les versions sœurs** du même périmètre (inv. 1) : le plan porte la version qui compte, pas un cimetière. Plus d'archivage, plus de filet. Une sœur encore en génération bloque le choix (409).
- **Rouvrir = DÉPOINTER** (inv. 2) : le plan redevient un **espace de travail** et la version **survit**, éditable, puis re-choisissable.
- **Aucun pointage automatique** (inv. 2) : générer ne pointe **jamais**. Seul le gestionnaire choisit.
- **« Planning de la saison »** = le plan **SEASON** et sa version choisie. Les **plans de période** (`CLOSURE`/`HOLIDAY`, plans d'exception bornés du cockpit) = **plannings secondaires** ; chacun porte **son propre** pointeur, sur son propre plan.
- **Invariant (UX-02)** : le pointeur du plan SEASON ne peut nommer qu'une version de saison (`calendarEntryId` null) — un overlay de période appartient au plan de sa période, pas au plan SEASON. Côté front, la sélection d'atterrissage (`pickLandingScheduleId`) ignore une version choisie qui serait un overlay ou en vol → jamais d'ouverture sur un « ★ · période » vide.
- Le gestionnaire consulte **tous** les plannings de l'année ; il édite ceux que leur plan ne pointe pas.

### Hors scope (reporté, raison technique)
- **Cascade « éditer le calendrier de la saison ⇒ répercuter sur les plannings secondaires »** : suppose que les secondaires dérivent du calendrier de base (modèle **templates → occurrences**, roadmap §2, **absent**). Impossible proprement aujourd'hui → différé, documenté. En attendant, déplacer ou retirer le pointeur du plan SEASON **détruit** les plans secondaires, sur confirmation explicite (inv. 14, §6).

## 2. État du code au moment de la rédaction (ancrages)

> *Instantané daté d'avant la PR-3, conservé pour la traçabilité — **périmé par la bascule ADR-0002 (2026-07-16)**. Le `/validate` qui pose le baseline, l'auto-assignation `assignBaselineIfFirst` et `set-baseline` n'existent plus ; voir §0/§1 et §3 pour le modèle en vigueur.*

| Élément | Réel |
|---------|------|
| `ScheduleStatus` | `DRAFT/PENDING/GENERATING/COMPLETED/FAILED` (`Enum/ScheduleStatus.php:9-13`) — **pas de VALIDATED** |
| `Schedule` | `name` (`:40`, len 180), `status` (`enumType`, `:42-43`), `version` (optimistic `:24`), `seasonId` (`:36`) |
| `/validate` actuel | `POST /api/schedules/{id}/validate` → **pose le baseline** (`season.setBaselineScheduleId`, `ValidateScheduleController.php:57`), garde `status==COMPLETED` sinon 409 (`:48`), garde tenant `resolveCurrentClubId` + 403 (`:43-46`, pattern `:63-78`) |
| baseline auto | `assignBaselineIfFirst()` au 1er succès si `baselineScheduleId` null (`GenerateScheduleHandler.php:211-216`) |
| Chemins d'édition **sans garde de statut** | `GenerateScheduleController` (regen `:46`) · `ManualEditController` (constraint, lock, move) · `ScheduleStateProcessor` PUT (`name/status/solverSeed` `:48-61`) — `schedule_slot_templates` est **read-only depuis 2026-08-16** (P4-102), plus de CRUD brut à garder ici |
| `ScheduleInput.status` | `Choice` = 5 statuts actuels (`Dto/ScheduleInput.php:17-19`) |
| Contrat engine | `ScheduleInputSchema` **sans status** ; output engine = littéral `queued/generating/completed/failed` ≠ enum backend ; `ContractSchemaTest` le vérifie → **VALIDATED n'impacte pas l'engine** |
| Front (instantané d'origine — **corrigé le 2026-08-29, plusieurs points avaient dérivé silencieusement**) | ⚠ **Faux, corrigé** : `validateSchedule()`+`useValidateSchedule()` ne sont plus « inutilisés » — le hook est appelé depuis `PlanningPage.tsx:298` et câblé au bouton « Valider » de `PlanningToolbar` (`onValidate`, §3.1). ⚠ **Faux, corrigé** : le badge de statut n'est plus un texte brut — pastille arrondie + icône verrou (`PlanningToolbar.tsx:177-180`, `STATUS_LABELS[selected.status]`) ; et il n'y a plus de paire Base/Secondaire — une seule pastille « Période » apparaît quand la version n'est PAS de saison (`:182`), son absence dit « saison ». **Toujours vrai, référence déplacée** : `pickDefaultSchedule` (désormais `planning/lib/pickLandingSchedule.ts`) ne matche que `COMPLETED`. **Toujours vrai, dette resorbée** : `IN_FLIGHT` a rejoint le foyer unique `shared/lib/scheduleStatus.ts` (D-31, 2026-08-08) **partout, y compris `planning/lib/pickLandingSchedule.ts`** — dernière copie littérale corrigée le jour même (`P4-147` SOLDÉE, `etat-des-lieux.md` §3). **Toujours vrai** : `SlotDetail`/`WeekGrid` restent sans conscience DIRECTE du statut, ils reçoivent un booléen `readOnly` déjà calculé par l'appelant |

## 3. Décisions de conception

### 3.1 Les actions et leur effet (ADR-0002)

| Action | Endpoint | Effet | Garde |
|--------|----------|-------|-------|
| **Créer une version de saison** | `POST /api/schedules` (sans `schedulePlanId`, ou avec le plan SEASON explicite) | prépare une nouvelle version sous le plan SEASON | **409 si le plan SEASON pointe déjà une version choisie** (P2-7, 2026-07-30) — « le planning de la saison est en vigueur, rouvrez-le avant d'en préparer un autre » ; le seul geste qui rouvre l'espace de travail est **Rouvrir** |
| **Valider (choisir)** | `POST /api/schedules/{id}/validate` | le **plan POINTE** cette version (`chosen_schedule_id`) **et ses versions sœurs du même périmètre sont SUPPRIMÉES** (inv. 1) | 409 si status ≠ `COMPLETED` · 409 si une sœur est `PENDING`/`GENERATING` · 409 `overlays_exist` si déplacer le pointeur de la saison détruirait des plans secondaires |
| **Rouvrir** | `POST /api/schedules/{id}/reopen` | le plan **DÉPOINTE** — il redevient un espace de travail, la version survit (inv. 2) | 409 si la version n'est pas celle que pointe son plan · 409 `overlays_exist` (voir §6) — le front n'active le bouton de confirmation qu'après saisie de la phrase exacte « modifier mon planning de saison » (`ConfirmDialog.confirmPhrase`, **uniquement** sur ce dialogue, pas sur celui de validation) |
| **Renommer le plan** | `PUT /api/schedule_plans/{id}` | `plan.name` — le **nom appartient au plan** (inv. 12) | gate management (SEC-07) |

- « Valider » reste le mot FR du bouton demandé par le gestionnaire ; ce qu'il fait, c'est **pointer**.
- **`POST /api/schedules/{id}/set-baseline` est supprimé** (inv. 18) : il n'y a plus qu'une vérité — le pointeur — donc plus de second geste pour la déplacer.
- **Aucun pointage automatique** (inv. 2) : la génération ne pointe jamais.
- **Tenant** : les deux endpoints de cycle de vie réutilisent le pattern `resolveCurrentClubId` (null → skip, RLS 404 ; mismatch → 403). Les deux exigent en plus le rôle management (SEC-07).

### 3.2 Verrou lecture seule **côté serveur** (les 3 chemins)
Le verrou se dérive du **pointeur** : « verrouillé » = **le plan pointe cette version** (`SchedulePlanProvisioner::isChosen`), jamais un statut. Les mutations de **contenu** sont alors refusées (409 « planning en vigueur ») :
- `GenerateScheduleController` : refuse la régénération de la version choisie — la rouvrir (dépointer) d'abord.
- `ManualEditController` (constraint/lock/move) : refuse si le `schedule` du slot est la version choisie de son plan.
- `ScheduleStateProcessor` PUT : refuse **toute** modification si la version est choisie — le verrou est **total** (« read only means read only »). Le **nom du plan** se renomme, lui, par `PUT /api/schedule_plans/{id}` (inv. 12), indépendamment de ce verrou. Les transitions de statut passent par `generate`/`validate`/`reopen`, jamais via PUT : le champ `status` est **accepté mais tout changement → 409**. Fabriquer un `COMPLETED` sans génération est donc impossible.

> `schedule_slot_templates` **n'a plus de rail d'écriture brut à garder** depuis que son CRUD est
> devenu read-only (2026-08-16, P4-102) — l'ancien 4ᵉ chemin (`ScheduleSlotTemplateStateProcessor`,
> qui refusait create/update/delete sur la version choisie) a disparu avec les routes qu'il gardait.
- `ScheduleStateProcessor` DELETE : la **version choisie ne se supprime pas** (409 — la rouvrir d'abord) et une version en cours de génération non plus (409). Gardé par `ScheduleLifecycleGuardTest` (phase1).

> Le verrou front seul serait une illusion (contrarian-review) : l'enforcement est **serveur**.

### 3.2bis Unicité du socle en vigueur (P2-7, livré 2026-07-30)

Le verrou lecture-seule ci-dessus protège la version **choisie** ; il ne dit rien d'une
**nouvelle** version créée à côté d'elle. `ScheduleStateProcessor::processPost` ferme ce trou :
un POST « de saison » (`schedulePlanId` omis → plan SEASON par défaut, ou plan SEASON nommé
explicitement) est refusé en **409** tant que `SchedulePlanProvisioner::chosenOfSeasonPlan`
répond non-null — message « Le planning de la saison est en vigueur — rouvrez-le avant d'en
préparer un autre. ». La garde est posée **sous le verrou de plan-scope de la saison**
(`SchedulePlanProvisioner::seasonScopeKey`) et **dans la transaction** de création (même
patron que `ValidateScheduleController`/`ReopenScheduleController` : un
`pg_advisory_xact_lock` pris hors transaction se relâcherait au statement suivant). Elle ne
s'arme jamais pour un overlay de période (plan CLOSURE/HOLIDAY).

**Défense en profondeur sur les trois autres portes (P2-9bis, livré 2026-07-31).** Le
cadrage initial soupçonnait un defect sur `regenerate`, `generate` et `regenerate-from` en
présence d'une version sœur héritée (l'état que seul le trou d'avant P2-7 pouvait produire).
Ce defect ne s'est jamais confirmé : `ValidateScheduleController` supprime déjà toutes les
sœurs à la validation, et le POST direct est fermé depuis P2-7 — quand le plan SEASON pointe,
il n'existe donc **qu'une seule** version de saison, et les trois portes n'ont aucune sœur à
viser. L'invariant était déjà tenu par cette **propriété émergente**, pas par une règle
explicite. `SocleGuard::assertSeasonPlanNotChosen` (miroir exact de `assertSeasonPlanChosen`,
même message) est désormais posée sur les trois portes en défense en profondeur — sous le
verrou de plan-scope de la saison et dans une transaction, pour qu'une lecture hors verrou ne
soit pas un simple TOCTOU :
- `GenerateScheduleController` : n'avait ni transaction ni verrou avant ce lot ; le passage en
  `PENDING` et le `flush` sont désormais enveloppés, verrou pris d'abord, `dispatch` **après**
  le commit. L'assertion n'y est armée que pour une version de saison (`isSeasonSchedule`) —
  un overlay a déjà passé `assertSeasonPlanChosen`, qui exige l'inverse.
- `RegenerateController` : assertion posée dans son `wrapInTransaction` existant.
- `RegenerateFromVersionController` : assertion posée dans son `wrapInTransaction` existant,
  **avant** le restore destructif (`StructureRestorer::apply`).

Le rattrapage de données sur les sœurs héritées d'avant P2-7 est **écarté** (décision
fondateur, V0 : pas de migration, les fixtures repartent de zéro). NR :
`Security/SeasonPlanInForceTest` (phase1) — épingle la propriété émergente elle-même
(valider une version de saison laisse exactement une version) en plus des trois portes et de
leur non-régression sans socle pointé.

⚠️ **Invariant, formulé juste** : « tant que le plan de saison est en vigueur, aucune AUTRE
version de saison n'est créée ni résolue, et la structure du club n'est pas écrasée par une
photo ancienne » — **pas** « rien ne touche au calendrier de saison ni à ses entrées ». La
structure du club (équipes, gymnases, coachs, contraintes permanentes) reste modifiable toute
l'année ; c'est précisément à ça que sert la comparaison `snapshotHash`/`currentStructureHash`,
pas à la geler.

### 3.3bis Confirmation de validation (responsabilité gestionnaire)
Le bouton **« Valider »** ouvre une **modale de confirmation** qui matérialise le choix du gestionnaire :
- Planning portant des **diagnostics/alertes** (partiel, dégradé, contraintes non satisfaites) → la modale **avertit explicitement** qu'il valide **malgré les contre-indications du solveur, sous sa responsabilité**.
- Planning sans alerte → confirmation simple.

Le « Valider » de la modale déclenche `POST /validate`. (Détection des alertes côté front via `useDiagnostics` déjà chargé sur `PlanningPage`.)

### 3.3 Machine à états
```
DRAFT ──generate──▶ PENDING ──▶ GENERATING ──▶ COMPLETED
                                        └──▶ FAILED
```
- `validate` / `reopen` **ne changent aucun statut** : ils posent et retirent le **pointeur du plan** (`schedule_plan.chosen_schedule_id`). Une version choisie reste `COMPLETED` ; « validé » se lit sur le pointeur (`Schedule.isChosen` en lecture d'API).
- `COMPLETED` inclut les plannings **partiels/dégradés** (commit `0fd895f`) → on peut choisir un planning partiel (assumé).
- **Effet de bord de `validate` (RMM-10 / P2-52, 2026-08-26)** : dans la MÊME transaction que le
  pointage, tout match domicile dont le gymnase a disparu du club+saison est dépointé
  (`FixtureVenueLossMarker`, `UNPLACED` + raison persistante `venue_lost`) — annoncé au préalable
  par `GET /api/schedules/{id}/validate-impact` (même prédicat, parité par construction). Comportement
  et détail complet : [`module-matchs.md`](module-matchs.md) § « P2-52 — un match déclaré ne perd
  plus sa salle en silence » ; cette spec ne fait que pointer l'effet de bord, pas le redécrire.

### 3.4 Pas de nouveau statut
`ScheduleStatus` reste `DRAFT/PENDING/GENERATING/COMPLETED/FAILED` : « validé » se dérive du pointeur, donc **aucun statut à ajouter** (inv. 1).

## 4. Frontend

- **Unions** : `ScheduleStatus` reste à **5** statuts (`planning/api.ts`, `wizard/api.ts`) — « validé » n'en est pas un.
- **API/hooks** : `reopenSchedule()` + `useReopenSchedule()` ; `useValidateSchedule`. Le pointeur se lit sur `/api/me` (`seasonPlan.chosenScheduleId`) et, par version, sur le champ de lecture `Schedule.isChosen`.
- **PlanningToolbar** :
  - Boutons contextuels : **« Valider »** si `COMPLETED` et non choisie (→ ouvre la **modale** §3.3bis) · **« Rouvrir »** si la version est choisie (+ indicateur 🔒 « Lecture seule ») · **« Régénérer »** sinon. **Symétrie stricte** (arbitrage fondateur, 2026-08-20, correction du modèle posé la veille) : Valider/Régénérer/Supprimer/le sélecteur de versions restent bornés à `embedded` (gestes de travail, wizard seul, étape Génération) ; **Rouvrir, lui, vit sur `/planning` autonome (`!embedded`)** — il en a DISPARU de la toolbar embarquée. Valider est la sortie du wizard et navigue vers `/planning` en succès ; Rouvrir en est la sortie inverse et ramène au wizard : les deux sont les sorties symétriques du cycle de vie. Le badge de statut est visible dans les **deux** modes. Détail du contrat de routage (plan pointé → `/planning`, non pointé → wizard mode déclaré) et du libellé d'état différencié : `frontend/docs/frontend-spec.md` §6.6bis.
  - Badge statut **traduit** (FR) pour les 5 statuts (voir §5).
  - Badge **« Planning principal »** vs **« Secondaire »**.
  - **Nom éditable** en ligne **uniquement si la version n'est pas choisie** (verrou total) ; le **nom du plan** se renomme par `PUT /api/schedule_plans/{id}`.
- **pickLandingScheduleId** : la version choisie du plan SEASON (hors overlay, hors vol) → sinon `pickDefaultSchedule` (`COMPLETED` le plus récent).
- **Read-only gating** : si la version sélectionnée est celle que pointe son plan → désactiver régénérer + renommage + passer `readOnly` à `SlotDetail` (move/lock off) et `WeekGrid` (clic slot off).
- `IN_FLIGHT` a UN foyer, `shared/lib/scheduleStatus.ts` (`IN_FLIGHT_STATUSES`), partout — y compris
  `pickLandingSchedule.ts` (P4-147, 2026-08-29 : dernière copie littérale résorbée).
- **Confirmation tapée à la réouverture** (P2-7, 2026-07-30) : la popup de réouverture (`ConfirmDialog` dans `PlanningPage.tsx`) porte `confirmPhrase="modifier mon planning de saison"` — son bouton reste désactivé tant que le gestionnaire n'a pas tapé la phrase exacte, et le champ **se revide à chaque tentative** (une confirmation qui échoue sans fermer le dialogue ne laisse pas le geste destructif à un clic). Prop additive sur `shared/components/ui/confirm-dialog.tsx`, câblée **uniquement** sur ce dialogue (pas sur celui de validation).
  ⚠ **Portée exacte, à ne pas surestimer** : cette popup n'apparaît **que** si la réouverture rencontre le 409 `overlays_exist`, c'est-à-dire s'il y a des plannings de période à détruire. **Un socle sans période à venir se rouvre en un clic, sans dialogue ni phrase** — décision fondateur assumée (2026-07-30) : la friction protège la destruction de plannings secondaires, pas le geste nu. Le serveur, lui, n'exige jamais la phrase : elle est purement cliente, c'est un garde-fou anti-fausse-manip, pas un contrôle de sécurité.

## 5. Libellés FR des statuts
`DRAFT`=Brouillon · `PENDING`=En attente · `GENERATING`=Génération… · `COMPLETED`=Terminé · `FAILED`=Échec (`STATUS_LABELS`, `planning/api.ts`). « Validé » ne figure pas dans cette liste : ce n'est pas un statut mais l'état du **pointeur**, affiché à part (badge / 🔒 « Lecture seule »).

## 6. Tests

**Backend** (`--group phase1` pour l'isolation) :
- `ValidateScheduleTest` : `/validate` **pointe** la version sur son plan et **supprime les versions sœurs** ; 409 si non-`COMPLETED` ; 409 si une sœur est en génération.
- `ReopenScheduleTest` : `/reopen` **dépointe** le plan (la version survit) ; 409 si la version n'est pas celle que pointe son plan.
- `SeasonVersionUniquenessTest` (P2-7, §3.2bis) : `POST /api/schedules` refusé en 409 tant que le plan SEASON pointe une version, sous ses deux formes (sans `schedulePlanId`, avec le plan SEASON explicite) ; accepté si aucune version n'est pointée ; accepté pour un overlay de période même quand le socle est en vigueur.
- `SeasonPlanInForceTest` (P2-9bis, §3.2bis) : épingle la propriété émergente (valider une version de saison laisse exactement une version de saison) et couvre `generate`/`regenerate`/`regenerate-from` — 409 « est en vigueur » sur une sœur pendant qu'une autre version est pointée, 202/200 nominal sans socle pointé.
- `SchedulePlanLifecycleTest` / `SchedulePlanReadModelTest` / `SchedulePlanProvisionerTest` : pointeur, compteur de versions, provisioning et modèle de lecture du plan.
- **Gardes** (`ScheduleLifecycleGuardTest`) : régénération / manual-edit / slot-template / PUT / DELETE → **409** quand le plan pointe la version.
- **Tenant isolation** (blocking) : `/validate` et `/reopen` cross-club → 403.
- **Déblocage du cockpit** (cockpit palier A) : `seasonPlan.hasFinishedVersion` = le plan SEASON porte ≥1 version terminée (`COMPLETED`/`FAILED`). **Dérivé, jamais posé, indépendant du pointeur** — `/reopen` ne re-verrouille pas. Exposé sur `/api/me`. Débloque l'accueil cockpit (vs work-loop). Voir `specs/courantes/accueil-cockpit-temporel.md` §2ter.
- **Reopen destructeur du calendrier de saison** (cockpit palier B) : rouvrir la **version choisie du plan SEASON** alors que des calendriers secondaires (plans de période) existent les **supprime** (spec §2bis, inv. 14). `POST /api/schedules/{id}/reopen` renvoie **409 `{code:"overlays_exist", count, overlays:[{entryId,title}]}`** ; le client confirme avec le body `{"confirmDeleteOverlays": true}` → chaque période est détruite **de bout en bout** (`OverlayManager::deletePeriodPlanForEntry`) : ses versions, **son plan**, et tous les réglages ancrés au plan (grille de créneaux copiée, réservations, modes gymnase, overrides d'équipes/de contraintes) — **l'entrée de calendrier survit** et retombe « à traiter » au radar, à refaire — puis le reopen procède. Même garde, même code, sur `/validate` quand choisir une **autre** version déplacerait le calendrier de la saison. **Portée (amendement fondateur, 2026-07-24, ADR-0002 inv. 14)** : toute période qui porte un plan, **validé ou non** (une période « Adaptée » mais jamais générée compte aussi), et seulement celles **entièrement à venir** — pivot = la date de **début** : « rien du passé, rien de ce qui est en cours » (décision fondateur 2026-07-16), `CalendarEntryRepository::findWithPlanNotStarted`. Zéro période concernée, ou reopen d'un overlay de période : comportement inchangé.

**Frontend** :
- Toolbar : bouton Valider (`COMPLETED` non choisie) / Rouvrir (version choisie), read-only gating, libellés statut, badge « Planning de la saison ». Bandeau cockpit : `SeasonPlanBanner`.

## 7. Vérification (backend touché ⇒ obligatoire)
- `cd backend && make test` (CS-Fixer + PHPStan lvl8 + PHPUnit)
- **`backend/scripts/smoke-solver.sh` → planning `COMPLETED`**
- `frontend` : `npm run test` + `npm run build`
- Contrat engine inchangé (le pointeur est backend-only, l'engine ne le voit pas) — aucun bump `CONTRACT_VERSION`.

## 8. Checklist de scope (§9 CLAUDE.md)

> *Checklist datée de la PR-3, conservée pour la traçabilité — **périmée par la bascule ADR-0002 (2026-07-16)** : `SetBaselineController` n'existe plus et le cycle de vie repose sur le pointeur du plan.*
- **Zone** : `backend/src` (Enum, Entity, Controller, State, Dto) + `backend/tests` + `frontend/src/features/planning` (+ union `wizard/api.ts`).
- **Interdit** : `engine/**` (aucun changement) · `specs/initiales/**` · la **cascade** baseline→secondaires · le modèle occurrences.
- **Fichiers probables** : `Enum/ScheduleStatus.php`, `Controller/{ValidateSchedule,Reopen,SetBaseline}Controller.php`, `Controller/GenerateScheduleController.php`, `Controller/ManualEditController.php`, `State/Processor/{Schedule,ScheduleSlotTemplate}StateProcessor.php`, `Dto/ScheduleInput.php` ; front `planning/{api,queries,PlanningToolbar,PlanningPage,SlotDetail,WeekGrid}.tsx`, `wizard/api.ts`. **Tests** : `ValidateScheduleTest`, `ReopenScheduleTest`, `SetBaselineTest`, gardes, tenant.
- **Doc** : cette spec + `roadmap.md` (N3).
- **Revalidation si** : besoin de la cascade, ou fuite du statut dans le contrat engine, ou impact multi-zone non prévu.
- **Aucun refactoring hors scope.**
- **Ordre commits** : (1) backend enum+endpoints+gardes+tests → (2) frontend → (3) doc.
