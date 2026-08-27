# Frontend Spec — Forward

> Specification FORWARD pour le rebuild du frontend Amateo, réconciliée avec le code
> livré (`frontend/src/`). L'inventaire backward du backend est dans
> `backend-inventory.md` — ce document le référence sans le dupliquer.

Last verified @ 2026-08-28 (rotation `documentation-update`, passe Lot E — §6.6bis re-confirmé au code (`useValidateImpact`/`ValidateDialog.tsx`, route `validate-impact` présente au snapshot). Les écrans matchs récents (chip trajet, carte « Adversaires à localiser », P2-54 PR-3) vivent dans `specs/courantes/module-matchs.md` (maison du module), non dupliqués ici. Passe précédente (RMM-10 / P2-52). §6.6bis :
« Valider » interroge `GET /api/schedules/{id}/validate-impact`
(`useValidateImpact`/`ValidateDialog.tsx`) avant de confirmer — annonce « salle perdue » si N>0,
bouton désactivé tant que l'impact n'est pas connu. `UnplacedList.tsx` gagne un badge calme
(`unplacedReasonLabel.ts`) quand `Fixture.unplacedReason=venue_lost`. Confronté au code :
`ValidateDialog.tsx`, `ValidateDialog.test.tsx`, `PlanningPage.tsx`, `planning/{api,queries}.ts`,
`UnplacedList.tsx`, `matches/lib/unplacedReasonLabel.ts`, `shared/components/ui/declared-fixtures-notice.tsx`.
Le reste du fichier (routes hors planning/matchs, primitives, stack) non re-vérifié cette passe — un
stamp REMPLACE, l'historique vit dans git : `git log -p --follow frontend/docs/frontend-spec.md`)

---

## 1. Stack Decided

Versions figées pour le rebuild. Aucune librairie ne sera ajoutée sans justification explicite.

| Catégorie | Choix | Version | Rôle |
|-----------|------|---------|------|
| Framework UI | React | 19.2 | Base composants, concurrent features, `use()` hook |
| Build tool | Vite | 8 | Dev server, HMR, build production |
| Langage | TypeScript | ~6.0 | Typage statique, `strict: true` |
| Styling | Tailwind CSS | 4 | Utility-first, engine Oxide, `@tailwindcss/vite` plugin |
| Server state | TanStack Query | 5 | Cache, invalidation, optimistic updates, pagination |
| Client state | Zustand | 5 | Stores globaux légers (auth, thème, UI wizard/planning) |
| HTTP client | ky | 2 | Fetch wrapper, interceptors, retry, hooks |
| Grille planning | Composant custom `WeekGrid` | — | Grille hebdomadaire maison (`src/features/planning/WeekGrid.tsx`) — **pas de FullCalendar** |
| Drag & drop | @dnd-kit (core 6 + sortable) | 6.x | Tri des équipes (inter-tier), accessible DnD |
| Primitives UI | Radix UI (label, slot) + cva + tailwind-merge | — | Composants shadcn-style dans `src/shared/components/ui/` |
| Routing | react-router | 8 | Data router (`createBrowserRouter`), **`lazy` par route** (P4-6), nested layouts. ⚠ paquet `react-router`, **pas** `react-router-dom` |
| Icons | lucide-react | 1.x | Icônes SVG tree-shakeable |
| Reporting d'erreurs | @sentry/react | 10.x | **Erreurs seules** — pas d'APM ni de replay (`tracesSampleRate: 0`, quota free tier). DSN absent → init sautée, SDK inerte. ⚠ L'activer demande **le DSN ET l'hôte d'ingestion en CSP** — voir §Sentry (P4-65) |
| Types API | — (manuels) | — | Types API écrits à la main par feature (`features/*/api.ts`) ; le codegen `openapi-typescript`/`types.gen.ts` a été **supprimé** (FRT-15 : 8365 l., 0 import, source de vérité fantôme) |

### Principes de la stack

- **Pas de Redux.** Zustand + TanStack Query couvrent tous les cas d'usage.
- **Pas d'Axios.** ky remplace — plus léger, hooks natifs, basé sur fetch.
- **Pas de CSS-in-JS.** Tailwind 4 uniquement. Les styles dynamiques via `clsx` + conditional classes.
- **Pas de i18n framework.** MVP = français uniquement. Les strings sont en dur dans les composants.
- **Pas de FullCalendar, pas de date-fns, pas de React Hook Form/Zod** dans le code livré :
  grille custom `WeekGrid`, formulaires contrôlés simples avec validation manuelle.
- **TypeScript strict.** `strict: true`, `noUncheckedIndexedAccess: true`, `exactOptionalPropertyTypes: true`.

---

## 2. Routes / Objectives

Chaque route a un objectif produit précis. Le routing utilise **react-router 8** en *data
router* avec nested layouts (`src/app/router.tsx`).

**Découpage du bundle par route (P4-6).** Tout est en `lazy` **sauf** `/login` et les gardes
(`AuthGuard`, `AdminGuard` — leur code doit être là pour décider). Trois filets rendent ce
découpage sûr et **aucun n'est optionnel** quand on ajoute une route :

| Filet | Sans lui |
|---|---|
| `errorElement` (racine **et** imbriqué sous `AppLayout`) | Un chunk 404 (déploiement pendant la session) remplace **toute l'app** par l'écran anglais non stylé du router, invisible de Sentry. L'imbriqué préserve en-tête, navigation et bandeaux quand une seule page échoue. |
| `HydrateFallback` | react-router rend `null` → **page blanche** à chaque ouverture directe ou F5 d'une route lazy. |
| Indicateur d'attente (`useNavigation`, dans `AppLayout`) | Un clic de navigation ne produit **aucun retour** tant que le chunk n'est pas arrivé. |

> Compromis assumé : le data router résout le `lazy` de **toutes** les routes appariées avant
> d'en rendre une seule — un visiteur anonyme sur `/planning` télécharge donc la page avant
> d'être redirigé vers `/login`. Ce JS est public et sans donnée ; l'éviter demanderait de
> dupliquer la décision d'auth dans un `loader` par route.

| Route | Objectif | Auth | Layout |
|-------|----------|------|--------|
| `/login` | Connexion gestionnaire (email + password) — **seule page eager** | Public | `AuthLayout` |
| `/register` | Inscription (A3) : soumet le formulaire → écran « vérifie tes emails » (aucune session ; le club et le JWT sont créés à la vérification) | Public | `AuthLayout` |
| `/verify-email/:token` | Consomme le lien email → crée/rejoint le club, connecte, redirige (`/waiting` si pending, sinon `/`) | Public | `AuthLayout` |
| `/forgot-password` | Demande de réinitialisation de mot de passe (`POST /api/password/forgot`) | Public | `AuthLayout` |
| `/reset-password/:token` | Saisie du nouveau mot de passe (`POST /api/password/reset`) | Public | `AuthLayout` |
| `/waiting` | Attente d'approbation (`WaitingApprovalPage`) — poll `/api/me` toutes les 5 s, redirige vers `/` dès `membershipStatus === "active"` | Token requis | `AuthLayout` |
| `/` | **Cockpit temporel** (`CockpitPage`) : bandeau planning principal (Ouvrir/**Modifier** = reopen · Tous les plannings) · calendrier mensuel des exceptions · radar (à traiter). **Débloqué dès `me.seasonPlan.hasFinishedVersion`** (le plan de saison porte ≥1 version terminée — dérivé, indépendant du pointeur : rouvrir ne re-verrouille pas) ; sinon redirige vers `/wizard`. **Palier B** : CTAs radar « Adapter » actifs (→ wizard mode période) ; « Voir le plan » (overlay généré → consultation) ; « Modifier » le socle avec overlays → **confirmation proportionnée** (409 `overlays_exist` → dialog « supprimera N secondaires »). Overlays exclus du sélecteur de plannings (badge « Période »). **Première incursion du module matchs** (RMM-6 PR-3, 2026-08-25) : `FbiDeadlineCard`, pleine largeur sous le bandeau socle, rendue UNIQUEMENT quand le backend sert une fenêtre J-7 ouverte (`GET /api/matches/deadline-outlook`) — le cockpit reste muet sur les matchs sinon ; escalade « dès le login » (décision fondateur : le placement de match est une urgence), résumé du gardien fusionné dans la même carte, détail : `specs/courantes/module-matchs.md` § « Échéances ligue/comité — RMM-6 » | Required | `AppLayout` |
| `/planning` | **L'écran de la version EN VIGUEUR** (`PlanningPage`, `embedded=false`, symétrie stricte 2026-08-20) : grille `WeekGrid` (un planning **FAILED** sans créneau y montre les **réservations** en pseudo-créneaux HARD lecture seule — couche socle/période du payload, gymnases désactivés filtrés, bandeau « seuls vos créneaux réservés sont affichés » + état vide dédié sans réservation — retour fondateur 2026-08-06), toolbar (badge de statut + pastille « Période » visibles ici comme en mode wizard ; **sélecteur de versions**, lui, reste réservé au wizard), **nom du planning éditable au header** (porté par le plan, `PUT /api/schedule_plans/{id}`), bandeau divergence structure, diagnostics, détail créneau. **« Rouvrir » vit ici** (`!embedded`, `PlanningToolbar.tsx`) — il ramène à l'étape Génération du wizard ; **Valider/Régénérer/Supprimer restent bornés à `embedded`** (gestes de travail, wizard seul) — « le plan pointe la version et ses sœurs sont supprimées » (ADR-0002 inv. 1) se déclenche depuis le wizard et atterrit ici en succès | Required | `AppLayout` |
| `/matchs` | **Module matchs — la boucle guidée** (`MatchesLayout` → `Outlet`, RMM-1 **livré en entier**) : rail à 5 étapes DÉRIVÉES (`lib/loopSteps.ts`, zéro état stocké — batch importé · placés au modèle · litiges · domiciles posés · saisi FBI), une vue par étape sur `MatchesPage` (import FBI, grille week-end + `PlacementPanel` — le slot du panneau est **PERMANENT** avec état vide « Sélectionnez un match », le mode échange se voit SUR la grille (`WeekendGrid` candidates en anneau, Échap sort), `UnplacedList` — la colonne « À placer », qui porte depuis RMM-10 (P2-52) un badge calme « Le gymnase n'est plus affilié au club » (`unplacedReasonLabel.ts`) quand `Fixture.unplacedReason=venue_lost`, ton muted distinct du warning d'auto-placement, radar de conflits, `FbiEntryList` — la vue de saisie FBI groupée par équipe, filtrable équipe/date, « tout marquer saisi » borné aux lignes affichées, **chaque ligne portant son échéance de saisie EFFECTIVE servie par le backend** (RMM-6 PR-2 — « avant le … (J-N) », dépassée en avertissement JAMAIS bloquant, absente sur un amical)). **Le « gardien » à l'ouverture (RMM-3, livré)** : `MatchesLayout` POSTe une visite au montage (`useModuleVisit`, une fois par ouverture, `enabled` sous la même garde socle) — `ModuleVisitBanner` résume ce qui a changé depuis la dernière visite (matchs arrivés, nouveaux conflits, planning de saison changé), muet en première visite/delta vide, et `ConflictRadar` porte des chips « Nouveau » par empreinte de conflit (ornement pur, aucune formule du rail n'en dépend). L'axe de navigation est la **semaine calendaire** (`weekLabel`), le n° de rencontre FBI (`externalRef`) affiché en repère (grille + listes). Sur l'étape « placés au modèle », un écart au modèle (`offModelBadge`) et un signal « même week-end » (`sameWeekendBadge`, pilule neutre — deux membres d'une même rotation reçus le même week-end, RMM-5 PR-4) s'affichent l'un sous l'autre. Les gestes rares (Engagements FFBB, Accès match, Habitudes & passerelles, image A/B, **Créneaux partagés (alternance)**, **Échéances de saisie** — `EntryDeadlinesEditor`, RMM-6 PR-2 : multi-sélection de compétitions + une date appliquée en lot, trois provenances CLUB/proposée/aucune distinguées icône+texte, badge « partagée avec les autres clubs » sur les compétitions appariées) vivent sous `/matchs/configuration` (`ConfigurationPage`, une carte par geste) — trois onglets/routes d'un même layout (`/matchs`, `/matchs/configuration`, `/matchs/reconciliation`), garde socle commun. **Rotation A/B (RMM-5, LIVRÉ EN ENTIER — 4 PR, clôt P2-49)** : `MatchSlotRotationsEditor` déclare des créneaux de match partagés (gymnase+jour+heure, N équipes ORDONNÉES en alternance, flèches ↑/↓, jamais de drag — « l'ordre ne commande aucun calendrier ») ; `TypicalWeekendGrid` gagne un segmenté « Semaine A/B/… » (`weekCountOf`, invisible sans rotation déclarée — la grille reste EXACTEMENT celle d'avant), date-less (aucun ancrage calendaire réel), rotation hors week-end listée à part. **Réconciliation FBI (RMM-4, LIVRÉ EN ENTIER — backend, front, canal API PR-3)** : à l'import, un domicile déjà placé dont date/heure/salle divergent du fichier bascule `ImportFbiDialog` (« Examiner… ») vers `/matchs/reconciliation` (`ReconciliationView` → `ReconciliationPanel`, sous le même `MatchesLayout`) au lieu d'importer directement — **zéro état serveur**, le payload d'analyse voyage EN MÉMOIRE via `useMatchesStore().reconciliation`, union discriminée `channel: "xlsx"|"api"` (accès direct/refresh sans payload = renvoi propre, rien d'écrit). Second canal, MÊME vue : `ConfigurationPage` porte un bouton « Vérifier via l'API FFBB » (`useFfbbRencontres`, à la demande) qui croise les rencontres publiées avec l'app — bandeau d'honnêteté (couverture jamais garantie), section « Présents à la FFBB, absents de l'app » (`TeamSelect` par ligne, non sélectionné = non créé) ; `ConfigurationPage` porte aussi une carte de fraîcheur xlsx (« Dernier dépôt FBI : il y a N jours », `useLatestFbiIngestion`) et `MatchesPage` un rappel discret près du rail. Détail métier : `specs/courantes/module-matchs.md` | Required | `AppLayout` |
| `/wizard` | Assistant de saisie 6 étapes : Équipes → Gymnases → Coachs → Contraintes → Récapitulatif → Génération (`AuthGuard` y redirige tant que `me.seasonPlan.hasFinishedVersion === false`, c.-à-d. tant que le club n'a jamais généré) | Required | `AppLayout` |
| `/club` | Identité du club : logo (upload + recadrage `LogoCropper` + suppression), couleur d'accent (+ palette), **section « Informations du club »** (champs FFBB — voir ci-dessous, admin) **et section « Demandes »** (approbation des adhésions `pending`, admin — l'ancienne route `/pending-members` a été repliée ici) | Required | `AppLayout` |
| `/profile` | Profil utilisateur | Required | `AppLayout` |
| `/confidentialite` | Politique de confidentialité (`PrivacyPage`) — atteignable depuis le menu compte | Public | aucun (autonome) |
| **`/wizard`** | **Adressable depuis P2-25 (2026-08-12)** — `?step=<id>` + une cible : `&slot=<id>` (étape Gymnases **positionnée sur ce créneau** : gymnase sélectionné + éditeur ouvert), `&edit=<id>` (éditeur de contrainte **pré-rempli**), `&tab=reserve`, `&from=<origine>` (retour nommé). ⚑ **Arriver sur l'étape ne suffit pas — il faut arriver SUR l'objet**, sinon on a seulement raccourci le scroll. Paramètre inconnu (id supprimé, étape inexistante) → **atterrissage propre**, jamais d'écran cassé ni d'état vide silencieux. **`&edit=<id>` amène aussi la LIGNE ciblée à l'écran (P4-95, 2026-08-14)** : `data-constraint-id` + `scrollIntoView` centré, planifié APRÈS le scroll-formulaire de l'éditeur pré-rempli (celui-ci garde la priorité, la ligne finit centrée) ; le crayon manuel (édition directe depuis la liste) ne scrolle QUE le formulaire, comportement inchangé. ⚠ **Mode guidé** : un lien vers une étape verrouillée s'affiche **DÉSACTIVÉ avec sa raison** (`WizardStepLink` + `stepLockReason`) — jamais un saut qui casse l'invariant, jamais une disparition sans explication. **Retour nommé** (« ← Retour à… ») : affiché **seulement** si `from=` est présent, il **nomme** l'origine, et il est **éphémère** — effacé au changement d'étape ou dès qu'on a agi. Jamais persisté. | **Authentifié** | — |
| **`/doleances/:token`** | **Page publique SANS login** (#10, lot C2 ; **stepper P2-24, 2026-08-11**) : parcours en ÉTAPES — intro (le pourquoi, + bandeau « déjà répondu le… » si `respondedAt`) → une étape PAR équipe (ses semaines, pré-remplies ; « Rien à signaler » avance sans rien modifier) → récap (« aucune modification » par équipe intacte, « Modifier » qui saute à l'équipe puis REVIENT au récap) qui porte la validation : « Valider et envoyer », ou « Confirmer sans modification » (envoie `submissions: []` — le coach passe ✓ répondu au lieu de rester silencieux). Envoi UNIQUE à la fin, seules les sections modifiées partent (payload inchangé, gardé par test NR) ; filet `sessionStorage` par token (restauré au montage, purgé au succès — jamais côté serveur). Route **plate, hors `AuthGuard`**. Contrat : `types-de-planning.md` §E5 | **Public** | aucun (autonome) |
| `/admin/login` | Authentification **superadmin SA0** (mot de passe + TOTP obligatoire) | Public | `AdminAuthLayout` |
| `/admin` | **Console superadmin** derrière `AdminGuard` → `AdminShell` : santé des services et conteneurs, dépendances externes, journaux (audit · messenger failed · erreurs système). Identité **globale et séparée** — un JWT club ne franchit jamais ce pare-feu, et la session admin ne pose jamais `app.club_id`. Client HTTP dédié (`adminApi`, préfixe `/api/admin`, cookie de session) qui **ne lit jamais** le store JWT club. Contrat : `superadmin-auth.md` | Session SA0 | `AdminShell` |
| `/admin/*` (inconnue) | Redirige vers `/admin` — **hors du shell lazy**, pour qu'une URL admin inconnue ne télécharge pas la console entière | Session SA0 | — |

> Toute URL authentifiée inconnue (dont l'ancienne `/pending-members`) **affiche l'écran 404** (`app/NotFoundPage`, catch-all `router.tsx`), dans `AppLayout` — en-tête et navigation conservés, l'accueil à un clic. ⚠ Elle **redirigeait silencieusement** vers `/` jusqu'au 2026-08-21 (P5-14) : un lien périmé téléportait le gestionnaire à l'accueil sans un mot, et l'écran 404 n'avait aucune route où vivre. Idem `/admin/*`, qui rend la 404 de la console. **Inchangé** : un visiteur ANONYME part toujours vers `/login` d'abord, et un club en onboarding vers le wizard — on ne révèle rien à qui n'est pas entré.

### Guards et redirects (`src/app/AuthGuard.tsx`)

- `isAuthenticated` faux dans `authStore` → redirect `/login` ; 401 API (hors `/api/login`) → clear + redirect `/login` (hook ky `afterResponse`). Le drapeau n'autorise rien : le cookie httpOnly est la seule identité, et le serveur tranche.
- `membershipStatus === "pending"` → `/waiting`.
- **Onboarding** : `AuthGuard` verrouille l'app au wizard tant que `me.seasonPlan.hasFinishedVersion === false` (le club n'a jamais généré). Le flag legacy `club.onboardingCompleted` **n'est plus lu pour le routage**.
- **Gate cockpit** : `CockpitPage` redirige vers `/wizard` tant que `me.seasonPlan.hasFinishedVersion === false`. Le critère est **dérivé** (le plan de saison porte ≥1 version terminée) et **indépendant du pointeur** : rouvrir un planning ne re-verrouille **pas** le cockpit — voir `planning-lifecycle-validated.md` et `specs/courantes/accueil-cockpit-temporel.md` §2ter.
- **Gate matchs / plans secondaires** : bloqués tant que `me.seasonPlan.chosenScheduleId === null` (front désactivé + `SocleGuard` **409** côté serveur).
- **Routes exemptées du verrou d'onboarding** : `AuthGuard` autorise `/wizard`, `/profile` et `/club` (constante `ONBOARDING_ALLOWED`).
  ⚠️ **Écart connu, non tranché** : `/confidentialite` figure au **menu compte** (`AppLayout`) mais **pas** dans `ONBOARDING_ALLOWED` — un club en cours d'onboarding qui clique « Confidentialité » est renvoyé vers `/wizard`. Décision fondateur en attente (l'ajouter à la liste, ou le retirer du menu tant que l'onboarding n'est pas terminé).
- **`/doleances/:token` et `/admin*` sont hors de cet arbre** : la page doléances est publique (aucune session), la console superadmin a sa propre garde (`AdminGuard`) et sa propre session.

### Routes non livrées

Il n'existe **pas** de routes `/dashboard`, `/teams`, `/priorities`, `/schedules/:id` ni
`/schedules/:id/diagnostics` : le planning et ses diagnostics vivent sur `/`, le CRUD
équipes/salles/coachs et le tri par priorité vivent dans le wizard (`/wizard`, rééditable).

---

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
- **Aucun header `X-Club-Id`.** Le club actif est résolu **côté serveur** depuis la membership
  du JWT (`backend-inventory.md` §4) — un header falsifié est refusé en 403.
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
`/.well-known/mercure` → Mercure hub.

> ⚠️ **Écart connu, non tranché** : `docker/frontend/nginx.conf` conserve un bloc
> `location /engine/` → `http://engine:8000/`. Il n'est appelé par aucun code de
> `frontend/src/`, mais il ouvre une route que la frontière §2 interdit. Décision fondateur
> en attente (le retirer, ou documenter pourquoi il reste).

---

## 5. Suivi temps réel de la génération — Polling (Mercure non consommé)

**État livré : le frontend ne consomme PAS Mercure.** Aucun `EventSource` dans `frontend/src/`.
Le suivi de génération se fait par **polling TanStack Query** (`src/features/planning/queries.ts`) :
la query des schedules a un `refetchInterval` de **2 500 ms tant qu'un planning est en vol**
(statut `PENDING`/`GENERATING`), désactivé sinon. `WaitingApprovalPage` poll `/api/me` toutes les 5 s.

Côté infra, le backend publie bien sur Mercure (topic `club:{clubId}:schedule:{scheduleId}`,
voir `backend-inventory.md` §5) et les proxies existent (Vite dev et Nginx prod exposent
`/.well-known/mercure`) — la bascule polling → SSE reste donc possible sans changement d'infra.

### Règles (si la consommation SSE est introduite un jour)

- **EventSource sur `/.well-known/mercure`.** Jamais d'URL hardcodée vers le hub Mercure directement.
- **Invalidation Query sur événement**, pas de mutation directe du cache sauf pour le statut.
- Tant que ce n'est pas fait, le polling à 2,5 s pendant la génération est la référence.

---

## 6. Besoins identifiés par l'expérience (forward)

Cette section capture les besoins frontend qui émergent de l'expérience produit, pas du
code existant. Ils guident le rebuild.

### 6.1 Onboarding guidé non-négociable

Le gestionnaire arrive avec ses données en vrac (Excel, papier, mémoire). Le frontend doit
le guider étape par étape sans le perdre. Le wizard livré compte **6 étapes** (Équipes →
Gymnases → Coachs → Contraintes → Récapitulatif → Génération — détail : `frontend-wizard.md`).
Le frontend doit :

- Sauvegarder à chaque étape (mutations API immédiates)
- Permettre la navigation arrière sans perte
- Valider chaque étape (`useStepValidation`, erreurs bloquantes + avertissements non bloquants)

### 6.2 Visualisation planning = `WeekGrid` (custom)

Le planning est une semaine type (**lundi→dimanche** — `lib/grid.ts:312` filtre `dayOfWeek >= 1 && <= 7` ; le samedi était la borne avant P4-37, alors qu'une séance du dimanche était placée par le solveur et imprimée par l'export tout en étant escamotée de l'écran), rendu par le composant maison `WeekGrid`
(`src/features/planning/WeekGrid.tsx` + `lib/grid.ts`) — pas de FullCalendar :

- Créneaux colorés, filtre par ressource (`ResourceFilter` : équipe / coach / salle). Il vit **ligne 1 de `PlanningToolbar`, contre le sélecteur de vue** dont il suit le libellé (« Par gymnase » → « Gymnases : … ») — séparés, c'étaient deux contrôles sur les mêmes ressources à deux endroits, dont le second passait inaperçu (P4-43). Un filtre **posé se voit** : bordure et texte en accent, graisse medium. ⚠ **Sans fond teinté, délibérément** — mesuré, `text-accent` sur `bg-accent/10` tombe à 4.18:1 en thème clair, sous les 4.5:1 de WCAG 1.4.3 ; le jeton est verrouillé par `a11y-contrast.spec.ts`. ⚠ **L'export ne connaît pas ce filtre** : `ExportMenu` porte son propre périmètre gymnase et le rendu est serveur.
- Click sur créneau → détail (`SlotDetail` : équipe, coach, salle, verrou). **Sous-ligne compacte (2026-08-16, volet B)** : une seule ligne discrète sous le titre — `<catégorie> · <durée> min · Coach <nom>` (séparateur « · », un segment vide omis sans « · » orphelin) — remplace les trois lignes étiquetées `Catégorie`/`Coach`/`Durée` d'avant cette date. **Enrichi par P2-2/F1 (2026-08-12)** : le wrap dit **POURQUOI** le créneau est verrouillé (« Réservation gymnase » / « Épinglé manuellement » / « Origine inconnue ») et liste les **contraintes applicables**, composées côté client depuis `GET /api/constraints` (aucun calcul serveur nouveau). ⚠ « Origine inconnue » se lit comme une **ignorance**, jamais comme une absence de verrou — c'est cette nuance qui décide si le gestionnaire ose déplacer. **Le panneau de créneau dit ce que chaque règle FAIT (2026-08-12)** — `describeConstraint` dérive la substance de `family`+`config` (« Samedi interdit », « Au moins 1 séance à Matéo », « Préfère Matéo »), rendue sur **UNE seule ligne par contrainte** : le nom libre n'apparaît **que faute de description dérivable** (repli, jamais en doublon — depuis le 2026-08-16, volet B ; avant cette date la description primait et le nom libre restait affiché en second sous elle). Les contraintes d'un même groupe se séparent désormais par un simple trait (`divide-y`) au lieu d'un espacement vertical. **La description NOMME aussi sa cible depuis P4-94 (2026-08-14)** — forme « \<cible\> · \<prédicat\> », même vocabulaire que l'auto-nommage du wizard (`ConstraintsStep.build()`) : équipe/coach → leur nom résolu depuis les lookups du planning, `CLUB`+`targetTag` → « Groupe \<tag\> », `CLUB` nu → « Toutes les équipes » ; cible introuvable (équipe/coach supprimé) → **prédicat seul**, jamais « ? · … ». Le choix du libellé de cible reste de la PRÉSENTATION (le branchement sur `scope` ne décide rien d'applicabilité, `applicableConstraints` reste seul juge). ⚑ **Pourquoi** : le nom est saisi par le gestionnaire, il peut être périmé ou copié — une contrainte réellement « samedi interdit, tout le club » mais nommée « SM2 au moins 1 seance a Mateo » s'affichait sur un créneau U11 et rendait le produit **invérifiable**. C'est de la PRÉSENTATION (autorisée), jamais une décision d'applicabilité (interdite au front, cf. `.claude/rules/frontend.md`). ⚠ Familles décrites : DAY (`forbiddenDays`/`allowedDays`), FACILITY (les 4 clés de gymnase), TIME, COACH_AVAILABILITY. **Tout le reste retombe sur le nom — sans inventer** : `forcedDays` legacy (sens ambigu), gymnase introuvable, clé inconnue. Une description approximative serait le même mensonge sous une autre forme. **Les deux panneaux latéraux sont bornés à la hauteur de la grille** et défilent en interne (mesuré : `cardBottom == rowBottom`, `scrolled > 0`). **Sélectionner un créneau REPLIE les diagnostics** — ils ne disparaissent plus : la barre repliée garde le compte et la sévérité max (« Diagnostics du système (12) · 3 alertes »), rouvrable d'un clic, restaurée à la fermeture du créneau. L'exception « sauf les ERROR » du 2026-08-12 est **retirée** : le repli rend la place sans rien enterrer, donc le cas particulier n'a plus lieu d'être. **Le panneau de créneau (2026-08-12)** : contraintes **repliées par défaut** avec leur NOMBRE visible replié (« Contraintes applicables (3) » — on sait s'il y a à ouvrir sans ouvrir), liste bornée en hauteur qui **défile en interne** au lieu d'agrandir l'aside, et deux groupes libellés **« Cette équipe » / « Tout le club »**. Sélectionner un créneau **masque les diagnostics** — ⚠ **sauf s'il reste des `ERROR`** : une erreur grave qui disparaît sur un clic serait le prochain défaut de confiance. C'est un état DÉRIVÉ, pas une mutation : fermer le créneau restaure le panneau à l'identique. ⚑ **Une contrainte `CLUB` portant un `targetTag` ne s'affiche que sur les équipes TAGUÉES** — miroir de l'éclatement backend (`ScheduleConstraintBuilder.php:846-870`) ; tag qui ne résout aucune équipe → affichée **nulle part** (miroir du NO-OP). Avant, `case "CLUB": return true` l'affichait partout : le panneau annonçait une règle que le solveur n'avait jamais appliquée à cette équipe. **Une bannière UNIQUE de péremption (2026-08-12)** nomme sa ou ses causes — « modifié manuellement » et/ou « une contrainte a changé depuis la génération » — plutôt que d'empiler deux bandeaux que le gestionnaire finirait par ignorer tous les deux. Le planning n'est pas FAUX, il est **périmé** : il décrit un état antérieur des règles, et l'action est de **régénérer pour savoir**. ⚠ Sur un planning **validé** (lecture seule), elle propose « Rouvrez ce planning, puis régénérez » — jamais un « Régénérer » nu qui rendrait 409. **Et depuis F2b (2026-08-12) il PEUT déplacer sûrement** : le geste passe par `/move`, donc par le verdict du moteur — un refus s'affiche **avec ses motifs nommés** (« le coach X a déjà… »), une génération en cours bloque le geste, et un déplacement accepté pose une bannière **« score périmé »** (le score affiché décrivait le planning d'avant). **Un diagnostic `conflict` ouvre LE créneau fautif depuis P4-95 (2026-08-14)** — jusque-là un clic ne faisait que surligner un rapprochement équipe/gymnase/coach (`DiagnosticsPanel`, comme l'« unused_slot » qui amène la colonne du gymnase à l'écran) ; un `conflict` PORTE désormais (gymnase, jour, heure) — deux champs additifs sur `schedule_diagnostic` (`dayOfWeek`/`startTime`, nullable, SEULEMENT ce type, contrat backend⇄engine inchangé, le schéma 2.6 les portait déjà côté engine) — donc `concernedSlots` resserre sur le créneau EXACT (gymnase+jour+heure-à-la-minute) et le clic sélectionne + scrolle jusqu'à lui (`data-slot-id` sur chaque cellule/membre de `WeekGrid`) au lieu de surligner un ensemble. Les 10 autres types de diagnostic restent au rapprochement large — résidu ouvert, `roadmap.md` P4-95
- Lecture seule quand le plan **pointe** la version affichée (`Schedule.isChosen` — le verrou d'édition)
- Pas de vue mensuelle — le planning est hebdomadaire type
- **Libellé de groupe fusionné, vue GYMNASE seulement (P2-17, 2026-08-14)** : quand un `VenueTrainingSlot` porte un `groupLabel` non vide ET que ≥ 2 équipes partagent son gymnase/jour/heure-de-début, `lib/grid.ts` fusionne leurs cellules en **une seule carte titrée par le libellé** — chaque équipe reste **individuellement cliquable** (`GridCellMember.slotId` ouvre le même `SlotDetail` qu'une carte séparée). Une seule équipe sous un libellé retombe sur la cellule ordinaire (pas de carte à un membre). Les vues **équipe** et **coach** sont inchangées — la fusion n'existe que côté gymnase. Purement esthétique : le libellé est **affiché**, jamais redérivé (le backend le calcule et le normalise) et ne rejoint jamais le payload solveur. Saisi dans le wizard (`GroupLabelField`, étape Gymnases — `frontend-wizard.md` item 2), affiché en **badge** (sans fusion) sur la grille « Réserver » (`frontend-wizard.md` item 4)
- **Le diagnostic d'une séance manquante NOMME la règle en cause et y mène (P4-99, 2026-08-15)** : sur un `session_below_effective_min`, `DiagnosticsPanel` rend **une ligne par cause** — le **NOM de la contrainte** en information principale, sa famille en complément (« 8 créneaux fermés par « Groupe EMB · pas après 17:30 » (une plage horaire trop étroite) »), plus un `WizardStepLink step="constraints" params={{edit: constraintId}} from="planning"` **« Corriger cette règle »** — même rail `?edit=` que P2-25/P4-95. ⚑ **Le nom, pas seulement la famille** : trois causes `time_window` issues de trois règles différentes rendraient sinon trois lignes IDENTIQUES avec trois liens divergents — illisible au moment précis où l'écran doit éclairer (falsifié par un test « deux causes de même `kind` doivent être distinguables »). **Dégradations, toutes explicites** : `constraintId` null → la cause s'affiche **sans lien** (jamais de lien mort) ; `label` null ou vide → repli sur la famille (jamais « null », jamais le code brut) ; `kind` inconnu (donnée future de l'engine) → le compte seul, aucun plantage. **`openCandidates` n'est PAS une cause** et a sa phrase dédiée (« 5 créneaux restaient disponibles — le planning y a placé une autre séance »), affichée **seulement si non-null ET > 0** : `0` (« aucun n'est resté ouvert ») et `null` (« non mesuré ») ne se confondent pas. ⚑ **Zéro redérivation** — le front AFFICHE ce que le backend a mesuré à la pose des contraintes ; le `Record<DiagnosticCauseKind, string>` est un choix de **libellé** (présentation autorisée), sur un `kind` hors des `POLICED_ENUMS`, jamais un décideur de comportement. Les 10 autres types de diagnostic sont inchangés (résidu P4-95)
- **4e vue « Par jour » (P2-33, 2026-08-17)** : `ViewMode` (`planning/store.ts`) gagne `"jour"`, choisi comme les trois autres dans `PlanningToolbar`. En vue jour, la ressource **FILTRABLE** devient le **jour ISO** de la semaine (`ResourceFilter` affiche « Jours : … », libellés **en toutes lettres** — `dayLabelLong`, `shared/lib/days.ts` — au lieu de l'abrégé des en-têtes de grille), défaut = tous les jours cochés. ⚑ **Les colonnes de grille, elles, restent les gymnases** : `lib/grid.ts` n'a pas de second moteur de layout, il aiguille toute la composition (colonnes, libellés, couleurs, fusion P2-17) sur un alias interne `columnView` (= `"gymnase"` quand `viewMode === "jour"`, sinon `columnView === viewMode` comme avant) — seul le **filtre** lit le vrai `viewMode`. Les jours du filtre sont triés en **ordre ISO** (lundi→dimanche), jamais alphabétique. Les cases vides restent incluses (mode cible P2-30 inchangé). Utilité : un club à beaucoup de gymnases (le wizard notamment) peut filtrer sur un jour et retomber à quelques colonnes au lieu du scroll horizontal permanent
- **5ᵉ vue « Par club » (P3-20, 2026-08-18)** : `ViewMode` gagne `"club"`. ⚑ **Ce n'est PAS une 5ᵉ mise en forme de la grille** — c'est la matrice **équipes × jours** que seuls les exports savaient produire (section 2 du PDF, feuille « Équipes × jours » du XLSX), donc un rendu à part (`lib/clubView.ts` pour la projection, `ClubViewTable.tsx` pour le rendu) : `buildGrid` ne la connaît pas et n'est pas réécrit (la page ne lui passe même aucun créneau dans cette vue). **Contenu = les règles des exports, à l'identique** — une ligne par équipe de la saison **y compris sans aucune séance** (le trou est ce qu'il faut voir : la ligne le DIT, « aucune séance »), deux séances le même jour = deux entrées triées par heure, colonnes = les jours réellement utilisés en ordre ISO, **aucun coach** (décision fondateur des exports : il vit dans la grille et n'encombrerait que le balayage), les fenêtres VIDES n'y entrent pas (elles n'appartiennent à aucune équipe). Lignes groupées par RANG comme la vue « Par équipe » ; l'axe FILTRABLE est l'équipe (« Équipes : … »). ⚑ **Une vue différente, les MÊMES gestes** (décision fondateur) : `ClubViewTable` porte exactement le contrat de props de `WeekGrid` et reçoit les mêmes handlers — sélection → `SlotDetail`, cadenas en un clic (bouton **frère**, jamais imbriqué), lentille de verrous, priorité surlignage conflit > mode cible > lentille, `data-slot-id` pour le clic-diagnostic. ⚠ **Sa seule limite, dite à l'écran** : en mode cible, désigner une séance existante fonctionne (la destination se déduit de son placement), mais une case (équipe, jour) **vide** n'est pas une destination — un couple équipe/jour ne porte ni gymnase ni horaire. Un bandeau `role="status"` renvoie alors vers « Par gymnase »/« Par jour », au lieu de laisser cliquer dans le vide
- **La grille se VOILE pendant tout (re)chargement des créneaux, jamais « Planning vide » avant réponse (2026-08-17, retour fondateur « ça mouline »)** : `useSlots` (`planning/queries.ts`) porte `placeholderData: (previous) => previous` — changer de version/période garde l'ancienne grille à l'écran le temps que les nouveaux créneaux arrivent, au lieu de la vider brutalement. `PlanningPage` lit la requête ENTIÈRE (pas juste `data`) ; `slotsBusy = isFetching` sur la version affichée pilote deux effets : (1) le conteneur de `WeekGrid` passe en `opacity-40 pointer-events-none` (le voile capte les clics, rien ne « passe au travers » vers une grille périmée) avec un indicateur centré superposé (« Chargement des créneaux… », `Loader2` animé, `role="status" aria-live="polite"`) ; (2) au **premier** chargement d'une version (aucune donnée précédente à voiler), l'état `slotsBusy` remplace l'`EmptyState` « Planning vide » qui aurait sinon menti tant que la requête n'a pas répondu — une fois la réponse arrivée et RÉELLEMENT vide, `slotsBusy` retombe et « Planning vide » s'affiche. N'intervient jamais par-dessus `GenerationWaiting` (génération en cours), qui a son propre rendu.
- **Le VOILE bloquant — « bloquer les impatients » (lot C, 2026-08-21, design fondateur)** : `app/ActionVeil.tsx`, monté dans `Providers` (pas dans `RootShell` : le voile est scopé **react-query**, or les étapes du wizard sont du zustand, invisibles de `useNavigation`). ⚠ **Deux temps, et le MOMENT du blocage dépend du contexte — c'est le cœur du réglage** : le blocage (`inert` natif React 19 sur un wrapper `display:contents` + overlay `pointer-events`, dérivé `blocking`) commence **dès 0 ms pour `enregistrement` et `traitement long`** — ils protègent un geste RÉELLEMENT parti, dont le blocage immédiat mange le 2ᵉ clic — mais **à 250 ms seulement pour `changement de page`**, à l'instant où le voile devient VISIBLE (GO fondateur 2026-08-21). Pourquoi cette asymétrie : sur un CHARGEMENT rien n'est parti, aucune double-soumission à empêcher — rien à protéger avant que l'utilisateur ne VOIE pourquoi il est bloqué ; bloquer à 0 ms alors que le voile est encore invisible mangerait des frappes **en silence** (le pire échec : croire son clavier mort), à l'arrivée comme sur une transition rapide. Le voile n'est de toute façon **visible qu'après 250 ms** — sinon il clignoterait à chaque enregistrement de 90 ms. L'asymétrie est gardée par le NR `ActionVeil.test` (`enregistrement` inert dès 0 ms, `page` pas avant 250 ms) : interdiction de fondre les deux régimes. Le `<Toaster />` reste **hors** du wrapper inert : une erreur doit rester lisible au moment précis où elle survient. Scrim opaque `bg-background/60`, **jamais de flou** (le flou signale « cliquable pour fermer », faux ici), `z-[60]` au-dessus de la barre de navigation `z-50`, focus rendu à l'élément déclencheur à la levée. Scène propre : le tableau tactique du coach (SVG+CSS pur, boucle 2 s, figée sous `prefers-reduced-motion`) — `GenerationWaiting` garde sa grille et son ballon, les deux attentes ne se confondent jamais. **Trois contextes** (priorité long > enregistrement > page). ⚠ **« Changement de page » ne s'arme QUE sur une TRANSITION déclenchée par le gestionnaire** (changement d'étape du wizard, de vue/version du planning — déclencheur `shared/stores/navTransitionStore`, ⚠ **armé dans les handlers de CLIC** de `WizardLayout`/`PlanningPage`, jamais dans les actions de store, qui servent aussi au guidage automatique au montage), **jamais sur le simple montage d'un écran** (règle corrigée le 2026-08-21, GO fondateur) : le blocage à 0 ms sert à manger le 2ᵉ clic d'un geste **déjà parti** ; une arrivée n'a rien lancé, et comme le voile est invisible sous 250 ms, geler un formulaire déjà peint mange les frappes **sans retour visuel** — pire que pas de voile (l'ancienne règle « tout premier chargement voile » gelait l'étape 1 du wizard à l'arrivée : `journey.spec.ts`, `veil-double-click.spec.ts`). Le premier chargement **sans cache** (`undefined === q.state.data`) qui suit la transition voile ; un refetch d'arrière-plan jamais. Quatre phrases chacun dans le ton des pages d'erreur (P5-14) : `Enregistrement` et `Changement de page` tournent à 2,6 s ; `Traitement long` avance par **paliers** ~0/5/12/25 s dont le dernier persiste — pas de boucle, parce que le bout-en-bout d'un verdict moteur est mesuré **> 30 s** sur un club dense et qu'une phrase qui revient ferait croire au plantage. **Deux régimes de sortie** : les contextes courts préviennent **et relâchent** au-delà de 10 s (une panne réseau ne doit pas condamner l'app jusqu'au F5) ; le contexte long **ne relâche jamais au chrono** — relâcher autoriserait un second déplacement par-dessus le premier — sa sortie est le bouton **« Abandonner ce déplacement »**, qui `abort()` la requête et resynchronise le même paquet qu'un déplacement accepté, **parce que le serveur a pu l'appliquer quand même** (le message le dit, il ne ment pas). **A11y, deux régimes assumés** : sans bouton `role="status"` + `aria-live="polite"` ; avec le bouton d'abandon le voile EST un dialogue → `role="dialog"` + `aria-modal` (jamais `alertdialog`, qui volerait le focus pour une attente sans urgence). Seule la phrase stable vit dans la région live, la rotation est `aria-hidden` (AUD-FRT-23/24). **Le régime est global par défaut, l'exemption se déclare** (`meta: { veil: false }`) — règle et liste des exemptions légitimes : `frontend/AGENTS.md` §« Toute mutation VOILE l'écran ». Effet de bord voulu : le blocage jusque-là MUET des flèches de réordonnancement dans `TeamsStep` (`reorderBusy`) gagne enfin sa raison visible, sans que le composant soit touché.
- **QUAND l'écran d'attente s'affiche : « une version du plan EN PORTÉE est en vol » (lot C PR-1, retour terrain fondateur 2026-08-21)** — maison unique : le dérivé `showGenerationWaiting = isGenerating || scopeInFlight` de `PlanningPage`. ⚠ Le défaut réparé : `isGenerating` ne dérivait que de la **sélection**, or au lancement la nouvelle version PENDING naît alors que la sélection embarquée pointe encore l'ancienne COMPLETED (ou rien, le temps que la liste se rafraîchisse) — sur un **overlay**, ce trou tombait donc sur le petit voile « Chargement des créneaux… » au lieu du MÊME écran qu'en saison, ce que le gestionnaire a rapporté tel quel (« un chargement qui mouline ?? au lieu d'utiliser le même écran »). `scopeInFlight` regarde la LISTE des versions, bornée à la portée : en portée période, les versions de CE plan (`schedulePlanId`) seul ; sinon celles de la saison. **La portée est une vraie borne, falsifiée dans les deux sens** : une version en vol d'un autre plan (autre période, ou la saison quand on est en portée période) ne déclenche RIEN. **Ce dérivé est la SEULE porte** : les huit gardes qui se taisent ou se grisent « pendant une génération » (bouton de suppression d'overlay, marqueur « périmé », barre d'outils, comparaison, actions, `DriftBanner`, bannière de séances périmées, bannière d'échec) le lisent toutes — sinon ces bannières flotteraient AU-DESSUS de l'écran d'attente, alors que la règle dit qu'il **remplace** le contenu. **Décision fondateur assumée** : pendant un vol, sélectionner manuellement une ancienne version COMPLETED montre malgré tout l'écran d'attente, sans les gestes — c'est la lettre de la règle. Côté `GenerateStep`, `showPlanning` est INCHANGÉ (le correctif du 2026-08-19, « revenir avec deux COMPLETED affiche le planning », tient) ; l'étape n'ajoute que **la fenêtre locale qu'elle seule connaît** — entre le POST et le premier refetch, la version fraîche n'est pas encore dans la liste, donc la portée ne peut pas la voir.
- **L'écran d'attente de génération (`GenerationWaiting.tsx`) est une SCÈNE animée, pas un logo pulsé (design fondateur, 2026-08-17)** — consommé identiquement par `PlanningPage` (`showGenerationWaiting`, voir juste après) et `wizard/steps/GenerateStep.tsx`, sans prop : le composant ne prend plus `initial`/`logoUrl` — `GenerateStep` n'a donc plus besoin de `useMe()` du tout (l'appel a été retiré) ; `PlanningPage` le garde pour ses autres usages. Cadre `bg-card`/`border-border` **pleine largeur, en hauteur bornée** (`h-[22rem] sm:h-[26rem]`) contenant **deux SVG décoratifs ancrés aux bords** (`role="img"` sur UNE seule des deux, un seul texte alternatif) — bandes latérales en terrain de basket filigrané, grille de créneaux qui se remplissent (coche à l'accent), ballon qui rebondit de case en case — et un centre HTML superposé (lisible au lecteur d'écran, `role="status" aria-live="polite"`) : mini-grille 4×4 qui se remplit + ligne de balayage, titre + phrase tournante (rotation 3 s), note de durée. **Aucun logo ni initiale du club** n'y est plus rendu (contraste avec avant cette date — voir `identite-visuelle-club.md`). **Toutes les couleurs lisent les tokens de thème et l'accent du club** (`var(--card|muted|border|muted-foreground|accent)`) — jamais un littéral hex/oklch — donc une seule scène pour les deux thèmes et n'importe quel accent. `prefers-reduced-motion` coupe toutes les animations (`GenerationWaiting.css`, classe `.gw-anim` neutralisée) et fige le ballon sur sa dernière case plutôt que de le figer à mi-course. **Pleine largeur depuis le 2026-08-18 (2ᵉ tranche de P4-107)** : le cadre ne porte plus de `max-w`. ⚠ Retirer le cap ne suffisait pas — le décor était UN svg `800×500` en `h-auto`, donc sa hauteur suivait sa largeur (~1100 px de haut sur un écran 1800 px, à scroller pendant toute la génération). Le décor ne vivant que dans **deux bandes latérales** (le centre est la zone protégée des textes du design), il est scindé en deux `<svg>` cadrés par leur seule **`viewBox`** — `0 0 230 500` à gauche, `570 0 230 500` à droite — ancrés `left-0`/`right-0` : **aucune coordonnée du dessin ni aucune keyframe n'a été réécrite** (les translations du ballon sont en unités utilisateur du svg et suivent l'échelle de leur bande), et le `clipPath` qui découpait les deux fenêtres dans le svg unique devient inutile. `max-w-[26%]` par bande laisse au centre 48 % du cadre à toute largeur ; **sous `sm` les bandes s'effacent** et le centre prend tout — deux bandes à leur taille minimale mangeraient le texte. La seconde bande est `aria-hidden` : deux `role="img"` feraient lire la scène deux fois. La scène est générique (motif basketball codé en dur, pas encore un asset par sport) — l'habillage par sport reste ouvert, `roadmap.md` P5-16.

### 6.3 Tri des équipes drag & drop (mode « Trier » du wizard)

La priorisation des équipes (S/A/B/C/D) vit dans l'étape Équipes du wizard
(`TeamsStep`, bouton « Trier » / « Terminer le tri ») :

- @dnd-kit (`useSortable` + zones droppables par tier) — **drag & drop inter-tier** :
  une équipe peut être déposée dans un autre tier, flèches haut/bas en fallback clavier/a11y
- Couleurs et libellés de tiers cohérents avec le planning
- Sauvegarde **en bulk atomique** à la fin du tri : `POST /api/teams/reorder` avec
  `{ items: [{ id, priorityTierId, tierOrder }] }` (une transaction — remplace les N
  `PUT /api/teams/{id}` concurrents qui perdaient des mises à jour sur le lock optimiste)

### 6.4 Diagnostics en langage gestionnaire

Le rapport post-génération affiche les `schedule_diagnostics` avec :

- Regroupement par severity (error > warning > info)
- Messages tels que rédigés côté backend (langage gestionnaire, pas technique)
- Liens directs vers l'entité à corriger (équipe, coach, salle)
- Pas d'auto-correction MVP — l'utilisateur clique → navigue vers l'entité

### 6.5 Export du planning — LIVRÉ

`ExportMenu` (`src/features/planning/ExportMenu.tsx`) → hook `useScheduleExport`
(`features/planning/queries.ts`) → `POST /api/schedules/{id}/export-pdf` (asynchrone,
Messenger ; handler backend `ExportPdfHandler`).

- **Périmètre au choix** : tous les gymnases, ou **un seul** (`{ venueId }` dans le body) —
  chaque export tient sur une page paysage.
- **Deux formats, deux vues, aucun réglage de vue (2026-08-21)** : PDF et Excel, chacun portant
  **les DEUX** vues — la grille (jours × gymnases) puis la matrice **équipes × jours** (section 2
  du PDF, 2ᵉ feuille de l'Excel). Il n'y a plus de sélecteur : il n'existait que pour l'**image
  PNG**, retirée du produit le même jour (décision fondateur — elle *photographiait* une des deux
  sections déjà rendues, sans rien apporter qu'un format qui ne se feuillette pas ; décision
  fermée dans l'état des lieux §2).
- **La matrice est INCONDITIONNELLE depuis cette date.** Elle dépendait de « ≥ 2 gymnases parmi
  les placements », au motif qu'elle « lève l'ambiguïté sur le gymnase ». C'était la justification
  d'un **déclencheur**, prise pour la raison d'être de la vue : les deux répondent à deux
  questions distinctes — la grille dit *qui occupe quel gymnase ce jour-là*, la matrice dit *quand
  s'entraîne CETTE équipe*, une ligne à lire — et le second besoin existe avec un seul gymnase.
  ⚠ **Les LIGNES de la matrice dépendent en revanche de la PORTÉE** : export « tous les gymnases »
  → toutes les équipes de la saison (une équipe sans séance est un trou du planning, à voir) ;
  export limité à UN gymnase → seules les équipes qui y ont une séance, sinon une équipe
  s'entraînant ailleurs passerait pour une équipe sans entraînement sur un document remis aux
  familles. Cette règle vivait dans le PDF depuis P3-20 ; l'Excel la porte depuis le retrait du
  seuil, qui la lui tenait lieu de garde.
  ⚠ **Les lignes de la matrice suivent la PORTÉE** : sur « tous les gymnases », toutes les
  équipes de la saison (une équipe sans séance est le trou qu'il faut voir) ; sur **un seul
  gymnase**, seules les équipes qui y sont placées — les données d'export portent toujours
  toutes les équipes du club, donc les lister toutes ferait passer une équipe qui s'entraîne
  ailleurs pour une équipe sans entraînement.
- Les fichiers produits sont servis sous **`/exports`** : proxifié par Vite en dev
  (`vite.config.ts`) et par le Nginx frontend en prod (`docker/frontend/nginx.conf`).

### 6.6 Multi-tenant transparent

Le gestionnaire ne voit jamais le concept de `club_id` ou `season_id`. Le frontend :

- N'envoie **aucun** header `X-Club-Id` : le backend dérive le club de la membership du JWT
  (`TenantFilterListener`)
- N'affiche jamais de sélecteur de club (un user = un club en MVP)
- La **saison**, elle, est visible et choisissable : `SeasonSelector` (dans `app/`) écrit dans
  `seasonStore`, qui alimente `X-Season-Id`. Sans sélection, le serveur dérive la saison
  courante (pivot du 15 juillet) — un club mono-saison ne voit donc jamais le sujet. Le
  bandeau `ReadonlySeasonBanner` signale une saison archivée (écritures → 409).

### 6.6 bis Cycle de vie du planning (le pointeur du plan)

- **Valider ↔ Rouvrir sont les deux sorties symétriques du cycle de vie** (arbitrage fondateur,
  symétrie stricte 2026-08-20) : Valider vit dans l'espace de travail (wizard, `embedded`) et en
  est la SORTIE — son succès navigue vers `/planning` ; Rouvrir vit sur `/planning` autonome
  (`!embedded`, l'écran de la version en vigueur) et en est la sortie inverse — il ramène au
  wizard. Le badge de statut et la pastille « Période », eux, sont visibles dans les DEUX modes
  (`PlanningToolbar.tsx`) : conséquence assumée, la pastille « Période » apparaît donc aussi en
  standalone sur un overlay.
- Un planning `COMPLETED` peut être **validé** (bouton « Valider » de la toolbar, visible
  seulement `embedded` — voir §route `/planning` ci-dessus) → modale de confirmation
  (`ValidateDialog`, avertit si des alertes subsistent, nomme le plan réel dans son titre) →
  `POST /api/schedules/{id}/validate` → **le plan pointe cette version** et **ses versions sœurs
  sont supprimées** (ADR-0002 inv. 1) ; le planning passe en **lecture seule** (grille non
  éditable, renommage et régénération masqués). Le statut, lui, **reste `COMPLETED`** : « validé »
  se lit sur le pointeur (`Schedule.isChosen`). Le succès navigue vers `/planning`
  (`PlanningPage.tsx`, `validate()` `onSuccess`) — vaut pour le socle saison comme pour un plan
  de période. **Effet de bord RMM-10 (P2-52)** : à l'ouverture de la modale, `useValidateImpact`
  interroge `GET /api/schedules/{id}/validate-impact` (armé seulement quand le geste est envisagé,
  `staleTime: 0`) — `ValidateDialog` n'affiche l'avertissement « salle perdue » que si l'impact est
  N>0 (zéro bruit préventif) et désactive « Valider » tant que la réponse est en vol ou en échec
  (bouton « Réessayer », jamais un impact inconnu présenté comme vide). La validation elle-même
  dépointe alors ces matchs (`UNPLACED` + raison persistante `venue_lost`, heure conservée) —
  détail métier : `specs/courantes/module-matchs.md` § « P2-52 ».
- « Rouvrir » (`POST /api/schedules/{id}/reopen`, bouton `/planning` autonome uniquement,
  `!embedded`) **dépointe** le plan (inv. 2) : la version survit et redevient éditable. Toute
  navigation qui suit **déclare son mode** (fix terrain 2026-08-19 défaut 3, `PlanningPage.tsx`
  `reopen()`) — jamais un `jumpTo("generate")` nu qui laisserait le mode ambiant du `localStorage`
  décider : la version rouverte est résolue (`schedulePlanId` → plan → `calendarEntryId`) et le
  wizard s'ouvre en mode période (`startPeriodMode`) si ce plan n'est pas `SEASON`, en mode saison
  (`exitPeriodMode`) sinon.
  La même règle route `SeasonSchedulesModal.consult()` (« Plannings de la saison ») : plan
  **pointé** → `/planning` ; plan **non pointé** → `/wizard`, mode déclaré pareil.
- Il n'existe **pas** de « Définir principal » : le pointeur se déplace **en validant**, et par rien
  d'autre (`set-baseline` supprimé, inv. 18). Le ★ de la saison = `seasonPlan.chosenScheduleId`
  de `/api/me`.
- La liste « Plannings de la saison » (`SeasonSchedulesModal`) affiche l'état du **plan**, pas le
  statut brut de la version (fix terrain 2026-08-19 défaut 1) : pointé → « Validé » ; `COMPLETED`
  non pointé → « Terminé · à valider » ; ouvert (`PENDING`/`GENERATING`) → « … · en cours ». Deux
  plans `COMPLETED` peuvent donc porter des libellés différents selon lequel est pointé.

### 6.6 ter Informations du club (fiche FFBB — lot B)

La route `/club` expose une section **« Informations du club »** (admin uniquement, `AccordionSection`)
qui édite les métadonnées FFBB du club, regroupées : **Identité** (code FFBB + ligue + zone de vacances
en lecture — auto-dérivés à l'onboarding ; code comité éditable), **Contact**, **Correspondant**,
**Président**, **Salle principale**. Un bouton « Enregistrer » envoie un `PATCH /api/club/info`
(management-gated SEC-07) qui met à jour **uniquement les champs présents** dans le body (partiel ;
`''` réinitialise à `null`), valide les emails et les longueurs (`422` sinon), puis invalide `["me"]`.
Les valeurs sont lues depuis le bloc `club` de `/api/me`. Saisie **manuelle** aujourd'hui ; l'autofill
depuis la fiche FFBB est prévu en lot C.

> **RGPD (minimisation).** Président et correspondant sont des **contacts professionnels** (données
> publiques de la fiche FFBB : nom, téléphone, email). **Aucune adresse de domicile** n'est stockée —
> seule l'adresse du club et de la salle principale (lieux publics) le sont. Base légale actée avec P0-1 (DP1 soldé) — [`../../docs/security/rgpd.md`](../../docs/security/rgpd.md) §2.

### 6.6 quater Statistiques d'utilisation des gymnases (P3-22, 2026-08-17)

La route `/club` expose un encart **« Statistiques d'utilisation »** (`VenueStatsSection`) qui
remplace intégralement l'ancien calcul front (`computeVenueStats`/`seasonWeeks` de
`lib/venueStats.ts`, une moyenne hebdomadaire du planning en vigueur + une projection saison
brute, livré le 2026-08-04 — **supprimé avec ses tests**, `lib/venueStats.ts` ne garde plus que
`formatHours`). **Tout le calcul est désormais SERVEUR** (`GET /api/venue-usage-stats`, détail
route : `backend-inventory.md`) — le front n'agrège plus rien de métier, il affiche.

- **Deux tableaux** (`UsageTable`) : **par gymnase** et **par niveau**, mêmes colonnes — un jour
  par colonne (lundi→samedi, dimanche seulement s'il porte des heures) puis Réalisé / À venir /
  Total, et une **ligne TOTAL par jour** visuellement marquée (`border-t-2`, gras) — le chiffre
  que le gestionnaire pose sur la table de négociation avec la mairie (« le lundi, on a 8 h »).
- **Sélecteur de plage** (`<input type="date">` Du/Au) **borné à la saison courante** (`min`/`max`
  sur `season.startDate`/`season.endDate`) ; défaut = saison entière. La plage réellement
  appliquée est redite sous les champs (`data.range.from/to`, celle que le backend a résolue).
- **Sans planning en vigueur** (`me.seasonPlan.chosenScheduleId` null), la section **dit
  pourquoi** elle est vide plutôt que d'afficher des tableaux à zéro — même doctrine que le
  reste de l'app (pas de silence).
- **Ventilation par niveau, une ligne par `TeamLevel` réellement utilisé** (aucune table de
  regroupement front — le backend sérialise déjà le libellé, `TeamLevel::label()`). ⚑ **Le
  libellé de niveau existe donc en DEUX endroits** : `TeamLevel::label()` côté backend
  (source pour cet encart) et `LEVEL_LABEL` du wizard (`features/wizard/lib/labels.ts`, pour
  l'étape Équipes qui affiche le niveau sans aller-retour réseau) — duplication **assumée**
  (présentation, pas une décision métier, cf. `.claude/rules/frontend.md` régime « présentation
  autorisée »), les deux tables restant alignées par convention (docblock de `TeamLevel::label()`
  le rappelle) plutôt que par un test de parité.

### 6.7 Retouche manuelle — mode cible, éviction, dérive, verrouiller (rail read-only + verdict moteur, 2026-08-16)

`schedule_slot_templates` est **read-only côté API** (`GetCollection`/`Get` seulement — POST/PUT/
DELETE et leur processor/DTO d'entrée ont disparu). Toute écriture passe par un rail dédié, jamais
par un CRUD brut sur la ressource :

- **Déplacer — mode cible click-click (P2-30 PR B, 2026-08-16, lot SOLDÉ)** : « Déplacer » sur le
  panneau `SlotDetail` (`onArmMove`) **ARME** le mode cible au lieu d'ouvrir un formulaire — **la
  décision fondateur D11 supprime le formulaire jour/heure/gymnase** (jamais utilisé, la grille
  EST l'éditeur). Armée, la grille (`WeekGrid`) marque la SOURCE (anneau + pulsation), transforme
  chaque case **vide** en un **vrai bouton focusable** « Placer ici — \<gymnase\>, \<jour\>
  \<début\>–\<fin\> » (`aria-label`), et rend chaque carte **occupée** cliquable comme cible ; un
  créneau **verrouillé** reste une cible refusée (tooltip « déverrouillez-le d'abord »), sauf la
  source elle-même. **Échap** ou un re-clic sur la source sort du mode sans rien toucher (le focus
  y revient). Le clic sur une case est routé par `WeekGrid.onPickTarget` — la PAGE décide (annuler,
  déplacer, évincer, placer) : la grille ne fait que router.
  - **Case d'un gymnase FERMÉ (P2-43 volet v, 2026-08-19)** : une fenêtre (gymnase, jour)
    effectivement fermée sur la version de PÉRIODE affichée est **marquée** « Fermé — … » (inerte,
    jamais un vrai bouton) au lieu d'être offerte comme cible — l'ancien régime affichait 100 % des
    créneaux vides d'un gymnase fermé comme boutons « Placer ici » que le serveur refusait ensuite
    (`slot_unavailable`), un aller-retour moteur perdu par clic. L'état vient de
    `useEntryConflicts` (jamais recomposé côté front — `computeClosedWindows`,
    `frontend/src/features/planning/lib/closedWindows.ts`) : l'OFFRE (armement du mode cible, filtre
    de `onPickTarget`) est **fail-closed**, l'AFFICHAGE reste **fail-open** (rien de masqué tant que
    l'état n'est pas résolu — doctrine « on annonce, on ne cache pas »). `entryId` vient en prop
    depuis `GenerateStep` en embarqué, sinon dérivé du plan de la version affichée (jamais le
    socle).
  - **L'armement suit son ancre, pas l'écran entier (P4-119 d, 2026-08-19)** : un DÉPLACEMENT tombe
    dès que le panneau du créneau SOURCE se ferme, qu'on change de vue ou de version affichée
    (`selectedSlotId` quitte la source) ; un PLACEMENT porte le contexte (version + vue) où il fut
    armé et tombe si l'un change ou si son équipe cesse de dériver. Avant ce correctif, l'armement
    survivait à la fermeture de son panneau — chaque clic suivant sur un créneau devenait une
    nouvelle tentative de déplacement non voulue. Échap reste préservé.
  - **Priorités visuelles** : surlignage **conflit** > **mode cible** > **lentille verrous** — la
    lentille se tait tant qu'un conflit règne OU que le mode cible est armé (elle ne doit jamais
    brouiller ni le rouge du conflit, ni la cible en cours de choix).
  - **Case libre** : `POST /api/schedule-slots/{id}/move` (`useMoveSlot`, sans `evictSlotId`).
    **Pas d'optimistic update** — la grille attend le verdict du moteur (`MoveFeedback` : `pending`
    pendant l'appel, ~500 ms).
    - **Accepté (200)** : `slots`/`schedules`/`diagnostics` sont invalidés (le moteur a rejugé la
      légalité, les diagnostics peuvent bouger) et un toast succès (« Créneau déplacé. ») confirme
      le geste — sans lui, un déplacement accepté était indistinguable d'un refus silencieux.
    - **Refusé (422)** : rien n'est écrit ; `moveState` passe `rejected` avec les règles violées
      NOMMÉES (`SlotDetail`, déjà documenté §6.2 F2b) — le **mode cible reste armé** pour
      réessayer. Chaque violation porte aussi les ids de l'entité fautive
      (`teamId`/`coachId`/`venueId`/`dayOfWeek`/`startTime`/`conflictingTeamId`, null-safe —
      miroir de `AssignmentViolationSchema`, contrat 2.8) : la grille **surligne** le créneau de
      l'équipe déjà en place que le moteur a nommée (`violationHighlightSlotIds` — présentation
      pure, aucune redérivation de règle ; une équipe absente du cache affiché n'ajoute aucun
      surlignage fantôme). Le surlignage s'efface au retour à `idle`/`pending` (nouveau créneau
      sélectionné, nouvel essai) sans jamais écraser un surlignage venu d'un diagnostic.
    - Génération en cours (409) / moteur injoignable (502) : `blocked`/`error`, déjà documenté
      §6.2 — toastés sans rester en panneau (409/502 nomment un contexte transitoire, pas une
      règle à corriger). **Moteur trop lent (504 `engine_timeout`, incident terrain 2026-08-17)** :
      DISTINCT du 502 — le moteur travaillait, il a juste dépassé le délai transport (20 s,
      `MoveSlotService::VALIDATE_HTTP_TIMEOUT_SECONDS`) ; rien n'est écrit. Sur un move/place
      DIRECT (case libre, sans essai préalable), `EngineTimeoutError` suit le même traitement que
      `TargetLockedError`/`SlotEditError` : toast NOMMÉ (message serveur, jamais un numéro nu), le
      mode cible reste armé pour réessayer. Sur l'ESSAI d'éviction (case occupée), voir l'état
      `failed` ci-dessous — la modale reste ouverte au lieu de se fermer en silence. **Timeout
      CLIENT (P4-119 a, incident terrain 2026-08-19)** : le rail move/place/dry-run attend
      désormais **45 s** (`MOVE_VERDICT_TIMEOUT_MS`, `api.ts`) — snapshot + budget transport moteur
      20 s + marge bout-en-bout mesurée > 30 s sur un club dense — au lieu du timeout par défaut de
      ky (~10 s), qui abandonnait la requête (nginx 499) AVANT que le moteur ait tranché : les logs
      du fondateur montraient un `valid=True` rendu UNE seconde après l'abandon client.
  - **Case occupée → éviction, remplie par un ESSAI (P2-32, décision fermée D6+D8, 2026-08-16)** :
    un clic sur une carte occupée arme `evictDialog` en **`checking`** et lance IMMÉDIATEMENT un
    **dry-run** — `POST /api/schedule-slots/{id}/move` avec `evictSlotId` = l'occupant **et
    `dryRun: true`** (`useMoveDryRun`) — le moteur juge SANS RIEN ÉCRIRE. `EvictConfirmDialog`
    (`frontend/src/features/planning/EvictConfirmDialog.tsx`) porte quatre états :
    - **`checking`** : « Vérification… » (spinner), aucun bouton de confirmation ;
    - **`accepted`** (dry-run `valid: true`) : « Ce créneau est occupé par \<équipe\>. […] » **+
      les compromis NOMMÉS du candidat** (`CompromiseList` — pastille « Concession » pour un
      `effect: "broken"`, « Gain » pour `"gained"`, broken listés en premier ; liste vide →
      « Aucun compromis détecté. ») **+** le bouton **« Déplacer et évincer »** qui déclenche
      cette fois le move **RÉEL** (sans `dryRun`) ;
    - **`refused`** (dry-run `valid: false`, un 200 — **pas** un 422) : les motifs **NOMMÉS**
      (`violations`), **PAS de bouton de confirmation** (« Fermer » seul), et la grille
      **surligne** le conflit nommé (même chemin que le refus d'un move réel) ; le mode cible
      reste armé pour réessayer ailleurs.
    - Un dry-run **refusé pour une raison MÉTIER** au transport (verrou `target_locked` posé
      entre-temps, génération en cours 409) ferme la modale (`onError`, pas `onSuccess`) et
      toaste le motif — le mode cible reste armé.
    - **`failed`** (incident terrain 2026-08-17, affiné P4-119 b le 2026-08-19) : l'essai lui-même
      **N'A PAS ABOUTI** — DISTINCT d'un `refused` : rien n'est tranché, donc la modale **RESTE
      OUVERTE** au lieu de se fermer en silence. **Trois causes NOMMÉES, jamais confondues**
      (`EvictFailureKind`, `EvictConfirmDialog.tsx`) : `timeout` — le SERVEUR a tranché « moteur
      trop lent » (504 `engine_timeout`) → « La vérification n'a pas abouti — le moteur n'a pas
      répondu à temps. » ; `unreachable` — une vraie panne réseau/5xx du backend → « … le moteur
      est indisponible. » ; `interrupted` — l'attente a été coupée CÔTÉ CLIENT avant la réponse
      (timeout `MOVE_VERDICT_TIMEOUT_MS` atteint ou navigation) : **aucune preuve de panne** (le
      moteur répondait peut-être `valid` juste après), donc surtout PAS « indisponible » → « La
      vérification a été interrompue avant la réponse — réessayez. » C'est cette 3ᵉ cause qui
      corrige le bug fondateur du 2026-08-19 : un déplacement parfaitement LÉGAL affichait
      jusque-là « le moteur est indisponible », un diagnostic FAUX puisque le moteur avait
      répondu `valid=True` une seconde après l'abandon client. Chaque état propose
      **« Réessayer »**, qui rejoue le dry-run sur la MÊME cible (repasse par `checking`). Demande
      fondateur explicite : ne jamais laisser un échec de vérification silencieux.

    Confirmé (état `accepted`) → `/move` réel avec `evictSlotId`, sans `dryRun`. 200 → toast
    nommé, suffixé **« — N compromis »** si le geste réel en a produit (« \<source\> déplacée —
    \<évincée\> est à replacer — N compromis. ») et une **barre d'éviction+compromis** apparaît
    sous la toolbar (voir bandeau combiné ci-dessous) ; elle disparaît au geste suivant ou au
    changement de version. Une carte **verrouillée** n'est jamais une cible d'éviction (D3,
    verrou souverain) — désactivée avec un tooltip.
  - **Case libre → aucun dry-run (décision fermée D8)** : un clic sur une case **vide**, en mode
    cible move OU place, écrit **directement** (`doMove`/`doPlace`, sans essai préalable) — « un
    clic = écrit, l'undo (geste 4) est le filet » ; pas de bouton « Essayer » séparé (différé,
    hors scope P2-32). Seule la case **occupée** passe par l'essai de la modale d'éviction.
  - **Compromis nommés (P2-32)** — un compromis (type `Compromise`, miroir de `CompromiseSchema`,
    contrat 2.10) est une préférence **SOUPLE** que le geste accepté **casse** (`effect:
    "broken"`) ou **rétablit** (`effect: "gained"`), déjà `message` HUMAIN (le moteur y nomme
    équipe/coach/gymnase, aucun id interne). `CompromiseList`
    (`frontend/src/features/planning/CompromiseList.tsx`) l'affiche en **présentation pure** :
    tri broken-d'abord puis gained, pastille sobre par effet (jamais de rouge destructif — ce
    sont des compromis LÉGAUX, pas des erreurs), un effet inconnu dégrade en pastille neutre
    plutôt que de planter. **Après tout geste ÉCRIT accepté** (move simple, move+éviction, place,
    raccourci « remettre l'évincée ») portant `compromises.length > 0` : le toast se suffixe
    (« — N compromis ») et un **bandeau combiné** apparaît sous la toolbar — en tête le
    raccourci d'éviction (déjà documenté, geste 2) s'il y en a un, dessous la `CompromiseList` du
    geste ; dismissible (bouton « Ignorer », ferme les deux) ; **purgé** au geste suivant, à
    l'undo (geste 4) et au changement de version.
  - **Placer une séance à la dérive (geste 3)** : le bandeau « Séances à replacer » (voir
    ci-dessous) arme le mode cible en **placement** — `POST /api/schedules/{id}/place-slot`
    (`usePlaceSlot`). **Décision fondateur (option a) : placer pose sur du LIBRE, jamais
    d'éviction au placement** — une case pleine se libère d'abord par un `/move` ; sur une case
    **occupée**, `doPlace` appelle `place-slot` **directement** (le rail ne porte pas
    `evictSlotId`) et laisse le **moteur trancher la capacité** (une case partagée type CEC peut
    accepter à côté). *Option b (évincer au placement) reste délibérément hors scope — on
    n'itère que si le terrain le réclame, décision fondateur.* Un refus toast la première
    violation et surligne le conflit ; le mode placement reste armé.
- **Bandeau « Séances à replacer » (`DriftBanner`, geste 3)** — présentation pure
  (`lib/drift.ts`, module PUR) : les équipes qui ont **moins** de séances placées que le seuil
  attendu, sur un planning **`COMPLETED`** seulement (hors génération). Un bouton par équipe
  arme le mode placement pour elle. **Règle de seuil, selon la COUCHE affichée (ADR-0002)** :
  sur le plan **SAISON**, le seuil est `Team.sessionsPerWeek` ; sur un plan de **PÉRIODE**, il
  vient de l'override du plan — équipe **désactivée** pour la période → jamais en dérive (elle ne
  joue pas la période), `sessionsPerWeek` overridé (non nul) → c'est LUI le seuil, sinon repli sur
  le seuil de saison. **FAIL-CLOSED** : sur une période dont les overrides ne sont pas encore lus,
  aucune dérive n'est affichée (pas de dérive fantôme devinée).
- **Annuler le dernier geste (geste 4, profondeur 1, session)** — bouton dans la barre compacte
  (à côté de « Diagnostics du système » / « Verrous manuels »), visible tant qu'un geste est
  annulable. Un `move` simple s'annule par le move inverse ; un `move` avec éviction s'annule par
  le move inverse **puis** un replacement de l'évincée (deux verdicts moteur) — un échec du
  second est **dit honnêtement** (toast : « \<source\> est revenue, \<évincée\> reste à
  replacer. »), jamais maquillé en succès. Le raccourci d'éviction et l'undo se réinitialisent au
  changement de version affichée.
- **Le hook POSSÈDE son feedback métier** (`useMoveSlot`/`usePlaceSlot`/**`useMoveDryRun`**) : un
  `onError` de NIVEAU HOOK **tait** les refus métier (`MoveRejectedError`/`TargetLockedError`/
  `SlotEditError`/`GenerationInProgressError`/`EngineTimeoutError` — la page les affiche dans son
  contexte) et ne parle que d'un vrai échec transport imprévu. Sans lui, le filet global
  `MutationCache.onError` (qui ne toaste QUE les mutations SANS `onError` de niveau hook) doublait
  un refus 422 en **« Problème de connexion. Vérifiez votre réseau. »** — mensonger, le réseau
  allait bien. `useMoveDryRun` (P2-32) suit la MÊME règle mais un dry-run **refusé n'est jamais
  une erreur** — c'est un 200 `{valid:false}` qui **résout** (`onSuccess`), pas une exception ;
  son `onError` voit `TargetLockedError`/`SlotEditError`/`GenerationInProgressError` (fermeture de
  la modale, refus MÉTIER) **et** `EngineTimeoutError`/toute autre panne transport (la modale
  passe en `failed` au lieu de se fermer — incident 2026-08-17, voir ci-dessus), jamais
  `MoveRejectedError`. `moveSlot`/`placeSlot` normalisent `compromises` à `[]` au parsing — jamais
  `undefined` à la lecture côté page.
- **Verrouiller/déverrouiller** : deux points d'entrée partagent désormais le même geste
  (**P2-31 PR 2, 2026-08-16**). Le panneau `SlotDetail.onToggleLock` **et** un **cadenas en un
  clic directement sur la carte de la grille** (`WeekGrid` : la carte devient un `<div>` wrapper,
  le cadenas est un **bouton FRÈRE** — jamais imbriqué dans le bouton de sélection, HTML
  invalide sinon — permanent si le créneau est verrouillé, révélé au survol/focus sinon ;
  `aria-label`/`title` « Verrouiller/Déverrouiller \<équipe\> » ; **absent en lecture seule**
  (planning validé ou `FAILED`), où le cadenas redevient un simple indicateur passif, non
  actionnable). Les deux appellent le même point d'entrée `requestToggleLock`
  (`PlanningPage.tsx`) → `POST /api/schedule-slots/{id}/manual-edit/lock` (`useLockSlot`). Un
  échec (moteur/réseau) pose un toast d'erreur avec le motif serveur — avant, le cadenas restait
  muet en cas d'échec. **Déverrouiller un verrou `RESERVATION`** ouvre d'abord `ConfirmDialog`
  (« Déverrouiller ce créneau réservé ? ») : c'est un engagement pris hors de l'app (réservation
  de gymnase), à ne pas relâcher par inadvertance — verrouiller, et déverrouiller un
  `MANUAL`/`UNKNOWN`, mutent directement sans confirmation. La confirmation retient **le créneau
  visé** (`pendingUnlockSlotId`), pas le créneau sélectionné : le cadenas de la grille peut viser
  un créneau différent de celui affiché dans le panneau de détail.
- **La garantie « les verrous survivent à la régénération » se DIT désormais à l'écran** (P2-31
  PR 2, 2026-08-16) — jusque-là elle ne vivait qu'en commentaire de code (`model.py` :
  `lockLevel == "HARD"` force la variable). Phrase discrète contre le bouton, dans le bloc
  non-`isChosen` de `PlanningToolbar` : « Vos créneaux verrouillés sont conservés à la
  régénération. ». L'intro du panneau Réserver du wizard (`ReservationPanel`) porte la même
  garantie : « Cliquez un créneau pour y fixer une équipe (verrou HARD, conservé à chaque
  régénération). »
- **Panneau latéral des verrous manuels + lentille** (**P2-31 PR 3, 2026-08-16 — lot SOLDÉ**) :
  la barre compacte de la grille (celle qui porte « Diagnostics du système ») gagne un bouton
  FRÈRE **« Verrous manuels (n) »** (icône `Lock`) comptant les créneaux `lockOrigin === "MANUAL"`
  **seulement** — ni les `RESERVATION` ni les `UNKNOWN`, c'est le travail du gestionnaire à
  rendre visible. Visible seulement quand le panneau est fermé **et** n > 0 (masqué à n = 0) ;
  **absent de la toolbar**. Même affordance que Diagnostics : un clic ouvre `LocksPanel.tsx`
  (nouveau, patron `SlotDetail`/`DiagnosticsPanel`) dans l'aside ; son repli (`PanelRightClose`)
  referme le panneau **et ÉTEINT la lentille** (pas d'état fantôme) ; le bouton de la barre
  revient.
- `LocksPanel` liste les créneaux verrouillés à la main, triés **jour puis heure** ; un clic
  sélectionne et fait défiler jusqu'au créneau (`onSelectSlot`, même mécanisme qu'un clic
  diagnostic). En-tête : « n verrou(s) posé(s) à la main ».
- **La lentille verrous** — toggle « Voir sur la grille » / « Masquer sur la grille »
  (`aria-pressed`) dans le panneau. Active : les créneaux SANS verrou s'estompent
  (`opacity-40`), les verrouillés portent un anneau **+ une icône** par origine — MANUAL =
  accent (`Lock`), RESERVATION = warning/ambre (`CalendarClock`), UNKNOWN = muted
  (`ShieldQuestion`) — **jamais la couleur seule** (WCAG 1.4.1) : une légende (pastille + icône +
  libellé par catégorie) s'affiche dans le panneau tant que la lentille est active. Le
  rouge/`destructive` du conflit reste réservé au conflit : quand `highlightSlotIds` surligne un
  conflit, la lentille se tait le temps du conflit (elle ne brouille jamais le rouge/l'ambre du
  conflit). `lib/lockLens.ts` est la MAISON UNIQUE du mapping origine→icône/couleur/libellé,
  partagée par `WeekGrid` (anneau + icône sur la cellule) et `LocksPanel` (légende) ; `GridCell`
  et `GridCellMember` portent chacun `lockOrigin: LockOrigin | null` (présentation pure — aucune
  règle métier n'y est re-dérivée, cf. `.claude/rules/frontend.md`).
- Sur une carte **fusionnée** (CEC), la lentille agit **par membre** : si tous les membres
  verrouillés d'une carte partagent la même origine, elle porte **un seul picto + un anneau au
  niveau de la carte** (comme une carte simple) ; sinon (origines mixtes) chaque **rangée
  verrouillée** porte son propre picto **EN LIGNE devant le nom d'équipe** et son propre anneau —
  les rangées non verrouillées de la même carte restent muettes.
- **Polissage cadenas/badge (2026-08-16)** : le cadenas de carte (éditable ou passif) passe en
  **bas-droit**, le badge de lentille (icône d'origine) en **bas-gauche** — les deux alignés au
  pixel sur le même axe horizontal (retour fondateur : le haut-gauche du badge empiétait sur le
  nom d'équipe).

### 6.7 bis Transcription depuis le socle — bouton, panneau « à replacer », comparaison (P2-44 PR-2/PR-4, ADR-0004)

Sur un plan de PÉRIODE **vierge** (aucune version), l'étape Génération du wizard
(`wizard/steps/GenerateStep.tsx`) propose une alternative au solve complet : la V1 peut naître
d'une **transcription sans solveur** du socle pointé (backend livré en PR-1,
[ADR-0004](../../docs/architecture/adr-0004-period-plan-birth-as-socle-copy.md)). Aucune API
n'est touchée par ce lot — le front consomme la route `POST
/api/schedule_plans/{id}/transcribe-from-socle` déjà en place.

- **Auto-déclenchement sur une FERMETURE (P2-44 PR-4, 2026-08-20).** Sur un plan de période dont
  le type est FERMETURE (`"closure" === periodType`) et qui n'a **aucune** version, la
  transcription part **automatiquement** à l'arrivée sur l'étape — le gestionnaire n'a plus à
  cliquer le bouton manuel : le planning de saison amputé des contraintes de la période est déjà
  à l'écran, prêt au déplacement manuel. Implémentation : un `useEffect` dans `GenerateStep.tsx`
  gardé par une **ref one-shot par plan** (`autoTranscribedPlan`, StrictMode/remontage/second
  onglet ne rejouent pas le déclenchement une fois la ref posée) et par le **rôle de gestion**
  (`isManagementRole(me?.role)`, miroir d'AFFICHAGE — le serveur reste seul juge, la parité est
  tenue par `ManagementRolesMatchBackendTest`) — c'est une **mutation FRONT**, jamais un GET qui
  écrit. Le 409 « plan déjà versionné » d'un double appel concurrent est traité comme **bénin** :
  le serveur relit sa garde sous verrou, le front invalide `["schedules"]` et réconcilie la liste
  sans bandeau rouge (seuls les autres échecs restent rendus via `transcribeReason`, comme le
  geste manuel). Les VACANCES (type HOLIDAY) gardent le comportement PR-2 à l'octet près — décision
  de sens fondateur (« un planning tout nouveau, pas de copie du socle ») **et** raison technique :
  une reprise dont la grille est réécrite (créneaux déplacés en journée) verrait les séances du
  soir du socle copiées en verrous HARD **hors grille** — `OrphanPinGuard::firstOrphanMessage`
  (backend, appelé par `GenerateScheduleController` ET `FillPeriodPlanController`) refuserait
  alors **422 « Régénérer » ET « Combler »**, enfermant le gestionnaire au lieu de l'aider.
- **Bouton « Partir du planning de saison »** (`CopyPlus`, variante `outline`, à côté du bouton de
  génération) : rendu **seulement** quand le plan de période n'a **aucune** version
  (`0 === periodPlanVersions.length` — la même garde que le backend, jamais une redérivation
  d'une règle différente). **Ni retiré ni relibellé par PR-4** — il disparaît de lui-même dès
  qu'une V1 existe (transcrite automatiquement ou non) et reste le SEUL chemin sur une reprise de
  vacances (et sur une fermeture, le geste de repli si l'auto-déclenchement a échoué en dehors du
  cas bénin ci-dessus). Le clic appelle `useTranscribeFromSocle` (`wizard/queries.ts`), qui
  invalide `["schedules"]` et `["calendar-entries"]` pour que l'écran embarqué atterrisse sur la
  V1 fraîchement créée (règle « embarqué = la version la plus récente », déjà en place). Un refus
  serveur (409 socle non pointé, 409 plan déjà versionné) est **affiché**, jamais muet
  (`errorMessage`, même patron que le reste du wizard) — pas de bouton « Générer » qui échoue en
  silence.
- **Liste « Séances à replacer »** (`ToReplaceList.tsx`) : les entrées de la réponse
  (`PeriodTranscriptionResult.toReplace` — équipe/jour/heure/gymnase/raison) sont **SERVIES**, le
  front ne redérive rien ; seul le libellé de la raison (`venue_closed`/`venue_disabled`/
  `team_reduced` → « Fermeture du gymnase »/etc., `lib/toReplaceReason.ts`) est une PRÉSENTATION
  pure (régime autorisé, comme `matches/lib/diagnostic.ts`), pas une redérivation de règle. Portée
  de vie **délibérée** : cette liste est un état de la SESSION D'ÉCRAN (passée en prop de
  `GenerateStep` à `PlanningPage`, `toReplace`) — la réponse ne peut pas être re-servie (la route
  crée la V1 une seule fois ; la rappeler sur un plan déjà versionné rendrait 409), donc après une
  navigation c'est le `DriftBanner` déjà existant qui prend le relais (il redit, par nature, les
  équipes sous leur quota) plutôt qu'une redérivation ad hoc ici. Le panneau et le bouton
  « Comparer avec la saison » ne s'affichent que sur l'écran de génération d'une période
  (`embedded && scoped` dans `PlanningPage.tsx`), jamais en page autonome (`/planning`).
- **Vides mis en évidence** (`WeekGrid` prop `emphasizeEmpty`) : quand la liste « à replacer »
  n'est pas vide, les créneaux VIDES de la grille passent en style « repérable » (bordure pleine
  accent, fond teinté, `aria-label` nommé « Créneau vide à combler… ») pour que le gestionnaire
  voie où recaser les séances non reprises. Cède le pas au surlignage **conflit** (`flagged`,
  jamais les deux à la fois) et **ne s'applique jamais à une case FERMÉE** — la case fermée sort
  avant cette branche (§6.7, marquage P2-43 volet v).
- **Modale « Comparer avec la saison »** (`SeasonComparisonModal.tsx`) : une CONSULTATION en
  lecture seule de la version de saison POINTÉE (le socle transcrit), réutilisant `buildGrid` +
  `WeekGrid` avec `onSelectSlot` inerte — aucun geste d'écriture, aucun mode cible. A11y modale via
  le composant `Modal` partagé (`shared/components/ui/modal`). Le bouton qui l'ouvre apparaît dès
  qu'un socle est consultable (une version de saison pointée existe), indépendamment de la liste
  « à replacer ».

**Comblement — bouton « Combler automatiquement » (P2-44 PR-3, ADR-0004, 2026-08-20)** : sur
l'écran embarqué (`PlanningPage`), dès que la dérive `driftEntries` (le prédicat SERVI, jamais
recomposé — même donnée que le bandeau existant) est non vide, un bouton `outline` (icône
`Sparkles`) apparaît à côté des « séances à replacer » : « Combler automatiquement ». Le clic
appelle `useFillSchedule` (`queries.ts`), miroir strict de `useRegenerate` — POST
`schedules/{id}/fill`, invalide `["schedules"]`, sélectionne la V+1 créée en `onSuccess`. Un refus
serveur (409 non-période/version choisie/génération en cours, 422 complexité/épinglage orphelin,
429 quota club) est **affiché** via `errorMessage`/toast, jamais muet. Désactivé pendant une
génération en cours ou sans version valide sélectionnée. **Outil d'appoint** : « Régénérer » (solve
complet) reste dans la barre d'outils à côté — le comblement ne le remplace pas, il évite un solve
complet pour le cas courant (un gymnase a fermé, une équipe a repris son volume).

**Les écarts NOMMÉS — panneau « Écarts avec le planning de saison » (P2-44 PR-5, ADR-0004,
2026-08-20)** : sur l'écran embarqué (`transcriptionSurface`, donc `embedded && scoped`) d'une
période de type **FERMETURE** portant une version `COMPLETED`, `SocleDeviationPanel.tsx` affiche
l'agrégat (« N séances déplacées, M à replacer ») puis le détail ligne à ligne — une déplacée se lit
`U13F1 · Mar 18h30 Matéo → Jeu 19h00 JDR`. La donnée est **SERVIE** par `GET
/api/schedules/{id}/socle-deviation` (`useSocleDeviation`, `queries.ts`) : le front ne compare
**rien** — il ne redérive ni l'appariement ni la raison, il met en forme. Une raison `null` (la
sélection de période n'explique pas l'absence) se rend **sans étiquette**, jamais avec une cause
inventée. Le hook est invalidé aux **trois** sites qui invalident déjà `["slots"]` (`useLockSlot`,
`useMoveSlot`, `usePlaceSlot`) pour que le diff suive un déplacement.

Contrairement à `ToReplaceList`, ce panneau **survit à la navigation** (route de lecture
re-appelable, pas une réponse de POST). Il **s'ajoute** à `ToReplaceList` sans le remplacer —
arbitrage fondateur « les deux affichés pour le moment » — d'où deux titres délibérément
distincts : « **Écarts avec le planning de saison** » (le neuf, qui porte en plus les **déplacées**)
vs « Séances non reprises du planning de saison » (l'existant, session d'écran). Sur une **vacance**
la route n'est **jamais appelée** : le comportement PR-2 reste intact à l'octet.

### 6.8 Loading states, error boundaries et ÉCRANS SYSTÈME (P5-14, 2026-08-21)

Chaque route a :

- Un skeleton loader pendant le chargement initial (pas de spinner vide)
- Un error boundary React qui affiche un message + bouton "Réessayer"
- Pas de page blanche en cas d'erreur API

**Les écrans système passent tous par UNE primitive** — `shared/components/ui/system-screen.tsx`
(`SystemScreen`). Elle porte la **forme** et rien d'autre : nom produit (via `PRODUCT_NAME`, jamais
un littéral), titre, corps, **un** geste principal + un secondaire, et une ligne « Code incident »
discrète quand le consommateur en fournit une. ⚠ **Aucune prop `variant`, aucun `switch` sur un type
d'écran** : chaque écran est un CONSOMMATEUR qui apporte sa copie et ses gestes. C'est ce qui tient la
règle « un seul composant d'état, jamais un deuxième » sans fabriquer un composant fourre-tout.
⚠ Contrainte dure : la primitive rend **sans aucun provider** — elle sert sous `ErrorBoundary`, monté
hors providers ; donc pas de `useQuery`, pas de `FeedbackDialog` à l'intérieur.

| Écran | Consommateur | Déclencheur |
|---|---|---|
| **404** | `app/NotFoundPage` | catch-all `*` et `/admin/*` (§2) |
| **403** | `app/ForbiddenPage` | `RouteErrorBoundary` sur une `Response` 403 — **porte générique**, aucun câblage page par page |
| **Hors ligne** (pleine page) | `app/OfflineScreen` | échec de chunk hors ligne, et échec au boot |
| **500** | `app/ServerErrorScreen` | crash de rendu (`ErrorBoundary`), et 5xx au boot |
| **Session expirée** | bloc dans `features/auth/LoginPage` | marqueur one-shot posé par `shared/api/client.ts` |
| **JavaScript coupé** | bloc `<noscript>` statique dans `index.html` | le navigateur lui-même — seul canal qui s'affiche quand la SPA ne peut pas rendre |

**La 404 sert AUSSI au refus tenant** — une ressource d'un autre club rend 404, jamais 403 (un 403
confirmerait son existence). ⚠ **Sa copie doit rester MUETTE sur les droits** : y ajouter « vous n'avez
pas accès » la transformerait en oracle d'existence. Le commentaire de `NotFoundPage` le dit, parce
que c'est exactement l'« amélioration » qu'une relecture bien intentionnée ajouterait.

**Le 403 n'a aujourd'hui aucun chemin UI qui le produise** (le front masque les gestes de gestion en
amont, `shared/lib/roles.ts` ; le 403 saison s'auto-guérit dans `client.ts`). L'écran et sa porte sont
livrés quand même — décision fondateur : le jour où un Membre atteint une route de gestion, il existe.

**JavaScript coupé (`<noscript>`, P5-14 vague 3)** : sans lui, `<div id="root">` reste vide
— page blanche muette, le cas de panne le plus silencieux. Bloc statique français (cause + geste :
réactiver puis recharger), **sans marque** (le `<title>` est l'unique littéral toléré d'`index.html`,
`shared/lib/product.ts:8-11`), zéro ressource externe, thème `prefers-color-scheme` en CSS pur.
Gardé par `frontend/tooling/noscript.test.ts` (existence, français, absence de marque, autonomie).

**La copie des messages d'erreur SERVEUR a sa règle** : `backend/docs/error-copy.md` — français
métier dès qu'un gestionnaire peut lire (nominal ou course), anglais toléré seulement hors de tout
chemin UI. Le front n'a rien à traduire : `errorMessage.ts` reprend le corps 4xx tel quel, et il
route sur `code`, jamais sur la phrase.

**Session expirée — pourquoi un marqueur et pas une page** : le 401 est capté dans `client.ts`, mais
« Se reconnecter » **EST** le formulaire de `/login` déjà présent ; une page dédiée ajouterait un clic
pour rien. Le marqueur est **one-shot**, en `sessionStorage`, sous une clé **sans nom de marque**
(leçon de `clubscheduler:wish-draft:`, piégée par le renommage). ⚠ Surtout **pas** un query param :
une URL partagée ou mise en favori afficherait le message à tort.

⚠ **`AuthGuard` n'envoie plus les 5xx vers `/login`.** Il le faisait pour TOUTE erreur de `/api/me`,
réseau compris — donc le serveur tombait et l'application répondait « reconnectez-vous ». Le vrai 401
est déjà éjecté par `client.ts`. Désormais : hors ligne → écran hors ligne, sinon → écran 500 dont
« Réessayer » refait le `refetch`.

**Le code d'incident** est l'`X-Request-Id` de corrélation déjà posé sur chaque requête et retenu 10 min
sur un ≥ 500 — jamais une stack, une exception ou du SQL. Il est déjà joint automatiquement à un
signalement : l'utilisateur n'a pas à le recopier.

**Le BANDEAU hors-ligne (livré le 2026-08-22)** — `app/OfflineBanner.tsx`, monté **dans le flux** de
`RootShell`, avant le contenu : il **empile, il ne recouvre pas** (aucun overlay, aucun z-index), et
il est monté **une seule fois** pour toutes les routes — page publique de doléances comprise, où le
coach sans réseau dans un gymnase est le cas nominal. ⚠ Il **ne double pas** `app/OfflineScreen.tsx`,
la page PLEINE qui sert quand il n'y a **rien derrière** (chunk ou `/api/me` en échec au boot) : deux
formes, deux portées, elles coexistent sans se contredire.

**Une seule source de vérité pour l'état réseau** : `shared/lib/online.ts` (`useOnline`), qui lit
l'**`onlineManager` de TanStack** — le même que celui qui décide de mettre les mutations en pause.
`RouteErrorBoundary` et `AuthGuard` y ont convergé ; plus personne ne lit `navigator.onLine` en
parallèle, sinon le bandeau et la file pourraient se contredire. ⚠ L'`onlineManager` naît
**optimiste** (`#online = true`, il ne bascule que sur un événement `window`) : `main.tsx` le **sème**
depuis `navigator.onLine` avant le render, sans quoi un démarrage hors ligne se croirait en ligne.

**Quatre états, tous dérivés du code — le compteur est le nombre RÉEL de mutations en pause**
(`useMutationState` sur `m.state.isPaused`), jamais une estimation :

| État | Ce qu'il dit | Geste |
|---|---|---|
| hors ligne, rien en attente | « Vous êtes hors ligne. Vos données restent consultables. » | aucun |
| hors ligne, N en attente | « N modification(s) en attente… **gardez cet onglet ouvert.** » | **aucun** |
| en ligne, envois en cours | l'envoi reprend | « Envoyer maintenant » **si** des mutations restent en pause |
| de retour | « De retour en ligne. » (+ « Vos modifications sont parties. » si c'est vrai) | s'efface seul à 5 s |

⚠ **Pourquoi aucun bouton hors ligne** : `resumePausedMutations()` est un **no-op** quand
`onlineManager.isOnline()` est faux (query-core `queryClient.js:206`). Un bouton y serait un mensonge
cliquable. ⚠ **Pourquoi « gardez cet onglet ouvert »** : la file vit **en mémoire** — aucun bloc
`mutations` persisté dans `shared/lib/queryClient.ts`, un rechargement la perd. La **persistance est
un lot séparé** ; tant qu'elle n'existe pas, la copie ne promet pas plus que ce que le code tient.

⚠ **Une mutation en PAUSE ne voile plus l'écran.** `ActionVeil` la comptait comme un geste en vol :
hors ligne, un clic bloquait à 0 ms puis annonçait à 10 s que « l'action continue en arrière-plan » —
**faux**, rien ne continue, elle est garée. Le prédicat `saving` l'exclut désormais. ⚠ Le contexte
**`long` reste inchangé** : un déplacement sous verdict garé qui repartirait plus tard doit rester
sous le régime bouton-Abandonner, jamais relâché en silence.

**A11y** : le conteneur visible n'est **pas** une région live ; une région `sr-only` `role="status"`
`aria-live="polite"` ne reçoit que les **transitions** — le compteur qui s'incrémente ne ré-annonce
pas (patron AUD-FRT-23/24). La couleur ne porte jamais seule le sens (icône + phrase distinctes).

**L'écran « LE SERVICE DE CALCUL NE RÉPOND PAS » (livré le 2026-08-22)** —
`features/planning/GenerationServiceDown.tsx`. ⚠ Il ne passe **pas** par `SystemScreen` : celle-ci
sert les écrans système de ROUTE, alors qu'ici c'est un **état du rail de génération**, dont la
scène EST l'identité. Il réutilise la scène de `GenerationWaiting` **sans la dupliquer** — le décor
est extrait en `GenerationScene` (cadre + deux bandes + mini-grille), avec un prop `halted` ; ce
n'est pas un `variant` déguisé, c'est un décor à deux états.

**À l'arrêt veut dire à l'arrêt** : aucune case ne se remplit, le chrono est barré, le ballon roule
au sol — un seul mouvement, lent et **arythmique**, qui ne vise aucune case. L'écran ne doit pas être
mort, mais il ne doit **jamais** laisser croire qu'un calcul tourne.
⚠ **Piège CSS, corrigé et gardé** : le bloc `prefers-reduced-motion` force `.gw-anim { opacity: 1
!important }`. Transposé tel quel, l'état arrêté aurait affiché une grille **PLEINE** — l'inverse de
l'intention, et une faute de VÉRACITÉ, pas de cosmétique. L'override `.gw-halted .gw-anim` le bat
par spécificité, et un test lit le CSS source (jsdom ne calcule aucune mise en page).

**La DISTINCTION, qui est la vraie raison de cet écran** : jusqu'ici, un service injoignable et un
planning **infaisable** empruntaient le même chemin d'échec — alors que les gestes attendus sont
opposés (attendre vs corriger ses contraintes). Elle se dérive du **`type`** du diagnostic, via
`features/planning/lib/serviceFailure.ts` (**miroir déclaré**) : `engine_timeout`, `engine_error`,
`internal_error`, `engine_status` sont écrits **uniquement par le backend quand le service n'a pas
répondu**. ⚠ **`engine_failed` en est EXCLU à dessein** — il signifie que le moteur A RÉPONDU
« failed », donc que le planning est infaisable. `isServiceDown` n'est vrai que si les diagnostics
ERROR sont **non vides et TOUS** dans la liste.
⚠ **Aucun affichage de causes en parallèle** : celui de P4-99 (`failureExplanations` /
`failureSuggestions`) reste seul et **inchangé** ; on n'a ajouté qu'un aiguillage.
Le miroir est gardé **dans les deux sens** par `backend/tests/CrossStack/EngineFailureTypeMirrorTest`
(groupe `contract`, job `engine-semantics`, required check) — sans lui, un type ajouté au handler
ferait classer une **panne** en « infaisable », en silence.

**« Il n'y a rien à corriger de votre côté »** est la seule phrase auto-disculpante du jeu d'écrans,
et elle est là pour ça : c'est le seul écran qu'on peut confondre avec une faute de saisie.

⚠ **La « référence support » n'est jamais fabriquée.** L'échec de génération est **asynchrone** — le
POST rend 202, l'échec arrive en `status: FAILED` dans des réponses **200** — or `lastIncidentStore`
n'enregistre un incident que sur un **≥ 500** (depuis P4-129, 2026-08-23, il capture `{status, url, code?, requestId?}` — request-id présent OU NON : le 502 nginx qui a motivé la ligne n'en portait pas, et l'ancien rail ne retenait rien ; le wrapper `readRecentIncidentRequestId` préserve le contrat de la modale de signalement). La ligne « Code incident » ne s'affiche donc que
si une valeur fraîche existe (rare ici). La corrélation honnête est le **`scheduleId`**, joint
automatiquement au signalement — à condition que « Contacter le support » ouvre `FeedbackDialog` en
**`variant="contextual"`** : en `free`, le contexte reste à quai.

**`launch.isError` seul ne route PAS vers cet écran** : un 4xx est du métier servi (422 épinglage
orphelin, 403 crédits) et garde son `launchReason` ; un échec réseau du POST relève du rail
hors-ligne/500 — le service de calcul n'a même pas été sollicité.

**En DEV seulement (P4-129)** : sous le message grand public de tout écran système (et de
`GenerationServiceDown`), un bloc repliable « Détails techniques (dev) » — statut réel, URL, code
machine s'il existe, `X-Request-Id`, horodatage figé au montage — en deux groupes (« Cet écran » /
« Dernier incident serveur (peut être sans lien avec cet écran) »). Gardé `import.meta.env.DEV` lu
au RENDU : physiquement absent du bundle prod (prouvé par grep du bundle ET par test sous
`vi.stubEnv`), hors de toute live region, jamais focusé au montage.

**Hors de cette tranche** : la **503/maintenance**, geste d'ops (Caddy `handle_errors`), pas du code
applicatif ; et cet écran dans la boucle de travail `/planning`, qui garde son traitement propre.

---

### 6.9 Largeurs — écran dense, page fiche, modale (P4-107)

**La règle, en une phrase : la largeur se choisit par le TYPE d'écran, jamais au cas par cas
dans le fichier qui la subit.** Elle n'était écrite nulle part avant le 2026-08-21 — c'est ce
silence qui a produit la dérive corrigée par la 3ᵉ tranche de P4-107 (six modales avaient
bricolé quatre valeurs différentes, deux pages fiche vivaient à une largeur de mobile élargi
sous un shell devenu pleine largeur).

| Type d'écran | Largeur | Où elle vit |
|---|---|---|
| **Dense** (grille de planning, wizard, module matchs, scène d'attente) | **pleine largeur** — le shell ne borne rien (`AppLayout.tsx`, 1ʳᵉ tranche, PR #613) | l'écran lui-même |
| **Fiche** (Club, Profil, Nouveautés) | **832 px** (`--container-fiche: 52rem`) | `FichePage` (`shared/components/ui/fiche-page.tsx`) |
| **Texte long** (Confidentialité) | `max-w-2xl` | la page |
| **Modale** | 4 paliers nommés — 448 / 576 / 768 / 1152 px | `MODAL_WIDTH` (`shared/components/ui/modal.tsx`) |

**Modales — la prop `size`, et rien d'autre.** `Modal` n'a **plus de prop `className`** : choisir
un palier est le seul geste offert, et `tsc` rougit sur toute récidive. Les paliers montent avec
le viewport puis **s'arrêtent** (tous atteignent leur plafond dès `lg:`, soit 1024 px de
viewport : un portable et un 1920 affichent la même largeur) :

- `sm` — confirmation, geste destructif : 448 px, **constant** (elle ne grandit jamais) ;
- `md` — **défaut**, formulaire de 6 champs au plus : plafond 576 px ;
- `lg` — formulaire long, liste : plafond 768 px (sous la largeur des fiches) ;
- `xl` — contenu tabulaire, comparaison de plannings : plafond 1152 px.

`confirm-dialog.tsx` et `EvictConfirmDialog.tsx` recopient le markup du panneau (duplication
assumée et commentée) mais **lisent `MODAL_WIDTH.sm`** : deux copies du markup, une seule
échelle.

**Pourquoi un plafond.** La passe de design `ui-ux-pro-max` n'endosse que des échelles qui
terminent sur une largeur fixe (`DON'T Full-width text on large screens`) : une modale qui
suivrait indéfiniment le viewport rejouerait sur 1920 px l'anti-pattern qu'on corrige sur 448.

**Pourquoi une borne de lisibilité dans les fiches.** `FichePage` porte `[&_p]:max-w-prose` :
la seule mesure chiffrée du corpus de design est 65-75 caractères par ligne, et elle vaut à
l'INTÉRIEUR d'un conteneur plus large — élargir le cadre sans borner les paragraphes
échangerait un défaut contre un autre. La borne ne vit pas dans `AccordionSection` : il a
quatre autres consommateurs (écrans du wizard, pleine largeur par conception) qu'elle aurait
reflowés en silence.

**Ce qui garde quoi.** `modal-size.test.tsx` et `fiche-page.test.tsx` épinglent les CLASSES
(égalité d'ENSEMBLE : une classe manquante rougit, une classe en trop aussi — c'est elle qui
attrape une reprise de croissance au-delà du plafond). Ils ne peuvent pas voir qu'une classe
n'engendre aucun CSS : jsdom n'a pas de moteur de mise en page. Les PIXELS se mesurent en
Playwright — `tests/e2e/width-calibration.spec.ts` (fiche à 832 px et paragraphe borné, sur
1920×1080) et `tests/e2e/modal-reachability.spec.ts` (le palier `xl` atteint 1152 px et s'y
arrête).

**Écrans denses : la largeur se DÉPENSE, elle ne se subit pas (4ᵉ tranche, 2026-08-21).** Un
écran dense n'a pas de cap — le défaut n'est jamais « trop étroit », c'est « la place est là et
personne ne s'en sert ». Deux formes, mesurées à 1920×1080 :

- **Une liste de lignes courtes devient un TABLEAU**, pas une pile de barres pleine largeur.
  Les contraintes du wizard étalaient ~50 caractères sur ~1650 px avec les actions à ~1400 px du
  libellé qu'elles concernent. `<table>` + `<thead>`/`<tbody>` **sémantiques** — pas une grille de
  `<div>` : c'est la seule règle de sévérité HAUTE rendue par la passe de design sur ce lot, et
  c'est elle qui fait annoncer « Règle : pas après » par un lecteur d'écran. Les regroupements
  survivent en lignes d'en-tête (`<th scope="rowgroup">`, pour ne pas polluer les en-têtes de
  colonnes). Cible de clic : `p-1.5 -m-1.5` autour d'une icône de 16 px donne 28 px cliquables
  **sans épaissir la ligne**.
- **Un champ se dimensionne sur la valeur qu'il doit MONTRER.** Le tableau Équipes faisait
  l'inverse : le nom prenait ~1050 px pour afficher `SM1` pendant que les sélecteurs coupaient
  leur propre valeur (« Homn » pour « Homme ») — nommément un DON'T du corpus de design
  (« Overflow or cut off »).

⚑ **Ce que le corpus de design ne dit PAS, et qu'on n'a donc pas le droit de lui faire dire** :
il est muet sur les lignes de groupe dans un tableau, sur la largeur d'un tableau de données, sur
la taille d'une tuile de KPI et sur les accordéons. La borne de la bande de cartes du Récap est
un choix ERGONOMIQUE (distance œil-chiffre), pas une prescription : `max-w-3xl` est un jeton du
corpus emprunté hors de sa règle (elle porte sur la longueur de ligne d'un TEXTE).

**La hauteur suit la même règle.** La grille de saisie Gymnases affiche 08:00→23:00 : la plage
reste ENTIÈRE (on y crée des créneaux au clic — rogner rendrait 09:00 inatteignable, et P4-37
interdit de masquer ce qui existe), mais la vue **s'ouvre positionnée sur la bande utile** du
gymnase affiché. Positionnement **instantané** (`useLayoutEffect`, jamais `smooth` : au montage
il n'y a aucun saut à adoucir, et animer fabriquerait le « forced scroll effect » que le corpus
interdit), rejoué au changement de gymnase (chaque gymnase a SA bande), et **jamais** repris une
fois que l'utilisateur a défilé. Aucune annonce lecteur d'écran : le DOM ne change pas, seul
l'offset diffère — la gouttière d'heures collante affiche « 17:30 » et dit à elle seule qu'on
n'est pas au début.

⚠ **Reste hors de ce chantier** : la grille de PLANNING, qui est réellement saturée (5 jours ×
9 gymnases débordent au-delà de 1920) — autre problème, autres leviers ; le module matchs a depuis
eu sa refonte UX propre (P2-26, livrée — `specs/courantes/module-matchs.md`). Le pseudo-tableau en
`<span>` de l'étape Équipes (`TeamsStep.tsx`) reste lui aussi en l'état : ce lot n'y touche que des
largeurs.

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
| wizard `store` | `src/features/wizard/store.ts` | étape courante, étape max atteinte, **mode** (`season`/`period`) + `calendarEntryId` — **aucune donnée métier** | persisté (`version: 4`) |
| planning `store` | `src/features/planning/store.ts` | planning sélectionné + état UI (vue, filtres) | Non persisté |
| matches `store` | `src/features/matches/store.ts` | état UI du module matchs — `railStep` (vue de la boucle choisie ; `null` = auto = premier trou), `unplacedReasons` (raisons du dernier auto-placement, par fixtureId) : les deux resettent au changement de semaine (`setSelectedWeekend`, RMM-1) | Non persisté |
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
| `/forgot-password`, `/reset-password/:token` | `POST /api/password/forgot`, `POST /api/password/reset` |
| `/waiting` | `GET /api/me` (poll 5 s jusqu'à `membershipStatus === "active"`) |
| `/planning` | `GET /api/me`, `GET /api/schedules` (poll 2,5 s si génération en vol), `GET /api/schedule_slot_templates?scheduleId={id}`, `GET /api/schedule_diagnostics?scheduleId={id}`, `POST /api/schedules/{id}/generate`, `POST /api/schedules/{id}/validate`, `POST /api/schedules/{id}/reopen`, `POST /api/schedules/{id}/export-pdf` (`ExportMenu`), `PUT /api/schedule_plans/{id}` (renommage du plan), `PUT /api/schedules/{id}` (renommage de la version), `DELETE /api/schedules/{id}` (suppression d'une version de travail), `POST /api/schedule-slots/{id}/move` (déplacer/évincer, mode cible sous verdict moteur — §6.7), `POST /api/schedules/{id}/place-slot` (placer une séance à la dérive — §6.7), `POST /api/schedule-slots/{id}/manual-edit/lock` (verrouiller/déverrouiller — §6.7), collections référentiels (`teams`, `venues`, `coaches`, `sport_categories`, `team_coaches`, `coach_player_memberships`) |
| `/` (cockpit) | `GET /api/me`, `GET /api/schedules`, `GET /api/schedule_plans`, `GET /api/calendar_entries` (+ conflits d'entrée), campagnes de doléances (badge radar), `GET /api/venue_unavailabilities` + `venue-unavailability-impact` (carte radar « gymnase indisponible » — P4-68) |
| `/matchs`, `/matchs/configuration`, `/matchs/reconciliation` | `POST /api/fixtures/import/analyze` (multipart `file` → mappings résolus + `deviations` RMM-4), `POST /api/fixtures/import` (multipart `file` + `mappings` + `decisions?` → rapport + `unresolvedDeviations`/`depositedAt`), `GET /api/fbi-ingestions/latest` (fraîcheur, Membre), `POST /api/matches/module-visit` (gardien RMM-3, un POST par ouverture), `GET /api/ffbb/rencontres` + `POST /api/ffbb/rencontres/apply` (canal API RMM-4 PR-3, à la demande — `useFfbbRencontres(enabled: false)`). ⚠ Catalogue **partiel** — le module porte aussi `fixtures` CRUD, `fixtures/conflicts`, `fixtures/place`, `league-match-windows`, `venue_match_windows`, `team_match_habits`, `team_links`, `ffbb/engagements`, `venue_unavailabilities` : non énumérés ici, ligne à compléter (signalé, pas corrigé cette passe — hors scope RMM-4) |
| `/wizard` | CRUD `teams`/`venues`/`coaches`/`constraints`/`venue_training_slots`…, `GET /api/priority_tiers`, `GET /api/sport_categories`, `POST /api/teams/reorder` (mode tri), `POST /api/constraints/validate`, `POST /api/schedules` + `generate` (étape Génération) |
| `/club` | `PATCH /api/club/appearance`, `POST/DELETE /api/club/logo`, `GET /api/clubs/{clubId}/logo` (public, cache-buster sur l'URL après upload), `PATCH /api/club/info` (fiche FFBB, management-gated), `GET /api/memberships/pending`, `POST /api/memberships/{id}/approve`, `POST /api/memberships/{id}/reject` (section « Demandes » — l'ancienne route `/pending-members` a été repliée ici), `GET /api/venue-usage-stats?from=&to=` (encart stats d'utilisation des gymnases — §6.6 quater) |
| `/profile` | `GET /api/me` |
| `/doleances/:token` | Endpoints **publics** de la campagne de doléances (lecture du formulaire pré-rempli + soumission des seules sections modifiées) — aucun JWT |
| `/admin*` | `POST /api/admin/auth/password`, `POST /api/admin/auth/totp`, `GET /api/admin/auth/me`, `GET /api/admin/{overview,health,clubs,jobs,actions}`, `POST /api/admin/jobs/{key}/run` (en-tête `X-CSRF-Token`) — client `adminApi` dédié, cookie de session `same-origin` |

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
| `/api/me` | GET | — | `{ id, email, firstName, lastName, membershipStatus, role, club: {…} \| null, seasonPlan: { id, name, chosenScheduleId, hasFinishedVersion, currentStructureHash } \| null, seasons, … }` — **forme complète : `src/features/auth/api.ts` (`MeResponse`)**, source de vérité (le bloc `club` porte aussi l'accent sombre, la fiche FFBB, la ligue et le comité) | Query `["me"]` — source des guards, du thème (accent) et de l'état du plan de saison (ADR-0002) |

Les trois champs **structurants** de cette réponse : `club.accentColor` / `club.accentColorDark`
(thème appliqué par `useApplyClubTheme`), `seasonPlan.hasFinishedVersion` (verrou d'onboarding
et gate cockpit) et `seasonPlan.chosenScheduleId` (gate matchs et plans secondaires).

Référence : `backend-inventory.md` §3 (AuthController, PasswordController, MembershipController).

### Génération asynchrone

| Étape | Endpoint | Statut HTTP | Frontend |
|-------|----------|-------------|----------|
| Lancer | `POST /api/schedules/{id}/generate` | 202 | Mutation TanStack Query, écran `GenerationWaiting` |
| Suivi | `GET /api/schedules` (polling) | 200 | `refetchInterval` 2 500 ms tant que `PENDING`/`GENERATING` (§5) |
| Résultat | `GET /api/schedule_slot_templates?scheduleId={id}` | 200 | Re-fetch slots à la fin du polling |
| Diagnostics | `GET /api/schedule_diagnostics?scheduleId={id}` | 200 | Afficher rapport (`DiagnosticsPanel`) |

Référence : `backend-inventory.md` §3 (GenerateScheduleController) + §5 (Mercure, publié mais non consommé côté frontend).

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

## 10. Conventions de code frontend

### Structure des dossiers (livrée)

```
frontend/src/
├── main.tsx                    # Entry point
├── index.css                   # Tailwind 4 (@theme) + variables d'accent
├── app/                        # router (lazy + filets), RootShell, RouteErrorBoundary,
│                               # ErrorBoundary, AppLayout, AuthGuard, providers,
│                               # SeasonSelector, SeasonTransitionBanner, ReadonlySeasonBanner
├── features/                   # Logique métier par domaine (liste : ls src/features/)
│   ├── admin/                  # Console superadmin : AdminGuard, AdminShell, AdminLoginPage,
│   │                           # AdminDashboardPage, sections/, Journaux/, client `adminApi` dédié
│   ├── auth/                   # Login/Register/ForgotPassword/ResetPassword/WaitingApproval/VerifyEmail + api/queries
│   ├── club/                   # ClubPage (logo + accent + infos FFBB + section Demandes), LogoCropper
│   ├── coach-wishes/           # #10 doléances : CoachWishesModal, CampaignDialog, CoachWishForm,
│   │                           # PublicWishPage (route publique), RadarCoachWishAction
│   ├── cockpit/                # CockpitPage : bandeau planning socle, calendrier mensuel, radar
│   │                           # overlays, FbiDeadlineCard (rappel FBI + escalade login, RMM-6 PR-3)
│   ├── legal/                  # PrivacyPage (/confidentialite)
│   ├── matches/                # MatchesLayout (trois routes) → MatchesPage (boucle : rail 5 étapes
│   │                           # dérivées lib/loopSteps.ts, grille week-end, radar conflits,
│   │                           # FbiEntryList) + ConfigurationPage (SET-UP rare) +
│   │                           # ReconciliationView/-Panel (RMM-4, écarts FBI par écart, livré —
│   │                           # deux canaux : dépôt xlsx et vérification API FFBB)
│   ├── planning/               # PlanningPage, PlanningToolbar, WeekGrid, SlotDetail, DiagnosticsPanel,
│   │                           # ResourceFilter, GenerationWaiting, ExportMenu, store, lib/grid
│   ├── profile/                # ProfilePage
│   ├── season-transition/      # RedateEventsDialog + api (le bandeau et le sélecteur vivent dans app/)
│   └── wizard/                 # WizardLayout, steps/ (Teams, Venues, Coaches, Constraints, Recap,
│                               # Generate + PeriodStructure, StructureSummary), lib/, store
├── shared/
│   ├── api/                    # client ky, collection (JSON-LD clé `member`), errors
│   ├── components/ui/          # Primitives (shadcn-style) — dont delete-confirm, load-error-hint,
│   │                           # team-select, modal, menu, accordion, toaster, empty-hint
│   ├── hooks/                  # useApplyTheme, useApplyClubTheme
│   ├── lib/                    # readState, teamTiers, color, palette, duration, errorMessage,
│   │                           # download, clipboard, passwordPolicy, useModalA11y, queryClient, utils
│   └── stores/                 # authStore, themeStore, seasonStore, toastStore, transitionUiStore
└── test/                       # setup vitest, helpers de rendu, suite a11y
```

### Trois pièces transverses à connaître avant de coder

- **`shared/lib/readState.ts`** — « cette lecture est-elle exploitable ? », une seule réponse
  pour toute l'app. Trois états sur un unique critère (*a-t-on une donnée ?*) : `loading`
  (rien à montrer encore), `failed` (échec **et** rien en cache — le seul cas où un écran doit
  céder la place à une erreur), `ready` (on a une donnée, même périmée). Deux conséquences :
  un `isError` de **refetch d'arrière-plan** ne doit pas détruire un écran qui fonctionne, et
  `data ?? []` pendant un premier chargement fabrique un **vide crédible** (« aucun créneau »)
  qui pousse à re-saisir (doublons) ou à valider une période qu'on croit vide.
- **`shared/components/ui/delete-confirm`** — confirmation destructive qui **annonce ses
  impacts** (« N réservations seront retirées »). À réutiliser plutôt qu'un `confirm()` nu.
- **`shared/components/ui/team-select`** — tout sélecteur d'équipes de l'app (contraintes,
  coachs, matchs, import FBI) passe par lui : optgroups par rang, même ordre que l'étape
  Équipes. Reclasser une équipe met l'ordre à jour **partout**.

### Alias

- `@/` → `src/` (configuré dans `vite.config.ts` et `tsconfig.json`)

### Thème appliqué avant le premier rendu

`src/main.tsx` lit le mode persisté (`readPersistedThemeMode`, clé `cs-theme`) et pose la
classe `.dark` **avant** le premier paint de React. Sans cela l'arbre se rend en clair puis un
effet bascule la classe : flash du mauvais thème **et** animation `transition-colors` qui
laisse les surfaces à des couleurs intermédiaires **sub-AA** (A11Y-06). Le pré-paint et
`useApplyTheme` partagent le même prédicat et la même forme de stockage, pour ne jamais
diverger.

### Reporting d'erreurs

`main.tsx` initialise Sentry **uniquement si `VITE_SENTRY_DSN` est posé au build** : erreurs
seules, `tracesSampleRate: 0`, pas de replay (quota free tier préservé, INF-01). DSN absent =
init sautée, SDK inerte — tout est câblé d'avance.

⚠ **Mais le DSN seul ne l'active PAS** (P4-65). `docker/frontend/csp.conf` déclare
`connect-src 'self' blob:` et **n'autorise aucun hôte tiers** : sans l'hôte d'ingestion du DSN
dans cette directive, le navigateur **jette chaque envoi, en silence**. Le SDK s'initialise,
l'application paraît instrumentée, rien n'arrive — et on le découvre le jour où on cherche
une erreur de production.

**Activer Sentry = deux gestes dans le même changement** : le DSN, et son hôte dans
`connect-src`. Un garde de build (`frontend/tooling/sentryCspGuard.ts`, appelé par
`vite.config.ts`) **fait échouer le build** si le DSN est posé sans son hôte — la panne
silencieuse est devenue bruyante. Il est inerte tant qu'aucun DSN n'est posé.

### Naming

- Composants : `PascalCase` (`ScheduleCalendar.tsx`)
- Hooks : `camelCase` préfixé `use` (`useApplyClubTheme.ts`)
- Stores : `camelCase` + `Store` (`authStore.ts`)
- Types : `PascalCase` (`ScheduleSlot`, `HydraCollection`)
- Query keys : `kebab-case` strings (`['schedule-slots', id]`)

### Tests

- Vitest + React Testing Library + MSW (Mock Service Worker) pour les tests composants (`*.test.tsx` co-localisés)
- Harnais E2E Playwright présent dans `frontend/tests/e2e/` (`@playwright/test` en devDependency)
- Couverture : composants critiques (auth, planning, toolbar, grille, wizard)
