# Programme plannings BCCL — saison, reprises, overlay « Mateo indisponible »

> Détail actif de la ligne **P2-58** de [`roadmap.md`](roadmap.md). Cadré avec le fondateur le
> 2026-08-31 (session de travail sur sa base de jeu `amateo_local`). Ce fichier consigne les
> décisions ARBITRÉES, les faits VÉRIFIÉS au code, et l'état d'avancement — pour reprendre le
> chantier sans ré-arbitrer si la session s'interrompt.

## 0. Le principe directeur (décision fondateur, non négociable)

**Le planning réel du club est la SPÉCIFICATION.** Les fichiers
`business/5-donnees/plannings-bccl/*.txt` (local-only, jamais versionnés) décrivent ce que le
gestionnaire a réellement défini — on ne les « tord » pas pour rentrer dans les limites de
l'application : **c'est l'application qui doit les tolérer**. Quand une limite du produit empêche
de représenter le réel (cf. §4 mutualisations), c'est une évolution produit à faire, pas un
contournement de données.

## 1. La cible finale

À la fin du programme, `BcclSeeder` (profil **dev**, club réel ARA0069036, `mara.mb@bccl.fr`)
contient **quatre plannings** :

1. le planning de **saison** (socle) — déjà transcrit et choisi (90 créneaux) ;
2. la **reprise du 17 août** — transcrite (25 créneaux) ;
3. la **reprise du 24 août** — transcrite (38 créneaux) ;
4. l'**overlay « Mateo indisponible »** du **31/08 au 16/10** (veille de la Toussaint) — à
   construire, remplace l'incident seedé actuel (« travaux », 18/08→30/09, fenêtre périmée).

Et chaque planning de vacances/overlay a subi **l'exercice de vérité du solveur** (§2).

## 2. La méthode par planning (validée le 2026-08-31)

Ordre : **reprise 17 août → reprise 24 août → overlay Mateo** (la saison a déjà eu son exercice).

1. **Contraintes co-construites** — proposition dérivée de la structure du planning cible,
   arbitrée par le fondateur AVANT toute écriture (règle : jamais de réglage du club réel sans
   options posées).
2. **Génération** par le solveur, en mode période.
3. **Mesure d'écart** créneau par créneau contre la cible transcrite (combien justes, combien
   déplaçables, combien inatteignables). Un écart est un RÉSULTAT (signal produit), jamais
   quelque chose à maquiller.
4. **Quelques déplacements manuels** — la donnée « ce qu'un vrai gestionnaire vivra ».
5. **La version validée est le fichier**, à la lettre (transcription), quoi qu'ait produit le
   solveur. ⚠ **Pas de tout-en-réservation** : solution de facilité explicitement refusée.

Conduite : chaque écriture en base annoncée AVANT, GO par GO (base de jeu du fondateur).

## 3. Décisions déjà arbitrées (2026-08-31)

| # | décision | détail |
|---|---|---|
| D1 | **La semaine du 31/08 est couverte par l'OVERLAY** | pour le BCCL. Les vacances d'été du référentiel finissent le lundi 31/08, la saison commence le 01/09 — semaine à cheval. |
| D2 | **L'incident seedé est REMPLACÉ** | « Matéo indisponible (travaux) » 18/08→30/09 → « incident » 31/08→16/10. Pas de coexistence. |
| D3 | **La durée d'une indisponibilité devient modifiable, dans LES DEUX SENS** | étendre ET rétrécir. Aujourd'hui figée par construction dès qu'un plan existe (`CalendarEntryStateProcessor:118` — « Supprimez la période puis recréez-la », choix ADR-0002 : grille copiée à la naissance). Rétrécir pose la question des créneaux orphelins hors fenêtre. Axe §7.1 planning lifecycle — cadrage dédié. |
| D4 | **Semaine charnière : le gestionnaire CHOISIT le type qui la couvre** | une semaine à cheval vacances/saison (ex. 31/08) est proposée en reprise OU en overlay **tant qu'elle n'est gérée par aucun** ; dès qu'un la prend, l'autre ne la propose plus. Motif fondateur : « pour un humain, il est impensable que la semaine du 31 août ne soit pas la semaine de reprise — mais d'autres structures peuvent voir l'inverse. D'où le choix. » |
| D5 | **Les mutualisations « comme le gestionnaire l'entend » = P2-51, déjà cadré** | Reconfirmé le 2026-08-31 (« je dois pouvoir faire mes mutualisations comme je l'entends ») — voir §4 : le besoin du cadrage du 2026-08-25 tient (les 8 partages), son MODÈLE est amendé le même jour en bloc (D9-D12), le lot entre dans ce programme. |
| D7 | **P2-51 AVANT les exercices solveur** | pas de palliatif par groupes déclarés — l'exercice mesurerait un écart contre un modèle faux. |
| D8 | **Pas de libellé sur les paires mutualisées** | seuls les CEC sont nommés, parce que le club les nomme. L'empilé est le rendu voulu. |
| D6 | Le nom d'équipe `Veterans` reste sans accent | les fichiers du fondateur l'écrivent de deux façons (`Vétérans`/`Véterans`) ; renommer toucherait 5 sites pour du cosmétique. À rouvrir s'il y tient. |
| D9 | **Le bloc de mutualisation se déclare comme une équipe** | amende le cadrage P2-51 du 2026-08-25 (ancrage au créneau, abandonné) : un ensemble d'équipes qui se comporte comme UNE équipe, avec son propre nombre de séances communes (liste déroulante bornée) — « la déclaration est identique à déclarer les créneaux dans une équipe » (verbatim). Sélectionnable dans Réserver comme une équipe ; ses séances lui appartiennent, le solveur les placera comme celles d'une équipe (PR-3) — dissout structurellement le double-comptage du modèle groupe. |
| D10 | **Le bloc est un fait de PLAN, pas de saison** | question posée par l'agent de doc, tranchée par le fondateur : « je ne m'attends pas à ce qu'il survive aux vacances ». Pas de persistance-avec-désactivation. Coût assumé : redéclarer ~8 blocs par overlay ; « recopier les blocs du socle » = extension future explicite. |
| D11 | **Un bloc se déplace ENTIER, jamais une équipe seule** | « si je dois le déplacer je bouge les 2 équipes d'un coup » (verbatim). Le verdict (PR-3) refusera le déplacement individuel d'un membre ; le rail de retouche gagnera « déplacer le bloc ». |
| D12 | **Un bloc meurt ENTIER à la suppression d'un membre** | « je préfère tout défaire puis recréer que de retirer du groupe — la suppression d'une équipe est lourde de conséquence » (verbatim). Inverse le patron de survie-à-2 de la rotation match ; implémenté `SharedTrainingBlockPruneStep`. |
| D14 | **La case de bloc ACTIVE exempte le conflit coach-joueur** (2026-09-01) | une personne coach d'une équipe d'un bloc et joueuse d'une autre équipe du MÊME bloc n'est pas en conflit sur la séance de bloc (une seule séance, un seul rôle tenu — le cas « coach de B, joueur de B » passait déjà). Arbitrage explicite : les 2 cas se distinguent — l'exemption est **réifiée sur b[case]=1** ; une coïncidence de deux séances SOLO de membres au même gymnase+heure (capacité ≥2, b=0) RESTE un conflit, et deux séances à débuts ou gymnases différents aussi. Toutes paires de rôles exemptées sous b=1 (coach-joueur, joueur-joueur). Déclencheur : exercice reprise-17 — Maxime Dionnet (coach SM1, joueur SM2, bloc SM1+SM2) rendait la génération INFEASIBLE. |
| D15 | **Gestes construits du 17 août (validés)** | l'exercice ne s'achève NI en inventant des règles NI en tordant la cible : décocher l'hérité qui n'a plus d'objet (SM2·ven — le bloc est figé ailleurs), acter les règles réelles (SM mutualisés ≥ 20:30 ; Nicolas indispo lun+ven), figer les créneaux CHOISIS des fanions en réservation de bloc, et assumer les déplacements manuels restants (8, jugés valides en lot par le verdict). |
| D16 | **Chaque plan est indépendant — contraintes de GENÈSE vs FAITS** | « un plan peut avoir des contraintes liées à sa genèse […] si je voulais les mêmes règles j'aurais couvert la zone directement » : les datées-sur-mère partagée contredisent ce modèle → P2-59, cadrage ouvert. |
| D17 | **L'unité de placement est le bloc** | règle complète validée (filtre sélecteur + garde 422 sur le résidu solo, correctif moteur écarté) → P2-60. |

## 4. Les mutualisations : le lot P2-51, cadré PUIS amendé — PAS un nouveau chantier

**Le réel** : 8 partages de créneau (lignes « + » du fichier saison), 5 équipes
multi-appartenances (U9F1, U9F2, U9M1, U9M2, U13F2). Le fondateur l'a reconfirmé le 2026-08-31 :
le planning réel est la spécification, l'app doit savoir le dire.

**Ce besoin a été cadré le 2026-08-25, puis son MODÈLE amendé le 2026-08-31** (pendant la
validation du plan de PR-1) — la même liste de 8 partages, verbatim, reste le besoin de référence.
Le fichier de cadrage `mutualisation-par-creneau.md` est **supprimé** depuis la clôture du lot le
2026-08-31 (le comportement livré vit dans `backend/docs/backend-inventory.md` §SharedTrainingBlock
et `engine/docs/constraint-vocabulary.md` §Bloc de mutualisation ; l'historique complet du cadrage
et des 7 PR reste lisible dans git). Ce que l'amendement change :
- motif profond, revérifié indépendamment le 2026-08-31 : lever l'unicité un-groupe-par-équipe ne
  suffirait PAS — l'exact-K du solveur **se double-compte sur les partages imbriqués** (la séance
  du trio CEC compte aussi pour la paire incluse). La représentation par groupes est
  structurellement incapable de dire le terrain — **ce diagnostic tient toujours**.
- ~~la mutualisation **par créneau** s'AJOUTE, patron `MatchSlotRotation`~~ → **abandonné** : le
  modèle retenu est le **BLOC** (D9) — un ensemble d'équipes qui se comporte comme UNE équipe,
  ses séances lui appartiennent (pas d'ancrage à une case gymnase/jour/heure) ; fait de PLAN (D10),
  déplacé en bloc (D11), meurt entier à la suppression d'un membre (D12).
- **PR-1 à PR-6 + PR-5b livrées le 2026-08-31** : PR-1 = entités `SharedTrainingBlock`/
  `SharedTrainingBlockTeam`, migration RLS, API CRUD, gardes (garde centrale Σ comprise), cascades,
  staleness, purges ; PR-2 = payload/contrat (`sharedBlocks`) ; PR-3 = le solveur CONSOMME le bloc
  (modélisation LIAGE) et le verdict refuse un déplacement qui le casse ; PR-4 = geste DÉCLARER à
  l'écran ; **D13 (correction fondateur reçue pendant PR-4)** : à l'écran, mutualisation et bloc
  SONT la même notion — un seul lien « Mutualisation » dans la modale « Liens » ; PR-5 = geste
  POSER débloqué (réservation groupée ré-ancrée sur le bloc) ; PR-5b = geste DÉPLACER débloqué
  (déplacement groupé atomique, contrat 2.18, `POST /api/schedule-slots/move-group`) ; PR-6 = les
  3 gestes branchés à l'écran. **PR-7 (2026-08-31) CLÔT LE LOT** : le modèle groupe {équipes, K}
  (`SharedTrainingGroup`, cadrage P2-27 d'origine) est **retiré entièrement** — backend, contrat
  (→ **2.19**), moteur, écran, seeder ; une migration CONVERTIT chaque groupe existant en bloc
  avant DROP des tables. Le BLOC est désormais la SEULE notion de mutualisation, partout.
  Traces : `specs/courantes/etat-des-lieux.md` §2 (décision fermée) et §3 · `backend/docs/backend-inventory.md`
  §SharedTrainingBlock · `engine/docs/constraint-vocabulary.md` §Bloc de mutualisation.

Les 3 **CEC** du mercredi restent correctement AFFICHÉS par le `groupLabel` du créneau (P2-17,
`BcclSeeder:394-396,491`) — mais leur sémantique solveur attend, elle aussi, P2-51.

**Séquencement (tranché le 2026-08-31, D7)** : **P2-51 se fait AVANT l'exercice solveur des
reprises** — pas de palliatif provisoire par groupes déclarés. L'exercice a besoin de la vraie
sémantique de mutualisation pour être fidèle ; un palliatif aurait mesuré un écart contre un
modèle qu'on sait faux.

**Question (c) fermée le 2026-08-31 (D8)** : **PAS de libellé** sur les 5 créneaux des paires —
« si je ne l'ai pas déclaré, c'est que je n'en veux pas » (fondateur). Seuls les 3 CEC portent un
libellé, parce que le club les NOMME ainsi. L'affichage empilé des autres paires est le rendu
voulu ; ne pas « harmoniser » vers la fusion.

## 5. Défauts et travaux connexes relevés en chemin

- **Bandeau « périmé » sur base FRAÎCHE (défaut seeder)** : les 3 versions transcrites naissent
  avec `constraints_changed_since_generation=true` et `resources_changed=true` — le seeder crée
  les versions PUIS continue d'insérer, et `ConstraintChangeStaleScheduleListener` estampille à
  chaque écriture. Faux par construction (la transcription égale l'état final seedé). Correctif :
  remettre les drapeaux à zéro en fin de seed. → **PR seeder à venir**.
- **`make play` ne seede que le club démo** (ARA9999999, sans plannings — décision « la démo reste
  avant première génération », `BcclSeedProfile:186`, qui NE s'applique qu'à la démo). La base de
  jeu du fondateur doit porter le **BCCL réel complet** : apprendre à `make play` à seeder les
  deux clubs (aujourd'hui : `make fixtures` manuel, fait le 2026-08-31).
- **La mémoire « recalage seed à faire » était périmée** : les 90 placements du fichier saison
  sont DÉJÀ identiques au seeder (diff mécanique, seul écart l'accent Veterans), la correction
  Anna U11M1 est faite (`BcclSeeder:810`). Les mutualisations « à recaler » = la représentation P2-51 (§4), pas les créneaux.

- **Les 10 passerelles réelles sont désormais seedées** (P5-23, livrée 2026-09-01) : le modèle
  `TeamLink` les porte depuis P2-34 — voir §6, trace `etat-des-lieux.md` §3.

## 6. État d'avancement

| étape | état |
|---|---|
| Base de jeu = BCCL réel + plannings (via `make fixtures`) | ✅ 2026-08-31 |
| Diff seeder ↔ fichier saison (90=90) | ✅ vérifié |
| Consignation du programme (ce fichier) | ✅ |
| PR seeder : drapeaux « périmé » sur base fraîche | ⬜ suivant |
| P2-51 modèle amendé (D9-D12) — PR-1 modèle bloc (backend seul) | ✅ 2026-08-31 |
| P2-51 PR-2 (payload/contrat — bloc `sharedBlocks`, CONTRACT_VERSION 2.17, INERTE) | ✅ 2026-08-31 |
| P2-51 PR-3 (sémantique solveur — le bloc est CONSOMMÉ, verdict refuse la casse) | ✅ 2026-08-31 |
| P2-51 PR-4 (frontend : geste DÉCLARER livré ; gestes POSER/déplacer bloqués backend, arbitrage fondateur en attente) | 🟠 partielle 2026-08-31 |
| P2-51 PR-5 (rail 1 — réservation groupée ré-ancrée sur le bloc, geste POSER débloqué) | ✅ 2026-08-31 |
| P2-51 PR-5b (rail 2 — déplacement groupé, contrat 2.18, `POST /api/schedule-slots/move-group`) | ✅ 2026-08-31 |
| P2-51 PR-6 (écran : bascule sur `sharedTrainingBlockId`, propose le geste déplacer-en-bloc) | ✅ 2026-08-31 |
| P2-51 PR-7 (retrait du repli `sharedTrainingGroupId` côté backend + modèle groupe K) | ✅ 2026-08-31 — **P2-51 SOLDÉ EN ENTIER** |
| Recalage seeder : les 8 partages réels (blocs BCCL, suite à P5-23/§5) | ✅ 2026-09-01 |
| **P5-23 passerelles au seeder** | ✅ 2026-09-01 — **P5-23 SOLDÉE** |
| **Exercice solveur reprise 17 août — 1ʳᵉ passe (sandbox)** | ✅ 2026-09-01 — INFEASIBLE à froid, 3 causes bisectées : conflit coach-joueur×bloc (→ D14, chantier livré), 2 indispos coach violées par la cible (Nicolas·jeudi/U18M1, Thomas·vendredi/U15M1), capacité 2 restée sur les 5 cases mutualisées de la grille de reprise. Mesure : cible = optimum − 18 pts (espace dégénéré) ; « coller au plan » recadré par le fondateur : pas de règles inventées, on arbitre l'application. |
| Exemption coach-joueur sur case de bloc active (D14) — moteur | ✅ 2026-09-01 (branche `feat/bloc-coach-joueur-exemption`, PR en cours) — payload d'origine de l'exercice re-solvé COMPLETED |
| **Exercice solveur reprise 17 août — 2ᵉ passe (gestes construits)** | ✅ 2026-09-01 — méthode « comparer la réalité à l'application et en déduire des contraintes réalistes » : décochages (Thomas·ven, Nicolas·jeu, SM2·ven), règle « SM mutualisés ≥ 20:30 », indispo « Nicolas lun+ven », réservations fanion (bloc SM lun/mar/jeu 20:45, bloc SF mer/ven 19:30), capacités → 1. Résultat : **17/25**, adultes 100 % ; les 8 déplacements restants jugés EN LOT par le verdict moteur : `valid: true`, zéro violation (compromis informatifs seulement). Barre ≤ 20 % de manuel : 8/25 = 32 %, assumé par le fondateur — la boucle est FERMÉE. |
| Consignation seed des gestes du 17 — COMPLÈTE | ✅ 2026-09-01 — réservations fanion, capacités occupant-unique, 3 décochages de permanentes (PR #811), puis les **3 contraintes de genèse** pendues à l'entrée-enfant de la semaine (P2-59 PR-1, #814). `make fixtures` reproduit l'état construit entier |
| P2-59 modèle FAIT/GENÈSE (2 PRs backend+frontend) | ✅ 2026-09-01 — **SOLDÉE**, trace `etat-des-lieux.md` §3, comportement gradué `accueil-cockpit-temporel.md` §9ter.c/e |
| P2-60 unité de placement = bloc ([`unite-de-placement-bloc.md`](unite-de-placement-bloc.md)) | ⬜ règle validée, GO d'implémentation fondateur attendu |
| **Exercice solveur reprise 24 août + consignation** | ✅ 2026-09-01 — document corrigé par le fondateur en route (grille 19:00, mardi, bloc U18F, SM2 ×4), gestes construits (réservations fanion, 16 genèses, 3 décochages, liens joueurs INTACTS (doctrine : jamais coupés — la réservation impose, le cri du moteur est assumé)), généré 40/40 OPTIMAL (19/40 exacts), chemin manuel PROUVÉ (24 déplacements en 3 lots, verdict vert à chaque pas — après les 4 correctifs verdict #817). Seed consigné (PR en cours). Trouvailles tracées : P2-61 (stabilité vs version validée), P4-158/159/160 |
| Overlay Mateo 31/08→16/10 (D2, D1) + exercice | ⬜ |
| Semaine charnière au choix (D4) — cadrage | ⬜ |
| Durée d'indispo modifiable (D3) — cadrage | ⬜ |
| PR seeder finale : overlay transcrit + `make play` complet | ⬜ |
