# Canal signalement, support & reproduction

> Le canal par lequel un gestionnaire signale un bug, une contrainte manquante ou une idée — et
> de quoi **reproduire** ce qu'un utilisateur a rencontré. Base saine (pas un `mailto:` jetable),
> sans sur-ingénierie tickets. Les décisions D1-D6 + §3bis/§3ter/§3quater/§3quinquies/§3sexies/
> §3septies ci-dessous sont implémentées — **chantier F (4 PR) livré en entier**.
>
> **La reproduction d'une génération n'est jamais le problème** : `Schedule::$snapshotData`
> (`backend/src/Entity/Schedule.php:96`) porte le payload engine complet et figé, écrit avant
> l'appel moteur, avec son sha256 — rejouable tel quel sur `/generate`. Ce que le canal
> signalement capte en plus, c'est le reste : l'écran, le geste, l'intention de l'utilisateur.

## 2. Les trois familles d'options

### A. In-app minimal (l'option retenue)
Un bouton « Signaler » dans l'app → formulaire court (type : bug / contrainte manquante / idée ;
texte libre) → une entité `Feedback` tenant-scopée + **contexte auto-joint** : URL/écran,
`clubId`, `seasonId`, le `scheduleId` courant s'il y en a un (= le snapshot rejouable est
automatiquement référencé), user-agent, version app. Consultation : console superadmin
(liste + détail + statut traité/non traité — PAS un workflow de tickets). Notification :
un email vers `support@` à chaque dépôt (le mail part par le bus, rail déjà async).
- **Pour** : la donnée de repro est LÀ où le signalement naît ; tenant/RGPD maîtrisés maison ;
  zéro dépendance ; s'aligne sur la console SA existante.
- **Contre** : c'est nous qui stockons (purge/retention à définir) ; l'UI de traitement reste
  rudimentaire (voulu).

### B. Externe (Tally/Formbricks/GitHub Issues public…)
- **Pour** : zéro code.
- **Contre** : perd le contexte auto (l'utilisateur recopie à la main — la moitié de la valeur),
  RGPD à contractualiser, identité club à ressaisir, et l'aversion « base saine d'emblée »
  pointait précisément ça.

### C. Email seul (`support@maratech.fr` affiché dans l'app)
- **Pour** : existe dès que la boîte existe.
- **Contre** : c'est le `mailto:` jetable refusé au cadrage — aucun contexte, aucun suivi,
  aucune structure. Peut vivre en COMPLÉMENT (l'alias existe de toute façon), pas en canal
  principal.

## 3. Décisions retenues

| # | Décision |
|---|---|
| D1 | **In-app, DEUX portes** : (a) un « Signaler » **contextuel**, dans l'en-tête (`PageHeader`, `frontend/docs/frontend-components.md`) de chaque écran principal et du wizard — contexte auto-joint (écran, club, saison, `scheduleId` s'il y en a un) + champ descriptif du bug ; (b) un « Signaler un bug » **dans le burger** — zone libre : choix d'un topic (bug / contrainte manquante / idée) + commentaire libre. Les deux portes existent partout. **Exception motivée (lot B)** : à l'étape Génération du wizard, l'écran embarqué (`PlanningPage`) masque sa propre porte (a) — celle de l'EN-TÊTE DU WIZARD reste SEULE, pour ne jamais en doubler deux sur le même écran, et joint le planning AFFICHÉ par l'écran embarqué (store wizard `displayedScheduleId`, éphémère) ; un planning ouvert via `/planning` autonome garde sa propre porte (a) |
| D2 | **Contexte MAXIMAL, redondance assumée** : « je préfère être redondant et pouvoir reproduire plutôt que devoir redemander » — écran, club, saison, `scheduleId` ET **copie** des diagnostics + du payload rejouable dans le signalement lui-même. Justification technique de la redondance : le planning référencé peut être supprimé/régénéré après coup — la copie rend le signalement **impérissable**. Le contexte serveur est détaillé en §3quater (`context.auto`) |
| D3 | **Signé, et TOUT LE MONDE peut signaler** (Gestionnaires ET Membres) |
| D4 | **Digest quotidien** vers `support@` (pas un email par dépôt) — la console SA reste la vue temps réel |
| D5 | **La corrélation request-id/logs structurés vit à part** : chaîne complète documentée dans [`docs/ops/observability.md`](../../docs/ops/observability.md) |
| D6 | **Console superadmin : liste des signalements en cours + statut traité/non traité** — « pour ne pas oublier » |

## 3bis. La boucle complète du déclarant

Le workflow vécu par le club AAAA qui déclare un bug :
1. **Dépôt** → toast in-app + **email « bien reçu »** au déclarant (« votre signalement est
   enregistré et sera traité »), part par le bus comme les autres emails.
2. **Traitement** → quand le fondateur passe le signalement en « traité » dans la console SA
   (le statut D6 devient le déclencheur), **email « traité + merci d'avoir contribué à
   l'amélioration »** au déclarant.
3. **Visibilité à la release** → l'email « traité » peut pointer vers le **journal de nouveautés**
   ([`frontend/docs/frontend-spec.md`](../../frontend/docs/frontend-spec.md), route `/nouveautes`) :
   entrées datées curées au rythme du fondateur (pas à chaque merge — la plupart des PR sont de la
   plomberie invisible), page dans le burger, modale « quoi de neuf » une fois par nouveauté non
   vue, crédit anonyme possible (« signalé par un club — corrigé »). ⚠ Garde-fou : PAS de lien
   automatique bug→release (« votre bug #12 est dans la v1.4 ») — c'est le système de tickets
   exclu par l'anti-scope ; la boucle personnelle est fermée par l'email (2), le journal donne la
   visibilité publique.

## 3ter. Indicateurs qualité de service du support (« pour que l'on s'améliore »)

L'entité signalement porte déjà tout ce qu'il faut (horodatages dépôt/traitement, topic,
statut) — les indicateurs en découlent en SQL pur, AUCUNE collecte supplémentaire :
- **Délai dépôt → traité** (moyenne + p95, par mois) — LA mesure de l'amélioration ;
- **Volume par topic et par période** — où l'app fait mal, et si ça se résorbe ;
- **Part traitée / en attente** (et l'âge du plus vieux non traité — l'oubli visible).

Surface : un petit panneau en tête de la vue console SA (D6). Pas de dashboard dédié, pas d'outil
externe. Le reste de la qualité de service (taux de réussite des générations, erreurs techniques,
délais de solve) est couvert ailleurs : `solver_metrics` + monitoring SA existant + Sentry (actif
sur les 3 zones, `etat-des-lieux.md` §3 2026-10-06) + métriques de capacité
(`AdminCapacityService`, console superadmin — détail [`superadmin-auth.md`](superadmin-auth.md)
§ Capacité).

## 3quater. Contexte automatique large (`context.auto`)

En plus du contexte léger whitelisté (D2) et de la copie du planning nommé, **tout** signalement
(planning nommé ou non) porte une clé `context.auto` calculée **côté serveur** par
`FeedbackContextEnricher` (`backend/src/Service/FeedbackContextEnricher.php`), pour que le support
se resitue sans rien redemander à l'auteur :

| Clé | Contenu |
|---|---|
| `role` | rôle de l'auteur dans le club (adhésion active) |
| `season` | saison courante résolue serveur : id/nom/dates |
| `offer` | offre EFFECTIVE du club (Découverte par défaut) — même calcul que `/api/me` |
| `counters` | compteurs d'inventaire (équipes, gymnases) |
| `socle` | le plan SEASON (ADR-0002) : `inForce` (une version pointée) + `validatedAt` (1ʳᵉ validation, `null` tant qu'il n'est pas validé) |
| `period` | la fenêtre CLOSURE/HOLIDAY qui contient AUJOURD'HUI (horloge du club) : type/nom/dates/avancement — `null` hors période (« pas de période » est une information) |
| `versions` | `displayed` (le planning déjà joint par le client, ré-vérifié tenant) vs `pointed` (la version choisie du socle) — numéro + nom de chacun, `null` si absent |
| `generation.running` | une génération est-elle en cours pour ce club (`ClubGenerationLock`) |
| `apiVersion` | version de l'API résolue serveur (env `VERSION`, repli « dev ») — comparée côté console à la version du bundle déclarée par le client (« App vX · API vY », détecte un bundle resté en cache après un déploiement) |
| `generationFailure` | la RAISON d'un échec de génération RÉCENT (< 12 h) de la saison, résolu depuis la DERNIÈRE version FAILED\|COMPLETED de la saison (COALESCE(`solveStartedAt`, `createdAt`)) — une COMPLETED postérieure l'efface (le gestionnaire a re-généré avec succès). Résumé borné `{status, kind: technical\|business, type, failedAt, version:{number,name}, firstError ≤300, countsByType}` — `kind` distingue une panne d'INFRASTRUCTURE émise par le backend (`engine_timeout`/`engine_rejected`/`engine_error`/`internal_error`/`engine_status`/`engine_failed`/`club_mismatch`/`overlay_entry_missing`/`stuck_timeout`) d'une INFÉASIBILITÉ du solveur (tout autre type de diagnostic) ; absent hors échec frais |
| `simulatedToday` | horloge simulée du club (démos/dev), `null` pour tout club à l'heure réelle |

**Toujours résolu depuis le club AUTHENTIFIÉ, jamais depuis un id client** : `clubId`/`seasonId`/
`planId` envoyés dans `context` ne sont pas dans `LIGHT_CONTEXT_FIELDS`, donc déjà tombés avant
d'atteindre l'enrichisseur — gardé par le NR d'axe *tenant isolation*
`Security/FeedbackContextIsolationTest` (bloquant, `docs/testing/blocking-tests.md`). Best-effort :
une lecture qui échoue n'annule jamais le dépôt (contexte partiel, erreur tracée sans PII).

Le client peut aussi joindre des repères TEXTE libres pour situer l'incident (whitelistés,
longueur bornée, stockés verbatim — jamais exécutés) : `screenLabel`, `activeFilters`,
`displayedRange`, `appVersion`, `lastClick`, `incidentStatus`/`incidentUrl`/`incidentCode` (trio
d'un appel réseau fautif, repris de `lastIncidentStore` — capté seulement sur un ≥ 500).

**Collecte livrée** (chantier F PR2) pour `screenLabel`, `lastClick` et le trio incident :
- `screenLabel` est composé par l'écran appelant et voyage `PageHeader`/`FeedbackButton`/
  `FeedbackDialog` — wizard : « Assistant · Étape i/6 · <libellé de l'étape> » ; module matchs :
  « Matchs · <onglet actif> » (`MatchesLayout.tsx`).
- `lastClick` vient de `frontend/src/shared/feedback/lastClickStore.ts` : un écouteur de clic
  GLOBAL, posé UNE fois au bootstrap (`installLastClickCapture`, `main.tsx`), retient le **nom
  accessible** (aria-label → libellé associé → texte visible → title) et le **rôle ARIA** du
  contrôle actionnable le plus proche — **jamais la valeur** d'un `<input>`/`<textarea>`/
  `<select>` ni un texte saisi (règle centrale de confidentialité du fichier), fenêtre de
  fraîcheur 10 min comme `lastIncidentStore`.
- le trio incident est désormais lu en entier (`readRecentIncident`, pas seulement le
  `requestId`) par `FeedbackDialog`.
- `appVersion` (P4-355) est posé par `FeedbackDialog` lui-même, systématiquement, pour les
  **deux portes** — pas par un écran appelant : la version du BUNDLE front (`shared/lib/appVersion.ts`,
  foyer unique), cuite au build par `VITE_APP_VERSION` (build-arg `docker/frontend/Dockerfile`,
  posée au déploiement avec le tag/sha — `.github/workflows/deploy.yml`), repli « dev » hors build
  taggé (dev, jsdom, e2e).
- `activeFilters`/`displayedRange` (P4-356) sont désormais alimentés par le module Matchs : les
  deux `PageHeader` de `MatchesLayout.tsx` reçoivent les filtres NON-DÉFAUT (axe + compte de
  ressources cochées, types/familles de conflit cochés en clair, « extérieurs masqués ») et la
  plage affichée (« week-end du JJ/MM » / le mois en clair / « Phase : <libellé> », selon la
  temporalité de consultation) — composition PURE dans `features/matches/lib/feedbackContext.ts`
  (`composeActiveFilters`/`composeDisplayedRange`), câblée dans `MatchesLayout`. Les autres
  écrans ne les renseignent pas encore (contrat de bout en bout déjà posé, à reprendre écran par
  écran).

L'**affichage/recherche** en console superadmin est livré — § 3quinquies. La **capture d'écran**
optionnelle est livrée — § 3sexies.

## 3quinquies. Console superadmin : recherche, deep-liens, contexte en 5 blocs

L'onglet Signalements (D6, `features/admin/sections/FeedbackSection.tsx`) rend le contexte large
(§3quater) en **5 blocs titrés** — Planning, Période, Écran, Dernier geste, Technique — le métier
lisible d'abord, le technique en dernier ; un bloc sans aucune donnée ne s'affiche pas. L'URL
reste du TEXTE pur, jamais un lien cliquable (consigne déjà posée pour le contexte lourd).

Le bloc **Planning** affiche aussi, quand `generationFailure` est présent, un sous-bloc « Échec de
génération récent » : la nature (technique/métier) + le type de diagnostic + le numéro de version
fautive sur une ligne, puis le 1ᵉʳ message d'erreur et sa date. Le bloc **Technique** affiche
« App vX · API vY » (`appVersion` du contexte client, `apiVersion` résolu serveur) et, si les deux
diffèrent, une mention invitant à suspecter un bundle resté en cache.

La liste se filtre par statut ET par une recherche texte `q` (`GET /api/admin/feedback?q=`,
`AdminFeedbackController`) : nom de club OU mot du message, insensible à la casse (`ILIKE`),
les deux se combinent avec le filtre de statut. Les métacaractères `LIKE` (`%`, `_`, `\`) sont
échappés avant d'être injectés dans le motif — `q` est cherché **littéralement**, jamais comme un
joker ; borné à 100 caractères (400 au-delà, même contrat que la recherche de clubs).

Deux paramètres d'URL ouvrent directement la console sur un signalement : `?feedback={id}` ouvre
son détail, `?fstatus=untreated|treated` initialise le filtre de statut.

## 3sexies. Capture d'écran jointe (chantier F PR3)

Les deux portes (D1) offrent désormais une case **« Joindre une capture »** — **cochée par
défaut**, décochable, juste sous le champ de message. Elle couvre ce que le texte ne dit pas
(mise en page, état exact d'un écran) sans ajouter un upload de fichier arbitraire : une seule
image, générée côté client, jamais choisie par l'utilisateur.

**Côté client** (`frontend/src/shared/feedback/captureScreen.ts`) : la librairie de rendu
DOM→image (`modern-screenshot`, nouvelle dépendance) est chargée en **import dynamique** (chunk à
part, jamais dans le bundle principal) ; `document.documentElement` est capturé, la **fenêtre de
signalement elle-même exclue** (`[role="dialog"]`, retirée du clone avant rendu) ; l'image est
plafonnée à 1920 px de large (`scale` réduit en conséquence) et exportée en **WebP**, qualité
dégressive (0.8 → 0.6 → 0.4) jusqu'à tomber sous **2 Mo** — même borne que le serveur ; Safari, qui
ignore parfois le type demandé, peut rendre un PNG à la place, accepté tel quel. Toute erreur ou
tout dernier palier encore trop lourd rend `null` : la capture est **best-effort**, un échec
n'empêche jamais l'envoi du signalement (il part sans image).

**Côté serveur** (`FeedbackController::createFeedback`/`validateScreenshot`) : une image jointe
fait basculer la requête en **multipart** (`payload` = le JSON habituel dans un champ de
formulaire, `screenshot` = le fichier) — le chemin JSON pur reste inchangé pour tout appelant qui
ne joint rien. Validation dans l'ordre taille → **type SONDÉ** (`finfo`, jamais le type déclaré
par le client — un SVG ou un polyglotte tombent ici) → dimensions lues dans l'en-tête sans décoder
un pixel (≤ 8000 px/côté, ≤ 40 Mpx — garde-fou anti-bombe de décompression), AVANT toute
persistance. L'écriture est **atomique dans les deux sens** : un signalement invalide ne laisse
aucune ligne ; si l'écriture du blob échoue APRÈS la création de la ligne, la ligne est retirée
(jamais de signalement marqué « avec capture » dont l'image manquerait).

Le blob vit **hors de la table `feedback`, hors RLS** — `FeedbackScreenshotStorage` (interface) /
`LocalFeedbackScreenshotStorage` (disque local, patron `LogoStorage`), clé = l'id du signalement
(UUID validé avant tout accès disque, pas de traversée de chemin possible), même paramètre
d'injection que les logos (`app.feedback_screenshot_storage_dir`,
`backend/config/services.yaml`). La table ne porte qu'un pointeur léger, `feedback.has_screenshot`
(migration `Version20261024120000`). **Seule porte de lecture** : `GET
/api/admin/feedback/{id}/screenshot` (console superadmin uniquement — le firewall `^/api/admin`
fait l'étanchéité tenant), type sondé à la lecture, `X-Content-Type-Options: nosniff` +
`Cache-Control: no-store`. La console (`FeedbackSection.tsx`) affiche l'aperçu en `<img>` (agrandissable au clic ou au clavier) quand
`hasScreenshot` est vrai.

La **rétention** (purge de la capture, suppression du blob à l'effacement RGPD d'un club, sort
de l'export de portabilité) est tranchée et livrée — § 3septies. Détail RGPD :
[`docs/security/rgpd.md`](../../docs/security/rgpd.md).

## 3septies. Rétention RGPD + digest réduit (chantier F PR4)

`app:feedback:purge` (`AdminJobCatalog` clé `purge-feedback`, quotidien 3h50 — juste après
`purge-exports`, avant le contrôle de restauration hebdomadaire) ferme la rétention du canal en
deux volets, horloge applicative :

- **Texte + contexte** d'un signalement **traité** sont détruits 30 jours après `treated_at` : le
  message est vidé (`''`), le contexte mis à `NULL`, mais la **ligne survit** — `purged_at` posé
  (les indicateurs QoS de §3ter continuent de compter ce signalement). Idempotent
  (`purged_at IS NULL` au filtre) ; repasser en « non traité » (`untreat`) ne ressuscite jamais un
  texte déjà détruit. La console affiche « Contenu purgé (rétention) » à la place du message
  (`FeedbackSection.tsx`, clé `purgedAt` exposée par `GET /api/admin/feedback`).
- **La capture** (blob hors table, §3sexies) part au PREMIER des deux : J+90 après le dépôt
  (`created_at`) OU J+30 après « traité » (`treated_at`) — un `untreat` ne prolonge JAMAIS la
  rétention au-delà de J+90, même sans aucun traitement.
- **Effacement d'un club** (`DELETE /api/me` → `ErasedClubPurger`) détruit aussi désormais les
  blobs de capture de ses signalements, avant le `DELETE` de masse des lignes `feedback` qui en
  donne les ids. L'export de portabilité (`RgpdExportService`) continue de n'exporter que le
  texte du signalement — jamais le blob de capture, hors boucle DB.

Le **digest** (D4) change de forme à la même PR : plus un email par dépôt ni un listing avec
extraits du message, mais UN mail le matin, UNIQUEMENT si des signalements **non traités** ont
été **créés la veille** [J-1, J) — le compte d'items, jamais leur contenu — avec un lien UNIQUE
vers la console filtrée sur les non-traités
(`/admin?tab=incidents&sub=signalements&fstatus=untreated`), jamais un lien par item.

## 3octies. Drapeau « Signaler » sur toute modale, dédup 60 s, fil d'incident client (PR B « Signaler depuis une modale », D14-D17)

Une 3ᵉ porte s'ajoute aux deux de D1 : la primitive `Modal`
([`frontend-components.md`](../../frontend/docs/frontend-components.md) § `Modal`) porte à
GAUCHE de sa croix de fermeture un drapeau « Signaler », rendu **seulement** quand un canal de
signalement est monté (`ModalReportProviderContext`, câblé par `ModalReportProvider` dans
`AppLayout.tsx`) — l'app club OUI, la console superadmin et tout test de primitive NON (le
drapeau reste alors ABSENT, jamais un drapeau inerte). Un appelant peut le masquer
(`showReportFlag={false}`) : seul `FeedbackDialog` le fait, sur SA propre modale — le canal ne
se signale pas lui-même.

**D15 — état complet de la modale joint.** Une modale déclare `reportState={() => ({…})}` : une
fonction évaluée AU CLIC (jamais à chaque rendu), sérialisée en JSON (best-effort — un cycle ou
un échec de sérialisation rend `undefined`, jamais une exception) et envoyée comme `modalState`.
Stocké côté serveur HORS de `LIGHT_CONTEXT_FIELDS` (contrairement aux repères texte de §3quater),
plafond 100 000 caractères — au-delà, TRONQUÉ (`modalStateTruncated: true`), jamais refusé : le
fondateur a explicitement écarté une limite RGPD ici (les coachs ne sont jamais extraits du
texte libre collé, `promptTemplate.ts`) ; la rétention suit celle du reste du contexte (§3septies,
même colonne `feedback.context`). Patron vivant : `ParcImportModal.tsx` joint l'étape courante,
le parc collé, la dernière réponse d'analyse, les décisions construites et la dernière erreur
serveur. Console : bloc dépliable dédié (`FeedbackSection.tsx`), mention « tronqué » quand le
serveur a coupé.

**D16 — anti double envoi.** Deux gardes, client et serveur, motivées par le cas réel d'un club pilote
(4 envois à la même seconde, aucune garde avant ce lot) : côté client, un verrou SYNCHRONE posé
en tête de `handleSubmit`, AVANT l'attente de la capture d'écran (`submit.isPending` ne bascule
qu'après, il ne couvre pas cette fenêtre), ignore tout second clic pendant qu'un envoi est en
cours ; côté serveur, `FeedbackRepository::findRecentDuplicate` (même club + même auteur + même
topic + même message, fenêtre de 60 s à l'horloge RÉELLE) rend la requête IDEMPOTENTE — 201 avec
l'id du signalement EXISTANT, jamais une seconde ligne.

**D17 — fil d'incident côté client + enrichissement serveur du dernier échec.** La porte
CONTEXTUELLE seule (jamais la porte libre du burger) joint en plus :
- `recentErrors` (≤ 5, la plus récente en tête) : toute réponse API ≥ 400 — pas seulement ≥ 500,
  une rafale de 422/409 raconte aussi l'incident —, captée par le hook `afterResponse` de
  `client.ts` dans l'anneau de `lastIncidentStore.ts` (`recordRecentIncident`), DISTINCT du
  dernier incident ≥ 500 qui alimente seul le bloc « Détails techniques (dev) ». Chaque entrée :
  méthode, route, code machine, jusqu'à 5 messages `errors[]`, request-id, horodatage — bornes
  serveur `method` 8 / `route` 300 / `code` 80 / `requestId` 128 / `errors[]` 5×300 ;
- `recentClicks` (≤ 5 × 160 caractères) : les noms accessibles des derniers contrôles cliqués,
  captés par un écouteur de clic GLOBAL posé une fois au bootstrap
  (`lastClickStore.ts::installLastClickCapture`) — jamais la valeur d'un
  `<input>`/`<textarea>`/`<select>`, même promesse de confidentialité que `lastClick` (§3quater) ;
- `clientClock` (`{at, timeZone}`) : l'heure et le fuseau RÉELS du navigateur de l'auteur —
  exception assumée à `shared/lib/clock.ts` (ce n'est pas l'« aujourd'hui » métier, c'est
  l'horloge du poste, pour recouper un incident avec les logs serveur).

Tout est BORNÉ et TRONQUÉ côté serveur (`FeedbackController::applyClientTrail`), jamais refusé.

Côté serveur, `FeedbackContextEnricher::generationFailure` (§3quater) est ÉTENDU — jamais
réécrit — pour porter, en plus du résumé déjà livré :
- `scheduleId` du planning FAILED (pivot de la copie lourde) ;
- `solver` : le verdict du solveur (version, temps mur, timeout, nb variables/contraintes/
  conflits) ;
- `plan` : le plan (socle SEASON ou période CLOSURE/HOLIDAY) auquel appartient l'échec ;
- `gridSummary` : slots ouverts / réservations / pins verrouillés / blocs de mutualisation /
  minutes verrouillées — calculé par `PayloadCapacityMirror::gridSummary`, fonction PURE qui
  tolère les deux conventions de clés (snapshot moteur réel `trainingSlots`/`slotTemplates`, ou
  la forme abrégée `slots`/`reservations`) ;
- `snapshot`/`diagnostics` : la COPIE LOURDE (entrée réelle du solve + diagnostics détaillés),
  SAUTÉE sans doublon si le client a déjà nommé CE MÊME planning (le contrôleur l'a alors déjà
  jointe à la racine du contexte).

Ces champs voyagent avec le signalement et restent lisibles en console via « Copier le JSON »
(bloc Technique, §3quinquies) — ils n'ont pas chacun leur ligne dédiée dans les 5 blocs : seuls
le résumé `generationFailure` (nature/type/version/1ᵉʳ message/date) et `modalState` le sont.

NR d'axe *tenant isolation* étendu : `Security/FeedbackContextIsolationTest` — l'id du planning
en échec d'un club B (pivot de la copie lourde D17) ne fuit jamais dans le signalement d'un
club A.

## 4. Hors périmètre
Un système de tickets (statuts multiples, assignation, SLA), un chat, un forum, une base de
connaissances, ni le remplaçant de Sentry (les erreurs techniques remontent par Sentry, pas par ce
canal). **Pas de pièce jointe générique** : la seule image possible est la capture d'écran
optionnelle du signalement lui-même (§3sexies, un seul fichier généré côté client, jamais un choix
libre de l'utilisateur) — aucune autre surface d'upload.
