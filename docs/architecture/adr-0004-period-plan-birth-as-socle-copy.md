# ADR-0004 — L'adaptation naît comme une COPIE du socle (transcription, pas un solve)

- **Status**: accepted — Date: 2026-08-19 (besoin fondateur, plan validé en session)
- Programme **P2-44**.

## Contexte

Un plan de PÉRIODE (overlay d'ajustement ou reprise de vacances — [`types-de-planning.md`](../../specs/courantes/types-de-planning.md))
naissait jusqu'ici sans V1 : le gestionnaire déclenchait un **solve CP-SAT complet** sur la
sélection de période (équipes/gymnases/contraintes filtrés — [ADR-0002](adr-0002-pattern-plan.md))
pour obtenir sa première version.

C'est disproportionné au besoin réel : la très grande majorité des adaptations de période sont
de la **routine** — un gymnase fermé trois semaines, une équipe qui réduit son volume — pas une
réorganisation. Un parent ne réorganise pas sa semaine de dépose/récupération pour une
indisponibilité de trois semaines ; il attend le planning **identique**, moins ce qui ne passe
plus. Un solve complet, lui, **redistribue tout** : la pénalité de stabilité et la proximité au
précédent ([ADR-0001](adr-0001-single-pass-solve.md)) ne font que départager des ex æquo une fois
l'espace de solutions déjà exploré, ou tenir une séance sous une préférence saisie — elles
n'empêchent pas le solveur de reproposer une séance à un horaire strictement équivalent à ses
yeux, mais pas aux yeux d'une famille qui a organisé sa semaine autour de l'ancien. En comblement
(voir plus bas), le backend n'émet d'ailleurs pas `previousAssignments` : seule la copie du socle
garantit l'identique.

**Alternative écartée** : contraindre le solve à imiter le socle (pénalité de stabilité en HARD,
ou socle injecté comme candidat unique par équipe) garde le coût d'un solve complet (budget
adaptatif, non-déterminisme documenté à la marge) pour un résultat qui, dans le cas nominal, est
*déjà* connu d'avance — la sélection de période est un filtre déterministe sur des placements déjà
valides. Contraindre un solveur à retrouver une réponse calculable directement est un détour, pas
une garantie de plus.

## Décision

### Transcription — la V1 sans solveur

La V1 d'un plan de PÉRIODE est une **transcription sans solveur** de la version **POINTÉE** du
plan SEASON (le socle en vigueur — `SocleGuard::assertSeasonPlanChosen`), filtrée par la
**sélection de période EXISTANTE** (`PeriodConstraintSelector`/`PlanVenueClosures` — le même
filtre que le gate pré-solve et le payload, jamais un second calcul) :

- équipe désactivée pour la période → ses séances ne sont **pas copiées** (elle ne joue pas) ;
- gymnase désactivé, ou séance sur un couple (gymnase, jour) effectivement fermé → non copiée,
  répertoriée **« à replacer »** avec sa raison (`venue_disabled` / `venue_closed`) ;
- équipe réduite (`sessionsPerWeek` d'override < séances du socle) → retrait **déterministe** des
  dernières séances de la semaine (tri jour puis heure décroissants) — « à replacer »
  (`team_reduced`) ; le gestionnaire échange ensuite par `move` pour un autre arbitrage.

C'est l'**industrialisation, côté produit**, d'un patron qui existait déjà comme geste manuel de
seed (`BcclSeeder::pointPeriodPlanAtReprise`) : version `COMPLETED` sans passer par l'engine,
numérotée par `SchedulePlanProvisioner::linkSchedule`, snapshot posé par
`ScheduleConstraintBuilder::buildForPeriodPlan` — le seed n'est pas refactoré pour appeler ce
service, le service copie son patron. Implémentation : `App\Service\PeriodPlanTranscriber`
(`backend/src/Service/PeriodPlanTranscriber.php`), route
`POST /api/schedule_plans/{id}/transcribe-from-socle` (`App\Controller\TranscribePeriodPlanController`).

Invariants qui tiennent le mécanisme :

1. **Verrous HARD révocables, pas immuables** — « des verrous qu'on décide de bouger volontairement
   après ». L'**origine** du verrou est VRAIE, jamais devinée (`LockOriginProvenanceTest`) :
   `RESERVATION` si une `Reservation` du plan coïncide avec le placement, sinon `MANUAL`.
2. **Pas de pointage automatique** — la V1 transcrite reste `COMPLETED` non pointée, validée via la
   route de validation existante, comme une version issue d'un solve.
3. **« À replacer » est SERVI par le backend**, jamais redérivé par le front — la réponse porte la
   liste complète (équipe/jour/heure/gymnase/raison).
4. **Provenance sans migration** — `Schedule.solverVersion` porte un marqueur produit dédié
   (`PeriodPlanTranscriber::TRANSCRIPTION_MARKER = 'socle-transcription'`), un champ existant
   réutilisé pour dire « cette version n'est pas née d'un solve ».
5. **Zéro appel moteur, contrat inchangé** — le service n'émet aucun payload engine.

**Revalider une AUTRE version de saison détruit les plans de période non commencés** —
`ValidateScheduleController::__invoke` calcule `OverlayManager::periodPlansInvalidatedBySeasonChange`
puis appelle `deletePeriodPlanForEntry(force: true)` sur le **plan entier** de chaque période non
commencée (grille copiée comprise), pas seulement ses versions : une copie transcrite ne survit
donc jamais à un socle qui a changé sous elle, sans mécanisme de détection de dérive dédié.

### Comblement partiel (`POST /api/schedules/{id}/fill`)

Plutôt que le choix binaire transcrire-ou-solver-tout, un solve **partiel** d'une version de plan
de PÉRIODE, borné aux séances « à replacer » (`App\Controller\FillPeriodPlanController`). Crée une
V+1 (savepoint) et dispatche le rail de génération async **existant** en mode fill
(`GenerateScheduleMessage::fillSourceScheduleId`) : `ScheduleConstraintBuilder::withPinnedAssignments`
greffe, **dans le payload du solve seul** (jamais persisté en base), les placements de la version
source en épingles `lockLevel: HARD` — un HARD n'a pas de variable côté moteur, le solveur ne peut
donc placer QUE les trous. Le handler saute `withPreviousAssignments` en mode fill (no-op sur du
déjà-épinglé) et le remplace par une **référence socle** : les placements
`{teamId, dayOfWeek, startTime}` — sans `venueId` — de la version pointée du plan SEASON sont émis
au moteur (`socleReferenceAssignments`) comme bonus de placement par tier, sans jamais entrer dans
le hash de snapshot. Verrou de génération, Mercure, import : réutilisés tels quels. Zéro changement
moteur, contrat backend⇄engine intact. Quotas : `ClubQuotaSubscriber` couvre 4 routes de solve
(`generate`/`regenerate`/`regenerate-from`/`fill`). Écran : bouton « Combler automatiquement »
(`PlanningPage`, visible dès qu'une dérive porte des séances « à replacer »).

NR sémantique (groupe `contract`, job `engine-semantics`) :
`CrossStack/FillPreservesCopiesAndFillsGapsTest` — falsifie que les placements copiés restent
INTACTS et que les orphelines sont placées, avec un vrai solveur. Détail :
`backend/docs/backend-inventory.md` §3, `frontend/docs/frontend-spec.md` §6.7 bis.

### La transcription est le défaut sur une fermeture vierge

À l'arrivée sur l'étape Génération d'un plan de période de type **FERMETURE**
(`CalendarEntryPeriodType::CLOSURE`) **sans aucune version**, la transcription se déclenche
automatiquement — le planning de saison amputé des contraintes de la période est déjà à l'écran,
prêt au déplacement manuel, sans que le gestionnaire ait à cliquer le bouton manuel de
transcription. Les trois issues restent atteignables : déplacer à la main, combler, tout remanier.

1. **HOLIDAY exclu, à l'octet près** — le bouton manuel « Partir du planning de saison » reste le
   seul geste sur une reprise de vacances. Deux raisons : décision de sens (« les vacances sont
   TOTALEMENT différentes d'un incident de saison, c'est un planning TOUT nouveau, je ne veux pas
   de copie du socle ici ») et raison **technique** : une reprise dont la grille est réécrite
   verrait les séances du soir du socle copiées en verrous HARD **hors grille** —
   `OrphanPinGuard::firstOrphanMessage` (appelé par `GenerateScheduleController` ET
   `FillPeriodPlanController`) refuserait alors 422 Régénérer ET Combler ; l'auto-transcription y
   enfermerait le gestionnaire au lieu de l'aider.
2. **Mutation FRONT, jamais un GET qui écrit** — le déclencheur vit dans `GenerateStep.tsx` (effet
   à l'arrivée sur l'étape, ref one-shot par plan), le serveur reste seul juge. Le 409 « plan déjà
   versionné » d'un double appel est traité comme **bénin** : le front réconcilie la liste des
   versions sans bandeau rouge.
3. **Zéro retrait** — le bouton manuel n'est ni supprimé ni relibellé, sa condition d'affichage
   (`0 === periodPlanVersions.length`) le fait disparaître de lui-même dès qu'une V1 existe.

Une V1 transcrite `COMPLETED` est une version du plan comme une autre : elle est déjà éligible au
repli générique de `GenerateScheduleHandler::resolvePreviousAssignmentSlots` (un solve complet
volontaire ultérieur émet ses placements en `previousAssignments`), sans mécanisme dédié. NR :
`CrossStack/PreviousAssignmentsPayloadParityTest` (step de `blocking-tests`) falsifie ce cas dans
les deux sens : ni la séance « à replacer » filtrée à la transcription ni un créneau du socle ne
fuient.

### Les écarts nommés vis-à-vis du socle (`GET /api/schedules/{id}/socle-deviation`)

Après génération (transcrite, comblée ou solvée), l'écran embarqué d'une période de type
**FERMETURE** ne se contente pas de laisser comparer deux grilles à l'œil : il **nomme** les
écarts vs le socle. Route de **LECTURE** (`SocleDeviationController` → `SocleDeviationCalculator`
→ `SocleDeviationResult`), donc **re-appelable** — ce qui manquait à la liste « à replacer », qui
n'existe que dans la réponse du POST de transcription et meurt à la navigation.

1. **La cible est la VERSION, pas le plan** — un plan sans version est un cas IMPOSSIBLE (sans
   version, pas d'id à appeler) ; une V1 transcrite n'a pas le même diff qu'une V+1 comblée.
2. **Deux catégories, pas quatre** : **déplacée** (même équipe, placement différent) et **non
   replacée** (présente au socle, absente de la période). Les séances nouvelles et inchangées ne
   sont pas rapportées — le gestionnaire veut savoir ce qu'il a perdu et ce qui a bougé.
3. **L'appariement est chronologique et déterministe** — par équipe, les clés de placement
   identiques (`team:venue:day:HH:MM`) sortent inchangées ; le reste est trié (jour, heure,
   gymnase) croissant des deux côtés et apparié **positionnellement** ; le reliquat du socle est
   « non replacé » — une équipe réduite laisse donc ses dernières séances de la semaine non
   replacées, le même déterminisme que la réduction du transcriber. Le serveur n'invente pas « qui
   est allée où ».
4. **La raison est DÉRIVÉE, jamais fabriquée** — précédence `team_reduced` > `venue_disabled` >
   `venue_closed`, **`null`** quand la sélection de période n'explique pas l'absence (suppression
   manuelle, solve qui n'a pas replacé) : le front rend alors la ligne sans étiquette.
5. **FERMETURES seulement** — une vacance réécrit sa grille de reprise, comparer dirait « tout a
   bougé ». Refus **422** nommé sur tout autre type de période et sur un plan SEASON ; **409** sur
   une version non `COMPLETED` ou un socle non pointé (route atteignable même là : une période déjà
   commencée peut se retrouver face à un socle rouvert, qui ne détruit que les plans futurs).
6. **Lecture ouverte au Membre** — pas de gate management, aucune écriture.

Le panneau front (`SocleDeviationPanel`) **s'ajoute** au panneau « à replacer » sans le remplacer
(décision fondateur « les deux affichés pour le moment »). NR bloquant
`Security/SocleDeviationParityTest`.

## Conséquences

- Une période dont le besoin réel est « la routine, moins ce qui ferme » obtient sa V1
  **instantanément**, sans budget solveur, sans variance de sortie CP-SAT.
- Le geste reste **optionnel** : rien n'empêche un solve complet sur un plan de période vierge —
  la transcription est une V1 alternative, pas un remplacement du solve.
- Les trois mécanismes (transcription, comblement, écarts nommés) sont zéro appel engine, zéro
  ligne moteur, `CONTRACT_VERSION` inchangé — sauf le comblement, qui émet un bloc optionnel au
  contrat existant (`socleReferenceAssignments`).
- **NR bloquant** : `Security/PeriodCopyBirthTest` — falsifie les deux sens (séance copiée+
  verrouillée ; jour fermé/gymnase désactivé/équipe réduite « à replacer » avec leur raison ;
  réduction déterministe ; plan déjà versionné refusé 409 ; route sous les gardes rôle+tenant).
  Axes structurants touchés (CLAUDE.md §7.1) : *generation pipeline* et *planning lifecycle*.

## Alternatives considérées

- Contraindre le solve à imiter le socle — voir §Contexte, écartée : coût d'un solve pour un
  résultat calculable directement dans le cas nominal.
