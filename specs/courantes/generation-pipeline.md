# Génération d'un planning — conduite normalisée (bout en bout)

Last verified @ 2026-08-29 (date recalée — le contenu ci-dessous a été écrit le 2026-08-28, le
commit qui le porte (#779) a atterri le lendemain, décalage d'horloge de session sans rapport avec
le contenu. Re-confronté avant de redater : topic
`club:{clubId}:schedule:{scheduleId}` (`backend/src/Mercure/MercureTopic.php:29`)
✓ · verrou par club `ClubGenerationLock::acquire` — `$redis->set(..., ['nx', 'ex' => $ttlSeconds])`
(`backend/src/Service/ClubGenerationLock.php:26`) ✓ · l'abonné Mercure frontend vit toujours à
`frontend/src/features/planning/lib/scheduleStream.ts` ✓. Tout juste, rien à corriger) — *(historique des passes retiré le 2026-08-19,
audit DOC-33 ; il vit dans git : `git log -p --follow specs/courantes/generation-pipeline.md`)*

> Vérité courante. Décrit ce qui **doit** se passer, zone par zone, quand un
> gestionnaire lance une génération : ce que fait le frontend, ce que fait le
> backend, ce que répond le moteur, comment le résultat est importé puis affiché.
> Sert de référence pour diagnostiquer une génération qui « ne s'affiche pas ».
> Détail archi transverse : `CLAUDE.md §6` + `docs/architecture/adr-0001-single-pass-solve.md`.

## 1. Vue d'ensemble (le contrat de bout en bout)

```
Frontend (wizard/planning)                Backend (Symfony)                 Engine (FastAPI/CP-SAT)
──────────────────────────                ─────────────────                ──────────────────────
POST /api/reservations (×N)  ───────────▶ réservations (étape Créneaux, EN AMONT du lancement)
POST /api/schedules (DRAFT)  ───────────▶ crée Schedule (club+season stampés)
POST /api/schedules/{id}/generate ──────▶ GenerateScheduleController
                                          └▶ GenerateScheduleMessage (Messenger/Redis)
                                             └▶ GenerateScheduleHandler
                                                ├─ gèle un snapshot (données figées)
                                                ├─ POST http://engine:8000/generate ─▶ solveur CP-SAT
                                                │                                     ◀─ { status, slots[], diagnostics }
                                                ├─ importe les slots placés
                                                └─ Mercure publish (canal ouvert, AUCUN abonné
                                                   frontend à ce jour — voir §2)
GET /api/schedules/{id} (poll PENDING/GENERATING) ◀───── status COMPLETED/FAILED
Planning : GET /api/schedules (collection)
└▶ atterrissage sur le plan de saison → affichage des créneaux
```

**Frontières (jamais franchies) :** frontend → backend via `/api/*` ; backend → engine
via `POST /generate` ; backend → frontend via Mercure SSE `club:{clubId}:schedule:{scheduleId}`.
**Le moteur ne rappelle jamais le backend. Le frontend n'appelle jamais le moteur.**

## 2. Frontend — ce qu'il fait

- **Lancement** (`features/wizard/queries.ts` → `useLaunchGeneration`) : **deux appels, pas
  plus** — `createSchedule(name, schedulePlanId)` puis `generateSchedule(scheduleId)`. En mode
  période, la version existante du plan est **réutilisée** (`existingScheduleId`) au lieu d'en
  créer une nouvelle. Invalide `["schedules"]` en `onSuccess`.
  ⚠ **Le lancement n'écrit AUCUNE donnée de structure.** Les réservations ont été posées bien
  plus tôt, à l'étape Créneaux du wizard (`POST /api/reservations`) ; les `ScheduleSlotTemplate`
  sont **produits par l'import du résultat**, côté serveur. Il n'existe pas de route
  `/api/slot_templates` — la ressource s'appelle `/api/schedule_slot_templates`.
- **Attente** (`features/wizard/steps/GenerateStep.tsx` + `useScheduleStatus`) : poll
  `GET /api/schedules/{id}` tant que le statut ∈ `{PENDING, GENERATING}`. Garde-fou
  client `TIMEOUT_MS = 5 min` → sinon écran d'échec + réessai.
- **Le frontend CONSOMME désormais Mercure** (FRT-04) — `features/planning/lib/scheduleStream.ts` ouvre
  **UN EventSource par session**, abonné au TEMPLATE du club (`club:{clubId}:schedule:{id}` tel
  quel : le hub matche chaque topic exact contre lui), donc toutes les générations du club
  arrivent sur la même connexion sans connaître leurs ids à l'avance. L'authentification est un
  **cookie httpOnly** posé par `GET /api/mercure/auth` — le JS ne voit jamais le jeton hub — et la
  réponse porte le `topicTemplate`, seule source du clubId côté front (le tenant est résolu
  serveur). ⚠ **Le flux reste BEST-EFFORT** : à réception on **invalide** les caches react-query,
  on ne recopie jamais le payload dedans — le serveur reste la source de vérité — et **le polling
  ne meurt pas, il ralentit** tant que le flux est connecté. C'est donc un accélérateur, pas un
  chemin critique : une génération s'affiche même hub éteint.
- **Affichage** : dès qu'une version existe pour le plan en cours (terminée OU en vol),
  `GenerateStep` bascule sur `<PlanningPage embedded scopePlanId={...} />`
  (`features/wizard/steps/GenerateStep.tsx`). **Règle d'atterrissage ARBITRÉE (fondateur,
  2026-08-19)** : l'écran **EMBARQUÉ** (cette étape) se pose sur la version la **plus RÉCENTE**
  du plan en portée (génération EN VOL comprise) — le gestionnaire doit revoir la génération
  qu'il vient de lancer. Le **POINTEUR** (`seasonPlan.chosenScheduleId`, ADR-0002, via
  `pickLandingScheduleId`) ne l'emporte QUE sur la page `/planning` autonome et au cockpit ; il
  ne pilote **plus** l'atterrissage embarqué (`pickLanding.test.ts` garde le chemin autonome,
  `PlanningPage.test.tsx` l'embarqué — la préférence est dérivée de `embedded` dans
  `PlanningPage.tsx`, pas un one-shot). **En mode saison**, `scopePlanId` est `null` :
  l'embarqué se pose sur la dernière version de saison, l'autonome sur celle que le plan SEASON
  pointe (sinon la dernière terminée). **En mode période**, `scopePlanId` porte l'id du plan de
  période : l'écran embarqué ne connaît QUE les versions de CE plan (atterrissage sur la plus
  récente, titre, toolbar, badge « principal » impossible), et une période sans version affiche
  un état vide **explicite** — jamais un repli sur une version de saison. ⚠ **Corrigé le
  2026-08-19** (bug fondateur) : avant cette date, l'écran embarqué n'avait aucune portée en mode
  période — une sélection laissée par un autre écran du cockpit (ou l'absence de sélection au
  retour sur l'étape) faisait retomber l'affichage sur le plan de saison (titre, badge
  « principal », versions de saison), masquant les versions déjà générées de la période et
  exposant à une double génération. Le **même jour**, la règle d'atterrissage a été arbitrée : la
  retombée « pointeur d'abord » ramenait la V1 POINTÉE (seed BCCL) au lieu de la génération
  fraîche, et le **push one-shot** de `GenerateStep` (supprimé) couplait l'affichage à l'ordre de
  montage — d'où « je ne vois plus MA génération ». En prime, le **verdict d'échec** de l'étape
  Génération se dérive désormais du dernier run FAILED du plan lu de la LISTE (plus du state
  local), donc il survit au retour sur l'étape au lieu de redevenir un lanceur muet. Ceinture
  conservée : les entrées en mode période (`RadarPanel`, `DayDialog`, la reprise overlay de
  `SeasonSchedulesModal`) et les sorties vers la génération de saison (`SeasonPlanBanner`,
  `SeasonSchedulesModal`) purgent la sélection planning au passage. Détail :
  `specs/courantes/etat-des-lieux.md` §3.

## 3. Backend — ce qu'il fait

- `GenerateScheduleController` → `GenerateScheduleMessage` → `GenerateScheduleHandler`
  (Symfony Messenger sur Redis, conteneur `messenger-worker`).
- Le handler : **gèle un snapshot** des données, `POST http://engine:8000/generate`,
  **importe** les slots renvoyés, **publie** sur Mercure. Verrou par club
  `ClubGenerationLock` (Redis `SETEX NX` + jeton de libération).
- Multi-tenant : le Schedule est stampé `club_id` + `season_id` (filtres Doctrine +
  RLS PostgreSQL). Écriture sur saison archivée → 409.

## 4. Engine — ce qu'il répond

- Solveur CP-SAT, **pas de fallback de relaxation** (toutes les contraintes HARD à
  chaque tentative). Réponse conforme au contrat Pydantic (`engine/CONTRACT_VERSION`,
  gardé par `ContractSchemaTest`) : `status`, `slots[]` (placements), `diagnostics[]`.
- INFEASIBLE → `status="failed"` + diagnostics. COMPLETED possible **avec** des
  warnings (un plan sous-optimal reste un plan).
- **Un verrou HARD ne bâillonne plus les contraintes (P2-9 volet 1, 2026-07-28).** Un créneau
  verrouillé HARD est **pré-placé hors du solveur** : la variable de décision correspondante
  n'est jamais créée, donc aucune contrainte ne peut agir dessus — elle n'est pas *battue*, elle
  est **inatteignable**. Le verrou reste **souverain** (décision fondateur, ALIGN-07), mais il
  n'est plus **silencieux** : `diagnose_locked_slot_violations` croise les verrous avec les
  contraintes **saisies par le gestionnaire** (indispo coach, fenêtres horaires et jours,
  gymnase interdit) et émet un diagnostic **`constraint_not_honored`** (sévérité INFO) qui nomme
  l'équipe, le coach, le gymnase et l'heure. Périmètre volontaire : uniquement le saisi — les
  impossibilités **physiques** qu'un verrou contourne aussi (un coach dans deux gymnases à la
  même heure) bloqueront la génération dans un lot dédié, elles ne relèvent pas de l'avertissement.

## 5. Invariants de contrat — les erreurs *silencieuses* à surveiller

Ces invariants ne cassent **pas** un test « le schedule atteint COMPLETED » : le
pipeline réussit, mais l'UI n'affiche rien. Ils exigent des tests dédiés.

### 5.1 `calendarEntryId` : `null` omis par API Platform (régression UX-02)

**API Platform 4 omet les champs `null` du JSON.** Un plan de saison a
`calendarEntryId = null` en base → le champ arrive **ABSENT** côté frontend
(`undefined`), pas `null`. Tout test `null === s.calendarEntryId` (« est-ce un plan
de saison ? ») échoue alors silencieusement : `pickLandingScheduleId` renvoie `null`,
le planning s'ouvre sur rien après une génération réussie.

- **Conduite normalisée** : normaliser à la **frontière**. `listSchedules`
  (`features/planning/api.ts`) mappe les champs nullable (`calendarEntryId`, `score`)
  en `?? null` → le type redevient honnête, **tous** les consommateurs voient un vrai
  `null`. Même piège pour `score` : un plan sans score (DRAFT/en vol) affichait sinon
  le littéral « score undefined ». ⚠ **Amendé 2026-08-01 (P4-39)** : plus aucun écran
  n'affiche le score, donc cette illustration est **historique** — la normalisation, elle,
  reste en place (`planning/api.ts:285`) — non plus pour corriger un affichage, mais pour
  garder le type honnête (`score: number | null`) sur un champ que l'API sert toujours et
  que **plus aucun code frontend ne lit**.
- **Règle générale** : tout champ nullable consommé côté frontend via une comparaison
  `=== null` doit être normalisé à la frontière de son endpoint, ou testé avec un
  check *nullish* (`!x` / `== null`), jamais `=== null` seul.

### 5.2 Autres invariants silencieux (même classe)

- **Contrat backend↔engine** : un champ renommé/retiré ne fait pas planter le solveur,
  il fait perdre un placement. Gardé par `ContractSchemaTest` (`CONTRACT_VERSION`).
- **Snapshot figé** : éditer les données pendant la génération ne doit pas affecter le
  run en cours (le handler travaille sur le snapshot).

## 6. Tests qui détectent ces erreurs silencieuses

| Erreur | Test garde |
|--------|-----------|
| `calendarEntryId` absent (`undefined`) non normalisé | `frontend/src/features/planning/api.test.ts` (mappe absent → `null`) |
| Atterrissage planning cassé (overlay/undefined) | `frontend/src/features/planning/pickLanding.test.ts` |
| Génération qui n'affiche pas le plan (bout en bout) | `frontend/tests/e2e/journey.spec.ts` (wizard → génération réelle → planning affiché → validé → cockpit) |
| Contrat schémas engine⇄backend | `backend` `CrossStack/ContractSchemaTest` |
| Solveur répond et produit un plan | `backend/scripts/smoke-solver.sh` → COMPLETED |
| Verrou HARD qui fait taire une contrainte saisie | `engine/tests/semantic/test_hard_lock_announces_violations.py` (+ `test_constraint_matrix.py`) — un `constraint_not_honored` est attendu |

**Principe** : un test de pipeline qui n'assert que le **statut** (`COMPLETED`) est
aveugle aux erreurs de contrat de *données*. Toujours doubler d'un test qui assert le
**rendu final** (le plan visible), unitaire à la frontière (§6 ligne 1) et e2e (journey).
