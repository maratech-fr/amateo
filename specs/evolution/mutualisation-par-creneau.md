# Mutualisation par créneau — le terrain pense créneau, le modèle pense groupe (P2-51)

> Besoin cadré le **2026-08-25** (échange fondateur, liste terrain BCCL fournie le jour même),
> **mis en attente à la demande du fondateur** — « on évolue le modèle d'abord » puis « stocke
> cette demande pour qu'on ne la perde pas ». Ce fichier est le détail de la ligne **P2-51** de
> [`roadmap.md`](roadmap.md) ; il devient le point d'entrée du cadrage quand le lot démarre.
>
> ⚠ **Amendé le 2026-08-31** (§0) : le modèle RETENU est le **BLOC**, pas l'ancrage au créneau
> décrit en §3 « Direction de modèle ». §1, §2 et la liste des 8 partages restent valables tels
> quels (le besoin terrain et le mur du double-comptage n'ont pas changé) — seule la FORME choisie
> pour les représenter a changé. Lire §0 avant §3.

## 0. Amendement du 2026-08-31 — le modèle retenu est le BLOC

Le fondateur a amendé ce cadrage le **2026-08-31**, pendant la validation du plan de PR-1 :
l'ancrage au créneau (§3, patron `MatchSlotRotation`) est **abandonné au profit du BLOC** — un
ensemble d'équipes qui se comporte comme **une équipe à part entière**, déclaré avec son propre
nombre de séances communes (`commonSessions`).

Décisions arbitrées (remplacent la « Direction de modèle » de §3) :
- **D9 — le bloc se déclare comme une équipe** : dans l'étape Équipes du wizard, avec une liste
  déroulante bornée pour son nombre de séances communes (« la déclaration est identique à
  déclarer les créneaux dans une équipe », verbatim fondateur) ; sélectionnable dans Réserver
  comme une équipe ordinaire. **Ses séances lui APPARTIENNENT** — le solveur les place comme
  celles d'une équipe (PR-3) ; on ne déduit plus une séance commune de la co-présence, ce qui
  dissout structurellement le mur du double-comptage décrit en §2 (il n'y a plus de comptage
  DÉDUIT de créneaux imbriqués : chaque bloc porte SES séances, indépendamment des autres).
- **D10 — fait de PLAN, pas de saison** (question posée par l'agent de doc, tranchée par le
  fondateur) : « un bloc de mutualisation est un fait de plan — je ne m'attends pas à ce qu'il
  survive aux vacances ». Pas de persistance-avec-désactivation (machinerie d'états ambigus
  refusée) ; `schedulePlanId` nullable, patron exact de `SharedTrainingGroup` (NULL = socle
  saison, non-null = plan de période). Coût assumé : redéclarer les ~8 blocs à chaque overlay ;
  un « recopier les blocs du socle » est une extension explicite future, pas ce lot.
- **D11 — déplacé EN BLOC, jamais une équipe seule** : « un créneau mutualisé est un bloc — si je
  dois le déplacer je bouge les 2 équipes d'un coup » (verbatim). Conséquence pour PR-3 : le
  verdict du solveur refusera le déplacement individuel d'une équipe membre, le rail de retouche
  gagnera un geste « déplacer le bloc ». Hors périmètre PR-1 (modèle seul).
- **D12 — meurt ENTIER à la suppression d'un membre** : « je préfère tout défaire puis recréer que
  de retirer du groupe — la suppression d'une équipe est lourde de conséquence » (verbatim).
  Inverse le patron de survie-à-2 hérité de la rotation match ; implémenté par
  `SharedTrainingBlockPruneStep` (`backend/src/Deletion/SharedTrainingBlockPruneStep.php`).
- **Garde centrale** (conséquence directe de « autant voire moins de créneaux que les équipes qui
  la composent ») : pour chaque équipe, **Σ des `commonSessions` de ses blocs de même portée ≤
  ses séances/semaine effectives** (`EffectiveTeamSessions`, override de période inclus) —
  `SharedTrainingBlockStateProcessor::assertBlockValid`.
- **Multi-appartenance PERMISE** (c'est elle qui manquait au modèle groupe, §2) : une équipe peut
  être membre de plusieurs blocs à la fois — l'unicité historique un-groupe-par-équipe
  (`SharedTrainingGroupStateProcessor.php:196`) n'est PAS reconduite sur le bloc ; c'est la garde
  Σ, pas l'unicité, qui borne le cumul.
- **D13 — à l'ÉCRAN, mutualisation et bloc sont LA MÊME notion** (correction fondateur du
  2026-08-31, reçue pendant la revue de la passe frontend PR-4 ; verbatim : « mutualisation et
  bloc équipe c'est la même chose — j'ai les passerelles qui sont un lien et les mutualisations
  (bloc équipe) qui sont un autre lien c'est tout »). Ceci **résout** le garde-fou UX posé en §3
  (« deux notions à l'écran = risque deux-maisons-pour-une-vérité ») : la modale « Liens » porte
  exactement **deux** liens — passerelles, et mutualisation (= le bloc) — jamais trois. Le panneau
  du groupe historique {équipes, K} **quitte cette modale** ; le bloc EST désormais ce que
  l'écran appelle « Mutualisation ». ⚠ **Ceci tranche l'écran, pas le modèle** : `SharedTrainingGroup`
  (entité, contrat, seeder) reste intact pour l'instant — retirer le MODÈLE groupe K est un lot de
  nettoyage séparé, à arbitrer.

**Ce qui NE change pas de ce cadrage** : le besoin terrain (§1, les 8 partages + 5 équipes
multi-appartenances), le diagnostic du mur (§2, `targeting.py:376-388` — toujours la preuve que le
modèle groupe {équipes, K} ne peut pas dire le terrain), l'AJOUT au MODÈLE (pas remplacement — le
groupe `SharedTrainingGroup` reste intact au niveau entité/contrat, c'est une nouvelle paire
d'entités) ; l'ÉCRAN, lui, a tranché en faveur d'UNE seule notion visible (D13, ci-dessus).

**Avancement** : PR-1 (modèle backend seul : entités, migration RLS, API CRUD, gardes, cascades,
staleness, purges), PR-2 (émission payload/contrat — bloc `sharedBlocks`, `CONTRACT_VERSION`
2.16→2.17, schémas moteur alors INERTES), **PR-3 (sémantique solveur + verdict de refus de
casse — détail §0bis)** et **PR-4 (frontend, geste DÉCLARER — détail §0ter) livrées** —
trace `specs/courantes/etat-des-lieux.md` §3, inventaires `backend/docs/backend-inventory.md` et
`engine/docs/engine-inventory.md` (changelog contrat), vocabulaire
`engine/docs/constraint-vocabulary.md`. **PR-5 (2026-08-31) livre le RAIL 1** (réservation groupée
ré-ancrée sur le bloc, détail §0quater) ; le **RAIL 2** (déplacement groupé atomique) est **STOPPÉ
sur un constat de contrat** (`candidate` singulier dans `/validate-assignments`, bump 2.18 requis)
— arbitrage fondateur en cours. PR-6 (écran : le front bascule sur `sharedTrainingBlockId`) et PR-7
(retrait du repli `sharedTrainingGroupId`) restent à faire.

## 0bis. PR-3 (2026-08-31) — la modélisation retenue : le LIAGE

**La décision qui porte tout le reste.** Chaque bloc possède, pour chaque case candidate
`(gymnase, jour, heure)`, une variable de DÉCISION propre au bloc `b[case]` — liée à chaque membre
par l'implication **UNIDIRECTIONNELLE** `x[membre, case] ≥ b[case]` (« si le bloc tient sa séance
ici, tous les membres y sont »), avec `Σ b == commonSessions`.

**On ne réifie PAS `b` depuis la co-présence** (pas de `b ⇔ tous présents`, contrairement au
littéral `y_s` du groupe historique `sharedTrainings`) — c'est ce refus, précisément, qui **dissout
structurellement** le mur du double-comptage décrit en §2 : deux blocs qui partagent une équipe ont
des `b` INDÉPENDANTS, chacun compte SES séances, sans jamais faire compter une même case deux fois
pour deux blocs qui se recouvrent.

Et parce que `b ⟹ x=1`, une séance de bloc **EST** une séance `x` normale du membre : elle
consomme une de ses séances/semaine, compte pour `one_session_per_day`, le repos coach, les
enchaînements, `team_no_overlap` et l'objectif de placement — tous déjà exprimés sur `x`, donc
**gratuitement**, sans aucun crédit à câbler à la main (à l'inverse d'une pseudo-équipe découplée,
qui aurait dû faire créditer chacun de ces postes séparément). La seule chirurgie nécessaire est la
**capacité de gymnase** : une séance de bloc réunissant `n` membres libres sur une case n'y occupe
qu'**UNE** place — allègement `(n_libres−1)·b` dans `add_room_at_most_one`, patron du crédit des
verrouillés (P4-97).

Détail moteur complet (capacité, exclusion dynamique de l'exact-K des groupes, exemption
passerelle, diagnostic, verdict) : `engine/docs/constraint-vocabulary.md` §Bloc de mutualisation.

## 0ter. PR-4 (2026-08-31) — geste DÉCLARER livré, gestes POSER/DÉPLACER bloqués backend

**Livré — geste 1 (DÉCLARER)** : le bloc se saisit dans la modale « Liens » de l'étape Équipes
(`TeamLinksModal.tsx`) via un nouveau panneau `SharedTrainingBlockPanel`. 2..10 équipes, **liste
déroulante** (verbatim fondateur, D9) des séances communes bornée `1..min(séances effectives des
membres, override de période inclus)` — plafond de CONFORT calculé côté front
(`wizard/lib/sharedTrainingBlock.ts::blockCommonSessionOptions`), le serveur reste seul juge
(garde Σ de `SharedTrainingBlockStateProcessor::assertBlockValid`, son 422 affiché tel quel).
Multi-appartenance PERMISE (D9/D12) avec un repère informatif « déjà dans N blocs »
(`blockMembershipCount`), sans verrou.

⚠ **Rendu ÉCRAN livré par cette PR : TRANSITOIRE.** Au commit `ffb07ff1`, la modale affiche ce
nouveau panneau **à côté** du panneau du groupe historique {équipes, K} (`MutualisationPanel`),
sous deux titres distincts — c'est l'état visible au moment de cette passe de documentation, PAS
l'état voulu. **D13 (§0) le corrige sur la même branche, juste après** : le panneau bloc DEVIENT
la section « Mutualisation », le panneau groupe K quitte la modale — la modale « Liens » ne porte
plus que deux liens (passerelles, mutualisation). Le comportement du geste DÉCLARER lui-même
(bornes, garde Σ, multi-appartenance) ne change pas ; seul son HABILLAGE à l'écran est corrigé.

**Livré sans code — geste 3a (afficher le refus)** : `SlotDetail.tsx:240` rend déjà chaque
`MoveViolation.message` génériquement (`<li>{v.message}</li>`), donc le refus nommé
`shared_block_broken` du verdict PR-3 s'affiche sans changement.

**Était BLOQUÉ backend — geste 2 (POSER dans Réserver)** : au moment de PR-4, le seul rail
d'écriture BATCH, `POST /api/reservations/group`, résolvait STRICTEMENT un `SharedTrainingGroup` —
404 sinon. **Débloqué par PR-5 (§0quater, 2026-08-31)** : le rail se ré-ancre sur le bloc.

**BLOQUÉ backend — geste 3b (déplacer le bloc entier)** : D11 exige un déplacement EN BLOC, jamais
une équipe seule. Le seul rail de déplacement, `POST /api/schedule-slots/{id}/move`
(`ManualEditController.php:111`), ne déplace qu'UN créneau sous UN verdict. N appels séquentiels
sans échec atomique casseraient le bloc à mi-chemin (le premier déplacement le rendrait
`shared_block_broken`, le verdict PR-3 refuserait alors le second) — c'est le cas d'arrêt prévu,
pas un bug. **Reste bloqué après PR-5** (§0quater) : le blocage n'est plus « aucun rail n'existe »
mais un constat de CONTRAT précis (`candidate` singulier de `/validate-assignments`), arbitrage
fondateur en cours.

**Conséquence** : PR-5 a résolu le geste 2 (rail 1, réservation). Le geste 3b (rail 2, déplacement)
reste en attente d'arbitrage sur le bump `CONTRACT_VERSION` 2.18 — détail §0quater.

## 0quater. PR-5 (2026-08-31) — rail 1 livré (réservation ré-ancrée), rail 2 STOPPÉ sur un constat de contrat

**Rail 1 — livré.** `POST /api/reservations/group` résout désormais un `SharedTrainingBlock`
D'ABORD (nouveau champ body `sharedTrainingBlockId`), avec repli sur `SharedTrainingGroup` (champ
legacy `sharedTrainingGroupId`) — **option (a), transition douce** : le front actuel
(`SlotReservationModal.tsx:240`) poste encore des groupes K, une bascule sèche aurait cassé toutes
les réservations groupées AVANT la PR-6 (écran). Parité stricte avec le rail groupe historique : N
réservations / 1 flush / atomique, les 5 gardes P2-46 appliquées au bloc
(`ReservationGroupOccupancy::assertBlockReservationAllowed`, plafond borné par `commonSessions` du
bloc), règles (b)/(e) : un bloc complet compte comme UN occupant. Détail complet, route et gardes :
`backend/docs/backend-inventory.md` §« Réservation groupée ».

**Constat sémantique, pas un trou** : une réservation de bloc = N verrous HARD sur une case ;
`add_room_at_most_one` laisse une case toute-verrouillée non contrainte, et le diagnostic de
sur-capacité replie déjà les blocs PAR CASE (câblé en PR-3). Aucun crédit manquant.

**Rail 2 — STOPPÉ, arbitrage fondateur requis.** Le schéma du verdict
(`engine/app/schemas/validate_input_schema.py:101`, champ `candidate:
CandidateAssignmentSchema`) déclare le candidat **au singulier, obligatoire** — un déplacement
groupé (N sources + N candidats sous UN verdict) exige un **bump de contrat 2.18** : une liste de
candidats côté schéma, et la généralisation du miroir `_shared_block_move_violation`
(`engine/app/solver/validate_assignments.py:131`) à un jugement N-déplacements (aujourd'hui, il ne
juge qu'UN déplacement avant/après via `reference`). Non implémenté, non bumpé — conforme au
cadrage (le rail 2 n'était pas dans le périmètre tant que le constat de contrat n'était pas posé).

**Garde P4-154 soldée dans cette même PR** : la paire `wizard/SharedTrainingBlock` (type TS miroir
↔ schéma OpenAPI) est enrôlée dans `TsFieldsMatchOpenApiSchemaTest::PAIRS`, sans exemption, sans
divergence trouvée.

**Reste après PR-5** : PR-6 (l'écran bascule sur `sharedTrainingBlockId`, retire le repli du POST
côté front) et PR-7 (retrait du repli `sharedTrainingGroupId` côté backend + rail 2 une fois
l'arbitrage du bump 2.18 tranché).

## 1. Le besoin, dans les mots du terrain

En recalant le seed BCCL (2026-08-25), le fondateur a fourni la réalité du club — et elle ne
rentre pas dans le modèle. Chez lui, une mutualisation est un fait du **CRÉNEAU** (« ce créneau
est partagé par ces équipes », une séance à chaque fois), pas d'un groupe d'équipes à K séances :

**Les 8 partages de créneau réels (verbatim fondateur, K=1 chacun) :**

| Créneau | Équipes ensemble |
|---|---|
| ADN mer 17h30–19h (ex-CEC1) | U9M2 / U9F1 / U9F2 |
| Matéo mer 16h–17h30 (ex-CEC2) | U9M1 / U11F2 |
| Matéo mer 17h30–19h (ex-CEC3) | U11M2 / U11F1 |
| Matéo lun 17h30–19h | U9F1 / U9F2 |
| Armand lun 17h30–19h | U13M1 / U13M2 |
| JDR mar 17h30–19h | U13F2 / U13F3 |
| Armand mer 14h–15h45 | U13F1 / U13F2 |
| JDR jeu 17h30–19h | U9M1 / U9M2 |

Cinq équipes appartiennent à **plusieurs** partages avec des partenaires différents (U9F1, U9F2,
U9M1, U9M2, U13F2). « Les créneaux à double/triple capacité étaient à chaque fois un **palliatif**
à la mutualisation » (fondateur) — le vrai modèle du terrain, c'est le partage nommé.

**Au même échange, le fondateur a fourni les 10 passerelles réelles** (elles, INDÉPENDANTES de ce
lot — le modèle `TeamLink` les porte déjà, elles peuvent entrer au seeder à tout moment) :
SM1–SM2 · SM1–U21M1 · U18M1–U18M2 · U15M1–U15M2 · U13M1–U13M2 · SF1–SF2 · SF1–U18F1 ·
U18F2–U18F1 · U15F1–U15F2 · U13F1–U13F2.

## 2. Pourquoi le modèle actuel ne peut pas le dire (vérifié au code, pas une impression)

Le modèle P2-27 (`SharedTrainingGroup` {équipes, K}) dit « ces N équipes s'entraînent ensemble
**exactement K fois**, le solveur choisit où ». Deux murs :

1. **L'unicité** : une équipe n'appartient qu'à UN groupe par plan
   (`SharedTrainingGroupStateProcessor.php:196`) — choix de conception du lot P2-27, pas un
   arbitrage produit (vérifié à l'historique, PR #606).
2. **Le plus profond — même en levant l'unicité, l'exact-K se double-compte sur les groupes
   imbriqués** : le solveur compte les cases où TOUS les membres d'un groupe sont présents
   (`engine/app/solver/constraints/targeting.py:376-388` — littéral `y_s` réifié dans les deux
   sens, `Σ y_s == K`). Si {U9F1, U9F2} K=1 et {U9M2, U9F1, U9F2} K=1 coexistent, la séance du
   trio compte AUSSI pour la paire (toutes deux présentes) → la paire voit 2 séances communes
   pour K=1 → infaisable ou faux. L'exact-K par groupe est **structurellement** incompatible
   avec des partages à sous-ensembles.

## 3. Les décisions déjà prises (2026-08-25 — à ne pas re-discuter au cadrage)

- **AJOUT, pas remplacement** (question posée par le fondateur, tranchée) : le groupe {équipes, K}
  garde son sens (« K séances ensemble, le solveur choisit où » — l'usage reprise pour lequel il
  est né) et **reste intact** ; la mutualisation par créneau s'AJOUTE (« ce créneau précis est
  partagé par ces équipes », N déclarations par équipe possibles, une séance par déclaration).
- **Direction de modèle** (⚠ **SUPERSEDÉE le 2026-08-31, voir §0**) : à l'origine, l'idée était que
  le créneau possède ses équipes — le patron de la rotation A/B côté matchs (`MatchSlotRotation`,
  RMM-5). Le fondateur a tranché différemment à la validation du plan : **le bloc se comporte
  comme une équipe** (§0, D9) — ses séances lui appartiennent, elles ne sont pas ancrées à une
  case (gymnase, jour, heure) précise.
- **Garde-fou UX à la passe design — RÉSOLU par D13 (§0), 2026-08-31** : deux notions de
  mutualisation à l'écran = risque « deux maisons pour une vérité » — le gestionnaire doit
  comprendre d'un coup d'œil quand utiliser l'une ou l'autre. Le fondateur a tranché À L'ÉCRAN
  (D13) : mutualisation et bloc SONT la même notion pour le gestionnaire ; le groupe {équipes, K}
  quitte la modale « Liens », le bloc en devient la seule section « Mutualisation ». Le retrait du
  MODÈLE groupe K, lui, reste une question ouverte (lot de nettoyage séparé).
- **Le seeder BCCL suit, ne précède pas** : le recalage complet (les 8 partages + suppression des
  capacités-palliatifs) attend ce lot ; seules les 10 passerelles pourraient entrer avant
  (indépendantes).

## 4. Questions OUVERTES pour le cadrage — TRANCHÉES le 2026-08-31 (§0)

Ces questions ont motivé la validation du plan qui a produit l'amendement §0 ; conservées pour
mémoire, avec leur réponse effective :

- ~~La forme exacte du modèle : entité créneau-partagé ancrée sur quoi~~ → **le bloc**, ancré sur
  aucune case précise ; portée plan/saison tranchée en D10 (fait de PLAN, `schedulePlanId`
  nullable, patron `SharedTrainingGroup`).
- ~~Le lien avec les réservations de groupe P2-46~~ → aucun : le bloc est un modèle indépendant,
  pas une généralisation de la réservation de groupe.
- ~~La sémantique solveur : ancré vs contrainte souple~~ → **ni l'un ni l'autre** : le bloc se
  comporte comme une équipe, ses séances lui appartiennent (D9) — tranché pour PR-3, pas encore
  implémenté (PR-1 = modèle seul).
- ~~Le bloc contrat (bump probable) et la parité stocké⇄émis (patron `SharedTrainingPayloadParityTest`)~~
  → **livré PR-2** : bloc `sharedBlocks`, `CONTRACT_VERSION` 2.17, gating
  `CrossStack/SharedBlockPayloadParityTest`.
- Axes §7.1 : **contrat backend⇄engine** — NR + smoke faits en PR-2 (contrat 2.17, schémas moteur
  inertes, goldens inchangés). **Sémantique de contrainte** — livrée en PR-3 (modélisation LIAGE,
  §0bis) : le bloc est CONSOMMÉ par le solveur, NR cross-stack `CrossStack/SharedBlockHonouredByEngineTest`
  (groupe `contract`, job `engine-semantics`).

## 5. Impacts recensés (consommateurs du modèle actuel, tous vérifiés le 2026-08-25)

Bloc contrat `sharedTrainings` · poseur `targeting.py` · `ReservationGroupOccupancy` (5 gardes
P2-46) · wizard onglet Mutualisation (case verrouillée) · exemption sur-capacité · cascade
« équipe supprimée tue le groupe » · seeder BCCL (reprises comprises) ·
`SharedTrainingPayloadParityTest` (step bloquant).
