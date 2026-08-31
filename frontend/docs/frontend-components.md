# FORWARD Components Spec — Pages & Shared Components (hors wizard)

Last verified @ 2026-08-31 (rotation `documentation-update`, zone non touchée par cette PR —
contrôle de fraîcheur. Re-confronté au code : `SurfaceSkin = "console" | "app"` toujours défini
dans `shared/lib/surfaceSkin.ts` et consommé par `tabs.tsx` (`TAB_SKINS`) ✓ ; `empty-hint.tsx`
porte toujours `EmptyHint`/`EmptyBlock` avec `variant?: SurfaceSkin` par défaut `"app"` ✓ ;
`consolePalette.guard.test.ts` existe bien sous `features/admin/` ✓. Le bloc « Historique »
(sections 2-9) reste superseded, non re-vérifié — il ne prétend à aucune autorité)

> 🛑 **Ce document est SUPERSEDED. Il ne décrit pas le frontend livré.**
>
> Les sections 2 à 9 sont la **spécification forward d'origine** (2026-07-03), écrite
> *avant* le frontend réellement construit. Elles sont conservées pour trace, repliées
> dans un bloc « Historique » — **ne les lisez pas comme un contrat**.
>
> **Où est la vérité :**
>
> | Sujet | Doc canonique |
> |---|---|
> | Routes, state, contrat API, arborescence livrée | [`frontend-spec.md`](frontend-spec.md) §2 / §8 / §9 / §10 |
> | Wizard (6 étapes) + mode période | [`frontend-wizard.md`](frontend-wizard.md) |
> | Cycle de vie du planning (valider / rouvrir / pointeur) | [`planning-lifecycle-validated.md`](../../specs/courantes/planning-lifecycle-validated.md) + ADR-0002 |
> | Cockpit (accueil temporel, radar) | [`accueil-cockpit-temporel.md`](../../specs/courantes/accueil-cockpit-temporel.md) |
> | Module matchs | [`module-matchs.md`](../../specs/courantes/module-matchs.md) |
> | Doléances coachs (#10) — dont la page publique `/doleances/{token}` | [`types-de-planning.md`](../../specs/courantes/types-de-planning.md) §E5 |
> | **Onglets** (`shared/components/ui/tabs.tsx`) — motif WAI-ARIA (roving tabindex, flèches/Home/End), deux peaux : `console` (admin, sombre) et `app` (club, **défaut**). Déplacé de `features/admin/` le 2026-08-01 quand la modale de sollicitation en a eu besoin. ⚠ **Des onglets dans une MODALE demandent deux précautions** (revue #346) : le piège à focus de `useModalA11y` ignore désormais les sous-arbres `hidden` — sans quoi le « dernier » focusable est un bouton du panneau inactif et Tab sort du dialogue — et toute bascule d'onglet PROGRAMMATIQUE doit emporter le focus, sinon il retombe sur `<body>` et le piège comme Échap cessent d'agir. **La peau elle-même vit dans un foyer partagé** (`shared/lib/surfaceSkin.ts`, `SurfaceSkin = "console" \| "app"`, P4-149, 2026-08-30) — elle n'appartient à aucun composant en particulier, `tabs.tsx` la consomme (table locale `TAB_SKINS`) au même titre qu'`empty-hint.tsx` | — |
> | **Palette console** — jetons `--console-*` (`src/index.css`, bloc « Console superadmin », décision UXC-12/P4-151, 2026-08-30). Chaque nuance Tailwind consommée par `features/admin/` a un jeton NOMMÉ par son rôle sémantique, construit par **ALIASING** (`--console-muted: var(--color-slate-500)`) — jamais une valeur `oklch` recopiée à la main, donc un rendu identique par construction — en **BIJECTION** stricte (une nuance Tailwind = un jeton ; deux nuances proches, ex. `emerald-400`/`emerald-500`, ne sont jamais fondues en un seul jeton, ce serait un changement visuel). Cette surface est **hors** du système de thème clair/sombre de l'app plus haut dans le même fichier (`--background`/`--foreground`…) — la console garde une esthétique sombre fixe, décision fermée ([`etat-des-lieux.md`](../../specs/courantes/etat-des-lieux.md) §2). `white`/`black` restent des classes littérales (ancres absolues, hors échelle numérique — même décision fermée). Gardé par `consolePalette.guard.test.ts` (`features/admin/`) : rougit si une classe de palette Tailwind brute réapparaît dans le source du module | — |
> | Console superadmin (`/admin`) | [`superadmin-auth.md`](../../specs/courantes/superadmin-auth.md) |
> | Conventions agent, pièges, primitives partagées | [`../../frontend/AGENTS.md`](../../frontend/AGENTS.md) |
>
> **Principaux écarts** entre les sections historiques et le code : aucune des pages
> qu'elles spécifient n'existe (`/dashboard`, `/teams`, `/priorities`, `/schedules/:id`,
> `/schedules/:id/diagnostics`) ; pas de **FullCalendar** (grille maison `WeekGrid`) ;
> pas de **Zod** (formulaires contrôlés, validation manuelle) ; pas de **Mercure consommé**
> (polling) ; pas de **sidebar** (`AppLayout` = header + menu compte) ; l'édition manuelle
> passe par `POST /api/schedule-slots/{id}/manual-edit/*` et non par un `PUT` de template ;
> le tri par rang vit dans le wizard (mode « Trier » + `POST /api/teams/reorder` en bulk
> atomique). Depuis, sont apparues les routes `/` (cockpit), `/matchs`, `/admin`,
> `/admin/login`, `/confidentialite` et `/doleances/:token`.
>
> Seule la **section 1** ci-dessous (conventions de nommage API) reste valide.

---

## 1. Conventions de nommage API

> **Important :** l'OpenAPI snapshot utilise `snake_case` pour les paths
> multi-mots (ex: `/api/priority_tiers`, `/api/schedule_diagnostics`,
> `/api/schedule_slot_templates`, `/api/sport_categories`,
> `/api/team_coaches`, `/api/venue_training_slots`). Le frontend doit utiliser
> les paths exacts de l'OpenAPI, pas une convention kebab-case dérivée. Les
> query params suivent la même convention (`schedule_id`, `season_id`).

Référence : `specs/courantes/openapi-snapshot.json` — paths des ressources API
Platform + opérations custom déclarées sur les ressources (`/api/schedules/{id}/generate`,
`/api/schedules/{id}/export-pdf`, `/api/clubs/{id}/import-teams`). Les routes
Symfony custom (`/api/me`, `/api/register`, `/api/password/*`, `/api/memberships/*`,
`/api/schedules/{id}/validate|reopen`, `/api/teams/reorder`,
`/api/club/appearance`, `/api/club/logo`, …) n'y figurent **pas** — inventaire
complet dans `backend-inventory.md` §3.

---

<details><summary><b>Historique — spec forward d'origine (sections 2 à 9), superseded, conservée pour trace</b></summary>

> ⚠️ Tout ce qui suit décrit un frontend qui n'a jamais été construit tel quel. Aucune
> affirmation de ce bloc ne fait autorité : voir le tableau « Où est la vérité » en tête.

## 2. Pages

### 2.1 LoginPage

**Route :** `/login` — `AuthLayout` — public

**Objectif :** Connexion du gestionnaire via email + password. Récupère un JWT,
hydrate le `authStore` Zustand, redirige vers `/` qui dispatche vers
`/wizard` (si `onboarding_completed === false`) ou `/dashboard`.

#### Endpoints consommés

| Endpoint | Méthode | Body | Réponse | Action |
|----------|---------|------|---------|--------|
| `/api/login` | POST | `{ email, password }` | `{ token }` (200) | Stocker token en `authStore`, redirect `/` |

> Référence OpenAPI : `specs/courantes/openapi-snapshot.json` path
> `/api/login` (POST). Le endpoint `/api/me` (GET) **existe** côté backend
> (route Symfony custom, `AuthController`) mais n'apparaît pas dans le snapshot
> OpenAPI généré par API Platform — ce n'est pas un gap fonctionnel.

#### Composants

| Composant | Rôle | Props |
|-----------|------|-------|
| `LoginForm` | Formulaire email + password, validation Zod | `onSuccess: (token) => void` |
| `AuthCard` | Wrapper visuel centré (logo + card) | `children` |
| `PasswordInput` | Input password avec toggle visibilité | `label`, `value`, `onChange` |

#### Schéma de validation Zod

```typescript
// Illustration — pas un fichier .ts
const LoginSchema = z.object({
  email: z.string().email("Email invalide"),
  password: z.string().min(8, "Mot de passe : 8 caractères minimum"),
});
```

#### UX

- Card centrée sur fond gradient subtil, logo club en haut
- Champ email avec autocomplete `username`, champ password avec `current-password`
- Toggle visibilité mot de passe (icône `lucide-react` Eye/EyeOff)
- Bouton "Se connecter" en pleine largeur, `aria-disabled` pendant la requête
- Lien "Pas encore de club ? S'inscrire" → `/register`
- Erreur 401 → message `role="alert"` : "Email ou mot de passe incorrect"
- Erreur réseau → message `role="alert"` : "Connexion impossible, réessayez"

### Test Cases — LoginPage

**Given** le gestionnaire "Maxence Dupont" (`maxence.dupont@example.com`) est sur `/login` avec un champ email vide
**When** il clique sur "Se connecter"
**Then** un message d'erreur s'affiche sous le champ email avec `role="alert"` : "Email invalide"
**And** le bouton "Se connecter" reste enabled (validation côté client uniquement)

**Given** le gestionnaire saisit `maxence.dupont@example.com` et le mot de passe `basket2026` (8 caractères)
**When** il clique sur "Se connecter"
**Then** `POST /api/login` est appelé avec `{ email: "maxence.dupont@example.com", password: "basket2026" }`
**And** le bouton passe en `aria-disabled` avec un spinner
**And** sur réponse 200 `{ token: "<jwt>" }`, `authStore.login(token)` est appelé
**And** la redirection se fait vers `/`

**Given** le gestionnaire saisit `maxence.dupont@example.com` et un mot de passe erroné `wrongpass`
**When** `POST /api/login` répond 401
**Then** un message `role="alert"` s'affiche : "Email ou mot de passe incorrect"
**And** le champ password est vidé et reçoit le focus
**And** le bouton "Se connecter" revient à enabled

**Given** le gestionnaire clique sur le toggle visibilité (icône Eye)
**When** l'icône est cliquée
**Then** le type du champ password passe de `password` à `text`
**And** l'icône passe de Eye à EyeOff
**And** `aria-pressed` est mis à jour sur le bouton toggle

---

### 2.2 RegisterPage

**Route :** `/register` — `AuthLayout` — public

**Objectif :** Inscription d'un nouveau club. Crée un User + Club + Season +
Sport + SportCategories en une seule requête. Redirige vers le wizard
d'onboarding après succès.

#### Endpoints consommés

| Endpoint | Méthode | Body | Réponse | Action |
|----------|---------|------|---------|--------|
| `/api/register` | POST | `{ email, password, firstName, lastName, clubName, ara }` | `{ token }` (201) | Stocker token, redirect `/wizard` |

> Le endpoint `/api/register` **existe** côté backend (route Symfony custom,
> `AuthController` — deux modes : nouveau club / adhésion via ARA, voir
> `backend-inventory.md` §3) mais n'apparaît pas dans le snapshot OpenAPI
> généré par API Platform — ce n'est pas un gap fonctionnel.

#### Composants

| Composant | Rôle | Props |
|-----------|------|-------|
| `RegisterForm` | Formulaire multi-champs, validation Zod | `onSuccess: (token) => void` |
| `ClubNameInput` | Input nom du club avec vérification slug auto-généré | `value`, `onChange` |
| `AraCheckbox` | Checkbox "Club agrée FFBB" (code ARA) | `checked`, `onChange` |

#### Schéma de validation Zod

```typescript
// Illustration — pas un fichier .ts
const RegisterSchema = z.object({
  firstName: z.string().min(1, "Prénom requis").max(100),
  lastName: z.string().min(1, "Nom requis").max(100),
  email: z.string().email("Email invalide"),
  password: z.string().min(8, "8 caractères minimum"),
  clubName: z.string().min(1, "Nom du club requis").max(200),
  ara: z.boolean().default(false),
});
```

#### UX

- Même `AuthCard` que LoginPage avec titre "Créer mon club"
- Champs : prénom, nom, email, mot de passe, nom du club, checkbox ARA
- Indicateur de force du mot de passe (barre colorée)
- Lien "Déjà inscrit ? Se connecter" → `/login`
- Sur succès 201 : `authStore.login(token)` + redirect `/wizard` (voir
  `frontend-wizard.md` pour le détail du wizard — non dupliqué ici)

### Test Cases — RegisterPage

**Given** le gestionnaire "Anna Lefevre" (`anna.lefevre@example.com`) est sur `/register` avec tous les champs vides
**When** elle clique sur "Créer mon club"
**Then** des messages `role="alert"` s'affichent sous chaque champ requis vide : "Prénom requis", "Nom requis", "Email invalide", "8 caractères minimum", "Nom du club requis"
**And** le focus se déplace sur le premier champ en erreur (prénom)

**Given** Anna saisit "Anna", "Lefevre", `anna.lefevre@example.com`, le mot de passe `club2026basket`, "Lyon Basket Club" et coche la case ARA
**When** elle clique sur "Créer mon club"
**Then** `POST /api/register` est appelé avec `{ firstName: "Anna", lastName: "Lefevre", email: "anna.lefevre@example.com", password: "club2026basket", clubName: "Lyon Basket Club", ara: true }`
**And** sur réponse 201 `{ token: "<jwt>" }`, `authStore.login(token)` est appelé
**And** la redirection se fait vers `/wizard`

**Given** Anna saisit un mot de passe de 4 caractères `test`
**When** elle quitte le champ password (blur)
**Then** la barre de force affiche 1/4 rouge
**And** un message `role="alert"` s'affiche : "8 caractères minimum"

**Given** Anna a déjà un compte avec l'email `anna.lefevre@example.com`
**When** `POST /api/register` répond 422 avec `{ violations: [{ propertyPath: "email", message: "Cet email est déjà utilisé" }] }`
**Then** un message `role="alert"` s'affiche sous le champ email : "Cet email est déjà utilisé"
**And** le champ email reçoit le focus

---

### 2.3 ScheduleViewPage

**Route :** `/schedules/:id` — `AppLayout` — auth required

**Objectif :** Visualisation du planning hebdomadaire type via FullCalendar 6.
Affiche les créneaux (ScheduleSlotTemplate) colorés par tier. Permet la
génération asynchrone, l'export PDF, et l'édition manuelle (drag-and-drop +
dialogue post-modification).

#### Endpoints consommés

| Endpoint | Méthode | Body | Réponse | Action |
|----------|---------|------|---------|--------|
| `/api/schedules/{id}` | GET | — | `Schedule` (200) | Afficher statut, score, métadonnées |
| `/api/schedule_slot_templates?schedule_id={id}` | GET | — | `HydraCollection<ScheduleSlotTemplate>` (200) | Afficher créneaux dans FullCalendar |
| `/api/schedules/{id}/generate` | POST | — | 202 | Lancer génération asynchrone, spinner |
| `/api/schedules/{id}/export-pdf` | POST | — | 202 | Lancer export PDF, statut via SSE |
| `/api/schedule_slot_templates/{id}` | PUT | `{ lockLevel, ... }` | 200 | Édition manuelle (lock, contrainte) |

> Référence OpenAPI : paths `/api/schedules/{id}`, `/api/schedules/{id}/generate`
> (POST), `/api/schedules/{id}/export-pdf` (POST), `/api/schedule_slot_templates`
> (GET, POST), `/api/schedule_slot_templates/{id}` (PUT). Les sous-routes
> `manual-edit/*` référencées dans `frontend-spec.md` §9 n'apparaissent pas dans
> l'OpenAPI — l'édition manuelle passe par `PUT /api/schedule_slot_templates/{id}`
> avec les champs `lockLevel`, `temporaryLock`, `temporaryLockFor`.

#### Schémas OpenAPI pertinents

```
ScheduleSlotTemplate:
  id, scheduleId, teamId, venueId, coachId,
  dayOfWeek (integer, default 0),
  startTime, durationMinutes,
  lockLevel (enum: NONE | SOFT | HARD),
  temporaryLock (boolean),
  temporaryLockFor (string | null),
  temporaryMinSessionsOverride,
  pendingConstraintSuggestion

Schedule:
  id, name, status (enum: DRAFT | PENDING | GENERATING | COMPLETED | FAILED),
  isChosen (bool — le plan de cette version la pointe = « validée », ADR-0002),
  score, solverSeed, pdfExportStatus, pdfExportUrl, pngExportUrl,
  solverWallTimeMs, solverNbVariables, solverNbConstraints
```

#### Composants

| Composant | Rôle | Props clés |
|-----------|------|------------|
| `ScheduleCalendar` | Wrapper FullCalendar `timeGridWeek`, lun-sam, créneaux colorés par tier | `scheduleId`, `slots`, `onSlotClick`, `onSlotDrop` |
| `ScheduleHeader` | Titre schedule, statut badge, score, boutons generate/export | `schedule`, `onGenerate`, `onExport` |
| `SlotDetailPanel` | Panel latéral droit : détails créneau (équipe, coach, salle, lock_level) | `slot`, `onClose`, `onLockChange` |
| `GenerateButton` | Bouton "Générer" avec spinner + statut SSE temps réel | `scheduleId`, `status` |
| `ExportPdfButton` | Bouton "Export PDF" avec statut asynchrone + timeout 60s | `scheduleId`, `pdfExportStatus` |
| `PostEditDialog` | Dialogue post-modification : contrainte permanente / lock / ponctuel | `slot`, `onConfirm`, `onCancel` |
| `ScheduleErrorBoundary` | Error boundary React : message + bouton "Réessayer" | `children` |

#### Flux de génération asynchrone

1. Click "Générer" → `POST /api/schedules/{id}/generate` (202)
2. `Schedule.status` passe à `GENERATING` (via SSE Mercure)
3. `GenerateButton` affiche spinner "Génération en cours"
4. SSE événement `{ status: 'done', score, unplaced, warnings }` → `invalidateQueries(['schedules', id])` + `invalidateQueries(['schedule_slot_templates', id])`
5. Re-fetch affiche le planning généré + score
6. Si `{ status: 'failed' }` → afficher erreur + lien vers diagnostics

> Référence SSE : `frontend-spec.md` §5 — topic `club:{clubId}:schedule:{scheduleId}`.

#### Flux d'édition manuelle (drag-and-drop)

1. Gestionnaire drag un créneau dans FullCalendar
2. UI se met à jour immédiatement (optimistic update)
3. `PUT /api/schedule_slot_templates/{id}` avec les champs modifiés (`dayOfWeek`, `startTime`)
4. Si 409 (conflit) → rollback + message "Ce créneau est en conflit"
5. Si succès → `PostEditDialog` s'ouvre : "Créer contrainte permanente" / "Verrouiller (SOFT/HARD)" / "Juste ponctuel"
6. Choix "Verrouiller" → `PUT /api/schedule_slot_templates/{id}` avec `{ lockLevel: 'SOFT' | 'HARD' }`
7. Choix "Ponctuel" → `PUT` avec `{ temporaryLock: true, temporaryLockFor: '<date>' }`

#### UX

- FullCalendar `timeGridWeek` : 6 colonnes (lun-sam), axe vertical 08:00-22:00
- Créneaux colorés par tier : S=rouge, A=orange, B=bleu, C=vert, D=gris
- Click sur créneau → `SlotDetailPanel` s'ouvre à droite (animation slide)
- Drag-and-drop activé en mode édition (toggle bouton "Éditer")
- Badge statut schedule : `DRAFT` (gris), `PENDING` (jaune), `GENERATING` (bleu + spinner), `COMPLETED` (vert), `FAILED` (rouge)
- Score affiché en haut à droite : "Score: 42" (si `COMPLETED`)
- Skeleton loader pendant le chargement initial (pas de spinner vide)

### Test Cases — ScheduleViewPage

**Given** le gestionnaire est sur `/schedules/abc-123` et le schedule a le statut `DRAFT` avec zéro créneau
**When** la page se charge
**Then** `GET /api/schedules/abc-123` retourne `{ id: "abc-123", status: "DRAFT", name: "Planning U13 2026" }`
**And** `GET /api/schedule_slot_templates?schedule_id=abc-123` retourne `{ "hydra:member": [], "hydra:totalItems": 0 }`
**And** le calendrier FullCalendar s'affiche avec 6 colonnes vides (lun-sam)
**And** le badge statut affiche "Brouillon" en gris
**And** le bouton "Générer" est visible et enabled

**Given** le schedule `abc-123` a le statut `COMPLETED` avec 12 créneaux et un score de 42
**When** la page se charge
**Then** 12 créneaux s'affichent dans FullCalendar, colorés par tier (S=rouge, A=orange, B=bleu, C=vert, D=gris)
**And** le badge statut affiche "Terminé" en vert
**And** le score "Score: 42" s'affiche en haut à droite
**And** le bouton "Export PDF" est visible

**Given** le gestionnaire clique sur "Générer" sur le schedule `abc-123` en statut `DRAFT`
**When** `POST /api/schedules/abc-123/generate` répond 202
**Then** le badge statut passe à "Génération en cours" avec un spinner bleu
**And** le bouton "Générer" passe en `aria-disabled`
**And** la connexion SSE s'ouvre sur `/.well-known/mercure?topic=club:{clubId}:schedule:abc-123`

**Given** le SSE émet `{ status: "done", score: 38, unplaced: 1, warnings: 3 }` pour le schedule `abc-123`
**When** l'événement est reçu
**Then** `invalidateQueries(['schedules', 'abc-123'])` est appelé
**And** `invalidateQueries(['schedule_slot_templates', 'abc-123'])` est appelé
**And** le badge statut passe à "Terminé" en vert
**And** le score "Score: 38" s'affiche
**And** un message de succès s'affiche : "Planning généré — 1 créneau non placé, 3 avertissements"

**Given** le gestionnaire drag le créneau de "U13 Masculin" du lundi 18:00 au mardi 19:00 en mode édition
**When** il lâche le créneau (drop)
**Then** l'UI se met à jour immédiatement (optimistic) — le créneau apparaît le mardi 19:00
**And** `PUT /api/schedule_slot_templates/{slotId}` est appelé avec `{ dayOfWeek: 2, startTime: "19:00" }`
**And** sur succès 200, le `PostEditDialog` s'ouvre avec 3 options : "Créer contrainte permanente", "Verrouiller", "Juste ponctuel"

**Given** le `PostEditDialog` est ouvert après déplacement du créneau "U13 Masculin"
**When** le gestionnaire sélectionne "Verrouiller" et choisit `SOFT`
**Then** `PUT /api/schedule_slot_templates/{slotId}` est appelé avec `{ lockLevel: "SOFT" }`
**And** le créneau affiche une icône cadenas SOFT (bleu) dans FullCalendar
**And** le dialogue se ferme

**Given** le gestionnaire clique sur le créneau "U13 Masculin" (lundi 18:00, tier B, coach Maxence Dupont, salle Gymnase A)
**When** le clic est détecté
**Then** le `SlotDetailPanel` s'ouvre à droite avec : Équipe "U13 Masculin", Coach "Maxence Dupont", Salle "Gymnase A", Tier "B" (badge bleu), Lock "NONE" (pas de cadenas)
**And** le panel a `role="region"` et `aria-label="Détails du créneau"`

**Given** le gestionnaire clique sur "Export PDF" pour le schedule `abc-123` en statut `COMPLETED`
**When** `POST /api/schedules/abc-123/export-pdf` répond 202
**Then** le bouton "Export PDF" affiche un spinner "Génération PDF en cours"
**And** un timeout UX de 60s démarre
**And** si l'événement SSE `{ status: "pdf_ready", url: "..." }` arrive avant 60s, le bouton devient "Télécharger le PDF"
**And** si le timeout expire, un message s'affiche : "Le PDF est encore en préparation, revenez plus tard"

---

### 2.4 DiagnosticsPage

**Route :** `/schedules/:id/diagnostics` — `AppLayout` — auth required

**Objectif :** Rapport post-génération affichant les `schedule_diagnostics` avec
regroupement par severity, messages en langage gestionnaire, et liens vers les
entités à corriger.

#### Endpoints consommés

| Endpoint | Méthode | Query params | Réponse | Action |
|----------|---------|--------------|---------|--------|
| `/api/schedule_diagnostics` | GET | `schedule_id={id}` | `HydraCollection<ScheduleDiagnostic>` (200) | Afficher rapport |

> Référence OpenAPI : path `/api/schedule_diagnostics` (GET, POST). Le query
> param `schedule_id` filtre par schedule. Le filtrage par `schedule_id` est
> géré par API Platform via le système de filtres Doctrine.

#### Schéma OpenAPI pertinent

```
ScheduleDiagnostic:
  id, scheduleId, type (string),
  severity (enum: ERROR | WARNING | INFO | SUCCESS),
  teamId, coachId, venueId,
  message (string),
  suggestions (string | null)
```

#### Composants

| Composant | Rôle | Props clés |
|-----------|------|------------|
| `DiagnosticsReport` | Conteneur principal : filtre severity + liste groupée | `scheduleId` |
| `DiagnosticItem` | Item individuel : icône severity + message + lien entité | `diagnostic`, `onNavigate` |
| `SeverityFilter` | Filtre par severity (ERROR / WARNING / INFO / ALL) | `value`, `onChange` |
| `DiagnosticSummary` | Résumé en haut : compteurs par severity | `diagnostics` |

#### UX

- Résumé en haut : 4 compteurs (ERROR rouge, WARNING orange, INFO bleu, SUCCESS vert)
- Filtre severity : tabs `ALL` | `ERROR` | `WARNING` | `INFO`
- Liste groupée par severity (ERROR en premier, puis WARNING, puis INFO)
- Chaque `DiagnosticItem` affiche :
  - Icône severity (AlertCircle / AlertTriangle / Info / CheckCircle)
  - Message tel que rédigé côté backend (langage gestionnaire)
  - Lien vers l'entité : "Voir l'équipe" → `/teams?focus={teamId}`
  - Suggestions si présentes (`diagnostic.suggestions`)
- Pas d'auto-correction MVP — l'utilisateur clique → navigue vers l'entité
- État vide : "Aucun diagnostic — le planning est optimal" avec icône CheckCircle
- Skeleton loader pendant le chargement

### Test Cases — DiagnosticsPage

**Given** le schedule `abc-123` a été généré avec 2 erreurs, 3 avertissements et 1 info
**When** le gestionnaire ouvre `/schedules/abc-123/diagnostics`
**Then** `GET /api/schedule_diagnostics?schedule_id=abc-123` retourne 6 diagnostics
**And** le `DiagnosticSummary` affiche : ERROR: 2 (rouge), WARNING: 3 (orange), INFO: 1 (bleu), SUCCESS: 0
**And** la liste affiche les 2 erreurs en premier, puis les 3 avertissements, puis 1 info

**Given** le diagnostic `{ severity: "ERROR", message: "L'équipe U13 Masculin n'a pas assez de créneaux (2/3)", teamId: "team-456", suggestions: "Ajoutez une salle le mercredi ou réduisez le min_sessions à 2" }` est affiché
**When** le gestionnaire clique sur "Voir l'équipe"
**Then** la navigation se fait vers `/teams?focus=team-456`
**And** l'équipe "U13 Masculin" est mise en évidence dans la liste

**Given** le gestionnaire clique sur le filtre "WARNING"
**When** le filtre s'active
**Then** seuls les 3 diagnostics de severity `WARNING` s'affichent
**And** le tab "WARNING" a `aria-selected="true"`
**And** les autres tabs ont `aria-selected="false"`

**Given** le schedule `abc-123` a été généré sans erreur ni avertissement
**When** le gestionnaire ouvre `/schedules/abc-123/diagnostics`
**Then** `GET /api/schedule_diagnostics?schedule_id=abc-123` retourne `{ "hydra:member": [], "hydra:totalItems": 0 }`
**And** un message s'affiche : "Aucun diagnostic — le planning est optimal" avec une icône CheckCircle verte

---

### 2.5 TierListPage

**Route :** `/priorities` — `AppLayout` — auth required

**Objectif :** Tier list drag & drop pour prioriser les équipes en S/A/B/C/D.
Sauvegarde automatique au drop via `PUT /api/teams/{id}` avec
`priority_tier_id`. Affiche le compteur de sessions min par tier.

#### Endpoints consommés

| Endpoint | Méthode | Query params | Réponse | Action |
|----------|---------|--------------|---------|--------|
| `/api/priority_tiers` | GET | — | `HydraCollection<PriorityTier>` (200) | Résoudre UUIDs des tiers S/A/B/C/D |
| `/api/teams` | GET | `season_id={id}` | `HydraCollection<Team>` (200) | Afficher équipes dans les colonnes |
| `/api/teams/{id}` | PUT | — | `Team` (200) | Mettre à jour `priorityTierId` au drop |

> Référence OpenAPI : paths `/api/priority_tiers` (GET), `/api/teams` (GET),
> `/api/teams/{id}` (PUT). Le schéma `PriorityTier` expose `label`, `name`,
> `color`, `orToolsWeight`, `defaultMinSessions`. Le schéma `Team` expose
> `priorityTierId`, `name`, `sessionsPerWeek`, `minSessionsOverride`.

#### Schémas OpenAPI pertinents

```
PriorityTier:
  id, label, name, color, orToolsWeight, defaultMinSessions

Team:
  id, sportCategoryId, priorityTierId, name, gender, level,
  sessionsPerWeek, minSessionsOverride, matchDay,
  forcedVenueId, isActive
  # level = TeamLevel (Élite..Loisir) — lu+écrit depuis le wizard (PR #35)
```

#### Composants

| Composant | Rôle | Props clés |
|-----------|------|------------|
| `TierBoard` | Conteneur principal : 5 colonnes S/A/B/C/D | `tiers`, `teams`, `onDrop` |
| `TierColumn` | Colonne individuelle avec header coloré + liste équipes | `tier`, `teams`, `onDrop` |
| `TierCard` | Carte équipe draggable (@dnd-kit) | `team`, `isDragging` |
| `TierSummary` | Résumé : compteurs par tier + total sessions min | `tiers`, `teams` |

#### UX

- 5 colonnes côte à côte : S (rouge), A (orange), B (bleu), C (vert), D (gris)
- Chaque colonne a un header avec le label du tier et le `defaultMinSessions`
- Les équipes apparaissent sous forme de cartes draggables dans leur colonne
- `@dnd-kit` pour le drag-and-drop entre colonnes
- Au drop : `PUT /api/teams/{id}` avec `{ priorityTierId: "<uuid-tier>" }`
- Sauvegarde automatique au drop (pas de bouton "Enregistrer")
- `TierSummary` en bas : "S: 2 équipes (6 sessions), A: 4 (8), B: 8 (8), C: 5 (5), D: 3 (3)"
- Couleurs cohérentes avec le planning (même palette tier)
- Animation fluide au drag (transform CSS, pas de re-render FullCalendar)

### Test Cases — TierListPage

**Given** le gestionnaire est sur `/priorities` avec 12 équipes réparties : S=0, A=2, B=5, C=3, D=2
**When** la page se charge
**Then** `GET /api/priority_tiers` retourne 5 tiers avec `{ name: "S", color: "#ef4444", defaultMinSessions: 3 }`, etc.
**And** `GET /api/teams?season_id={id}` retourne 12 équipes
**And** 5 colonnes s'affichent avec les équipes dans leurs colonnes respectives
**And** le `TierSummary` affiche : "S: 0 (0), A: 2 (4), B: 5 (5), C: 3 (3), D: 2 (2)"

**Given** le gestionnaire glisse l'équipe "U13 Masculin" de la colonne D vers la colonne B
**When** il lâche la carte (drop)
**Then** l'UI se met à jour immédiatement (optimistic) — "U13 Masculin" apparaît dans la colonne B
**And** `PUT /api/teams/{teamId}` est appelé avec `{ priorityTierId: "<uuid-tier-B>" }`
**And** sur succès 200, la carte reste dans la colonne B
**And** le `TierSummary` met à jour les compteurs : D=1, B=6

**Given** le gestionnaire glisse "U13 Masculin" vers la colonne B mais la requête `PUT /api/teams/{id}` échoue (500)
**When** la réponse d'erreur arrive
**Then** la carte "U13 Masculin" revient à la colonne D (rollback)
**And** un message `role="alert"` s'affiche : "Erreur lors de la mise à jour de la priorité"
**And** la carte a une animation de shake (feedback visuel d'échec)

**Given** le gestionnaire utilise le clavier (navigation accessible @dnd-kit) et focus sur "U15 Féminin" dans la colonne C
**When** il appuie sur `ArrowRight`
**Then** "U15 Féminin" se déplace visuellement vers la colonne D
**And** `aria-live="polite"` annonce "U15 Féminin déplacé vers tier D"
**And** `PUT /api/teams/{id}` est appelé avec `{ priorityTierId: "<uuid-tier-D>" }`

---

## 3. Shared Components

Composants UI réutilisables transversaux à toutes les pages. Ils vivent dans
`frontend/src/shared/ui/` et ne contiennent aucune logique métier — seulement
de la présentation + accessibilité.

| Composant | Rôle | Props clés | Utilisé par |
|-----------|------|------------|-------------|
| `Button` | Bouton avec variants + sizes. **Désactivé, il INFORME** (P4-127 e) : plus de `pointer-events-none` — les `title=` d'explication vivent, curseur `not-allowed`, survols bornés aux boutons actifs (`hover:enabled:`) ; la doctrine « raison en clair à côté » reste la norme pour les cas importants | `variant`, `size`, `disabled`, `children` | Toutes les pages |
| `Input` | Input texte avec label, erreur, hint | `label`, `error`, `hint`, `type`, `value`, `onChange` | LoginForm, RegisterForm, tous formulaires |
| `Select` | Dropdown natif stylé + label | `label`, `options`, `value`, `onChange` | ScheduleViewPage (filtres), DiagnosticsPage |
| `Badge` | Badge coloré (statut, tier, severity) | `color`, `children`, `icon` | ScheduleHeader, TierColumn, DiagnosticItem |
| `Spinner` | Spinner de chargement accessible | `size`, `label` | GenerateButton, ExportPdfButton, tous loaders |
| `Skeleton` | Skeleton loader pour chargement initial | `lines`, `width`, `height` | ScheduleViewPage, DiagnosticsPage, TierListPage |
| `ErrorBoundary` | Error boundary React avec message + "Réessayer" | `children`, `onRetry` | Toutes les pages (wrap de contenu) |
| `EmptyState` / `EmptyBlock` / `EmptyHint` | Les TROIS étages du vide, une seule maison (`empty-hint.tsx`, UXC-17). **Règle de choix** (UXC-10, tranchée en ralliant les sites inline) : une **vue entière** sans rien à montrer → `EmptyState` (Card pointillée) ; une **grille/panneau** vide dans un écran par ailleurs peuplé → `EmptyBlock` (bloc pointillé) ; une **liste/résultat de filtre** vide, en ligne dans le flux → `EmptyHint` (paragraphe discret). `EmptyBlock`/`EmptyHint` portent une prop `variant` (`SurfaceSkin`, P4-149, 2026-08-30) : `app` (jetons de thème, **défaut**) ou `console` (jetons `--console-*`) — même patron que les onglets | `EmptyState` : `icon` (défaut `CalendarX2`), `title`, `description` — pas de prop `action` (l'ancienne ligne en promettait une qui n'a jamais existé) ; `EmptyBlock`/`EmptyHint` : `children`, `className`, `variant` (`SurfaceSkin`, défaut `app`) | PlanningPage (State) · grilles horaires (Block) · la plupart des listes/filtres vides (Hint) — exceptions structurelles (balisage `<li>`/`<ul>`, valeur italique porteuse de sens, espacement centré délibéré) : décision fermée `specs/courantes/etat-des-lieux.md` §2. `features/admin/` consomme `variant="console"` sur une partie de ses empty states — reste ouvert : `roadmap.md` P4-149 |
| `Menu` / `MenuItem` | Dropdown accessible (burger, motif APG menu-button) — focus au 1er item à l'ouverture, flèches ↑/↓ (roving), Esc/Tab ferment + rendent le focus au déclencheur, clic-dehors, `z-50` au-dessus du plein écran wizard, sans dépendance | `label`, `trigger`, `children` / `onSelect` \| `to` (NavLink, état actif), `icon` | AppLayout (menu compte : Club · Profil · Thème · Logout) |
| `AccordionSection` | Section dépliable (`aria-expanded`/`aria-controls`, chevron) | `title`, `defaultOpen`, `children` | ClubPage (sections Demandes / Visuel) |
| `Modal` | Modal accessible (focus trap, Escape, backdrop), **hauteur bornée + contenu défilant**, **largeur par palier nommé**, **pied d'actions ÉPINGLÉ hors défilement** (P4-127 d — la règle de partage : le pied reçoit les actions et le microcopy qui les qualifie, les conséquences restent dans le corps) | `label`, `title`, `onClose`, `children`, `footer`, `size` (`sm`\|`md`\|`lg`\|`xl`, défaut `md`) | cockpit, wizard, matchs, planning, admin |
| `FichePage` | Le cadre des pages « fiche » : 832 px centrés + paragraphes bornés à la longueur de ligne lisible | `className` (rythme vertical de la page), `children` | ClubPage, ProfilePage, ReleaseNotesPage |
| `WarningPanel` | L'encart d'AVERTISSEMENT — un fait qui limite ce qui est possible ici (semaines sous vacances, fenêtre déjà planifiée). **Une seule boîte** depuis P4-127 : trois sites la re-déclaraient avec deux bordures différentes, deux d'entre eux côte à côte dans la même modale. ⚠ Ce n'est **pas** une alerte d'erreur : ni `role="alert"` ni `aria-live` — quand le contenu arrive de façon asynchrone, c'est le CONTENEUR de l'appelant qui porte la région live, pour annoncer une fois et non une fois par encart | `icon` (décoratif — le texte porte seul le sens), `message`, `children` (l'action, rendue en FRÈRE du paragraphe : un `<div>` dans un `<p>` est invalide), `className` | `WindowAlreadyPlannedNotice`, `WeekPickerDialog`, `DayDialog` |
| `Toast` | Notification temporaire (succès, erreur, info) | `variant`, `message`, `duration` | Toutes les pages (mutations) |
| `ConfirmDialog` | Dialogue de confirmation (action destructive) — panneau jumeau de `Modal`, **largeur lue dans `MODAL_WIDTH.sm`** | `open`, `title`, `description`, `confirmLabel`, `confirmPhrase`, `onConfirm`, `onCancel` | Suppression d'équipe, reset club |

### Règles shared components

- **Aucune logique métier.** Pas de `useQuery`, pas de `useMutation`, pas de
  `useAuthStore` dans les composants shared. Toute logique vit dans les pages
  ou les `features/`.
- **Tous les composants sont typés.** Props en TypeScript `interface`, pas
  de `any`.
- **Variants via `clsx`.** Pas de CSS-in-JS. Les variants sont des classes
  Tailwind conditionnelles.
- **Accessibilité native.** `Button` rend un `<button>`, `Input` rend un
  `<label>` + `<input>`, `Modal` gère le focus trap et `Escape`.

### Test Cases — Shared Components

**Given** le composant `Button` est rendu avec `variant="danger"` et `loading={true}`
**When** il s'affiche
**Then** le bouton a la classe Tailwind `bg-red-600 text-white`
**And** un `Spinner` s'affiche à la place du texte
**And** `aria-disabled="true"` est présent
**And** le bouton n'est pas focusable au clic (disabled)

**Given** le composant `Modal` est ouvert avec un formulaire à l'intérieur
**When** l'utilisateur appuie sur `Tab` plusieurs fois
**Then** le focus reste piégé dans le modal (ne sort pas vers l'arrière-plan)
**And** le premier élément focusable reçoit le focus à l'ouverture
**And** `aria-modal="true"` et `role="dialog"` sont présents

**Given** le composant `Toast` est déclenché avec `{ variant: "success", message: "Priorité mise à jour", duration: 3000 }`
**When** le toast s'affiche
**Then** il apparaît en bas à droite avec un fond vert et une icône CheckCircle
**And** `role="status"` est présent
**And** après 3000ms, le toast disparaît avec une animation fade-out

**Given** le composant `ErrorBoundary` wrappe le `ScheduleCalendar` et une erreur se produit dans FullCalendar
**When** l'erreur est captée par la boundary
**Then** un message s'affiche : "Une erreur est survenue lors du chargement du planning"
**And** un bouton "Réessayer" est présent et appelle `onRetry` au clic
**And** l'erreur est loggée en console (pas envoyée à un service externe en MVP)

---

## 4. Layout

Deux layouts principaux + un layout wizard (non détaillé ici, voir
`frontend-wizard.md`).

### 4.1 AuthLayout

**Routes :** `/login`, `/register`

Layout minimal pour les pages d'authentification. Pas de navigation, pas de
sidebar.

```
┌─────────────────────────────────────┐
│                                     │
│      [Icône + nom produit]          │
│                                     │
│      ┌─────────────────────┐        │
│      │                     │        │
│      │   AuthCard (children)│       │
│      │                     │        │
│      └─────────────────────┘        │
│                                     │
│    [Lien switch login/register]     │
│                                     │
└─────────────────────────────────────┘
```

| Élément | Rôle | Détail |
|---------|------|--------|
| `AuthLayout` | Wrapper plein écran, fond gradient subtil | `<main role="main">` |
| `AuthCard` | Card centrée, max-width 480px | `<div>` avec ombre légère |
| Marque | Icône `CalendarCheck2` + nom produit en haut | `<span>{PRODUCT_NAME}</span>` (« Amateo », `shared/lib/product.ts` — pas un `<img>`) |

### 4.2 AppLayout

**Routes :** `/dashboard`, `/schedules/:id`, `/schedules/:id/diagnostics`,
`/teams`, `/priorities`, `/profile`

Layout principal de l'application. Sidebar navigation + topbar + contenu.

```
┌──────────┬──────────────────────────────┐
│          │  TopBar (club name, user)    │
│  Sidebar ├──────────────────────────────┤
│          │                              │
│  - Dash  │                              │
│  - Plan  │     Content (children)       │
│  - Équip │                              │
│  - Prio  │                              │
│  - Profil│                              │
│          │                              │
└──────────┴──────────────────────────────┘
```

| Élément | Rôle | Détail |
|---------|------|--------|
| `AppLayout` | Wrapper flex, sidebar fixe + contenu scrollable | `<div>` |
| `Sidebar` | Navigation principale, collapsible | `<nav role="navigation" aria-label="Navigation principale">` |
| `SidebarItem` | Item de navigation avec icône + label | `<a>` avec `aria-current="page"` si actif |
| `TopBar` | Barre supérieure : nom du club, avatar user, logout | `<header role="banner">` |
| `ContentArea` | Zone de contenu, scrollable | `<main role="main" id="main-content">` |

#### Navigation sidebar

| Item | Icône lucide-react | Route | `aria-current` |
|------|-------------------|-------|----------------|
| Tableau de bord | `LayoutDashboard` | `/dashboard` | `page` si actif |
| Planning | `Calendar` | `/schedules/{activeId}` | `page` si actif |
| Équipes | `Users` | `/teams` | `page` si actif |
| Priorités | `Trophy` | `/priorities` | `page` si actif |
| Profil | `Settings` | `/profile` | `page` si actif |

#### Skip link

```html
<!-- Premier élément du DOM dans AppLayout -->
<a href="#main-content" class="sr-only focus:not-sr-only">
  Aller au contenu principal
</a>
```

### Test Cases — Layout

**Given** le gestionnaire est connecté et sur `/dashboard`
**When** la page se charge avec `AppLayout`
**Then** la sidebar affiche 5 items de navigation avec icônes
**And** l'item "Tableau de bord" a `aria-current="page"`
**And** les autres items n'ont pas `aria-current`
**And** un skip link "Aller au contenu principal" est présent en premier élément du DOM

**Given** le gestionnaire est sur `/priorities` et la sidebar est en mode collapsible
**When** il clique sur le bouton de collapse de la sidebar
**Then** la sidebar se réduit à une largeur de 64px (icônes uniquement)
**And** les labels disparaissent avec `aria-hidden="true"`
**And** `uiStore.sidebarOpen` passe à `false`
**And** le contenu s'élargit pour remplir l'espace

**Given** le gestionnaire utilise un lecteur d'écran et navigue au clavier
**When** il appuie sur `Tab` en arrivant sur la page
**Then** le skip link "Aller au contenu principal" reçoit le focus en premier
**And** s'il appuie sur `Enter`, le focus se déplace sur `<main id="main-content">`

---

## 5. ARIA / Accessibility

### Structure ARIA par page

| Page | Élément | Attribut ARIA | Valeur |
|------|---------|---------------|--------|
| LoginPage | Formulaire | `role="form"` `aria-label="Connexion"` | — |
| LoginPage | Message d'erreur | `role="alert"` | Annonce auto |
| RegisterPage | Formulaire | `role="form"` `aria-label="Inscription club"` | — |
| RegisterPage | Barre de force password | `role="progressbar"` `aria-valuenow` `aria-valuemin` `aria-valuemax` | 0-4 |
| ScheduleViewPage | Calendrier | `role="grid"` `aria-label="Planning hebdomadaire"` | — |
| ScheduleViewPage | Créneau | `role="gridcell"` `aria-label="{équipe} {jour} {heure}"` | — |
| ScheduleViewPage | Panel détails | `role="region"` `aria-label="Détails du créneau"` | — |
| ScheduleViewPage | Bouton "Générer" | `aria-disabled` pendant génération | `true`/`false` |
| DiagnosticsPage | Liste diagnostics | `role="list"` `aria-label="Diagnostics"` | — |
| DiagnosticsPage | Item diagnostic | `role="listitem"` | — |
| DiagnosticsPage | Filtre severity | `role="tablist"` + `role="tab"` + `aria-selected` | — |
| TierListPage | Board | `role="list"` `aria-label="Tier list par priorité"` | — |
| TierListPage | Colonne tier | `role="group"` `aria-label="Tier {S/A/B/C/D}"` | — |
| TierListPage | Carte équipe | `role="listitem"` `aria-grabbed` pendant drag | — |

### Focus management

1. **Changement de page** (React Router) : le focus se déplace sur `<h1>` de
   la nouvelle page. Le skip link est le premier élément focusable.
2. **Ouverture de panel** (SlotDetailPanel) : le focus se déplace sur le titre
   du panel. `Escape` ferme le panel et restore le focus sur le créneau cliqué.
3. **Ouverture de modal** (PostEditDialog, ConfirmDialog) : focus trap dans le
   modal. `Escape` ferme et restore le focus sur l'élément déclencheur.
4. **Toast notification** : `role="status"` — pas de déplacement de focus,
   annonce polie par le lecteur d'écran.
5. **Erreur de formulaire** : le focus se déplace sur le premier champ en
   erreur. Le message d'erreur a `role="alert"`.

### Keyboard navigation

| Touche | Contexte | Action |
|--------|----------|--------|
| `Tab` / `Shift+Tab` | Global | Navigation séquentielle |
| `Enter` | Bouton, lien | Activation |
| `Escape` | Modal, panel ouvert | Ferme + restore focus |
| `ArrowUp` / `ArrowDown` | TierListPage (carte focus) | Déplace entre tiers |
| `ArrowLeft` / `ArrowRight` | TierListPage (carte focus) | Déplace vers tier adjacent |
| `Space` | TierListPage (carte focus) | Démarre le drag @dnd-kit |

### Test Cases — ARIA/Accessibility

**Given** le gestionnaire est sur `/schedules/abc-123` avec un lecteur d'écran actif
**When** le calendrier FullCalendar se charge
**Then** le conteneur du calendrier a `role="grid"` et `aria-label="Planning hebdomadaire"`
**And** chaque créneau a `role="gridcell"` avec un `aria-label` descriptif : "U13 Masculin, lundi 18:00, tier B"
**And** le lecteur d'écran annonce les créneaux lors de la navigation

**Given** le gestionnaire est sur `/priorities` et navigue au clavier
**When** il tab jusqu'à la carte "U15 Féminin" dans la colonne C et appuie sur `ArrowRight`
**Then** la carte se déplace visuellement vers la colonne D
**And** `aria-live="polite"` annonce "U15 Féminin déplacé vers tier D"
**And** le focus reste sur la carte "U15 Féminin" dans sa nouvelle position

**Given** le gestionnaire est sur `/schedules/abc-123` et clique sur un créneau
**When** le `SlotDetailPanel` s'ouvre
**Then** le focus se déplace sur le titre du panel "Détails du créneau"
**And** le panel a `role="region"` et `aria-label="Détails du créneau"`
**And** quand il appuie sur `Escape`, le panel se ferme et le focus revient sur le créneau cliqué

---

## 6. Lock Levels (NONE / SOFT / HARD)

### Définition

Le champ `ScheduleSlotTemplate.lockLevel` (enum OpenAPI : `NONE`, `SOFT`,
`HARD`) contrôle le comportement du solver OR-Tools lors de la régénération
du planning.

| Lock Level | Comportement solver | UI FullCalendar | Cas d'usage |
|------------|---------------------|-----------------|-------------|
| `NONE` | Créneau libre — le solver peut le déplacer, supprimer, ou recréer | Pas d'icône | Créneau généré automatiquement, non verrouillé |
| `SOFT` | Créneau fixé — le solver ne peut pas le déplacer, mais peut ajuster les autres créneaux autour | Icône cadenas bleu | Créneau validé par le gestionnaire, à préserver |
| `HARD` | Créneau verrouillé — le solver ne peut ni le déplacer ni le supprimer, et aucun autre créneau ne peut chevaucher | Icône cadenas rouge | Créneau imposé (match, contrainte externe) |

### Champs OpenAPI associés

```
ScheduleSlotTemplate:
  lockLevel: "NONE" | "SOFT" | "HARD"     -- verrouillage permanent
  temporaryLock: boolean                    -- verrouillage ponctuel (une semaine)
  temporaryLockFor: string | null           -- date cible (ISO 8601) du verrou ponctuel
```

### Flux de changement de lock level

1. Gestionnaire clique sur un créneau → `SlotDetailPanel` s'ouvre
2. Panel affiche le lock level actuel avec un sélecteur (NONE / SOFT / HARD)
3. Gestionnaire change le lock level
4. `PUT /api/schedule_slot_templates/{id}` avec `{ lockLevel: "<new_level>" }`
5. UI se met à jour : icône cadenas apparaît/disparaît sur le créneau
6. Au prochain `POST /api/schedules/{id}/generate`, le solver respecte les locks

### Lock ponctuel (temporaryLock)

Le lock ponctuel permet de verrouiller un créneau pour une semaine spécifique
sans le verrouiller de façon permanente :

1. Gestionnaire édite un créneau (drag-and-drop)
2. `PostEditDialog` s'ouvre → choix "Juste ponctuel"
3. `PUT /api/schedule_slot_templates/{id}` avec `{ temporaryLock: true, temporaryLockFor: "2026-07-14" }`
4. Le solver ignore ce créneau lors de la génération pour la semaine du 2026-07-14
5. Après cette semaine, le lock ponctuel expire automatiquement

### Test Cases — Lock Levels

**Given** le créneau "U13 Masculin" (lundi 18:00) a `lockLevel: "NONE"`
**When** le gestionnaire ouvre le `SlotDetailPanel` et sélectionne "SOFT"
**Then** `PUT /api/schedule_slot_templates/{slotId}` est appelé avec `{ lockLevel: "SOFT" }`
**And** sur succès 200, une icône cadenas bleu s'affiche sur le créneau dans FullCalendar
**And** le `SlotDetailPanel` affiche "Verrouillage: SOFT (fixé)"

**Given** le créneau "U15 Féminin" (mercredi 17:00) a `lockLevel: "HARD"`
**When** le gestionnaire tente de le drag-and-drop en mode édition
**Then** le drag est désactivé sur ce créneau (pas de poignée de drag)
**And** le curseur affiche `not-allowed` au survol
**And** une infobulle s'affiche : "Créneau verrouillé (HARD) — déverrouillez pour déplacer"

**Given** le créneau "U11 Masculin" (samedi 10:00) a `lockLevel: "NONE"` et le gestionnaire choisit "Juste ponctuel" dans le `PostEditDialog`
**When** il saisit la date "2026-07-14" et confirme
**Then** `PUT /api/schedule_slot_templates/{slotId}` est appelé avec `{ temporaryLock: true, temporaryLockFor: "2026-07-14" }`
**And** une icône cadenas orange (ponctuel) s'affiche sur le créneau
**And** une infobulle indique : "Verrouillé pour le 14/07/2026 uniquement"

**Given** le solver régénère le planning avec 3 créneaux SOFT et 1 créneau HARD
**When** `POST /api/schedules/{id}/generate` est appelé
**Then** les 3 créneaux SOFT restent à leur position (le solver les préserve)
**And** le créneau HARD reste à sa position et aucun autre créneau ne le chevauche
**And** les créneaux NONE sont librement réorganisés par le solver

---

## 7. Reference Day Anchoring

> ⚠ **Section OBSOLÈTE dans son implémentation (constaté le 2026-08-01, P4-37).** Elle décrit
> une pile — **FullCalendar 6** et une validation **Zod** (`VenueSlotSchema`) — dont on ne trouve
> **aucune trace** dans le dépôt : ni dans `frontend/package.json`, ni dans `frontend/src/`. Elle
> date d'avant la reconstruction du frontend. Le **concept** d'ancrage reste juste ; les extraits
> de configuration et les bornes qu'ils citent (`hiddenDays: [0]`, `slotMaxTime: '22:00:00'`,
> `z.number().min(1).max(6)`) ne décrivent **rien de ce qui tourne** — et ils contredisent
> désormais la géométrie réelle, qui vit dans **`frontend/src/features/wizard/lib/weekGrid.ts`**
> (sept jours, 08h→23h) et n'a pas de validation Zod. Ne pas s'y fier sans lire ce fichier.

### Concept

Le planning Amateo est une **semaine type** de **sept jours** (lundi à
dimanche, depuis P4-37 / 2026-08-01 — la semaine s'arrêtait au samedi côté écran
alors que l'API acceptait `dayOfWeek` jusqu'à 7 et que l'export l'imprimait). Le
"jour de référence" (reference day) est le point d'ancrage qui définit le début
de cette semaine type dans le calendrier.

### Configuration

| Paramètre | Source | Valeur par défaut | Impact |
|-----------|--------|-------------------|--------|
| `dayOfWeek` | `ScheduleSlotTemplate.dayOfWeek` (integer) | 0 | 1=lundi, 2=mardi, ..., 7=dimanche. 0 = non assigné |
| Premier jour calendrier | FullCalendar `firstDay` config | 1 (lundi) | La colonne la plus à gauche est lundi |
| Timezone club | `Club.timezone` | `Europe/Paris` | Détermine l'heure locale affichée |
| Locale club | `Club.locale` | `fr_FR` | Formatage des dates et heures |

> Référence OpenAPI : `ScheduleSlotTemplate.dayOfWeek` est un `integer` (default
> 0). Le schéma n'impose pas de min/max dans l'OpenAPI, mais le frontend
> valide `1 ≤ dayOfWeek ≤ 6` via Zod (voir `frontend-wizard.md` §2, schema
> `VenueSlotSchema` qui utilise `z.number().int().min(1).max(6)`).

### Ancrage FullCalendar

FullCalendar 6 avec `timeGridWeek` est configuré pour :

```typescript
// Illustration — pas un fichier .ts
const calendarConfig = {
  initialView: 'timeGridWeek',
  firstDay: 1,              // lundi = jour de référence
  hiddenDays: [0],          // dimache caché (pas de planning le dimanche)
  slotMinTime: '08:00:00',
  slotMaxTime: '22:00:00',
  slotDuration: '00:15:00', // tranches de 15min
  dayHeaderFormat: { weekday: 'short' }, // "lun", "mar", ...
  nowIndicator: false,      // pas d'indicateur "maintenant" (semaine type, pas temps réel)
  allDaySlot: false,        // pas de slot "journée entière"
};
```

### Mapping dayOfWeek → colonne FullCalendar

| `dayOfWeek` (API) | Jour | Colonne FullCalendar | Index colonne |
|-------------------|------|---------------------|---------------|
| 1 | Lundi | Colonne 1 (reference day) | 0 |
| 2 | Mardi | Colonne 2 | 1 |
| 3 | Mercredi | Colonne 3 | 2 |
| 4 | Jeudi | Colonne 4 | 3 |
| 5 | Vendredi | Colonne 5 | 4 |
| 6 | Samedi | Colonne 6 | 5 |
| 0 | Non assigné | N/A (créneau invisible) | — |

### Ancrage lors de l'édition manuelle

Quand le gestionnaire drag un créneau d'un jour à un autre dans FullCalendar :

1. FullCalendar émet l'événement `eventDrop` avec `{ oldEvent, event, delta }`
2. Le frontend extrait le nouveau `dayOfWeek` depuis la colonne cible
3. Le frontend extrait le nouveau `startTime` depuis la position verticale
4. `PUT /api/schedule_slot_templates/{id}` avec `{ dayOfWeek: <new>, startTime: "<HH:MM>" }`
5. Le `dayOfWeek` est toujours un entier 1-6 (validé côté frontend avant envoi)

### Ancrage et timezone

L'ancrage du jour de référence est indépendant de la timezone : `dayOfWeek=1`
est toujours lundi, quelle que soit la timezone du club. La timezone affecte
uniquement l'affichage de `startTime` (ex: "18:00" en `Europe/Paris` vs
"16:00" en `UTC`). Le frontend utilise `date-fns-tz` pour formater les heures
selon `Club.timezone`.

### Test Cases — Reference Day Anchoring

**Given** le schedule `abc-123` a un créneau avec `{ dayOfWeek: 1, startTime: "18:00", durationMinutes: 90 }` et le club timezone est `Europe/Paris`
**When** le calendrier FullCalendar se charge avec `firstDay: 1`
**Then** le créneau s'affiche dans la colonne 1 (lundi) de 18:00 à 19:30
**And** l'heure "18:00" est formatée selon `Europe/Paris` (UTC+2 en été)
**And** le header de colonne affiche "lun"

**Given** le gestionnaire drag le créneau de la colonne lundi (dayOfWeek=1) vers la colonne mercredi (dayOfWeek=3)
**When** l'événement `eventDrop` est émis
**Then** le frontend extrait `dayOfWeek: 3` depuis la colonne cible
**And** `PUT /api/schedule_slot_templates/{slotId}` est appelé avec `{ dayOfWeek: 3, startTime: "18:00" }`
**And** le créneau se déplace visuellement vers la colonne mercredi

**Given** un créneau a `dayOfWeek: 0` (non assigné)
**When** le calendrier se charge
**Then** le créneau n'est pas affiché dans FullCalendar (invisible)
**And** un avertissement est loggé en console : "Créneau {id} a dayOfWeek=0, non affiché"

**Given** le club timezone est `America/New_York` et un créneau a `startTime: "18:00"`
**When** le calendrier se charge
**Then** le créneau s'affiche à "18:00" en heure locale New York (pas en heure Paris)
**And** `date-fns-tz.formatInTimeZone` est utilisé pour le formatage

---

## 8. Endpoints consommés — synthèse

| Endpoint | Méthode | Page | Statut OpenAPI |
|----------|---------|------|----------------|
| `/api/login` | POST | LoginPage | ✅ Présent |
| `/api/register` | POST | RegisterPage | ✅ Existe (route Symfony custom — hors snapshot OpenAPI) |
| `/api/me` | GET | AppLayout (init) | ✅ Existe (route Symfony custom — hors snapshot OpenAPI) |
| `/api/schedules/{id}` | GET | ScheduleViewPage | ✅ Présent |
| `/api/schedules/{id}/generate` | POST | ScheduleViewPage | ✅ Présent (custom controller) |
| `/api/schedules/{id}/export-pdf` | POST | ScheduleViewPage | ✅ Présent (custom controller) |
| `/api/schedule_slot_templates` | GET | ScheduleViewPage | ✅ Présent |
| `/api/schedule_slot_templates/{id}` | PUT | ScheduleViewPage | ✅ Présent |
| `/api/schedule_diagnostics` | GET | DiagnosticsPage | ✅ Présent |
| `/api/priority_tiers` | GET | TierListPage | ✅ Présent |
| `/api/teams` | GET | TierListPage | ✅ Présent |
| `/api/teams/{id}` | PUT | TierListPage | ✅ Présent |
| `/api/clubs/{id}` | GET, PUT | (profil — non détaillé ici) | ✅ Présent |

> Référence : `specs/courantes/openapi-snapshot.json` (paths re-vérifiés au
> 2026-07-03, backend SHA `09e67f3`). `/api/register` et `/api/me` existent
> côté backend mais restent hors du snapshot (routes Symfony custom).

### Gaps identifiés (résolus)

| Point | État réel (vérifié @ 09e67f3) |
|-------|-------------------------------|
| `/api/register`, `/api/me` | **Existent** côté backend (routes Symfony custom) ; absents du snapshot OpenAPI car hors API Platform. Pas un gap fonctionnel. |
| `manual-edit/*` sub-routes | **Existent** (`ManualEditController` : `constraint` / `lock` / `one-time`) — le frontend livré utilise `lock` et `one-time`. |
| `onboarding_completed` sur Club | Présent dans l'OpenAPI (`Club.onboardingCompleted: boolean`). |

---

## 9. File structure (réservé au frontend)

```
frontend/src/
├── routes/
│   ├── login/
│   │   └── index.tsx              # LoginPage
│   ├── register/
│   │   └── index.tsx              # RegisterPage
│   ├── schedules/
│   │   └── $id/
│   │       ├── index.tsx          # ScheduleViewPage
│   │       └── diagnostics.tsx    # DiagnosticsPage
│   ├── priorities/
│   │   └── index.tsx              # TierListPage
│   └── wizard/                    # Réservé — voir frontend-wizard.md
├── features/
│   ├── auth/
│   │   ├── LoginForm.tsx
│   │   └── RegisterForm.tsx
│   ├── schedules/
│   │   ├── ScheduleCalendar.tsx
│   │   ├── ScheduleHeader.tsx
│   │   ├── SlotDetailPanel.tsx
│   │   ├── GenerateButton.tsx
│   │   ├── ExportPdfButton.tsx
│   │   ├── PostEditDialog.tsx
│   │   └── useScheduleSSE.ts
│   ├── diagnostics/
│   │   ├── DiagnosticsReport.tsx
│   │   ├── DiagnosticItem.tsx
│   │   └── SeverityFilter.tsx
│   └── priorities/
│       ├── TierBoard.tsx
│       ├── TierColumn.tsx
│       └── TierCard.tsx
├── shared/
│   ├── ui/                        # Button, Input, Select, Badge, Spinner, etc.
│   ├── api/                       # Instance ky + query helpers
│   ├── hooks/                     # useScheduleSSE, useAuth, etc.
│   ├── stores/                    # Zustand stores (authStore, uiStore)
│   └── types/                     # Types partagés (HydraCollection, etc.)
└── layouts/
    ├── AuthLayout.tsx
    └── AppLayout.tsx
```

> Aucun fichier `.test.ts` ou `.test.tsx` n'est créé dans le cadre de cette
> spécification. Les test cases sont en prose Given/When/Then dans ce fichier.
> La stratégie de test (TDD mandate) est dans `frontend-strategy.md`.

</details>
