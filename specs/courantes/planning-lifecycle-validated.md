# Cycle de vie des plannings — le pointeur du plan (N3)

Le plan de type **SEASON** (`schedule_plan`) et **la version qu'il pointe**
(`chosen_schedule_id`, `App\Entity\SchedulePlan`) SONT le calendrier de la saison
([ADR-0002](../../docs/architecture/adr-0002-pattern-plan.md)).

> **But** : permettre au gestionnaire de **choisir** la version qui fait foi pour la saison, de la **rouvrir** pour l'éditer, et distinguer le **« Planning de la saison »** (le plan SEASON pointé) des **plannings de période** (les plans de période).
> **Source de vérité** : le **code** (confronté au besoin exprimé). La roadmap n'est qu'une base de discussion.

## 0. Machine à états accueil/onboarding (cockpit) — 2026-07-08

Le point d'entrée après login dérive de **deux critères portés par le plan SEASON**, exposés ensemble par `/api/me` dans `seasonPlan` : le plan **porte-t-il au moins une version terminée** (`hasFinishedVersion`, ADR-0002 inv. 8/16) et **pointe-t-il une version** (`chosenScheduleId`, inv. 13). Le flag legacy `club.onboardingCompleted` **n'est plus lu pour le routage**.

Les deux critères sont **indépendants** : `hasFinishedVersion` est dérivé des versions du plan et jamais retiré par un dépointage — **rouvrir ne re-verrouille donc pas le cockpit** et ne renvoie personne à l'onboarding.

| État | Condition | Landing | « Ouvrir » (carte planning de saison) | Restrictions |
|---|---|---|---|---|
| **1. Jamais généré** | `hasFinishedVersion = false` | **/wizard** — étape **Récap** (ou 1ʳᵉ étape incomplète) | — | app verrouillée au wizard (`AuthGuard`, burger profil/club autorisé) |
| **2. A généré, rien de choisi** | `hasFinishedVersion = true`, `chosenScheduleId = null` | **cockpit** (déverrouillé) + bandeau « valider pour débloquer » | → **/wizard** étape **Génération** | **matchs** + **plannings de période** bloqués (front désactivé + `SocleGuard` 409) |
| **3. Une version choisie** | `chosenScheduleId ≠ null` | **cockpit** (tout ouvert) | → /planning | aucune |

Ancrages : `AuthGuard.tsx` (onboarding = `!seasonPlan.hasFinishedVersion`), `CockpitPage.tsx` (idem → /wizard), `WizardLayout.tsx` (guided = `!hasFinishedVersion`, landing Récap), `SeasonPlanBanner.tsx` (« Ouvrir » état 2→wizard génération), `AppLayout.tsx` + `MatchesLayout.tsx` (matchs verrouillés tant que `chosenScheduleId` est null — le garde a quitté `MatchesPage.tsx` pour `MatchesLayout.tsx` en RMM-1 PR2, 2026-08-23, pour couvrir les deux routes `/matchs` et `/matchs/configuration`), `App\Service\SocleGuard::assertSeasonPlanChosen` (409 création match/import/overlay tant qu'aucune version n'est pointée, appliqué dans `FixtureStateProcessor`, `ImportFixturesController`, `ScheduleStateProcessor` et `GenerateScheduleController`). Fixtures (P5-17, 2026-08-17) : le club BCCL **dev** est seedé en **état 3** — `BcclSeeder` transcrit le planning réel du club (90 créneaux) dans une version `COMPLETED` et pointe le plan SEASON dessus (`linkSchedule` puis `choose`) ; le club de **DÉMONSTRATION** reste seedé en **état 1** (données saisies, aucune génération — drapeau `BcclSeedProfile::transcribeRealSchedule` à `false`).

## 1. Modèle produit (validé avec le gestionnaire)

- **Valider = POINTER** (inv. 1) : le plan nomme la version qui fait foi (`schedule_plan.chosen_schedule_id`). **« Validé » n'est pas un statut** — ça se dérive du pointeur, et de rien d'autre ; la version choisie reste `COMPLETED`. Un plan pointe **au plus une** version.
- **Valider SUPPRIME les versions sœurs** du même périmètre (inv. 1) : le plan porte la version qui compte, pas un cimetière. Plus d'archivage, plus de filet. Une sœur encore en génération bloque le choix (409).
- **Rouvrir = DÉPOINTER** (inv. 2) : le plan redevient un **espace de travail** et la version **survit**, éditable, puis re-choisissable.
- **Générer ne pointe jamais** (inv. 2). Seul le gestionnaire choisit, avec une exception unique : un planning de PÉRIODE (`CLOSURE`/`HOLIDAY`) non validé **7 jours après le début de sa période** est pointé seul sur sa version chargée, **sœurs gardées** (`PeriodPlanAutoValidator`, tâche `app:period-plans:auto-validate` à 00:15 ; jamais le plan SEASON, jamais un plan déjà validé une fois — même rouvert —, jamais une entrée `ignored`, une mère découpée ni une saison archivée ; une version en génération le diffère, aucune version terminée = rien d'écrit). `first_chosen_at` n'est pas posé ; la marque « Validé automatiquement » (`autoValidated`) se lit dans « Tous les plannings » tant qu'aucune validation manuelle n'a suivi ; « Rouvrir » reste possible. Rien d'autre ne bouge (matchs, doléances, e-mail, Mercure).
- **« Planning de la saison »** = le plan **SEASON** et sa version choisie. Les **plans de période** (`CLOSURE`/`HOLIDAY`, plans d'exception bornés du cockpit) = **plannings de période** ; chacun porte **son propre** pointeur, sur son propre plan.
- **Invariant (UX-02)** : le pointeur du plan SEASON ne peut nommer qu'une version de saison (`calendarEntryId` null) — un overlay de période appartient au plan de sa période, pas au plan SEASON. Côté front, la sélection d'atterrissage (`pickLandingScheduleId`) ignore une version choisie qui serait un overlay ou en vol → jamais d'ouverture sur un « ★ · période » vide.
- Le gestionnaire consulte **tous** les plannings de l'année ; il édite ceux que leur plan ne pointe pas.

### Hors scope (reporté, raison technique)
- **Cascade « éditer le calendrier de la saison ⇒ répercuter sur les plannings de période »** : suppose que les secondaires dérivent du calendrier de base (modèle **templates → occurrences**, roadmap §2, **absent**). Impossible proprement aujourd'hui → différé, documenté. En attendant, déplacer ou retirer le pointeur du plan SEASON **détruit** les plannings de période, sur confirmation explicite (inv. 14, §6).

## 2. Radar « une personne à deux endroits » sur le planning en vigueur (P4-269)

Compléter le modèle après génération (lier un coach adjoint déclaré tard, un joueur actif) ne
rend **jamais** le planning « à régénérer » sur une version **EN VIGUEUR** (validée) — P4-266 : un
planning validé ne porte plus aucun signal, quelle qu'en soit la cause. Sur une version de
**TRAVAIL**, en revanche, rattacher un coach à une équipe fait désormais diverger l'empreinte de
structure (P4-266 — revirement assumé sur l'ancien listener ciblé P4-268, qui n'écoutait pas
`TeamCoach`/`CoachPlayerMembership`). Dans tous les cas, le lien peut mettre une personne sur deux
séances **déjà placées** qui se chevauchent, sans que rien ne le dise. `App\Service\PlacedSessionPersonConflictDetector` ferme
cet angle mort : feed **recalculé en lecture seule** à chaque appel (rien n'est persisté), il
croise les séances placées de la version **pointée** du plan SEASON avec les liens COURANTS —
coach MAIN, joueur (`CoachPlayerMembership` actif) — amendé le 2026-10-30 (« un coach tient la séance ») : un adjoint ou un coach absent ne fait plus conflit tant qu'un coach tient la séance (`CoachDoubleBookingDetector::holdersFor`), conflit seulement si personne ne peut la tenir, le joueur dans deux équipes restant un conflit ; amendé le 2026-11-02 : **un adjoint NOMMÉ sur une séance ne la tient jamais** (`PlacedSessionPersonConflictDetector::sessionCoachesOf` — le nommé compte s'il n'est pas adjoint de l'équipe, un nommé sans lien compte ; adjoint nommé ⇒ les principaux de l'équipe restent attendus), et une équipe sans principal n'a personne d'attendu : le radar se tait, sans repli sur l'adjoint (le Récap avertit, sans bloquer) — et réutilise, sans le
réécrire, `CoachDoubleBookingDetector::bookingsCollide` (intervalles réels, gymnases
**DIFFÉRENTS** uniquement — le même gymnase reste la mutualisation voulue, jamais un conflit). Les indisponibilités de coach y sont jugées à l'heure de début de la séance (règle de l'existant) : seule la pose libre du moteur et le candidat d'un déplacement les testent au chevauchement.
Périmètre V1 (fondateur 2026-09-28) : la version pointée du plan **SEASON** seulement (les plans
de période en sont hors scope).

**Relais de l'adjoint (chantier H)** : une paire dédoublante où la personne est un **coach de
séance** de l'équipe REJOINTE (la plus tardive — un joueur n'est jamais « ouvert » par un adjoint)
n'est plus un conflit mais un **relais TOLÉRÉ** quand la séance rejointe porte une tolérance
d'arrivée tardive (`ScheduleSlotTemplate.coachLateToleranceMinutes`, 0..30) couvrant le retard
géométrique — fin de la 1ʳᵉ séance + barème de trajet (`VenueTravelTime`, selon véhiculé ou non) −
début de la 2ᵉ — **et** qu'un adjoint de l'équipe rejointe est DISPONIBLE pour ouvrir la séance
(aucune indisponibilité déclarée ni séance MAIN dans un AUTRE gymnase sur la fenêtre de retard ;
même gymnase = mutualisation, jamais un obstacle). Indisponibilités déclarées et trajets entre
gymnases entrent donc désormais dans le calcul, mais **seulement** pour qualifier un relais —
jamais pour la règle de collision elle-même (`bookingsCollide`, inchangée). `App\Service\
PlacedSessionPersonConflictDetector::detect` rend les relais à part (`relays`, détail §ci-dessous),
jamais mélangés aux conflits.

**Séance sans coach au début (`absences`, 2026-11-02)** : une séance dont tous les coachs principaux sont
indisponibles au début ne ferme plus rien au moteur (elle est jugée seule quand ses partenaires de groupe ne
sont pas tous figés sur sa case) : les déplacements des autres séances restent possibles, et l'écart se dit à
l'écran. `absences` (`{scheduleId, dayOfWeek, startTime, venueName, teamNames, coachNames}`, toujours présent,
calculé même sans paire en conflit) suit la version AFFICHÉE : `?scheduleId=` (optionnel ; absent = version
pointée ; autre club ou inconnu = 404) alors que `conflicts` et `relays` restent ceux de la version pointée.
Candidats = principaux de l'équipe et des partenaires de groupe figés sur la même case
(`CoachDoubleBookingDetector::sessionsWithoutAnyCoachAtStart`) ; un adjoint n'est jamais candidat, un coach
nommé périmé est ignoré, une équipe sans principal ne déclenche rien. Les indisponibilités lues sont celles de
la saison (plan SEASON) ou celles retenues pour la période (`selectForPlan(...)->kept`) : une absence
datée d'un autre moment n'alerte pas. Rendu : `PlacedAbsencesNotice` (`planning/PlacedAbsencesNotice.tsx`, ton
`warning`, « Une séance sans coach » / « N séances sans coach »), dès qu'une version est affichée, via `usePlacedConflicts(scheduleId)` (clé `["training","placed-conflicts", scheduleId ?? "season"]`,
invalidée par préfixe après un déplacement).

`GET /api/training/placed-conflicts` (club-scopé, réservé gestionnaire — déclaré,
refusé 403 au Membre par le contrôle central avant le contrôleur) rend `{seasonPlanChosen, conflicts,
relays, absences}` : `seasonPlanChosen: false` (aucune version pointée) veut dire « pas de planning en
vigueur à scanner » — `conflicts: []` n'y signifie alors **pas** « tout va bien ». Un relais toléré
sort de `conflicts` et entre dans `relays` (`{personId, personName, dayOfWeek, lateMinutes,
adjointName, first, second}`) — **jamais compté parmi les conflits**, affiché à part
(`PlacedRelaysNotice`, `planning/PlacedRelaysNotice.tsx`, ton `warning`, un encart par relais à côté
du bandeau de conflits, jamais fusionné avec lui).

Trois rendus de la même primitive de présentation (`PlacedConflictsNotice`,
`planning/PlacedConflictsNotice.tsx`), consommée par `usePlacedConflicts()`
(`planning/queries.ts`, `staleTime: 10 000`) :
- l'étape Coachs du wizard (`CoachesStep`), rafraîchie après chaque mutation de lien
  coach↔équipe ou coach-joueur ;
- le bandeau de `/planning`, page **autonome** seulement (`!embedded && !scoped`), et seulement
  quand la version affichée est **celle en vigueur** (`isReadOnly`) ;
- la pastille du bandeau de saison du cockpit (`SeasonPlanBanner`) — le nombre de **personnes**
  distinctes concernées (une personne peut porter plusieurs paires en conflit), pas le nombre de
  paires.

Détail exhaustif (règle de collision, forme du payload) : le service et le contrôleur eux-mêmes,
inventoriés dans [`backend-controllers.md`](../../backend/docs/backend-controllers.md) § « Radar de
conflits d'entraînement ».

## 3. Décisions de conception

### 3.1 Les actions et leur effet (ADR-0002)

| Action | Endpoint | Effet | Garde |
|--------|----------|-------|-------|
| **Créer une version de saison** | `POST /api/schedules` (sans `schedulePlanId`, ou avec le plan SEASON explicite) | prépare une nouvelle version sous le plan SEASON ; avec un socle en vigueur, la version née est de travail et le pointeur ne bouge pas | aucun refus au motif du socle en vigueur (lot 5) |
| **Valider (choisir)** | `POST /api/schedules/{id}/validate` | le **plan POINTE** cette version (`chosen_schedule_id`) **et ses versions sœurs du même périmètre sont SUPPRIMÉES** (inv. 1) | 409 si status ≠ `COMPLETED` · 409 si une sœur est `PENDING`/`GENERATING` · 409 `not_loaded_version` si ce n'est pas la version chargée · 409 `changes_to_confirm` si la copie du froid de la version diffère de celle en vigueur ET que la validation supprimerait des plannings de période pas commencés : la requête se rejoue avec `confirmChanges` (le jeton de la liste vue, `GET …/validate-impact`) |
| **Rouvrir** (plan de PÉRIODE seulement) | `POST /api/schedules/{id}/reopen` | le plan **DÉPOINTE** — il redevient un espace de travail, la version survit (inv. 2) | 409 si la version n'est pas celle que pointe son plan · **409 pour un plan de saison** : il se retravaille (`POST /api/schedules/{id}/rework`, copie de travail ; le pointeur ne bouge qu'à la validation) |
| **Renommer le plan** | `PUT /api/schedule_plans/{id}` | `plan.name` — le **nom appartient au plan** (inv. 12) | gate management (SEC-07) |

- « Valider » reste le mot FR du bouton demandé par le gestionnaire ; ce qu'il fait, c'est **pointer**.
- **`POST /api/schedules/{id}/set-baseline` est supprimé** (inv. 18) : il n'y a plus qu'une vérité — le pointeur — donc plus de second geste pour la déplacer.
- **Aucun pointage automatique** (inv. 2) : la génération ne pointe jamais.
- **Tenant** : les deux endpoints de cycle de vie partagent `ResolvesCurrentClubTrait::resolveCurrentClubId` (null → skip, RLS 404 ; mismatch → 403). Les deux exigent en plus le rôle management (SEC-07).

### 3.1bis Les verrous d'une période survivent à « Régénérer » ; « Charger » pour une période (ADR-0002 inv. 21)

Sur un plan de **PÉRIODE**, « Régénérer » crée une version NUE (aucun `ScheduleSlotTemplate`
propre) ; sans mécanisme dédié, ses verrous HARD se perdaient. Elle repart désormais TOUJOURS des
**données en cours** du plan — la version **SOURCE**, dont les verrous HARD sont greffés par
`ScheduleConstraintBuilder::buildForPlan` (maison de résolution :
`SchedulePlanProvisioner::lockSourceScheduleIdOfPlan`, SEASON comme PÉRIODE) :

| Action | Endpoint | Effet | Garde |
|--------|----------|-------|-------|
| **Régénérer un overlay** | `POST /api/schedules` (plan de PÉRIODE) + `generate` | nouvelle version du plan, verrous HARD de la source réépinglés | le socle, la génération en cours (409) |
| **Charger** (tout plan) | `POST /api/schedules/{id}/load` | remet l'état de travail du plan d'après la copie de la version, puis pose le **pointeur** `schedule_plan.lock_source_schedule_id` sur elle — ses verrous deviennent les données en cours, aucun solve lancé | 409 sur une version non `COMPLETED`, un plan **validé**, une génération en cours (`generation_in_progress`), une version qui ne se charge pas (`load_blocked`) |

La source d'un plan est le pointeur s'il désigne une version `COMPLETED` existante du même plan,
sinon sa dernière version `COMPLETED` — ce repli couvre tout plan sur lequel personne n'a « chargé »
une version. Le pointeur se pose de deux façons : automatiquement (chaque génération de période qui
vient de `COMPLETED` devient la source — `GenerateScheduleHandler`) ou par **Charger**, réservé à un
plan non validé (la même route pour le socle et pour une période : `LoadVersionController`). Régénérer en REGARDANT une autre version que
la source ne change pas la source retenue — c'est pour ça que « Charger » existe : sans lui, la
phrase de confirmation ci-dessous (§3.5) mentirait sur une période.

### 3.2 Verrou lecture seule **côté serveur** (les 3 chemins)
Le verrou se dérive du **pointeur** : « verrouillé » = **le plan pointe cette version** (`SchedulePlanProvisioner::isChosen`), jamais un statut. Les mutations de **contenu** sont alors refusées (409 « planning en vigueur ») :
- `GenerateScheduleController` : refuse la régénération de la version choisie — la rouvrir (dépointer) d'abord.
- `ManualEditController` (constraint/lock/move) : refuse si le `schedule` du slot est la version choisie de son plan.
- `ScheduleStateProcessor` PUT : refuse **toute** modification si la version est choisie — le verrou est **total** (« read only means read only »). Le **nom du plan** se renomme, lui, par `PUT /api/schedule_plans/{id}` (inv. 12), indépendamment de ce verrou. Les transitions de statut passent par `generate`/`validate`/`reopen`, jamais via PUT : le champ `status` est **accepté mais tout changement → 409**. Fabriquer un `COMPLETED` sans génération est donc impossible.

> `schedule_slot_templates` est **read-only** (`ScheduleSlotTemplateResource` n'expose qu'un
> provider, aucun processor) : aucun rail d'écriture à garder sur ce verrou.
- `ScheduleStateProcessor` DELETE : la **version choisie ne se supprime pas** (409 — la rouvrir d'abord) et une version en cours de génération non plus (409). Gardé par `ScheduleLifecycleGuardTest` (phase1).

> Le verrou front seul serait une illusion (smith) : l'enforcement est **serveur**.

### 3.2bis Un socle en vigueur se retravaille, il ne se rouvre pas

Tant que le plan SEASON pointe une version, générer, régénérer, charger une version et préparer une nouvelle version de saison (`POST /api/schedules`) sont permis : aucun ne touche au pointeur, la version en vigueur reste celle que lisent matchs, radar et plans de période jusqu'à la prochaine validation. `SocleGuard::assertSeasonPlanNotChosen` n'existe plus. « Rouvrir » un plan de saison répond 409 ; le geste est « Retravailler » (`ReworkScheduleController`), qui copie la version en vigueur en version de travail. Valider une version de saison supprime encore ses sœurs. NR : `Security/SeasonPlanInForceTest`, `Security/SeasonVersionUniquenessTest` (bloquants).

⚠️ **Invariant, formulé juste** : tant que le plan de saison est en vigueur, la version en vigueur ne change que par une validation, et la structure du club n'est pas écrasée par une photo ancienne — **pas** « rien ne touche au calendrier de saison ». La structure du club (équipes, gymnases, coachs, contraintes permanentes) reste modifiable toute l'année ; la comparaison `snapshotHash` ⇄ empreinte de structure (`SchedulePlanProvisioner::structureHashOfPlan`, P4-266) signale l'écart, elle ne gèle rien.

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
- `COMPLETED` inclut les plannings **partiels/dégradés** → on peut choisir un planning partiel (assumé).
- **Effet de bord de `validate` (RMM-10 / P2-52, 2026-08-26)** : dans la MÊME transaction que le
  pointage, tout match domicile dont le gymnase a disparu du club+saison est dépointé
  (`FixtureVenueLossMarker`, `UNPLACED` + raison persistante `venue_lost`) — annoncé au préalable
  par `GET /api/schedules/{id}/validate-impact` (même prédicat, parité par construction). Comportement
  et détail complet : [`module-matchs-perimetre-engage.md`](module-matchs-perimetre-engage.md) §11 « Le périmètre engagé » (sous-§ salle
  protégée à la source) ; cette spec ne fait que pointer l'effet de bord, pas le redécrire.

### 3.4 Pas de nouveau statut
`ScheduleStatus` reste `DRAFT/PENDING/GENERATING/COMPLETED/FAILED` : « validé » se dérive du pointeur, donc **aucun statut à ajouter** (inv. 1).

### 3.5 Capacités exposées par le serveur (`ScheduleCapabilityResolver`, P2-8)

Le front ne re-dérive plus les règles de refus du planning : chaque `Schedule` de `GET /api/schedules` porte un
bloc `capabilities` (`canDelete`, `canValidate`, `canLoad`,
`versionsDeletedOnValidate`, `canEdit`, `editBlockedReason`,
`loadBlockedReason`), calculé par `App\Service\ScheduleCapabilityResolver` — les gardes d'écriture
(DELETE / validate / load) et le sérialiseur tirent le **même verdict** via les mêmes prédicats
unitaires (`isInFlight`, `isChosen`, `isLastFinishedSeasonVersion`, `inFlightInSeason`), donc la capacité
affichée EST le refus réel — pas de dérive front↔serveur possible. Le front lit, il n'infère plus ; repli
fail-closed (capacité absente → geste non offert). `forSchedules()` calcule le bloc en BATCH pour toute une
collection (un `GROUP BY (schedule_plan_id, status)` + un appel par saison, coût fixe quel que soit le nombre
de versions) — jamais de N+1. **`canLoad`** (§3.1bis, tout plan) : version `COMPLETED`, plan
**non validé**, aucune génération en cours dans la saison, chargeable (`LoadFeasibility`), et **pas déjà** la version
chargée de son plan (le geste ne changerait rien : faux) — un batch distinct lu sur
`SchedulePlanProvisioner::lockSourceScheduleIdOfPlan`. Gardé par `ScheduleCapabilityParityTest` (step du gate CI) : la capacité exposée
== le verdict du garde, prouvé dans les deux sens. `Schedule` porte aussi **`isLockSource`/`hardLockCount`**
(V1, renseignés EN BATCH par `ScheduleStateProvider`) — est-ce que CETTE version est la source des verrous
de son plan, et combien elle en porte ; consommés par la confirmation de régénération ci-dessous (§4).

**Seule la version CHARGÉE (l'étoile) se modifie** (`App\Service\VersionEditability`, maison unique du
verdict) : les neuf guichets de retouche, la validation et « Combler » refusent toute autre version en
409 codé `not_loaded_version` (la version en vigueur garde `chosen_version`) ; `canEdit` /
`editBlockedReason` exposent ce même verdict. Même règle pour un plan de période : sa version source
(`lockSourceScheduleIdOfPlan`). Les autres versions se consultent. Côté génération de saison, seule la
chargée part au moteur et compte pour les verrous (`ScheduleConstraintBuilder::buildForPlan`,
`versionId`) ; `POST /regenerate` repart toujours de la chargée et répond 202 avec
`sourceScheduleId` ; `POST /generate` sur une version de saison déjà `COMPLETED` → 409 (« Régénérer »
en crée une nouvelle) ; si la version de départ a disparu avant le solve, l'échec est typé
`source_version_missing`. `loadBlockedReason` dit, mot pour mot, pourquoi « Charger » est
refusé (`App\Plans\LoadFeasibility`). Supprimer la version d'où repart une génération en cours → 409.
L'empreinte de structure ignore désormais l'id et l'ordre des lignes (placements dédoublonnés) : voir
`docs/ops/deploy.md`.

## 4. Frontend

- **Unions** : `ScheduleStatus` reste à **5** statuts (`planning/api.ts`, `wizard/api.ts`) — « validé » n'en est pas un.
- **API/hooks** : `reopenSchedule()` + `useReopenSchedule()` ; `useValidateSchedule`. Le pointeur se lit sur `/api/me` (`seasonPlan.chosenScheduleId`) et, par version, sur le champ de lecture `Schedule.isChosen`.
- **PlanningToolbar** :
  - Boutons contextuels : **« Valider »** si `COMPLETED` et non choisie (→ ouvre la **modale** §3.3bis) · **« Retravailler »** (plan de saison) ou **« Rouvrir »** (plan de période) si la version est choisie (+ indicateur 🔒 « Lecture seule ») · **« Régénérer »** sinon. **Symétrie stricte** (arbitrage fondateur, 2026-08-20, correction du modèle posé la veille) : Valider/Régénérer/Supprimer/le sélecteur de versions restent bornés à `embedded` (gestes de travail, wizard seul, étape Génération) ; **Rouvrir, lui, vit sur `/planning` autonome (`!embedded`)** — il en a DISPARU de la toolbar embarquée. Valider est la sortie du wizard et navigue vers `/planning` en succès ; Rouvrir en est la sortie inverse et ramène au wizard : les deux sont les sorties symétriques du cycle de vie. Le badge de statut est visible dans les **deux** modes. Détail du contrat de routage (plan pointé → `/planning`, non pointé → wizard mode déclaré) et du libellé d'état différencié : `frontend/docs/frontend-spec.md` §6.6bis.
  - Badge statut **traduit** (FR) pour les 5 statuts (voir §5).
  - Le titre porte le **nom réel du plan** (`displayedPlanName`, ex. « Planning de la saison 2026-2027 », défaut « Planning de saison ») — plus de badge générique de planning (la pastille de l'écran Planning dit « saison ») ; un planning de PÉRIODE se distingue par le mot « de période » dans ses propres phrases (compteur, confirmations), jamais une étiquette fixe sur le titre.
  - **Nom éditable** en ligne **uniquement si la version n'est pas choisie** (verrou total) ; le **nom du plan** se renomme par `PUT /api/schedule_plans/{id}`.
  - **Version non chargée** : un geste grisé dit son motif en clair, une ligne dans la barre (`notLoadedToolbarLine`, ou `cannotLoadLine` avec le motif serveur `loadBlockedReason` si elle ne peut pas être chargée) et dans la fiche de séance. Une bannière neutre de consultation s'affiche sur toute version `COMPLETED` non chargée, saison comme période ; sur une période non source, le bouton « Ouvrir la version source » y mène. Les textes vivent dans `PLANNING_TEXTS` (`planning/texts.ts`), `PlanningToolbar.tsx` est inscrit à la garde des textes centralisés.
- **pickLandingScheduleId** : la version choisie du plan SEASON (hors overlay, hors vol) → sinon `pickDefaultSchedule` (la version chargée `COMPLETED`, à défaut la `COMPLETED` la plus récente). L'embarqué en saison suit la règle d'atterrissage de `generation-pipeline.md` (chargée).
- **Read-only gating** : si la version sélectionnée est celle que pointe son plan → désactiver régénérer + renommage + passer `readOnly` à `SlotDetail` (move/lock off) et `WeekGrid` (clic slot off).
- `IN_FLIGHT` a UN foyer, `shared/lib/scheduleStatus.ts` (`IN_FLIGHT_STATUSES`), consommé partout —
  y compris `planning/lib/pickLandingSchedule.ts` — jamais de copie littérale du statut.
- **Confirmation tapée** : `confirmPhrase` (`shared/components/ui/confirm-dialog.tsx`) ne sert plus à « Rouvrir » (plus de dialogue « Rouvrir et supprimer ») ; « Retravailler » demande une confirmation simple (`reworkTitle`, `reworkDescription`, `planning/texts.ts`).
- **« Charger cette version » pour une PÉRIODE + confirmation de régénération** (V1/V1bis, §3.1bis) :
  `PlanningToolbar` offre « Charger cette version » (`canLoad`, saison comme période) via
  `POST /api/schedules/{id}/load` (`useLoadVersion`), sans générer — une
  `ConfirmDialog` NON destructive l'annonce (elle ne nomme plus la version). Et régénérer un overlay en REGARDANT une version qui
  n'est pas la source (`isLockSource`) ouvre une seconde `ConfirmDialog` NON destructive nommant les
  deux versions et le nombre de verrous HARD de la source (`hardLockCount`) avant de confirmer la
  régénération depuis la source. Détail des deux dialogues : `frontend/docs/frontend-workloop.md`
  §6.7 quater.

## 5. Libellés FR des statuts
`DRAFT`=Brouillon · `PENDING`=En attente · `GENERATING`=Génération… · `COMPLETED`=Terminé · `FAILED`=Échec (`STATUS_LABELS`, `planning/api.ts`). « Validé » ne figure pas dans cette liste : ce n'est pas un statut mais l'état du **pointeur**, affiché à part (badge / 🔒 « Lecture seule »).

## 6. Tests

**Backend** (`--group phase1` pour l'isolation) :
- `ValidateScheduleTest` : `/validate` **pointe** la version sur son plan et **supprime les versions sœurs** ; 409 si non-`COMPLETED` ; 409 si une sœur est en génération.
- `ReopenScheduleTest` : `/reopen` d'un plan de période **dépointe** le plan (la version survit) ; 409 si la version n'est pas celle que pointe son plan, 409 pour un plan de saison.
- `SeasonVersionUniquenessTest` (P2-7, §3.2bis) : `POST /api/schedules` refusé en 409 tant que le plan SEASON pointe une version, sous ses deux formes (sans `schedulePlanId`, avec le plan SEASON explicite) ; accepté si aucune version n'est pointée ; accepté pour un overlay de période même quand le socle est en vigueur.
- `SeasonPlanInForceTest` (P2-9bis, §3.2bis) : épingle la propriété émergente (valider une version de saison laisse exactement une version de saison) et couvre `generate`/`regenerate`/`load` — 409 « est en vigueur » sur une sœur pendant qu'une autre version est pointée, 202/200 nominal sans socle pointé.
- `SchedulePlanLifecycleTest` / `SchedulePlanReadModelTest` / `SchedulePlanProvisionerTest` : pointeur, compteur de versions, provisioning et modèle de lecture du plan.
- **Gardes** (`ScheduleLifecycleGuardTest`) : régénération / manual-edit / slot-template / PUT / DELETE → **409** quand le plan pointe la version.
- **Tenant isolation** (blocking) : `/validate` et `/reopen` cross-club → 403.
- **Déblocage du cockpit** : `seasonPlan.hasFinishedVersion` = le plan SEASON porte ≥1 version terminée (`COMPLETED`/`FAILED`). **Dérivé, jamais posé, indépendant du pointeur** — `/reopen` ne re-verrouille pas. Exposé sur `/api/me`. Débloque l'accueil cockpit (vs work-loop). Voir `specs/courantes/accueil-cockpit-temporel.md` §2ter.
- **Rouvrir ne détruit plus rien** : « Rouvrir » un plan de saison répond 409, il n'y a plus de bascule à la réouverture. **Seul le froid supprime** : valider une version du socle dont la copie du froid diffère de celle en vigueur (ou, sans version en vigueur, quand des plans de période existent) détruit les plans de période PAS COMMENCÉS ; une séance déplacée ou un verrou ne supprime jamais rien. `GET /validate-impact` annonce la liste (`changes`, `destroyedPeriodPlans`, `keptPeriodPlans`, `keptWishes`, `coachChanges`, `changesToken`) ; `POST /validate` répond 409 `{code:"changes_to_confirm", …}` si des plannings de période seraient supprimés et que le jeton manque ou diffère, le client rejoue avec `{"confirmChanges": "<jeton>"}`. Chaque période est détruite de bout en bout (`OverlayManager::deletePeriodPlanForEntry` : versions, plan, grille copiée, réglages ancrés) ; l'entrée de calendrier survit et retombe « à traiter ». Pivot = la date de **début** (`startDate > today`, `CalendarEntryRepository::findWithPlanNotStarted`), validé ou non. **La collecte de doléances des vacances à venir n'est plus purgée** : une doléance modifiée avant la dernière validation qui a changé du froid (`schedule_plan.cold_changed_at`) porte la mention « Réponse donnée avant la modification du planning de saison » (`CoachWishResource::answeredBeforeSocleChange`). Les lignes de règle des périodes (commencées comprises) suivent le socle qui entre en vigueur (`PlanComposition::propagateSocleChanges`).
- **« Valider ces modifications », sans régénérer** : un changement fait hors du planning (le coach principal, une règle…) se valide sans régénérer par `POST /api/schedule_plans/{id}/apply-pending-changes` (`App\Plans\PendingChanges::apply`, gestionnaire, plan de saison avec version en vigueur et du froid en attente, jeton exigé). `GET /api/schedule_plans/{id}/pending-impact` annonce ce qui change et, pour un gestionnaire seulement, les incohérences avec le planning en vigueur (`App\Plans\InForceCoherence` : les séances touchées soumises au verdict du moteur, demandes de 50 séances au plus, six au plus ; « non concluant » sinon, jamais bloquant). Le geste écrit la copie du froid, le coach des séances, l'entrée figée et l'empreinte de la version en vigueur, rien d'autre.
- **Les verrous d'une période survivent à « Régénérer » ; « Charger » (V1/V1bis, §3.1bis)** :
  `LoadVersionTest` / `LoadVersionGuardsTest` / `LoadVersionAuditTest` (gardes manager/tenant/terminée/plan non validé/génération en cours/`load_blocked`, effet
  du pointeur et de la copie, re-pointage automatique à la complétion, parité `canLoad` ⇄ verdict) ;
  `ScheduleConstraintBuilderOverlayTest` étendu (la greffe des HARD hérités, forme canonique,
  dédup par placement, un verrou repassé NONE ne revient pas) ; `LockOriginProvenanceTest` étendu
  (un verrou MANUAL hérité ne dégrade jamais en UNKNOWN ; l'origine RESERVATION ne se lit que sur
  les réservations du plan de la séance, règle `backend/docs/generation-flow.md` §5.1) ; `SchedulePlanStructureHashTest` étendu
  (le hash tient le round-trip après une régénération qui fait survivre des verrous) ;
  `OverlayVersionsTest` (parité `isLockSource`/`hardLockCount` de la collection, batch, sans fuite
  inter-club). Feature Behat `un-verrou-est-souverain.feature` : 3 scénarios dédiés — deux
  séances d'un plan de période reviennent verrouillées après régénération ; « Charger » une version
  plus ancienne puis régénérer repart de SES verrous (pas de la plus récente) ; deux régénérations
  successives transportent les verrous de l'une à l'autre.

**Frontend** :
- Toolbar : bouton Valider (`COMPLETED` non choisie) / Retravailler (saison) ou Rouvrir (période) sur la version choisie, read-only gating, libellés statut, badge « Planning de la saison ». Bandeau cockpit : `SeasonPlanBanner`.
- `PlanningToolbar.test.tsx`/`PlanningPage.test.tsx` (V1/V1bis) : le bouton « Charger cette version »
  de PÉRIODE n'apparaît QUE si `canLoad` ; la confirmation de régénération depuis une autre
  version que la source nomme les deux versions et le compte de verrous de la source ; aucune
  régénération directe n'est émise tant que la confirmation n'est pas validée.

