# Frontend — Contrat API frontend ↔ backend

> Endpoints consommés par le frontend (par route), radar de conflits et invalidations, headers obligatoires, authentification, génération asynchrone, pagination, formats. Découpé mécaniquement de `frontend-spec.md` (DOC-59).

Last verified @ 2026-10-06 (découpage thématique DOC-59 — contenu déplacé TEL QUEL depuis `frontend-spec.md`, sans réécriture de fond ; la fraîcheur du contenu est celle de la passe du même jour sur `frontend-spec.md`, l'historique de vérification vit dans `git log -p --follow` ce fichier).

## 9. Contrat API Frontend ↔ Backend

> Ce section référence le contrat API. L'inventaire complet des ressources, contrôleurs, et
> sécurité est dans `backend-inventory.md`. Le snapshot OpenAPI complet est dans
> `openapi-snapshot.json`. Ce section ne duplique pas — il spécifie comment le frontend
> consomme le contrat.

### Références contrat

| Document | Rôle | Localisation |
|----------|------|--------------|
| `backend-inventory.md` | Inventaire backward : resources API Platform, contrôleurs custom, sécurité JWT, Mercure, pagination | `../../backend/docs/backend-inventory.md` |
| `openapi-snapshot.json` | Snapshot OpenAPI 3.1 des ressources API Platform (contrat/doc ; plus de codegen front — types API manuels depuis FRT-15) | `specs/courantes/openapi-snapshot.json` |

### Endpoints consommés par le frontend (par route)

| Route frontend | Endpoints backend consommés |
|----------------|---------------------------|
| `/login` | `POST /api/login` |
| `/register` | `POST /api/register` (202, écran « vérifie tes emails ») |
| `/verify-email/:token` | `POST /api/register/verify` (émet le JWT → app) |
| `/invitation/:token` | `GET /api/invitations/public/{token}` (contexte : club, adresse, rôle, `hasAccount`), `POST /api/invitations/public/{token}/accept` (sans compte → crée le compte + cookie JWT → app), `POST /api/invitations/{token}/accept` (connecté, un clic) — P4-299 |
| `/forgot-password`, `/reset-password/:token` | `POST /api/password/forgot`, `POST /api/password/reset` |
| `/waiting` | `GET /api/me` (poll 5 s jusqu'à `membershipStatus === "active"`) |
| `/planning` | `GET /api/me`, `GET /api/schedules` (poll 2,5 s si génération en vol), `GET /api/schedule_slot_templates?scheduleId={id}`, `GET /api/schedule_diagnostics?scheduleId={id}`, `POST /api/schedules/{id}/generate`, `POST /api/schedules/{id}/validate`, `POST /api/schedules/{id}/reopen`, `POST /api/schedules/{id}/export-pdf` (`ExportMenu`), `PUT /api/schedule_plans/{id}` (renommage du plan), `DELETE /api/schedules/{id}` (suppression d'une version de travail), `POST /api/schedule-slots/{id}/move` (déplacer/évincer, mode cible sous verdict moteur — §6.7), `POST /api/schedules/{id}/place-slot` (placer une séance à la dérive — §6.7), `POST /api/schedule-slots/{id}/manual-edit/lock` (verrouiller/déverrouiller — §6.7), `GET /api/training/placed-conflicts` (radar « une personne à deux endroits », bandeau autonome sur la version en vigueur — P4-269), collections référentiels (`teams`, `venues`, `coaches`, `sport_categories`, `team_coaches`, `coach_player_memberships`) |
| `/` (cockpit) | `GET /api/me`, `GET /api/schedules`, `GET /api/schedule_plans`, `GET /api/calendar_entries` (+ conflits d'entrée), campagnes de doléances (badge radar), `GET /api/venue_unavailabilities` + `venue-unavailability-impact` (carte radar « gymnase indisponible » — P4-68), `GET /api/training/placed-conflicts` (pastille « N personnes à deux endroits » du bandeau de saison — P4-269), `PUT /api/calendar_entries/{id}` (re-dater une racine `closure` re-datable — bouton « Modifier les dates » de la liste du jour, D3 v1 PR-2 ; ou, pour une mère DÉCOUPÉE, confirme un `POST /redate-preview` — D3 v2), `POST /api/calendar_entries/{id}/redate-preview` (aperçu des effets avant confirmation, mère découpée seulement — D3 v2, P4-174) |
| `/matchs`, `/matchs/consulter`, `/matchs/importer`, `/matchs/configuration`, `/matchs/contraintes`, `/matchs/adversaires`, `/matchs/semaine-type`, `/matchs/reconciliation` | `POST /api/fixtures/import/analyze` (multipart `file` → mappings résolus, PR-3b : plus de `deviations` dans la réponse consommée), `POST /api/fixtures/import` (multipart `file` + `mappings`, **plus de `decisions`** depuis PR-3b → rapport + `unresolvedDeviations`/`depositedAt`), `GET /api/fbi-ingestions/latest` (fraîcheur, Membre), `POST /api/matches/module-visit` (gardien RMM-3, un POST par ouverture), `GET /api/ffbb/rencontres` + `POST /api/ffbb/rencontres/apply` (canal API, à la demande — `useFfbbRencontres(enabled: false)`), `POST /api/fixtures/review` (geste ligne `{fixtureIds}` ou masse `{teamId}`, PR-3a/3b) + `POST /api/fixtures/review/deviations` (trancher un écart `{fixtureId, field, choice}`). ⚠ Catalogue **partiel** — le module porte aussi `fixtures` CRUD, `fixtures/conflicts`, `fixtures/place`, `league-match-windows`, `club_league_windows` CRUD (P4-272 ①, écran Contraintes), `league-window-suggestions[/apply]` (P4-272 ②, bloc Plages suggérées), `match_constraints` CRUD + `match-constraints/coherence` (P4-272 ③/④, écran Contraintes sections Club et Équipes), `venue_match_windows`, `team_match_habits`, `team_links`, `ffbb/engagements`, `venue_unavailabilities`, `POST /api/opponents/resolve` (rattrapage des codes FFBB des adversaires, cap dur `MAX_DISTINCT` — `OpponentResolveController.php`, route fine conservée en compat), `POST /api/opponents/refresh` (l'orchestrateur appelé depuis « Mettre à jour les adversaires » depuis PR 2b, 2026-09-16 — `OpponentRefreshController.php`), `GET /api/opponents/travel` (`travelStatus`/`hasLogo` additifs, C5/C7), `POST /api/opponents/travel/resolve` (dispatche au worker depuis C6, `{queued: true}`), `POST /api/opponents/travel/manual`/`/auto`, `GET /api/opponents/{code}/venue-suggestions`, `GET /api/opponents/{code}/logo` (route membre, C7) |
| `/wizard` | CRUD `teams`/`venues`/`coaches`/`constraints`/`venue_training_slots`…, `GET /api/priority_tiers`, `GET /api/sport_categories`, `POST /api/teams/reorder` (mode tri), `POST /api/constraints/validate`, `POST /api/schedules` + `generate` (étape Génération), `GET /api/training/placed-conflicts` (encart étape Coachs, rafraîchi après un lien — P4-269) |
| `/club` | `PATCH /api/club/appearance`, `POST/DELETE /api/club/logo`, `GET /api/clubs/{clubId}/logo` (public, cache-buster sur l'URL après upload), `POST /api/club/ffbb-import` (re-import institutionnel, seul geste de correction de la fiche FFBB, management-gated), `PATCH /api/club/siege` (siège du club, seule saisie de la page — §6.6 ter), `GET /api/memberships/pending`, `POST /api/memberships/{id}/approve`, `POST /api/memberships/{id}/reject` (section « Demandes » — l'ancienne route `/pending-members` a été repliée ici), `GET /api/invitations`, `POST /api/invitations`, `POST /api/invitations/{id}/resend`, `DELETE /api/invitations/{id}` (section « Invitations », P4-299), `GET /api/venue-usage-stats?from=&to=` (encart stats d'utilisation des gymnases — §6.6 quater) |
| `/profile` | `GET /api/me` |
| `/boite-aux-lettres` | `GET /api/mailbox` (liste, triée du plus récent), `GET /api/mailbox/{id}` (détail + corps) — lecture seule, tenant pur (P4-16) |
| `/doleances/:token` | Endpoints **publics** de la campagne de doléances (lecture du formulaire pré-rempli + soumission des seules sections modifiées) — aucun JWT |
| `/admin*` | `POST /api/admin/auth/password`, `POST /api/admin/auth/totp`, `GET /api/admin/auth/me`, `GET /api/admin/{overview,health,clubs,jobs,actions}`, `POST /api/admin/jobs/{key}/run` (en-tête `X-CSRF-Token`) — client `adminApi` dédié, cookie de session `same-origin` |

### Radar de conflits (`/matchs`) — toute écriture qui le nourrit DOIT l'invalider

`useConflicts` (`matches/queries.ts`, `queryKey: ["fixtures", "conflicts"]`, `staleTime: 10_000`)
est **dérivé, jamais stocké** — `App\Service\MatchConflictDetector` le recalcule à la volée côté
serveur à chaque lecture. Une écriture qui change une donnée dont le détecteur **dépend** doit
donc invalider `["fixtures", "conflicts"]` **en plus** de sa propre clé, sinon le radar sert son
cache jusqu'à 10 s — un accès perdu s'y lit en faux vert, un conflit résolu y reste affiché.
Cinq foyers d'écriture respectent la règle, chacun via un helper `invalidate*` dédié
(`matches/queries.ts`) : `invalidateTravel` (trajet adverse), `invalidateUnavailabilities`
(indisponibilités gymnase), `invalidateHabits` (habitudes équipe), `invalidateTeamLinks`
(passerelles), `invalidateMatchWindows` (fenêtres d'accès match). Ce dernier a été le
**défaut trouvé et corrigé par FRT-20 2ᵉ tranche (2026-08-29)** : `useCreateVenueMatchWindow` /
`useDeleteVenueMatchWindow` n'invalidaient QUE `["venue_match_windows"]`, alors que
`ACCESS_WINDOW_LOST` est dérivé de ces fenêtres (`MatchConflictDetector::kickoffInsideWindow`,
miroir front `matches/lib/matchAccess.ts`) — c'était le seul des cinq foyers à l'ignorer.
**Toute nouvelle écriture du module matchs qui influence le détecteur suit ce patron** (vérifier
`MatchConflictDetector.php` avant d'écrire un hook) ; couverture par l'EFFET (le vrai
`useConflicts` monté sur un `QueryClient` réel refetche) plutôt qu'un espion sur
`invalidateQueries` : `matches/queries.test.tsx`.

**Nuance (FRT-20 3ᵉ tranche, planning, 2026-08-29) : une invalidation n'est due que si le lecteur
DÉRIVE réellement de l'écriture.** La règle ci-dessus tient parce que le radar est **calculé à la
volée** à chaque lecture — c'est ça qui rend un cache périmé possible, et donc l'invalidation
nécessaire. Elle ne se généralise pas à « toute écriture doit invalider tout ce qui y ressemble » :
ajouter une clé « par symétrie » sur une donnée qui ne dérive PAS de l'écriture est un refetch
inutile, pas une sécurité. Exemple canonique — `useLockSlot` (`planning/queries.ts`) n'invalide
QUE `["slots"]` + `["socle-deviation"]`, alors que le foyer commun des retouches acceptées
(`invalidateMovePacket`, move/place) invalide aussi `["diagnostics"]` et `["schedules"]`. C'est
correct : verrouiller un créneau n'appelle jamais le moteur (`ManualEditService::applyLock` ne
fait que poser le verrou + `flush`), et les diagnostics sont des lignes **persistées à la
génération** (`ScheduleDiagnosticsRecorder::record`, appelé UNIQUEMENT depuis
`GenerateScheduleHandler`) — aucun diagnostic ne dérive du `lockLevel` d'un créneau à la lecture.
Invalider `["diagnostics"]` après un verrou re-téléchargerait donc des lignes identiques : un
no-op. Avant d'ajouter une invalidation « pour faire pareil qu'ailleurs », vérifier que le lecteur
visé lit réellement une donnée que l'écriture change — sinon ne rien ajouter. Preuve :
`planning/queries.test.tsx` (verrouille les deux faces : refetch requis d'un côté, absence de
refetch de l'autre).

### Headers obligatoires

| Header | Source | Injection |
|--------|--------|-----------|
| *(plus d'`Authorization`)* | **cookie httpOnly `BEARER`** posé par le serveur (SEC-16) | le navigateur l'envoie seul, `credentials: "include"` |
| `X-Season-Id` | `seasonStore.selectedSeasonId` | ky `beforeRequest` — **conditionnel** : uniquement si une saison est explicitement sélectionnée et que la requête n'en porte pas déjà une |

**Aucun header `X-Club-Id`** : le club est dérivé du JWT côté serveur (`backend-inventory.md`
§4) ; le frontend ne l'envoie jamais. `X-Season-Id`, lui, est envoyé quand le gestionnaire a
choisi une saison (voir §4) — et validé côté serveur dans tous les cas.

### Authentification

| Endpoint | Méthode | Body | Réponse | Action frontend |
|----------|---------|------|---------|-----------------|
| `/api/login` | POST | `{ email, password }` | **204 sans corps** — le JWT part en **cookie httpOnly** (SEC-16) | Poser `isAuthenticated`, redirect `/` — **il n'y a aucun jeton à stocker** |
| `/api/register` | POST | `{ email, password, firstName, lastName, ara, club_name?, consent }` (consent obligatoire — RGPD) | **202** `{ status:"verification_pending" }` (aucun token — A3) | Afficher l'écran « vérifie tes emails » ; **pas de redirect** (le JWT vient de la vérification) |
| `/api/register/verify` | POST | `{ token }` (du lien email) | `{ membershipStatus, user }` + **cookie httpOnly** posé par `JwtCookieFactory` (aucun jeton dans le corps) | Poser `isAuthenticated` ; `pending` → `/waiting`, sinon `/` |
| `/api/me` | GET | — | `{ id, email, firstName, lastName, membershipStatus, role, club: {…} \| null, seasonPlan: { id, name, chosenScheduleId, hasFinishedVersion, currentStructureHash } \| null, seasons, accountDeletionScheduledFor, … }` — **forme complète : `src/features/auth/api.ts` (`MeResponse`)**, source de vérité (le bloc `club` porte aussi l'accent sombre, la fiche FFBB, la ligue et le comité ; `accountDeletionScheduledFor`, P4-301, date `Y-m-d` nullable — échéance de suppression d'un compte sans club) | Query `["me"]` — source des guards, du thème (accent) et de l'état du plan de saison (ADR-0002) |

Les trois champs **structurants** de cette réponse : `club.accentColor` / `club.accentColorDark`
(thème appliqué par `useApplyClubTheme`), `seasonPlan.hasFinishedVersion` (verrou d'onboarding
et gate cockpit) et `seasonPlan.chosenScheduleId` (gate matchs et plans secondaires).

Référence : `backend-inventory.md` §3 (AuthController, PasswordController, MembershipController).

### Génération asynchrone

| Étape | Endpoint | Statut HTTP | Frontend |
|-------|----------|-------------|----------|
| Lancer | `POST /api/schedules/{id}/generate` | 202 | Mutation TanStack Query, écran `GenerationWaiting` |
| Suivi | `GET /api/schedules` — invalidé par le flux Mercure, sinon `polling` | 200 | Flux SSE + `refetchInterval` 2 500/15 000 ms selon connexion (§5) |
| Résultat | `GET /api/schedule_slot_templates?scheduleId={id}` | 200 | Re-fetch slots sur événement terminal (SSE ou polling) |
| Diagnostics | `GET /api/schedule_diagnostics?scheduleId={id}` | 200 | Afficher rapport (`DiagnosticsPanel`) |

Référence : `backend-inventory.md` §3 (GenerateScheduleController) + §5 (Mercure, publié ET consommé côté frontend).

### Édition manuelle

| Endpoint | Méthode | Body | Réponse | Dialogue frontend |
|----------|---------|------|---------|-------------------|
| `/api/schedule-slots/{id}/manual-edit/lock` | POST | `{ lockLevel }` | 200 / erreur → toast | "Verrouiller SOFT/HARD" ; déverrouiller un `RESERVATION` passe d'abord par `ConfirmDialog` (§6.7) |
| `/api/schedule-slots/{id}/move` | POST | `{ dayOfWeek, startTime, venueId, evictSlotId?, dryRun? }` | réel accepté : 200 (toast + invalidation `diagnostics`, `compromises` normalisé `[]`, `evicted` si éviction) / 422 `{valid:false, violations:[…]}` (refusé, surlignage du conflit) / 422 codé `target_locked`/`evict_target_mismatch` (toast) / 502 moteur injoignable / **504 `{code:"engine_timeout"}`** (moteur trop lent, rien écrit — incident 2026-08-17, `EngineTimeoutError`) — **`dryRun:true`** (P2-32) : rend TOUJOURS 200 pour un verdict TRANCHÉ, jamais de throw de légalité — `{valid:true, dryRun:true, compromises}` (accepté) ou `{valid:false, dryRun:true, violations}` (refusé) ; un 504 sur le dry-run lui-même reste une exception (`EngineTimeoutError`) → état `failed` de la modale, PAS un verdict inventé — voir §6.7 et `backend-inventory.md` §route `/move` | Remplace l'ancien rail `manual-edit/one-time` (retiré) — déplacement **sous verdict moteur**, mode cible click-click (P2-30) ; le dry-run alimente `EvictConfirmDialog` (P2-32) avant tout move réel |
| `/api/schedules/{id}/place-slot` | POST | `{ teamId, dayOfWeek, startTime, venueId, durationMinutes?, dryRun? }` | réel accepté : 200 `{valid:true, slotId, compromises}` (toast + invalidation `diagnostics`) / 422 `{valid:false, violations:[…]}` (refusé, surlignage du conflit) / 502 moteur injoignable / **504 `{code:"engine_timeout"}`** (moteur trop lent, rien créé) — `dryRun:true` (P2-32) suit la même forme 200-toujours que `/move` mais n'a **aucun appelant** dans ce lot (case vide = décision D8, aucun essai — §6.7) — voir §6.7 et `backend-inventory.md` §route `/place-slot` | Placer une séance à la dérive (P2-30, geste 3) — arme depuis `DriftBanner`, pose sur une case LIBRE (le moteur tranche la capacité sur une case occupée) |

Référence : `backend-inventory.md` §3 (ManualEditController).

### Pagination

Toutes les collections API Platform sont paginées à 30 items/page (JSON-LD).

- `member` : items de la page (clé **sans** préfixe `hydra:` — API Platform 4)
- Query param `page` pour la pagination — c'est celui que suit `collectionAll()`

Le frontend passe par `collection()` / `collectionAll()` (§7 ci-dessus) — **pas**
d'`useInfiniteQuery`.

Référence : `backend-inventory.md` §6.

### Formats

- **Requêtes** : `application/json` (ky default)
- **Réponses collections** : `application/ld+json` (JSON-LD)
- **Réponses item** : `application/ld+json` ou `application/json`
- **Import Excel** : `multipart/form-data` (file + seasonId)

Référence : `backend-inventory.md` §1 (config API Platform).

---

