# Frontend — État & données

> Gestion d'état et flux de données du frontend : frontière Zustand ⇄ TanStack Query, client HTTP ky, suivi temps réel de la génération (Mercure/SSE). Découpé mécaniquement de `frontend-spec.md` (DOC-59) ; le contrat d'endpoints vit dans `frontend-api-contract.md`.

Last verified @ 2026-10-06 (découpage thématique DOC-59 — contenu déplacé TEL QUEL depuis `frontend-spec.md`, sans réécriture de fond ; la fraîcheur du contenu est celle de la passe du même jour sur `frontend-spec.md`, l'historique de vérification vit dans `git log -p --follow` ce fichier).

## 3. State Management Strategy

Deux couches distinctes, responsabilités non chevauchantes.

| Couche | Outil | Responsabilité | Règle |
|--------|-------|----------------|-------|
| Server state | TanStack Query 5 | Données issues de l'API (resources, collections, mutations) | **Toujours** via Query. Jamais de state local pour des données serveur. |
| Client state | Zustand 5 | État UI pur, drapeau de session, thème, préférences | **Jamais** de données serveur en Zustand. Sync via Query callbacks. |

### Frontière stricte

```typescript
// Illustration — frontière Zustand / TanStack Query

// ✅ Zustand : état UI pur, pas de données serveur (authStore réel : un booléen,
// PLUS AUCUN jeton — le JWT est un cookie httpOnly, SEC-16)
type AuthStore = {
  isAuthenticated: boolean;
  setAuthenticated: (value: boolean) => void;
  clear: () => void;
};

// ✅ TanStack Query : données serveur, cache, invalidation
const schedulesQuery = useQuery({
  queryKey: ['schedules', { clubId, seasonId }],
  queryFn: () => api.get('schedules', { clubId, seasonId }),
});

// ❌ Interdit : stocker le résultat de useQuery dans Zustand
// ❌ Interdit : faire un fetch manuel dans un composant sans passer par Query
```

### Quand utiliser Zustand vs TanStack Query

| Situation | Choix | Raison |
|-----------|-------|--------|
| Identité après login | **Cookie httpOnly posé par le serveur** (SEC-16) ; Zustand ne garde qu'un booléen `isAuthenticated` (persist `cs-auth`) | Un jeton lisible par le JS était exfiltrable ; le drapeau n'est qu'un indice d'UI, l'autorisation reste au serveur → [`jwt-cookie.md`](../../docs/security/jwt-cookie.md) |
| Contexte tenant (club/saison) | **Aucun état client** | Résolu côté serveur depuis le JWT (`TenantFilterListener`) — le frontend n'envoie aucun header tenant |
| Thème clair/sombre | Zustand (`themeStore`) | UI pure ; l'accent club vient de `/api/me` via `useApplyClubTheme` |
| État UI wizard / planning | Zustand (stores de feature `store.ts`) | UI pure, pas de persistence serveur |
| Liste des équipes | TanStack Query | Donnée serveur, cacheable, invalidable |
| Statut d'une génération | TanStack Query + **flux Mercure** (FRT-04), polling en fallback | Donnée serveur temps réel ; le publieur est best-effort, donc le poll ne meurt pas |
| Formulaires wizard | État local contrôlé | Formulaires simples, soumis puis invalidés via Query |

---

## 4. HTTP Client Strategy

ky 2 comme unique client HTTP. Configuration centralisée, jamais instancié ad-hoc dans les composants.

### Instance configurée (`src/shared/api/client.ts`)

```typescript
// Extrait fidèle au code livré
export const api = ky.create({
  prefix: "/api", // proxy Vite dev, Nginx prod — jamais de host en dur
  credentials: "include", // SEC-16 : l'identité est un cookie httpOnly, plus un en-tête
  hooks: {
    beforeRequest: [
      (state) => {
        // Plus d'Authorization : seul X-Season-Id est injecté ici.
      },
    ],
    afterResponse: [
      (state) => {
        // 401 sur /api/login = mauvais identifiants (géré par l'appelant).
        const isLogin = state.request.url.includes("/api/login");
        if (state.response.status === 401 && !isLogin) {
          useAuthStore.getState().clear();
          window.location.assign("/login");
        }
      },
    ],
  },
});
```

### Règles

- **Toutes les requêtes passent par l'instance `api` ky.** Pas de `fetch()` direct dans les composants.
- **Aucun header `X-Club-Id`** — il n'existe même plus côté serveur (AUD-SEC-25). Le club actif
  est résolu **côté serveur** depuis la membership du JWT (`backend-inventory.md` §4) ; un
  header de ce nom envoyé par un client est ignoré, pas analysé.
- **`X-Season-Id` est envoyé, mais seulement s'il y a une sélection explicite.** Le hook
  `beforeRequest` pose l'en-tête depuis `seasonStore.selectedSeasonId` quand il est non nul et
  que la requête n'en porte pas déjà un (un appel cross-saison ponctuel — re-datation lors
  d'une transition — gagne donc sur la sélection courante). Absent = le serveur dérive la
  saison courante (pivot du 15 juillet). Il est **validé côté serveur dans tous les cas**,
  jamais fait confiance côté client.
- **Auto-guérison d'une saison périmée** : si le backend répond **403 avec l'en-tête
  `X-Season-Rejected`** (saison purgée côté serveur), le hook `afterResponse` vide
  `seasonStore` et recharge. Le déclencheur est ce marqueur, **pas** un 403 quelconque — sinon
  un refus d'autorisation légitime effacerait la sélection au lieu de remonter son erreur.
  Sans ce filet, l'app ne pourrait plus jamais se rétablir : le serveur 403-erait *toutes* les
  requêtes, `/api/me` compris.
- **401 → logout automatique** (sauf sur `/api/login`). Le hook `afterResponse` vide le store et redirige vers `/login`.
- **Pas de hardcodage d'URL.** `prefix: '/api'` utilise le proxy Vite en dev et Nginx en prod.
- **Content-Type.** API Platform sert du JSON-LD (`application/ld+json`). Le déballage hydra vit dans `src/shared/api/collection.ts`.

### Proxy Vite (dev)

```typescript
// vite.config.ts — réel (extrait)
export default defineConfig({
  server: {
    proxy: {
      '/api': { target: process.env.API_PROXY_TARGET ?? 'http://127.0.0.1:8080', changeOrigin: true },
      // Fichiers PDF exportés, servis depuis le `public/exports` du backend.
      '/exports': { target: process.env.API_PROXY_TARGET ?? 'http://127.0.0.1:8080', changeOrigin: true },
      '/.well-known/mercure': { target: process.env.MERCURE_PROXY_TARGET ?? 'http://127.0.0.1:3000', changeOrigin: true },
      // FRT-17 : PAS de proxy `/engine` — le frontend ne contacte JAMAIS l'engine
      // directement (frontière §2 de CLAUDE.md). Le proxy mort a été supprimé.
    },
  },
});
```

En production, le Nginx frontend proxy `/api` → backend Nginx, `/exports` → backend et
`/.well-known/mercure` → Mercure hub. **Pas de `location /engine/`** (`docker/frontend/nginx.conf:96-102`) —
frontière §2 de `CLAUDE.md` : le frontend ne contacte jamais l'engine directement.

---

## 5. Suivi temps réel de la génération — flux Mercure (SSE), polling en repli

**État livré (FRT-04, 2026-08-07) : le frontend consomme Mercure.** `features/planning/lib/scheduleStream.ts`
ouvre un `EventSource` unique, ref-compté par session, sur `/.well-known/mercure?topic={topicTemplate}`
(l'auth passe par un cookie httpOnly posé par `GET /api/mercure/auth`, jamais un jeton lisible par le
JS — `backend-inventory.md` §5). Chaque événement lisible **invalide** les caches react-query concernés
(jamais de mutation directe du cache sauf le drapeau `connected`) ; un statut terminal invalide aussi
créneaux et diagnostics.

Le **polling reste le repli** (`src/features/planning/queries.ts`) : `refetchInterval` de 2 500 ms tant
qu'un planning est en vol et que le flux n'est pas connecté (`isScheduleStreamConnected()`), ralenti à
15 s quand le flux est connecté — le publieur Mercure est **best-effort** (le backend avale ses échecs
de publication), donc le poll ne meurt jamais. `WaitingApprovalPage` poll `/api/me` toutes les 5 s
(canal séparé, non concerné par Mercure).

**Diagnostic observable (P4-168, 2026-09-03)** : `getScheduleStreamDiagnostics()`/
`useScheduleStreamDiagnostics()` exposent `{ connected, eventsReceived }` — `eventsReceived` est un
compteur **monotone**, jamais remis à zéro (y compris à la fermeture normale du flux en fin de
génération), pensé pour survivre au moment où `connected` est déjà retombé. `ScheduleStreamWitness.tsx`
en fait un témoin **DOM sans rendu** (`hidden`), monté dans `PlanningPage.tsx` : attributs
`data-schedule-stream="connected|disconnected"` et `data-schedule-stream-events="N"`, lus par
`frontend/tests/e2e/journey.spec.ts` pour distinguer une livraison PAR SSE d'une livraison par le repli
polling (hub muet).

### Règles

- **EventSource sur `/.well-known/mercure`.** Jamais d'URL hardcodée vers le hub Mercure directement.
- **Invalidation Query sur événement**, pas de mutation directe du cache sauf pour le statut.
- Le polling à 2,5 s pendant la génération reste actif tant que le flux SSE n'est pas connecté.

---

## 7. TanStack Query Strategy

### Conventions de query keys

```typescript
// Illustration — hiérarchie de query keys (clé réelle du profil : ["me"])
type QueryKey =
  | ['me']                                       // GET /api/me
  | ['schedules', { seasonId: string }]          // GET /api/schedules?seasonId=X
  | ['schedules', scheduleId]                    // GET /api/schedules/{id}
  | ['schedule-slots', scheduleId]               // GET /api/schedule-slot-templates?scheduleId=X
  | ['schedule-diagnostics', scheduleId]         // GET /api/schedule-diagnostics?scheduleId=X
  | ['teams', { seasonId: string }]              // GET /api/teams
  | ['priority-tiers']                           // GET /api/priority-tiers (cache longue durée)
  | ['sport-categories']                         // GET /api/sport-categories
  | ['venues', { seasonId: string }]             // GET /api/venues
  | ['coaches', { seasonId: string }]            // GET /api/coaches
  ;
```

### Stale time par type de donnée

| Type de donnée | `staleTime` | Raison |
|----------------|-------------|--------|
| Auth (`/api/me`) | 5 min | Change rarement, mais doit détecter logout côté serveur |
| Référentiels (tiers, categories, sports) | 30 min | Données quasi-statiques |
| Collections métier (teams, venues, coaches) | 1 min | Changent pendant la saisie |
| Schedule + slots | 0 (toujours stale) | Temps réel via Mercure, re-fetch systématique |
| Diagnostics | 0 | Re-fetch après génération |

### Mutations et invalidation

```typescript
// Illustration — pattern mutation + invalidation
const useGenerateSchedule = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (scheduleId: string) =>
      api.post(`schedules/${scheduleId}/generate`),
    onSuccess: (_, scheduleId) => {
      // Le statut arrive via SSE, on invalide pour le re-fetch
      queryClient.invalidateQueries({
        queryKey: ['schedules', scheduleId],
      });
    },
  });
};
```

### Pagination JSON-LD

API Platform 4 sert les collections en JSON-LD avec la clé **`member`** — **sans** le
préfixe `hydra:`.

```typescript
// Fidèle au code livré (src/shared/api/collection.ts)
// collection()  : déballe `member`, tolère aussi un tableau nu, sinon [].
// collectionAll(): pagine par `?page=N` (PAGE_SIZE = 30), dédoublonne par `id`,
//                  s'arrête sur une page courte OU une page n'apportant rien de
//                  neuf (garde contre un `page` no-op côté serveur).
const raw = await api.get(path, { searchParams }).json<unknown>();
if (Array.isArray(raw)) return raw as T[];
if (Array.isArray((raw as { member?: unknown }).member)) return (raw as { member: T[] }).member;
return [];
```

Le frontend n'utilise **pas** `useInfiniteQuery` (aucune occurrence dans `src/`) :
`collectionAll()` agrège toutes les pages en une requête logique.

### Règles

- **Pas de `useQuery` sans `queryKey` structuré.** Les keys sont typées et hiérarchiques.
- **Toutes les mutations invalident explicitement.** Pas d'invalidation globale (`invalidateQueries()` sans key).
- **Pas de `queryClient.setQueryData` sauf pour statut temps réel SSE.** Préférer invalidation + re-fetch.
- **`enabled` conditionnel pour les queries dépendantes.** Ex: slots query `enabled: !!scheduleId`.

---

## 8. Zustand Strategy

### Stores (livrés)

| Store | Fichier | Contenu | Persistence |
|-------|---------|---------|-------------|
| `authStore` | `src/shared/stores/authStore.ts` | `isAuthenticated` uniquement — **aucun jeton** (SEC-16) | `localStorage` (`persist`, clé `cs-auth`, `version: 2` dont la migration EFFACE un jeton legacy) |
| `themeStore` | `src/shared/stores/themeStore.ts` | mode clair/sombre + slot `accent` du club | persisté (clé `cs-theme`, relue avant le premier rendu — voir §10) |
| `seasonStore` | `src/shared/stores/seasonStore.ts` | saison sélectionnée (`selectedSeasonId`) — alimente l'en-tête `X-Season-Id` | persisté |
| `toastStore` | `src/shared/stores/toastStore.ts` | file des notifications, rendue par `ui/toaster` | Non persisté |
| `transitionUiStore` | `src/shared/stores/transitionUiStore.ts` | état UI du bandeau de bascule de saison | persisté |
| `navTransitionStore` | `src/shared/stores/navTransitionStore.ts` | jeton monotone armé par un GESTE de navigation (`armNavTransition`) — seul déclencheur du contexte « Changement de page » du voile `ActionVeil` | Non persisté |
| wizard `store` | `src/features/wizard/store.ts` | étape courante, étape max atteinte, **mode** (`season`/`period`) + `calendarEntryId` — **aucune donnée métier** | persisté (`version: 4`) |
| planning `store` | `src/features/planning/store.ts` | planning sélectionné + état UI (vue, filtres) | Non persisté |
| matches `store` | `src/features/matches/store.ts` | état UI du module matchs — `selectedWeekend` (semaine affichée du Calendrier, `null` = auto, miroir URL `?semaine=`, PR 3b) + `unplacedReasons` (raisons du dernier auto-placement, par fixtureId) : les deux resettent au changement de semaine (`setSelectedWeekend`) ; **`railStep` SUPPRIMÉ (PR 3b, 2026-09-16, remplacé par la barre `WeekCounters` dérivée pure — plus aucun état de rail stocké)** ; `filterMode` (`equipe`/`coach`/`gymnase`) + `filterIds` (PR-1 filtres 2026-09-08) : changer d'axe vide la sélection, changer de semaine ne purge PAS le filtre ; miroir dans l'URL `?vue=&filtre=` ; `consultKinds` (`null` = **les DÉFAUTS** championnat+coupe+brassage depuis le 2026-09-16/17, plus « tout » — `DEFAULT_KINDS`)/`consultFamilies` (`null` = tout) + `consultTypicalWeek` (PR-2a, 2026-09-08, initial `false` depuis le 2026-09-16/17) + `consultAway` (interrupteur Extérieurs, initial `false`, non persisté) + `consultTemporality`/`consultMonth`/`consultPhaseId` (PR-2b), miroir URL `type=`/`conflits=`/`type_semaine=`/`exterieurs=`/`temps=`/`mois=`/`phase=` ; `conflictsPivot`/`conflictsFamilies` (PR A Conflits, 2026-09-15) — SÉPARÉS de `consultFamilies` (le compte de l'onglet Conflits est SAISON, celui du Calendrier la temporalité affichée), miroir URL `pivot=`/`conflits=` (`decodeConflictsParams`/`applyConflictsToParams` — `traitement=`/`domicile=` sont aussi décodés par ces fonctions mais restent en état LOCAL de `ConflictsPage.tsx`, pas dans ce store) | Non persisté |
| admin `store` | `src/features/admin/store.ts` | état UI de la console superadmin | Non persisté |

### authStore

```typescript
// Fidèle au code livré — SEC-16 : plus aucun jeton côté client, juste un indice d'UI.
type AuthState = {
  isAuthenticated: boolean;
  setAuthenticated: (value: boolean) => void;
  clear: () => void;
};

// Tout le reste (user, club, membershipStatus, seasonPlan, accent)
// vient de GET /api/me via TanStack Query (queryKey ["me"]).
```

### Règles

- **Un store par domaine.** Pas de store global "app" qui mélange tout.
- **Pas de données serveur en Zustand.** Si ça vient de l'API, c'est en TanStack Query.
- **Actions dans le store, pas dans les composants.** `login()`, `logout()`, `setContext()` vivent dans le store.
- **Pas de middleware complexe.** `persist` pour les préférences (thème, saison, drapeau de session), c'est tout. Pas de `devtools` en prod. ⚠ **Jamais de jeton dans un store** : depuis SEC-16 le JWT est un **cookie httpOnly** que le JS ne voit pas (§ tableau ci-dessus) — `authStore` ne porte qu'un booléen.
- **Sélecteurs fins.** `useAuthStore((s) => s.isAuthenticated)` pour éviter les re-renders inutiles.

---

