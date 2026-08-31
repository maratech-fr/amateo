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

**Ce qui NE change pas de ce cadrage** : le besoin terrain (§1, les 8 partages + 5 équipes
multi-appartenances), le diagnostic du mur (§2, `targeting.py:376-388` — toujours la preuve que le
modèle groupe {équipes, K} ne peut pas dire le terrain), l'AJOUT (pas remplacement — le groupe
`SharedTrainingGroup` reste intact, c'est une nouvelle paire d'entités), le garde-fou UX « deux
notions, un coup d'œil » (§3).

**Avancement** : PR-1 (modèle backend seul : entités, migration RLS, API CRUD, gardes, cascades,
staleness, purges) **livrée** — trace `specs/courantes/etat-des-lieux.md` §3, inventaire
`backend/docs/backend-inventory.md`. Restent PR-2 (émission payload/contrat), PR-3 (sémantique
solveur + verdict de déplacement-en-bloc), PR-4 (frontend). Détail de programme et séquencement :
[`plannings-bccl-2026-08-31.md`](plannings-bccl-2026-08-31.md) §4/§6.

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
- **Garde-fou UX à la passe design** : deux notions de mutualisation à l'écran = risque « deux
  maisons pour une vérité » — le gestionnaire doit comprendre d'un coup d'œil quand utiliser
  l'une ou l'autre. Et si le cadrage révèle que le co-placement libre n'a AUCUN cas terrain
  restant, la question « le retirer ? » revient au fondateur explicitement, jamais en silence.
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
- Le bloc contrat (bump probable) et la parité stocké⇄émis (patron `SharedTrainingPayloadParityTest`)
  — reste ouvert, périmètre PR-2.
- Axes §7.1 : **contrat backend⇄engine** ET **sémantique de contrainte** → NR + smoke obligatoires
  quand PR-2/PR-3 les touchent (PR-1 = modèle backend seul, aucun payload ni engine).

## 5. Impacts recensés (consommateurs du modèle actuel, tous vérifiés le 2026-08-25)

Bloc contrat `sharedTrainings` · poseur `targeting.py` · `ReservationGroupOccupancy` (5 gardes
P2-46) · wizard onglet Mutualisation (case verrouillée) · exemption sur-capacité · cascade
« équipe supprimée tue le groupe » · seeder BCCL (reprises comprises) ·
`SharedTrainingPayloadParityTest` (step bloquant).
