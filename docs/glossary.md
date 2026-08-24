# Glossaire Amateo — termes métier & clés de payload

> **Un concept = un mot.** Glossaire transverse (les termes traversent les zones — le payload est
> le contrat). Référencé depuis `CLAUDE.md` §Pointers ; vocabulaire contraintes exhaustif :
> `engine/docs/constraint-vocabulary.md`.

## Métier (FFBB / club)

| Terme | Définition |
|-------|------------|
| **Club** | Racine tenant. Un club = un espace de données isolé (RLS + `TenantFilter`). |
| **Saison** | Cadre annuel d'un club (pivot calendaire **15 juillet**). Une saison archivée est en lecture seule (409 à l'écriture). |
| **Équipe** (`Team`) | Unité planifiée : catégorie d'âge (U9…U21, senior), genre, niveau, `sessionsPerWeek`. |
| **Gymnase** (`Venue`) — *jamais « salle »* | Lieu d'entraînement, porte des créneaux (`trainingSlots`). Divisible via `canSplit`/capacité. |
| **Solveur** — *interne ; l'UI gestionnaire dit « le système »* | Le moteur CP-SAT. Règle de vocabulaire (fondateur, 2026-08-04) : dans tout libellé face au gestionnaire, écrire « le système » (« Rendre au système », « Diagnostics du système »…) — « solveur » reste le mot du code, des docs et de la console superadmin. |
| **Habitude de match** (`TeamMatchHabit`) | La fenêtre habituelle d'une équipe (« SF3 = dimanche 17h30 ») : jour + heure-POINT + gymnase optionnel, une par jour. Pré-remplit le placement, protège les week-ends sans calendrier (bloc fantôme), estime l'heure des matchs extérieur. Recopiée en N+1 |
| **Passerelle** (`TeamLink`) | Lien DÉCLARÉ entre deux équipes (aucune entité joueur n'existe) : `NOT_SIMULTANEOUS` (joueurs partagés — leurs matchs ne doivent pas se chevaucher, finding `TEAM_LINK_OVERLAP`) ou `BACK_TO_BACK` (enchaînement souhaité — préférence solveur, pas une règle). Symétrique, nom neutre : servira aussi à l'entraînement |
| **Source de placement** (`Fixture.placementSource`) | Qui a posé heure+salle d'un match domicile : `MANUAL` (tout geste API du gestionnaire — avec `SUBMITTED`/`VALIDATED`, c'est une **ancre FIXED** que le solveur ne bouge jamais) ou `SOLVER` (re-plaçable au prochain solve, bonus stabilité). `null` legacy = manuel. UI (PR E1) : cadenas sur la grille — verrouiller = re-stamp MANUAL, « rendre au solveur » = SOLVER, accepté seulement à placement inchangé. ADR-0003 |
| **Fenêtre d'accès match** (`VenueMatchWindow`) | Plage `jour + heures` qu'un gymnase reçoit **les jours de match** — distincte des créneaux d'entraînement. ⚠ Ne jamais écrire « de la mairie » dans un libellé : c'est le cas du BCCL, pas de tous les clubs (conseil départemental, lycée, salle privée). Un gymnase avec ≥ 1 fenêtre EST un « gymnase de match » (flag dérivé, jamais un booléen). Recopiées à la bascule de saison. Affichées en fantôme hachuré non interactif sur la grille du wizard (`frontend-wizard.md` §Gymnases) |
| **Indisponibilité gymnase** (`VenueUnavailability`) | Fermeture **toutes circonstances** d'un gymnase sur une plage de dates (incluses), posée au cockpit. Alerte les matchs placés ET les entraînements (impact chiffré) — ne bloque et ne régénère jamais rien. Jamais recopiée en N+1 |
| **Créneau** (`slot`) — *jamais « slot » dans l'UI* | Fenêtre `jour + heure début + durée` d'un gymnase. Id engine : `"jour:HH:MM"` (jour 1=lundi…7=dimanche). |
| **Coach** — *jamais « entraîneur » dans l'UI* | Encadrant d'équipes. Taxonomie : **salarié** (`isEmployee`), **coach-joueur** (joue aussi — `CoachPlayerMembership`), **bénévole**. Rôle `ASSISTANT` = optionnel, ne bloque jamais un placement. |
| **Contrainte** | Règle de placement saisie (famille × ruleType × config) ou implicite (no-overlap, capacité…). |
| **Rang / tier** (`PriorityTier`) | Priorité S/A/B/C/D d'une équipe, poids objectif exponentiel (S=10000…D=1). **Perception interne du club** — ça bouge, et ça reste modifiable même sur une équipe engagée. À ne pas confondre avec le **niveau**. |
| **Niveau** (`Team.level`) | Le niveau FÉDÉRAL sous lequel l'équipe est inscrite : REGIONAL, DEPARTEMENTAL, ELITE… Se saisit **avant** de générer (il alimente le tag NIVEAU, donc les contraintes, donc la photo de structure). **Figé dès qu'elle est engagée — sans exception**, y compris s'il n'a jamais été renseigné : le laisser bouger après ferait diverger la photo et la base. Rien à voir avec le rang/tier. |
| **Équipe engagée** | Elle porte **au moins un match**, quel qu'en soit le statut. La correspondance faite par l'import FBI entre une rencontre et une de nos équipes **est** l'engagement : la fédération la connaît. Le statut ne dit rien de lui — l'import crée TOUT en `UNPLACED`, donc filtrer dessus rendrait la garde inerte au moment où elle doit mordre. Ses matchs étant déposés, elle ne peut plus être **supprimée** ni changer de **niveau**. Nom, rang, `isActive`, créneaux et gymnase restent libres — « une équipe qui joue peut être déplacée, pas supprimée ». Exposé par `TeamResource.isEngaged` ; la règle vit dans `TeamEngagementGuard`. |
**Tags d'âge — ADULTE et SENIOR se CHEVAUCHENT** (volet A du lot tags, 2026-08-15) : `ADULTE` = +18 ans (`ageMin >= 19`, ex-`SENIOR` renommé — le nom mentait, l'écran disait déjà « Adulte »), `SENIOR` = +22 ans (SM/SF, Vétérans — **pas** les U21). Une équipe ≥22 porte les DEUX. Les autres tranches (BABY, EMB, JEUNE) restent exclusives entre elles. Nouveau tag d'axe NIVEAU : `COMPETITION` = équipe dont le niveau n'est ni LOISIR_ADULTE ni LOISIR_JEUNE (**une équipe sans niveau n'est PAS en compétition** — ne jamais contraindre par défaut).
| **Doléance** (`CoachWish`) | Souhait exprimé par un coach pour une semaine : nombre de séances voulues, jours indisponibles, commentaire libre. **Ce n'est PAS une contrainte** — aucun effet solveur : c'est une saisie que le gestionnaire lit avant de décider. |
| **Campagne de doléances** (`CoachWishCampaign`) | Collecte bornée ouverte par le gestionnaire : semaines × équipes × date limite. |
| **Lien coach** (`CoachWishToken`) | Lien personnel `/doleances/{token}`, **sans login**. Le token est un secret aléatoire **stocké en clair** (décision fondateur : le gestionnaire doit pouvoir le recopier pour le renvoyer) ; son privilège est minuscule et borné par construction — il n'écrit que des souhaits, dans le périmètre du token (ce coach, ses équipes ∩ campagne, les semaines de la campagne), et meurt à la date limite. |
| **Appariement FFBB** | Le rattachement d'un **engagement** fédéral (une équipe du club vue par la ligue : compétition + poule) à une équipe de l'app — écrit les réfs sur sa `Competition`, fige `expectedMatchdays` (2×(N−1)) et la liste des clubs de la poule. **Ré-apparié à chaque phase** (1 clic, pré-rempli) ; ligne non rattachée = rien modélisé. Données de la ligue : un écart se corrige auprès d'elle |
| **Socle** | Le calendrier de la saison en vigueur : la version que **pointe** le plan `SEASON`. Les modules (matchs, plans secondaires) l'exigent. Se lit sur le pointeur — il n'y a pas de jalon qui le dise. |
| **FFBB / FBI / ARA** | Fédération / son outil de gestion (import matchs `externalRef`) / code d'affiliation club. |

## Cycle de vie planning

> Vocabulaire du pattern « Plan » ([ADR-0002](architecture/adr-0002-pattern-plan.md))
> — **il fait foi pour parler du produit**, et depuis la bascule du 2026-07-16 il décrit
> aussi le code. Termes **bannis** : *baseline*, *planningName*, statuts *VALIDATED*/
> *ARCHIVED* — ils n'existent plus nulle part.
>
> `CalendarEntry.overlayScheduleId` (pointeur inverse d'une période) et `liveContext`
> (la ★) survivent : le premier jusqu'au lot C, la seconde **par décision** (inv. 17).

| Terme | Définition |
|-------|------------|
| **Plan** (`SchedulePlan`) | LE planning nommé : type (`SEASON`/`CLOSURE`/`HOLIDAY`) + période + nom + **pointeur**. C'est l'objet que le gestionnaire manipule. |
| **Version** (`Schedule`) | Une résolution du solveur d'un plan : « V3 ». Jamais nommée par l'humain. Cycle : `DRAFT → PENDING → GENERATING → COMPLETED \| FAILED`. |
| **Version choisie** | Celle que **pointe** le plan (`chosenScheduleId`) = « validée ». **Valider = pointer**, et **les autres versions sont supprimées**. « Validé » n'est pas un statut : ça se dérive du pointeur, et de rien d'autre (`Schedule.isChosen` le dit par version). |
| **Espace de travail** | Plan au **pointeur null** : on génère/compare des versions, on choisira. Rouvrir y ramène. **Aucun pointage automatique** — seul le gestionnaire pointe. |
| **★ / photo chargée** | La version dont la photo de structure est chargée dans le wizard (`liveContextScheduleId`). **Ce n'est PAS le pointeur du plan** : elle suit chaque génération COMPLETED du socle. C'est l'auto-*pointeur* qui est mort, pas la ★ (inv. 17). |
| **Plan secondaire** | Plan `CLOSURE`/`HOLIDAY` borné à une période du cockpit — vacances, fermeture. Exige que le plan `SEASON` soit **pointé**. Ne remplace pas le plan de saison. |
| **Segment** (P2-41) | La fenêtre d'un enfant de période (`CalendarEntry.parentEntryId`) : un bloc de semaines calendaires **pleines et contiguës** (lundi→dimanche, clamp saison admis aux deux bords), inclus dans les semaines qui couvrent la mère. **La semaine simple est le segment de taille 1** (rétro-compatible, aucun nouveau concept). Un segment naît avec SON plan comme n'importe quel enfant (rail 1 entrée = 1 plan) et reste soumis aux mêmes garde-fous — anti-chevauchement entre segments frères, garde d'unicité de fenêtre 409 `window_already_planned` (P2-38). Livré des deux côtés : API (`CalendarEntryStateProcessor::assertValidWeekChild`) et écran — le picker (`WeekPickerDialog`) propose des segments précochés aux ruptures géométriques de l'offre, scindables et fusionnables. Détail : [ADR-0002](architecture/adr-0002-pattern-plan.md) (lot P2-41). |
| **Réglages de période** | Ce qui s'accroche au **plan** (jamais au déclencheur calendrier) : coches d'équipes (`TeamPeriodOverride`), contraintes gardées/enlevées (`ConstraintPeriodOverride`), sa **grille de gymnases**, ses **modes de gymnase**, ses réservations. Les contraintes **datées du FAIT** (« Barros fermé du 20 au 26 »), elles, restent sur la `CalendarEntry` : elles décrivent l'incident, pas la réponse — et c'est le radar qui les lit pour déclencher le geste « Adapter ». |
| **Grille de période** | Depuis #8 : **la période POSSÈDE sa grille**. À la naissance du plan, TOUTE la grille de saison de chaque gymnase est **COPIÉE** en créneaux du plan (`copySeasonalSlots`). Il n'y a donc **jamais** d'union saison ∪ période : le build d'overlay ne lit que les créneaux du plan, et rien ne peut se chevaucher entre couches. |
| **Mode de gymnase** (`VenuePeriodOverride`) | Réglage **sparse** par (plan, gymnase) agissant sur cette copie, DEUX réglages indépendants et facultatifs : **`mode`** (NULLABLE) — **DÉSACTIVÉ** = la grille est conservée mais le gymnase sort du payload envoyé à l'engine · **VIERGE** = la grille est vidée · **pas de ligne = hériter** (revenir à hériter revide puis **RECOPIE** depuis le modèle de saison) — et **`dayOverrides`**, un masque manuel jour ISO 1..7 → OPEN\|CLOSED qui s'ajoute au défaut dérivé d'une fermeture datée (v. **Indisponibilité informative** ci-dessous). |
| **Indisponibilité informative** (décision fondateur 2026-08-18) | Une fermeture datée (`venue_closed`) ne verrouille plus le réglage du gymnase — elle **PRÉ-REMPLIT** un défaut que le masque du plan peut contredire, jour par jour (jour rouvert OPEN, jour décoché CLOSED). La composition vit dans la maison unique `PlanVenueClosures::effectiveStateForPlan/Entry`, partagée par le gate, le payload, `OrphanPinGuard`, les réservations et le radar. Supplante le régime P2-37 du matin même (réactiver un gymnase entièrement fermé était refusé en 422, non réversible). |
| **Épinglage orphelin** | Un verrou HARD ou une réservation qui ne retombe plus sur aucun créneau de la grille de la période. **Bloque la génération en 422** en nommant le gymnase, le jour et l'équipe (`OrphanPinGuard`) — jamais filtré en silence. **Exceptions (non bloquantes, l'épinglage est inerte) : un gymnase DÉSACTIVÉ** (`VenuePeriodOverride`, P3-20) **ou EFFECTIVEMENT fermé-total sur la fenêtre** (état effectif de `PlanVenueClosures`, incident × masque manuel, v. **Indisponibilité informative**) — un jour EFFECTIVEMENT fermé (par le défaut ou par le masque) d'un gymnase par ailleurs ouvert reste bloquant. |
| **Déblocage cockpit** | Le plan `SEASON` possède **≥1 version terminée** (`COMPLETED`/`FAILED` — le solveur a rendu sa réponse) — exposé par `/api/me.seasonPlan.hasFinishedVersion`. **Indépendant du pointeur** : avoir généré une fois suffit, donc rouvrir ne re-verrouille jamais le cockpit. |
| **Cockpit** | Vue temporelle de la saison : périodes (`CalendarEntry` PERIOD/EVENT), overlays, matchs. |
| **Génération** | Pipeline async : controller → Messenger(Redis) → handler (lock + snapshot figé) → engine CP-SAT → import → Mercure. |
| **Snapshot figé** | Photo des données au moment du dispatch — le solve est **rejouable**, insensible aux éditions concurrentes. |
| **Réservation** (`Reservation`) | Épingle durable équipe→créneau (HARD), envoyée à l'engine en `slotTemplates`. ≠ `ScheduleSlotTemplate` (résultat de solve). |
| **Diagnostic** | Explication structurée d'un échec/compromis solveur (`ScheduleDiagnostic`, ex. `day_constraint_conflict`, `venue_minimum_unreachable`). |

## Payload backend↔engine (contrat `CONTRACT_VERSION`, actuel 2.15)

Clés racine : `version` · `clubId` · `seasonId` · `scheduleName` · `solverSeed` (déterminisme) ·
`solverTimeoutSeconds` (**plafond**, jamais le budget réel — paliers adaptatifs 60/180/600 s) ·
`venues` · `teams` · `coaches` · `constraints` · `slotTemplates` · `priorityTiers`.

| Clé | Sens |
|-----|------|
| `venues[].trainingSlots[]` | Créneaux ouverts du gymnase (jour/heure/durée/capacité). |
| `teams[].sessionsPerWeek` | Cible de séances (soft — ENG-18, pas un plancher dur). |
| `teams[].tags` | Tags système **lus** en base (`TeamTagAssignment`) par `ScheduleConstraintBuilder`. Ils sont maintenus au write-path par `TeamTagSyncListener` — le builder ne les matérialise PAS : il l'a fait jusqu'à P2-9ter, et l'écriture au milieu de la sérialisation les rendait paradoxalement **toujours vides** dans le payload. ⚠ **Deux déclencheurs, pas un** (P4-50, 2026-08-11) : `postPersist`/`postUpdate` sur `Team`, **et** `postUpdate` sur `SportCategory` — la dérivation lit le nom et les bornes d'âge de la catégorie, donc corriger une catégorie doit retaguer ses équipes, qu'on n'a pas touchées. Le fan-out passe par le repository : le `SeasonFilter` le borne à la saison courante (une catégorie est club-scopée et sans saison, ses équipes existent dans N-1/N/N+1). **Aucun rattrapage de l'existant** : un club reste sur ses tags d'alors jusqu'à la prochaine écriture de l'un ou l'autre. |
| `coaches[].isEmployee` | Salarié (distribution équitable dédiée). |
| `constraints[]` | `{scope, scopeTargetId, family, ruleType, config}` — familles TIME/DAY/FACILITY/COACH_AVAILABILITY. **Toute clé de `config` absente de `engine/docs/constraint-vocabulary.md` est ignorée sans erreur.** |
| `ruleType` | `HARD`/`LOCK` = dur (jamais violé) · `PREFERRED`/`BONUS` = soft (objectif). |
| `config.targetTag` | Cible de groupe — le backend **éclate** en N contraintes TEAM avant l'envoi. |
| `slotTemplates[]` | Épingles HARD (réservations, verrous manuels). |
| `priorityTiers[].orToolsWeight` | Poids objectif du rang (S=10000…D=1). |
| Sortie : `status` | `completed` \| `failed` (INFEASIBLE → `failed` + diagnostics ; **pas de fallback par relaxation**). |
| Sortie : `score` | Valeur objectif (stable au re-run même seed ; l'*assignment* peut varier en multi-worker). |

## Infra & sécurité

| Terme | Définition |
|-------|------------|
| **Tenant** | Le club courant, résolu **côté serveur depuis le JWT** (le frontend n'envoie PAS `X-Club-Id` ; header spoofé → 403). |
| **RLS** | Row-Level Security PostgreSQL, policies `FORCE` sur `club_id`, clé = GUC `app.club_id`. Runtime `app_user` (restreint) / migrations-ops `clubscheduler` (connexion `admin`, **bypasse RLS**). CLI sans GUC → 0 ligne (attendu). |
| **GUC** | Variable de session PostgreSQL (`app.club_id`) posée par `TenantConnectionContext` (workers : depuis le message). |
| **season_filter** | Filtre Doctrine intra-club : scope saison (X-Season-Id validé, sinon saison calendaire courante). |
| **Mercure** | Hub SSE ; topic `club:{clubId}:schedule:{scheduleId}` ; publication **best-effort** (le front polle en secours). |
| **ClubGenerationLock** | Verrou Redis (`SETEX NX` + token) : une génération à la fois par club. |
| **phase1** | Groupe PHPUnit bloquant (tests structurants tenant/RLS/contrat/vie du planning). |
| **Gestionnaire / Membre** | Les deux rôles assignables d'une adhésion (`ClubRole`, P1-1) : Gestionnaire (`admin`) pilote tout ; Membre (`member`) lit tout, n'écrit rien — toute écriture API est management par défaut. `owner` : legacy, toléré en lecture comme management, jamais assignable. |
| **Désactivé** | État d'adhésion (`deactivated_at`) distinct de « en attente » : sorti du club, réversible par le geste Réactiver du gestionnaire — jamais par la file d'approbation ni par la console superadmin. |

## Wizard & frontend

| Terme | Définition |
|-------|------------|
| **Wizard** | Saisie guidée 6 étapes : équipes → gymnases+créneaux → coachs → contraintes → récap → génération. |
| **Work-loop planning** | Boucle d'ajustement post-génération : éditer/verrouiller/régénérer/valider. |
| **Onglet Réserver** | Crée des `Reservation` (pins durables) — pas des contraintes. |
| **Mode « uniquement » vs « au moins »** | `allowedDays` (whitelist dure) ≠ `forcedDays` (≥1 séance ce jour) — piège ENG-16. |
