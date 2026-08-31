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

## 4. Les mutualisations : le lot P2-51, cadré PUIS amendé — PAS un nouveau chantier

**Le réel** : 8 partages de créneau (lignes « + » du fichier saison), 5 équipes
multi-appartenances (U9F1, U9F2, U9M1, U9M2, U13F2). Le fondateur l'a reconfirmé le 2026-08-31 :
le planning réel est la spécification, l'app doit savoir le dire.

**Ce besoin a été cadré le 2026-08-25, puis son MODÈLE amendé le 2026-08-31** (pendant la
validation du plan de PR-1) : [`mutualisation-par-creneau.md`](mutualisation-par-creneau.md) §0
tient le détail — la même liste de 8 partages, verbatim, reste le besoin de référence. Ce que
l'amendement change :
- motif profond, revérifié indépendamment le 2026-08-31 : lever l'unicité un-groupe-par-équipe ne
  suffirait PAS — l'exact-K du solveur **se double-compte sur les partages imbriqués** (la séance
  du trio CEC compte aussi pour la paire incluse). La représentation par groupes est
  structurellement incapable de dire le terrain — **ce diagnostic tient toujours**.
- ~~la mutualisation **par créneau** s'AJOUTE, patron `MatchSlotRotation`~~ → **abandonné** : le
  modèle retenu est le **BLOC** (D9) — un ensemble d'équipes qui se comporte comme UNE équipe,
  ses séances lui appartiennent (pas d'ancrage à une case gymnase/jour/heure). Le groupe
  {équipes, K} existant **reste intact** (AJOUT confirmé, seule la forme du nouveau modèle a
  changé) ; fait de PLAN (D10), déplacé en bloc (D11), meurt entier à la suppression d'un membre
  (D12).
- **PR-1, PR-2 et PR-3 livrées le 2026-08-31** : PR-1 = entités `SharedTrainingBlock`/
  `SharedTrainingBlockTeam`, migration RLS, API CRUD, gardes (garde centrale Σ comprise), cascades,
  staleness, purges ; PR-2 = payload/contrat (`sharedBlocks`, `CONTRACT_VERSION` 2.17) ; PR-3 = le
  solveur CONSOMME le bloc (modélisation LIAGE) et le verdict refuse un déplacement qui le casse.
  Traces : `specs/courantes/etat-des-lieux.md` §3, `backend/docs/backend-inventory.md`,
  `engine/docs/constraint-vocabulary.md`. **PR-4 livrée en PARTIE le 2026-08-31** : le geste
  DÉCLARER est fait (modale « Liens » de l'étape Équipes) ; les gestes POSER (Réserver) et
  déplacer le bloc entier restent BLOQUÉS — aucun rail backend atomique n'existe pour l'un ni
  l'autre (analyse : `mutualisation-par-creneau.md` §0ter). **D13 (correction fondateur reçue
  pendant cette même passe)** : à l'écran, mutualisation et bloc SONT la même notion — un seul
  lien « Mutualisation » dans la modale « Liens », le panneau du groupe historique {équipes, K}
  en sort ; correctif en cours sur la même branche (détail `mutualisation-par-creneau.md` §0,
  D13).

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

- **Les 10 passerelles réelles ne sont pas seedées** (P5-23, déjà tracée) : le modèle `TeamLink`
  les porte depuis P2-34, indépendantes de P2-51 — peuvent entrer au seeder à tout moment.

## 6. État d'avancement

| étape | état |
|---|---|
| Base de jeu = BCCL réel + plannings (via `make fixtures`) | ✅ 2026-08-31 |
| Diff seeder ↔ fichier saison (90=90) | ✅ vérifié |
| Consignation du programme (ce fichier) | ✅ |
| PR seeder : drapeaux « périmé » (+ passerelles P5-23 si le fondateur valide) | ⬜ suivant |
| P2-51 modèle amendé (D9-D12) — PR-1 modèle bloc (backend seul) | ✅ 2026-08-31 |
| P2-51 PR-2 (payload/contrat — bloc `sharedBlocks`, CONTRACT_VERSION 2.17, INERTE) | ✅ 2026-08-31 |
| P2-51 PR-3 (sémantique solveur — le bloc est CONSOMMÉ, verdict refuse la casse) | ✅ 2026-08-31 |
| P2-51 PR-4 (frontend : geste DÉCLARER livré ; gestes POSER/déplacer bloqués backend, arbitrage fondateur en attente) | 🟠 partielle 2026-08-31 |
| P2-51 PR-5 (rail 1 — réservation groupée ré-ancrée sur le bloc, geste POSER débloqué) | ✅ 2026-08-31 |
| P2-51 PR-5b (rail 2 — déplacement groupé, contrat 2.18, `POST /api/schedule-slots/move-group`) | ✅ 2026-08-31 |
| P2-51 PR-6 (écran : bascule sur `sharedTrainingBlockId`, propose le geste déplacer-en-bloc) / PR-7 (retrait du repli groupe) | ⬜ |
| Exercice solveur reprise 17 août | ⬜ après P2-51 |
| Exercice solveur reprise 24 août | ⬜ |
| Overlay Mateo 31/08→16/10 (D2, D1) + exercice | ⬜ |
| Semaine charnière au choix (D4) — cadrage | ⬜ |
| Durée d'indispo modifiable (D3) — cadrage | ⬜ |
| PR seeder finale : overlay transcrit + `make play` complet | ⬜ |
