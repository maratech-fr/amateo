# Mutualisation par créneau — le terrain pense créneau, le modèle pense groupe (P2-51)

> Besoin cadré le **2026-08-25** (échange fondateur, liste terrain BCCL fournie le jour même),
> **mis en attente à la demande du fondateur** — « on évolue le modèle d'abord » puis « stocke
> cette demande pour qu'on ne la perde pas ». Ce fichier est le détail de la ligne **P2-51** de
> [`roadmap.md`](roadmap.md) ; il devient le point d'entrée du cadrage quand le lot démarre.

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
- **Direction de modèle** : le créneau possède ses équipes — le patron établi par la rotation A/B
  côté matchs (`MatchSlotRotation`, RMM-5), et déjà effleuré par les réservations de groupe
  (P2-46 : N verrous sur la même case lus par le moteur comme UNE séance commune — mais exigeant
  aujourd'hui un groupe déclaré unique).
- **Garde-fou UX à la passe design** : deux notions de mutualisation à l'écran = risque « deux
  maisons pour une vérité » — le gestionnaire doit comprendre d'un coup d'œil quand utiliser
  l'une ou l'autre. Et si le cadrage révèle que le co-placement libre n'a AUCUN cas terrain
  restant, la question « le retirer ? » revient au fondateur explicitement, jamais en silence.
- **Le seeder BCCL suit, ne précède pas** : le recalage complet (les 8 partages + suppression des
  capacités-palliatifs) attend ce lot ; seules les 10 passerelles pourraient entrer avant
  (indépendantes).

## 4. Questions OUVERTES pour le cadrage (le plan les tranche, avec exemples)

- La forme exacte du modèle : entité créneau-partagé ancrée sur quoi (slot template ? case
  (gymnase, jour, heure) à la MatchSlotRotation ?) ; portée plan/saison (ADR-0002).
- Le lien avec les réservations de groupe P2-46 : un créneau mutualisé déclaré est-il une
  généralisation de la réservation de groupe (ensemble d'équipes AD HOC, sans groupe préalable) ?
- La sémantique solveur : ancré = les équipes sont co-placées SUR ce créneau (proche d'un lot de
  verrous) vs contrainte souple ; interaction avec l'exemption de sur-capacité et le diagnostic.
- Le bloc contrat (bump probable) et la parité stocké⇄émis (patron `SharedTrainingPayloadParityTest`).
- Axes §7.1 : **contrat backend⇄engine** ET **sémantique de contrainte** → NR + smoke obligatoires.

## 5. Impacts recensés (consommateurs du modèle actuel, tous vérifiés le 2026-08-25)

Bloc contrat `sharedTrainings` · poseur `targeting.py` · `ReservationGroupOccupancy` (5 gardes
P2-46) · wizard onglet Mutualisation (case verrouillée) · exemption sur-capacité · cascade
« équipe supprimée tue le groupe » · seeder BCCL (reprises comprises) ·
`SharedTrainingPayloadParityTest` (step bloquant).
